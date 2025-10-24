<?php
// Public/api/opendata/gov_labor_insurance.php
// 政府開放資料：勞保投保薪資分級表

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
$table = 'gov_labor_insurance';
$tmpTable = 'gov_labor_insurance_tmp';
$url = 'https://apiservice.mol.gov.tw/OdService/download/A17000000J-020014-rpF';

function fetch_raw(string $url): ?string {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => 30,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code >= 400 || $res === false) return null;
    return $res;
  }
  $res = @file_get_contents($url);
  return $res === false ? null : $res;
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
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new Exception('解析 JSON 失敗');

    if ($mode === 'refresh') {
      $pdo->exec("TRUNCATE TABLE {$table}");
      $sql = "INSERT INTO {$table} (level_no, wage_min, wage_max, base_amount, category, effective_date)
              VALUES (:level_no, :wage_min, :wage_max, :base_amount, :category, :effective_date)";
      $stmt = $pdo->prepare($sql);
    } else {
      // refresh_tmp → 寫入暫存表
      $pdo->exec("CREATE TABLE IF NOT EXISTS {$tmpTable} LIKE {$table}");
      $pdo->exec("TRUNCATE TABLE {$tmpTable}");
      $sql = "INSERT INTO {$tmpTable} (level_no, wage_min, wage_max, base_amount, category, effective_date)
              VALUES (:level_no, :wage_min, :wage_max, :base_amount, :category, :effective_date)";
      $stmt = $pdo->prepare($sql);
    }

    $n = 0;
    foreach ($data as $row) {
      $stmt->execute([
        ':level_no'      => $row['等級'] ?? null,
        ':wage_min'      => $row['實際薪資下限'] ?? null,
        ':wage_max'      => $row['實際薪資上限'] ?? null,
        ':base_amount'   => $row['月投保金額'] ?? null,
        ':category'      => $row['投保類別'] ?? '一般勞工',
        ':effective_date'=> $row['生效日期'] ?? null,
      ]);
      $n++;
    }

    echo json_encode([
      'ok'     => true,
      'target' => $mode === 'refresh' ? $table : $tmpTable,
      'count'  => $n
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  throw new Exception('未知的 mode');
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
