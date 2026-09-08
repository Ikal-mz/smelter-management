<!-- <?php

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$smelterId = filter_input(INPUT_GET, 'smelter_id', FILTER_VALIDATE_INT);

if (!$smelterId) {
    echo json_encode([
        'success' => false,
        'message' => 'Smelter tidak valid.'
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        name
    FROM teams
    WHERE smelter_id = ?
      AND status = 'active'
    ORDER BY name ASC
");

$stmt->execute([$smelterId]);

echo json_encode([
    'success' => true,
    'data' => $stmt->fetchAll()
]); -->

//===========================

<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json');

$smelterId = (int) ($_GET['smelter_id'] ?? 0);

if (!$smelterId) {

    echo json_encode([
        'success' => false,
        'message' => 'Smelter tidak valid.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Ambil user
|--------------------------------------------------------------------------
*/

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT
        u.division_id,
        r.name AS role_name
    FROM users u
    JOIN roles r
        ON r.id = u.role_id
    WHERE u.id = ?
      AND u.status = 'active'
    LIMIT 1
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {

    echo json_encode([
        'success' => false,
        'message' => 'User tidak ditemukan.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Pastikan Smelter sesuai divisi user
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id
    FROM smelters
    WHERE id = ?
      AND division_id = ?
      AND status = 'active'
    LIMIT 1
");

$stmt->execute([
    $smelterId,
    $user['division_id']
]);

if (!$stmt->fetchColumn()) {

    echo json_encode([
        'success' => false,
        'message' => 'Smelter tidak berada pada divisi Anda.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Ambil Team aktif
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        name
    FROM teams
    WHERE smelter_id = ?
      AND status = 'active'
    ORDER BY name
");

$stmt->execute([$smelterId]);

$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);


echo json_encode([
    'success' => true,
    'data' => $teams
]);

