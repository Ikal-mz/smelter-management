<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API: Get Register Teams
|--------------------------------------------------------------------------
| Mengambil daftar Team aktif berdasarkan Smelter aktif.
|
| Endpoint:
|   /api/get-register-teams?smelter_id=1
|
| API ini PUBLIC karena digunakan pada halaman registrasi
| sebelum user melakukan login.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| JSON RESPONSE HELPER
|--------------------------------------------------------------------------
*/
function apiJsonResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $httpStatus = 200
): void {
    http_response_code($httpStatus);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data'    => $data
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| METHOD CHECK
|--------------------------------------------------------------------------
| Endpoint ini hanya menerima GET.
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiJsonResponse(
        false,
        'Method tidak diizinkan.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| LOAD DATABASE
|--------------------------------------------------------------------------
*/
try {

    require_once __DIR__ . '/../config/database.php';

} catch (Throwable $e) {

    /*
     * Detail error database tidak boleh dikirim ke browser.
     */
    error_log(
        'API get-register-teams database load error: ' .
        $e->getMessage()
    );

    apiJsonResponse(
        false,
        'Terjadi kesalahan server.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| VALIDATE SMELTER ID
|--------------------------------------------------------------------------
*/
$smelterId = filter_input(
    INPUT_GET,
    'smelter_id',
    FILTER_VALIDATE_INT
);

if (
    $smelterId === false ||
    $smelterId === null ||
    $smelterId <= 0
) {
    apiJsonResponse(
        false,
        'Smelter ID tidak valid.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| DATABASE PROCESS
|--------------------------------------------------------------------------
*/
try {

    /*
    |--------------------------------------------------------------------------
    | VALIDASI SMELTER
    |--------------------------------------------------------------------------
    | Smelter harus:
    | - ada
    | - aktif
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT
            id,
            division_id,
            name
        FROM smelters
        WHERE id = ?
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        $smelterId
    ]);

    $smelter = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$smelter) {
        apiJsonResponse(
            false,
            'Smelter tidak ditemukan atau tidak aktif.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AMBIL TEAM AKTIF
    |--------------------------------------------------------------------------
    | Team harus:
    | - aktif
    | - benar-benar milik Smelter yang diminta
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

    /*
    |--------------------------------------------------------------------------
    | NORMALISASI RESPONSE
    |--------------------------------------------------------------------------
    | Pastikan ID selalu dikembalikan sebagai integer.
    |--------------------------------------------------------------------------
    */
    foreach ($teams as &$team) {

        $team['id'] = (int) $team['id'];
        $team['name'] = (string) $team['name'];

    }

    unset($team);

    /*
    |--------------------------------------------------------------------------
    | RESPONSE SUCCESS
    |--------------------------------------------------------------------------
    | Tidak ada Team aktif bukan error server.
    | Tetap success dengan data [].
    |--------------------------------------------------------------------------
    */
    apiJsonResponse(
        true,
        'Data Team berhasil dimuat.',
        $teams,
        200
    );

} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | DATABASE ERROR
    |--------------------------------------------------------------------------
    | Detail SQL tidak dikirim ke browser.
    |--------------------------------------------------------------------------
    */
    error_log(
        'API get-register-teams database error: ' .
        $e->getMessage()
    );

    apiJsonResponse(
        false,
        'Terjadi kesalahan database.',
        [],
        500
    );

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | UNEXPECTED ERROR
    |--------------------------------------------------------------------------
    */
    error_log(
        'API get-register-teams unexpected error: ' .
        $e->getMessage()
    );

    apiJsonResponse(
        false,
        'Terjadi kesalahan server.',
        [],
        500
    );
}