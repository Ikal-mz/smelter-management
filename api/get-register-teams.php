<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

try {

    $smelterId = filter_input(
        INPUT_GET,
        'smelter_id',
        FILTER_VALIDATE_INT
    );

    if (!$smelterId) {

        echo json_encode([
            'success' => false,
            'message' => 'Smelter tidak valid.'
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Pastikan Smelter aktif
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT id
        FROM smelters
        WHERE id = ?
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        $smelterId
    ]);

    if (!$stmt->fetch()) {

        echo json_encode([
            'success' => false,
            'message' => 'Smelter tidak ditemukan atau tidak aktif.'
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
        ORDER BY name ASC
    ");

    $stmt->execute([
        $smelterId
    ]);

    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $teams
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Gagal mengambil data Team.'
    ]);
}