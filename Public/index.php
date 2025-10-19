<?php
// Public/index.php
$app = require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/auth.php';

// 已登入 → 儀表板；未登入 → 登入頁
if (is_logged_in()) {
    header('Location: /dashboard');
} else {
    header('Location: /auth/login');
}
exit;
