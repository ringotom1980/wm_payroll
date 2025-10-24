<?php
// Public/api/opendata/gov_nhi.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php'; // 需提供 $pdo (PDO)

/**
 * 主要流程：
 * 1) 從 data.gov.tw/dataset/20251 抓 HTML
 * 2) 解析每筆「資料資源」附近文字，萃取 (年, 月) 與「資料資源品質檢測時間」
 * 3) 排序：年 desc, 月 desc, 品質時間 desc, 出現順序 desc
 * 4) 下載該 CSV → 解析 → 寫入 gov_nhi（同 effective_date 先刪後插）
 */

const DATASET_URL = 'https://data.gov.tw/dataset/20251';

/* ------------------ HTTP 工具 ------------------ */
function http_get(string $url): ?string {
    // cURL 優先
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
    // fallback
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
/** 將「民國年/月」或「英文月份 西元年」或「純西元年」字樣解析為排序用 (y,m)。 */
function parse_year_month_rank(string $text): ?array {
    $t = mb_strtolower($text, 'UTF-8');

    // 英文月份 + 西元年
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

    // 民國 年/月：例如「114年1月」「113 年 12 月」
    if (preg_match('/\b(1\d{2})\s*年(?:\s*(\d{1,2})\s*月)?/u', $text, $m2)) {
        $roc = (int)$m2[1];
        $year = $roc + 1911; // 113 -> 2024, 114 -> 2025
        $month = isset($m2[2]) ? max(1, min(12, (int)$m2[2])) : 12;
        return ['y' => $year, 'm' => $month];
    }

    // 純民國年（當年末）
    if (preg_match('/\b(1\d{2})\b/u', $text, $m3)) {
        $roc = (int)$m3[1];
        return ['y' => $roc + 1911, 'm' => 12];
    }

    // 純西元年（當年末）
    if (preg_match('/\b(20\d{2})\b/', $t, $m4)) {
        return ['y' => (int)$m4[1], 'm' => 12];
    }

    return null;
}

/** 從一小段 HTML 文字中抓「資料資源品質檢測時間 YYYY-MM-DD HH:MM:SS」 */
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

    // DOM 解析
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    // 候選條件：所有 <a> 連結中 URL 包含 csv（或最常見的 CSV 下載器關鍵字）
    $nodes = $xp->query('//a[contains(translate(@href,"CSV","csv"), ".csv") or contains(@href,"download") or contains(@href,"dq_download_csv")]');

    $candidates = [];
    $order = 0;

    // 取得原始 HTML 文字以便做「附近文本」分析
    $rawHtml = $html;

    foreach ($nodes as $a) {
        /** @var DOMElement $a */
        $href = trim($a->getAttribute('href'));
        if ($href === '') continue;

        // 轉成絕對 URL（如果是相對）
        if (strpos($href, 'http') !== 0) {
            // 用 data.gov.tw 作 base
            $base = parse_url($datasetUrl);
            $href = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') . '/' . ltrim($href, '/');
        }

        $text = trim(preg_replace('/\s+/', ' ', $a->textContent ?? ''));
        $rank = parse_year_month_rank($text);

        // 從原始 HTML 找到這個 href 附近的片段，抓品質檢測時間或補強年月
        $pos = mb_stripos($rawHtml, $href);
        $quality_ts = null;
        if ($pos !== false) {
            $start = max(0, $pos - 800);
            $len   = 1600;
            $segment = mb_substr($rawHtml, $start, $len);

            // 品質檢測時間
            $quality_ts = parse_quality_time($segment);

            // 若 rank 還沒有，嘗試從段落中補抓
            if (!$rank) {
                // 解 HTML 實體後再解析
                $seg_txt = html_entity_decode(strip_tags($segment), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $rank = parse_year_month_rank($seg_txt);
            }
        }

        $candidates[] = [
            'href' => $href,
            'text' => $text,
            'rank' => $rank,          // ['y'=>YYYY, 'm'=>MM] or null
            'quality_ts' => $quality_ts, // UNIX ts or null
            'order' => $order++,      // 出現順序
        ];
    }

    if (empty($candidates)) {
        throw new RuntimeException('找不到任何 CSV 連結候選');
    }

    // 排序規則：年 desc, 月 desc, 品質時間 desc, 出現順序 desc
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
        // 年月相同或皆無 → 比品質時間
        $qa = $a['quality_ts'] ?? 0;
        $qb = $b['quality_ts'] ?? 0;
        if ($qa !== $qb) return $qb <=> $qa;

        // 最後以出現順序（較後面視為較新）
        return $b['order'] <=> $a['order'];
    });

    $best = $candidates[0];
    // 計算 effective_date：若無月，補 12 月
    $y = $best['rank']['y'] ?? null;
    $m = $best['rank']['m'] ?? 12;
    if ($y) {
        $effective_date = sprintf('%04d-%02d-01', $y, $m);
    } else {
        // 沒有任何版本資訊，最後手段：用今天月份
        $effective_date = date('Y-m-01');
    }

    return [
        'csv_url' => $best['href'],
        'version' => $best['rank'],        // 可能為 null
        'quality_ts' => $best['quality_ts'],
        'effective_date' => $effective_date,
        'picked_from' => $best,
        'stats' => [
            'total_candidates' => count($candidates),
        ],
    ];
}

/* ------------------ CSV 解碼/剖析 ------------------ */
function normalize_to_utf8(string $bytes): string {
    // 嘗試常見編碼（有些政府 CSV 會用 Big5/CP950）
    $enc = mb_detect_encoding($bytes, ['UTF-8','BIG5','CP950','EUC-TW','ISO-8859-1'], true);
    if ($enc && $enc !== 'UTF-8') {
        $converted = @iconv($enc, 'UTF-8//IGNORE', $bytes);
        if ($converted !== false) return $converted;
    }
    // 強制去掉 UTF-8 BOM
    return preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
}

/**
 * 將 CSV 轉陣列（首列為標題）。允許欄位數不一的行（自動跳過不完整列）。
 */
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
        // 補齊/截斷到 header 長度
        if (count($cols) !== count($header)) {
            // 若欄位數差太多，跳過
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

/* ------------------ 欄位對應（彈性） ------------------ */
/**
 * 依常見欄名猜測：等級、級距、投保金額/薪資（單值或上下限）、群組/類別代碼。
 * 回傳統一欄位：level_no, wage_min, wage_max, base_amount, group_code
 */
function map_row_to_nhi(array $row, int $fallbackLevelNo): array {
    // 嘗試抓 level 編號
    $levelFields = ['等級','級距','等第','級距序','等','級距代碼','Level','No'];
    $level_no = null;
    foreach ($levelFields as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== '') {
            $level_no = (int)preg_replace('/[^\d]/', '', $row[$k]);
            break;
        }
    }
    if (!$level_no) $level_no = $fallbackLevelNo;

    // 工資/金額（單值或區間）
    $amountSingleFields = ['投保金額','投保薪資','投保金額(元)','月投保金額','級距金額','保險金額','Base','Amount'];
    $minFields = ['投保薪資下限','級距下限','薪資下限','下限','金額下限','wage_min','min'];
    $maxFields = ['投保薪資上限','級距上限','薪資上限','上限','金額上限','wage_max','max'];

    $wage_min = null; $wage_max = null; $base_amount = null;

    // 先試分開上下限
    foreach ($minFields as $k) {
        if (isset($row[$k]) && $row[$k] !== '') {
            $wage_min = (float)preg_replace('/[^\d.]/', '', $row[$k]);
            break;
        }
    }
    foreach ($maxFields as $k) {
        if (isset($row[$k]) && $row[$k] !== '') {
            $wage_max = (float)preg_replace('/[^\d.]/', '', $row[$k]);
            break;
        }
    }

    // 若沒有分開，試單一欄或「區間字串」
    if ($wage_min === null && $wage_max === null) {
        foreach ($amountSingleFields as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                $txt = $row[$k];
                if (preg_match('/(\d[\d,\.]*)\s*[-~–]\s*(\d[\d,\.]*)/', $txt, $m)) {
                    $wage_min = (float)str_replace([','], [''], $m[1]);
                    $wage_max = (float)str_replace([','], [''], $m[2]);
                } else {
                    $val = (float)preg_replace('/[^\d.]/', '', $txt);
                    $wage_min = $val;
                    $wage_max = $val;
                }
                break;
            }
        }
    }

    // base_amount：若有單獨欄位就用，否則取 wage_min（單值）
    $baseFields = ['投保金額','投保薪資','級距金額','保險金額','月投保金額','base_amount'];
    foreach ($baseFields as $k) {
        if (isset($row[$k]) && $row[$k] !== '') {
            $base_amount = (float)preg_replace('/[^\d.]/', '', $row[$k]);
            break;
        }
    }
    if ($base_amount === null && $wage_min !== null && $wage_max !== null && abs($wage_min - $wage_max) < 0.001) {
        $base_amount = $wage_min;
    }

    // group_code（若有）
    $groupFields = ['類別','身分別','身份別','身份類別','身份代碼','身份','類別代碼','Group','group_code'];
    $group_code = null;
    foreach ($groupFields as $k) {
        if (isset($row[$k]) && $row[$k] !== '') {
            $group_code = (string)$row[$k];
            break;
        }
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

    // 同 effective_date 先刪
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
            ':level_no'      => $r['level_no'],
            ':wage_min'      => $r['wage_min'],
            ':wage_max'      => $r['wage_max'],
            ':base_amount'   => $r['base_amount'],
            ':group_code'    => $r['group_code'],
            ':resource_id'   => $resourceId,
            ':effective_date'=> $effectiveDate,
        ]);
        $count++;
    }
    return $count;
}

/* ------------------ 主程式 ------------------ */
try {
    // 1) 找最新 CSV + 推論 effective_date
    $pick = find_latest_csv_from_dataset(DATASET_URL);
    $csvUrl = $pick['csv_url'];
    $effectiveDate = $pick['effective_date'];

    // 2) 下載 CSV
    $csvBytes = http_get($csvUrl);
    if (!$csvBytes || strlen($csvBytes) < 64) {
        throw new RuntimeException('CSV 下載失敗或內容異常：' . $csvUrl);
    }

    // 3) 轉 UTF-8 並剖析
    $utf8 = normalize_to_utf8($csvBytes);
    $parsed = parse_csv_to_array($utf8);
    if (empty($parsed['rows'])) {
        throw new RuntimeException('CSV 解析不到資料列（檢查是否分隔符/編碼異常）');
    }

    // 4) 欄位對應 → 統一結構
    $mapped = [];
    $lvl = 1;
    foreach ($parsed['rows'] as $row) {
        $m = map_row_to_nhi($row, $lvl);
        // 若三個金額欄都抓不到，跳過
        if ($m['wage_min'] === null && $m['wage_max'] === null && $m['base_amount'] === null) {
            $lvl++;
            continue;
        }
        // 正常化：wage_min <= wage_max
        if ($m['wage_min'] !== null && $m['wage_max'] !== null && $m['wage_min'] > $m['wage_max']) {
            $tmp = $m['wage_min'];
            $m['wage_min'] = $m['wage_max'];
            $m['wage_max'] = $tmp;
        }
        $mapped[] = $m;
        $lvl++;
    }

    if (empty($mapped)) {
        throw new RuntimeException('無法對應任何有效的級距金額欄位，請檢查 CSV 欄名');
    }

    // 5) 寫入 DB（交易）
    $pdo->beginTransaction();
    $resourceId = substr(sha1($csvUrl), 0, 40); // 記錄來源（用 URL 的 SHA1 縮）
    $n = upsert_gov_nhi($pdo, $mapped, $effectiveDate, $resourceId);
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'csv_url' => $csvUrl,
        'effective_date' => $effectiveDate,
        'inserted' => $n,
        'pick_meta' => [
            'version' => $pick['version'],
            'quality_ts' => $pick['quality_ts'],
            'candidates' => $pick['stats']['total_candidates'] ?? null,
        ],
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
