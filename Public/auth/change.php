<?php
// Public/auth/change.php
$app = require __DIR__ . '/../../config/app.php';
require __DIR__ . '/../../config/auth.php';

// 必須已登入，未登入會直接回 401 JSON（這裡改成導向登入頁較友善）
if (!is_logged_in()) {
    header('Location: /auth/login');
    exit;
}

$APP_NAME = htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理', ENT_QUOTES);
$user = auth_user();
$display = htmlspecialchars($user['display_name'] ?? $user['username'] ?? '', ENT_QUOTES);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <title><?= $APP_NAME ?>｜變更密碼</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/assets/css/auth.css" rel="stylesheet">
</head>
<body>
  <main class="login-wrap">
    <section class="login-card">
      <h1 class="app-title"><?= $APP_NAME ?></h1>
      <h2 class="card-title">變更密碼</h2>
      <p class="footer-note" style="margin-top:-6px;margin-bottom:12px;">使用者：<?= $display ?></p>

      <div id="msg" class="msg" role="alert" aria-live="polite"></div>

      <form id="changeForm" autocomplete="off">
        <label class="field">
          <span>目前密碼</span>
          <input id="curr" name="current_password" type="password" required>
        </label>

        <label class="field">
          <span>新密碼（至少 8 碼）</span>
          <input id="new1" name="new_password" type="password" minlength="8" required>
        </label>

        <label class="field">
          <span>再次輸入新密碼</span>
          <input id="new2" name="new_password2" type="password" minlength="8" required>
        </label>

        <div class="actions">
          <button type="submit" id="btnChange" class="btn-primary">更新密碼</button>
          <a class="link" href="/dashboard">返回儀表板</a>
        </div>
      </form>

      <footer class="footer-note">© <?= date('Y') ?> 旺苗 Internal</footer>
    </section>
  </main>

  <script src="/assets/js/auth/change.js"></script>
</body>
</html>
