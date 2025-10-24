<?php
// Public/api/opendata/gov_nhi.php
// 健保投保金額分級表（自動偵測最新 resource_id + 欄位容錯 + 區間解析）

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';

$table    = 'gov_nhi';
$tmpTable = 'gov_nhi_tmp';

function fetch_json($url){
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
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

function latest_nhi_identifier() {
  // 只抓 B1000A 群組，關鍵字「投保金額分級表」，取修改時間最新
  $dsUrl = 'https://info.nhi.gov.tw/api/iode0010/v1/rest/dataset?groupCode=B1000A&limit=200&offset=0&q=' . urlencode('投保金額分級表');
  $ds = fetch_json($dsUrl);
  $records = $ds['result']['records'] ?? null;
  if (!is_array($records)) throw new Exception('取得資料集清單失敗');

  $candidates = [];
  foreach ($records as $rec) {
    $id  = $rec['identifier'] ?? '';
    $ttl = ($rec['title'] ?? '') . ' ' . ($rec['notes'] ?? '') . ' ' . ($rec['description'] ?? '');
    if (strpos($id, 'A21030000I-B1000A-') === 0 && mb_strpos($ttl, '投保金額分級表') !== false) {
      $modified = $rec['modified'] ?? $rec['metadata_modified'] ?? '';
      $candidates[] = ['identifier' => $id, 'modified' => $modified];
    }
  }
  if (!$candidates) return 'A21030000I-B1000A-00F'; // 後備
  usort($candidates, fn($a,$b) => strcmp($b['modified'] ?? '', $a['modified'] ?? ''));
  return $candidates[0]['identifier'];
}

function parse_group_code($identifier){
  if (preg_match('/^[A-Z0-9]+\-([A-Z0-9]+)\-\w+$/', $identifier, $m)) return $m[1];
  return '';
}

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
    try {
      $target = $table;
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
        $level = coalesce($r['等級'] ?? null, $r['投保等級'] ?? null);

        // 健保可能只有單一「實際薪資月額（元）」；也可能有上下限欄位
        $rangeStr = coalesce(
          $r['實際薪資'] ?? null,
          $r['薪資範圍'] ?? null,
          $r['實際薪資月額（元）'] ?? null
        );
        [$wmin, $wmax] = [null, null];
        if ($rangeStr !== null && $rangeStr !== '') {
          if (is_numeric($rangeStr)) {
            $wmin = $wmax = 0 + $rangeStr;
          } else {
            [$wmin, $wmax] = parse_range_to_minmax((string)$rangeStr);
          }
        }
        // 若資料同時提供上下限欄位，覆蓋之
        $wmin = coalesce($r['實際薪資下限'] ?? null, $wmin);
        $wmax = coalesce($r['實際薪資上限'] ?? null, $wmax);

        $base = coalesce($r['月投保金額'] ?? null, $r['投保金額'] ?? null);

        $eff = coalesce($r['生效日期'] ?? null, $r['實施日期'] ?? null);

        $stmt->execute([
          ':level_no'      => $level,
          ':wage_min'      => $wmin,
          ':wage_max'      => $wmax,
          ':base_amount'   => $base,
          ':group_code'    => $groupCode,
          ':resource_id'   => $identifier,
          ':effective_date'=> $eff,
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
