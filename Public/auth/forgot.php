<?php
// Public/auth/forgot.php
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
  <title><?= $APP_NAME ?>｜忘記密碼</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/assets/css/auth.css" rel="stylesheet">
</head>
<body>
  <main class="login-wrap">
    <section class="login-card">
      <h1 class="app-title"><?= $APP_NAME ?></h1>
      <h2 class="card-title">忘記密碼</h2>

      <div id="msg" class="msg" role="alert" aria-live="polite"></div>

      <form id="forgotForm" autocomplete="on">
        <label class="field">
          <span>帳號</span>
          <input id="username" name="username" type="text" inputmode="email" required autofocus>
        </label>

        <div class="actions">
          <button type="submit" id="btnSend" class="btn-primary">送出重設連結</button>
          <a class="link" href="/auth/login">回登入</a>
        </div>
      </form>

      <footer class="footer-note">© <?= date('Y') ?> 旺苗 Internal</footer>
    </section>
  </main>

  <script src="/assets/js/auth/forgot.js"></script>
</body>
</html>
