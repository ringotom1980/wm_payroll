<?php // Public/modules/company_calendar.php
$PAGE_TITLE = '公司行事曆';
require __DIR__ . '/../partials/header.php';
?>
<link href="/assets/css/company_calendar.css?v=7" rel="stylesheet">
<link href="/assets/css/leave_records.css?v=17" rel="stylesheet">
<link rel="icon" type="image/png" href="/assets/img/WM-logo.png" sizes="32x32">
</head>
<body>
  <div class="layout" id="layout">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <main class="content">
      <h1 class="page-title">
        公司行事曆
        <a href="https://data.gov.tw/dataset/14718" target="_blank" rel="noopener" class="gov-link">取得政府最新公布行事曆</a>
      </h1>

      <!-- 上區塊 -->
      <section class="card section-top">
        <div class="cc-toolbar">
          <div class="cc-left">
            <label class="cc-year"><select id="ccYear"></select></label>
            <label class="cc-month">
              <select id="ccMonth">
                <?php for($m=1;$m<=12;$m++): ?>
                  <option value="<?=$m?>"><?=$m?> 月</option>
                <?php endfor; ?>
              </select>
            </label>
            <button id="btnPrevMonth" class="btn">〈 上月</button>
            <button id="btnNextMonth" class="btn">下月 〉</button>
          </div>
          <div class="cc-right">
            <label class="cc-file">
              <input id="ccCsvFile" type="file" accept=".csv" />
              <span class="btn secondary">匯入政府行事曆</span>
            </label>
            <span id="ccCsvName" class="cc-filename">未選擇檔案</span>
            <button id="btnImportCsv" class="btn primary">開始匯入</button>
          </div>
        </div>

        <div id="ccMonthGrid" class="cc-grid" aria-live="polite"></div>
        <div id="ccMsg" class="cc-msg" role="status" aria-live="polite"></div>
      </section>

      <h1 class="page-title">休請假管理</h1>

      <!-- 下區塊 -->
      <section class="card section-bottom" id="lrSection">
        <div class="lr-toolbar">
          <div class="lr-left">
            <div class="lr-year-display">目前月份：<span id="lrYearText">—</span></div>
          </div>
          <div class="lr-right">
            <input id="lrSearch" class="lr-search" type="search" placeholder="搜尋員工姓名…" />
          </div>
        </div>
<div class="lr-legend">＊每一格的第二行為該年度累計天數。</div>
        <div class="lr-table-wrap">
          <table class="lr-table" id="lrTable" aria-describedby="lrHelp">
            <thead>
              <tr>
                <th>項次</th>
                <th>姓名</th>
                <th>規定特休</th>
                <th>實給特休</th>
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
                <th>育嬰留停</th>
                <th>備註</th>
              </tr>
            </thead>
            <tbody id="lrTbody"></tbody>
          </table>
          <div id="lrMsg" class="lr-msg" role="status" aria-live="polite"></div>
        </div>

        <div id="lrModalsRoot"></div>
        <p id="lrHelp" class="sr-only">點擊各假別欄可開啟「該員該月橫向日列視窗」；完成即儲存，立即刷新年度統計。</p>
      </section>
    </main>
  </div>

  <?php require __DIR__ . '/../partials/footer.php'; ?>
  <script src="/assets/js/company_calendar.js?v=5" defer></script>
  <script src="/assets/js/leave_records.js?v=16" defer></script>
</body>
</html>
