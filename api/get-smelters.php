<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API: Get Smelters
|--------------------------------------------------------------------------
| Digunakan untuk mengambil daftar Smelter aktif berdasarkan Division.
|
| Endpoint:
|   /api/get-smelters?division_id=1
|
| API ini public karena digunakan pada halaman registrasi sebelum user login.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| Helper JSON Response
|--------------------------------------------------------------------------
*/
function jsonResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $httpStatus = 200
): never {
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
| Method Check
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(
        false,
        'Method tidak diizinkan.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Load Database
|--------------------------------------------------------------------------
*/
try {
    require_once __DIR__ . '/../config/database.php';
} catch (Throwable $e) {
    error_log(
        'API get-smelters database load error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Terjadi kesalahan server.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Validate Division ID
|--------------------------------------------------------------------------
*/
$divisionId = filter_input(
    INPUT_GET,
    'division_id',
    FILTER_VALIDATE_INT
);

if (
    $divisionId === false ||
    $divisionId === null ||
    $divisionId <= 0
) {
    jsonResponse(
        false,
        'Division ID tidak valid.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| Query Smelter
|--------------------------------------------------------------------------
*/
try {
    /*
     * Pastikan Division memang ada dan aktif.
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
            name
        FROM divisions
        WHERE id = ?
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        $divisionId
    ]);

    $division = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$division) {
        jsonResponse(
            false,
            'Division tidak ditemukan atau tidak aktif.',
            [],
            404
        );
    }

    /*
     * Ambil hanya Smelter aktif milik Division tersebut.
     */
    $stmt = $pdo->prepare("
        SELECT
            id,
            name
        FROM smelters
        WHERE division_id = ?
          AND status = 'active'
        ORDER BY name ASC
    ");

    $stmt->execute([
        $divisionId
    ]);

    $smelters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Normalisasi tipe data ID agar response konsisten.
     */
    foreach ($smelters as &$smelter) {
        $smelter['id'] = (int) $smelter['id'];
        $smelter['name'] = (string) $smelter['name'];
    }

    unset($smelter);

    /*
     * Tidak menemukan Smelter bukan server error.
     * Tetap success dengan data array kosong.
     */
    jsonResponse(
        true,
        'Data Smelter berhasil dimuat.',
        $smelters,
        200
    );

} catch (PDOException $e) {

    /*
     * Detail error database tidak boleh dikirim ke browser.
     * Simpan detail hanya di server log.
     */
    error_log(
        'API get-smelters database error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Terjadi kesalahan database.',
        [],
        500
    );

} catch (Throwable $e) {

    /*
     * Fallback untuk error tak terduga.
     */
    error_log(
        'API get-smelters unexpected error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Terjadi kesalahan server.',
        [],
        500
    );
}