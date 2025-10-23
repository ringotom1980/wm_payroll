<?php
// Public/dashboard.php
$app = require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/auth.php';
if (!is_logged_in()) {
  header('Location: /auth/login');
  exit;
}
$APP_NAME = htmlspecialchars($app['APP_NAME'] ?? '旺苗人員薪資管理', ENT_QUOTES);

// 這行會輸出 <!DOCTYPE html><html><head> 與共用 app.css
require __DIR__ . '/partials/header.php';
?>

<title><?= $APP_NAME ?>｜儀表板</title>
<link rel="icon" type="image/png" href="/assets/img/WM-logo.png" sizes="32x32">
</head>
<body>
<?php require __DIR__ . '/partials/sidebar.php'; ?>

<main class="container">
<h1 class="page-title">儀表板</h1>
  <!-- 登入者資訊列（app.js 會填入 display_name 並處理登出） -->
  <!-- <section class="row">
    <div id="authBar" class="auth-bar">
      <span id="userDisplay" class="auth-user">—</span>
      <button id="btnLogout" class="btn-ghost" type="button">登出</button>
    </div>
  </section> -->

  <!-- KPI Cards -->
  <section class="row kpi-grid">
    <div class="card kpi">
      <div class="kpi-title">員工總數</div>
      <div id="kpiEmployees" class="kpi-value">—</div>
      <div class="kpi-sub">在職/離職一覽</div>
    </div>

    <div class="card kpi">
      <div class="kpi-title">開放期別（本月）</div>
      <div id="kpiActivePeriods" class="kpi-value">—</div>
      <div class="kpi-sub">待計薪 / 已核准 / 已鎖定</div>
    </div>

    <div class="card kpi">
      <div class="kpi-title">待核准單</div>
      <div id="kpiPendingApprovals" class="kpi-value">—</div>
      <div class="kpi-sub">期別核准/支付待辦</div>
    </div>

    <div class="card kpi">
      <div class="kpi-title">政府分攤資料</div>
      <div id="govLastSync" class="kpi-value">—</div>
      <div class="kpi-sub">最後更新時間</div>
    </div>
  </section>

  <!-- 操作列：手動同步政府資料 -->
  <section class="row">
    <div class="card actions">
      <div class="actions-left">
        <button id="btnRefreshGov" class="btn" type="button" title="手動同步政府開放資料">
          手動同步政府資料
        </button>
        <span id="govRefreshMsg" class="muted" style="margin-left:10px;"></span>
      </div>
    </div>
  </section>

  <!-- 近期異動 -->
  <section class="row">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">近期異動</h3>
        <div class="card-tools">
          <button id="btnReloadRecent" class="btn-ghost" type="button">重新整理</button>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:160px">時間</th>
              <th style="width:140px">模組</th>
              <th>內容</th>
              <th style="width:120px">操作人</th>
            </tr>
          </thead>
          <tbody id="recentChangesBody">
            <tr><td colspan="4" class="muted center">載入中…</td></tr>
          </tbody>
        </table>
      </div>

      <div class="pager" id="recentPager">
        <button id="recentPrev" class="btn-ghost" type="button">上一頁</button>
        <span id="recentPgInfo" class="muted">—</span>
        <button id="recentNext" class="btn-ghost" type="button">下一頁</button>
      </div>
    </div>
  </section>

  <!-- 月度統計（簡表） -->
  <section class="row">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">本月薪資概覽</h3>
        <div class="card-tools">
          <a class="link" href="/modules/payroll_yearstats.php">前往年度統計</a>
        </div>
      </div>
      <div class="grid-3">
        <div>
          <div class="muted">已計薪人數</div>
          <div id="sumCalcCount" class="stat">—</div>
        </div>
        <div>
          <div class="muted">本月總薪資</div>
          <div id="sumPayroll" class="stat">—</div>
        </div>
        <div>
          <div class="muted">已支付金額</div>
          <div id="sumPaid" class="stat">—</div>
        </div>
      </div>
    </div>
  </section>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
<script src="/assets/js/dashboard.js?v=1"></script>
</body>
</html>
