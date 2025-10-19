<?php
// Public/api/auth/reset_password.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

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

    $token = trim((string)($data['token'] ?? ''));
    $pass  = (string)($data['new_password'] ?? '');
    if ($token === '' || $pass === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'EMPTY_FIELDS'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($pass) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'WEAK_PASSWORD_MIN8'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT user_id FROM users
          WHERE reset_token = :t
            AND reset_expires_at > NOW()
          LIMIT 1"
    );
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'INVALID_OR_EXPIRED_TOKEN'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $upd = $pdo->prepare(
        "UPDATE users
            SET password_hash = :ph,
                reset_token = NULL,
                reset_expires_at = NULL
          WHERE user_id = :id"
    );
    $upd->execute([':ph' => $hash, ':id' => (int)$row['user_id']]);

    // 自動登入（可選）
    $stmt2 = $pdo->prepare("SELECT user_id, username, role, display_name, last_login_at, is_active FROM users WHERE user_id = :id LIMIT 1");
    $stmt2->execute([':id' => (int)$row['user_id']]);
    $urow = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($urow && (int)($urow['is_active'] ?? 1) === 1) login_user($urow);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR'], JSON_UNESCAPED_UNICODE);
}
