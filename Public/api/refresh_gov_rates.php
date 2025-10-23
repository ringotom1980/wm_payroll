<?php
// Public/api/refresh_gov_rates.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// -------------------------------
// 0) 授權檢查（需登入）
// -------------------------------
require __DIR__ . '/../../config/auth.php';
if (!is_logged_in()) {
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
// 2) 參數解析
// -------------------------------
$input = $_POST + $_GET;
$schemesParam = isset($input['schemes']) ? (array)$input['schemes'] : ['LABOR_INSURANCE', 'LABOR_PENSION', 'NHI'];
$schemes = array_values(array_intersect($schemesParam, ['LABOR_INSURANCE', 'LABOR_PENSION', 'NHI']));
if (!$schemes) $schemes = ['LABOR_INSURANCE', 'LABOR_PENSION', 'NHI'];

$force = isset($input['force']) ? (int)$input['force'] : 0;
$maxAgeHours = isset($input['max_age_hours']) ? max(1, (int)$input['max_age_hours']) : 720; // 預設 30 天

// -------------------------------
// 3) 常數：來源 URL（固定）
// -------------------------------
const URL_MOL_LI = 'https://apiservice.mol.gov.tw/OdService/download/A17000000J-020014-rpF'; // 勞工保險
const URL_MOL_LP = 'https://apiservice.mol.gov.tw/OdService/download/A17000000J-020031-8So'; // 勞工退休金
const NHI_RID_PREFIX = 'A21030000I-B1000A-'; // 健保 rId 前綴
const NHI_DATASET_ENDPOINT = 'https://info.nhi.gov.tw/api/iode0000s01/Dataset?rId=';

// -------------------------------
// 4) 小工具
// -------------------------------
function fetch_json(string $url, int $timeoutSec = 8): array
{
    $maxBytes = 5_000_000; // 5MB 防雷
    $buf = '';

    $ch = curl_init($url);
    if (!$ch) throw new RuntimeException("cURL init 失敗: $url");

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,       // 用 writefunction 控制大小
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5, // 連線 5 秒
        CURLOPT_TIMEOUT => 12,        // 總逾時
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,   // 避開 IPv6 路徑不穩
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'wm_payroll/1.0 (+wmpay.jinghong.pw)',
        CURLOPT_HTTPHEADER => ['Accept: application/json; charset=utf-8'],
        CURLOPT_WRITEFUNCTION => function ($ch, $str) use (&$buf, $maxBytes) {
            $buf .= $str;
            if (strlen($buf) > $maxBytes) return 0; // 超過上限即中止
            return strlen($str);
        },
    ]);

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($ok !== true) throw new RuntimeException("抓取失敗: $url ($err)");
    if ($code < 200 || $code >= 300) throw new RuntimeException("來源HTTP狀態: $code ($url)");

    // 去BOM
    if (strncmp($buf, "\xEF\xBB\xBF", 3) === 0) $buf = substr($buf, 3);

    $data = json_decode($buf, true);
    if (!is_array($data)) throw new RuntimeException("JSON 解析失敗: $url");
    return [$data, $buf];
}


/** 民國 yyyMMdd 或 yyy/MM/dd / yyy-MM-dd → 西元 YYYY-MM-DD；若已是西元也可解析 */
function to_gregorian_date(?string $s): ?string
{
    if (!$s) return null;
    $s = trim($s);
    // 數字版（可能是民國或西元）
    if (preg_match('/^\d{7,8}$/', $s)) {
        if (strlen($s) === 7) { // 民國 yyyMMdd（7 碼）
            $y = (int)substr($s, 0, 3);
            $m = (int)substr($s, 3, 2);
            $d = (int)substr($s, 5, 2);
            return sprintf('%04d-%02d-%02d', $y + 1911, $m, $d);
        } else { // 8 碼，可能西元 yyyymmdd
            $y = (int)substr($s, 0, 4);
            $m = (int)substr($s, 4, 2);
            $d = (int)substr($s, 6, 2);
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
    }
    // 含斜線或破折號：判斷民國或西元
    if (preg_match('/^(\d{3,4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $s, $m)) {
        $y = (int)$m[1];
        $mm = (int)$m[2];
        $dd = (int)$m[3];
        if ($y < 1911) $y += 1911;
        return sprintf('%04d-%02d-%02d', $y, $mm, $dd);
    }
    // 其他格式直接嘗試 strtotime
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** 解析工資範圍字串 → [min, max|null]；例：'1500以下','1501至3000','147901以上' */
function parse_wage_range(string $s): array
{
    $s = preg_replace('/[^\d至以上以下\-]/u', '', $s);
    if ($s === '') return [0, null];
    if (strpos($s, '以下') !== false) {
        $n = (int)preg_replace('/\D/', '', $s);
        return [0, $n];
    }
    if (strpos($s, '以上') !== false) {
        $n = (int)preg_replace('/\D/', '', $s);
        return [$n, null];
    }
    if (strpos($s, '至') !== false) {
        [$a, $b] = array_map('trim', explode('至', $s, 2));
        return [(int)preg_replace('/\D/', '', $a), (int)preg_replace('/\D/', '', $b)];
    }
    // 單一數字
    $n = (int)preg_replace('/\D/', '', $s);
    return [$n, $n];
}

/** 取欄位（多個候選鍵名） */
function pick(array $row, array $candidates): ?string
{
    foreach ($candidates as $k) {
        if (isset($row[$k]) && $row[$k] !== '') return (string)$row[$k];
    }
    return null;
}

/** 取得相同唯一鍵（scheme+effective_date+version_label_norm）的既有 snapshot_id，沒有就回 null */
function get_existing_snapshot_id(PDO $pdo, string $scheme, string $effectiveDate, ?string $versionLabel): ?int {
    $sql = "SELECT snapshot_id FROM gov_rate_snapshots
            WHERE scheme=? AND effective_date=? AND COALESCE(version_label,'') = COALESCE(?, '')
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$scheme, $effectiveDate, $versionLabel]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}


/** 取得或建立 gov_rate_sources.source_id（以 scheme+source_url 唯一） */
function get_or_create_source_id(PDO $pdo, string $scheme, string $sourceUrl, string $format = 'JSON', ?string $note = null): int
{
    $sel = $pdo->prepare("SELECT source_id FROM gov_rate_sources WHERE scheme=? AND source_url=? LIMIT 1");
    $sel->execute([$scheme, $sourceUrl]);
    $sid = $sel->fetchColumn();
    if ($sid) return (int)$sid;

    $ins = $pdo->prepare("INSERT INTO gov_rate_sources (scheme, source_url, format, note) VALUES (?,?,?,?)");
    $ins->execute([$scheme, $sourceUrl, $format, $note]);
    return (int)$pdo->lastInsertId();
}


/** 健保 rId 自動掃描：回傳 [rId, data, raw] */
function find_latest_nhi_dataset(int $maxProbe = 24, int $timeoutSecPerProbe = 5): array
{
    // 觀察：NHI 的 rId 後綴通常是 00A..00Z（年度批次），偶爾用數字
    // 我們優先探測 00Z → 00T（共 7 個），再探測 009 → 000（10 個），剩下補足到 $maxProbe
    $candidates = [];

    // 先 Z→T
    foreach (range('Z', 'T') as $ch) {
        $candidates[] = '00' . $ch;
    }
    // 再 009→000
    for ($i = 9; $i >= 0; $i--) $candidates[] = '00' . $i;
    // 若還不夠，補 S→A
    if (count($candidates) < $maxProbe) {
        foreach (range('S', 'A') as $ch) {
            $candidates[] = '00' . $ch;
            if (count($candidates) >= $maxProbe) break;
        }
    }

    foreach ($candidates as $suf) {
        $rId = NHI_RID_PREFIX . $suf;
        $url = NHI_DATASET_ENDPOINT . $rId;
        try {
            [$data, $raw] = fetch_json($url, $timeoutSecPerProbe);
            if (!is_array($data) || count($data) < 10) continue;
            $row0 = (array)$data[0];
            $keys = implode('|', array_keys($row0));
            if (!preg_match('/(級距|等級|月投保金額|投保金額|實際薪資月額)/u', $keys)) continue;
            return [$rId, $data, $raw];
        } catch (Throwable $e) {
            // 繼續試下一個
            continue;
        }
    }
    throw new RuntimeException('無法找到健保最新 rId（已嘗試 ' . count($candidates) . ' 個）');
}


/** 由健保資料推測生效日（嘗試找常見鍵名），若無則以當年 01-01 */
function infer_effective_date_from_nhi(array $data): string
{
    $candidates = ['生效日', '生效日期', '實施日期', '生效年月日', '適用日期'];
    foreach ($data as $row) {
        foreach ($candidates as $k) {
            if (!empty($row[$k])) {
                $d = to_gregorian_date((string)$row[$k]);
                if ($d) return $d;
            }
        }
    }
    return date('Y') . '-01-01';
}

/** 計算 sha256 */
function sha256(string $s): string
{
    return hash('sha256', $s);
}

/** 取得制度最新快照（for 節流/是否更新） */
function get_latest_snapshot(PDO $pdo, string $scheme): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM gov_rate_snapshots WHERE scheme=? AND status='ACTIVE' ORDER BY effective_date DESC, snapshot_id DESC LIMIT 1");
    $stmt->execute([$scheme]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** 新建 snapshot + levels（在交易內呼叫） */
function insert_snapshot_and_levels(
    PDO $pdo,
    string $scheme,
    ?string $versionLabel,
    string $effectiveDate,
    array $rows,
    string $rawJson,
    int $sourceId
): array {
    $checksum = sha256($rawJson);
    $nowCount = count($rows);

    // 1) UPSERT snapshots（首筆是 INSERT；之後遇到同唯一鍵就是 UPDATE）
    //    關鍵：最後一行把 snapshot_id = LAST_INSERT_ID(snapshot_id)
    //    這樣不論是 insert 還是 update，PDO->lastInsertId() 都會回傳那一筆 snapshot_id
    $stmt = $pdo->prepare("
        INSERT INTO gov_rate_snapshots
            (source_id, scheme, version_label, effective_date, record_count, status, fetched_at, raw_json, checksum_sha256)
        VALUES
            (?, ?, ?, ?, ?, 'ACTIVE', NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE
            source_id = VALUES(source_id),
            record_count = VALUES(record_count),
            status = 'ACTIVE',
            fetched_at = NOW(),
            raw_json = VALUES(raw_json),
            checksum_sha256 = VALUES(checksum_sha256),
            snapshot_id = LAST_INSERT_ID(snapshot_id)
    ");
    $stmt->execute([$sourceId, $scheme, $versionLabel, $effectiveDate, $nowCount, $rawJson, $checksum]);

    // 取得該筆 snapshot_id（insert 或 update 都可拿到）
    $snapshotId = (int)$pdo->lastInsertId();

    // 2) 重建 levels：先清除舊的，再批量寫入
    $pdo->prepare("DELETE FROM gov_rate_levels WHERE snapshot_id=?")->execute([$snapshotId]);

    $ins = $pdo->prepare("
        INSERT INTO gov_rate_levels (snapshot_id, level_no, wage_min, wage_max, base_amount, category)
        VALUES (?,?,?,?,?,?)
    ");
    $count = 0;
    foreach ($rows as $r) {
        $ins->execute([
            $snapshotId,
            (int)$r['level_no'],
            (int)$r['wage_min'],
            $r['wage_max'] === null ? null : (int)$r['wage_max'],
            (int)$r['base_amount'],
            isset($r['category']) ? (string)$r['category'] : null
        ]);
        $count++;
    }

    // 3) 將同 scheme 其他 ACTIVE 標成 ARCHIVED（保留你原本的語意）
    $pdo->prepare("
        UPDATE gov_rate_snapshots
           SET status='ARCHIVED'
         WHERE scheme=? AND snapshot_id<>? AND status='ACTIVE'
    ")->execute([$scheme, $snapshotId]);

    // 4) 回傳動作描述（created / updated）供前端訊息用
    //    判斷方式：看這次是否觸發 duplicate（用受影響行數無法準確分辨；改用是否已存在判斷更穩）
    $existed = get_existing_snapshot_id($pdo, $scheme, $effectiveDate, $versionLabel) === $snapshotId;
    $action = $existed ? 'updated' : 'created'; // 若想更嚴謹，可在呼叫前先查

    return ['snapshot_id' => $snapshotId, 'record_count' => $count, 'checksum' => $checksum, 'action' => $action];
}


// -------------------------------
// 5) 制度別撈取與解析實作
// -------------------------------
function refresh_labor_insurance(PDO $pdo, int $force, int $maxAgeHours): array
{
    $latest = get_latest_snapshot($pdo, 'LABOR_INSURANCE');
    if (!$force && $latest && (time() - strtotime($latest['fetched_at'])) < ($maxAgeHours * 3600)) {
        return ['scheme' => 'LABOR_INSURANCE', 'action' => 'skipped', 'reason' => 'fresh', 'snapshot_id' => $latest['snapshot_id'], 'record_count' => $latest['record_count'], 'effective_date' => $latest['effective_date'], 'version_label' => $latest['version_label']];
    }

    [$data, $raw] = fetch_json(URL_MOL_LI);
    if (!is_array($data) || !count($data)) throw new RuntimeException('勞保 JSON 無資料');

    // 解析
    $rows = [];
    $effDate = null;
    foreach ($data as $row) {
        $row = (array)$row;
        $level = pick($row, ['等級', '級距', '等第']);
        $range = pick($row, ['實際工資', '實際工資/執行業務所得', '實際工資(元)']);
        $base  = pick($row, ['月投保薪資', '月提繳工資金額/月提繳執行業務所得金額', '月投保金額', '月投保(元)']);
        $cat   = pick($row, ['被保險人身分類別', '身分類別', '被保險人類別']);

        if ($effDate === null) {
            $effCandidate = pick($row, ['生效日', '生效日期', '實施日期']);
            $effDate = to_gregorian_date($effCandidate) ?: $effDate;
        }

        if ($level === null || $range === null || $base === null) continue;
        [$min, $max] = parse_wage_range($range);
        $rows[] = [
            'level_no'    => (int)preg_replace('/\D/', '', $level),
            'wage_min'    => $min,
            'wage_max'    => $max,
            'base_amount' => (int)preg_replace('/\D/', '', $base),
            'category'    => $cat ?: '一般被保險人'
        ];
    }
    if ($effDate === null) $effDate = date('Y') . '-01-01';
    // 若與現有 snapshot checksum 一致且未過期 → 跳過
    $checksum = sha256($raw);
    if (!$force && $latest && $latest['checksum_sha256'] === $checksum) {
        return ['scheme' => 'LABOR_INSURANCE', 'action' => 'skipped', 'reason' => 'same-checksum', 'snapshot_id' => $latest['snapshot_id'], 'record_count' => $latest['record_count'], 'effective_date' => $latest['effective_date'], 'version_label' => $latest['version_label']];
    }
    $sourceId = get_or_create_source_id($pdo, 'LABOR_INSURANCE', URL_MOL_LI, 'JSON', '勞保級距 (OdService JSON)');

    // 寫 DB（交易）
    $pdo->beginTransaction();
    try {
        $ret = insert_snapshot_and_levels($pdo, 'LABOR_INSURANCE', null, $effDate, $rows, $raw, $sourceId);

        $pdo->commit();
        return array_merge(['scheme' => 'LABOR_INSURANCE'], $ret, ['effective_date' => $effDate, 'version_label' => null]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function refresh_labor_pension(PDO $pdo, int $force, int $maxAgeHours): array
{
    $latest = get_latest_snapshot($pdo, 'LABOR_PENSION');
    if (!$force && $latest && (time() - strtotime($latest['fetched_at'])) < ($maxAgeHours * 3600)) {
        return ['scheme' => 'LABOR_PENSION', 'action' => 'skipped', 'reason' => 'fresh', 'snapshot_id' => $latest['snapshot_id'], 'record_count' => $latest['record_count'], 'effective_date' => $latest['effective_date'], 'version_label' => $latest['version_label']];
    }

    [$data, $raw] = fetch_json(URL_MOL_LP);
    if (!is_array($data) || !count($data)) throw new RuntimeException('勞退 JSON 無資料');

    $rows = [];
    $effDate = null;
    foreach ($data as $row) {
        $row = (array)$row;
        $level = pick($row, ['等級', '級距', '等第']);
        $range = pick($row, ['實際工資/執行業務所得', '實際工資', '實際工資(元)']);
        $base  = pick($row, ['月提繳工資金額/月提繳執行業務所得金額', '月提繳工資金額', '月投保金額', '月投保(元)']);

        if ($effDate === null) {
            $effCandidate = pick($row, ['生效日', '生效日期', '實施日期']);
            $effDate = to_gregorian_date($effCandidate) ?: $effDate;
        }

        if ($level === null || $range === null || $base === null) continue;
        [$min, $max] = parse_wage_range($range);
        $rows[] = [
            'level_no'    => (int)preg_replace('/\D/', '', $level),
            'wage_min'    => $min,
            'wage_max'    => $max,
            'base_amount' => (int)preg_replace('/\D/', '', $base),
            'category'    => null
        ];
    }
    if ($effDate === null) $effDate = date('Y') . '-01-01';

    $checksum = sha256($raw);
    if (!$force && $latest && $latest['checksum_sha256'] === $checksum) {
        return ['scheme' => 'LABOR_PENSION', 'action' => 'skipped', 'reason' => 'same-checksum', 'snapshot_id' => $latest['snapshot_id'], 'record_count' => $latest['record_count'], 'effective_date' => $latest['effective_date'], 'version_label' => $latest['version_label']];
    }
    $sourceId = get_or_create_source_id($pdo, 'LABOR_PENSION', URL_MOL_LP, 'JSON', '勞退級距 (OdService JSON)');
    $pdo->beginTransaction();
    try {
        $ret = insert_snapshot_and_levels($pdo, 'LABOR_PENSION', null, $effDate, $rows, $raw, $sourceId);
        $pdo->commit();
        return array_merge(['scheme' => 'LABOR_PENSION'], $ret, ['effective_date' => $effDate, 'version_label' => null]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function refresh_nhi(PDO $pdo, int $force, int $maxAgeHours): array
{
    $latest = get_latest_snapshot($pdo, 'NHI');
    if (!$force && $latest && (time() - strtotime($latest['fetched_at'])) < ($maxAgeHours * 3600)) {
        return ['scheme' => 'NHI', 'action' => 'skipped', 'reason' => 'fresh', 'snapshot_id' => $latest['snapshot_id'], 'record_count' => $latest['record_count'], 'effective_date' => $latest['effective_date'], 'version_label' => $latest['version_label']];
    }

    // 動態找 rId
    [$rId, $data, $raw] = find_latest_nhi_dataset();
    $effDate = infer_effective_date_from_nhi($data);

    // 解析健保欄位：嘗試多種鍵名
    $rows = [];
    foreach ($data as $row) {
        $row = (array)$row;
        $level = pick($row, ['等級', '級距', '分級']);
        $range = pick($row, ['實際薪資月額', '實際薪資', '工資', '薪資(月)']);
        $base  = pick($row, ['月投保金額', '投保金額', '月投保(元)']);

        if ($level === null || $base === null) continue;

        // 健保的「實際薪資月額」常見格式：'1501-3000'、'1500以下'、'147901以上'
        [$min, $max] = $range ? parse_wage_range($range) : [0, null];
        $rows[] = [
            'level_no'    => (int)preg_replace('/\D/', '', $level),
            'wage_min'    => $min,
            'wage_max'    => $max,
            'base_amount' => (int)preg_replace('/\D/', '', $base),
            'category'    => null
        ];
    }

    $checksum = sha256($raw);
    if (!$force && $latest && ($latest['version_label'] === $rId) && $latest['checksum_sha256'] === $checksum) {
        return ['scheme' => 'NHI', 'action' => 'skipped', 'reason' => 'same-version-checksum', 'snapshot_id' => $latest['snapshot_id'], 'record_count' => $latest['record_count'], 'effective_date' => $latest['effective_date'], 'version_label' => $latest['version_label']];
    }
    $sourceUrl = NHI_DATASET_ENDPOINT . $rId;
    $sourceId  = get_or_create_source_id($pdo, 'NHI', $sourceUrl, 'JSON', '健保資料集（動態 rId）');
    $pdo->beginTransaction();
    try {
        $ret = insert_snapshot_and_levels($pdo, 'NHI', $rId, $effDate, $rows, $raw, $sourceId);
        $pdo->commit();
        return array_merge(['scheme' => 'NHI'], $ret, ['effective_date' => $effDate, 'version_label' => $rId]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// -------------------------------
// 6) 主流程
// -------------------------------
$results = [];
$errors  = [];

foreach ($schemes as $sc) {
    try {
        if ($sc === 'LABOR_INSURANCE')    $results[] = refresh_labor_insurance($pdo, $force, $maxAgeHours);
        elseif ($sc === 'LABOR_PENSION')  $results[] = refresh_labor_pension($pdo, $force, $maxAgeHours);
        elseif ($sc === 'NHI')            $results[] = refresh_nhi($pdo, $force, $maxAgeHours);
    } catch (Throwable $e) {
        $errors[] = ['scheme' => $sc, 'error' => $e->getMessage()];
    }
}

echo json_encode(['ok' => empty($errors), 'updated' => $results, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
