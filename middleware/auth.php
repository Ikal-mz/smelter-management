<?php

/**
 * =========================================================
 * AUTHENTICATION MIDDLEWARE
 * =========================================================
 *
 * Fungsi:
 * 1. Memastikan session tersedia
 * 2. Memastikan user sudah login
 * 3. Memastikan user masih ada di database
 * 4. Memastikan status user masih active
 * 5. Memperbarui data session dari database
 *
 * Middleware ini dipakai oleh:
 * - Admin
 * - SPV
 * - Foreman
 * - API yang membutuhkan login
 * =========================================================
 */


/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {

    /*
     * Pengaturan cookie session.
     *
     * httponly:
     * JavaScript tidak dapat membaca cookie session.
     *
     * samesite=Lax:
     * Mengurangi risiko CSRF berbasis cross-site request.
     *
     * secure:
     * false untuk localhost/XAMPP/HTTP.
     * Saat production HTTPS, ubah menjadi true.
     */

    session_set_cookie_params([
        'httponly' => true,
        'secure'   => false,
        'samesite' => 'Lax'
    ]);

    session_start();
}


/*
|--------------------------------------------------------------------------
| Pastikan user sudah login
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['user_id'])) {

    header('Location: ../auth/login');

    exit;
}


/*
|--------------------------------------------------------------------------
| Koneksi database
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/database.php';


/*
|--------------------------------------------------------------------------
| Ambil user terbaru dari database
|--------------------------------------------------------------------------
*/

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.nik,
        u.name,
        u.email,
        u.status,
        r.name AS role_name
    FROM users u
    INNER JOIN roles r
        ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| User tidak ditemukan
|--------------------------------------------------------------------------
*/

if (!$user) {

    session_unset();
    session_destroy();

    header('Location: ../auth/login');

    exit;
}


/*
|--------------------------------------------------------------------------
| User tidak aktif
|--------------------------------------------------------------------------
|
| Hanya user dengan status active yang boleh
| menggunakan halaman yang membutuhkan authentication.
|
*/

if ($user['status'] !== 'active') {

    session_unset();
    session_destroy();

    header('Location: ../auth/login');

    exit;
}


/*
|--------------------------------------------------------------------------
| Refresh session
|--------------------------------------------------------------------------
|
| Jangan hanya mempercayai role/status yang tersimpan
| di session.
|
| Kita mengambil data terbaru dari database.
|--------------------------------------------------------------------------
*/

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['nik']     = $user['nik'];
$_SESSION['name']    = $user['name'];
$_SESSION['email']   = $user['email'];
$_SESSION['role']    = $user['role_name'];
$_SESSION['status']  = $user['status'];