<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

function normHoliday($v): ?int {
  // 支援：0/2、Y/N、1/0（大小寫均可）
  $s = strtoupper(trim((string)$v));
  if ($s === 'Y' || $s === '1' || $s === '2') return 1; // 2=放假，1=放假（保留）
  if ($s === 'N' || $s === '0') return 0;               // 0=工作日
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

  // 判斷表頭
  $lineNo = 0;
  $peek = fgetcsv($fh);
  if ($peek === false) throw new RuntimeException('Empty CSV');
  $lineNo++;
  $firstCol = isset($peek[0]) ? trim((string)$peek[0]) : '';
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstCol)) {
    // 第一列就是資料，回捲
    rewind($fh);
    $lineNo = 0;
  }

  while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;
    // 期待欄位：0=西元日期, 1=星期(忽略), 2=是否放假(0/2 or Y/N or 1/0), 3=備註(文字)
    $date = trim((string)($row[0] ?? ''));
    $isHolRaw = $row[2] ?? '';
    $note = isset($row[3]) ? trim((string)$row[3]) : null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      $errors[] = "CSV 第 {$lineNo} 行：日期格式錯誤（需 YYYY-MM-DD）";
      continue;
    }
    $norm = normHoliday($isHolRaw);
    if ($norm === null) {
      $errors[] = "CSV 第 {$lineNo} 行：「是否放假」僅接受 Y/N、0/2 或 1/0";
      continue;
    }

    $yearForResp  = $yearForResp  ?? (int)substr($date, 0, 4);
    $monthForResp = $monthForResp ?? (int)substr($date, 5, 2);

    // upsert company_calendar（補班=0）
    $stmt = $pdo->prepare("INSERT INTO company_calendar (`date`, is_holiday, is_makeup_workday, note, source, updated_at)
                           VALUES (?, ?, 0, ?, 'CSV_GOV', NOW())
                           ON DUPLICATE KEY UPDATE
                             is_holiday=VALUES(is_holiday),
                             note=VALUES(note),
                             source='CSV_GOV',
                             updated_at=NOW()");
    $stmt->execute([$date, $norm, $note]);

    // 方案 B：有備註 → 新增/覆寫 GOV_HOLIDAY（同日同標題去重）
    if ($note !== null && $note !== '') {
      $sel = $pdo->prepare("SELECT id FROM company_calendar_events WHERE `date`=? AND category='GOV_HOLIDAY' AND title=? LIMIT 1");
      $sel->execute([$date, $note]);
      $eid = (int)($sel->fetchColumn() ?: 0);
      if ($eid > 0) {
        $upd = $pdo->prepare("UPDATE company_calendar_events SET remark=NULL, source='CSV_GOV', updated_at=NOW() WHERE id=?");
        $upd->execute([$eid]);
      } else {
        $ins = $pdo->prepare("INSERT INTO company_calendar_events (`date`, category, title, remark, source, updated_at)
                              VALUES (?, 'GOV_HOLIDAY', ?, NULL, 'CSV_GOV', NOW())");
        $ins->execute([$date, $note]);
      }
    }
  }
  fclose($fh);

  $pdo->commit();

  $resp = ['ok'=>true, 'year'=>$yearForResp, 'month'=>$monthForResp];
  if (!empty($errors)) $resp['errors'] = $errors;
  echo json_encode($resp, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(500);
  echo json_encode(['error'=>'Server error','detail'=>$e->getMessage(), 'errors'=>$errors], JSON_UNESCAPED_UNICODE);
}
