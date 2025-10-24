<?php
// Public/api/opendata/gov_nhi.php
// 健保投保金額分級表（自動找最新資料集 + 欄位容錯 + 區間解析 + 民國年轉換）
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../config/db.php';

$table = 'gov_nhi';
$tmpTable = 'gov_nhi_tmp';

function fetch_json($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'wm_payroll-fetch/1.0',]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400 || $res === false) return null;
        $j = json_decode($res, true);
        return $j ?: null;
    }
    $res = @file_get_contents($url);
    if ($res === false) return null;
    $j = json_decode($res, true);
    return $j ?: null;
}
function coalesce(...$xs)
{
    foreach ($xs as $v) {
        if (isset($v) && $v !== '') return $v;
    }
    return null;
}
function parse_range_to_minmax(?string $s): array
{
    if (!$s) return [null, null];
    $t = preg_replace('/[^\d\-~–—～]/u', '', $s);
    $t = str_replace(['～', '–', '—', '~'], '-', $t);
    $p = array_values(array_filter(explode('-', $t), 'strlen'));
    if (count($p) === 2) return [0 + $p[0], 0 + $p[1]];
    if (count($p) === 1) {
        $n = 0 + $p[0];
        return [$n, $n];
    }
    return [null, null];
}
function normalize_date($v): ?string
{
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    $s = preg_replace('/[年月\.]/u', '/', $s);
    $s = str_replace(['－', '—', '–', '．', '。', '-', '.'], '/', $s);
    $s = str_replace(['日'], '', '', $s);
    $s = preg_replace('/\s+/', '', $s);
    if (preg_match('/^\d{7,8}$/', $s)) {
        if (strlen($s) === 7) {
            $y = (int)substr($s, 0, 3) + 1911;
            $m = (int)substr($s, 3, 2);
            $d = (int)substr($s, 5, 2);
            return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
        }
        $y = (int)substr($s, 0, 4);
        $m = (int)substr($s, 4, 2);
        $d = (int)substr($s, 6, 2);
        if (checkdate($m, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $m, $d);
        $y3 = (int)substr($s, 0, 3) + 1911;
        $m = (int)substr($s, 3, 2);
        $d = (int)substr($s, 5, 2);
        return checkdate($m, $d, $y3) ? sprintf('%04d-%02d-%02d', $y3, $m, $d) : null;
    }
    if (strpos($s, '/') !== false) {
        $parts = array_values(array_filter(explode('/', $s), 'strlen'));
        if (count($parts) >= 3) {
            $a = (int)$parts[0];
            $b = (int)$parts[1];
            $c = (int)$parts[2];
            $y = ($a <= 300) ? $a + 1911 : $a;
            $m = $b;
            $d = $c;
            return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
        }
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}
function latest_nhi_identifier()
{
    $dsUrl = 'https://info.nhi.gov.tw/api/iode0010/v1/rest/dataset?groupCode=B1000A&limit=200&offset=0&q=' . urlencode('投保金額分級表');
    $ds = fetch_json($dsUrl);
    $recs = $ds['result']['records'] ?? null;
    if (!is_array($recs)) throw new Exception('取得資料集清單失敗');
    $cand = [];
    foreach ($recs as $rec) {
        $id = $rec['identifier'] ?? '';
        $ttl = ($rec['title'] ?? '') . ' ' . ($rec['notes'] ?? '') . ' ' . ($rec['description'] ?? '');
        if (strpos($id, 'A21030000I-B1000A-') === 0 && mb_strpos($ttl, '投保金額分級表') !== false) {
            $modified = $rec['modified'] ?? $rec['metadata_modified'] ?? '';
            $cand[] = ['identifier' => $id, 'modified' => $modified];
        }
    }
    if (!$cand) return 'A21030000I-B1000A-00F';
    usort($cand, fn($a, $b) => strcmp($b['modified'] ?? '', $a['modified'] ?? ''));
    return $cand[0]['identifier'];
}
function parse_group_code($identifier)
{
    return preg_match('/^[A-Z0-9]+\-([A-Z0-9]+)\-\w+$/', $identifier, $m) ? $m[1] : '';
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mode = $_GET['mode'] ?? 'status';

    if ($mode === 'status') {
        $stmt = $pdo->query("SELECT COUNT(*) cnt, MAX(effective_date) latest_date, MAX(created_at) updated_at FROM {$table}");
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }

    if ($mode === 'refresh' || $mode === 'refresh_tmp') {
        $identifier = latest_nhi_identifier();
        $groupCode = parse_group_code($identifier);
        $dataUrl = "https://info.nhi.gov.tw/api/iode0000s01/Dataset?rId={$identifier}";
        $data = fetch_json($dataUrl);
        if (!isset($data['result']['records']) || !is_array($data['result']['records'])) throw new Exception('取得健保資料失敗');
        $rows = $data['result']['records'];

        $target = $mode === 'refresh' ? $table : $tmpTable;
        $pdo->beginTransaction();
        try {
            if ($mode === 'refresh') {
                $pdo->exec("TRUNCATE TABLE {$table}");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS {$tmpTable} LIKE {$table}");
                $pdo->exec("TRUNCATE TABLE {$tmpTable}");
            }

            $sql = "INSERT INTO {$target}
            (level_no,wage_min,wage_max,base_amount,group_code,resource_id,effective_date)
            VALUES (:level_no,:wage_min,:wage_max,:base_amount,:group_code,:resource_id,:effective_date)";
            $stmt = $pdo->prepare($sql);

            $n = 0;
            foreach ($rows as $r) {
                $level = coalesce($r['等級'] ?? null, $r['投保等級'] ?? null);
                $range = coalesce($r['實際薪資'] ?? null, $r['薪資範圍'] ?? null, $r['實際薪資月額（元）'] ?? null);
                $wmin = $wmax = null;
                if ($range !== null && $range !== '') {
                    if (is_numeric($range)) {
                        $wmin = $wmax = 0 + $range;
                    } else {
                        [$wmin, $wmax] = parse_range_to_minmax((string)$range);
                    }
                }
                $wmin = coalesce($r['實際薪資下限'] ?? null, $wmin);
                $wmax = coalesce($r['實際薪資上限'] ?? null, $wmax);
                $base = coalesce($r['月投保金額'] ?? null, $r['投保金額'] ?? null);
                $eff  = normalize_date(coalesce($r['生效日期'] ?? null, $r['實施日期'] ?? null));

                $stmt->execute([
                    ':level_no' => $level,
                    ':wage_min' => $wmin,
                    ':wage_max' => $wmax,
                    ':base_amount' => $base,
                    ':group_code' => $groupCode,
                    ':resource_id' => $identifier,
                    ':effective_date' => $eff
                ]);
                $n++;
            }
            $pdo->commit();
            echo json_encode(['ok' => true, 'target' => $target, 'count' => $n, 'resource_id' => $identifier, 'group_code' => $groupCode], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    throw new Exception('未知的 mode');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
