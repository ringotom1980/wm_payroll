<?php
// Public/api/auth/me.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../../config/auth.php';

// 如果沒登入 → 401
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = auth_user();
echo json_encode(['ok' => true, 'data' => $user], JSON_UNESCAPED_UNICODE);
