<?php // Public/modules/company_calendar.php
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <title>公司行事曆｜旺苗人員薪資管理</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- 全站樣式 -->
  <link href="/assets/css/app.css?v=1" rel="stylesheet">
  <!-- 上區塊：公司行事曆樣式（外掛） -->
  <link href="/assets/css/company_calendar.css?v=1" rel="stylesheet">
  <link rel="icon" type="image/png" href="/assets/img/WM-logo.png" sizes="32x32">
</head>
<body>
<?php include __DIR__ . '/../partials/header.php'; ?>
<div class="layout" id="layout">
  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main class="content">
    <h1 class="page-title">公司行事曆</h1>

    <!-- 共用年度/月份切換（本頁上區塊專用；下區塊之後再接） -->
    <section class="card section-top">
      <div class="cc-toolbar">
        <div class="cc-left">
          <label class="cc-year">
            <span>年度</span>
            <select id="ccYear"></select>
          </label>
          <label class="cc-month">
            <span>月份</span>
            <select id="ccMonth">
              <option value="1">1 月</option>
              <option value="2">2 月</option>
              <option value="3">3 月</option>
              <option value="4">4 月</option>
              <option value="5">5 月</option>
              <option value="6">6 月</option>
              <option value="7">7 月</option>
              <option value="8">8 月</option>
              <option value="9">9 月</option>
              <option value="10">10 月</option>
              <option value="11">11 月</option>
              <option value="12">12 月</option>
            </select>
          </label>
          <button id="btnPrevMonth" class="btn">〈 上月</button>
          <button id="btnNextMonth" class="btn">下月 〉</button>
        </div>
        <div class="cc-right">
          <label class="cc-file">
            <input id="ccCsvFile" type="file" accept=".csv" />
            <span class="btn secondary">匯入政府 CSV</span>
          </label>
          <button id="btnImportCsv" class="btn primary">開始匯入</button>
        </div>
      </div>

      <!-- 月份橫向日列 -->
      <div id="ccMonthGrid" class="cc-grid" aria-live="polite"></div>

      <!-- 錯誤/提示列 -->
      <div id="ccMsg" class="cc-msg" role="status" aria-live="polite"></div>
    </section>

    <!-- 之後：下區塊（員工年度統計）會在同頁下方接上 -->
  </main>
</div>

<!-- 上區塊：公司行事曆腳本（外掛） -->
<script src="/assets/js/company_calendar.js?v=1"></script>
</body>
</html>
