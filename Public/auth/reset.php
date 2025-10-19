<?php
// Public/auth/reset.php
$app = require __DIR__ . '/../../config/app.php';
require __DIR__ . '/../../config/auth.php';

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
  <title><?= $APP_NAME ?>｜重設密碼</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/assets/css/auth.css" rel="stylesheet">
</head>
<body>
  <main class="login-wrap">
    <section class="login-card">
      <h1 class="app-title"><?= $APP_NAME ?></h1>
      <h2 class="card-title">重設密碼</h2>

      <div id="msg" class="msg" role="alert" aria-live="polite"></div>

      <form id="resetForm" autocomplete="off">
        <label class="field">
          <span>新密碼</span>
          <input id="newPassword" name="new_password" type="password" minlength="8" required>
        </label>

        <label class="field">
          <span>再次輸入新密碼</span>
          <input id="newPassword2" name="new_password2" type="password" minlength="8" required>
        </label>

        <div class="actions">
          <button type="submit" id="btnReset" class="btn-primary">設定新密碼</button>
          <a class="link" href="/auth/login">回登入</a>
        </div>
      </form>

      <footer class="footer-note">© <?= date('Y') ?> 旺苗 Internal</footer>
    </section>
  </main>

  <script src="/assets/js/auth/reset.js"></script>
</body>
</html>
