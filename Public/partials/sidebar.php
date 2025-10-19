<?php
// Public/partials/sidebar.php
// 注意：依照你的要求「不修改結構，只增連結」
// 如未登入導向由各頁自行判斷，這裡不判斷登入狀態
?>
<aside class="sidebar">
  <ul class="menu">
    <li><a href="/dashboard" class="menu-link">🏠 儀表板</a></li>

    <li class="menu-section">人員資料</li>
    <li><a href="/modules/employees.php" class="menu-link">👥 員工主檔</a></li>

    <li class="menu-section">薪資設定</li>
    <li><a href="/modules/salary_master.php" class="menu-link">💰 薪資主檔</a></li>
    <li><a href="/modules/payroll_periods.php" class="menu-link">🗓️ 薪資期別</a></li>

    <li class="menu-section">薪資處理</li>
    <li><a href="/modules/payroll_monthlies.php" class="menu-link">🧾 當月薪資</a></li>
    <li><a href="/modules/payroll_yearstats.php" class="menu-link">📈 年度統計</a></li>

    <li class="menu-section">系統管理</li>
    <li><a href="/modules/users.php" class="menu-link">🔑 使用者管理</a></li>
  </ul>
</aside>
