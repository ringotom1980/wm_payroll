<?php
// Public/api/leave_records/aggregate_year.php
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

  // 年度合計 + 當月合計
  $sumYear = [];
  $sumMonth = [];

  foreach ($rows as $r) {
    $eid   = (int)$r['emp_id'];
    $code  = $r['leave_code'];
    $date  = $r['date'];
    $m     = (int)substr($date, 5, 2);

    if ($code === null || $code === '') {
      continue;
    }

    if ($r['slot'] === 'HRS') {
      $h = (int)($r['hours'] ?? 0);
      $v = $h / 8.0;
    } else {
      $v = 0.5;
    }

    // 全年
    $sumYear[$eid][$code] = ($sumYear[$eid][$code] ?? 0.0) + $v;

    // 當月
    if ($m === $monthForNote) {
      $sumMonth[$eid][$code] = ($sumMonth[$eid][$code] ?? 0.0) + $v;
    }
  }

  // 月備註（只有「當月」）
  $stmtMN = $pdo->prepare("SELECT emp_id, note FROM leave_month_notes WHERE year=? AND month=?");
  $stmtMN->execute([$year, $monthForNote]);
  $notesByEmp = [];
  foreach ($stmtMN->fetchAll() as $n) $notesByEmp[(int)$n['emp_id']] = $n['note'];

  $items = [];
  foreach ($emps as $e) {
    $eid  = (int)$e['emp_id'];
    $name = $e['emp_name'] ?? ('E'.$eid);

    // 規定特休（純演算法，不看覆蓋）
    $statutory = calc_calendar_year_quota_statutory($pdo, $eid, $year);
    // 實給特休（人工覆蓋值；可能為 null）
    $manual = get_manual_quota_override($pdo, $eid, $year);

    // 年度已休特休（全年）
    $usedYear  = (float)($sumYear[$eid]['ANNUAL'] ?? 0.0);
    // 當月已休特休
    $usedMonth = (float)($sumMonth[$eid]['ANNUAL'] ?? 0.0);

    // 剩餘 = （有實給→用實給，否則→用規定） - 年度已休
    $baseForLeft = ($manual !== null ? $manual : $statutory);
    $left = max(0.0, $baseForLeft - $usedYear);

    $pls = $e['parental_leave_start'] ?? null;
    $ple = $e['expected_return_date'] ?? null;

    // 整年度合計
    $leaveYear = [
      'SICK'           => (float)($sumYear[$eid]['SICK'] ?? 0.0),
      'OCC_SICK'       => (float)($sumYear[$eid]['OCC_SICK'] ?? 0.0),
      'MARRIAGE'       => (float)($sumYear[$eid]['MARRIAGE'] ?? 0.0),
      'FUNERAL'        => (float)($sumYear[$eid]['FUNERAL'] ?? 0.0),
      'PERSONAL'       => (float)($sumYear[$eid]['PERSONAL'] ?? 0.0),
      'MATERNITY'      => (float)($sumYear[$eid]['MATERNITY'] ?? 0.0),
      'PATERNITY'      => (float)($sumYear[$eid]['PATERNITY'] ?? 0.0),
      'FAMILY_CARE'    => (float)($sumYear[$eid]['FAMILY_CARE'] ?? 0.0),
      'MENSTRUAL'      => (float)($sumYear[$eid]['MENSTRUAL'] ?? 0.0),
      'PARENTAL_LEAVE' => (float)($sumYear[$eid]['PARENTAL_LEAVE'] ?? 0.0),
    ];

    // 當月合計（沒有就給 0）
    $leaveMonth = [
      'SICK'           => (float)($sumMonth[$eid]['SICK'] ?? 0.0),
      'OCC_SICK'       => (float)($sumMonth[$eid]['OCC_SICK'] ?? 0.0),
      'MARRIAGE'       => (float)($sumMonth[$eid]['MARRIAGE'] ?? 0.0),
      'FUNERAL'        => (float)($sumMonth[$eid]['FUNERAL'] ?? 0.0),
      'PERSONAL'       => (float)($sumMonth[$eid]['PERSONAL'] ?? 0.0),
      'MATERNITY'      => (float)($sumMonth[$eid]['MATERNITY'] ?? 0.0),
      'PATERNITY'      => (float)($sumMonth[$eid]['PATERNITY'] ?? 0.0),
      'FAMILY_CARE'    => (float)($sumMonth[$eid]['FAMILY_CARE'] ?? 0.0),
      'MENSTRUAL'      => (float)($sumMonth[$eid]['MENSTRUAL'] ?? 0.0),
      'PARENTAL_LEAVE' => (float)($sumMonth[$eid]['PARENTAL_LEAVE'] ?? 0.0),
    ];

    $items[] = [
      'emp_id' => $eid,
      'name'   => $name,

      // ※ 保留 annual_quota 供相容（沿用舊欄位名稱），其值等於「規定特休」
      'annual_quota'            => $statutory,

      // 新欄位（你原本就有）：規定 / 實給
      'annual_quota_statutory'  => $statutory,
      'annual_quota_actual'     => $manual,       // 可能為 null

      // 特休：全年已休 + 當月已休 + 剩餘
      'annual_used'        => $usedYear,          // 整年
      'annual_used_month'  => $usedMonth,         // 當月
      'annual_left'        => $left,

      // 假別合計（整年）
      'leave'        => $leaveYear,
      // 假別當月合計
      'leave_month'  => $leaveMonth,

      'parental_leave_period' => ['start'=>$pls, 'end'=>$ple],
      'month_note'            => $notesByEmp[$eid] ?? null,
    ];
  }

  echo json_encode([
    'year'  => $year,
    'month' => $monthForNote,
    'items' => $items
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
