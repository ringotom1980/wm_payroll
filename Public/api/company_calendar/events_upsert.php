<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id      = isset($input['id']) ? (int)$input['id'] : 0;
$date    = $input['date'] ?? null;
$cat     = $input['category'] ?? '';
$title   = trim((string)($input['title'] ?? ''));
$remark  = isset($input['remark']) ? trim((string)$input['remark']) : null;
$source  = $input['source'] ?? 'MANUAL';

if ($id <= 0 && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) { http_response_code(400); echo json_encode(['error'=>'Invalid date']); exit; }
if (!in_array($cat, ['GOV_HOLIDAY','MAKEUP','COMPANY_EVENT'], true)) { http_response_code(400); echo json_encode(['error'=>'Invalid category']); exit; }
if ($title === '') { http_response_code(400); echo json_encode(['error'=>'Title required']); exit; }
if (!in_array($source, ['CSV_GOV','MANUAL'], true)) { http_response_code(400); echo json_encode(['error'=>'Invalid source']); exit; }

try {
  if ($id > 0) {
    $sql = "UPDATE company_calendar_events SET category=?, title=?, remark=?, source=?, updated_at=NOW() WHERE id=?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cat, $title, $remark, $source, $id]);
    echo json_encode(['ok'=>true, 'id'=>$id], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($cat === 'GOV_HOLIDAY') {
    $stmt = $pdo->prepare("SELECT id FROM company_calendar_events WHERE `date`=? AND category='GOV_HOLIDAY' AND title=? LIMIT 1");
    $stmt->execute([$date, $title]);
    $existId = (int)($stmt->fetchColumn() ?: 0);
    if ($existId > 0) {
      $stmt = $pdo->prepare("UPDATE company_calendar_events SET remark=?, source=?, updated_at=NOW() WHERE id=?");
      $stmt->execute([$remark, $source, $existId]);
      echo json_encode(['ok'=>true, 'id'=>$existId, 'replaced'=>true], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  $sql = "INSERT INTO company_calendar_events (`date`, category, title, remark, source, updated_at)
          VALUES (?, ?, ?, ?, ?, NOW())";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$date, $cat, $title, $remark, $source]);
  echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
