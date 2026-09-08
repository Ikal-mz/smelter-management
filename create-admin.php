<?php

require_once __DIR__ . '/config/database.php';

$nik = 'ADMIN001';
$name = 'Administrator';
$email = 'admin@smelter.local';
$password = 'Admin@12345';


/*
|--------------------------------------------------------------------------
| Cek apakah Admin sudah ada
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT u.id
    FROM users u
    INNER JOIN roles r
        ON r.id = u.role_id
    WHERE r.name = 'admin'
    LIMIT 1
");

$stmt->execute();

if ($stmt->fetch()) {
    die('Akun Admin sudah ada.');
}


/*
|--------------------------------------------------------------------------
| Ambil Role Admin
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id
    FROM roles
    WHERE name = 'admin'
    LIMIT 1
");

$stmt->execute();

$role = $stmt->fetch();

if (!$role) {
    die('Role Admin tidak ditemukan.');
}


/*
|--------------------------------------------------------------------------
| Ambil Division pertama
|
| Admin tidak menggunakan division untuk pembatasan akses.
| Kita tetap mengisi division_id karena kolom tersebut NOT NULL.
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id
    FROM divisions
    WHERE status = 'active'
    ORDER BY id ASC
    LIMIT 1
");

$division = $stmt->fetch();

if (!$division) {
    die('Division belum tersedia.');
}


/*
|--------------------------------------------------------------------------
| Hash Password
|--------------------------------------------------------------------------
*/

$passwordHash = password_hash(
    $password,
    PASSWORD_DEFAULT
);


/*
|--------------------------------------------------------------------------
| Insert Admin
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    INSERT INTO users (
        nik,
        name,
        email,
        password,
        role_id,
        division_id,
        status
    )
    VALUES (?, ?, ?, ?, ?, ?, 'active')
");

$stmt->execute([
    $nik,
    $name,
    $email,
    $passwordHash,
    $role['id'],
    $division['id']
]);

echo "Admin berhasil dibuat.<br><br>";

echo "Email: " . htmlspecialchars($email) . "<br>";
echo "Password: " . htmlspecialchars($password) . "<br><br>";

echo "<strong>HAPUS FILE create-admin.php setelah berhasil.</strong>";