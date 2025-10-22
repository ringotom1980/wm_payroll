<?php // Public/modules/salary_master.php ?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <title>薪資主檔｜旺苗人員薪資管理</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/assets/css/app.css?v=1" rel="stylesheet">
  <link href="/assets/css/salary_master.css?v=1" rel="stylesheet">
  <link rel="icon" type="image/png" href="/assets/img/WM-logo.png" sizes="32x32">
</head>
<body>
<?php include __DIR__ . '/../partials/header.php'; ?>

<div class="layout" id="layout">
  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main class="content">
    <h1 class="page-title">薪資主檔</h1>

    <!-- 上表：收入項 -->
    <section class="card section-block" id="incomeSection">
      <div class="section-header"><h2>收入項</h2></div>
      <div class="table-wrap">
        <table class="wm-table" id="tblIncome" aria-label="收入項表格">
          <thead>
            <tr>
              <th>項次</th>
              <th>姓名</th>
              <th>本薪</th>
              <th>主管津貼</th>
              <th>工作獎金</th>
              <th>電話費(補助)</th>
              <th>伙食費</th>
              <th>加油費</th>
              <th>專業加給</th>
              <th>全勤獎金</th>
              <th class="is-clickable">加班時數</th>
              <th>加班費</th>
              <th>其它</th>
            </tr>
          </thead>
          <tbody id="incomeBody"></tbody>
          <tfoot>
            <tr>
              <th colspan="2">本頁小計</th>
              <th colspan="11"></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <!-- 下表：扣項 -->
    <section class="card section-block" id="deductSection">
      <div class="section-header"><h2>扣項</h2></div>
      <div class="table-wrap">
        <table class="wm-table" id="tblDeduct" aria-label="扣項表格">
          <thead>
            <tr>
              <th>項次</th>
              <th>姓名</th>
              <th>薪資總額</th>
              <th>勞保級距(政)</th>
              <th>勞保級距(實)</th>
              <th>勞保費</th>
              <th>健保級距(政)</th>
              <th>健保級距(實)</th>
              <th>眷口數</th>
              <th>健保費</th>
              <th class="is-clickable">請假天數</th>
              <th>請假扣薪</th>
              <th>分期還款</th>
              <th>電話費(代扣)</th>
              <th>自提6%</th>
              <th>其它</th>
            </tr>
          </thead>
          <tbody id="deductBody"></tbody>
          <tfoot>
            <tr>
              <th colspan="2">本頁小計</th>
              <th colspan="14"></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  </main>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
<script src="/assets/js/salary_master.js?v=1"></script>
</body>
</html>
