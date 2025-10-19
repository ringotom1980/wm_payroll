<?php
// Public/api/auth/logout.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../../config/auth.php';

logout_user();

echo json_encode(['ok' => true, 'message' => 'LOGGED_OUT'], JSON_UNESCAPED_UNICODE);
