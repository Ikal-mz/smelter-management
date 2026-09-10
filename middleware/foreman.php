<?php

require_once __DIR__ . '/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'foreman') {
    http_response_code(403);
    exit('Akses ditolak.');
}

if (($_SESSION['status'] ?? '') !== 'active') {
    session_destroy();
    header('Location: ../auth/login');
    exit;
}