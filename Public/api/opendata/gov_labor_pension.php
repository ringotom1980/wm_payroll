<?php
// Public/api/opendata/gov_labor_pension.php
// 勞退提繳工資等級表：下載 CSV → 清空 → 寫入 gov_labor_pension
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

    // 1) 抓 CSV 原始內容
    $raw = fetch_raw(SRC_URL);
    if ($raw === null || $raw === '') {
        throw new RuntimeException('下載失敗或空白內容');
    }
    $raw = normalize_encoding($raw);

    // 2) 解析 CSV
    [$headers, $rows] = parse_csv($raw);
    if (!$headers) throw new RuntimeException('CSV 標題列解析失敗');

    // 3) 直接硬對應這份資料實際標題 + 備援模糊對應
    $map = map_columns_strict_then_fuzzy($headers);

    if ($map['level'] === null || (
        $map['wage_range'] === null &&
        !($map['wage_min'] !== null && $map['wage_max'] !== null)
    )) {
        throw new RuntimeException('必要欄位不足（等級／薪資範圍或上下限）');
    }

    // 4) 轉成資料列
    $data = [];
    foreach ($rows as $r) {
        $level_no = parse_int($r[$map['level']] ?? null);

        // 工資區間
        $wmin = null; $wmax = null;
        if ($map['wage_range'] !== null) {
            [$wmin, $wmax] = parse_wage_range((string)($r[$map['wage_range']] ?? ''));
        }
        if ($map['wage_min'] !== null) $wmin = parse_money($r[$map['wage_min']] ?? null, $wmin);
        if ($map['wage_max'] !== null) $wmax = parse_money($r[$map['wage_max']] ?? null, $wmax);

        // 基數（本表有：月提繳工資金額/月提繳執行業務所得金額）
        $base_amount = null;
        if ($map['base_amount'] !== null) {
            $base_amount = parse_money($r[$map['base_amount']] ?? null, null);
        }

        // 生效日（可能是民國 7 碼，如 1140101）
        $effective_date = null;
        if ($map['effective_date'] !== null) {
            $effective_date = parse_date_roc_or_gregorian((string)($r[$map['effective_date']] ?? ''));
        }

        // 跳過關鍵值缺失
        if ($level_no === null || ($wmin === null && $wmax === null)) continue;

        $data[] = [
            'level_no'       => $level_no,
            'wage_min'       => $wmin,
            'wage_max'       => $wmax,
            'base_amount'    => $base_amount,
            'effective_date' => $effective_date,
        ];
    }

    if (!$data) throw new RuntimeException('CSV 內容未解析出有效資料列');

    // 5) 清空並寫入
    $pdo->exec("TRUNCATE TABLE `{$table}`");
    $pdo->beginTransaction();
    
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
        'headers' => $headers,
        'headers_detected' => $map,
        'sample' => $data[0] ?? null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}


// ---------- Helpers ----------

function fetch_raw(string $url): ?string {
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
        if ($resp !== false && $code >= 200 && $code < 300) return $resp;
        if ($err) { /* 繼續 fallback */ }
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 60, 'header' => "User-Agent: wm_payroll-opendata-fetcher/1.0\r\n"]
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return ($resp !== false) ? $resp : null;
}

function normalize_encoding(string $s): string {
    // 去除 UTF-8 BOM
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") $s = substr($s, 3);
    // 若偵測不是 UTF-8，嘗試 BIG5/CP950 轉 UTF-8
    if (!mb_check_encoding($s, 'UTF-8')) {
        $c = @iconv('BIG5', 'UTF-8//IGNORE', $s);
        if ($c !== false) return $c;
        $c = @iconv('CP950', 'UTF-8//IGNORE', $s);
        if ($c !== false) return $c;
    }
    return $s;
}

function parse_csv(string $raw): array {
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $raw);
    rewind($fp);

    $headers = null;
    $rows = [];
    while (($cols = fgetcsv($fp, 0, ',')) !== false) {
        // 忽略全空行
        if (count(array_filter($cols, fn($v) => trim((string)$v) !== '')) === 0) continue;
        if ($headers === null) {
            $headers = array_map('trim', $cols);
            continue;
        }
        $row = [];
        foreach ($headers as $i => $h) $row[$i] = $cols[$i] ?? null;
        $rows[] = $row;
    }
    fclose($fp);
    return [$headers ?? [], $rows];
}

function map_columns_strict_then_fuzzy(array $headers): array {
    $map = [
        'level'          => null,
        'wage_range'     => null,
        'wage_min'       => null,
        'wage_max'       => null,
        'base_amount'    => null,
        'effective_date' => null,
    ];

    // 先精確對應（這份 CSV 的實際欄名）
    foreach ($headers as $i => $raw) {
        $raw = trim((string)$raw);
        if ($raw === '等級') $map['level'] = $i;
        if ($raw === '實際工資/執行業務所得') $map['wage_range'] = $i;
        if ($raw === '月提繳工資金額/月提繳執行業務所得金額') $map['base_amount'] = $i;
        if ($raw === '生效日') $map['effective_date'] = $i;
    }

    // 備援模糊對應（以防將來官方改名）
    foreach ($headers as $i => $h) {
        $raw = trim((string)$h);
        $simple = preg_replace('/\s+/', '', $raw);

        if ($map['level'] === null && preg_match('/等級|級距|級別|級$/u', $simple)) $map['level'] = $i;
        if ($map['wage_range'] === null && preg_match('/實際工資|執行業務所得|範圍|區間|級距/u', $raw)) $map['wage_range'] = $i;
        if ($map['base_amount'] === null && preg_match('/月提繳(工資)?金額|月提繳執行業務所得金額|基數|本薪|本俸/u', $raw)) $map['base_amount'] = $i;
        if ($map['effective_date'] === null && preg_match('/生效日|實施日|適用日|發布日|日期/u', $raw)) $map['effective_date'] = $i;

        if ($map['wage_min'] === null && preg_match('/(薪資|工資)?(下限|min)/iu', $raw)) $map['wage_min'] = $i;
        if ($map['wage_max'] === null && preg_match('/(薪資|工資)?(上限|max)/iu', $raw)) $map['wage_max'] = $i;
    }
    return $map;
}

function parse_int($v): ?int {
    if ($v === null) return null;
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace(',', '', $v);
    if (!preg_match('/^-?\d+$/', $v)) return null;
    return (int)$v;
}

function parse_money($v, $default = null): ?float {
    if ($v === null) return $default;
    $s = trim((string)$v);
    if ($s === '') return $default;
    $s = preg_replace('/[^\d\.\-]/', '', $s);
    if ($s === '' || !is_numeric($s)) return $default;
    return round((float)$s, 2);
}

function parse_wage_range(string $s): array {
    // 支援： "1500以下" → [null,1500]; "30000以上" → [30000,null];
    //       "1501至3000"、"23100-24000"、"23100～24000" → [min,max]
    $s = trim($s);
    if ($s === '') return [null, null];
    $s = str_replace(['～', '—', '–'], '-', $s);

    if (preg_match('/^([\d,\.]+)\s*以下$/u', $s, $m)) {
        $max = parse_money($m[1], null);
        return [null, $max];
    }
    if (preg_match('/^([\d,\.]+)\s*以上$/u', $s, $m)) {
        $min = parse_money($m[1], null);
        return [$min, null];
    }
    if (preg_match('/^([\d,\.]+)\s*(?:至|-)\s*([\d,\.]+)$/u', $s, $m)) {
        $min = parse_money($m[1], null);
        $max = parse_money($m[2], null);
        return [$min, $max];
    }
    $v = parse_money($s, null);
    return [$v, $v];
}

function parse_date_roc_or_gregorian(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;

    // 民國 7 碼（例如 1140101 → 2025-01-01）
    if (preg_match('/^(\d{3})(\d{2})(\d{2})$/', $s, $m)) {
        $y = (int)$m[1] + 1911;
        return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3]);
    }
    // 民國 yyy/mm/dd
    if (preg_match('/^(\d{2,3})[\/\.\-](\d{1,2})[\/\.\-](\d{1,2})$/', $s, $m)) {
        $y = (int)$m[1]; if ($y < 1911) $y += 1911;
        return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3]);
    }
    // 西元 YYYY-MM-DD / YYYY/MM/DD
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    // 西元 YYYYMMDD
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    // 西元 YYYY-MM / YYYY/MM
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]);
    }
    return null;
}
