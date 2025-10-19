<?php
// Public/api/auth/change_password.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../../config/auth.php';
/** @var PDO $pdo */
$pdo = require __DIR__ . '/../../../config/db.php';

try {
    require_login();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;

    $curr = (string)($data['current_password'] ?? '');
    $next = (string)($data['new_password'] ?? '');
    if ($curr === '' || $next === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'EMPTY_PASSWORD'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($next) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'WEAK_PASSWORD_MIN8'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $u = auth_user();
    $stmt = $pdo->prepare("SELECT user_id, password_hash FROM users WHERE user_id = :id LIMIT 1");
    $stmt->execute([':id' => (int)$u['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($curr, (string)$row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'INVALID_CURRENT_PASSWORD'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newHash = password_hash($next, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE users SET password_hash = :ph WHERE user_id = :id");
    $upd->execute([':ph' => $newHash, ':id' => (int)$u['user_id']]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR'], JSON_UNESCAPED_UNICODE);
}
