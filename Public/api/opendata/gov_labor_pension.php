<?php
// Public/api/opendata/gov_labor_pension.php
// 勞退提繳分級（CSV 版）— 由 data.gov.tw metadata 取 CSV → 匯入
// datasetId: 6274
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$datasetId = 6274;
$table     = 'gov_labor_pension';
$tmpTable  = 'gov_labor_pension_tmp';

$mode = $_GET['mode'] ?? 'sync';

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../../config/db.php';
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($mode === 'status') {
        $stmt = $pdo->query("SELECT COUNT(*) cnt, MAX(effective_date) latest_date, MAX(created_at) updated_at FROM `{$table}`");
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        echo json_encode([
            'ok' => true,
            'cnt' => (int)($r['cnt'] ?? 0),
            'latest_date' => $r['latest_date'] ?: null,
            'updated_at'  => $r['updated_at'] ?: null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- sync ----
    $metaUrl = "https://data.gov.tw/api/v1/dataset/{$datasetId}";
    $ctx = stream_context_create([
        'http' => ['timeout' => 15, 'header' => "Accept: application/json\r\nUser-Agent: wm-payroll/1.0\r\n"]
    ]);
    $meta = json_decode(@file_get_contents($metaUrl, false, $ctx), true);
    if (!$meta || empty($meta['resources'])) throw new Exception('讀取 metadata 失敗或無資源');

    $csvRes = null;
    foreach ($meta['resources'] as $r) {
        if (isset($r['format']) && strcasecmp($r['format'], 'CSV') === 0 && !empty($r['url'])) {
            $csvRes = $r; break;
        }
    }
    if (!$csvRes) throw new Exception('找不到 CSV 資源');
    $csvUrl = $csvRes['url'];
    $updated = $csvRes['updated'] ?? null;

    // 下載 CSV
    $raw = @file_get_contents($csvUrl, false, stream_context_create(['http'=>['timeout'=>30,'header'=>"User-Agent: wm-payroll/1.0\r\n"]]));
    if ($raw === false) throw new Exception("下載失敗：$csvUrl");
    $raw = mb_convert_encoding($raw, 'UTF-8', 'auto');

    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $headers = fgetcsv($fh);
    if (!$headers) throw new Exception('CSV 無標題列');

    $idx = ['level' => -1, 'range' => -1, 'wage' => -1, 'date' => -1];
    foreach ($headers as $i => $h) {
        $h = trim((string)$h);
        if ($idx['level'] < 0 && (mb_strpos($h, '等級') !== false || mb_strpos($h, '級') !== false)) $idx['level'] = $i;
        if ($idx['range'] < 0 && (mb_strpos($h, '實際工資') !== false || mb_strpos($h, '區間') !== false || mb_strpos($h, '級距') !== false)) $idx['range'] = $i;
        if ($idx['wage']  < 0 && (mb_strpos($h, '月提繳工資') !== false || mb_strpos($h, '提繳工資') !== false)) $idx['wage']  = $i;
        if ($idx['date']  < 0 && (mb_strpos($h, '生效') !== false || mb_strpos($h, '日期') !== false)) $idx['date']  = $i;
    }
    if ($idx['range'] < 0 || $idx['wage'] < 0) throw new Exception('CSV 欄位無法對應（需要：實際工資/區間、月提繳工資）');

    $pdo->exec("TRUNCATE TABLE `{$tmpTable}`");
    $ins = $pdo->prepare("
        INSERT INTO `{$tmpTable}` (level_no, wage_min, wage_max, base_amount, effective_date)
        VALUES (:lv, :min, :max, :base, :eff)
    ");

    $n = 0;
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < max($idx) + 1) continue;

        $lv   = $idx['level'] >= 0 ? trim((string)$row[$idx['level']]) : '';
        $range= trim((string)$row[$idx['range']]);
        $wage = trim((string)$row[$idx['wage']]);
        $date = $idx['date'] >= 0 ? trim((string)($row[$idx['date']] ?? '')) : '';

        $from = null; $to = null;
        if (preg_match('/(\d+)\s*[~至-]\s*(\d+)/u', $range, $m)) {
            $from = (float)$m[1]; $to = (float)$m[2];
        } elseif (preg_match('/(\d+)\s*元\s*以上/u', $range, $m)) {
            $from = (float)$m[1]; $to = null;
        } else {
            $v = preg_replace('/[^\d]/', '', $range);
            $from = $to = $v !== '' ? (float)$v : null;
        }

        $base = (float)preg_replace('/[^\d]/', '', $wage);
        if ($base <= 0) continue;

        $eff = null;
        if ($updated) {
            $u = substr($updated, 0, 10);
            if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $u, $mm)) {
                $eff = sprintf('%s-%s-01', $mm[1], $mm[2]);
            }
        } elseif (preg_match('/^(\d{3,4})[\/\-\.](\d{1,2})/u', $date, $mm)) {
            $yy = (int)$mm[1]; $mn = (int)$mm[2];
            if ($yy < 1911) $yy += 1911;
            $eff = sprintf('%04d-%02d-01', $yy, $mn);
        }

        $ins->execute([
            ':lv'   => (int)preg_replace('/\D/', '', $lv),
            ':min'  => $from,
            ':max'  => $to,
            ':base' => $base,
            ':eff'  => $eff
        ]);
        $n++;
    }
    fclose($fh);

    $pdo->beginTransaction();
    $pdo->exec("TRUNCATE TABLE `{$table}`");
    $pdo->exec("
        INSERT INTO `{$table}` (level_no, wage_min, wage_max, base_amount, effective_date)
        SELECT level_no, wage_min, wage_max, base_amount, effective_date
        FROM `{$tmpTable}`
    ");
    $pdo->commit();

    echo json_encode(['ok' => true, 'count' => $n, 'source' => $csvUrl, 'updated' => $updated], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
