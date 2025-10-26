<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

/**
 * 將各種日期字串轉成 YYYY-MM-DD
 * 支援：20250101 / 2025-01-01 / 2025/01/01 / 1140101(民國)
 */
function normalize_csv_date(string $raw): ?string {
  $s = trim($raw);

  // 民國年：1140101 -> 2025-01-01
  if (preg_match('/^(\d{3})(\d{2})(\d{2})$/', $s, $m)) {
    $year = (int)$m[1] + 1911;
    return sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[3]);
  }

  // 純西元 8 碼：20250101
  if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
  }

  // 西元帶分隔：2025-01-01 或 2025/01/01
  if (preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
  }

  return null;
}

$errors = [];
$yearsTouched = [];   // 如 [2025 => true, 2026 => true]
$rowsByYear  = [];    // 各年要寫入的資料列

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['error'=>'No file uploaded or upload error']); exit;
}
$tmp = $_FILES['file']['tmp_name'];
if (!is_readable($tmp)) { http_response_code(400); echo json_encode(['error'=>'File not readable']); exit; }

try {
  // 先讀整份 CSV，分桶到各年份
  $fh = fopen($tmp, 'r');
  if ($fh === false) throw new RuntimeException('Failed to open file');

  $lineNo = 0;
  $peek = fgetcsv($fh);
  $lineNo++;
  // 若首列就是資料而非表頭，倒帶
  if ($peek && normalize_csv_date((string)($peek[0] ?? ''))) { rewind($fh); $lineNo = 0; }

  while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;

    // 欄位意義：0=日期、1=星期(忽略)、2=旗標(2休/1補班/0上班)、3=備註(節日名)
    $date = normalize_csv_date((string)($row[0] ?? ''));
    if (!$date) { $errors[] = "CSV 第 {$lineNo} 行：日期格式錯誤"; continue; }

    $flag = trim((string)($row[2] ?? ''));
    $is_holiday = ($flag === '2') ? 1 : 0;
    $is_makeup  = ($flag === '1') ? 1 : 0;
    $note = isset($row[3]) ? trim((string)$row[3]) : null;

    $year = (int)substr($date, 0, 4);
    $yearsTouched[$year] = true;
    $rowsByYear[$year][] = [
      'date'        => $date,
      'is_holiday'  => $is_holiday,
      'is_makeup'   => $is_makeup,
      'gov_note'    => $note,
    ];
  }
  fclose($fh);

  if (empty($rowsByYear)) {
    echo json_encode(['ok'=>false, 'errors'=>array_merge(['CSV 內容為空或皆為錯誤'], $errors)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 依「每個年份」做整批覆蓋（該年 CSV_GOV 先清空 → 再重建）
  $pdo->beginTransaction();

  $stmtDelCal = $pdo->prepare("DELETE FROM company_calendar
                               WHERE source='CSV_GOV' AND `date` BETWEEN ? AND ?");
  $stmtDelEvt = $pdo->prepare("DELETE FROM company_calendar_events
                               WHERE source='CSV_GOV' AND `date` BETWEEN ? AND ? AND category='GOV_HOLIDAY'");

  $stmtInsCal = $pdo->prepare("
    INSERT INTO company_calendar (`date`, is_holiday, is_makeup_workday, note, source, updated_at)
    VALUES (?, ?, ?, ?, 'CSV_GOV', NOW())
  ");

  $stmtInsEvt = $pdo->prepare("
    INSERT INTO company_calendar_events (`date`, category, title, source, updated_at)
    VALUES (?, 'GOV_HOLIDAY', ?, 'CSV_GOV', NOW())
  ");

  ksort($rowsByYear); // 只是讓處理順序更穩定，非必要

  foreach ($rowsByYear as $year => $rows) {
    $from = sprintf('%04d-01-01', $year);
    $to   = sprintf('%04d-12-31', $year);

    // ① 清掉該年所有 CSV_GOV
    $stmtDelCal->execute([$from, $to]);
    $stmtDelEvt->execute([$from, $to]);

    // ② 重建該年的資料
    foreach ($rows as $r) {
      $stmtInsCal->execute([$r['date'], $r['is_holiday'], $r['is_makeup'], $r['gov_note']]);
      if (!empty($r['gov_note'])) {
        $stmtInsEvt->execute([$r['date'], $r['gov_note']]);
      }
    }
  }

  $pdo->commit();

  // 回傳本次觸及的年份（例如 [2025, 2026]）
  $years = array_keys($yearsTouched);
  sort($years);
  $resp = ['ok'=>true, 'years'=>$years];
  if ($errors) $resp['errors'] = $errors;
  echo json_encode($resp, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage(),'errors'=>$errors], JSON_UNESCAPED_UNICODE);
}
