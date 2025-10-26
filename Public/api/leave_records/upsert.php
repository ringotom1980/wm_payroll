<?php
// Public/api/leave_records/upsert.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/_annual_quota.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$empId = (int)($input['emp_id'] ?? 0);
$date  = (string)($input['date'] ?? '');
$slot  = (string)($input['slot'] ?? '');
$code  = (string)($input['leave_code'] ?? '');
$note  = isset($input['note']) ? trim((string)$input['note']) : null;
$hours = isset($input['hours']) ? (int)$input['hours'] : null;

$allowCode = ['ANNUAL','SICK','OCC_SICK','MARRIAGE','FUNERAL','PERSONAL','MATERNITY','PATERNITY','FAMILY_CARE','MENSTRUAL','PARENTAL_LEAVE'];
$allowSlot = ['AM','PM','HRS'];
if ($empId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($slot, $allowSlot, true) || !in_array($code, $allowCode, true)) {
  http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit;
}

/** 育嬰留停區間鎖定：在範圍內僅允許操作 PARENTAL_LEAVE 欄位 */
$empStmt = $pdo->prepare("SELECT parental_leave_start, expected_return_date FROM employees WHERE emp_id=? LIMIT 1");
$empStmt->execute([$empId]);
$emp = $empStmt->fetch();
$pls = $emp['parental_leave_start'] ?? null;
$ple = $emp['expected_return_date'] ?? null;

$inLock = false;
if ($pls && $ple)       $inLock = ($date >= $pls && $date <= $ple);
elseif (!$pls && $ple)  $inLock = ($date <= $ple);
if ($inLock && $code !== 'PARENTAL_LEAVE') {
  http_response_code(400);
  echo json_encode(['error'=>'IN_PARENTAL_LEAVE_RANGE','message'=>'該員育嬰留停期間，僅可編輯育嬰留停欄位'], JSON_UNESCAPED_UNICODE);
  exit;
}

/** 特休餘額檢核（AM/PM 每次 0.5 天；HRS 換算 hours/8 天） **/
if ($code === 'ANNUAL') {
  $year = (int)substr($date, 0, 4);

  // 用共用演算法：曆年制＋扣除育嬰留停期間；若有手動覆蓋則用覆蓋值
  $quota = calc_calendar_year_quota($pdo, $empId, $year);

  // 年度已用（含 HRS 換算）
  $stmtUsed = $pdo->prepare("SELECT SUM(CASE WHEN slot='HRS' THEN (hours/8.0) ELSE 0.5 END)
                             FROM leave_records
                             WHERE emp_id=? AND YEAR(`date`)=? AND leave_code='ANNUAL'");
  $stmtUsed->execute([$empId, $year]);
  $used = (float)($stmtUsed->fetchColumn() ?: 0.0);

  // 本次增量
  $delta = 0.0;
  if ($slot === 'AM' || $slot === 'PM') {
    $stmtCur = $pdo->prepare("SELECT leave_code FROM leave_records WHERE emp_id=? AND `date`=? AND slot=?");
    $stmtCur->execute([$empId, $date, $slot]);
    $orig = $stmtCur->fetchColumn();
    $delta = ($orig === 'ANNUAL') ? 0.0 : 0.5;
  } else { // HRS
    $hInc = max(0, min(8, (int)$hours));
    $delta = $hInc / 8.0;

    // 若同日已有 ANNUAL 的 AM/PM，HRS 上限 4
    $chk = $pdo->prepare("SELECT COUNT(*) FROM leave_records
                          WHERE emp_id=? AND `date`=? AND slot IN ('AM','PM') AND leave_code='ANNUAL'");
    $chk->execute([$empId, $date]);
    if ((int)$chk->fetchColumn() > 0 && $hInc > 4) {
      http_response_code(400);
      echo json_encode(['error'=>'HRS_LIMIT_4_WHEN_HALF','message'=>'已選半天時，小時上限為 4 小時'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  if (($quota - $used) + 1e-9 < $delta) {
    http_response_code(400);
    echo json_encode(['error'=>'ANNUAL_SHORTAGE','message'=>sprintf('剩餘特休不足（尚餘 %.1f 天）', max(0.0, round($quota - $used, 1)))], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/** HRS 規則檢核（整數 0–8；和 AM/PM 的互斥/上限） **/
if ($slot === 'HRS') {
  if ($hours === null || $hours < 0 || $hours > 8) {
    http_response_code(400); echo json_encode(['error'=>'HRS_RANGE','message'=>'小時須為 0–8 的整數']); exit;
  }
  // 同日是否同時有 AM 與 PM
  $stmt2 = $pdo->prepare("SELECT GROUP_CONCAT(slot) FROM leave_records WHERE emp_id=? AND `date`=? AND slot IN ('AM','PM')");
  $stmt2->execute([$empId, $date]);
  $have = (string)$stmt2->fetchColumn(); // "AM,PM" | "AM" | "PM" | null

  $bothHalf = (strpos($have, 'AM') !== false) && (strpos($have, 'PM') !== false);
  if ($bothHalf && $hours > 0) {
    http_response_code(400); echo json_encode(['error'=>'HRS_LOCKED_BY_AMPM','message'=>'上午與下午都已選，小時鎖定為 0']); exit;
  }
  $oneHalf = (!$bothHalf && ($have !== null && $have !== ''));
  if ($oneHalf && $hours > 4) {
    http_response_code(400); echo json_encode(['error'=>'HRS_LIMIT_4_WHEN_HALF','message'=>'只選一半天時，小時上限為 4']); exit;
  }
}

try {
  if ($slot === 'HRS') {
    // hours=0 也可 upsert 成 0（或由前端呼叫 delete.php 刪除皆可）
    $sql = "INSERT INTO leave_records (emp_id, `date`, slot, leave_code, hours, note, source, created_by, created_at, updated_at)
            VALUES (?, ?, 'HRS', ?, ?, ?, 'MANUAL', NULL, NOW(), NOW())
            ON DUPLICATE KEY UPDATE leave_code=VALUES(leave_code), hours=VALUES(hours), note=VALUES(note), updated_at=NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empId, $date, $code, $hours, $note]);
  } else {
    $sql = "INSERT INTO leave_records (emp_id, `date`, slot, leave_code, note, source, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'MANUAL', NULL, NOW(), NOW())
            ON DUPLICATE KEY UPDATE leave_code=VALUES(leave_code), note=VALUES(note), updated_at=NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empId, $date, $slot, $code, $note]);
  }
  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
