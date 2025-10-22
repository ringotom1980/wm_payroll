<?php
// config/auth.php
// 統一 Session 啟用與權限檢查、登入/登出工具（for PHP 8.2）
declare(strict_types=1);

$app = require __DIR__ . '/app.php';

// ---- 時區 ----
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set($app['TIMEZONE'] ?? 'Asia/Taipei');
}

// ---- 自動判斷是否 HTTPS（含常見代理/Cloudflare 標頭）----
$via_https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
    (!empty($_SERVER['HTTP_CF_VISITOR']) && stripos((string)$_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false)
);

// ---- 讀取 SESSION 設定並套用安全預設 ----
$ss = $app['SESSION'] ?? [];

// 建議：預設 session 為「關閉瀏覽器即失效」；如需長時效，於 app.php 設定 SESSION.LIFETIME（秒）
$lifetime = isset($ss['LIFETIME']) ? (int)$ss['LIFETIME'] : 0;

$cookieParams = [
    'lifetime' => $lifetime,                               // 0 = 關閉瀏覽器失效
    'path'     => '/',                                     // 全站有效
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',             // 綁定目前 Host（避免子網域/代理下吃不到）
    'secure'   => $via_https ? true : (bool)($ss['SECURE'] ?? false), // HTTPS 環境自動開啟 Secure
    'httponly' => (bool)($ss['HTTPONLY'] ?? true),
    'samesite' => $ss['SAMESITE'] ?? 'Lax',                // 同網域建議 Lax；跨網域需配合 None+Secure
];

// ---- Session 名稱（避免與其他站衝突）----
session_name($ss['NAME'] ?? 'wm_payroll_sid');

// ---- 啟動 Session（務必在任何輸出前）----
if (session_status() === PHP_SESSION_NONE) {
    // PHP 7.3+ 陣列語法（你是 8.2，OK）
    session_set_cookie_params($cookieParams);

    // 額外安全建議
    ini_set('session.use_strict_mode', '1');   // 拒絕無效/已用過的 id
    ini_set('session.cookie_httponly', '1');   // 防止 JS 取用
    if ($cookieParams['secure']) {
        ini_set('session.cookie_secure', '1'); // 僅在 HTTPS 下傳送
    }

    session_start();
}

// ====================== 工具函式 ======================

/** 取得目前登入的使用者（或 null） */
function auth_user(): ?array {
    return (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
}

/** 是否已登入 */
function is_logged_in(): bool {
    $u = auth_user();
    return !empty($u) && !empty($u['user_id']);
}

/** 強制需要登入（未登入回 401 JSON） */
function require_login(): void {
    if (!is_logged_in()) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store'); // 避免被快取
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
            header('Cache-Control: no-store');
        }
        echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** 登入成功：建立 session，並重新產生 session id（防 session fixation） */
function login_user(array $row): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user'] = [
        'user_id'       => (int)($row['user_id'] ?? 0),
        'username'      => (string)($row['username'] ?? ''),
        'role'          => (string)($row['role'] ?? 'STAFF'),
        'display_name'  => (string)($row['display_name'] ?? ($row['username'] ?? '')),
        'last_login_at' => $row['last_login_at'] ?? null,
    ];
}

/** 登出並清理 Cookie */
function logout_user(): void {
    // 清除伺服端資料
    $_SESSION = [];

    // 清除 Cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?? '/',
                'domain'   => $params['domain'] ?? ($_SERVER['HTTP_HOST'] ?? ''),
                'secure'   => !empty($params['secure']),
                'httponly' => !empty($params['httponly']),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    // 結束 session
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
