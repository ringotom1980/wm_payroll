<?php
// Public/api/opendata/status.php
// 回傳政府資料同步狀態與目前三表的最新時間

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$lockFile = __DIR__ . '/../../../temp/gov_refresh.lock';

try {
  /** @var PDO $pdo */
  $pdo = require __DIR__ . '/../../../config/db.php';
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $running = false;
  $job_id  = null;
  if (is_file($lockFile)) {
    $meta = @json_decode(@file_get_contents($lockFile), true) ?: [];
    $running = !empty($meta['running']);
    $job_id  = $meta['job_id'] ?? null;

    // 簡單防呆：超過 10 分鐘視為異常/過期
    $ts = (int)($meta['ts'] ?? 0);
    if ($running && (time() - $ts) > 600) {
      $running = false;
    }
  }

  $q = function(string $table) use ($pdo) {
    $stmt = $pdo->query("SELECT MAX(effective_date) AS effective_date, MAX(created_at) AS updated_at, COUNT(*) AS cnt FROM {$table}");
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
      'effective_date' => $r['effective_date'] ?: null,
      'updated_at'     => $r['updated_at'] ?: null,
      'count'          => (int)($r['cnt'] ?? 0),
    ];
  };

  echo json_encode([
    'ok'      => true,
    'running' => $running,
    'job_id'  => $job_id,
    'sources' => [
      'lp'  => $q('gov_labor_pension'),
      'li'  => $q('gov_labor_insurance'),
      'nhi' => $q('gov_nhi'),
    ],
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
