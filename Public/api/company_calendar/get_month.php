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
  // 每日狀態
  $stmt = $pdo->prepare("SELECT `date`, is_holiday, is_makeup_workday, note FROM company_calendar WHERE `date` BETWEEN ? AND ? ORDER BY `date`");
  $stmt->execute([$first, $last]);
  $byDate = [];
  foreach ($stmt->fetchAll() as $r) {
    $byDate[$r['date']] = [
      'date' => $r['date'],
      'is_holiday' => (int)$r['is_holiday'],
      'is_makeup_workday' => (int)$r['is_makeup_workday'],
      'note' => $r['note'],
      'events' => []
    ];
  }

  // 事件
  $stmt2 = $pdo->prepare("SELECT id, `date`, category, title, remark, source, updated_at FROM company_calendar_events WHERE `date` BETWEEN ? AND ? ORDER BY `date`, id");
  $stmt2->execute([$first, $last]);
  foreach ($stmt2->fetchAll() as $e) {
    $d = $e['date'];
    if (!isset($byDate[$d])) {
      $byDate[$d] = ['date'=>$d,'is_holiday'=>0,'is_makeup_workday'=>0,'note'=>null,'events'=>[]];
    }
    $byDate[$d]['events'][] = [
      'id'=>(int)$e['id'],'category'=>$e['category'],'title'=>$e['title'],'remark'=>$e['remark'],
      'source'=>$e['source'],'updated_at'=>$e['updated_at']
    ];
  }

  // 補齊
  $days = [];
  for ($i=1; $i <= $daysInMonth; $i++) {
    $d = sprintf('%04d-%02d-%02d', $year, $month, $i);
    $days[] = $byDate[$d] ?? ['date'=>$d,'is_holiday'=>0,'is_makeup_workday'=>0,'note'=>null,'events'=>[]];
  }

  echo json_encode([
    'year'=>$year,'month'=>$month,
    'firstWeekday'=>$firstWeekday,'daysInMonth'=>$daysInMonth,'days'=>$days
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
