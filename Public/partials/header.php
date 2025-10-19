<?php
// Public/partials/header.php
$app = $app ?? (require __DIR__ . '/../../config/app.php');
$APP_NAME = htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理', ENT_QUOTES);
?>
<header class="main-header">
  <div class="header-left">
    <h1 class="site-title"><?= $APP_NAME ?></h1>
  </div>
  <div class="header-right">
    <nav class="top-nav">
      <a href="/dashboard" class="nav-link">儀表板</a>
      <a href="/auth/change" class="nav-link">變更密碼</a>
      <a href="/api/auth/logout.php" id="topLogout" class="nav-link logout-link">登出</a>
    </nav>
  </div>
</header>
