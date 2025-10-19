<?php
// Public/modules/employees.php
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <title>員工主檔｜旺苗人員薪資管理</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/assets/css/app.css" rel="stylesheet">
  <link href="/assets/css/employees.css?v=3" rel="stylesheet">
</head>
<body>
  <?php include __DIR__ . '/../partials/header.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <main class="content">
      <h1 class="page-title">員工主檔</h1>

      <!-- 上區塊：表單 -->
      <section id="empFormSection" class="card section-top">
        <div class="section-header">
          <h2>員工資料</h2>
        </div>
        <form id="empForm" autocomplete="off">
          <input type="hidden" id="emp_id">
          <div class="grid two-col">
            <label>姓名<span class="req">*</span>
              <input type="text" id="full_name" placeholder="請輸入姓名">
              <small class="err" data-for="full_name"></small>
            </label>

            <label>入職日<span class="req">*</span>
              <input type="date" id="hire_date">
              <small class="err" data-for="hire_date"></small>
            </label>

            <label>電話（市話）
              <input type="text" id="phone" placeholder="例：02-xxxxxxx">
              <small class="err" data-for="phone"></small>
            </label>

            <label>手機
              <input type="text" id="phone_mobile" placeholder="例：09xx-xxx-xxx">
              <small class="err" data-for="phone_mobile"></small>
            </label>

            <label>地址
              <input type="text" id="address" placeholder="請輸入地址">
              <small class="err" data-for="address"></small>
            </label>

            <label>Email
              <input type="email" id="email" placeholder="name@example.com">
              <small class="err" data-for="email"></small>
            </label>

            <label>在職狀況
              <select id="status">
                <option value="ACTIVE">在職</option>
                <option value="LEAVE">留職停薪</option>
                <option value="RESIGNED">離職</option>
              </select>
              <small class="err" data-for="status"></small>
            </label>

            <label>健保眷口數
              <input type="number" id="dependents_count" min="0" step="1" value="0">
              <small class="err" data-for="dependents_count"></small>
            </label>

            <label>預計復職日（限留職停薪時）
              <input type="date" id="expected_return_date" disabled>
              <small class="err" data-for="expected_return_date"></small>
            </label>

            <label>離職日（限離職時）
              <input type="date" id="resign_date" disabled>
              <small class="err" data-for="resign_date"></small>
            </label>
          </div>

          <div class="actions">
            <button type="button" id="btnCreate" class="btn primary">新增員工</button>
            <button type="button" id="btnSave" class="btn" disabled>儲存修改</button>
            <button type="button" id="btnCancel" class="btn ghost" hidden>放棄修改</button>
          </div>
        </form>
      </section>

      <!-- 中區塊：現職員工列表 -->
      <section class="card">
        <div class="section-header">
          <h2>現職員工</h2>
          <div class="toolbar">
            <input type="search" id="qActive" placeholder="逐字搜尋：姓名/電話/手機/Email/地址">
            <button type="button" id="btnActiveEdit" class="btn">編輯</button>
            <button type="button" id="btnActivePrint" class="btn">列印</button>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table" id="tblActive">
            <thead>
              <tr>
                <th>#</th><th>姓名</th><th>電話</th><th>手機</th><th>地址</th><th>Email</th>
                <th>入職日</th><th>在職狀況</th><th>已任職時間</th><th>眷口數</th><th class="op-col" hidden>操作</th>
              </tr>
            </thead>
            <tbody id="tbodyActive"></tbody>
          </table>
        </div>

        <div class="pager">
          <button class="btn" id="activePrev">上一頁</button>
          <span id="activePgInfo">第 1 / 1 頁</span>
          <button class="btn" id="activeNext">下一頁</button>
        </div>
      </section>

      <!-- 下區塊：離職員工列表 -->
      <section class="card">
        <div class="section-header">
          <h2>離職員工</h2>
          <div class="toolbar">
            <input type="search" id="qResigned" placeholder="逐字搜尋：姓名/電話/手機/Email/地址">
            <button type="button" id="btnResignedEdit" class="btn">編輯</button>
            <button type="button" id="btnResignedPrint" class="btn">列印</button>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table" id="tblResigned">
            <thead>
              <tr>
                <th>#</th><th>姓名</th><th>電話</th><th>手機</th><th>地址</th><th>Email</th>
                <th>入職日</th><th>離職日</th><th>在職狀況</th><th>已任職時間</th><th class="op-col" hidden>操作</th>
              </tr>
            </thead>
            <tbody id="tbodyResigned"></tbody>
          </table>
        </div>

        <div class="pager">
          <button class="btn" id="resignedPrev">上一頁</button>
          <span id="resignedPgInfo">第 1 / 1 頁</span>
          <button class="btn" id="resignedNext">下一頁</button>
        </div>
      </section>
    </main>
  </div>

  <!-- 確認彈窗（共用） -->
  <div id="modalMask" class="mask" hidden></div>
  <div id="modal" class="modal" hidden>
    <div class="modal-body">
      <h3 id="modalTitle">確認</h3>
      <p id="modalMsg">確定要執行嗎？</p>
      <div class="modal-actions">
        <button type="button" id="modalCancel" class="btn">取消</button>
        <button type="button" id="modalOk" class="btn primary">確定</button>
      </div>
    </div>
  </div>

  <script src="/assets/js/employees.js?v=3" defer></script>
</body>
</html>
