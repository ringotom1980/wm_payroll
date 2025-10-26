<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($month < 1 || $month > 12) { http_response_code(400); echo json_encode(['error'=>'Invalid month']); exit; }

$first = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int)date('t', strtotime($first));
$last  = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
$firstWeekday = (int)date('w', strtotime($first)); // 0=Sun

try {
  // 1) 政府欄位（note 改名為 gov_note）
  $byDate = [];
  $stmt = $pdo->prepare("
    SELECT `date`, is_holiday, is_makeup_workday, note AS gov_note
    FROM company_calendar
    WHERE `date` BETWEEN ? AND ?
    ORDER BY `date`
  ");
  $stmt->execute([$first, $last]);
  foreach ($stmt->fetchAll() as $r) {
    $byDate[$r['date']] = [
      'date' => $r['date'],
      'is_holiday' => (int)$r['is_holiday'],
      'is_makeup_workday' => (int)$r['is_makeup_workday'],
      'gov_note' => $r['gov_note'],
      'user_notes' => [],
      'more_count' => 0,
    ];
  }

  // 2) 每日取前 2 筆使用者記事（置頂優先、再依建立時間新到舊）
  $stmt2 = $pdo->prepare("
    SELECT t.date, t.id, t.note, t.time_hhmm, t.is_pinned
    FROM (
      SELECT n.*, ROW_NUMBER() OVER (PARTITION BY n.date ORDER BY n.is_pinned DESC, n.created_at DESC) AS rn
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
      $byDate[$d] = ['date'=>$d,'is_holiday'=>0,'is_makeup_workday'=>0,'gov_note'=>null,'user_notes'=>[],'more_count'=>0];
    }
    $byDate[$d]['user_notes'][] = [
      'id' => (int)$n['id'],
      'text' => $n['note'],
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
    $d = $c['date']; $cnt = (int)$c['cnt'];
    if (!isset($byDate[$d])) {
      $byDate[$d] = ['date'=>$d,'is_holiday'=>0,'is_makeup_workday'=>0,'gov_note'=>null,'user_notes'=>[],'more_count'=>0];
    }
    $byDate[$d]['more_count'] = max(0, $cnt - count($byDate[$d]['user_notes']));
  }

  // 4) 補齊整月
  $days = [];
  for ($i=1; $i <= $daysInMonth; $i++) {
    $d = sprintf('%04d-%02d-%02d', $year, $month, $i);
    $days[] = $byDate[$d] ?? ['date'=>$d,'is_holiday'=>0,'is_makeup_workday'=>0,'gov_note'=>null,'user_notes'=>[],'more_count'=>0];
  }

  echo json_encode([
    'year'=>$year, 'month'=>$month,
    'firstWeekday'=>$firstWeekday, 'daysInMonth'=>$daysInMonth, 'days'=>$days
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
