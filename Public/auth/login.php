<?php
// Public/auth/login.php
$app = require __DIR__ . '/../../config/app.php';
require __DIR__ . '/../../config/auth.php';

// 已登入 → 導向儀表板
if (is_logged_in()) {
    header('Location: /dashboard');
    exit;
}

$APP_NAME = htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理', ENT_QUOTES);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <title><?= $APP_NAME ?>｜登入</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/assets/css/auth.css?v=2" rel="stylesheet">
</head>
<body>
  <main class="login-wrap">
    <section class="login-card">
      <h1 class="app-title"><?= $APP_NAME ?></h1>
      <h2 class="card-title">登入</h2>

      <!-- ✅ 登入／登出訊息 -->
      <div id="msg" class="msg" role="alert" aria-live="polite"></div>

      <form id="loginForm" autocomplete="on">
        <label class="field">
          <span>帳號</span>
          <input id="username" name="username" type="text" inputmode="email" required autofocus>
        </label>

        <label class="field">
          <span>密碼</span>
          <input id="password" name="password" type="password" required>
        </label>

        <div class="actions">
          <button type="submit" id="btnLogin" class="btn-primary">登入</button>
          <a class="link" href="/auth/forgot">忘記密碼？</a>
        </div>
      </form>

      <footer class="footer-note">© <?= date('Y') ?> 旺苗 Internal</footer>
    </section>
  </main>

  <script src="/assets/js/auth/login.js?v=2"></script>
</body>
</html>
