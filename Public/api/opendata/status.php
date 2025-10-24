<?php
// Public/api/opendata/status.php
// 彙總三表現況 + 是否有 refresh_all 正在執行
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$lockFile = __DIR__ . '/../../../temp/gov_refresh.lock';

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
  return (time() - $ts) <= 600; // 10 分鐘過期保護
}

try {
  /** @var PDO $pdo */
  $pdo = require __DIR__ . '/../../../config/db.php';
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  /**
   * 取得單表狀態
   * - 回傳：
   *   cnt:           筆數
   *   effective_date: LI/LP 為 YYYY-MM-DD；NHI 若無則回今年 YYYY
   *   created_at:    資料庫最新建立日 YYYY-MM-DD
   */
  $q = function(string $table, bool $isNhi = false) use ($pdo): array {
    // MAX(effective_date) 直接取 date；created_at 轉成 YYYY-MM-DD
    $sql = "
      SELECT
        COUNT(*)                          AS cnt,
        MAX(effective_date)               AS eff,
        DATE_FORMAT(MAX(created_at), '%Y-%m-%d') AS created_at
      FROM `{$table}`";
    $stmt = $pdo->query($sql);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'eff'=>null,'created_at'=>null];

    $cnt = (int)($r['cnt'] ?? 0);
    $eff = $r['eff'] ?: null;          // 可能為 NULL（尤其 NHI）
    $created = $r['created_at'] ?: null; // 已是 YYYY-MM-DD 或 NULL

    // 規則：NHI 若 eff 為空，改給今年度（YYYY）
    if ($isNhi) {
      if (empty($eff)) {
        $effective = date('Y'); // 只有年份
      } else {
        // NHI 有值也只回年份（前端需求：不要月日）
        $effective = substr((string)$eff, 0, 4);
      }
    } else {
      // LI/LP 正常回 YYYY-MM-DD（eff 已是 DATE）
      $effective = $eff ? (string)$eff : null;
    }

    return [
      'cnt'            => $cnt,
      'effective_date' => $effective,
      'created_at'     => $created,
    ];
  };

  echo json_encode([
    'ok'      => true,
    'running' => is_running($lockFile),
    'sources' => [
      'li'  => $q('gov_labor_insurance', false),
      'lp'  => $q('gov_labor_pension',  false),
      'nhi' => $q('gov_nhi',             true), // ✅ 年份處理在後端完成
    ],
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
