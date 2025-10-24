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
$lockDir  = dirname($lockFile);
if (!is_dir($lockDir)) {
  @mkdir($lockDir, 0775, true);
}

/** 協定偵測（支援反向代理） */
function detect_scheme(): string {
  $https = $_SERVER['HTTPS'] ?? '';
  $xfp   = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
  if ($https && strtolower($https) !== 'off') return 'https';
  if ($xfp) return strtolower(explode(',', $xfp)[0]);
  return (!empty($_SERVER['REQUEST_SCHEME'])) ? $_SERVER['REQUEST_SCHEME'] : 'http';
}

// ---- base URL ----
$scheme = detect_scheme();
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$reqUri = $_SERVER['REQUEST_URI'] ?? '/api/opendata/refresh_all.php';
$dirUri = rtrim(str_replace('\\', '/', dirname($reqUri)), '/');
$base   = "{$scheme}://{$host}{$dirUri}";

$apis = [
  'li'  => $base . '/gov_labor_insurance.php',
  'lp'  => $base . '/gov_labor_pension.php',
  'nhi' => $base . '/gov_nhi.php',
];

$mode  = $_GET['mode']  ?? 'refresh';
$async = isset($_GET['async']) && $_GET['async'] == '1';

function write_lock(string $file, array $data): void {
  $payload = json_encode($data + ['ts'=>time()], JSON_UNESCAPED_UNICODE);
  $fp = @fopen($file, 'c+');
  if (!$fp) { @file_put_contents($file, $payload); return; }
  if (@flock($fp, LOCK_EX)) {
    ftruncate($fp, 0);
    fwrite($fp, $payload);
    fflush($fp);
    @flock($fp, LOCK_UN);
  }
  fclose($fp);
}
function read_lock(string $file): array {
  if (!is_file($file)) return [];
  $txt = @file_get_contents($file);
  $meta = @json_decode($txt ?: '', true);
  return is_array($meta) ? $meta : [];
}
function is_running(string $file): bool {
  $meta = read_lock($file);
  if (empty($meta['running'])) return false;
  $ts = (int)($meta['ts'] ?? 0);
  // 10 分鐘過期保護
  return (time() - $ts) <= 600;
}
function finish_request_early(array $payload): void {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if (function_exists('fastcgi_finish_request')) {
    echo $json;
    fastcgi_finish_request();
  } else {
    header('Connection: close');
    ignore_user_abort(true);
    ob_start();
    echo $json;
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush(); flush();
  }
}
/** 並行 GET */
function multi_get(array $urls): array {
  $mh = curl_multi_init();
  $chs = [];

  foreach ($urls as $key => $url) {
    $sep  = (strpos($url, '?') === false) ? '?' : '&';
    $full = $url . $sep . 'mode=sync&from=refresh_all';
    $ch = curl_init($full);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => 60, // 每支最多 60s
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'],
      CURLOPT_USERAGENT      => 'wm-payroll-refresh/1.0',
    ]);
    curl_multi_add_handle($mh, $ch);
    $chs[$key] = $ch;
  }

  $active = null;
  do {
    $status = curl_multi_exec($mh, $active);
    if ($active) {
      curl_multi_select($mh, 0.5);
    }
  } while ($active && $status == CURLM_OK);

  $out = [];
  foreach ($chs as $key => $ch) {
    $body = curl_multi_getcontent($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
    $out[$key] = [
      'status' => (int)$code,
      'ok'     => ($code >= 200 && $code < 300),
      'json'   => json_decode($body ?? '', true),
      'raw'    => $body,
      'error'  => $err ?: null,
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
      $stmt = $pdo->query("SELECT COUNT(*) cnt, MAX(effective_date) latest_date, MAX(created_at) updated_at FROM `{$table}`");
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
