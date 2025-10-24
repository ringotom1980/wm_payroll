<?php
// Public/api/opendata/gov_labor_insurance.php
// 勞保投保薪資分級表 — 自動抓取政府開放資料 API、寫入暫存表，再更新正式表
// author: 湯億林專案版本
// created: 2025-10-24

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../config/db.php';

// === 基本設定 ===
$table    = 'gov_labor_insurance';
$tmpTable = 'gov_labor_insurance_tmp';
$url      = 'https://apiservice.mol.gov.tw/OdService/rest/datastore/A17000000J-020014-q8B';

// === 抓取遠端 JSON ===
function fetch_raw(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$res) return null;
    return $res;
}

// === 主程式 ===
try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../../config/db.php';
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1️⃣ 抓取 API JSON
    $raw = fetch_raw($url);
    if (!$raw) throw new Exception('遠端 API 無回應或 HTTP 錯誤');
    $json = json_decode($raw, true);
    if (!isset($json['result']['records'])) throw new Exception('JSON 結構異常');

    $rows = $json['result']['records'];
    if (!is_array($rows) || count($rows) === 0) throw new Exception('未取得資料');

    // 2️⃣ 清空暫存表
    $pdo->exec("TRUNCATE TABLE `$tmpTable`");

    // 3️⃣ 寫入暫存表
    $ins = $pdo->prepare("
        INSERT INTO `$tmpTable` (
            grade, salary_from, salary_to, insured_salary,
            effective_date, note, src_year, src_month
        ) VALUES (
            :grade, :salary_from, :salary_to, :insured_salary,
            :effective_date, :note, :src_year, :src_month
        )
    ");

    $n = 0;
    foreach ($rows as $r) {
        // 欄位容錯處理
        $grade  = trim($r['級距'] ?? '');
        $salary = trim($r['投保薪資'] ?? '');
        $range  = trim($r['投保薪資分級區間'] ?? '');
        $date   = trim($r['生效日期'] ?? '');
        $note   = trim($r['備註'] ?? '');

        // 區間解析
        $salary_from = null;
        $salary_to   = null;
        if (preg_match('/(\d+)\s*~\s*(\d+)/u', $range, $m)) {
            $salary_from = (int)$m[1];
            $salary_to   = (int)$m[2];
        }

        // 投保薪資
        $insured_salary = is_numeric($salary) ? (int)$salary : null;

        // 民國年轉西元
        $src_year = null; $src_month = null;
        if (preg_match('/^(\d{3})[\/\-\.](\d{1,2})/', $date, $m)) {
            $src_year  = 1911 + (int)$m[1];
            $src_month = (int)$m[2];
        }
        $effective_date = ($src_year && $src_month)
            ? sprintf('%04d-%02d-01', $src_year, $src_month)
            : null;

        $ins->execute([
            ':grade' => $grade,
            ':salary_from' => $salary_from,
            ':salary_to' => $salary_to,
            ':insured_salary' => $insured_salary,
            ':effective_date' => $effective_date,
            ':note' => $note,
            ':src_year' => $src_year,
            ':src_month' => $src_month,
        ]);
        $n++;
    }

    // 4️⃣ 更新正式表
    //   - 清空正式表
    //   - 將暫存表資料搬過去
    $pdo->beginTransaction();
    $pdo->exec("TRUNCATE TABLE `$table`");
    $pdo->exec("INSERT INTO `$table` SELECT * FROM `$tmpTable`");
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'count' => $n,
        'message' => "成功更新 $table，共 $n 筆資料"
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
