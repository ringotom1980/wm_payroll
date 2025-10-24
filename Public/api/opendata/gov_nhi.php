<?php
// Public/api/opendata/gov_nhi.php
// 健保投保金額分級（CSV 版）— metadata 內若有多版本，挑 updated 最新的 CSV
// datasetId: 20251
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$datasetId = 20251;
$table     = 'gov_nhi';
$tmpTable  = 'gov_nhi_tmp';

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../../config/db.php';
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1) 取 metadata，從多個 CSV 中挑 updated 最新的
    $metaUrl = "https://data.gov.tw/api/v1/dataset/{$datasetId}";
    $meta = json_decode(file_get_contents($metaUrl), true);
    if (!$meta || empty($meta['resources'])) throw new Exception('讀取 metadata 失敗或無資源');

    $csvRes = null;
    foreach ($meta['resources'] as $r) {
        if (isset($r['format']) && strcasecmp($r['format'], 'CSV') === 0 && !empty($r['url'])) {
            if (!$csvRes) $csvRes = $r;
            else {
                $a = strtotime($csvRes['updated'] ?? '1970-01-01');
                $b = strtotime($r['updated'] ?? '1970-01-01');
                if ($b > $a) $csvRes = $r;
            }
        }
    }
    if (!$csvRes) throw new Exception('找不到 CSV 資源');
    $csvUrl = $csvRes['url'];
    $updated = $csvRes['updated'] ?? null;
    $resId   = $csvRes['resourceId'] ?? null;

    // 2) 下載 CSV
    $raw = file_get_contents($csvUrl);
    if (!$raw) throw new Exception("下載失敗：$csvUrl");
    $raw = mb_convert_encoding($raw, 'UTF-8', 'auto');

    // 3) 解析
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $headers = fgetcsv($fh);
    if (!$headers) throw new Exception('CSV 無標題列');

    // 對應欄位：等級/級距、區間、月投保金額（名稱各年度略有差異）
    $idx = ['level' => -1, 'range' => -1, 'wage' => -1, 'date' => -1, 'note' => -1];
    foreach ($headers as $i => $h) {
        $h = trim($h);
        if ($idx['level'] < 0 && (mb_strpos($h, '等級') !== false || mb_strpos($h, '級距') !== false)) $idx['level'] = $i;
        if ($idx['range'] < 0 && (mb_strpos($h, '級距') !== false || mb_strpos($h, '區間') !== false)) $idx['range'] = $i;
        if ($idx['wage']  < 0 && (mb_strpos($h, '月投保金額') !== false || mb_strpos($h, '投保金額') !== false || mb_strpos($h, '投保薪資') !== false)) $idx['wage']  = $i;
        if ($idx['date']  < 0 && (mb_strpos($h, '生效') !== false || mb_strpos($h, '日期') !== false)) $idx['date']  = $i;
        if ($idx['note']  < 0 && (mb_strpos($h, '備註') !== false)) $idx['note']  = $i;
    }
    if ($idx['range'] < 0 || $idx['wage'] < 0) throw new Exception('CSV 欄位無法對應（需要：級距/區間、月投保金額）');

    $pdo->exec("TRUNCATE TABLE `$tmpTable`");
    $ins = $pdo->prepare("
        INSERT INTO `$tmpTable`
        (level_no, wage_min, wage_max, base_amount, group_code, resource_id, effective_date)
        VALUES
        (:lv, :min, :max, :base, :grp, :rid, :eff)
    ");

    $n = 0;
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < max($idx) + 1) continue;

        $lv    = $idx['level'] >= 0 ? trim((string)$row[$idx['level']]) : '';
        $range = trim((string)$row[$idx['range']]);
        $sal   = trim((string)$row[$idx['wage']]);
        $date  = $idx['date'] >= 0 ? trim((string)($row[$idx['date']] ?? '')) : '';
        // $note 不寫入（這張表沒有 category 欄）

        $from = null; $to = null;
        if (preg_match('/(\d+)\s*[~至-]\s*(\d+)/u', $range, $m)) {
            $from = (float)$m[1]; $to = (float)$m[2];
        } elseif (preg_match('/(\d+)\s*元\s*以上/u', $range, $m)) {
            $from = (float)$m[1]; $to = null;
        } else {
            $v = preg_replace('/[^\d]/', '', $range);
            $from = $to = $v !== '' ? (float)$v : null;
        }

        $base = (float)preg_replace('/[^\d]/', '', $sal);
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
            ':grp'  => null,     // 原始 CSV 無群組碼 → 先 NULL
            ':rid'  => $resId,   // 來自 metadata 的 resourceId（若有）
            ':eff'  => $eff
        ]);
        $n++;
    }
    fclose($fh);

    $pdo->beginTransaction();
    $pdo->exec("TRUNCATE TABLE `$table`");
    $pdo->exec("
        INSERT INTO `$table` (level_no, wage_min, wage_max, base_amount, group_code, resource_id, effective_date)
        SELECT level_no, wage_min, wage_max, base_amount, group_code, resource_id, effective_date
        FROM `$tmpTable`
    ");
    $pdo->commit();

    echo json_encode(['ok' => true, 'count' => $n, 'source' => $csvUrl, 'updated' => $updated], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
