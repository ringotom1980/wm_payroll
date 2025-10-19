<?php
// Public/api/auth/login.php
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
    $password = (string)($data['password'] ?? '');

    if ($username === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'EMPTY_CREDENTIALS'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT user_id, username, password_hash, role, display_name, last_login_at, is_active
           FROM users
          WHERE username = :u
          LIMIT 1"
    );
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !isset($row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'INVALID_LOGIN'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (isset($row['is_active']) && (int)$row['is_active'] !== 1) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'USER_INACTIVE'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!password_verify($password, (string)$row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'INVALID_LOGIN'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    login_user($row);

    try {
        $upd = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = :id");
        $upd->execute([':id' => (int)$row['user_id']]);
    } catch (Throwable $e) { /* ignore */ }

    echo json_encode(['ok' => true, 'data' => [
        'user_id' => (int)$row['user_id'],
        'username' => (string)$row['username'],
        'role' => (string)$row['role'],
        'display_name' => (string)($row['display_name'] ?? $row['username']),
    ]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (($app['ENV'] ?? 'production') !== 'production') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR'], JSON_UNESCAPED_UNICODE);
    }
}
