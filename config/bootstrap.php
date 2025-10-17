<?php
// config/bootstrap.php
// 功能：在沒有 phpdotenv / composer 的情況下，自動讀取 .env

$root = dirname(__DIR__);          // wm_payroll 根目錄
$envFile = $root . '/.env';

if (!is_file($envFile)) {
    return; // 沒有 .env 就略過（例如開發初期）
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;

    // 切開 key=value
    [$key, $val] = array_pad(explode('=', $line, 2), 2, '');
    $key = trim($key);
    $val = trim($val, " \t\n\r\0\x0B\"'");

    if ($key === '') continue;

    // 設定環境變數
    putenv("$key=$val");
    $_ENV[$key] = $val;
    $_SERVER[$key] = $val;
}
