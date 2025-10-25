<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$empId = (int)($input['emp_id'] ?? 0);
$date  = (string)($input['date'] ?? '');
$slot  = (string)($input['slot'] ?? '');
$code  = (string)($input['leave_code'] ?? '');
$note  = isset($input['note']) ? trim((string)$input['note']) : null;

$allow = ['ANNUAL','SICK','OCC_SICK','MARRIAGE','FUNERAL','PERSONAL','MATERNITY','PATERNITY','FAMILY_CARE','MENSTRUAL','PARENTAL_LEAVE'];
if ($empId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($slot, ['AM','PM'], true) || !in_array($code, $allow, true)) {
  http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit;
}

function hasColumn(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table . '|' . $col;
  if (isset($cache[$key])) return $cache[$key];
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->execute([$table, $col]);
  return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

// 若有育嬰欄位才做期間鎖定
$hasPLS = hasColumn($pdo, 'employees', 'parental_leave_start');
$hasPLE = hasColumn($pdo, 'employees', 'parental_leave_end');
if ($hasPLS || $hasPLE) {
  $sel = [];
  if ($hasPLS) $sel[] = 'parental_leave_start';
  if ($hasPLE) $sel[] = 'parental_leave_end';
  $sql = "SELECT " . implode(',', $sel) . " FROM employees WHERE emp_id=? LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$empId]);
  $row = $stmt->fetch();
  $pls = $hasPLS ? ($row['parental_leave_start'] ?? null) : null;
  $ple = $hasPLE ? ($row['parental_leave_end'] ?? null)   : null;
  if ($pls && $ple && $date >= $pls && $date <= $ple) {
    http_response_code(400);
    echo json_encode(['error'=>'IN_PARENTAL_LEAVE_RANGE','message'=>'該員育嬰留停中，僅可編輯非育嬰留停欄位（此期間鎖定不可編）'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// 特休餘額檢核（ANNUAL 不可使剩餘 < 0）
if ($code === 'ANNUAL') {
  $year = (int)substr($date, 0, 4);
  $quota = 0.0;
  try {
    $q = $pdo->prepare("SELECT annual_quota_days FROM leave_annual_quota WHERE emp_id=? AND year=? LIMIT 1");
    $q->execute([$empId, $year]);
    $v = $q->fetchColumn();
    if ($v !== false) $quota = (float)$v;
  } catch (Throwable $e) {}
  if ($quota <= 0) {
    $h = $pdo->prepare("SELECT hire_date FROM employees WHERE emp_id=?");
    $h->execute([$empId]);
    $hire = $h->fetchColumn();
    if ($hire) {
      $hireDt = new DateTime($hire);
      $yEnd   = new DateTime(sprintf('%04d-12-31', $year));
      $diff   = $hireDt->diff($yEnd);
      $years  = $diff->y + $diff->m/12.0;
      if     ($years >= 0.5 && $years < 1)  $quota = 3.0;
      elseif ($years >= 1   && $years < 2)  $quota = 7.0;
      elseif ($years >= 2   && $years < 3)  $quota = 10.0;
      elseif ($years >= 3   && $years < 5)  $quota = 14.0;
      elseif ($years >= 5   && $years < 10) $quota = 15.0;
      elseif ($years >= 10)                 $quota = min(30.0, 15.0 + (int)floor($years) - 10);
      $quota = round($quota * 2) / 2.0;
    }
  }

  $stmtUsed = $pdo->prepare("SELECT SUM(0.5) FROM leave_records WHERE emp_id=? AND YEAR(`date`)=? AND leave_code='ANNUAL'");
  $stmtUsed->execute([$empId, $year]);
  $used = (float)($stmtUsed->fetchColumn() ?: 0.0);

  $stmtCur = $pdo->prepare("SELECT leave_code FROM leave_records WHERE emp_id=? AND `date`=? AND slot=?");
  $stmtCur->execute([$empId, $date, $slot]);
  $orig = $stmtCur->fetchColumn();
  $delta = ($orig === 'ANNUAL') ? 0.0 : 0.5;

  if (($quota - $used) < $delta - 1e-9) {
    http_response_code(400);
    echo json_encode([
      'error'=>'ANNUAL_SHORTAGE',
      'message'=>sprintf('剩餘特休不足，無法新增此筆（年度剩餘：%.1f 天）', max(0.0, $quota - $used))
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

try {
  $sql = "INSERT INTO leave_records (emp_id, `date`, slot, leave_code, note, source, created_by, created_at, updated_at)
          VALUES (?, ?, ?, ?, ?, 'MANUAL', NULL, NOW(), NOW())
          ON DUPLICATE KEY UPDATE leave_code=VALUES(leave_code), note=VALUES(note), updated_at=NOW()";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$empId, $date, $slot, $code, $note]);
  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
