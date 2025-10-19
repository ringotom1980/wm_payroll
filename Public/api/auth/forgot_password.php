<?php
// Public/api/auth/forgot_password.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$app = require __DIR__ . '/../../../config/app.php';
require __DIR__ . '/../../../config/auth.php';
/** @var PDO $pdo */
$pdo = require __DIR__ . '/../../../config/db.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;

    $username = trim((string)($data['username'] ?? ''));
    if ($username === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'EMPTY_USERNAME'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT user_id, username, is_active FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 安全考量：不洩漏是否存在
    if (!$row || (isset($row['is_active']) && (int)$row['is_active'] !== 1)) {
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $token   = bin2hex(random_bytes(32));
    $expires = 30; // 分鐘

    $upd = $pdo->prepare(
        "UPDATE users
            SET reset_token = :t,
                reset_expires_at = DATE_ADD(NOW(), INTERVAL :m MINUTE)
          WHERE user_id = :id"
    );
    $upd->bindValue(':t',  $token, PDO::PARAM_STR);
    $upd->bindValue(':m',  $expires, PDO::PARAM_INT);
    $upd->bindValue(':id', (int)$row['user_id'], PDO::PARAM_INT);
    $upd->execute();

    $base = rtrim((string)($app['URLS']['APP_URL'] ?? ''), '/');
    $resetUrl = ($base ?: '') . '/auth/reset?token=' . urlencode($token);

    echo json_encode(['ok' => true, 'data' => ['reset_url' => $resetUrl]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR'], JSON_UNESCAPED_UNICODE);
}
