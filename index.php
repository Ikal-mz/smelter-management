<?php

session_start();

if (isset($_SESSION['user_id'])) {

    switch ($_SESSION['role']) {

        case 'admin':
            header('Location: admin/dashboard.php');
            exit;

        case 'spv':
            header('Location: spv/dashboard.php');
            exit;

        case 'foreman':
            header('Location: foreman/dashboard.php');
            exit;
    }
}

header('Location: auth/login.php');
exit;