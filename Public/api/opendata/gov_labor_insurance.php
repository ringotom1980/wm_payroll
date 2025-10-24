<?php
// Public/api/opendata/gov_labor_insurance.php
// 勞保投保薪資分級表：CSV 下載 → 清空 → 寫入 gov_labor_insurance
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

const SRC_URL = 'https://apiservice.mol.gov.tw/OdService/download/A17000000J-020014-Uy8';
$table = 'gov_labor_insurance';

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../../config/db.php';
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('DB 連線失敗：config/db.php 未回傳 PDO');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1) 下載 CSV
    $raw = fetch_raw(SRC_URL);
    if (!$raw) throw new RuntimeException('下載失敗或空白內容');
    $raw = normalize_encoding($raw);

    // 2) 解析 CSV
    [$headers, $rows] = parse_csv($raw);
    if (!$headers) throw new RuntimeException('CSV 標題列解析失敗');

    // 3) 欄位對應（硬對應 + 模糊備援）
    $map = map_columns_strict_then_fuzzy($headers);

    // 必要欄位：等級 or 序號、月薪資總額(做區間) 或 上下限、月投保薪資(基數)、適用起日、身分別(分類)
    if ($map['level_no'] === null) throw new RuntimeException('找不到 投保薪資等級/序號 欄');
    if ($map['wage_range'] === null && !($map['wage_min'] !== null && $map['wage_max'] !== null)) {
        throw new RuntimeException('找不到 月薪資總額（或上下限）欄');
    }
    if ($map['base_amount'] === null) throw new RuntimeException('找不到 月投保薪資 欄');
    if ($map['effective_date'] === null) throw new RuntimeException('找不到 適用起日 欄');
    if ($map['category'] === null) throw new RuntimeException('找不到 身分別 欄');

    // 4) 轉資料
    $data = [];
    foreach ($rows as $r) {
        $lvl   = parse_int($r[$map['level_no']] ?? null);
        $cat   = trim((string)($r[$map['category']] ?? '')) ?: null;

        // 區間
        $wmin = null; $wmax = null;
        if ($map['wage_range'] !== null) {
            [$wmin, $wmax] = parse_wage_range((string)($r[$map['wage_range']] ?? ''));
        }
        if ($map['wage_min'] !== null) $wmin = parse_money($r[$map['wage_min']] ?? null, $wmin);
        if ($map['wage_max'] !== null) $wmax = parse_money($r[$map['wage_max']] ?? null, $wmax);

        $base  = parse_money($r[$map['base_amount']] ?? null, null);
        $ed    = parse_date_roc_or_gregorian((string)($r[$map['effective_date']] ?? ''));

        // 跳關鍵缺值
        if ($lvl === null || ($wmin === null && $wmax === null) || $base === null) continue;

        $data[] = [
            'level_no'       => $lvl,
            'wage_min'       => $wmin,
            'wage_max'       => $wmax,
            'base_amount'    => $base,
            'category'       => $cat,
            'effective_date' => $ed,
        ];
    }
    if (!$data) throw new RuntimeException('CSV 內容未解析出有效資料列');

    // 5) 清空（TRUNCATE 會隱含提交 → 放交易外）
    $pdo->exec("TRUNCATE TABLE `{$table}`");

    // 6) 寫入（放在交易內）
    $pdo->beginTransaction();
    $sql = "INSERT INTO `{$table}` 
        (level_no, wage_min, wage_max, base_amount, category, effective_date)
        VALUES (:level_no, :wage_min, :wage_max, :base_amount, :category, :effective_date)";
    $stmt = $pdo->prepare($sql);

    $inserted = 0;
    foreach ($data as $row) {
        $stmt->execute([
            ':level_no'       => $row['level_no'],
            ':wage_min'       => $row['wage_min'],
            ':wage_max'       => $row['wage_max'],
            ':base_amount'    => $row['base_amount'],
            ':category'       => $row['category'],
            ':effective_date' => $row['effective_date'],
        ]);
        $inserted++;
    }
    $pdo->commit();

    echo json_encode([
        'ok' => 1,
        'inserted' => $inserted,
        'message' => "{$table} 已重載完成",
        'headers' => $headers,
        'headers_detected' => $map,
        'sample' => $data[0] ?? null,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/* ------------------ Helpers ------------------ */

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
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) return $resp;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 60, 'header' => "User-Agent: wm_payroll-opendata-fetcher/1.0\r\n"]
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return ($resp !== false) ? $resp : null;
}

function normalize_encoding(string $s): string {
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") $s = substr($s, 3);
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

    $headers = null; $rows = [];
    while (($cols = fgetcsv($fp, 0, ',')) !== false) {
        if (count(array_filter($cols, fn($v) => trim((string)$v) !== '')) === 0) continue;
        if ($headers === null) { $headers = array_map('trim', $cols); continue; }
        $row = [];
        foreach ($headers as $i => $h) $row[$i] = $cols[$i] ?? null;
        $rows[] = $row;
    }
    fclose($fp);
    return [$headers ?? [], $rows];
}

/** 勞保 6258 欄位對應 */
function map_columns_strict_then_fuzzy(array $headers): array {
    $map = [
        'effective_date' => null, // 適用起日
        'level_no'       => null, // 投保薪資等級 / 序號
        'category'       => null, // 身分別
        'wage_range'     => null, // 月薪資總額（解析區間）
        'wage_min'       => null, // 若官方改為上下限
        'wage_max'       => null,
        'base_amount'    => null, // 月投保薪資
    ];

    // 精確對應
    foreach ($headers as $i => $raw0) {
        $raw = trim((string)$raw0);
        if ($raw === '適用起日') $map['effective_date'] = $i;
        if ($raw === '序號' || $raw === '投保薪資等級') $map['level_no'] = $i;
        if ($raw === '身分別') $map['category'] = $i;
        if ($raw === '月薪資總額') $map['wage_range'] = $i;
        if ($raw === '月投保薪資') $map['base_amount'] = $i;
        if ($raw === '下限') $map['wage_min'] = $i;
        if ($raw === '上限') $map['wage_max'] = $i;
    }

    // 模糊備援
    foreach ($headers as $i => $h) {
        $raw = trim((string)$h);
        $simple = preg_replace('/\s+/', '', $raw);

        if ($map['effective_date'] === null && preg_match('/適用起日|生效日|實施日|日期/u', $raw)) $map['effective_date'] = $i;
        if ($map['level_no'] === null && preg_match('/(序號|等級|級距|投保薪資等級)/u', $raw)) $map['level_no'] = $i;
        if ($map['category'] === null && preg_match('/身分別|身分類別|類別/u', $raw)) $map['category'] = $i;
        if ($map['wage_range'] === null && preg_match('/月薪資總額|薪資總額|工資總額|月工資/u', $raw)) $map['wage_range'] = $i;
        if ($map['base_amount'] === null && preg_match('/月投保薪資|投保薪資/u', $raw)) $map['base_amount'] = $i;

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

/** 解析「月薪資總額」成區間：以下/以上/至/—/～/單值 */
function parse_wage_range(string $s): array {
    // 支援多種寫法與尾綴：例「28,591至28,800元」「43901以上」「6000以下」「23100～24000」「23100-24000」
    $s = trim($s);
    if ($s === '') return [null, null];

    // 標準化分隔符
    $s = str_replace(['～', '—', '–'], '-', $s);

    // 取出字串裡「所有數字」（允許小數，但通常沒有）
    $nums = [];
    if (preg_match_all('/\d+(?:\.\d+)?/', $s, $m)) {
        $nums = array_map(function ($v) {
            // 去掉千分位殘留（若來源已經含逗號，preg_match_all 就不會抓到逗號；這裡保險）
            $v = str_replace(',', '', $v);
            return (float)$v;
        }, $m[0]);
    }

    // 關鍵字判斷
    $hasBelow = mb_strpos($s, '以下') !== false; // 只有上限
    $hasAbove = mb_strpos($s, '以上') !== false; // 只有下限

    if ($hasBelow && isset($nums[0])) {
        return [null, round($nums[0], 2)];
    }
    if ($hasAbove && isset($nums[0])) {
        return [round($nums[0], 2), null];
    }

    // 雙邊區間（優先採前兩個數）
    if (count($nums) >= 2) {
        return [round($nums[0], 2), round($nums[1], 2)];
    }

    // 單一數值（當成單點）
    if (count($nums) === 1) {
        $v = round($nums[0], 2);
        return [$v, $v];
    }

    // 都抓不到
    return [null, null];
}


/** 民國/西元 → YYYY-MM-DD */
function parse_date_roc_or_gregorian(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;

    if (preg_match('/^(\d{3})(\d{2})(\d{2})$/', $s, $m)) { // 1140101
        $y = (int)$m[1] + 1911;
        return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^(\d{2,3})[\/\.\-](\d{1,2})[\/\.\-](\d{1,2})$/', $s, $m)) {
        $y = (int)$m[1]; if ($y < 1911) $y += 1911;
        return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]);
    }
    return null;
}
