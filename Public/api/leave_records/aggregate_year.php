<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo  = require __DIR__ . '/../../../config/db.php';
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$monthForNote = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($year < 1900 || $year > 2100 || $monthForNote < 1 || $monthForNote > 12) {
  http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit;
}

/** 快速偵測欄位是否存在（結果快取） */
function hasColumn(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table . '|' . $col;
  if (isset($cache[$key])) return $cache[$key];
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->execute([$table, $col]);
  return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

/** 年度額度：先讀快照；沒有就用簡化算法（HIRE_DATE 基準，四捨五入到 0.5） */
function annual_quota(PDO $pdo, int $empId, int $year): float {
  try {
    $q = $pdo->prepare("SELECT annual_quota_days FROM leave_annual_quota WHERE emp_id=? AND year=? LIMIT 1");
    $q->execute([$empId, $year]);
    $v = $q->fetchColumn();
    if ($v !== false) return (float)$v;
  } catch (Throwable $e) {}

  $stmt = $pdo->prepare("SELECT hire_date FROM employees WHERE emp_id=? AND (is_deleted IS NULL OR is_deleted=0)");
  $stmt->execute([$empId]);
  $hire = $stmt->fetchColumn();
  if (!$hire) return 0.0;

  $hireDt = new DateTime($hire);
  $yEnd   = new DateTime(sprintf('%04d-12-31', $year));
  if ($yEnd < $hireDt) return 0.0;

  $diff  = $hireDt->diff($yEnd);
  $years = $diff->y + $diff->m/12.0;
  $days  = 0.0;
  if     ($years >= 0.5 && $years < 1)  $days = 3.0;
  elseif ($years >= 1   && $years < 2)  $days = 7.0;
  elseif ($years >= 2   && $years < 3)  $days = 10.0;
  elseif ($years >= 3   && $years < 5)  $days = 14.0;
  elseif ($years >= 5   && $years < 10) $days = 15.0;
  elseif ($years >= 10)                 $days = min(30.0, 15.0 + (int)floor($years) - 10);
  return round($days * 2) / 2.0;
}

try {
  // 名稱欄位用 full_name；只撈在職且未刪除
  $hasPLS = hasColumn($pdo, 'employees', 'parental_leave_start');
  $hasPLE = hasColumn($pdo, 'employees', 'parental_leave_end');
  $plCols = [];
  if ($hasPLS) $plCols[] = 'parental_leave_start';
  if ($hasPLE) $plCols[] = 'parental_leave_end';
  $plSelect = $plCols ? (', ' . implode(', ', $plCols)) : '';

  $sqlEmp = "SELECT emp_id, full_name AS emp_name, hire_date{$plSelect}
             FROM employees
             WHERE status='ACTIVE' AND (is_deleted IS NULL OR is_deleted=0)
             ORDER BY emp_id";
  $emps = $pdo->query($sqlEmp)->fetchAll();

  $start = sprintf('%04d-01-01', $year);
  $end   = sprintf('%04d-12-31', $year);

  // 當年全部請假
  $stmt = $pdo->prepare("SELECT emp_id, leave_code FROM leave_records WHERE `date` BETWEEN ? AND ?");
  $stmt->execute([$start, $end]);
  $rows = $stmt->fetchAll();

  $sum = [];
  foreach ($rows as $r) {
    $eid = (int)$r['emp_id'];
    $code = $r['leave_code'];
    $sum[$eid][$code] = ($sum[$eid][$code] ?? 0) + 0.5;
  }

  // 指定月份的月備註
  $stmtMN = $pdo->prepare("SELECT emp_id, note FROM leave_month_notes WHERE year=? AND month=?");
  $stmtMN->execute([$year, $monthForNote]);
  $notesByEmp = [];
  foreach ($stmtMN->fetchAll() as $n) {
    $notesByEmp[(int)$n['emp_id']] = $n['note'];
  }

  $items = [];
  foreach ($emps as $e) {
    $eid   = (int)$e['emp_id'];
    $name  = $e['emp_name'] ?? ('E'.$eid);
    $quota = annual_quota($pdo, $eid, $year);
    $used  = (float)($sum[$eid]['ANNUAL'] ?? 0.0);
    $left  = max(0.0, $quota - $used);

    $plStart = $hasPLS ? ($e['parental_leave_start'] ?? null) : null;
    $plEnd   = $hasPLE ? ($e['parental_leave_end'] ?? null)   : null;

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
      'parental_leave_period' => ['start' => $plStart, 'end' => $plEnd],
      'month_note' => $notesByEmp[$eid] ?? null
    ];
  }

  echo json_encode(['year'=>$year, 'month'=>$monthForNote, 'items'=>$items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
