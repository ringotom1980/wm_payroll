<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo  = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/_annual_quota.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$monthForNote = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($year < 1900 || $year > 2100 || $monthForNote < 1 || $monthForNote > 12) {
  http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit;
}

/** 改為呼叫共用：曆年制＋扣除育嬰留停期間 */
function annual_quota(PDO $pdo, int $empId, int $year): float {
  return calc_calendar_year_quota($pdo, $empId, $year);
}

try {
  $sqlEmp = "SELECT emp_id, full_name AS emp_name, hire_date, status, parental_leave_start, expected_return_date
             FROM employees
             WHERE (is_deleted IS NULL OR is_deleted=0)
             ORDER BY emp_id";
  $emps = $pdo->query($sqlEmp)->fetchAll();

  $start = sprintf('%04d-01-01', $year);
  $end   = sprintf('%04d-12-31', $year);

  $stmt = $pdo->prepare("SELECT emp_id, leave_code, `date`, slot, hours
                         FROM leave_records
                         WHERE `date` BETWEEN ? AND ?");
  $stmt->execute([$start, $end]);
  $rows = $stmt->fetchAll();

  $sum = [];
  foreach ($rows as $r) {
    $eid  = (int)$r['emp_id'];
    $code = $r['leave_code'];
    if ($r['slot'] === 'HRS') {
      $h = (int)($r['hours'] ?? 0);
      $sum[$eid][$code] = ($sum[$eid][$code] ?? 0) + ($h / 8.0);
    } else {
      $sum[$eid][$code] = ($sum[$eid][$code] ?? 0) + 0.5;
    }
  }

  $stmtMN = $pdo->prepare("SELECT emp_id, note FROM leave_month_notes WHERE year=? AND month=?");
  $stmtMN->execute([$year, $monthForNote]);
  $notesByEmp = [];
  foreach ($stmtMN->fetchAll() as $n) $notesByEmp[(int)$n['emp_id']] = $n['note'];

  $items = [];
  foreach ($emps as $e) {
    $eid   = (int)$e['emp_id'];
    $name  = $e['emp_name'] ?? ('E'.$eid);
    $quota = annual_quota($pdo, $eid, $year);
    $used  = (float)($sum[$eid]['ANNUAL'] ?? 0.0);
    $left  = max(0.0, $quota - $used);

    $pls = $e['parental_leave_start'] ?? null;
    $ple = $e['expected_return_date'] ?? null;

    $items[] = [
      'emp_id' => $eid,
      'name'   => $name,
      'annual_quota' => $quota,
      'annual_used'  => $used,
      'annual_left'  => $left,
      'leave' => [
        'SICK' => (float)($sum[$eid]['SICK'] ?? 0.0),
        'OCC_SICK' => (float)($sum[$eid]['OCC_SICK'] ?? 0.0),
        'MARRIAGE' => (float)($sum[$eid]['MARRIAGE'] ?? 0.0),
        'FUNERAL' => (float)($sum[$eid]['FUNERAL'] ?? 0.0),
        'PERSONAL' => (float)($sum[$eid]['PERSONAL'] ?? 0.0),
        'MATERNITY' => (float)($sum[$eid]['MATERNITY'] ?? 0.0),
        'PATERNITY' => (float)($sum[$eid]['PATERNITY'] ?? 0.0),
        'FAMILY_CARE' => (float)($sum[$eid]['FAMILY_CARE'] ?? 0.0),
        'MENSTRUAL' => (float)($sum[$eid]['MENSTRUAL'] ?? 0.0),
        'PARENTAL_LEAVE' => (float)($sum[$eid]['PARENTAL_LEAVE'] ?? 0.0),
      ],
      'parental_leave_period' => ['start'=>$pls, 'end'=>$ple],
      'month_note' => $notesByEmp[$eid] ?? null
    ];
  }

  echo json_encode(['year'=>$year, 'month'=>$monthForNote, 'items'=>$items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
