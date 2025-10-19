<?php
// config/app.php
// 說明：集中全站設定（從 .env 讀取，並提供預設值）

require_once __DIR__ . '/bootstrap.php';

$env = function(string $k, $d = null) {
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $d;
};

return [
    'APP_NAME'  => $env('APP_NAME', '旺苗人員薪資管理'),
    'ENV'       => $env('APP_ENV', 'production'),       // development / production
    'TIMEZONE'  => $env('TIMEZONE', 'Asia/Taipei'),
    'LOG_LEVEL' => $env('LOG_LEVEL', 'INFO'),

    // 資料庫預設（db.php 會再讀 .env）
    'DB' => [
        'HOST'      => $env('DB_HOST', '127.0.0.1'),
        'PORT'      => (int)$env('DB_PORT', '3306'),
        'NAME'      => $env('DB_NAME', 'u327657097_wm_payroll'),
        'USER'      => $env('DB_USER', 'u327657097_wm_admin'),
        'PASS'      => (string)$env('DB_PASS', ''),
        'CHARSET'   => $env('DB_CHARSET', 'utf8mb4'),
        'COLLATION' => $env('DB_COLLATION', 'utf8mb4_general_ci'),
        'SSL_CA'    => (string)$env('DB_SSL_CA', ''),
        'SSL_CERT'  => (string)$env('DB_SSL_CERT', ''),
        'SSL_KEY'   => (string)$env('DB_SSL_KEY', ''),
    ],

    // Session/Cookie
    'SESSION' => [
        'NAME'        => (string)$env('SESSION_NAME', 'wm_payroll_sid'),
        'LIFETIME'    => (int)$env('SESSION_LIFETIME', '1440'), // 秒
        'SAMESITE'    => (string)$env('COOKIE_SAMESITE', 'Lax'), // Lax / Strict / None
        'SECURE'      => filter_var($env('COOKIE_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN),
        'HTTPONLY'    => true,
    ],

    // 除錯
    'DEBUG_SQL' => filter_var($env('DEBUG_SQL', 'false'), FILTER_VALIDATE_BOOLEAN),
];
