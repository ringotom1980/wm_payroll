<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo   = require __DIR__ . '/../../../config/db.php';
$empId = isset($_GET['emp_id']) ? (int)$_GET['emp_id'] : 0;
$year  = isset($_GET['year'])   ? (int)$_GET['year']   : (int)date('Y');
$month = isset($_GET['month'])  ? (int)$_GET['month']  : (int)date('n');

if ($empId <= 0 || $month < 1 || $month > 12) { http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit; }

$first = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth = (int)date('t', strtotime($first));
$last  = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

try {
  $stmt = $pdo->prepare("SELECT id, leave_code, `date`, slot, note FROM leave_records WHERE emp_id=? AND `date` BETWEEN ? AND ? ORDER BY `date`, FIELD(slot,'AM','PM')");
  $stmt->execute([$empId, $first, $last]);
  $rows = $stmt->fetchAll();

  $byDate = [];
  for ($d=1; $d <= $daysInMonth; $d++) {
    $k = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $byDate[$k] = ['date'=>$k, 'AM'=>null, 'PM'=>null];
  }
  foreach ($rows as $r) {
    $byDate[$r['date']][$r['slot']] = [
      'id'=>(int)$r['id'],
      'code'=>$r['leave_code'],
      'note'=>$r['note']
    ];
  }

  // 月備註
  $stmt2 = $pdo->prepare("SELECT note FROM leave_month_notes WHERE emp_id=? AND year=? AND month=?");
  $stmt2->execute([$empId, $year, $month]);
  $monthNote = $stmt2->fetchColumn();

  // 育嬰留停期間（鎖定）
  $stmt3 = $pdo->prepare("SELECT parental_leave_start, parental_leave_end FROM employees WHERE emp_id=?");
  $stmt3->execute([$empId]);
  $pl = $stmt3->fetch();

  echo json_encode([
    'emp_id'=>$empId, 'year'=>$year, 'month'=>$month,
    'days'=>array_values($byDate),
    'month_note'=>$monthNote,
    'parental_leave'=>['start'=>$pl['parental_leave_start'] ?? null, 'end'=>$pl['parental_leave_end'] ?? null]
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
