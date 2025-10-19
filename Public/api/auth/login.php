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

    // 解析 JSON 或 x-www-form-urlencoded
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($username === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'EMPTY_CREDENTIALS'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 查使用者
    $stmt = $pdo->prepare(
        "SELECT user_id, username, password_hash, role, display_name, last_login_at, status
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
    if (isset($row['status']) && strtoupper((string)$row['status']) !== 'ACTIVE') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'USER_INACTIVE'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 驗證密碼（資料表以 password_hash() 產生的雜湊）
    if (!password_verify($password, (string)$row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'INVALID_LOGIN'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 建立 Session
    login_user($row);

    // 更新最後登入時間（可忽略錯誤）
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
    // 開發環境可以輸出詳情
    if (($app['ENV'] ?? 'production') !== 'production') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR'], JSON_UNESCAPED_UNICODE);
    }
}
