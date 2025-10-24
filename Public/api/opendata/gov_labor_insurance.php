<?php
// Public/api/opendata/gov_labor_insurance.php
// 勞保投保薪資分級表（REST JSON + 欄位容錯 + 區間解析 + 民國年轉換）
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../config/db.php';

$table    = 'gov_labor_insurance';
$tmpTable = 'gov_labor_insurance_tmp';
$url      = 'https://apiservice.mol.gov.tw/OdService/rest/datastore/A17000000J-020014-q8B';

function fetch_raw(string $url): ?string
{ /* 同上 */
    $ch = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'wm_payroll-fetch/1.0',
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400 || $res === false) return null;
        return $res;
    }
    $res = @file_get_contents($url);
    return $res === false ? null : $res;
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
    $t = $s;
    if (!$t) return [null, null];
    $t = preg_replace('/[^\d\-~–—～]/u', '', $t);
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
{ /* 同上完整版本 */
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    $s = preg_replace('/[年月\.]/u', '/', $s);
    $s = str_replace(['－', '—', '–', '．', '。', '-', '.'], '/', $s);
    $s = str_replace(['日'], '', $s);
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

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mode = $_GET['mode'] ?? 'status';

    if ($mode === 'status') {
        $stmt = $pdo->query("SELECT COUNT(*) cnt, MAX(effective_date) latest_date, MAX(created_at) updated_at FROM {$table}");
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }

    if ($mode === 'refresh' || $mode === 'refresh_tmp') {
        $raw = fetch_raw($url);
        if (!$raw) throw new Exception('下載失敗');
        $j = json_decode($raw, true);
        $rows = $j['result']['records'] ?? null;
        if (!is_array($rows)) throw new Exception('解析 JSON 失敗（無 records）');

        $target = $mode === 'refresh' ? $table : $tmpTable;
        $pdo->beginTransaction();
        try {
            if ($mode === 'refresh') {
                $pdo->exec("TRUNCATE TABLE {$table}");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS {$tmpTable} LIKE {$table}");
                $pdo->exec("TRUNCATE TABLE {$tmpTable}");
            }

            $sql = "INSERT INTO {$target} (level_no,wage_min,wage_max,base_amount,category,effective_date)
            VALUES (:level_no,:wage_min,:wage_max,:base_amount,:category,:effective_date)";
            $stmt = $pdo->prepare($sql);

            $n = 0;
            foreach ($rows as $r) {
                $level = coalesce($r['投保薪資等級'] ?? null, $r['等級'] ?? null, $r['級距'] ?? null);
                $range = coalesce($r['月薪資總額'] ?? null, $r['實際薪資'] ?? null, $r['薪資範圍'] ?? null);
                [$wmin, $wmax] = parse_range_to_minmax(is_string($range) ? $range : (string)$range);
                $base  = coalesce($r['月投保薪資'] ?? null, $r['月投保金額'] ?? null, $r['投保金額'] ?? null);
                $cat   = coalesce($r['身分別'] ?? null, $r['投保類別'] ?? null, '一般勞工');
                $eff   = normalize_date(coalesce($r['生效日'] ?? null, $r['生效日期'] ?? null, $r['實施日期'] ?? null));

                $stmt->execute([
                    ':level_no' => $level,
                    ':wage_min' => $wmin,
                    ':wage_max' => $wmax,
                    ':base_amount' => $base,
                    ':category' => $cat,
                    ':effective_date' => $eff
                ]);
                $n++;
            }
            $pdo->commit();
            echo json_encode(['ok' => true, 'target' => $target, 'count' => $n], JSON_UNESCAPED_UNICODE);
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
