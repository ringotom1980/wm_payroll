<?php
// Public/api/opendata/gov_nhi.php
// 目的：下載健保資料集（指定或最新版本）、解析為欄位，寫入 gov_nhi；最後把該 rid 標記為 IMPORTED
// 相依：config/db.php 會提供 $pdo；gov_nhi_state / gov_nhi_versions；gov_nhi 主表（你已提供）
// PHP 7.2 相容

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php'; // 需建立 $pdo (PDO)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

const DATASET_API_BASE = 'https://info.nhi.gov.tw/api/iode0000s01/Dataset?rId=';

// -----------------------------
// 小工具：讀目前 prefix 與 last_rid
// -----------------------------
function current_state(PDO $pdo): array {
    $row = $pdo->query("SELECT prefix, last_rid FROM gov_nhi_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('gov_nhi_state 尚未初始化');
    }
    if (!$row['prefix']) {
        throw new RuntimeException('gov_nhi_state.prefix 未設定');
    }
    return $row;
}

// -----------------------------
// 小工具：HTTP 下載
// -----------------------------
function http_get(string $url, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => false,
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err  = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("cURL error ($errno): $err");
    }
    $headersRaw = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);

    // 取 Content-Type
    $contentType = '';
    foreach (explode("\r\n", $headersRaw) as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $contentType = trim(substr($h, strlen('Content-Type:')));
            break;
        }
    }

    return ['status' => $status, 'content_type' => $contentType, 'body' => $body];
}

// -----------------------------
// 內容判定：JSON or CSV
// -----------------------------
function is_json_payload(string $body): bool {
    $trim = ltrim($body);
    if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) return false;
    json_decode($body, true);
    return (json_last_error() === JSON_ERROR_NONE);
}

// -----------------------------
// CSV 解析（簡易 parser：支援引號、逗點；假設 UTF-8）
// -----------------------------
function parse_csv_to_rows(string $csv): array {
    $rows = [];
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    $header = null;
    while (($cols = fgetcsv($fp)) !== false) {
        if ($cols === [null] || count($cols) === 0) continue;
        if ($header === null) {
            $header = $cols;
            continue;
        }
        $row = [];
        foreach ($header as $i => $colName) {
            $row[$colName] = $cols[$i] ?? null;
        }
        $rows[] = $row;
    }
    fclose($fp);
    return $rows;
}

// -----------------------------
// JSON 解析 → rows
// 支援常見格式：直接陣列；或 {data: [...]}；或 {result: {..., records: [...]}} 等
// -----------------------------
function parse_json_to_rows(array $data): array {
    // 直接是 array of rows
    if (isset($data[0]) && is_array($data[0])) {
        return $data;
    }
    // 常見包裝
    if (isset($data['data']) && is_array($data['data'])) {
        return $data['data'];
    }
    if (isset($data['result']['records']) && is_array($data['result']['records'])) {
        return $data['result']['records'];
    }
    if (isset($data['records']) && is_array($data['records'])) {
        return $data['records'];
    }
    // 嘗試找第一個 array
    foreach ($data as $v) {
        if (is_array($v) && isset($v[0]) && is_array($v[0])) return $v;
    }
    return [];
}

// -----------------------------
// 欄位對應：嘗試從多種欄名/格式抓對應資訊
// - level_no：可能叫 等級/級距/序號/No 等
// - wage_min / wage_max：可能叫 自/至/起/迄/下限/上限
// - base_amount：投保金額/月投保金額
// - group_code：類別代號/對象別代碼
// - effective_date：生效/實施/發布 日期（YYYY-MM-DD 或 民國年）
// -----------------------------
function normalize_row(array $r): array {
    // 先把鍵全部 trim、統一
    $norm = [];
    foreach ($r as $k => $v) {
        $kk = trim((string)$k);
        $norm[$kk] = (is_string($v)) ? trim($v) : $v;
    }

    $get = function(array $candidates) use ($norm) {
        foreach ($candidates as $c) {
            foreach ($norm as $k => $v) {
                if (mb_strtolower($k) === mb_strtolower($c)) return $v;
            }
        }
        // 也嘗試模糊包含
        foreach ($candidates as $c) {
            foreach ($norm as $k => $v) {
                if (mb_stripos($k, $c) !== false) return $v;
            }
        }
        return null;
    };

    // level_no
    $level = $get(['等級', '級距', '級次', '序號', 'level', 'no', '項次', '項目序號']);
    if ($level === null) {
        // 若有「級距起迄」，可以用遞增在外面處理；這裡先留 null
    } else {
        // 清掉非數字
        $level = preg_replace('/[^\d]/', '', (string)$level);
        $level = ($level === '') ? null : (int)$level;
    }

    // wage_min / wage_max
    $min = $get(['自', '起', '下限', '最低', '金額自', '級距自', '區間起', 'wage_min', 'min']);
    $max = $get(['至', '迄', '上限', '最高', '金額至', '級距至', '區間迄', 'wage_max', 'max']);

    // base_amount（若資料集只有單一金額欄）
    $base = $get(['投保金額', '月投保金額', '基數', '保險金額', '保險投保金額', 'base_amount', '金額']);

    // group_code
    $group = $get(['類別代號', '身分類別代號', '投保對象別代碼', 'group_code', '類別']);

    // effective_date（盡量抓 YYYY-MM-DD；若是民國年，自行轉）
    $eff = $get(['生效日期', '實施日期', '發布日期', 'effective_date', '起始日期', '適用日期']);
    $eff = normalize_date($eff);

    // 金額轉 decimal
    $toDecimal = function($x) {
        if ($x === null || $x === '') return null;
        // 去除千分位與非數字符號
        $v = str_replace([',', '，'], '', (string)$x);
        $v = preg_replace('/[^\d.\-]/', '', $v);
        return ($v === '' || $v === '-' ) ? null : (float)$v;
    };

    $wmin = $toDecimal($min);
    $wmax = $toDecimal($max);
    $baseAmt = $toDecimal($base);

    return [
        'level_no'      => $level,
        'wage_min'      => $wmin,
        'wage_max'      => $wmax,
        'base_amount'   => $baseAmt,
        'group_code'    => ($group === null ? null : (string)$group),
        'effective_date'=> $eff,
    ];
}

// 民國年或常見日期轉 YYYY-MM-DD
function normalize_date($s): ?string {
    if (!$s) return null;
    $s = trim((string)$s);
    if ($s === '') return null;

    // 已經像 2024-12-26 / 2024/12/26
    if (preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}$/', $s)) {
        $s = str_replace('/', '-', $s);
        $t = strtotime($s);
        return $t ? date('Y-m-d', $t) : null;
    }

    // 民國年：113/12/26 or 113-12-26
    if (preg_match('/^(\d{2,3})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $s, $m)) {
        $y = (int)$m[1] + 1911;
        $mm = (int)$m[2];
        $dd = (int)$m[3];
        return sprintf('%04d-%02d-%02d', $y, $mm, $dd);
    }

    // 一般中文日期：113年12月26日
    if (preg_match('/^(\d{2,3})年(\d{1,2})月(\d{1,2})日$/u', $s, $m)) {
        $y = (int)$m[1] + 1911;
        $mm = (int)$m[2];
        $dd = (int)$m[3];
        return sprintf('%04d-%02d-%02d', $y, $mm, $dd);
    }

    // 單純年/月（最後回傳月初）
    if (preg_match('/^(\d{4})[-\/](\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]);
    }
    if (preg_match('/^(\d{2,3})年(\d{1,2})月$/u', $s, $m)) {
        $y = (int)$m[1] + 1911;
        return sprintf('%04d-%02d-01', $y, (int)$m[2]);
    }

    // 無法判讀就丟 null
    return null;
}

// -----------------------------
// 主流程
// -----------------------------
$res = ['ok'=>false, 'rid'=>null, 'resource_id'=>null, 'imported_rows'=>0, 'skipped_rows'=>0, 'msg'=>null, 'error'=>null];

try {
    // 取得 rid：優先 querystring ?rid=（三碼），否則取 state.last_rid
    $queryRid = isset($_GET['rid']) ? trim((string)$_GET['rid']) : null;
    $state = current_state($pdo);
    $prefix = $state['prefix'];
    $rid    = $queryRid ?: $state['last_rid'];

    if (!$rid) {
        throw new RuntimeException('沒有可用的 rid，請先執行版本掃描（gov_nhi_versions.php）或提供 ?rid=');
    }

    $resourceIdFull = $prefix . $rid;
    $url = DATASET_API_BASE . rawurlencode($resourceIdFull);

    $http = http_get($url, 45);
    if ($http['status'] !== 200 || !$http['body']) {
        throw new RuntimeException("下載失敗，HTTP {$http['status']}");
    }

    // 解析成 rows
    $rows = [];
    if (is_json_payload($http['body'])) {
        $data = json_decode($http['body'], true);
        $rows = parse_json_to_rows($data);
    } else {
        // 可能是 CSV
        $rows = parse_csv_to_rows($http['body']);
    }

    if (!$rows || !is_array($rows) || count($rows) === 0) {
        throw new RuntimeException('資料內容為空或無法解析成列');
    }

    // 先刪除同 resource_id 的舊資料（確保 idempotent）
    $delStmt = $pdo->prepare("DELETE FROM gov_nhi WHERE resource_id = ?");
    $pdo->beginTransaction();
    $delStmt->execute([$resourceIdFull]);

    // 準備 insert
    $ins = $pdo->prepare("
        INSERT INTO gov_nhi
        (level_no, wage_min, wage_max, base_amount, group_code, resource_id, effective_date)
        VALUES (?,?,?,?,?,?,?)
    ");

    $imported = 0; $skipped = 0;
    $autoLevel = 0;

    foreach ($rows as $r) {
        if (!is_array($r)) { $skipped++; continue; }

        $n = normalize_row($r);

        // 若 level_no 缺，給遞增序號
        if ($n['level_no'] === null) {
            $autoLevel++;
            $n['level_no'] = $autoLevel;
        } else {
            // 同時更新 autoLevel 以免重覆
            $autoLevel = max($autoLevel, (int)$n['level_no']);
        }

        // 只要最低限度：base_amount 或 (wage_min/wage_max 任一) 有值才寫入，避免空行
        $hasAnyAmount = ($n['base_amount'] !== null) || ($n['wage_min'] !== null) || ($n['wage_max'] !== null);
        if (!$hasAnyAmount) { $skipped++; continue; }

        $ins->execute([
            $n['level_no'],
            $n['wage_min'],
            $n['wage_max'],
            $n['base_amount'],
            $n['group_code'],
            $resourceIdFull,
            $n['effective_date'],
        ]);
        $imported++;
    }

    // 將版本標記為 IMPORTED（若已存在就更新）
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO gov_nhi_versions (rid, resource_id_full, fetched_at, status)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE status=VALUES(status), fetched_at=VALUES(fetched_at)
    ")->execute([$rid, $resourceIdFull, $now, 'IMPORTED']);

    $pdo->commit();

    $res['ok'] = true;
    $res['rid'] = $rid;
    $res['resource_id'] = $resourceIdFull;
    $res['imported_rows'] = $imported;
    $res['skipped_rows'] = $skipped;
    $res['msg'] = "gov_nhi 已寫入（IMPORTED=$imported, SKIPPED=$skipped）";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $res['error'] = $e->getMessage();
}

echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
