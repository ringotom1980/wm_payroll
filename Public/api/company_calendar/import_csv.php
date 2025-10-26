<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

function normalize_csv_date(string $raw): ?string {
  $s = trim($raw);
  if (preg_match('/^\d{8}$/', $s)) {
    return sprintf('%s-%s-%s', substr($s,0,4), substr($s,4,2), substr($s,6,2));
  }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  return null;
}

$errors = [];
$yearForResp = null; 
$monthForResp = null;

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['error'=>'No file uploaded or upload error']); exit;
}
$tmp = $_FILES['file']['tmp_name'];
if (!is_readable($tmp)) { http_response_code(400); echo json_encode(['error'=>'File not readable']); exit; }

try {
  $pdo->beginTransaction();

  // 🔹 先清掉舊的政府資料（整批覆蓋）
  $pdo->exec("DELETE FROM company_calendar WHERE source='CSV_GOV'");
  $pdo->exec("DELETE FROM company_calendar_events WHERE source='CSV_GOV'");

  $fh = fopen($tmp, 'r');
  if ($fh === false) throw new RuntimeException('Failed to open file');

  // 檢查表頭
  $lineNo = 0;
  $peek = fgetcsv($fh);
  $lineNo++;
  $maybeDate = normalize_csv_date((string)($peek[0] ?? ''));
  if ($maybeDate) { rewind($fh); $lineNo = 0; }

  $stmtCal = $pdo->prepare("
    INSERT INTO company_calendar (`date`, is_holiday, is_makeup_workday, note, source, updated_at)
    VALUES (?, ?, ?, ?, 'CSV_GOV', NOW())
  ");

  $stmtEv = $pdo->prepare("
    INSERT INTO company_calendar_events (`date`, category, title, source, updated_at)
    VALUES (?, 'GOV_HOLIDAY', ?, 'CSV_GOV', NOW())
  ");

  while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;
    $date = normalize_csv_date((string)($row[0] ?? ''));
    if (!$date) { $errors[] = "CSV 第 {$lineNo} 行：日期格式錯誤（支援 YYYYMMDD / YYYY-MM-DD）"; continue; }

    $flag = trim((string)($row[2] ?? ''));
    $is_holiday = ($flag === '2') ? 1 : 0;
    $is_makeup  = ($flag === '1') ? 1 : 0;
    $note = isset($row[3]) ? trim((string)$row[3]) : null;

    $yearForResp  = $yearForResp  ?? (int)substr($date, 0, 4);
    $monthForResp = $monthForResp ?? (int)substr($date, 5, 2);

    $stmtCal->execute([$date, $is_holiday, $is_makeup, $note]);
    if ($note) $stmtEv->execute([$date, $note]);
  }

  fclose($fh);
  $pdo->commit();

  $resp = ['ok'=>true, 'year'=>$yearForResp, 'month'=>$monthForResp];
  if ($errors) $resp['errors'] = $errors;
  echo json_encode($resp, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage(),'errors'=>$errors], JSON_UNESCAPED_UNICODE);
}
