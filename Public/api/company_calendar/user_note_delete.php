<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$pdo = require __DIR__ . '/../../../config/db.php';
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($in['id'] ?? 0);
if ($id<=0){ http_response_code(400); echo json_encode(['error'=>'bad id']); exit; }
$stmt=$pdo->prepare("DELETE FROM company_calendar_user_notes WHERE id=?");
$stmt->execute([$id]);
echo json_encode(['ok'=>true, 'deleted'=>(int)$stmt->rowCount()], JSON_UNESCAPED_UNICODE);
