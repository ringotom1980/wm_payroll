<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$pdo = require __DIR__ . '/../../../config/db.php';
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($in['id'] ?? 0);
$text = isset($in['text']) ? trim((string)$in['text']) : null;
$time = array_key_exists('time_hhmm',$in) ? (string)$in['time_hhmm'] : null;
$isPinned = isset($in['is_pinned']) ? ((int)$in['is_pinned'] ? 1 : 0) : null;
if ($id<=0) { http_response_code(400); echo json_encode(['error'=>'bad id']); exit; }

$set = []; $bind=[];
if ($text!==null){ $set[]="note=?"; $bind[]=$text; }
if ($time!==null){ $set[]="time_hhmm=?"; $bind[]=$time ?: null; }
if ($isPinned!==null){ $set[]="is_pinned=?"; $bind[]=$isPinned; }
if (!$set){ echo json_encode(['ok'=>true]); exit; }
$bind[]=$id;

$sql="UPDATE company_calendar_user_notes SET ".implode(',', $set).", updated_at=NOW() WHERE id=?";
$stmt=$pdo->prepare($sql); $stmt->execute($bind);
echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
