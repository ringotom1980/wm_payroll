<?php
// config/auth.php
// 說明：統一 Session 啟用與權限檢查、登入/登出工具

$app = require __DIR__ . '/app.php';

// 設定時區
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set($app['TIMEZONE'] ?? 'Asia/Taipei');
}

// 安全的 Session 參數
$ss = $app['SESSION'] ?? [];
$cookieParams = [
    'lifetime' => (int)($ss['LIFETIME'] ?? 1440),
    'path'     => '/',
    'domain'   => '', // 預設即可；若有子網域策略再調整
    'secure'   => (bool)($ss['SECURE'] ?? false),
    'httponly' => (bool)($ss['HTTPONLY'] ?? true),
    'samesite' => $ss['SAMESITE'] ?? 'Lax', // PHP >= 7.3
];

// 設定 Session 名稱與 Cookie 參數
session_name($ss['NAME'] ?? 'wm_payroll_sid');
if (PHP_VERSION_ID >= 70300) {
    // 支援 samesite
    session_set_cookie_params($cookieParams);
} else {
    // 舊版 fallback（不支援 samesite 明確設定）
    session_set_cookie_params(
        $cookieParams['lifetime'],
        $cookieParams['path'],
        $cookieParams['domain'],
        $cookieParams['secure'],
        $cookieParams['httponly']
    );
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** 取得目前登入的使用者（或 null） */
function auth_user(): ?array {
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

/** 是否已登入 */
function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

/** 強制需要登入（未登入回 401 JSON） */
function require_login(): void {
    if (!is_logged_in()) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** 需要指定角色（ADMIN 擁有超集權限） */
function require_role(string $role): void {
    require_login();
    $u = auth_user();
    if (!$u || !isset($u['role']) || ($u['role'] !== $role && $u['role'] !== 'ADMIN')) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** 登入成功：建立 session，並重新產生 session id */
function login_user(array $row): void {
    // 防 session fixation
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user'] = [
        'user_id'      => (int)($row['user_id'] ?? 0),
        'username'     => (string)($row['username'] ?? ''),
        'role'         => (string)($row['role'] ?? 'STAFF'),
        'display_name' => (string)($row['display_name'] ?? ($row['username'] ?? '')),
        'last_login_at'=> $row['last_login_at'] ?? null,
    ];
}

/** 登出並清理 Cookie */
function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}
