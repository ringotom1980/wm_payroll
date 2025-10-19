<?php
// config/db.php
// 說明：安全的 PDO 連線（.env 驅動、utf8mb4、原生 prepared、可選 SSL）

require_once __DIR__ . '/bootstrap.php';

$app = require __DIR__ . '/app.php';
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set($app['TIMEZONE'] ?? 'Asia/Taipei');
}

$ENV = $app['ENV'] ?? 'production';
$DB  = $app['DB']  ?? [];

// 基本參數
$host     = $DB['HOST']      ?? '127.0.0.1';
$port     = (int)($DB['PORT'] ?? 3306);
$dbname   = $DB['NAME']      ?? 'u327657097_wm_payroll';
$user     = $DB['USER']      ?? 'u327657097_wm_payroll_user';
$pass     = (string)($DB['PASS'] ?? '');
$charset  = $DB['CHARSET']   ?? 'utf8mb4';
$collate  = $DB['COLLATION'] ?? 'utf8mb4_general_ci';

// SSL（選用）
$ssl_ca   = $DB['SSL_CA']   ?? '';
$ssl_cert = $DB['SSL_CERT'] ?? '';
$ssl_key  = $DB['SSL_KEY']  ?? '';

// 生產環境保護：避免空密碼
if ($ENV === 'production' && trim($pass) === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'DB_CREDENTIALS_INVALID']);
    exit;
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,           // 交由上層統一處理
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,                            // 原生 prepared statements
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$collate}",
    PDO::ATTR_PERSISTENT         => false,
];

// 啟用 SSL（若提供）
if (!empty($ssl_ca)) {
    $options[PDO::MYSQL_ATTR_SSL_CA]   = $ssl_ca;
}
if (!empty($ssl_cert)) {
    $options[PDO::MYSQL_ATTR_SSL_CERT] = $ssl_cert;
}
if (!empty($ssl_key)) {
    $options[PDO::MYSQL_ATTR_SSL_KEY]  = $ssl_key;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // 連線後的安全/一致性設定
    $pdo->exec("SET time_zone = '+08:00';");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';");


    // （可選）SQL 除錯開關
    if (!empty($app['DEBUG_SQL'])) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Throwable $e) {
    if ($ENV === 'production') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'DB_CONNECTION_FAILED']);
        exit;
    }
    // 開發環境：直接拋出詳細錯誤
    throw $e;
}

return $pdo;
