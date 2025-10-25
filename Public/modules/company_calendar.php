<?php // Public/modules/company_calendar.php ?>
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
  <!-- 下區塊：員工年度統計樣式（外掛） -->
  <link href="/assets/css/leave_records.css?v=1" rel="stylesheet">

  <link rel="icon" type="image/png" href="/assets/img/WM-logo.png" sizes="32x32">
</head>
<body>
<?php include __DIR__ . '/../partials/header.php'; ?>
<div class="layout" id="layout">
  <?php include __DIR__ . '/../partials/sidebar.php'; ?>

  <main class="content">
    <h1 class="page-title">公司行事曆</h1>

    <!-- 上區塊：共用年度/月份切換（本頁上區塊專用；下區塊共用年度值） -->
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
              <option value="1">1 月</option><option value="2">2 月</option><option value="3">3 月</option>
              <option value="4">4 月</option><option value="5">5 月</option><option value="6">6 月</option>
              <option value="7">7 月</option><option value="8">8 月</option><option value="9">9 月</option>
              <option value="10">10 月</option><option value="11">11 月</option><option value="12">12 月</option>
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

    <!-- 下區塊：員工年度統計（同頁下方；由 leave_records.js 讀取/渲染） -->
    <section class="card section-bottom" id="lrSection">
      <div class="lr-toolbar">
        <div class="lr-left">
          <!-- 共用年度切換：下區塊直接監聽 #ccYear 的變化 -->
          <div class="lr-year-display">年度：<span id="lrYearText">—</span></div>
        </div>
        <div class="lr-right">
          <!-- 可放篩選/搜尋（未實作時保留容器） -->
          <input id="lrSearch" class="lr-search" type="search" placeholder="搜尋員工姓名…" />
        </div>
      </div>

      <div class="lr-table-wrap">
        <table class="lr-table" id="lrTable" aria-describedby="lrHelp">
          <thead>
            <tr>
              <th style="width:56px;">項次</th>
              <th style="min-width:160px;">姓名</th>
              <th>應休特休</th>
              <th>已休特休</th>
              <th>剩餘特休</th>
              <th>普病</th>
              <th>公病</th>
              <th>婚假</th>
              <th>喪假</th>
              <th>事假</th>
              <th>產假</th>
              <th>陪產假</th>
              <th>照顧假</th>
              <th>生理假</th>
              <th style="min-width:180px;">育嬰留停</th>
              <th style="min-width:180px;">備註</th>
            </tr>
          </thead>
          <tbody id="lrTbody"><!-- 由 JS 動態渲染 --></tbody>
        </table>
        <div id="lrMsg" class="lr-msg" role="status" aria-live="polite"></div>
      </div>

      <!-- 月度編輯/備註彈窗：由 leave_records.js 動態插入 -->
      <div id="lrModalsRoot"></div>

      <p id="lrHelp" class="sr-only">
        點擊各假別欄可開啟「該員該月橫向日列視窗」；完成即儲存，立即刷新年度統計。
      </p>
    </section>

  </main>
</div>

<!-- 外掛腳本：上區塊 -->
<script src="/assets/js/company_calendar.js?v=1"></script>
<!-- 外掛腳本：下區塊 -->
<script src="/assets/js/leave_records.js?v=1"></script>
</body>
</html>
