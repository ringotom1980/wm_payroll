<?php
// Public/api/opendata/gov_nhi.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php'; // 應提供 $pdo (PDO)

$USE_LOCAL_FILE_FOR_TEST = isset($_GET['local']) && $_GET['local'] === '1';
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
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
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 400) ? (string)$body : null;
    }
    $ctx = stream_context_create([
        'http' => ['method' => 'GET','timeout' => 25,'header' => "User-Agent: Mozilla/5.0 (wm_payroll gov_nhi sync)\r\nAccept: text/html\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body !== false) ? (string)$body : null;
}

/* ------------------ 版本/時間 解析 ------------------ */
function parse_year_month_rank(string $text): ?array {
    $t = mb_strtolower($text, 'UTF-8');
    $months = ['january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12];
    foreach ($months as $name => $m) {
        if (strpos($t, $name) !== false && preg_match('/\b(20\d{2})\b/', $t, $m1)) return ['y'=>(int)$m1[1],'m'=>$m];
    }
    if (preg_match('/\b(1\d{2})\s*年(?:\s*(\d{1,2})\s*月)?/u', $text, $m2)) {
        return ['y'=>(int)$m2[1]+1911,'m'=> isset($m2[2])?max(1,min(12,(int)$m2[2])):12];
    }
    if (preg_match('/\b(1\d{2})\b/u', $text, $m3)) return ['y'=>(int)$m3[1]+1911,'m'=>12];
    if (preg_match('/\b(20\d{2})\b/', $t, $m4)) return ['y'=>(int)$m4[1],'m'=>12];
    return null;
}
function parse_quality_time(string $segment): ?int {
    if (preg_match('/品質檢測時間[^0-9]*(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/u', $segment, $m)) {
        $ts = strtotime($m[1].' '.$m[2]); return $ts!==false?$ts:null;
    }
    return null;
}

/* ------------------ 抓取 & 擷取 CSV 候選 ------------------ */
function find_latest_csv_from_dataset(string $datasetUrl): array {
    $html = http_get($datasetUrl);
    if (!$html) throw new RuntimeException('無法下載 dataset 頁面');

    $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($html); libxml_clear_errors();
    $xp = new DOMXPath($dom);

    $nodes = $xp->query('//a[contains(translate(@href,"CSV","csv"), ".csv") or contains(@href,"download") or contains(@href,"dq_download_csv")]');

    $candidates = []; $order = 0; $rawHtml = $html;
    foreach ($nodes as $a) {
        /** @var DOMElement $a */
        $href = trim($a->getAttribute('href')); if ($href==='') continue;
        if (strpos($href, 'http') !== 0) {
            $base = parse_url($datasetUrl);
            $href = $base['scheme'].'://'.$base['host'].(isset($base['port'])?':'.$base['port']:'').'/'.ltrim($href,'/');
        }
        $text = trim(preg_replace('/\s+/', ' ', $a->textContent ?? ''));
        $rank = parse_year_month_rank($text);

        $pos = mb_stripos($rawHtml, $href); $quality_ts = null;
        if ($pos !== false) {
            $segment = mb_substr($rawHtml, max(0,$pos-800), 1600);
            $quality_ts = parse_quality_time($segment);
            if (!$rank) {
                $seg_txt = html_entity_decode(strip_tags($segment), ENT_QUOTES|ENT_HTML5, 'UTF-8');
                $rank = parse_year_month_rank($seg_txt);
            }
        }
        $candidates[] = ['href'=>$href,'text'=>$text,'rank'=>$rank,'quality_ts'=>$quality_ts,'order'=>$order++];
    }
    if (!$candidates) throw new RuntimeException('找不到任何 CSV 連結候選');

    usort($candidates, function($a,$b){
        $ra=$a['rank']; $rb=$b['rank'];
        if ($ra && $rb) { if ($ra['y']!==$rb['y']) return $rb['y']<=>$ra['y']; if (($ra['m']??0)!==($rb['m']??0)) return ($rb['m']??0)<=>($ra['m']??0); }
        elseif ($ra && !$rb) return -1; elseif (!$ra && $rb) return 1;
        $qa=$a['quality_ts']??0; $qb=$b['quality_ts']??0; if ($qa!==$qb) return $qb<=>$qa;
        return $b['order']<=>$a['order'];
    });

    $best = $candidates[0];
    $y = $best['rank']['y'] ?? null; $m = $best['rank']['m'] ?? 12;
    $effective_date = $y ? sprintf('%04d-%02d-01',$y,$m) : date('Y-m-01');

    return ['csv_url'=>$best['href'],'version'=>$best['rank'],'quality_ts'=>$best['quality_ts'],'effective_date'=>$effective_date,'picked_from'=>$best,'stats'=>['total_candidates'=>count($candidates)]];
}

/* ------------------ CSV 解析 ------------------ */
function normalize_to_utf8(string $bytes): string {
    $enc = mb_detect_encoding($bytes, ['UTF-8','BIG5','CP950','EUC-TW','ISO-8859-1'], true);
    if ($enc && $enc!=='UTF-8') { $converted=@iconv($enc,'UTF-8//IGNORE',$bytes); if ($converted!==false) return $converted; }
    return preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
}
function parse_csv_to_array(string $csv): array {
    $csv = str_replace("\r\n","\n",$csv);
    $lines = explode("\n", trim($csv));
    if (!$lines) return [];
    $header = str_getcsv(array_shift($lines));
    // 標準化欄名：去空白、全形括號轉半形
    $header = array_map(function($h){ $h=trim((string)$h); $h=str_replace(['（','）'],['(',')'],$h); return $h; }, $header);

    $rows = [];
    foreach ($lines as $ln) {
        if (trim($ln)==='') continue;
        $cols = str_getcsv($ln);
        if (count($cols) < 1) continue;
        if (count($cols) !== count($header)) {
            if (count($cols) < floor(count($header)*0.6)) continue;
            $cols = array_pad($cols, count($header), null);
            $cols = array_slice($cols, 0, count($header));
        }
        $r=[];
        foreach ($header as $i=>$h) { $v=isset($cols[$i])?trim((string)$cols[$i]):null; $r[$h]= $v!==''?$v:null; }
        $rows[]=$r;
    }
    return ['header'=>$header,'rows'=>$rows];
}

/* ------------------ 工具：範圍與數值 ------------------ */
function parse_money(?string $s): ?float {
    if ($s===null||$s==='') return null;
    $s = str_replace(['，',',',' '],['','',''],$s);
    $s = preg_replace('/[^\d\.]/u','',$s);
    return $s===''?null:(float)$s;
}
function parse_range(string $s): array {
    $sep='～|~|-|–|至|—|──|~|－';
    if (preg_match('/(\d[\d,，\.]*)\s*(?:'.$sep.')\s*(\d[\d,，\.]*)/u', $s, $m)) {
        $a=parse_money($m[1]); $b=parse_money($m[2]);
        if ($a!==null && $b!==null) { if ($a>$b){$t=$a;$a=$b;$b=$t;} return [$a,$b]; }
    }
    $v=parse_money($s); return [$v,$v];
}

/* ------------------ 欄位偵測輔助（模糊匹配） ------------------ */
function find_col(array $header, array $keywords): ?string {
    // 將欄名正規化（全形→半形、空白移除）
    $norm = [];
    foreach ($header as $h) {
        $key = preg_replace('/\s+/u','', str_replace(['（','）'],['(',')'],$h));
        $norm[$key] = $h; // 保留原欄名
    }
    foreach ($norm as $k=>$orig) {
        $k_lower = mb_strtolower($k,'UTF-8');
        $ok=true;
        foreach ($keywords as $kw) {
            if (mb_strpos($k_lower, $kw) === false) { $ok=false; break; }
        }
        if ($ok) return $orig;
    }
    return null;
}

/* ------------------ 欄位對應（強化版） ------------------ */
function map_row_to_nhi(array $row, array $header, int $fallbackLevelNo, array $detectCols): array {
    // level_no
    $level_no = null;
    $levelCandidates = ['投保等級','等級','級距','等第','級距序','等','級距代碼','Level','No'];
    foreach ($levelCandidates as $c) if (array_key_exists($c,$row) && $row[$c]!==null) { $level_no=(int)preg_replace('/[^\d]/','',$row[$c]); break; }
    if (!$level_no) $level_no = $fallbackLevelNo;

    // group_code
    $group_code = null;
    $groupCandidates = ['組別級距','類別','身分別','身份別','身份類別','身份代碼','身份','類別代碼','Group','group_code'];
    foreach ($groupCandidates as $c) if (isset($row[$c]) && $row[$c]!==null) { $group_code=(string)$row[$c]; break; }

    // 金額來源欄
    $base_amount = null; $wage_min = null; $wage_max = null;

    // 先用偵測到的欄位名（模糊規則找出來的）
    $col_base = $detectCols['base_amount'] ?? null;     // 例如：月投保金額（元）
    $col_range = $detectCols['salary_range'] ?? null;   // 例如：實際薪資月額（元）

    if ($col_base && isset($row[$col_base])) $base_amount = parse_money($row[$col_base]);
    if ($col_range && isset($row[$col_range])) list($wage_min,$wage_max)=parse_range($row[$col_range] ?? '');

    // 退路：常見別名
    if ($base_amount===null) {
        $baseFields = ['月投保金額（元）','月投保金額(元)','投保金額','投保薪資','級距金額','保險金額','月投保金額','base_amount'];
        foreach ($baseFields as $c) if (isset($row[$c]) && $row[$c]!==null) { $base_amount=parse_money($row[$c]); break; }
    }
    if ($wage_min===null && $wage_max===null) {
        $rangeFields = ['實際薪資月額（元）','實際薪資月額(元)','實際薪資','薪資月額','投保薪資範圍','投保薪資（範圍）','薪資區間','級距範圍'];
        foreach ($rangeFields as $c) if (isset($row[$c]) && $row[$c]!==null) { list($wage_min,$wage_max)=parse_range($row[$c]); break; }
    }

    // 若沒有區間，使用 base_amount 當單點
    if ($wage_min===null && $wage_max===null && $base_amount!==null) { $wage_min=$base_amount; $wage_max=$base_amount; }

    // 正常化
    if ($wage_min!==null && $wage_max!==null && $wage_min>$wage_max) { $t=$wage_min; $wage_min=$wage_max; $wage_max=$t; }

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
    if (!$rows) return 0;
    $pdo->prepare("DELETE FROM gov_nhi WHERE effective_date = ?")->execute([$effectiveDate]);
    $stmt = $pdo->prepare("INSERT INTO gov_nhi (level_no,wage_min,wage_max,base_amount,group_code,resource_id,effective_date)
                           VALUES (:level_no,:wage_min,:wage_max,:base_amount,:group_code,:resource_id,:effective_date)");
    $n=0; foreach ($rows as $r) { $stmt->execute([
        ':level_no'=>$r['level_no'], ':wage_min'=>$r['wage_min'], ':wage_max'=>$r['wage_max'],
        ':base_amount'=>$r['base_amount'], ':group_code'=>$r['group_code'],
        ':resource_id'=>$resourceId, ':effective_date'=>$effectiveDate
    ]); $n++; }
    return $n;
}

/* ------------------ 主程式 ------------------ */
try {
    if ($USE_LOCAL_FILE_FOR_TEST) {
        if (!is_file($LOCAL_CSV_PATH)) throw new RuntimeException('找不到本地 CSV：'.$LOCAL_CSV_PATH);
        $csvBytes = file_get_contents($LOCAL_CSV_PATH);
        $effectiveDate = '2025-01-01';
        $csvUrl = 'file://'.basename($LOCAL_CSV_PATH);
    } else {
        $pick = find_latest_csv_from_dataset(DATASET_URL);
        $csvUrl = $pick['csv_url'];
        $effectiveDate = $pick['effective_date'];
        $csvBytes = http_get($csvUrl);
        if (!$csvBytes || strlen($csvBytes) < 64) throw new RuntimeException('CSV 下載失敗或內容異常：'.$csvUrl);
    }

    $utf8 = normalize_to_utf8($csvBytes);
    $parsed = parse_csv_to_array($utf8);
    $header = $parsed['header'] ?? [];
    $rowsRaw = $parsed['rows'] ?? [];
    if (!$rowsRaw) throw new RuntimeException('CSV 解析不到資料列（檢查是否分隔符/編碼/空白列）');

    // ---- 欄位模糊偵測（關鍵詞都需包含；已把全形括號轉半形處理） ----
    $col_base = find_col($header, ['月','投保','金額']);      // 例如：月投保金額（元）
    $col_range = find_col($header, ['薪資','月','額']);       // 例如：實際薪資月額（元）
    // 若偵測不到，試英文/別名
    if (!$col_base)  $col_base  = find_col($header, ['base']) ?: find_col($header, ['投保','金額']);
    if (!$col_range) $col_range = find_col($header, ['實際','薪資']) ?: find_col($header, ['薪資','區間']);

    $detectCols = ['base_amount'=>$col_base, 'salary_range'=>$col_range];

    // ---- 映射 ----
    $mapped=[]; $lvl=1;
    foreach ($rowsRaw as $row) {
        $m = map_row_to_nhi($row, $header, $lvl, $detectCols);
        if ($m['wage_min']===null && $m['wage_max']===null && $m['base_amount']===null) { $lvl++; continue; }
        $mapped[]=$m; $lvl++;
    }

    if (!$mapped) {
        if ($DEBUG) {
            echo json_encode([
                'ok'=>false,
                'error'=>'無法對應任何有效的級距金額欄位',
                'detected_cols'=>$detectCols,
                'headers'=>$header,
                'sample_rows'=>array_slice($rowsRaw,0,5),
                'csv_url'=>$csvUrl,
                'effective_date'=>$effectiveDate,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw new RuntimeException('無法對應任何有效的級距金額欄位，請檢查 CSV 欄名');
    }

    // ---- 寫 DB ----
    $pdo->beginTransaction();
    $resourceId = substr(sha1($csvUrl), 0, 40);
    $n = upsert_gov_nhi($pdo, $mapped, $effectiveDate, $resourceId);
    $pdo->commit();

    echo json_encode([
        'ok'=>true,
        'csv_url'=>$csvUrl,
        'effective_date'=>$effectiveDate,
        'inserted'=>$n,
        'detected_cols'=>$detectCols,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
