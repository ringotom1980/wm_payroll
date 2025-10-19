<?php
// config/bootstrap.php
// 功能：在沒有 phpdotenv / composer 的情況下，自動讀取 .env

$root   = dirname(__DIR__);          // wm_payroll 根目錄
$envFile = $root . '/.env';

if (!is_file($envFile)) {
    return; // 沒有 .env 就略過（例如開發初期）
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;
    if ($line[0] === '#') continue; // 註解行

    // 切開 key=value（只切一次，右邊允許包含 '='）
    $pos = strpos($line, '=');
    if ($pos === false) continue;

    $key = trim(substr($line, 0, $pos));
    $val = trim(substr($line, $pos + 1));

    // 去除包住的單/雙引號
    $len = strlen($val);
    if ($len >= 2) {
        $first = $val[0];
        $last  = $val[$len - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $val = substr($val, 1, -1);
        }
    }

    if ($key === '') continue;

    // 設定環境變數
    putenv("$key=$val");
    $_ENV[$key]    = $val;
    $_SERVER[$key] = $val;
}
