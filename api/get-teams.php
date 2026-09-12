<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API: Get Teams
|--------------------------------------------------------------------------
| Mengambil daftar Team aktif berdasarkan Smelter.
|
| Endpoint:
|   /api/get-teams?smelter_id=1
|
| API ini hanya dapat digunakan oleh user yang:
| - sudah login
| - berstatus active
|
| User hanya boleh mengambil Team dari Smelter yang berada
| pada Division miliknya sendiri.
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
| Endpoint hanya menerima GET.
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
| SESSION
|--------------------------------------------------------------------------
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| USER LOGIN CHECK
|--------------------------------------------------------------------------
| Karena ini API, jangan redirect ke halaman login.
| Kembalikan HTTP 401 + JSON.
|--------------------------------------------------------------------------
*/
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    apiJsonResponse(
        false,
        'Sesi pengguna tidak valid.',
        [],
        401
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

    error_log(
        'API get-teams database load error: ' .
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
    | VALIDASI USER
    |--------------------------------------------------------------------------
    | User harus:
    | - masih ada
    | - active
    | - memiliki role
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.division_id,
            u.status,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r
            ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $userId
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        apiJsonResponse(
            false,
            'User tidak ditemukan.',
            [],
            401
        );
    }

    if ($user['status'] !== 'active') {
        apiJsonResponse(
            false,
            'Akun pengguna tidak aktif.',
            [],
            403
        );
    }

    if (
        empty($user['division_id']) ||
        (int) $user['division_id'] <= 0
    ) {
        apiJsonResponse(
            false,
            'Division pengguna tidak valid.',
            [],
            403
        );
    }

    $divisionId = (int) $user['division_id'];

    /*
    |--------------------------------------------------------------------------
    | VALIDASI SMELTER
    |--------------------------------------------------------------------------
    | Smelter harus:
    | - ada
    | - active
    | - berada di Division user
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT
            id,
            division_id,
            status
        FROM smelters
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $smelterId
    ]);

    $smelter = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$smelter) {
        apiJsonResponse(
            false,
            'Smelter tidak ditemukan.',
            [],
            404
        );
    }

    if ($smelter['status'] !== 'active') {
        apiJsonResponse(
            false,
            'Smelter tidak aktif.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | IDOR PROTECTION
    |--------------------------------------------------------------------------
    | User tidak boleh meminta Team dari Division lain.
    |--------------------------------------------------------------------------
    */
    if ((int) $smelter['division_id'] !== $divisionId) {
        apiJsonResponse(
            false,
            'Smelter tidak berada pada divisi Anda.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AMBIL TEAM
    |--------------------------------------------------------------------------
    | Hanya Team:
    | - active
    | - milik Smelter yang diminta
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
    | NORMALISASI DATA
    |--------------------------------------------------------------------------
    */
    foreach ($teams as &$team) {

        $team['id'] = (int) $team['id'];
        $team['name'] = (string) $team['name'];

    }

    unset($team);

    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    | Tidak ada Team aktif bukan error.
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
        'API get-teams database error: ' .
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
        'API get-teams unexpected error: ' .
        $e->getMessage()
    );

    apiJsonResponse(
        false,
        'Terjadi kesalahan server.',
        [],
        500
    );
}