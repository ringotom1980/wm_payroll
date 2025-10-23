<?php
// Public/api/refresh_gov_rates.php
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
// 2) 參數解析
// -------------------------------
$input = $_POST + $_GET;
$schemesParam = isset($input['schemes']) ? (array)$input['schemes'] : ['LABOR_INSURANCE', 'LABOR_PENSION', 'NHI'];
$schemes = array_values(array_intersect($schemesParam, ['LABOR_INSURANCE','LABOR_PENSION','NHI']));
if (!$schemes) $schemes = ['LABOR_INSURANCE','LABOR_PENSION','NHI'];

$force = isset($input['force']) ? (int)$input['force'] : 0;
$maxAgeHours = isset($input['max_age_hours']) ? max(1, (int)$input['max_age_hours']) : 720; // 預設 30 天

// -------------------------------
// 3) 常數：來源 URL（固定）
// -------------------------------
const URL_MOL_LI = 'https://apiservice.mol.gov.tw/OdService/download/A17000000J-020030-3fD'; // 勞工保險
const URL_MOL_LP = 'https://apiservice.mol.gov.tw/OdService/download/A17000000J-020031-8So'; // 勞工退休金
const NHI_RID_PREFIX = 'A21030000I-B1000A-'; // 健保 rId 前綴
const NHI_DATASET_ENDPOINT = 'https://info.nhi.gov.tw/api/iode000s01/Dataset?rId=';

// -------------------------------
// 4) 小工具
// -------------------------------
function fetch_json(string $url, int $timeoutSec = 20): array {
    $ctx = stream_context_create(['http' => ['timeout' => $timeoutSec, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException("抓取失敗 $url");
    // 去除 UTF-8 BOM
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("JSON 解析失敗 $url");
    return [$data, $raw];
}

/** 民國 yyyMMdd 或 yyy/MM/dd / yyy-MM-dd → 西元 YYYY-MM-DD；若已是西元也可解析 */
function to_gregorian_date(?string $s): ?string {
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
        $y = (int)$m[1]; $mm = (int)$m[2]; $dd = (int)$m[3];
        if ($y < 1911) $y += 1911;
        return sprintf('%04d-%02d-%02d', $y, $mm, $dd);
    }
    // 其他格式直接嘗試 strtotime
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** 解析工資範圍字串 → [min, max|null]；例：'1500以下','1501至3000','147901以上' */
function parse_wage_range(string $s): array {
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
        return [(int)preg_replace('/\D/','',$a), (int)preg_replace('/\D/','',$b)];
    }
    // 單一數字
    $n = (int)preg_replace('/\D/','',$s);
    return [$n, $n];
}

/** 取欄位（多個候選鍵名） */
function pick(array $row, array $candidates): ?string {
    foreach ($candidates as $k) {
        if (isset($row[$k]) && $row[$k] !== '') return (string)$row[$k];
    }
    return null;
}

/** 健保 rId 自動掃描：回傳 [rId, data, raw] */
function find_latest_nhi_dataset(): array {
    // 先嘗試 00Z → 000..199 的順序（從新到舊）
    $suffixes = [];
    // A..Z
    for ($c = ord('Z'); $c >= ord('A'); $c--) $suffixes[] = '00' . chr($c);
    // 000..199
    for ($i = 199; $i >= 0; $i--) $suffixes[] = str_pad((string)$i, 3, '0', STR_PAD_LEFT);

    foreach ($suffixes as $suf) {
        $rId = NHI_RID_PREFIX . $suf;
        $url = NHI_DATASET_ENDPOINT . $rId;
        try {
            [$data, $raw] = fetch_json($url, 20);
            if (!is_array($data) || count($data) < 10) continue; // 少於 10 筆太可疑
            // 粗略驗證欄位：需包含任何一種健保常見鍵名
            $row0 = (array)$data[0];
            $keys = implode('|', array_keys($row0));
            if (!preg_match('/(級距|等級|月投保金額|投保金額|實際薪資月額)/u', $keys)) continue;
            return [$rId, $data, $raw];
        } catch (Throwable $e) {
            continue;
        }
    }
    throw new RuntimeException('無法找到健保最新 rId');
}

/** 由健保資料推測生效日（嘗試找常見鍵名），若無則以當年 01-01 */
function infer_effective_date_from_nhi(array $data): string {
    $candidates = ['生效日','生效日期','實施日期','生效年月日','適用日期'];
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
function sha256(string $s): string {
    return hash('sha256', $s);
}

/** 取得制度最新快照（for 節流/是否更新） */
function get_latest_snapshot(PDO $pdo, string $scheme): ?array {
    $stmt = $pdo->prepare("SELECT * FROM gov_rate_snapshots WHERE scheme=? AND status='ACTIVE' ORDER BY effective_date DESC, snapshot_id DESC LIMIT 1");
    $stmt->execute([$scheme]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** 新建 snapshot + levels（在交易內呼叫） */
function insert_snapshot_and_levels(PDO $pdo, string $scheme, ?string $versionLabel, string $effectiveDate, array $rows, string $rawJson): array {
    // 1) snapshot
    $stmt = $pdo->prepare("INSERT INTO gov_rate_snapshots (source_id, scheme, version_label, effective_date, record_count, status, fetched_at, raw_json, checksum_sha256) VALUES (NULL, ?, ?, ?, ?, 'ACTIVE', NOW(), ?, ?)");
    $checksum = sha256($rawJson);
    $stmt->execute([$scheme, $versionLabel, $effectiveDate, count($rows), $rawJson, $checksum]);
    $snapshotId = (int)$pdo->lastInsertId();

    // 2) levels
    $ins = $pdo->prepare("INSERT INTO gov_rate_levels (snapshot_id, level_no, wage_min, wage_max, base_amount, category) VALUES (?,?,?,?,?,?)");

    $count = 0;
    foreach ($rows as $r) {
        $levelNo = (int)$r['level_no'];
        $wmin = (int)$r['wage_min'];
        $wmax = $r['wage_max'] === null ? null : (int)$r['wage_max'];
        $base = (int)$r['base_amount'];
        $cat  = isset($r['category']) ? (string)$r['category'] : null;
        $ins->execute([$snapshotId, $levelNo, $wmin, $wmax, $base, $cat]);
        $count++;
    }

    // 3) 將舊版標成 ARCHIVED
    $pdo->prepare("UPDATE gov_rate_snapshots SET status='ARCHIVED' WHERE scheme=? AND snapshot_id<>? AND status='ACTIVE'")
        ->execute([$scheme, $snapshotId]);

    return ['snapshot_id' => $snapshotId, 'record_count' => $count, 'checksum' => $checksum];
}

// -------------------------------
// 5) 制度別撈取與解析實作
// -------------------------------
function refresh_labor_insurance(PDO $pdo, int $force, int $maxAgeHours): array {
    $latest = get_latest_snapshot($pdo, 'LABOR_INSURANCE');
    if (!$force && $latest && (time() - strtotime($latest['fetched_at'])) < ($maxAgeHours * 3600)) {
        return ['scheme'=>'LABOR_INSURANCE','action'=>'skipped','reason'=>'fresh','snapshot_id'=>$latest['snapshot_id'],'record_count'=>$latest['record_count'],'effective_date'=>$latest['effective_date'],'version_label'=>$latest['version_label']];
    }

    [$data, $raw] = fetch_json(URL_MOL_LI);
    if (!is_array($data) || !count($data)) throw new RuntimeException('勞保 JSON 無資料');

    // 解析
    $rows = [];
    $effDate = null;
    foreach ($data as $row) {
        $row = (array)$row;
        $level = pick($row, ['等級','級距','等第']);
        $range = pick($row, ['實際工資','實際工資/執行業務所得','實際工資(元)']);
        $base  = pick($row, ['月投保薪資','月提繳工資金額/月提繳執行業務所得金額','月投保金額','月投保(元)']);
        $cat   = pick($row, ['被保險人身分類別','身分類別','被保險人類別']);

        if ($effDate === null) {
            $effCandidate = pick($row, ['生效日','生效日期','實施日期']);
            $effDate = to_gregorian_date($effCandidate) ?: $effDate;
        }

        if ($level === null || $range === null || $base === null) continue;
        [$min,$max] = parse_wage_range($range);
        $rows[] = [
            'level_no'    => (int)preg_replace('/\D/','',$level),
            'wage_min'    => $min,
            'wage_max'    => $max,
            'base_amount' => (int)preg_replace('/\D/','',$base),
            'category'    => $cat ?: '一般被保險人'
        ];
    }
    if ($effDate === null) $effDate = date('Y').'-01-01';
    // 若與現有 snapshot checksum 一致且未過期 → 跳過
    $checksum = sha256($raw);
    if (!$force && $latest && $latest['checksum_sha256'] === $checksum) {
        return ['scheme'=>'LABOR_INSURANCE','action'=>'skipped','reason'=>'same-checksum','snapshot_id'=>$latest['snapshot_id'],'record_count'=>$latest['record_count'],'effective_date'=>$latest['effective_date'],'version_label'=>$latest['version_label']];
    }

    // 寫 DB（交易）
    $pdo->beginTransaction();
    try {
        $ret = insert_snapshot_and_levels($pdo, 'LABOR_INSURANCE', null, $effDate, $rows, $raw);
        $pdo->commit();
        return ['scheme'=>'LABOR_INSURANCE','action'=>'created'] + $ret + ['effective_date'=>$effDate,'version_label'=>null];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function refresh_labor_pension(PDO $pdo, int $force, int $maxAgeHours): array {
    $latest = get_latest_snapshot($pdo, 'LABOR_PENSION');
    if (!$force && $latest && (time() - strtotime($latest['fetched_at'])) < ($maxAgeHours * 3600)) {
        return ['scheme'=>'LABOR_PENSION','action'=>'skipped','reason'=>'fresh','snapshot_id'=>$latest['snapshot_id'],'record_count'=>$latest['record_count'],'effective_date'=>$latest['effective_date'],'version_label'=>$latest['version_label']];
    }

    [$data, $raw] = fetch_json(URL_MOL_LP);
    if (!is_array($data) || !count($data)) throw new RuntimeException('勞退 JSON 無資料');

    $rows = [];
    $effDate = null;
    foreach ($data as $row) {
        $row = (array)$row;
        $level = pick($row, ['等級','級距','等第']);
        $range = pick($row, ['實際工資/執行業務所得','實際工資','實際工資(元)']);
        $base  = pick($row, ['月提繳工資金額/月提繳執行業務所得金額','月提繳工資金額','月投保金額','月投保(元)']);

        if ($effDate === null) {
            $effCandidate = pick($row, ['生效日','生效日期','實施日期']);
            $effDate = to_gregorian_date($effCandidate) ?: $effDate;
        }

        if ($level === null || $range === null || $base === null) continue;
        [$min,$max] = parse_wage_range($range);
        $rows[] = [
            'level_no'    => (int)preg_replace('/\D/','',$level),
            'wage_min'    => $min,
            'wage_max'    => $max,
            'base_amount' => (int)preg_replace('/\D/','',$base),
            'category'    => null
        ];
    }
    if ($effDate === null) $effDate = date('Y').'-01-01';

    $checksum = sha256($raw);
    if (!$force && $latest && $latest['checksum_sha256'] === $checksum) {
        return ['scheme'=>'LABOR_PENSION','action'=>'skipped','reason'=>'same-checksum','snapshot_id'=>$latest['snapshot_id'],'record_count'=>$latest['record_count'],'effective_date'=>$latest['effective_date'],'version_label'=>$latest['version_label']];
    }

    $pdo->beginTransaction();
    try {
        $ret = insert_snapshot_and_levels($pdo, 'LABOR_PENSION', null, $effDate, $rows, $raw);
        $pdo->commit();
        return ['scheme'=>'LABOR_PENSION','action'=>'created'] + $ret + ['effective_date'=>$effDate,'version_label'=>null];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function refresh_nhi(PDO $pdo, int $force, int $maxAgeHours): array {
    $latest = get_latest_snapshot($pdo, 'NHI');
    if (!$force && $latest && (time() - strtotime($latest['fetched_at'])) < ($maxAgeHours * 3600)) {
        return ['scheme'=>'NHI','action'=>'skipped','reason'=>'fresh','snapshot_id'=>$latest['snapshot_id'],'record_count'=>$latest['record_count'],'effective_date'=>$latest['effective_date'],'version_label'=>$latest['version_label']];
    }

    // 動態找 rId
    [$rId, $data, $raw] = find_latest_nhi_dataset();
    $effDate = infer_effective_date_from_nhi($data);

    // 解析健保欄位：嘗試多種鍵名
    $rows = [];
    foreach ($data as $row) {
        $row = (array)$row;
        $level = pick($row, ['等級','級距','分級']);
        $range = pick($row, ['實際薪資月額','實際薪資','工資','薪資(月)']);
        $base  = pick($row, ['月投保金額','投保金額','月投保(元)']);

        if ($level === null || $base === null) continue;

        // 健保的「實際薪資月額」常見格式：'1501-3000'、'1500以下'、'147901以上'
        [$min,$max] = $range ? parse_wage_range($range) : [0, null];
        $rows[] = [
            'level_no'    => (int)preg_replace('/\D/','',$level),
            'wage_min'    => $min,
            'wage_max'    => $max,
            'base_amount' => (int)preg_replace('/\D/','',$base),
            'category'    => null
        ];
    }

    $checksum = sha256($raw);
    if (!$force && $latest && ($latest['version_label'] === $rId) && $latest['checksum_sha256'] === $checksum) {
        return ['scheme'=>'NHI','action'=>'skipped','reason'=>'same-version-checksum','snapshot_id'=>$latest['snapshot_id'],'record_count'=>$latest['record_count'],'effective_date'=>$latest['effective_date'],'version_label'=>$latest['version_label']];
    }

    $pdo->beginTransaction();
    try {
        $ret = insert_snapshot_and_levels($pdo, 'NHI', $rId, $effDate, $rows, $raw);
        $pdo->commit();
        return ['scheme'=>'NHI','action'=>'created'] + $ret + ['effective_date'=>$effDate,'version_label'=>$rId];
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
        $errors[] = ['scheme'=>$sc, 'error'=>$e->getMessage()];
    }
}

echo json_encode(['ok' => empty($errors), 'updated' => $results, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
