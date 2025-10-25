<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$date   = $input['date'] ?? null;
$hol    = isset($input['is_holiday']) ? (int)$input['is_holiday'] : 0;
$makeup = isset($input['is_makeup_workday']) ? (int)$input['is_makeup_workday'] : 0;
$note   = isset($input['note']) ? trim((string)$input['note']) : null;
$source = $input['source'] ?? 'MANUAL';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) { http_response_code(400); echo json_encode(['error'=>'Invalid date']); exit; }
if (!in_array($source, ['CSV_GOV','MANUAL'], true)) { http_response_code(400); echo json_encode(['error'=>'Invalid source']); exit; }

try {
  $sql = "INSERT INTO company_calendar (`date`, is_holiday, is_makeup_workday, note, source, updated_at)
          VALUES (?, ?, ?, ?, ?, NOW())
          ON DUPLICATE KEY UPDATE
            is_holiday=VALUES(is_holiday),
            is_makeup_workday=VALUES(is_makeup_workday),
            note=VALUES(note),
            source=VALUES(source),
            updated_at=NOW()";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$date, $hol, $makeup, $note, $source]);

  echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
