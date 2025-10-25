<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

// 允許 JSON 或表單
$raw = file_get_contents('php://input');
$input = $raw ? (json_decode($raw, true) ?? []) : $_POST;
$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid id']); exit; }

try {
  $stmt = $pdo->prepare("DELETE FROM company_calendar_events WHERE id=?");
  $stmt->execute([$id]);
  echo json_encode(['ok'=>true, 'deleted'=>(int)$stmt->rowCount()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
