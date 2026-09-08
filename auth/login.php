<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {

        $error = 'Email dan password wajib diisi.';

    } else {

        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.nik,
                u.name,
                u.email,
                u.password,
                u.status,
                r.name AS role_name
            FROM users u
            INNER JOIN roles r
                ON r.id = u.role_id
            WHERE u.email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {

            $error = 'Email atau password salah.';

        } elseif (!password_verify($password, $user['password'])) {

            $error = 'Email atau password salah.';

        } elseif ($user['status'] === 'pending') {

            $error = 'Akun Anda masih menunggu persetujuan admin.';

        } elseif ($user['status'] === 'rejected') {

            $error = 'Pendaftaran akun Anda ditolak.';

        } elseif ($user['status'] === 'suspended') {

            $error = 'Akun Anda sedang dinonaktifkan.';

        } elseif ($user['status'] === 'deleted') {

            $error = 'Akun tidak dapat digunakan.';

        } elseif ($user['status'] !== 'active') {

            $error = 'Status akun tidak valid.';

        } else {

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['nik']      = $user['nik'];
            $_SESSION['name']     = $user['name'];
            $_SESSION['email']    = $user['email'];
            $_SESSION['role']     = $user['role_name'];
            $_SESSION['status']   = $user['status'];

            switch ($user['role_name']) {

                case 'admin':
                    header('Location: ../admin/dashboard.php');
                    exit;

                case 'spv':
                    header('Location: ../spv/dashboard.php');
                    exit;

                case 'foreman':
                    header('Location: ../foreman/dashboard.php');
                    exit;

                default:
                    session_unset();
                    session_destroy();

                    $error = 'Role pengguna tidak valid.';
                    break;
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login - Smelter Management</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                linear-gradient(
                    135deg,
                    #0f172a 0%,
                    #1e293b 50%,
                    #334155 100%
                );

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
        }

        .login-card {
            background: #ffffff;

            border-radius: 16px;

            padding: 40px;

            box-shadow:
                0 20px 50px rgba(0, 0, 0, 0.25);
        }

        .logo {
            width: 70px;
            height: 70px;

            margin: 0 auto 20px;

            border-radius: 50%;

            background: #0f172a;

            color: #ffffff;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 26px;
            font-weight: bold;
        }

        .title {
            text-align: center;

            margin: 0;

            font-size: 25px;

            color: #0f172a;
        }

        .subtitle {
            text-align: center;

            margin: 8px 0 30px;

            color: #64748b;

            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;

            margin-bottom: 8px;

            font-size: 14px;

            font-weight: 600;

            color: #334155;
        }

        input {
            width: 100%;

            padding: 13px 14px;

            border: 1px solid #cbd5e1;

            border-radius: 8px;

            font-size: 15px;

            outline: none;

            transition: 0.2s;
        }

        input:focus {
            border-color: #2563eb;

            box-shadow:
                0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-login {
            width: 100%;

            border: none;

            border-radius: 8px;

            padding: 14px;

            background: #2563eb;

            color: #ffffff;

            font-size: 15px;

            font-weight: bold;

            cursor: pointer;

            transition: 0.2s;
        }

        .btn-login:hover {
            background: #1d4ed8;
        }

        .alert {
            margin-bottom: 20px;

            padding: 12px 14px;

            border-radius: 8px;

            background: #fee2e2;

            border: 1px solid #fecaca;

            color: #b91c1c;

            font-size: 14px;
        }

        .footer {
            text-align: center;

            margin-top: 25px;

            color: #64748b;

            font-size: 13px;
        }

        .register-link {
            text-align: center;

            margin-top: 20px;

            font-size: 14px;
        }

        .register-link a {
            color: #2563eb;

            text-decoration: none;

            font-weight: 600;
        }

        .register-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

<div class="login-container">

    <div class="login-card">

        <div class="logo">
            SM
        </div>

        <h1 class="title">
            Smelter Management
        </h1>

        <p class="subtitle">
            Silakan masuk ke sistem
        </p>

        <?php if ($error !== ''): ?>

            <div class="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="">

            <div class="form-group">

                <label for="email">
                    Email
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Masukkan email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="email"
                    required
                >

            </div>

            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Masukkan password"
                    autocomplete="current-password"
                    required
                >

            </div>

            <button
                type="submit"
                class="btn-login"
            >
                Login
            </button>

        </form>

        <div class="register-link">
            Belum memiliki akun?
            <a href="register.php">
                Daftar di sini
            </a>
        </div>

        <div class="footer">
            &copy; <?= date('Y') ?> Smelter Management
        </div>

    </div>

</div>

</body>
</html>