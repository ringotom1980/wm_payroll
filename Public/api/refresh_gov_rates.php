<?php
// Public/api/refresh_gov_rates.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// 若此檔原本有登入驗證就保留，沒有就略過
// require __DIR__ . '/../../config/auth.php';
// if (!is_logged_in()) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Unauthorized']); exit; }

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../config/db.php';
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB 連線失敗: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

const URL_LI  = 'https://apiservice.mol.gov.tw/OdService/download/A17000000J-020014-rpF'; // 勞保
const URL_LP  = 'https://apiservice.mol.gov.tw/OdService/download/A17000000J-020031-8So'; // 勞退
const URL_NHI = 'https://info.nhi.gov.tw/api/iode0000s01/Dataset?rId=A21030000I-B1000A-00B'; // 健保（固定到 00B；要改再說）

function fetch_json(string $url, int $timeout=15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => ['Accept: application/json; charset=utf-8'],
        CURLOPT_USERAGENT => 'wm_payroll/1.0',
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException("抓取失敗: $url ($err)");
    if ($code < 200 || $code >= 300) throw new RuntimeException("來源HTTP狀態: $code ($url)");
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("JSON 解析失敗: $url");
    return $data;
}

// 解析「1501至3000 / 1500以下 / 147901以上」→ [min,max?]
function parse_range(string $s): array {
    $s = preg_replace('/[^\d至以上以下\-]/u', '', (string)$s);
    if ($s === '') return [0, null];
    if (mb_strpos($s, '以下') !== false) { $n=(int)preg_replace('/\D/','',$s); return [0,$n]; }
    if (mb_strpos($s, '以上') !== false) { $n=(int)preg_replace('/\D/','',$s); return [$n,null]; }
    if (mb_strpos($s, '至') !== false) {
        [$a,$b] = explode('至',$s,2);
        return [(int)preg_replace('/\D/','',$a),(int)preg_replace('/\D/','',$b)];
    }
    $n = (int)preg_replace('/\D/','',$s);
    return [$n,$n];
}

// 民國/西元常見格式 → YYYY-MM-DD；抓不到回 null
function to_date(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    if (preg_match('/^\d{7}$/', $s)) { // 民國 yyyMMdd
        $y=(int)substr($s,0,3)+1911; $m=(int)substr($s,3,2); $d=(int)substr($s,5,2);
        return sprintf('%04d-%02d-%02d',$y,$m,$d);
    }
    if (preg_match('/^\d{8}$/', $s)) { // 西元 yyyymmdd
        $y=(int)substr($s,0,4); $m=(int)substr($s,4,2); $d=(int)substr($s,6,2);
        return sprintf('%04d-%02d-%02d',$y,$m,$d);
    }
    if (preg_match('/^(\d{3,4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $s, $m)) {
        $y=(int)$m[1]; if ($y<1911) $y+=1911;
        return sprintf('%04d-%02d-%02d',(int)$y,(int)$m[2],(int)$m[3]);
    }
    $ts = strtotime($s);
    return $ts? date('Y-m-d',$ts): null;
}

$done = [];
$errs = [];

try {
    // 勞保
    $data = fetch_json(URL_LI);
    $pdo->exec("TRUNCATE TABLE gov_labor_insurance");
    $ins = $pdo->prepare("INSERT INTO gov_labor_insurance(level_no,wage_min,wage_max,base_amount,category,effective_date) VALUES (?,?,?,?,?,?)");
    $eff = null;
    foreach ($data as $r) {
        $lvl  = $r['投保薪資等級'] ?? $r['等級'] ?? null;
        $rng  = $r['月薪資總額']   ?? $r['實際工資'] ?? '';
        $base = $r['月投保薪資']   ?? $r['月投保(元)'] ?? null;
        $cat  = $r['身分別'] ?? $r['被保險人身分類別'] ?? $r['身分類別'] ?? null;
        $eff  = $eff ?? (to_date($r['適用起日'] ?? $r['生效日'] ?? $r['生效日期'] ?? null) ?? date('Y-m-d'));
        if (!$lvl || !$base) continue;
        [$min,$max] = parse_range((string)$rng);
        $ins->execute([(int)preg_replace('/\D/','',$lvl), $min, $max, (int)preg_replace('/\D/','',$base), $cat, $eff]);
    }
    $done[] = ['table'=>'gov_labor_insurance','rows'=>$ins->rowCount()]; // rowCount() 只有最後一次；僅作到此為止顯示
} catch (Throwable $e) {
    $errs[] = ['table'=>'gov_labor_insurance','error'=>$e->getMessage()];
}

try {
    // 勞退
    $data = fetch_json(URL_LP);
    $pdo->exec("TRUNCATE TABLE gov_labor_pension");
    $ins = $pdo->prepare("INSERT INTO gov_labor_pension(level_no,wage_min,wage_max,base_amount,effective_date) VALUES (?,?,?,?,?)");
    $eff = null;
    foreach ($data as $r) {
        $lvl  = $r['等級'] ?? null;
        $rng  = $r['實際工資/執行業務所得'] ?? $r['實際工資'] ?? '';
        $base = $r['月提繳工資金額/月提繳執行業務所得金額'] ?? $r['月投保金額'] ?? null;
        $eff  = $eff ?? (to_date($r['生效日'] ?? $r['生效日期'] ?? null) ?? date('Y-m-d'));
        if (!$lvl || !$base) continue;
        [$min,$max] = parse_range((string)$rng);
        $ins->execute([(int)preg_replace('/\D/','',$lvl), $min, $max, (int)preg_replace('/\D/','',$base), $eff]);
    }
    $done[] = ['table'=>'gov_labor_pension','rows'=>$ins->rowCount()];
} catch (Throwable $e) {
    $errs[] = ['table'=>'gov_labor_pension','error'=>$e->getMessage()];
}

try {
    // 健保（固定 00B 版本）
    $data = fetch_json(URL_NHI);
    $pdo->exec("TRUNCATE TABLE gov_nhi");
    $ins = $pdo->prepare("INSERT INTO gov_nhi(level_no,wage_min,wage_max,base_amount,effective_date) VALUES (?,?,?,?,?)");
    $eff = null;
    foreach ($data as $r) {
        $lvl  = $r['等級'] ?? $r['分級'] ?? null;
        $rng  = $r['實際薪資月額'] ?? $r['實際薪資'] ?? '';
        $base = $r['月投保金額'] ?? $r['投保金額'] ?? null;
        $eff  = $eff ?? (to_date($r['生效日'] ?? $r['生效日期'] ?? $r['實施日期'] ?? null) ?? date('Y-m-d'));
        if (!$lvl || !$base) continue;
        [$min,$max] = parse_range((string)$rng);
        $ins->execute([(int)preg_replace('/\D/','',$lvl), $min, $max, (int)preg_replace('/\D/','',$base), $eff]);
    }
    $done[] = ['table'=>'gov_nhi','rows'=>$ins->rowCount()];
} catch (Throwable $e) {
    $errs[] = ['table'=>'gov_nhi','error'=>$e->getMessage()];
}

echo json_encode(['ok'=>empty($errs), 'done'=>$done, 'errors'=>$errs], JSON_UNESCAPED_UNICODE);
