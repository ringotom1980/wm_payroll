<?php
// Public/api/opendata/gov_nhi.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php'; // 應提供 $pdo (PDO)

// 如果你想暫時用「固定檔名」測試本地 CSV（例如你剛上傳的 A21030000I-B1000A-00F.csv）
// 可在 URL 加 ?local=1 走本地檔案（正式跑請移除這段或把 local=1 拿掉）
$USE_LOCAL_FILE_FOR_TEST = isset($_GET['local']) && $_GET['local'] === '1';
$LOCAL_CSV_PATH = '/mnt/data/A21030000I-B1000A-00F.csv';

const DATASET_URL = 'https://data.gov.tw/dataset/20251';

/* ------------------ HTTP 工具 ------------------ */
function http_get(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (wm_payroll gov_nhi sync)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 400) ? (string)$body : null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 25,
            'header' => "User-Agent: Mozilla/5.0 (wm_payroll gov_nhi sync)\r\nAccept: text/html\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body !== false) ? (string)$body : null;
}

/* ------------------ 版本/時間 解析 ------------------ */
function parse_year_month_rank(string $text): ?array {
    $t = mb_strtolower($text, 'UTF-8');
    $months = [
        'january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,
        'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12
    ];
    foreach ($months as $name => $m) {
        if (strpos($t, $name) !== false) {
            if (preg_match('/\b(20\d{2})\b/', $t, $m1)) {
                return ['y' => (int)$m1[1], 'm' => $m];
            }
        }
    }
    if (preg_match('/\b(1\d{2})\s*年(?:\s*(\d{1,2})\s*月)?/u', $text, $m2)) {
        $roc = (int)$m2[1];
        $year = $roc + 1911;
        $month = isset($m2[2]) ? max(1, min(12, (int)$m2[2])) : 12;
        return ['y' => $year, 'm' => $month];
    }
    if (preg_match('/\b(1\d{2})\b/u', $text, $m3)) {
        return ['y' => (int)$m3[1] + 1911, 'm' => 12];
    }
    if (preg_match('/\b(20\d{2})\b/', $t, $m4)) {
        return ['y' => (int)$m4[1], 'm' => 12];
    }
    return null;
}

function parse_quality_time(string $segment): ?int {
    if (preg_match('/品質檢測時間[^0-9]*(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/u', $segment, $m)) {
        $ts = strtotime($m[1] . ' ' . $m[2]);
        return $ts !== false ? $ts : null;
    }
    return null;
}

/* ------------------ 抓取 & 擷取 CSV 候選 ------------------ */
function find_latest_csv_from_dataset(string $datasetUrl): array {
    $html = http_get($datasetUrl);
    if (!$html) {
        throw new RuntimeException('無法下載 dataset 頁面');
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    $nodes = $xp->query('//a[contains(translate(@href,"CSV","csv"), ".csv") or contains(@href,"download") or contains(@href,"dq_download_csv")]');

    $candidates = [];
    $order = 0;
    $rawHtml = $html;

    foreach ($nodes as $a) {
        /** @var DOMElement $a */
        $href = trim($a->getAttribute('href'));
        if ($href === '') continue;
        if (strpos($href, 'http') !== 0) {
            $base = parse_url($datasetUrl);
            $href = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') . '/' . ltrim($href, '/');
        }
        $text = trim(preg_replace('/\s+/', ' ', $a->textContent ?? ''));
        $rank = parse_year_month_rank($text);

        $pos = mb_stripos($rawHtml, $href);
        $quality_ts = null;
        if ($pos !== false) {
            $start = max(0, $pos - 800);
            $len   = 1600;
            $segment = mb_substr($rawHtml, $start, $len);
            $quality_ts = parse_quality_time($segment);
            if (!$rank) {
                $seg_txt = html_entity_decode(strip_tags($segment), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $rank = parse_year_month_rank($seg_txt);
            }
        }

        $candidates[] = [
            'href' => $href,
            'text' => $text,
            'rank' => $rank,
            'quality_ts' => $quality_ts,
            'order' => $order++,
        ];
    }

    if (empty($candidates)) {
        throw new RuntimeException('找不到任何 CSV 連結候選');
    }

    usort($candidates, function ($a, $b) {
        $ra = $a['rank']; $rb = $b['rank'];
        if ($ra && $rb) {
            if ($ra['y'] !== $rb['y']) return $rb['y'] <=> $ra['y'];
            if (($ra['m'] ?? 0) !== ($rb['m'] ?? 0)) return ($rb['m'] ?? 0) <=> ($ra['m'] ?? 0);
        } elseif ($ra && !$rb) {
            return -1;
        } elseif (!$ra && $rb) {
            return 1;
        }
        $qa = $a['quality_ts'] ?? 0;
        $qb = $b['quality_ts'] ?? 0;
        if ($qa !== $qb) return $qb <=> $qa;
        return $b['order'] <=> $a['order'];
    });

    $best = $candidates[0];
    $y = $best['rank']['y'] ?? null;
    $m = $best['rank']['m'] ?? 12;
    $effective_date = $y ? sprintf('%04d-%02d-01', $y, $m) : date('Y-m-01');

    return [
        'csv_url' => $best['href'],
        'version' => $best['rank'],
        'quality_ts' => $best['quality_ts'],
        'effective_date' => $effective_date,
        'picked_from' => $best,
        'stats' => ['total_candidates' => count($candidates)],
    ];
}

/* ------------------ CSV 解析 ------------------ */
function normalize_to_utf8(string $bytes): string {
    $enc = mb_detect_encoding($bytes, ['UTF-8','BIG5','CP950','EUC-TW','ISO-8859-1'], true);
    if ($enc && $enc !== 'UTF-8') {
        $converted = @iconv($enc, 'UTF-8//IGNORE', $bytes);
        if ($converted !== false) return $converted;
    }
    return preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
}

function parse_csv_to_array(string $csv): array {
    $csv = str_replace("\r\n", "\n", $csv);
    $lines = explode("\n", trim($csv));
    if (count($lines) === 0) return [];
    $header = str_getcsv(array_shift($lines));
    $header = array_map(function($h){ return trim((string)$h); }, $header);

    $rows = [];
    foreach ($lines as $ln) {
        if (trim($ln) === '') continue;
        $cols = str_getcsv($ln);
        if (count($cols) < 1) continue;
        if (count($cols) !== count($header)) {
            if (count($cols) < floor(count($header) * 0.6)) continue;
            $cols = array_pad($cols, count($header), null);
            $cols = array_slice($cols, 0, count($header));
        }
        $row = [];
        foreach ($header as $i => $h) {
            $row[$h] = isset($cols[$i]) ? trim((string)$cols[$i]) : null;
        }
        $rows[] = $row;
    }
    return ['header' => $header, 'rows' => $rows];
}

/* ------------------ 工具：範圍與數值 ------------------ */
function parse_money(string $s): ?float {
    if ($s === '' || $s === null) return null;
    $s = preg_replace('/[^\d\.]/u', '', str_replace(',', '', $s));
    return $s === '' ? null : (float)$s;
}
function parse_range(string $s): array {
    // 支援：～, ~, -, –, 至
    $sep = '～|~|-|–|至|—|──';
    if (preg_match('/(\d[\d,\.]*)\s*(?:'.$sep.')\s*(\d[\d,\.]*)/u', $s, $m)) {
        $a = parse_money($m[1]);
        $b = parse_money($m[2]);
        if ($a !== null && $b !== null) {
            if ($a > $b) { $t = $a; $a = $b; $b = $t; }
            return [$a, $b];
        }
    }
    $v = parse_money($s);
    return [$v, $v];
}

/* ------------------ 欄位對應（已擴充符合你的檔案） ------------------ */
function map_row_to_nhi(array $row, int $fallbackLevelNo): array {
    // level_no：支援「投保等級」「等級」「級距」
    $levelFields = ['投保等級','等級','級距','等第','級距序','等','級距代碼','Level','No'];
    $level_no = null;
    foreach ($levelFields as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== '') {
            $level_no = (int)preg_replace('/[^\d]/', '', $row[$k]);
            break;
        }
    }
    if (!$level_no) $level_no = $fallbackLevelNo;

    // 組別/身分類：這份 CSV 看到「組別級距」
    $groupFields = ['組別級距','類別','身分別','身份別','身份類別','身份代碼','身份','類別代碼','Group','group_code'];
    $group_code = null;
    foreach ($groupFields as $k) {
        if (isset($row[$k]) && $row[$k] !== '') {
            $group_code = (string)$row[$k];
            break;
        }
    }

    // 金額欄位：
    // 1) 單值基本金額：例如「月投保金額（元）」→ base_amount
    // 2) 薪資區間：例如「實際薪資月額（元）」→ 解析區間到 wage_min / wage_max
    $baseFields = ['月投保金額（元）','投保金額','投保薪資','級距金額','保險金額','月投保金額','base_amount'];
    $base_amount = null;
    foreach ($baseFields as $k) {
        if (isset($row[$k]) && $row[$k] !== '') {
            $base_amount = parse_money($row[$k]);
            break;
        }
    }

    $wage_min = null; $wage_max = null;
    $rangeFields = ['實際薪資月額（元）','實際薪資','薪資月額','投保薪資範圍','投保薪資（範圍）','薪資區間','級距範圍'];
    foreach ($rangeFields as $k) {
        if (isset($row[$k]) && $row[$k] !== '') {
            list($wage_min, $wage_max) = parse_range($row[$k]);
            break;
        }
    }

    // 如果沒有區間，則將 base_amount 視為單點上下限相同
    if ($wage_min === null && $wage_max === null && $base_amount !== null) {
        $wage_min = $base_amount;
        $wage_max = $base_amount;
    }

    return [
        'level_no'    => $level_no,
        'wage_min'    => $wage_min,
        'wage_max'    => $wage_max,
        'base_amount' => $base_amount,
        'group_code'  => $group_code,
    ];
}

/* ------------------ DB 寫入 ------------------ */
function upsert_gov_nhi(PDO $pdo, array $rows, string $effectiveDate, string $resourceId): int {
    if (empty($rows)) return 0;
    $stmtDel = $pdo->prepare("DELETE FROM gov_nhi WHERE effective_date = ?");
    $stmtDel->execute([$effectiveDate]);

    $stmt = $pdo->prepare("
        INSERT INTO gov_nhi
            (level_no, wage_min, wage_max, base_amount, group_code, resource_id, effective_date)
        VALUES
            (:level_no, :wage_min, :wage_max, :base_amount, :group_code, :resource_id, :effective_date)
    ");

    $count = 0;
    foreach ($rows as $r) {
        $stmt->execute([
            ':level_no'       => $r['level_no'],
            ':wage_min'       => $r['wage_min'],
            ':wage_max'       => $r['wage_max'],
            ':base_amount'    => $r['base_amount'],
            ':group_code'     => $r['group_code'],
            ':resource_id'    => $resourceId,
            ':effective_date' => $effectiveDate,
        ]);
        $count++;
    }
    return $count;
}

/* ------------------ 主程式 ------------------ */
try {
    if ($USE_LOCAL_FILE_FOR_TEST) {
        // 測試用：讀本地 CSV
        if (!is_file($LOCAL_CSV_PATH)) {
            throw new RuntimeException('找不到本地 CSV：'.$LOCAL_CSV_PATH);
        }
        $csvBytes = file_get_contents($LOCAL_CSV_PATH);
        $effectiveDate = '2025-01-01'; // 你可以依檔案版本自行調整
        $csvUrl = 'file://'.basename($LOCAL_CSV_PATH);
    } else {
        // 1) 找最新 CSV + 推論 effective_date
        $pick = find_latest_csv_from_dataset(DATASET_URL);
        $csvUrl = $pick['csv_url'];
        $effectiveDate = $pick['effective_date'];
        // 2) 下載 CSV
        $csvBytes = http_get($csvUrl);
        if (!$csvBytes || strlen($csvBytes) < 64) {
            throw new RuntimeException('CSV 下載失敗或內容異常：' . $csvUrl);
        }
    }

    // 3) 轉 UTF-8 並剖析
    $utf8 = normalize_to_utf8($csvBytes);
    $parsed = parse_csv_to_array($utf8);
    if (empty($parsed['rows'])) {
        throw new RuntimeException('CSV 解析不到資料列（檢查是否分隔符/編碼異常）');
    }

    // 4) 對應欄位
    $mapped = [];
    $lvl = 1;
    foreach ($parsed['rows'] as $row) {
        $m = map_row_to_nhi($row, $lvl);
        if ($m['wage_min'] === null && $m['wage_max'] === null && $m['base_amount'] === null) {
            $lvl++;
            continue;
        }
        if ($m['wage_min'] !== null && $m['wage_max'] !== null && $m['wage_min'] > $m['wage_max']) {
            $tmp = $m['wage_min']; $m['wage_min'] = $m['wage_max']; $m['wage_max'] = $tmp;
        }
        $mapped[] = $m;
        $lvl++;
    }
    if (empty($mapped)) {
        throw new RuntimeException('無法對應任何有效的級距金額欄位，請檢查 CSV 欄名');
    }

    // 5) 寫 DB
    $pdo->beginTransaction();
    $resourceId = substr(sha1($csvUrl), 0, 40);
    $n = upsert_gov_nhi($pdo, $mapped, $effectiveDate, $resourceId);
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'csv_url' => $csvUrl,
        'effective_date' => $effectiveDate,
        'inserted' => $n,
        'headers' => $parsed['header'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
