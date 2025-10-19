<?php
// Public/partials/footer.php
$app = $app ?? (require __DIR__ . '/../../config/app.php');
$APP_NAME = htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理', ENT_QUOTES);
$version = 'v1.0.0'; // 可改成讀取 config/app.php 或 git tag
?>
<footer class="main-footer">
  <div class="footer-left">
    <span class="muted">© <?= date('Y') ?> <?= $APP_NAME ?>　All rights reserved.</span>
  </div>
  <div class="footer-right">
    <span class="muted">版本：<?= $version ?>｜政府分攤資料：最新</span>
  </div>
</footer>
