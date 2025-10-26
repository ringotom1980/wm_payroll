<?php
// Public/api/leave_records/month_notes.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  $empId = isset($_GET['emp_id']) ? (int)$_GET['emp_id'] : 0;
  $year  = isset($_GET['year'])   ? (int)$_GET['year']   : (int)date('Y');
  $month = isset($_GET['month'])  ? (int)$_GET['month']  : (int)date('n');

  if ($empId <= 0 || $month < 1 || $month > 12) { http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit; }

  try {
    $stmt = $pdo->prepare("SELECT note, updated_at FROM leave_month_notes WHERE emp_id=? AND year=? AND month=?");
    $stmt->execute([$empId, $year, $month]);
    $row = $stmt->fetch();
    echo json_encode(['emp_id'=>$empId, 'year'=>$year, 'month'=>$month, 'note'=>$row['note'] ?? null, 'updated_at'=>$row['updated_at'] ?? null], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($method === 'POST') {
  $input = json_decode(file_get_contents('php://input'), true) ?? [];
  $empId = (int)($input['emp_id'] ?? 0);
  $year  = (int)($input['year']   ?? date('Y'));
  $month = (int)($input['month']  ?? date('n'));
  $note  = isset($input['note']) ? (string)$input['note'] : '';

  if ($empId <= 0 || $month < 1 || $month > 12) { http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit; }

  try {
    $sql = "INSERT INTO leave_month_notes (emp_id, year, month, note, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE note=VALUES(note), updated_at=NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empId, $year, $month, $note]);
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

http_response_code(405);
echo json_encode(['error'=>'Method not allowed']);
