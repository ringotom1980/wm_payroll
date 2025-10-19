<?php
// Public/api/payroll_periods.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/pdo.php';
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 小工具
    $fetch_one = function (string $sql, array $params = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $fetch_val = function (string $sql, array $params = [], $default = 0) use ($fetch_one) {
        $row = $fetch_one($sql, $params);
        if (!$row) return $default;
        $v = reset($row);
        return is_null($v) ? $default : 0 + $v;
    };

    // --- summary: KPI 匯總 ---
    if (isset($_GET['summary'])) {
        $employees         = $fetch_val('SELECT COUNT(*) FROM employees WHERE status = "ACTIVE"', [], 0);

        // 視你的 schema 調整（以下示意欄位：status: OPEN/APPROVED/LOCKED）
        $active_periods    = $fetch_val('SELECT COUNT(*) FROM payroll_periods WHERE status = "OPEN"', [], 0);
        $pending_approvals = $fetch_val('SELECT COUNT(*) FROM payroll_periods WHERE status IN ("APPROVAL_PENDING","PAY_PENDING")', [], 0);

        // 政府資料最後同步時間（請依你的表調整）
        $gov_last_sync = $fetch_one('SELECT DATE_FORMAT(MAX(updated_at), "%Y-%m-%d %H:%i") AS ts FROM gov_rate_snapshots');
        $gov_last_sync_str = ($gov_last_sync && $gov_last_sync['ts']) ? $gov_last_sync['ts'] : '—';

        echo json_encode([
            'employees'        => (int)$employees,
            'active_periods'   => (int)$active_periods,
            'pending'          => (int)$pending_approvals,
            'gov_last_sync'    => $gov_last_sync_str,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- recent: 近期異動列表（示意） ---
    if (isset($_GET['recent'])) {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $size = 10;
        $off  = ($page - 1) * $size;

        // 依你的審批/操作紀錄表調整（示意抓最近期別操作）
        $stmt = $pdo->prepare(
            'SELECT 
                DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i:%s") AS time,
                "payroll_periods" AS module,
                CONCAT("狀態：", status) AS content,
                COALESCE(updated_by, "system") AS user
             FROM payroll_periods
             ORDER BY updated_at DESC
             LIMIT :off, :size'
        );
        $stmt->bindValue(':off', $off, PDO::PARAM_INT);
        $stmt->bindValue(':size', $size, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 預設
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
// 結尾不要有 "?>"
