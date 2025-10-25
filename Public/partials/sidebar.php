<?php
// Public/partials/sidebar.php
// 注意：依你的要求「不修改結構，只增連結」

$current = basename($_SERVER['PHP_SELF']); // 目前頁面的檔名
function active($file)
{
  global $current;
  return $current === $file ? 'active' : '';
}
?>
<aside class="sidebar">
  <ul class="menu">
    <li><a href="/dashboard" class="menu-link <?= active('dashboard.php') ?>"><i class="iconoir-home-simple"></i> 儀表板</a></li>

    <li class="menu-section">人員資料</li>
    <li><a href="/modules/employees.php" class="menu-link <?= active('employees.php') ?>"><i class="iconoir-group"></i> 員工主檔</a></li>
    <li><a href="/modules/company_calendar.php" class="menu-link <?= active('company_calendar.php') ?>"><i class="iconoir-calendar-minus"></i> 休假管理</a></li>

    <li class="menu-section">薪資設定</li>
    <li><a href="/modules/salary_master.php" class="menu-link <?= active('salary_master.php') ?>"><i class="iconoir-money-square"></i> 薪資主檔</a></li>
    <li><a href="/modules/payroll_periods.php" class="menu-link <?= active('payroll_periods.php') ?>"><i class="iconoir-calendar"></i> 薪資期別</a></li>

    <li class="menu-section">薪資處理</li>
    <li><a href="/modules/payroll_monthlies.php" class="menu-link <?= active('payroll_monthlies.php') ?>"><i class="iconoir-coins"></i> 當月薪資</a></li>
    <li><a href="/modules/payroll_yearstats.php" class="menu-link <?= active('payroll_yearstats.php') ?>"><i class="iconoir-stats-report"></i> 年度統計</a></li>

    <li class="menu-section">系統管理</li>
    <li><a href="/modules/users.php" class="menu-link <?= active('users.php') ?>"><i class="iconoir-key"></i> 使用者管理</a></li>
  </ul>
</aside>