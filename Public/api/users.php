<?php
// Public/api/users.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';     /** @var PDO $pdo */
require_once __DIR__ . '/../../config/auth.php';

// 允許的 actions：login / logout / me / change_password
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function json_ok($data = []) {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg = 'ERROR', $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($action === 'login') {
        // 基本輸入
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            json_err('帳號或密碼不可為空', 422);
        }

        // 取用戶
        $stmt = $pdo->prepare('SELECT user_id, username, password_hash, role, display_name, last_login_at, is_active
                               FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['is_active'] !== 1) {
            // 統一錯誤訊息避免撞帳號
            json_err('帳號或密碼錯誤', 401);
        }

        // 驗證密碼
        if (!password_verify($password, $row['password_hash'])) {
            json_err('帳號或密碼錯誤', 401);
        }

        // 登入：寫 session
        login_user($row);

        // 更新最後登入時間（不影響登入體驗）
        try {
            $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE user_id=:id')
                ->execute([':id' => $row['user_id']]);
        } catch (\Throwable $e) {
            // 忽略非關鍵錯誤
        }

        json_ok([
            'user_id' => (int)$row['user_id'],
            'username' => $row['username'],
            'role' => $row['role'],
            'display_name' => $row['display_name'] ?: $row['username'],
        ]);
    }
    elseif ($action === 'logout') {
        if (is_logged_in()) logout_user();
        json_ok();
    }
    elseif ($action === 'me') {
        if (!is_logged_in()) json_err('未登入', 401);
        json_ok(auth_user());
    }
    elseif ($action === 'change_password') {
        require_login();
        $uid = (int)auth_user()['user_id'];

        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        if (strlen($new) < 8) {
            json_err('新密碼長度至少 8 碼', 422);
        }

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id=:id LIMIT 1');
        $stmt->execute([':id' => $uid]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($old, $row['password_hash'])) {
            json_err('舊密碼不正確', 403);
        }

        $newHash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash=:ph, updated_at=NOW() WHERE user_id=:id')
            ->execute([':ph' => $newHash, ':id' => $uid]);

        json_ok(['message' => '密碼已更新']);
    }
    else {
        json_err('未知的 action', 404);
    }
} catch (Throwable $e) {
    // 生產環境只回通用錯誤
    json_err('伺服器處理失敗', 500);
}
