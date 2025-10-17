<?php
// Public/dashboard.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_login(); // 未登入會回 401

$u = auth_user();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>儀表板</title>
  <link rel="stylesheet" href="/wm_payroll/Public/assets/css/app.css">
</head>
<body>
  <h2 style="padding:16px;">Hi，<?= htmlspecialchars($u['display_name'] ?? $u['username']) ?>！這是儀表板佔位頁。</h2>
  <form id="logoutForm" style="padding:16px;">
    <button type="submit">登出</button>
  </form>

  <script>
    document.getElementById('logoutForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(); fd.append('action','logout');
      await fetch('/wm_payroll/Public/api/users.php', { method:'POST', body:fd, credentials:'same-origin' });
      location.href = '/wm_payroll/Public/index.php';
    });
  </script>
</body>
</html>
