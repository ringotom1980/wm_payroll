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
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="main-header">
  <div class="header-left">
    <h1 class="site-title"><i class="iconoir-building"></i> <?= $APP_NAME ?></h1>
  </div>
  <div class="header-right">
    <nav class="top-nav">
      <a href="/dashboard" class="nav-link"><i class="iconoir-home-simple"></i> 儀表板</a>
      <a href="/auth/change" class="nav-link"><i class="iconoir-lock"></i> 變更密碼</a>
      <a href="/api/auth/logout.php" id="topLogout" class="nav-link logout-link"><i class="iconoir-log-out"></i> 登出</a>
    </nav>
  </div>
</header>
