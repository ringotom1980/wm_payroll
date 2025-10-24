<?php
// Public/api/opendata/gov_labor_pension.php
// 政府開放資料：勞退分級表（REST JSON 版 + 欄位容錯 + 區間解析）

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';

$table    = 'gov_labor_pension';
$tmpTable = 'gov_labor_pension_tmp';
// MOL OdService REST Datastore（可依實際需要調整 resource id）
$url = 'https://apiservice.mol.gov.tw/OdService/rest/datastore/A17000000J-020031-76z';

function fetch_raw(string $url): ?string {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => 30,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_HTTPHEADER     => ['Accept: application/json'],
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

function coalesce(...$xs) {
  foreach ($xs as $v) {
    if (isset($v) && $v !== '') return $v;
  }
  return null;
}

function parse_range_to_minmax(?string $s): array {
  if (!$s) return [null, null];
  $t = preg_replace('/[^\d\-~–—～]/u', '', $s);
  $t = str_replace(['～','–','—','~'], '-', $t);
  $parts = array_values(array_filter(explode('-', $t), 'strlen'));
  if (count($parts) === 2) return [0 + $parts[0], 0 + $parts[1]];
  if (count($parts) === 1) { $n = 0 + $parts[0]; return [$n, $n]; }
  return [null, null];
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $mode = $_GET['mode'] ?? 'status';

  if ($mode === 'status') {
    $stmt = $pdo->query("SELECT COUNT(*) AS cnt, MAX(effective_date) AS latest_date, MAX(created_at) AS updated_at FROM {$table}");
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC)); exit;
  }

  if ($mode === 'refresh' || $mode === 'refresh_tmp') {
    $raw = fetch_raw($url);
    if (!$raw) throw new Exception('下載失敗');
    $j = json_decode($raw, true);
    $rows = $j['result']['records'] ?? null;
    if (!is_array($rows)) throw new Exception('解析 JSON 失敗（無 records）');

    $target = $table;
    $pdo->beginTransaction();
    try {
      if ($mode === 'refresh') {
        $pdo->exec("TRUNCATE TABLE {$table}");
        $target = $table;
      } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$tmpTable} LIKE {$table}");
        $pdo->exec("TRUNCATE TABLE {$tmpTable}");
        $target = $tmpTable;
      }

      $sql = "INSERT INTO {$target}
              (level_no, wage_min, wage_max, base_amount, effective_date)
              VALUES (:level_no, :wage_min, :wage_max, :base_amount, :effective_date)";
      $stmt = $pdo->prepare($sql);

      $n = 0;
      foreach ($rows as $r) {
        $level = coalesce($r['等級'] ?? null, $r['級距'] ?? null);

        // 勞退常見：一個區間欄位（實際工資/執行業務所得）
        $rangeStr = coalesce(
          $r['實際工資/執行業務所得'] ?? null,
          $r['實際工資'] ?? null,
          $r['實際薪資'] ?? null,
          $r['薪資範圍'] ?? null
        );
        [$wmin, $wmax] = parse_range_to_minmax(is_string($rangeStr) ? $rangeStr : (string)$rangeStr);

        $base = coalesce(
          $r['月提繳工資金額/月提繳執行業務所得金額'] ?? null,
          $r['月提繳工資'] ?? null,
          $r['月提繳工資金額'] ?? null,
          $r['月提繳執行業務所得金額'] ?? null
        );

        $eff = coalesce($r['生效日'] ?? null, $r['生效日期'] ?? null);

        $stmt->execute([
          ':level_no'      => $level,
          ':wage_min'      => $wmin,
          ':wage_max'      => $wmax,
          ':base_amount'   => $base,
          ':effective_date'=> $eff,
        ]);
        $n++;
      }

      $pdo->commit();
      echo json_encode(['ok'=>true,'target'=>$target,'count'=>$n], JSON_UNESCAPED_UNICODE); exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }

  throw new Exception('未知的 mode');
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
