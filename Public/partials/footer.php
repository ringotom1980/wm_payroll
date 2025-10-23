<?php
// Public/partials/footer.php
$app = $app ?? (require __DIR__ . '/../../config/app.php');
$APP_NAME = htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理', ENT_QUOTES);
$version = htmlspecialchars($app['APP_VERSION'] ?? 'v1.0.0', ENT_QUOTES);
?>
<footer class="main-footer">
  <div class="footer-left">
    <span class="muted">© <?= date('Y') ?> <?= $APP_NAME ?> · All rights reserved.</span>
  </div>
  <div class="footer-right">
    <span class="muted">
      版本：<?= $version ?>｜政府分攤資料：<span id="govVer">最新</span>
    </span>
  </div>
</footer>

<!-- 全站共用腳本（外掛，不內嵌） -->
<?php
$jsVerApp = filemtime(__DIR__ . '/../assets/js/app.js');
$jsVerLogout = filemtime(__DIR__ . '/../assets/js/auth/logout.js');
?>
<script src="/assets/js/app.js?v=<?= $jsVerApp ?>"></script>
<script src="/assets/js/auth/logout.js?v=<?= $jsVerLogout ?>"></script>

</body>
</html>
