<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {

    /*
    |--------------------------------------------------------------------------
    | Validasi smelter
    |--------------------------------------------------------------------------
    */

    $smelterId = filter_input(
        INPUT_GET,
        'smelter_id',
        FILTER_VALIDATE_INT
    );

    if (!$smelterId || $smelterId <= 0) {

        echo json_encode([
            'success' => false,
            'message' => 'Smelter tidak valid.'
        ]);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Ambil user aktif
    |--------------------------------------------------------------------------
    */

    $userId = $_SESSION['user_id'] ?? 0;

    if (!$userId) {

        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Sesi pengguna tidak valid.'
        ]);

        exit;
    }


    $stmt = $pdo->prepare("
        SELECT
            u.division_id,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r
            ON r.id = u.role_id
        WHERE u.id = ?
          AND u.status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {

        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'User tidak ditemukan atau tidak aktif.'
        ]);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Pastikan smelter berada dalam divisi user
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id
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

    $smelterExists = $stmt->fetchColumn();

    if (!$smelterExists) {

        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Smelter tidak berada pada divisi Anda.'
        ]);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Ambil team aktif
    |--------------------------------------------------------------------------
    */

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

    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'data' => $teams
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan pada database.'
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan pada server.'
    ]);
}