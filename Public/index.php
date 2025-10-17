<?php
// Public/index.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

// 若已登入，直接進 dashboard
if (is_logged_in()) {
    header('Location: /wm_payroll/Public/dashboard.php');
    exit;
}
$app = require __DIR__ . '/../config/app.php';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理') ?> - 登入</title>
  <link rel="stylesheet" href="/wm_payroll/Public/assets/css/app.css">
  <link rel="stylesheet" href="/wm_payroll/Public/assets/css/users.css">
</head>
<body>
  <div class="login-container">
    <h1><?= htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理') ?></h1>

    <form id="loginForm" class="login-form" autocomplete="off">
      <div class="form-row">
        <label for="username">帳號</label>
        <input type="text" id="username" name="username" required autofocus />
      </div>
      <div class="form-row">
        <label for="password">密碼</label>
        <input type="password" id="password" name="password" required />
      </div>
      <div class="form-actions">
        <button type="submit" id="btnLogin">登入</button>
      </div>
      <p id="loginError" class="error" style="display:none;"></p>
    </form>
  </div>

  <script src="/wm_payroll/Public/assets/js/users.js"></script>
</body>
</html>
