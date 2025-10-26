<?php
// Public/api/leave_records/annual_quota_set.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo   = require __DIR__ . '/../../../config/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$empId = (int)($input['emp_id'] ?? 0);
$year  = (int)($input['year']   ?? date('Y'));
$val   = $input['value'] ?? null; // null → 刪除覆蓋；數字 → 覆蓋值(DECIMAL(4,1))

if ($empId <= 0 || $year < 1900 || $year > 2100) {
  http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit;
}

try {
  if ($val === null || $val === '') {
    // 刪除覆蓋
    $stmt = $pdo->prepare("DELETE FROM leave_annual_quota WHERE emp_id=? AND year=?");
    $stmt->execute([$empId, $year]);
  } else {
    $v = round((float)$val, 1);
    $stmt = $pdo->prepare("
      INSERT INTO leave_annual_quota (emp_id, year, annual_quota_days, updated_at)
      VALUES (?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE annual_quota_days=VALUES(annual_quota_days), updated_at=NOW()
    ");
    $stmt->execute([$empId, $year, $v]);
  }
  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
