<?php
// Public/api/opendata/gov_nhi.php
// 健保投保金額分級表（自動偵測最新 resource_id）

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';

$table = 'gov_nhi';
$tmpTable = 'gov_nhi_tmp';

// ---- Helper ----
function fetch_json($url){
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
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

function latest_nhi_identifier() {
  // 依健保署開放資料 API，抓取最新版本的「投保金額分級表」
  $dsUrl = 'https://info.nhi.gov.tw/api/iode0010/v1/rest/dataset?limit=200&offset=0';
  $ds = fetch_json($dsUrl);
  if (!isset($ds['result']['records']) || !is_array($ds['result']['records'])) {
    throw new Exception('取得資料集清單失敗');
  }

  $candidates = [];
  foreach ($ds['result']['records'] as $rec) {
    $id  = $rec['identifier'] ?? '';
    $ttl = ($rec['title'] ?? '') . ' ' . ($rec['notes'] ?? '') . ' ' . ($rec['description'] ?? '');
    // ✅ 僅挑出 group_code=B1000A 且標題含「投保金額分級表」
    if (strpos($id, 'A21030000I-B1000A-') === 0 && mb_strpos($ttl, '投保金額分級表') !== false) {
      $modified = $rec['modified'] ?? $rec['metadata_modified'] ?? '';
      $candidates[] = ['identifier' => $id, 'modified' => $modified];
    }
  }

  if (empty($candidates)) {
    // 若沒有找到任何符合項目，回退到目前 114 年版
    return 'A21030000I-B1000A-00F';
  }

  // 依修改時間由新到舊排序，取最新的 identifier
  usort($candidates, fn($a, $b) => strcmp($b['modified'] ?? '', $a['modified'] ?? ''));
  return $candidates[0]['identifier'];
}


function parse_group_code($identifier){
  if (preg_match('/^[A-Z0-9]+\-([A-Z0-9]+)\-\w+$/', $identifier, $m)) return $m[1];
  return '';
}

// ---- Main ----
try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $mode = $_GET['mode'] ?? 'status';

  if ($mode === 'status') {
    $stmt = $pdo->query("SELECT COUNT(*) cnt, MAX(effective_date) latest_date, MAX(created_at) updated_at FROM {$table}");
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC)); exit;
  }

  if ($mode === 'refresh' || $mode === 'refresh_tmp') {
    $identifier = latest_nhi_identifier();
    $groupCode  = parse_group_code($identifier);
    $dataUrl    = "https://info.nhi.gov.tw/api/iode0000s01/Dataset?rId={$identifier}";
    $data = fetch_json($dataUrl);
    if (!isset($data['result']['records']) || !is_array($data['result']['records'])) {
      throw new Exception('取得健保資料失敗');
    }
    $rows = $data['result']['records'];

    $pdo->beginTransaction();

    if ($mode === 'refresh') {
      $pdo->exec("TRUNCATE TABLE {$table}");
      $target = $table;
    } else {
      $pdo->exec("CREATE TABLE IF NOT EXISTS {$tmpTable} LIKE {$table}");
      $pdo->exec("TRUNCATE TABLE {$tmpTable}");
      $target = $tmpTable;
    }

    $sql = "INSERT INTO {$target}
            (level_no, wage_min, wage_max, base_amount, group_code, resource_id, effective_date)
            VALUES (:level_no,:wage_min,:wage_max,:base_amount,:group_code,:resource_id,:effective_date)";
    $stmt = $pdo->prepare($sql);

    $n = 0;
    foreach ($rows as $r) {
      $effective = $r['生效日期'] ?? $r['實施日期'] ?? null;
      $stmt->execute([
        ':level_no'     => $r['等級'] ?? null,
        ':wage_min'     => $r['實際薪資下限'] ?? null,
        ':wage_max'     => $r['實際薪資上限'] ?? null,
        ':base_amount'  => $r['月投保金額'] ?? null,
        ':group_code'   => $groupCode,
        ':resource_id'  => $identifier,
        ':effective_date'=> $effective,
      ]);
      $n++;
    }
    $pdo->commit();

    echo json_encode([
      'ok'=>true,
      'target'=>$target,
      'count'=>$n,
      'resource_id'=>$identifier,
      'group_code'=>$groupCode
    ], JSON_UNESCAPED_UNICODE); exit;
  }

  throw new Exception('未知的 mode');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
