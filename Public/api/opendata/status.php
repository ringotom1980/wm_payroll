<?php
// Public/api/opendata/status.php
// 彙總三表現況 + 是否有 refresh_all 正在執行
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$lockFile = __DIR__ . '/../../../temp/gov_refresh.lock';

function is_running(string $file): bool {
  if (!is_file($file)) return false;
  $meta = @json_decode(@file_get_contents($file), true) ?: [];
  if (empty($meta['running'])) return false;
  $ts = (int)($meta['ts'] ?? 0);
  return (time() - $ts) <= 600; // 10 分鐘過期保護
}

try {
  /** @var PDO $pdo */
  $pdo = require __DIR__ . '/../../../config/db.php';
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $q = function(string $table) use ($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) cnt, MAX(effective_date) latest_date, MAX(created_at) updated_at FROM {$table}");
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
      'cnt'         => (int)($r['cnt'] ?? 0),
      'latest_date' => $r['latest_date'] ?: null,
      'updated_at'  => $r['updated_at'] ?: null,
    ];
  };

  echo json_encode([
    'ok'      => true,
    'running' => is_running($lockFile),
    'sources' => [
      'li'  => $q('gov_labor_insurance'),
      'lp'  => $q('gov_labor_pension'),
      'nhi' => $q('gov_nhi'),
    ],
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
