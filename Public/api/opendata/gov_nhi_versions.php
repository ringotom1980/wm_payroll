<?php
// Public/api/opendata/gov_nhi_versions.php
// 功能：掃描健保資料集版本，更新 gov_nhi_state / gov_nhi_versions
// 調整：避免「MySQL server has gone away」— 掃描前釋放連線、寫入前重連、寫入失敗自動重試一次
// PHP 7.2 + cURL

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php'; // 取得 $pdo (PDO 連線)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// -----------------------------
// 基本設定（縮短逾時、降低單次探測上限，減少長時間閒置）
// -----------------------------
const DATASET_API_BASE = 'https://info.nhi.gov.tw/api/iode0000s01/Dataset?rId=';
const MISS_LIMIT  = 2;  // 連續 MISS 幾次後停止
const PROBE_LIMIT = 8;  // 單次最多探測幾個候選

$response = ['ok' => false, 'found' => [], 'updated' => null, 'error' => null];

// -----------------------------
// 工具：base-36 3 碼遞增（0-9A-Z）
// -----------------------------
function rid_next(?string $rid): string
{
    if (!$rid) return '001';
    $digits = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $map = array_flip(str_split($digits));
    $a = str_split($rid);
    if (count($a) !== 3) throw new RuntimeException("RID長度必須是3: " . $rid);
    for ($i = 2; $i >= 0; $i--) {
        $v = $map[$a[$i]];
        if ($v < 35) {
            $a[$i] = $digits[$v + 1];
            return implode('', $a);
        }
        $a[$i] = '0';
    }
    return '000'; // 理論上不會用到
}

// -----------------------------
// 工具：檢查版本是否存在（HTTP 200 + 有內容）
// -----------------------------
function version_exists(string $ridFull): bool
{
    $url = DATASET_API_BASE . rawurlencode($ridFull);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12, // 原 20 → 12
        CURLOPT_CONNECTTIMEOUT => 5,  // 原 8  → 5
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_NOBODY         => false,
        CURLOPT_HEADER         => false,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // 狀態200且內容長度合理才算存在
    return ($status === 200 && $resp && strlen((string)$resp) > 100);
}

// -----------------------------
// 工具：寫庫時遇 2006 斷線 → 重連一次再重試
// -----------------------------
function pdo_exec_retry(callable $fn)
{
    try {
        return $fn();
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (
            strpos($msg, '2006 MySQL server has gone away') !== false ||
            strpos($msg, 'server has gone away') !== false
        ) {
            // 斷線 → 重連一次
            require __DIR__ . '/../../../config/db.php'; // 建立新的 $pdo
            $GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // 再重試
            return $fn();
        }
        throw $e;
    }
}

// -----------------------------
// 工具：取 prefix（重連後也能穩定取得）
// -----------------------------
function get_prefix(PDO $pdo): string
{
    $r = $pdo->query("SELECT prefix FROM gov_nhi_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$r || empty($r['prefix'])) throw new RuntimeException('prefix 取得失敗');
    return $r['prefix'];
}

try {
    // 取得當前 prefix / last_rid
    $row = $pdo->query("SELECT prefix, last_rid FROM gov_nhi_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('gov_nhi_state 尚未初始化');
    }

    $prefix  = $row['prefix'];
    $lastRid = $row['last_rid'];

    // ★ 掃描前先釋放 DB 連線，避免長時間 HTTP 掃描導致閒置超時
    $pdo = null;

    // 開始往上掃描
    $cur   = rid_next($lastRid);
    $found = [];
    $miss  = 0;
    $tries = 0;

    while ($tries < PROBE_LIMIT) {
        $tries++;
        $ridFull = $prefix . $cur;
        if (version_exists($ridFull)) {
            $found[] = $cur;
            $miss = 0;
            $cur = rid_next($cur);
        } else {
            $miss++;
            if ($miss >= MISS_LIMIT) break;
            $cur = rid_next($cur);
        }
    }

    // ★ 寫入前重連 DB
    require __DIR__ . '/../../../config/db.php';
    /** @var PDO $pdo */
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($found) {
        pdo_exec_retry(function () use (&$pdo, $found) {
            $now = date('Y-m-d H:i:s');
            $pdo->beginTransaction();

            // 以重連後的 prefix 為準，避免先前變數失效
            $prefixNow = get_prefix($pdo);

            foreach ($found as $rid) {
                $stmt = $pdo->prepare(
                    "INSERT IGNORE INTO gov_nhi_versions (rid, resource_id_full, fetched_at, status)
                     VALUES (?,?,?,?)"
                );
                $stmt->execute([$rid, $prefixNow . $rid, $now, 'FOUND']);
            }

            $latest = end($found);
            $stmt2 = $pdo->prepare(
                "UPDATE gov_nhi_state
                 SET last_rid=?, last_checked_at=?, last_updated_at=?
                 WHERE id=1"
            );
            $stmt2->execute([$latest, $now, $now]);

            $pdo->commit();
        });

        $response['ok']      = true;
        $response['found']   = $found;
        $response['updated'] = end($found);
    } else {
        // 沒找到新版本也更新 last_checked_at
        pdo_exec_retry(function () use (&$pdo) {
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE gov_nhi_state SET last_checked_at=? WHERE id=1");
            $stmt->execute([$now]);
        });

        $response['ok']      = true;
        $response['found']   = [];
        $response['updated'] = null;
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
