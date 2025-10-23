<?php
// Public/gov_rate_snapshots.php
declare(strict_types=1);
$pdo = require __DIR__ . '/../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function count_table(PDO $pdo, string $table): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}
function latest_eff(PDO $pdo, string $table): ?string {
    $stmt = $pdo->query("SELECT MAX(effective_date) FROM {$table}");
    $d = $stmt->fetchColumn();
    return $d ?: null;
}
$li_cnt = count_table($pdo, 'gov_labor_insurance');
$lp_cnt = count_table($pdo, 'gov_labor_pension');
$nhi_cnt= count_table($pdo, 'gov_nhi');

$li_eff = latest_eff($pdo, 'gov_labor_insurance');
$lp_eff = latest_eff($pdo, 'gov_labor_pension');
$nhi_eff= latest_eff($pdo, 'gov_nhi');
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title>政府級距資料（簡版）</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Noto Sans TC,Arial,sans-serif; padding:16px}
.card{border:1px solid #ddd; border-radius:12px; padding:16px; margin-bottom:16px}
.btn{padding:8px 12px; border:0; border-radius:8px; background:#222; color:#fff; cursor:pointer}
.btn:disabled{opacity:.6; cursor:not-allowed}
.kv{display:grid; grid-template-columns:120px 1fr; gap:6px 12px}
small{color:#666}
</style>
</head>
<body>
  <h1>政府級距資料（簡版）</h1>

  <div class="card">
    <button id="btnSync" class="btn">同步最新資料</button>
    <span id="syncMsg" style="margin-left:12px;"></span>
  </div>

  <div class="card">
    <h2>勞工保險</h2>
    <div class="kv">
      <div>筆數</div><div><?= htmlspecialchars((string)$li_cnt) ?></div>
      <div>生效日</div><div><?= htmlspecialchars((string)($li_eff ?? '—')) ?></div>
    </div>
    <small>表：gov_labor_insurance</small>
  </div>

  <div class="card">
    <h2>勞工退休金</h2>
    <div class="kv">
      <div>筆數</div><div><?= htmlspecialchars((string)$lp_cnt) ?></div>
      <div>生效日</div><div><?= htmlspecialchars((string)($lp_eff ?? '—')) ?></div>
    </div>
    <small>表：gov_labor_pension</small>
  </div>

  <div class="card">
    <h2>全民健康保險</h2>
    <div class="kv">
      <div>筆數</div><div><?= htmlspecialchars((string)$nhi_cnt) ?></div>
      <div>生效日</div><div><?= htmlspecialchars((string)($nhi_eff ?? '—')) ?></div>
    </div>
    <small>表：gov_nhi</small>
  </div>

<script src="./js/dashboard.js?v=3"></script>
</body>
</html>
