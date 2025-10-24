<?php
// Public/api/opendata/gov_labor_pension.php
// 勞退提繳工資等級表（CSV 下載 → 解析 → 清空 → 寫入 gov_labor_pension）
// 回傳 JSON：{ ok:1, inserted: N, message: "...", sample: {...} }

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

const SRC_URL = 'https://apiservice.mol.gov.tw/OdService/download/A17000000J-020031-PFu';
$table = 'gov_labor_pension';

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../../config/db.php';
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('DB 連線失敗：config/db.php 未回傳 PDO 實例');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1) 抓 CSV 原始內容（cURL 優先、file_get_contents 後備）
    $raw = fetch_raw(SRC_URL);
    if ($raw === null || $raw === '') {
        throw new RuntimeException('下載失敗或空白內容');
    }

    // 2) 正常化編碼（常見為 UTF-8 / Big5）
    $raw = normalize_encoding($raw);

    // 3) 解析 CSV
    [$headers, $rows] = parse_csv($raw);
    if (empty($headers)) {
        throw new RuntimeException('CSV 標題列解析失敗');
    }

    // 4) 建立欄位對應（盡量容錯）
    $map = detect_columns($headers);
    if (!$map['level'] || (!$map['wage_range'] && !($map['wage_min'] && $map['wage_max'])) ) {
        // 至少需要 等級 以及（範圍 或 上下限）
        throw new RuntimeException('必要欄位不足（等級／薪資範圍或上下限）');
    }

    // 5) 轉資料
    $data = [];
    foreach ($rows as $r) {
        $level_no = parse_int($r[$map['level']] ?? null);

        // 薪資區間
        $wmin = null; $wmax = null;
        if ($map['wage_range']) {
            [$wmin, $wmax] = parse_wage_range((string)($r[$map['wage_range']] ?? ''));
        }
        if ($map['wage_min']) $wmin = parse_money($r[$map['wage_min']] ?? null, $wmin);
        if ($map['wage_max']) $wmax = parse_money($r[$map['wage_max']] ?? null, $wmax);

        // 提繳基數（若無，留 NULL；若有「提繳工資」「本薪」「基數」之類就取）
        $base_amount = null;
        if ($map['base_amount']) {
            $base_amount = parse_money($r[$map['base_amount']] ?? null, null);
        } else {
            // 有些資料會直接用區間下限當基數，這裡不強制推論；若你想要以 wmin 當基數，取消下行註解：
            // $base_amount = $wmin;
        }

        // 生效日期（民國/西元自動）
        $effective_date = null;
        if ($map['effective_date']) {
            $effective_date = parse_date_roc_or_gregorian((string)($r[$map['effective_date']] ?? ''));
        }

        if ($level_no === null || ($wmin === null && $wmax === null)) {
            // 跳過明顯不完整列
            continue;
        }

        $data[] = [
            'level_no'       => $level_no,
            'wage_min'       => $wmin,
            'wage_max'       => $wmax,
            'base_amount'    => $base_amount,
            'effective_date' => $effective_date,
        ];
    }

    if (!$data) {
        throw new RuntimeException('CSV 內容未解析出有效資料列');
    }

    // 6) 清空並寫入
    $pdo->beginTransaction();
    $pdo->exec("TRUNCATE TABLE `{$table}`");

    $sql = "INSERT INTO `{$table}` (level_no, wage_min, wage_max, base_amount, effective_date)
            VALUES (:level_no, :wage_min, :wage_max, :base_amount, :effective_date)";
    $stmt = $pdo->prepare($sql);

    $inserted = 0;
    foreach ($data as $row) {
        $stmt->execute([
            ':level_no'       => $row['level_no'],
            ':wage_min'       => $row['wage_min'],
            ':wage_max'       => $row['wage_max'],
            ':base_amount'    => $row['base_amount'],
            ':effective_date' => $row['effective_date'],
        ]);
        $inserted++;
    }
    $pdo->commit();

    echo json_encode([
        'ok' => 1,
        'inserted' => $inserted,
        'message' => 'gov_labor_pension 已重載完成',
        'sample' => $data[0] ?? null,
        'headers_detected' => $map,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}


// ------------------------ Functions ------------------------

/** 下載原始資料（cURL 優先，file_get_contents 後備） */
function fetch_raw(string $url): ?string {
    // cURL
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: text/csv, */*'],
            CURLOPT_USERAGENT => 'wm_payroll-opendata-fetcher/1.0',
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) {
            return $resp;
        }
        if ($err) {
            // 繼續嘗試 fallback
        }
    }
    // fallback
    $ctx = stream_context_create([
        'http' => ['timeout' => 60, 'header' => "User-Agent: wm_payroll-opendata-fetcher/1.0\r\n"]
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return ($resp !== false) ? $resp : null;
}

/** 嘗試轉為 UTF-8 */
function normalize_encoding(string $s): string {
    // 去除 UTF-8 BOM
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
        $s = substr($s, 3);
    }
    // 非 UTF-8 時嘗試從 BIG5 轉
    if (!mb_check_encoding($s, 'UTF-8')) {
        $converted = @iconv('BIG5', 'UTF-8//IGNORE', $s);
        if ($converted !== false) return $converted;
        $converted = @iconv('CP950', 'UTF-8//IGNORE', $s);
        if ($converted !== false) return $converted;
    }
    return $s;
}

/** 解析 CSV → [headers[], rows[]] */
function parse_csv(string $raw): array {
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $raw);
    rewind($fp);

    $headers = null;
    $rows = [];
    // 嘗試常見分隔（逗號為主）
    while (($cols = fgetcsv($fp, 0, ',')) !== false) {
        // 忽略全空行
        if (count(array_filter($cols, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        if ($headers === null) {
            // 首列當標題
            $headers = array_map('trim', $cols);
            continue;
        }
        // 一般資料列
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$i] = $cols[$i] ?? null;
        }
        $rows[] = $row;
    }
    fclose($fp);

    return [$headers ?? [], $rows];
}

/** 偵測欄位索引（容錯、關鍵字比對） */
function detect_columns(array $headers): array {
    // 同時保留原始與簡化（去空白）
    $norm = [];
    foreach ($headers as $i => $h) {
        $raw = trim((string)$h);
        $norm[$i] = [
            'raw' => $raw,
            'simple' => preg_replace('/\s+/', '', $raw),
        ];
    }

    $map = [
        'level'          => null,
        'wage_range'     => null,
        'wage_min'       => null,
        'wage_max'       => null,
        'base_amount'    => null,
        'effective_date' => null,
    ];

    foreach ($norm as $i => $h) {
        $raw = $h['raw'];
        $simple = $h['simple'];

        // 1) 等級
        if ($map['level'] === null) {
            if (preg_match('/^等級$|級距|級別|^級$/u', $raw) || preg_match('/等級|級距|級別|級$/u', $simple)) {
                $map['level'] = $i;
                continue;
            }
        }

        // 2) 直接標示上下限（保留舊邏輯）
        if ($map['wage_min'] === null && preg_match('/(薪資|工資)?(下限|min)/iu', $raw)) {
            $map['wage_min'] = $i;
        }
        if ($map['wage_max'] === null && preg_match('/(薪資|工資)?(上限|max)/iu', $raw)) {
            $map['wage_max'] = $i;
        }

        // 3) CSV 實際用語：把「實際工資/執行業務所得」視為【薪資範圍】
        if ($map['wage_range'] === null) {
            if (preg_match('/實際工資|執行業務所得/u', $raw)) {
                $map['wage_range'] = $i;
                continue;
            }
            // 備援：仍支援「範圍/區間/級距/分級」等用語
            if (preg_match('/(薪資|工資|月提繳|提繳)?(範圍|區間|級距|分級)/u', $raw)) {
                $map['wage_range'] = $i;
                continue;
            }
        }

        // 4) CSV 實際用語：把「月提繳工資金額/月提繳執行業務所得金額」視為【base_amount】
        if ($map['base_amount'] === null) {
            if (preg_match('/月提繳(工資)?金額|月提繳執行業務所得金額/u', $raw)) {
                $map['base_amount'] = $i;
                continue;
            }
            // 備援：泛用關鍵字
            if (preg_match('/(提繳|投保)?(工資|基數)|本薪|本俸/u', $raw)) {
                $map['base_amount'] = $i;
                continue;
            }
        }

        // 5) 生效/實施/適用日期
        if ($map['effective_date'] === null) {
            if (preg_match('/生效日|實施日|適用日|發布日/u', $raw)) {
                $map['effective_date'] = $i;
                continue;
            }
            if (preg_match('/(生效|實施|適用|發布)?日(期)?/u', $simple)) {
                $map['effective_date'] = $i;
                continue;
            }
        }
    }

    return $map;
}


/** 解析整數 */
function parse_int($v): ?int {
    if ($v === null) return null;
    $v = trim((string)$v);
    if ($v === '') return null;
    if (!preg_match('/^-?\d+$/', str_replace(',', '', $v))) return null;
    return (int)str_replace(',', '', $v);
}

/** 解析金額（保留兩位），fallback 若給定 default 則回傳 default */
function parse_money($v, $default = null): ?float {
    if ($v === null) return $default;
    $s = trim((string)$v);
    if ($s === '') return $default;
    // 去除千分位與非數字符號
    $s = preg_replace('/[^\d\.\-]/', '', $s);
    if ($s === '' || !is_numeric($s)) return $default;
    return round((float)$s, 2);
}

/** 解析區間字串，如「23,100-24,000」或「23100～24000」 */
function parse_wage_range(string $s): array {
    $s = trim($s);
    if ($s === '') return [null, null];
    // 統一分隔符
    $s = str_replace(['～', '—', '–', '至'], '-', $s);
    if (strpos($s, '-') !== false) {
        [$a, $b] = explode('-', $s, 2);
        return [parse_money($a, null), parse_money($b, null)];
    }
    // 若只有一個數，當作單點
    $v = parse_money($s, null);
    return [$v, $v];
}

/** 解析日期（支援 民國YYY/MM/DD 或 西元 YYYY/MM/DD、YYYY-MM-DD）→ YYYY-MM-DD */
function parse_date_roc_or_gregorian(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;

    // 民國： e.g., 112/01/01 或 112.1.1
    if (preg_match('/^(\d{2,3})[\/\.\-](\d{1,2})[\/\.\-](\d{1,2})$/', $s, $m)) {
        $y = (int)$m[1];
        $mth = (int)$m[2];
        $d = (int)$m[3];
        // 判斷是否民國（小於 1911 視為民國年）
        if ($y < 1911) $y += 1911;
        return sprintf('%04d-%02d-%02d', $y, $mth, $d);
    }

    // 西元：YYYY-MM-DD / YYYY/MM/DD
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }

    // 只給年月
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]);
    }

    // 無法判讀
    return null;
}
