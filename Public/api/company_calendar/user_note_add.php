<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$pdo = require __DIR__ . '/../../../config/db.php';
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$date = $in['date'] ?? '';
$text = trim((string)($in['text'] ?? ''));
$time = isset($in['time_hhmm']) ? trim((string)$in['time_hhmm']) : null;
$isPinned = (int)($in['is_pinned'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $text==='') { http_response_code(400); echo json_encode(['error'=>'bad request']); exit; }

$stmt = $pdo->prepare("INSERT INTO company_calendar_user_notes (`date`,`note`,`time_hhmm`,`is_pinned`) VALUES (?,?,?,?)");
$stmt->execute([$date, $text, $time ?: null, $isPinned ? 1 : 0]);
echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
