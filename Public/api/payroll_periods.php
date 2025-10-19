<?php
// Public/api/payroll_periods.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// —— 正式機建議：不要把 PHP 錯誤輸出給瀏覽器 ——
// ini_set('display_errors', '0');
// ini_set('log_errors', '1');

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/db.php';
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('PDO not returned from db.php');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 小工具
    $fetchOne = function (string $sql, array $params = []) use ($pdo): ?array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };
    $fetchVal = function (string $sql, array $params = [], $default = 0) use ($fetchOne) {
        $row = $fetchOne($sql, $params);
        if (!$row) return $default;
        $v = reset($row);
        return is_null($v) ? $default : (0 + $v);
    };

    // 健檢：/api/payroll_periods.php?ping=1
    if (isset($_GET['ping'])) {
        echo json_encode(['ok' => true, 'message' => 'pong'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // —— KPI 匯總：/api/payroll_periods.php?summary=1 ——
    if (isset($_GET['summary'])) {
        // 員工總數（若沒有 status 欄位，改用 COUNT(*)）
        $employees = $fetchVal('
            SELECT COUNT(*) FROM employees
            WHERE (status IS NULL OR status = "" OR status = "ACTIVE")
        ', [], 0);

        // 期別狀態（若你的狀態名稱不同，請依實際修改）
        $activePeriods = $fetchVal('
            SELECT COUNT(*) FROM payroll_periods
            WHERE status IN ("OPEN","ACTIVE","IN_PROGRESS")
        ', [], 0);

        $pendingApprovals = $fetchVal('
            SELECT COUNT(*) FROM payroll_periods
            WHERE status IN ("APPROVAL_PENDING","PAY_PENDING","PENDING")
        ', [], 0);

        // 政府資料最後同步時間（若表名不同請調整）
        $lastSyncRow = $fetchOne('
            SELECT DATE_FORMAT(MAX(updated_at), "%Y-%m-%d %H:%i") AS ts
            FROM gov_rate_snapshots
        ');
        $govLastSync = ($lastSyncRow && $lastSyncRow['ts']) ? $lastSyncRow['ts'] : '—';

        echo json_encode([
            'employees'       => (int) $employees,
            'active_periods'  => (int) $activePeriods,
            'pending'         => (int) $pendingApprovals,
            'gov_last_sync'   => $govLastSync,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // —— 近期異動：/api/payroll_periods.php?recent=1&page=1 ——
    if (isset($_GET['recent'])) {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $size = 10;
        $off  = ($page - 1) * $size;

        // 這裡用 payroll_periods 做示範（若你有操作日誌表，改成那張）
        $stmt = $pdo->prepare('
            SELECT
              DATE_FORMAT(COALESCE(updated_at, created_at), "%Y-%m-%d %H:%i:%s") AS time,
              "payroll_periods" AS module,
              CONCAT("狀態：", COALESCE(status,"")) AS content,
              COALESCE(updated_by, created_by, "system") AS user
            FROM payroll_periods
            ORDER BY COALESCE(updated_at, created_at) DESC
            LIMIT :off, :size
        ');
        $stmt->bindValue(':off', $off, PDO::PARAM_INT);
        $stmt->bindValue(':size', $size, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 預設回應
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        // 'trace' => $e->getTraceAsString(), // 調試時可暫時開啟
    ], JSON_UNESCAPED_UNICODE);
}
