<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

/** 支援 20250101 或 2025-01-01 */
function normalize_csv_date(string $raw): ?string {
  $s = trim($raw);
  if (preg_match('/^\d{8}$/', $s)) {
    return sprintf('%s-%s-%s', substr($s,0,4), substr($s,4,2), substr($s,6,2));
  }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  return null;
}

$errors = [];
$yearForResp = null; $monthForResp = null;

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['error'=>'No file uploaded or upload error']); exit;
}
$tmp = $_FILES['file']['tmp_name'];
if (!is_readable($tmp)) { http_response_code(400); echo json_encode(['error'=>'File not readable']); exit; }

try {
  $pdo->beginTransaction();
  $fh = fopen($tmp, 'r');
  if ($fh === false) throw new RuntimeException('Failed to open file');

  // 嘗試吃掉表頭（但不強制）
  $lineNo = 0;
  $peek = fgetcsv($fh); $lineNo++;
  if ($peek === false) throw new RuntimeException('Empty CSV');
  $maybeDate = normalize_csv_date((string)($peek[0] ?? ''));
  if ($maybeDate) { rewind($fh); $lineNo = 0; }

  while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;

    // 0=日期(YYYYMMDD/YYYY-MM-DD), 1=星期(忽略), 2=是否放假(2=休,1=補班,0=上班), 3=備註(節日名)
    $date = normalize_csv_date((string)($row[0] ?? ''));
    if (!$date) { $errors[] = "CSV 第 {$lineNo} 行：日期格式錯誤（支援 YYYYMMDD / YYYY-MM-DD）"; continue; }

    $flag = trim((string)($row[2] ?? ''));
    $is_holiday = ($flag === '2') ? 1 : 0;
    $is_makeup  = ($flag === '1') ? 1 : 0;

    $govNote = isset($row[3]) ? trim((string)$row[3]) : null;

    $yearForResp  = $yearForResp  ?? (int)substr($date, 0, 4);
    $monthForResp = $monthForResp ?? (int)substr($date, 5, 2);

    // upsert：只動政府欄位，不碰使用者記事（在另一張表）
    $stmt = $pdo->prepare("
      INSERT INTO company_calendar (`date`, is_holiday, is_makeup_workday, note, source, updated_at)
      VALUES (?, ?, ?, ?, 'CSV_GOV', NOW())
      ON DUPLICATE KEY UPDATE
        is_holiday         = VALUES(is_holiday),
        is_makeup_workday  = VALUES(is_makeup_workday),
        note               = VALUES(note),
        source             = 'CSV_GOV',
        updated_at         = NOW()
    ");
    $stmt->execute([$date, $is_holiday, $is_makeup, $govNote]);

    // （可選）同步一筆 GOV_HOLIDAY 事件，供別處使用；同日同標題去重
    if ($govNote) {
      $sel = $pdo->prepare("SELECT id FROM company_calendar_events WHERE `date`=? AND category='GOV_HOLIDAY' AND title=? LIMIT 1");
      $sel->execute([$date, $govNote]);
      $eid = (int)($sel->fetchColumn() ?: 0);
      if ($eid) {
        $upd = $pdo->prepare("UPDATE company_calendar_events SET source='CSV_GOV', updated_at=NOW() WHERE id=?");
        $upd->execute([$eid]);
      } else {
        $ins = $pdo->prepare("INSERT INTO company_calendar_events (`date`, category, title, source, updated_at)
                              VALUES (?, 'GOV_HOLIDAY', ?, 'CSV_GOV', NOW())");
        $ins->execute([$date, $govNote]);
      }
    }
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
