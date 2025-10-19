<?php
// Public/partials/header.php
$app = $app ?? (require __DIR__ . '/../../config/app.php');
$APP_NAME = htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理', ENT_QUOTES);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $APP_NAME ?></title>

  <!-- 全站共用樣式（內含 Iconoir 匯入） -->
  <link rel="stylesheet" href="/assets/css/app.css?v=2">

  <!-- Favicon / PWA 基本 -->
  <link rel="icon" type="image/png" href="/assets/img/WM-logo.png" sizes="32x32">
  <link rel="shortcut icon" href="/assets/img/WM-logo.png">
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
    <a href="/dashboard" class="nav-link"><i class="iconoir-home-simple"></i> 儀表板</a>
    <a href="/auth/change.php" class="nav-link"><i class="iconoir-lock"></i> 變更密碼</a>
    <a href="/api/auth/logout.php" id="topLogout" class="nav-link logout-link"><i class="iconoir-log-out"></i> 登出</a>
  </nav>
</header>
