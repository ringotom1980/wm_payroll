<?php
// Public/api/employees.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../config/db.php';
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB 連線失敗: ' . $e->getMessage()]);
    exit;
}

function j($ok, $msg = '', $extra = [])
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** --------- helpers --------- */
function fetch_all(PDO $pdo, string $sql, array $params = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_one(PDO $pdo, string $sql, array $params = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function scalar(PDO $pdo, string $sql, array $params = [])
{
    $row = fetch_one($pdo, $sql, $params);
    if (!$row) return null;
    return reset($row);
}
function like_q(string $q): string
{
    return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
}
function in_today_or($dateStr, $fallback): string
{
    return $dateStr ?: $fallback;
}

/** tenure text */
function tenure_text(?string $from, ?string $to = null): string
{
    if (!$from) return ''; // 沒起始日就不計算
    try {
        $start = new DateTime($from);
        $end = $to ? new DateTime($to) : new DateTime('today');
        if ($end < $start) $end = $start;
        $diff = $start->diff($end);
        $parts = [];
        if ($diff->y) $parts[] = $diff->y . ' 年';
        if ($diff->m) $parts[] = $diff->m . ' 個月';
        if (!$parts) $parts[] = $diff->d . ' 天';
        return implode(' ', $parts);
    } catch (Throwable $e) {
        return ''; // 任意解析失敗都回空字串，避免 500
    }
}


/** columns whitelist for select */
$baseCols = "emp_id, emp_no, full_name, birth_date, phone, phone_mobile, address, email,
             emergency_contact_name, emergency_contact_phone, emergency_contact_mobile,
             hire_date, parental_leave_start, resign_date, status, dependents_count, expected_return_date";

$commonWhere = "is_deleted=0";

/** --------- actions --------- */

if ($action === 'list_active') {
    $q = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $ps = min(100, max(5, (int)($_GET['page_size'] ?? 10)));

    $where = "$commonWhere AND status IN ('ACTIVE','LEAVE')";
    $params = [];
    if ($q !== '') {
        $where .= " AND (
            full_name     LIKE :q1 OR
            phone         LIKE :q2 OR
            phone_mobile  LIKE :q3 OR
            email         LIKE :q4 OR
            address       LIKE :q5 
        )";
        $search = like_q($q);
        $params = [
            ':q1' => $search,
            ':q2' => $search,
            ':q3' => $search,
            ':q4' => $search,
            ':q5' => $search,
        ];
    }

    $total = (int)scalar($pdo, "SELECT COUNT(*) FROM employees WHERE $where", $params);
    $pages = max(1, (int)ceil($total / $ps));
    $page = min($page, $pages);
    $off = ($page - 1) * $ps;

    $rows = fetch_all(
        $pdo,
        "SELECT $baseCols FROM employees WHERE $where
         ORDER BY hire_date DESC, emp_id DESC LIMIT $ps OFFSET $off",
        $params
    );

    foreach ($rows as &$r) {
        $r['tenure'] = tenure_text($r['hire_date'] ?? null, null);
        $r['status_text'] = ($r['status'] === 'ACTIVE') ? '在職'
            : (($r['status'] === 'LEAVE') ? '留職停薪' : '離職');
    }
    j(true, 'ok', ['data' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total]);
}

if ($action === 'list_resigned') {
    $q = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $ps = min(100, max(5, (int)($_GET['page_size'] ?? 10)));

    $where = "$commonWhere AND status='RESIGNED'";
    $params = [];
    if ($q !== '') {
        $where .= " AND (
            full_name     LIKE :q1 OR
            phone         LIKE :q2 OR
            phone_mobile  LIKE :q3 OR
            email         LIKE :q4 OR
            address       LIKE :q5 
        )";
        $search = like_q($q);
        $params = [
            ':q1' => $search,
            ':q2' => $search,
            ':q3' => $search,
            ':q4' => $search,
            ':q5' => $search,
        ];
    }

    $total = (int)scalar($pdo, "SELECT COUNT(*) FROM employees WHERE $where", $params);
    $pages = max(1, (int)ceil($total / $ps));
    $page = min($page, $pages);
    $off = ($page - 1) * $ps;

    $rows = fetch_all(
        $pdo,
        "SELECT $baseCols FROM employees WHERE $where
         ORDER BY COALESCE(resign_date, hire_date) DESC, emp_id DESC
         LIMIT $ps OFFSET $off",
        $params
    );

    foreach ($rows as &$r) {
        $end = ($r['resign_date'] ?: (new DateTime('today'))->format('Y-m-d'));
        $r['tenure'] = tenure_text($r['hire_date'] ?? null, $end);
        $r['status_text'] = '離職';
    }
    j(true, 'ok', ['data' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total]);
}


if ($action === 'get') {
    $id = (int)($_GET['emp_id'] ?? 0);
    if ($id <= 0) j(false, 'emp_id 無效');
    $row = fetch_one($pdo, "SELECT $baseCols FROM employees WHERE emp_id=:id AND $commonWhere", [':id' => $id]);
    if (!$row) j(false, '查無資料');
    j(true, 'ok', ['data' => $row]);
}

if ($action === 'check_dupe') {
    // 單欄驗重：姓名、電話、手機、Email（只檢查 is_deleted=0）
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone_mobile = trim($_POST['phone_mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');

    $dupes = [];
    if ($full_name !== '' && scalar($pdo, "SELECT 1 FROM employees WHERE is_deleted=0 AND full_name=:v LIMIT 1", [':v' => $full_name])) $dupes['full_name'] = '已有相同姓名';
    if ($phone !== '' && scalar($pdo, "SELECT 1 FROM employees WHERE is_deleted=0 AND phone=:v LIMIT 1", [':v' => $phone])) $dupes['phone'] = '已有相同電話';
    if ($phone_mobile !== '' && scalar($pdo, "SELECT 1 FROM employees WHERE is_deleted=0 AND phone_mobile=:v LIMIT 1", [':v' => $phone_mobile])) $dupes['phone_mobile'] = '已有相同手機';
    if ($email !== '' && scalar($pdo, "SELECT 1 FROM employees WHERE is_deleted=0 AND email=:v LIMIT 1", [':v' => $email])) $dupes['email'] = '已有相同 Email';

    j(true, 'ok', ['dupes' => $dupes]);
}

/* ===================== create ===================== */
/* ===================== create ===================== */
if ($action === 'create') {
    // ---- 讀取與基本整理 ----
    $full_name = trim($_POST['full_name'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone_mobile = trim($_POST['phone_mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');

    $emg_name = trim($_POST['emergency_contact_name'] ?? '');
    $emg_phone = trim($_POST['emergency_contact_phone'] ?? '');
    $emg_mobile = trim($_POST['emergency_contact_mobile'] ?? '');

    $hire_date = trim($_POST['hire_date'] ?? '');
    $status = $_POST['status'] ?? 'ACTIVE';
    $dependents_count = (int)($_POST['dependents_count'] ?? 0);

    $parental_leave_start = trim($_POST['parental_leave_start'] ?? '');
    $expected_return_date = trim($_POST['expected_return_date'] ?? '');
    $resign_date = trim($_POST['resign_date'] ?? '');

    // ---- 必填與基本規則驗證 ----
    $today = (new DateTime('today'))->format('Y-m-d');
    $invalid = [];

    // 必填（一般欄位）
    if ($full_name === '') $invalid['full_name'] = '必填';
    if ($birth_date === '') $invalid['birth_date'] = '必填';
    if ($phone_mobile === '') $invalid['phone_mobile'] = '必填';
    if ($address === '') $invalid['address'] = '必填';
    if ($emg_name === '') $invalid['emergency_contact_name'] = '必填';
    if ($emg_mobile === '') $invalid['emergency_contact_mobile'] = '必填';
    if ($hire_date === '') $invalid['hire_date'] = '必填';
    if ($status === '') $invalid['status'] = '必填';
    if ($dependents_count < 0) $invalid['dependents_count'] = '必須≥0';

    // 日期不可晚於今天（維持原規則）：生日 / 到職 / 離職
    foreach (['birth_date', 'hire_date', 'resign_date'] as $k) {
        $v = $$k ?? '';
        if ($v !== '' && $v > $today) $invalid[$k] = '不可晚於今天';
    }
    // 【重點】預計復職日允許晚於今天，不做今天上限檢核

    // 狀態聯動與留停欄位檢核
    if ($status === 'LEAVE') {
        // 兩欄必填
        if ($parental_leave_start === '') $invalid['parental_leave_start'] = '必填';
        if ($expected_return_date === '') $invalid['expected_return_date'] = '必填';
        // 只有兩欄都有值才比較先後
        if ($parental_leave_start !== '' && $expected_return_date !== '') {
            if ($expected_return_date < $parental_leave_start) {
                $invalid['expected_return_date'] = '不得早於育嬰開始日';
            }
        }
    } else {
        // 非 LEAVE → 強制清空
        $parental_leave_start = '';
        $expected_return_date = '';
    }

    if (!empty($invalid)) {
        j(false, '請修正欄位錯誤', ['invalid_fields' => $invalid]);
    }

    // ---- 單欄驗重（只檢查未刪除資料）----
    $dupes = [];
    if ($full_name !== '' && scalar($pdo, "SELECT 1 FROM employees WHERE is_deleted=0 AND full_name=:v LIMIT 1", [':v' => $full_name])) $dupes['full_name'] = '已有相同姓名';
    if ($phone !== '' && scalar($pdo, "SELECT 1 FROM employees WHERE is_deleted=0 AND phone=:v LIMIT 1", [':v' => $phone])) $dupes['phone'] = '已有相同電話';
    if ($phone_mobile !== '' && scalar($pdo, "SELECT 1 FROM employees WHERE is_deleted=0 AND phone_mobile=:v LIMIT 1", [':v' => $phone_mobile])) $dupes['phone_mobile'] = '已有相同手機';
    if ($email !== '' && scalar($pdo, "SELECT 1 FROM employees WHERE is_deleted=0 AND email=:v LIMIT 1", [':v' => $email])) $dupes['email'] = '已有相同 Email';
    if ($dupes) {
        j(false, '欄位重複，無法新增', ['invalid_fields' => $dupes]);
    }

    // ---- 寫入 ----
    $sql = "INSERT INTO employees (
      emp_no, full_name, birth_date,
      phone, phone_mobile, address, email,
      emergency_contact_name, emergency_contact_phone, emergency_contact_mobile,
      hire_date, parental_leave_start, resign_date, status,
      dependents_count, expected_return_date,
      is_deleted, created_at, updated_at
    ) VALUES (
      :emp_no, :full_name, :birth_date,
      :phone, :phone_mobile, :address, :email,
      :emg_name, :emg_phone, :emg_mobile,
      :hire_date, :parental_leave_start, :resign_date, :status,
      :dependents_count, :expected_return_date,
      0, NOW(), NOW()
    )";

    try {
        $st = $pdo->prepare($sql);
        $st->execute([
            ':emp_no' => null, // 關鍵：不要用空字串
            ':full_name' => $full_name,
            ':birth_date' => ($birth_date ?: null),
            ':phone' => $phone,
            ':phone_mobile' => $phone_mobile,
            ':address' => $address,
            ':email' => $email,
            ':emg_name' => $emg_name,
            ':emg_phone' => $emg_phone,
            ':emg_mobile' => $emg_mobile,
            ':hire_date' => $hire_date,
            ':parental_leave_start' => ($status === 'LEAVE' ? ($parental_leave_start ?: null) : null),
            ':resign_date' => ($status === 'RESIGNED' ? ($resign_date ?: null) : null),
            ':status' => $status,
            ':dependents_count' => $dependents_count,
            ':expected_return_date' => ($status === 'LEAVE' ? ($expected_return_date ?: null) : null),
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        j(false, 'DB 錯誤：' . $e->getMessage());
    }

    j(true, '新增完成', ['emp_id' => (int)$pdo->lastInsertId()]);
}


/* ===================== update ===================== */
if ($action === 'update') {
    $emp_id = (int)($_POST['emp_id'] ?? 0);
    if ($emp_id <= 0) j(false, 'emp_id 無效');

    // 確認存在且未被軟刪
    $row = fetch_one($pdo, "SELECT emp_id FROM employees WHERE emp_id=:id AND is_deleted=0", [':id' => $emp_id]);
    if (!$row) j(false, '查無資料或已刪除');

    // ---- 讀取與基本整理 ----
    $full_name = trim($_POST['full_name'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone_mobile = trim($_POST['phone_mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');

    $emg_name = trim($_POST['emergency_contact_name'] ?? '');
    $emg_phone = trim($_POST['emergency_contact_phone'] ?? '');
    $emg_mobile = trim($_POST['emergency_contact_mobile'] ?? '');

    $hire_date = trim($_POST['hire_date'] ?? '');
    $status = $_POST['status'] ?? 'ACTIVE';
    $dependents_count = (int)($_POST['dependents_count'] ?? 0);

    $parental_leave_start = trim($_POST['parental_leave_start'] ?? '');
    $expected_return_date = trim($_POST['expected_return_date'] ?? '');
    $resign_date = trim($_POST['resign_date'] ?? '');

    // ---- 必填與基本規則驗證 ----
    $today = (new DateTime('today'))->format('Y-m-d');
    $invalid = [];

    if ($full_name === '') $invalid['full_name'] = '必填';
    if ($birth_date === '') $invalid['birth_date'] = '必填';
    if ($phone_mobile === '') $invalid['phone_mobile'] = '必填';
    if ($address === '') $invalid['address'] = '必填';
    if ($emg_name === '') $invalid['emergency_contact_name'] = '必填';
    if ($emg_mobile === '') $invalid['emergency_contact_mobile'] = '必填';
    if ($hire_date === '') $invalid['hire_date'] = '必填';
    if ($status === '') $invalid['status'] = '必填';
    if ($dependents_count < 0) $invalid['dependents_count'] = '必須≥0';

    // 日期不可晚於今天（維持原規則）：生日 / 到職 / 離職
    foreach (['birth_date', 'hire_date', 'resign_date'] as $k) {
        $v = $$k ?? '';
        if ($v !== '' && $v > $today) $invalid[$k] = '不可晚於今天';
    }
    // 【重點】預計復職日允許晚於今天，不做今天上限檢核

    // 狀態聯動
    if ($status === 'LEAVE') {
        if ($parental_leave_start === '') $invalid['parental_leave_start'] = '必填';
        if ($expected_return_date === '') $invalid['expected_return_date'] = '必填';
        if ($parental_leave_start !== '' && $expected_return_date !== '' && $expected_return_date < $parental_leave_start) {
            $invalid['expected_return_date'] = '不得早於育嬰開始日';
        }
    } else {
        $parental_leave_start = '';
        $expected_return_date = '';
    }

    if (!empty($invalid)) {
        j(false, '請修正欄位錯誤', ['invalid_fields' => $invalid]);
    }

    // （設計）update 不做重複擋；如需擋，可複用 create 的驗重

    // ---- 寫入 ----
    $sql = "UPDATE employees SET
          emp_no=:emp_no,
          full_name=:full_name,
          birth_date=:birth_date,
          phone=:phone,
          phone_mobile=:phone_mobile,
          address=:address,
          email=:email,
          emergency_contact_name=:emg_name,
          emergency_contact_phone=:emg_phone,
          emergency_contact_mobile=:emg_mobile,
          hire_date=:hire_date,
          parental_leave_start=:parental_leave_start,
          resign_date=:resign_date,
          status=:status,
          dependents_count=:dependents_count,
          expected_return_date=:expected_return_date,
          updated_at=NOW()
        WHERE emp_id=:emp_id AND is_deleted=0";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':emp_id' => $emp_id,
        ':emp_no' => null,
        ':full_name' => $full_name,
        ':birth_date' => ($birth_date ?: null),
        ':phone' => $phone,
        ':phone_mobile' => $phone_mobile,
        ':address' => $address,
        ':email' => $email,
        ':emg_name' => $emg_name,
        ':emg_phone' => $emg_phone,
        ':emg_mobile' => $emg_mobile,
        ':hire_date' => $hire_date,
        ':parental_leave_start' => ($status === 'LEAVE' ? ($parental_leave_start ?: null) : null),
        ':resign_date' => ($status === 'RESIGNED' ? ($resign_date ?: null) : null),
        ':status' => $status,
        ':dependents_count' => $dependents_count,
        ':expected_return_date' => ($status === 'LEAVE' ? ($expected_return_date ?: null) : null),
    ]);

    j(true, '已更新');
}


if ($action === 'soft_delete') {
    $emp_id = (int)($_POST['emp_id'] ?? 0);
    if ($emp_id <= 0) j(false, 'emp_id 無效');
    $st = $pdo->prepare("UPDATE employees SET is_deleted=1, updated_at=NOW() WHERE emp_id=:id AND is_deleted=0");
    $st->execute([':id' => $emp_id]);
    if ($st->rowCount() === 0) j(false, '資料不存在或已刪除');
    j(true, '已刪除（軟刪）');
}

if ($action === 'print') {
    header('Content-Type: text/html; charset=utf-8');
    $list = $_POST['list_type'] ?? 'active';
    $cols = $_POST['columns'] ?? [];
    $q = trim($_POST['q'] ?? '');
    $company = '旺苗科技工程股份有限公司';

    $where = "is_deleted=0";
    if ($list === 'active') $where .= " AND status IN ('ACTIVE','LEAVE')";
    else $where .= " AND status='RESIGNED'";
    $params = [];
    if ($q !== '') {
        $where .= " AND (
            full_name     LIKE :q1 OR
            phone         LIKE :q2 OR
            phone_mobile  LIKE :q3 OR
            email         LIKE :q4 OR
            address       LIKE :q5 
        )";
        $search = like_q($q);
        $params = [
            ':q1' => $search,
            ':q2' => $search,
            ':q3' => $search,
            ':q4' => $search,
            ':q5' => $search,
        ];
    }

    $rows = fetch_all(
        $pdo,
        "SELECT $baseCols FROM employees WHERE $where ORDER BY full_name ASC",
        $params
    );
    // enrich tenure
    foreach ($rows as &$r) {
        $end = ($list === 'resigned') ? ($r['resign_date'] ?: (new DateTime('today'))->format('Y-m-d')) : null;
        $r['tenure'] = tenure_text($r['hire_date'], $end);
        $r['status_text'] = ($r['status'] === 'ACTIVE' ? '在職' : ($r['status'] === 'LEAVE' ? '留職停薪' : '離職'));
    }

    // 簡單列印頁（固定三欄 + 選擇欄）
?>
    <!DOCTYPE html>
    <html lang="zh-Hant">

    <head>
        <meta charset="utf-8">
        <title>列印｜員工主檔</title>
        <link rel="stylesheet" href="/assets/css/print.css">
        <style>
            body {
                font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial;
                font-size: 12px;
            }

            h1 {
                font-size: 18px;
                margin: 0 0 8px;
            }

            .meta {
                margin-bottom: 12px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th,
            td {
                border: 1px solid #ccc;
                padding: 6px 8px;
                text-align: left;
                vertical-align: top;
            }

            th {
                background: #f8fafc;
            }

            @media print {
                .no-print {
                    display: none
                }
            }
        </style>
    </head>

    <body>
        <h1><?php echo htmlspecialchars($company, ENT_QUOTES, 'UTF-8'); ?> — 員工列表</h1>
        <div class="meta">清單：<?php echo $list === 'active' ? '現職' : '離職'; ?>；筆數：<?php echo count($rows); ?></div>
        <table>
            <thead>
                <tr>
                    <th>姓名</th>
                    <th>電話</th>
                    <th>地址</th>
                    <?php foreach ($cols as $c): ?>
                        <th><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['phone']); ?></td>
                        <td><?php echo htmlspecialchars($r['address']); ?></td>
                        <?php foreach ($cols as $c):
                            // 允許的欄位對應
                            $map = [
                                '手機' => $r['phone_mobile'] ?? '',
                                'Email' => $r['email'] ?? '',
                                '入職日' => $r['hire_date'] ?? '',
                                '在職狀況' => $r['status_text'] ?? '',
                                '預計復職日' => $r['expected_return_date'] ?? '',
                                '健保眷口數' => $r['dependents_count'] ?? '',
                                '離職日' => $r['resign_date'] ?? '',
                                '已任職時間' => $r['tenure'] ?? '',
                            ];
                            $val = $map[$c] ?? '';
                        ?>
                            <td><?php echo htmlspecialchars((string)$val); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="no-print" style="margin-top:12px">
            <button onclick="window.print()">列印</button>
        </div>
    </body>

    </html>
<?php
    exit;
}

j(false, 'Unknown action');
