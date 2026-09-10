<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {

    switch ($_SESSION['role'] ?? '') {

        case 'admin':
            header('Location: admin/dashboard');
            exit;

        case 'spv':
            header('Location: spv/dashboard');
            exit;

        case 'foreman':
            header('Location: foreman/dashboard');
            exit;
    }
}

header('Location: auth/login');
exit;