<?php
// Public/api/opendata/gov_nhi_versions.php
// 功能：掃描健保資料集版本，更新 gov_nhi_state / gov_nhi_versions
// PHP 7.2 + cURL 相容

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php'; // 取得 $pdo (PDO 連線)

// -----------------------------
// 基本設定
// -----------------------------
const DATASET_API_BASE = 'https://info.nhi.gov.tw/api/iode0000s01/Dataset?rId=';
const MISS_LIMIT = 2;   // 連續失敗幾次後停止掃描
const PROBE_LIMIT = 20; // 每次最多嘗試版本數

$response = ['ok' => false, 'found' => [], 'updated' => null, 'error' => null];

try {
    // 取得當前 prefix / last_rid
    $row = $pdo->query("SELECT prefix, last_rid FROM gov_nhi_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('gov_nhi_state 尚未初始化');
    }

    $prefix  = $row['prefix'];
    $lastRid = $row['last_rid'];

    // -----------------------------
    // base36 轉換工具
    // -----------------------------
    function rid_next(?string $rid): string {
        if (!$rid) return '001';
        $digits = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $map = array_flip(str_split($digits));
        $a = str_split($rid);
        for ($i = 2; $i >= 0; $i--) {
            $v = $map[$a[$i]];
            if ($v < 35) {
                $a[$i] = $digits[$v + 1];
                return implode('', $a);
            }
            $a[$i] = '0';
        }
        return '000';
    }

    // -----------------------------
    // 檢查該版本是否存在（HTTP 200）
    // -----------------------------
    function version_exists(string $rid): bool {
        $url = DATASET_API_BASE . rawurlencode($rid);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOBODY => false,
            CURLOPT_HEADER => false,
        ]);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($status === 200 && $resp && strlen($resp) > 100);
    }

    // -----------------------------
    // 開始往上掃描
    // -----------------------------
    $cur = rid_next($lastRid);
    $found = [];
    $miss = 0;
    $tries = 0;

    while ($tries < PROBE_LIMIT) {
        $tries++;
        $fullRid = $prefix . $cur;
        if (version_exists($fullRid)) {
            $found[] = $cur;
            $miss = 0;
            $cur = rid_next($cur);
        } else {
            $miss++;
            if ($miss >= MISS_LIMIT) break;
            $cur = rid_next($cur);
        }
    }

    if ($found) {
        $pdo->beginTransaction();
        $now = date('Y-m-d H:i:s');
        foreach ($found as $rid) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO gov_nhi_versions (rid, resource_id_full, fetched_at, status) VALUES (?,?,?,?)");
            $stmt->execute([$rid, $prefix . $rid, $now, 'FOUND']);
        }
        $latest = end($found);
        $stmt2 = $pdo->prepare("UPDATE gov_nhi_state SET last_rid=?, last_checked_at=?, last_updated_at=? WHERE id=1");
        $stmt2->execute([$latest, $now, $now]);
        $pdo->commit();
        $response['ok'] = true;
        $response['found'] = $found;
        $response['updated'] = $latest;
    } else {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE gov_nhi_state SET last_checked_at=? WHERE id=1")->execute([$now]);
        $response['ok'] = true;
        $response['found'] = [];
        $response['updated'] = null;
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
