<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$pdo = require __DIR__ . '/../../../config/db.php';

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $date = trim((string)($data['date'] ?? ''));
    $mode = trim((string)($data['mode'] ?? ''));
    $note = isset($data['note']) ? trim((string)$data['note']) : null;

    // 基本驗證：日期
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => '日期格式錯誤，需 YYYY-MM-DD'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 檢查合法日期
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        http_response_code(400);
        echo json_encode(['error' => '無效日期'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // mode：FOLLOW / FORCE_HOLIDAY / FORCE_WORKDAY
    $validModes = ['FOLLOW', 'FORCE_HOLIDAY', 'FORCE_WORKDAY'];
    if (!in_array($mode, $validModes, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'mode 參數錯誤'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($note === '') {
        $note = null;
    }
    if ($note !== null && mb_strlen($note, 'UTF-8') > 255) {
        $note = mb_substr($note, 0, 255, 'UTF-8');
    }

    if ($mode === 'FOLLOW') {
        // 代表「照政府」：刪除覆寫紀錄
        $stmt = $pdo->prepare("DELETE FROM company_calendar_overrides WHERE `date` = ?");
        $stmt->execute([$date]);
    } else {
        // 強制休假 / 強制上班：UPSERT
        $stmt = $pdo->prepare("
            INSERT INTO company_calendar_overrides (`date`, override_type, note, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
              override_type = VALUES(override_type),
              note = VALUES(note),
              updated_at = VALUES(updated_at)
        ");
        $stmt->execute([$date, $mode, $note]);
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'Server error',
        'detail' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
