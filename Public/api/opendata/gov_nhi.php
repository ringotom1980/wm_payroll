<?php
// Public/api/opendata/gov_nhi.php
// 健保投保金額分級：mode=status / mode=sync（抓→清空→重寫）
// 你的表結構：id, level_no, wage_min, wage_max, base_amount, group_code, resource_id, effective_date, created_at
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../config/db.php';

$table = 'gov_nhi';
// 常用 NHI OpenData；實際欄位名稱會因資源不同而異，下面做了多名稱容錯
$url   = 'https://data.nhi.gov.tw/resource/Nhi_TL_130?format=json';

function fetch_raw(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'wm_payroll-fetch/1.0',
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 400 || $res === false) ? null : $res;
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
    if (!$s) return [null, null];
    $t = str_replace([',', '，', ' '], '', trim((string)$s));
    $t = str_replace(['至', '到', '～', '〜', '–', '—', '－', '~'], '-', $t);
    $t = preg_replace('/[^\d\-]/u', '', $t);
    $p = array_values(array_filter(explode('-', $t), 'strlen'));
    if (count($p) >= 2) {
        $a = (int)$p[0];
        $b = (int)$p[1];
        if ($a > 0 && $b > 0) return [$a, $b];
    }
    if (count($p) === 1) {
        $n = (int)$p[0];
        return $n > 0 ? [$n, $n] : [null, null];
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
        $p = array_values(array_filter(explode('/', $s), 'strlen'));
        if (count($p) >= 3) {
            $a = (int)$p[0];
            $b = (int)$p[1];
            $c = (int)$p[2];
            $y = ($a <= 300) ? $a + 1911 : $a;
            return checkdate($b, $c, $y) ? sprintf('%04d-%02d-%02d', $y, $b, $c) : null;
        }
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mode = $_GET['mode'] ?? 'status';

    if ($mode === 'status') {
        $st = $pdo->query("SELECT COUNT(*) cnt, MAX(effective_date) latest_date, MAX(created_at) updated_at FROM {$table}");
        echo json_encode($st->fetch(PDO::FETCH_ASSOC));
        exit;
    }

    if ($mode === 'sync') {
        $raw = fetch_raw($url);
        if (!$raw) throw new Exception('來源無回應或 HTTP 錯誤');
        $rows = json_decode($raw, true);
        if (!is_array($rows)) throw new Exception('來源 JSON 不是陣列');

        $pdo->beginTransaction();
        try {
            try {
                $pdo->exec("TRUNCATE TABLE {$table}");
            } catch (Throwable $e) {
                $pdo->exec("DELETE FROM {$table}");
            }

            $sql = "INSERT INTO {$table} (level_no,wage_min,wage_max,base_amount,group_code,resource_id,effective_date)
            VALUES (:level_no,:wmin,:wmax,:base,:gcode,:rid,:eff)";
            $ins = $pdo->prepare($sql);
            $n = 0;

            foreach ($rows as $r) {
                // 各資料集欄位名稱差異很大，這裡盡量廣義容錯
                $level = coalesce($r['等級'] ?? null, $r['投保金額等級'] ?? null, $r['投保等級'] ?? null, $r['級距'] ?? null);
                $base = coalesce($r['投保金額'] ?? null, $r['保險金額'] ?? null, $r['月投保金額'] ?? null, $r['金額'] ?? null);
                $range = coalesce($r['薪資範圍'] ?? null, $r['適用薪資'] ?? null, $r['月薪資總額'] ?? null, $r['實際薪資'] ?? null);
                [$wmin, $wmax] = parse_range_to_minmax(is_string($range) ? $range : (string)$range);

                if (is_string($base)) $base = str_replace([',', '，', ' '], '', $base);
                $base = is_numeric($base) ? (float)$base : null;

                $gcode = coalesce($r['類別代碼'] ?? null, $r['被保險人類別代碼'] ?? null, $r['group_code'] ?? null, $r['類別'] ?? null);
                $rid  = coalesce($r['resource_id'] ?? null, $r['資料資源代碼'] ?? null, $r['來源代碼'] ?? null);
                $eff  = normalize_date(coalesce($r['生效日'] ?? null, $r['生效日期'] ?? null, $r['實施日期'] ?? null, $r['effective_date'] ?? null));

                if ($level === null || $base === null) continue;

                $ins->execute([
                    ':level_no' => (int)$level,
                    ':wmin' => $wmin,
                    ':wmax' => $wmax,
                    ':base' => $base,
                    ':gcode' => $gcode,
                    ':rid' => $rid,
                    ':eff' => $eff
                ]);
                $n++;
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'inserted' => $n], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    throw new Exception('unknown mode');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
