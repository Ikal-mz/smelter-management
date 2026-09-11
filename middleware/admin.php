<?php

require_once __DIR__ . '/auth.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin' ||
    ($_SESSION['status'] ?? '') !== 'active'
) {
    http_response_code(403);

    die('
        <h1>403 - Access Denied</h1>
        <p>Anda tidak memiliki akses ke halaman ini.</p>
    ');
}