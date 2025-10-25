<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo   = require __DIR__ . '/../../../config/db.php';
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$empId = (int)($input['emp_id'] ?? 0);
$date  = (string)($input['date'] ?? '');
$slot  = (string)($input['slot'] ?? '');

if ($empId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($slot, ['AM','PM'], true)) {
  http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit;
}

try {
  $stmt = $pdo->prepare("DELETE FROM leave_records WHERE emp_id=? AND `date`=? AND slot=?");
  $stmt->execute([$empId, $date, $slot]);
  echo json_encode(['ok'=>true, 'deleted'=>(int)$stmt->rowCount()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
