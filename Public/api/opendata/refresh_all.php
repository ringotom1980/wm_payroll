<?php
/**
 * 一次刷新三表（並行觸發三支單抓 API；支援 async 回應）
 * 路徑：Public/api/opendata/refresh_all.php
 * 用法：
 *   - 背景刷新：/api/opendata/refresh_all.php?mode=refresh&async=1
 *   - 同步刷新：/api/opendata/refresh_all.php?mode=refresh  （會等三支回來）
 *   - 查狀態： /api/opendata/refresh_all.php?mode=status
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$lockFile = __DIR__ . '/../../../temp/gov_refresh.lock';

// ---- base URL ----
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dirUri = rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/api/opendata/'), '/');
$base   = "{$scheme}://{$host}{$dirUri}";

$apis = [
  'li'  => $base . '/gov_labor_insurance.php',
  'lp'  => $base . '/gov_labor_pension.php',
  'nhi' => $base . '/gov_nhi.php',
];

$mode  = $_GET['mode']  ?? 'refresh';
$async = isset($_GET['async']) && $_GET['async'] == '1';

function write_lock(string $file, array $data): void {
  @file_put_contents($file, json_encode($data + ['ts'=>time()], JSON_UNESCAPED_UNICODE));
}
function is_running(string $file): bool {
  if (!is_file($file)) return false;
  $meta = @json_decode(@file_get_contents($file), true) ?: [];
  if (empty($meta['running'])) return false;
  $ts = (int)($meta['ts'] ?? 0);
  return (time() - $ts) <= 600; // 10 分鐘過期保護
}
function finish_request_early(array $payload): void {
  if (function_exists('fastcgi_finish_request')) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    fastcgi_finish_request();
  } else {
    header('Connection: close');
    ignore_user_abort(true);
    ob_start();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush(); flush();
  }
}
function multi_get(array $urls): array {
  // 平行 GET
  $mh = curl_multi_init();
  $chs = [];
  foreach ($urls as $key => $url) {
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $full = $url . $sep . 'mode=sync';
    $ch = curl_init($full);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => 60, // 每支最多 60s
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
      'status' => (int)$code,
      'ok'     => ($code >= 200 && $code < 300),
      'json'   => json_decode($body ?? '', true),
      'raw'    => $body,
    ];
  }
  curl_multi_close($mh);
  return $out;
}

try {
  if ($mode === 'status') {
    // 直接回傳各表狀態 + running
    $running = is_running($lockFile);
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../../config/db.php';
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $q = function(string $table) use ($pdo) {
      $stmt = $pdo->query("SELECT COUNT(*) cnt, MAX(effective_date) latest_date, MAX(created_at) updated_at FROM {$table}");
      $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
      return [
        'cnt'          => (int)($r['cnt'] ?? 0),
        'latest_date'  => $r['latest_date'] ?: null,
        'updated_at'   => $r['updated_at'] ?: null,
      ];
    };
    echo json_encode([
      'ok'      => true,
      'running' => $running,
      'sources' => [
        'li'  => $q('gov_labor_insurance'),
        'lp'  => $q('gov_labor_pension'),
        'nhi' => $q('gov_nhi'),
      ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // refresh
  if (is_running($lockFile)) {
    echo json_encode(['ok'=>true,'running'=>true,'message'=>'job is running'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $jobId = bin2hex(random_bytes(6));
  write_lock($lockFile, ['running'=>true, 'job_id'=>$jobId]);

  if ($async) {
    finish_request_early(['ok'=>true,'running'=>true,'job_id'=>$jobId,'started_at'=>date('Y-m-d H:i:s')]);
  }

  // 平行觸發三支「正式表」同步（各自「清空→重寫」）
  $results = multi_get([
    'li'  => $apis['li'],
    'lp'  => $apis['lp'],
    'nhi' => $apis['nhi'],
  ]);

  write_lock($lockFile, ['running'=>false, 'job_id'=>$jobId, 'results'=>$results]);

  if (!$async) {
    echo json_encode(['ok'=>true,'running'=>false,'job_id'=>$jobId,'results'=>$results], JSON_UNESCAPED_UNICODE);
  }
} catch (Throwable $e) {
  write_lock($lockFile, ['running'=>false,'error'=>$e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok'=>false,'running'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
