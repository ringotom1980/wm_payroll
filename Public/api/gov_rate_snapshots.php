<?php
// Public/api/gov_rate_snapshots.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// -------------------------------
// 0) 授權檢查（需登入）
// -------------------------------
if (session_status() === PHP_SESSION_NONE) session_start();
if (
    empty($_SESSION['user']) &&
    empty($_SESSION['uid']) &&
    empty($_SESSION['username']) &&
    empty($_SESSION['auth'])
) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

// -------------------------------
// 1) DB 連線
// -------------------------------
try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../config/db.php';
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB 連線失敗: ' . $e->getMessage()]);
    exit;
}

// -------------------------------
// 2) 參數
// -------------------------------
$mode = $_GET['mode'] ?? 'status';
$scheme = $_GET['scheme'] ?? null;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;

// -------------------------------
// 3) 三個模式：status / levels / snapshots
// -------------------------------
try {

    if ($mode === 'status') {
        // 取各制度最新 snapshot 狀態
        $stmt = $pdo->query("
            SELECT scheme, snapshot_id, effective_date, version_label, record_count, fetched_at
            FROM gov_rate_snapshots
            WHERE status='ACTIVE'
            ORDER BY scheme
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'status'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'levels') {
        if (!$scheme) {
            echo json_encode(['ok'=>false,'message'=>'請指定 scheme=LABOR_INSURANCE|LABOR_PENSION|NHI']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT l.level_no, l.wage_min, l.wage_max, l.base_amount, l.category,
                   s.effective_date, s.version_label, s.snapshot_id
            FROM gov_rate_levels l
            JOIN gov_rate_snapshots s ON s.snapshot_id=l.snapshot_id
            WHERE s.scheme=? AND s.status='ACTIVE'
            ORDER BY l.level_no
        ");
        $stmt->execute([$scheme]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'scheme'=>$scheme,'levels'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'snapshots') {
        $sql = "SELECT scheme, snapshot_id, version_label, effective_date, record_count, status, fetched_at 
                FROM gov_rate_snapshots ORDER BY fetched_at DESC LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'snapshots'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok'=>false,'message'=>'未知的 mode 參數']);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
    exit;
}
