<?php
/**
 * 一次刷新三表（平行抓 & 暫存表切換 & 背景執行）
 * 路徑：Public/api/opendata/refresh_all.php
 * 用法：
 *   - 啟動背景刷新：/api/opendata/refresh_all.php?mode=refresh&async=1
 *   - 同步刷新（不建議）：/api/opendata/refresh_all.php?mode=refresh
 *   - 查狀態：/api/opendata/refresh_all.php?mode=status （僅代理轉發到 status.php）
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$lockFile = __DIR__ . '/../../../temp/gov_refresh.lock';

// ---- 決定 base URL（以目前請求目錄為基準）----
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dirUri = rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/api/opendata/'), '/');
$base   = "{$scheme}://{$host}{$dirUri}";

$apis = [
  'lp'  => $base . '/gov_labor_pension.php',
  'li'  => $base . '/gov_labor_insurance.php',
  'nhi' => $base . '/gov_nhi.php',
];

$mode  = $_GET['mode']  ?? 'refresh';
$async = isset($_GET['async']) && $_GET['async'] == '1';

if ($mode === 'status') {
  // 直接代理到 status.php，方便前端用一個端點
  $statusUrl = $base . '/status.php';
  $res = @file_get_contents($statusUrl);
  if ($res === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'status failed']); exit; }
  echo $res;
  exit;
}

function write_lock($file, array $data) {
  @file_put_contents($file, json_encode($data + ['ts'=>time()], JSON_UNESCAPED_UNICODE));
}

function is_running($file): bool {
  if (!is_file($file)) return false;
  $meta = @json_decode(@file_get_contents($file), true) ?: [];
  if (empty($meta['running'])) return false;
  // 過期保護（10 分鐘）
  $ts = (int)($meta['ts'] ?? 0);
  return (time() - $ts) <= 600;
}

function multi_get(array $urls, array $qs): array {
  // 平行 GET （加 querystring）
  $mh = curl_multi_init();
  $chs = [];
  foreach ($urls as $key => $url) {
    $full = $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($qs);
    $ch = curl_init($full);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => 30, // 單支最多 30s
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    curl_multi_add_handle($mh, $ch);
    $chs[$key] = $ch;
  }
  do {
    $status = curl_multi_exec($mh, $active);
    curl_multi_select($mh, 0.5);
  } while ($active && $status == CURLM_OK);

  $out = [];
  foreach ($chs as $key => $ch) {
    $body = curl_multi_getcontent($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
    $out[$key] = [
      'status' => $code ?: 0,
      'ok'     => ($code >= 200 && $code < 300),
      'json'   => json_decode($body ?? '', true),
      'raw'    => $body,
    ];
  }
  curl_multi_close($mh);
  return $out;
}

function finish_request_early(array $payload) {
  if (function_exists('fastcgi_finish_request')) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    fastcgi_finish_request();
  } else {
    // FPM 以外環境：盡力早結束
    header('Connection: close');
    ignore_user_abort(true);
    ob_start();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
    flush();
  }
}

try {
  // 已有 job 在跑 → 直接回覆 running
  if (is_running($lockFile)) {
    echo json_encode(['ok'=>true, 'running'=>true, 'message'=>'job is running'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $jobId = bin2hex(random_bytes(6));
  write_lock($lockFile, ['running'=>true, 'job_id'=>$jobId]);

  if ($async) {
    finish_request_early(['ok'=>true, 'running'=>true, 'job_id'=>$jobId, 'started_at'=>date('Y-m-d H:i:s')]);
  }

  // ---- 平行呼叫三支單抓 API，但指定寫入「_tmp」表 ----
  // 你將在各 API 中新增 mode=refresh_tmp 支援（見下段第 3)）
  $results = multi_get([
    'lp'  => $apis['lp'],
    'li'  => $apis['li'],
    'nhi' => $apis['nhi'],
  ], ['mode' => 'refresh_tmp']);

  // ---- 切換暫存表到正式表（各自成功就各自切換）----
  /** @var PDO $pdo */
  $pdo = require __DIR__ . '/../../../config/db.php';
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $renameOne = function(string $tmp, string $real) use ($pdo) {
    // 若 tmp 無資料，不切換
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM {$tmp}")->fetchColumn();
    if ($cnt > 0) {
      $pdo->beginTransaction();
      try {
        // 保留上一版（可選）：real -> real_bak（僅保留一份）
        @$pdo->exec("DROP TABLE IF EXISTS {$real}_bak");
        $pdo->exec("RENAME TABLE {$real} TO {$real}_bak, {$tmp} TO {$real}");
        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
      }
    }
  };

  if (!empty($results['lp']['ok']))  { $renameOne('gov_labor_pension_tmp',   'gov_labor_pension'); }
  if (!empty($results['li']['ok']))  { $renameOne('gov_labor_insurance_tmp', 'gov_labor_insurance'); }
  if (!empty($results['nhi']['ok'])) { $renameOne('gov_nhi_tmp',             'gov_nhi'); }

  write_lock($lockFile, ['running'=>false, 'job_id'=>$jobId]);

  if (!$async) {
    echo json_encode(['ok'=>true, 'running'=>false, 'job_id'=>$jobId, 'results'=>$results], JSON_UNESCAPED_UNICODE);
  }
} catch (Throwable $e) {
  write_lock($lockFile, ['running'=>false, 'error'=>$e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok'=>false, 'running'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
