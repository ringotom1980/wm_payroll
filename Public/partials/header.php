<?php
// Public/partials/header.php
$app = $app ?? (require __DIR__ . '/../../config/app.php');
require_once __DIR__ . '/../../config/auth.php';

$APP_NAME = htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理', ENT_QUOTES);
$logged_in = is_logged_in();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $APP_NAME ?></title>

  <!-- 全站共用樣式（內含 Iconoir 匯入） -->
  <!-- <link href="/assets/css/app.css?v=11" rel="stylesheet"> -->

  <!-- Favicon / PWA 基本 -->
  <link rel="icon" type="image/png" href="/assets/img/WM-logo.png" sizes="32x32">
  <link rel="shortcut icon" href="/assets/img/WM-logo.png">
  <link rel="shortcut icon" href="/assets/img/WM-logo.png" type="image/png">
  <link rel="apple-touch-icon" href="/assets/img/WM-logo.png">
</head>
<body>

<header class="main-header">
  <div class="header-left">
    <a href="/dashboard" class="brand">
      <img src="/assets/img/WM-logo.png" alt="<?= $APP_NAME ?>" class="site-logo">
      <span class="site-title"><?= $APP_NAME ?></span>
    </a>
  </div>
  <nav class="top-nav">
    <?php if ($logged_in): ?>
      <a href="#" id="topLogout" class="nav-link logout-link"><i class="iconoir-log-out"></i> 登出</a>
    <?php else: ?>
      <a href="/auth/login.php" class="nav-link"><i class="iconoir-log-in"></i> 登入</a>
    <?php endif; ?>
  </nav>
</header>
