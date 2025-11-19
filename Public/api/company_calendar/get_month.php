<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($month < 1 || $month > 12) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid month']);
  exit;
}

$first = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth   = (int)date('t', strtotime($first));
$last          = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
$firstWeekday  = (int)date('w', strtotime($first)); // 0=Sun

/**
 * 給某一天建立預設結構（沒有政府資料、沒有覆寫）
 */
function cc_default_day(string $date): array {
  return [
    'date'                    => $date,

    // 政府原始資料
    'gov_is_holiday'          => 0,
    'gov_is_makeup_workday'   => 0,
    'gov_note'                => null,

    // 公司覆寫資訊
    'override_type'           => null,   // FORCE_HOLIDAY / FORCE_WORKDAY / null
    'override_note'           => null,

    // 最終判斷（公司實際採用）
    'final_is_holiday'        => 0,
    'final_is_makeup_workday' => 0,

    // 為了相容舊前端，維持原本欄位名稱 = final_*
    'is_holiday'              => 0,
    'is_makeup_workday'       => 0,

    // 使用者記事
    'user_notes'              => [],
    'more_count'              => 0,
  ];
}

try {
  // 1) 政府欄位 + 公司覆寫（final_* = 公司最後認定）
  $byDate = [];

  $stmt = $pdo->prepare("
    SELECT
      c.`date`,
      c.is_holiday        AS gov_is_holiday,
      c.is_makeup_workday AS gov_is_makeup_workday,
      c.`note`            AS gov_note,
      o.override_type,
      o.`note`            AS override_note,
      CASE
        WHEN o.override_type = 'FORCE_HOLIDAY' THEN 1
        WHEN o.override_type = 'FORCE_WORKDAY' THEN 0
        ELSE c.is_holiday
      END AS final_is_holiday,
      CASE
        WHEN o.override_type = 'FORCE_HOLIDAY' THEN 0
        WHEN o.override_type = 'FORCE_WORKDAY' THEN 0
        ELSE c.is_makeup_workday
      END AS final_is_makeup_workday
    FROM company_calendar c
    LEFT JOIN company_calendar_overrides o
      ON o.`date` = c.`date`
    WHERE c.`date` BETWEEN ? AND ?
    ORDER BY c.`date`
  ");
  $stmt->execute([$first, $last]);

  foreach ($stmt->fetchAll() as $r) {
    $d = $r['date'];

    $finalHoliday = (int)$r['final_is_holiday'];
    $finalMakeup  = (int)$r['final_is_makeup_workday'];

    $byDate[$d] = [
      'date'                    => $d,

      'gov_is_holiday'          => (int)$r['gov_is_holiday'],
      'gov_is_makeup_workday'   => (int)$r['gov_is_makeup_workday'],
      'gov_note'                => ($r['gov_note'] === '' ? null : $r['gov_note']),

      'override_type'           => $r['override_type'],        // 可能是 null
      'override_note'           => $r['override_note'],

      'final_is_holiday'        => $finalHoliday,
      'final_is_makeup_workday' => $finalMakeup,

      // 相容舊前端：直接等於 final_*
      'is_holiday'              => $finalHoliday,
      'is_makeup_workday'       => $finalMakeup,

      'user_notes'              => [],
      'more_count'              => 0,
    ];
  }

  // 2) 每日取前 2 筆使用者記事（置頂優先、再依建立時間新到舊）
  $stmt2 = $pdo->prepare("
    SELECT t.date, t.id, t.note, t.time_hhmm, t.is_pinned
    FROM (
      SELECT n.*,
             ROW_NUMBER() OVER (
               PARTITION BY n.date
               ORDER BY n.is_pinned DESC, n.created_at DESC
             ) AS rn
      FROM company_calendar_user_notes n
      WHERE n.date BETWEEN ? AND ?
    ) t
    WHERE t.rn <= 2
    ORDER BY t.date, t.is_pinned DESC, t.created_at DESC
  ");
  $stmt2->execute([$first, $last]);

  foreach ($stmt2->fetchAll() as $n) {
    $d = $n['date'];
    if (!isset($byDate[$d])) {
      $byDate[$d] = cc_default_day($d);
    }

    $byDate[$d]['user_notes'][] = [
      'id'        => (int)$n['id'],
      'text'      => $n['note'],
      'time_hhmm' => $n['time_hhmm'],
      'is_pinned' => (int)$n['is_pinned'],
    ];
  }

  // 3) 算剩餘筆數
  $stmt3 = $pdo->prepare("
    SELECT `date`, COUNT(*) AS cnt
    FROM company_calendar_user_notes
    WHERE `date` BETWEEN ? AND ?
    GROUP BY `date`
  ");
  $stmt3->execute([$first, $last]);

  foreach ($stmt3->fetchAll() as $c) {
    $d   = $c['date'];
    $cnt = (int)$c['cnt'];

    if (!isset($byDate[$d])) {
      $byDate[$d] = cc_default_day($d);
    }

    $byDate[$d]['more_count'] = max(0, $cnt - count($byDate[$d]['user_notes']));
  }

  // 4) 補齊整月（確保每一天都有一筆）
  $days = [];
  for ($i = 1; $i <= $daysInMonth; $i++) {
    $d = sprintf('%04d-%02d-%02d', $year, $month, $i);
    $days[] = $byDate[$d] ?? cc_default_day($d);
  }

  echo json_encode([
    'year'         => $year,
    'month'        => $month,
    'firstWeekday' => $firstWeekday,
    'daysInMonth'  => $daysInMonth,
    'days'         => $days,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error'  => 'Server error',
    'detail' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
