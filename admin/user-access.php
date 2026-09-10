<?php

require_once __DIR__ . '/../middleware/admin.php';
require_once __DIR__ . '/../config/database.php';

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];


/*
|--------------------------------------------------------------------------
| VALIDASI USER ID
|--------------------------------------------------------------------------
*/

$userId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$userId) {
    http_response_code(400);
    exit('User ID tidak valid.');
}


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| AMBIL DATA USER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.nik,
        u.name,
        u.email,
        u.status,
        u.division_id,
        r.name AS role_name,
        d.name AS division_name
    FROM users u
    INNER JOIN roles r
        ON r.id = u.role_id
    LEFT JOIN divisions d
        ON d.id = u.division_id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->execute([
    $userId
]);

$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    exit('User tidak ditemukan.');
}


/*
|--------------------------------------------------------------------------
| ADMIN TIDAK BOLEH DIKELOLA MELALUI HALAMAN INI
|--------------------------------------------------------------------------
*/

if ($user['role_name'] === 'admin') {
    http_response_code(403);
    exit('Akses manual untuk user Admin tidak diperbolehkan.');
}


/*
|--------------------------------------------------------------------------
| PROSES POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | CEK CSRF
    |--------------------------------------------------------------------------
    */

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !$postedToken ||
        !hash_equals($csrfToken, $postedToken)
    ) {

        $error = 'Token keamanan tidak valid. Silakan ulangi.';

    } else {

        $action = $_POST['action'] ?? '';


        /*
        |--------------------------------------------------------------------------
        | GRANT SPV - SMELTER
        |--------------------------------------------------------------------------
        */

        if ($action === 'grant_smelter') {

            if ($user['role_name'] !== 'spv') {

                $error = 'Aksi ini hanya untuk user SPV.';

            } elseif ($user['status'] !== 'active') {

                $error = 'User harus berstatus Active untuk diberikan akses.';

            } else {

                $smelterId = filter_input(
                    INPUT_POST,
                    'smelter_id',
                    FILTER_VALIDATE_INT
                );

                if (!$smelterId) {

                    $error = 'Smelter tidak valid.';

                } else {

                    try {

                        $pdo->beginTransaction();


                        /*
                        |--------------------------------------------------------------------------
                        | CEK SMELTER
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            SELECT
                                s.id,
                                s.name,
                                s.division_id,
                                s.status,
                                d.name AS division_name
                            FROM smelters s
                            INNER JOIN divisions d
                                ON d.id = s.division_id
                            WHERE s.id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $smelterId
                        ]);

                        $smelter = $stmt->fetch();

                        if (!$smelter) {
                            throw new Exception(
                                'Smelter tidak ditemukan.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CEK STATUS SMELTER
                        |--------------------------------------------------------------------------
                        */

                        if ($smelter['status'] !== 'active') {
                            throw new Exception(
                                'Smelter tidak aktif.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CEK DIVISI USER
                        |--------------------------------------------------------------------------
                        */

                        if (
                            (int) $smelter['division_id']
                            !==
                            (int) $user['division_id']
                        ) {
                            throw new Exception(
                                'Smelter harus berada dalam divisi user.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CARI ACCESS YANG SUDAH PERNAH ADA
                        |--------------------------------------------------------------------------
                        |
                        | Penting:
                        | Jangan hanya mencari status ACTIVE.
                        |
                        | Jika status REVOKED ditemukan, record tersebut
                        | akan diaktifkan kembali.
                        |
                        */

                        $stmt = $pdo->prepare("
                            SELECT
                                id,
                                status
                            FROM user_access
                            WHERE user_id = ?
                              AND smelter_id = ?
                              AND team_id IS NULL
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $userId,
                            $smelterId
                        ]);

                        $existingAccess = $stmt->fetch();


                        /*
                        |--------------------------------------------------------------------------
                        | SUDAH ACTIVE
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $existingAccess &&
                            $existingAccess['status'] === 'active'
                        ) {
                            throw new Exception(
                                'User sudah memiliki akses aktif ke Smelter tersebut.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | REACTIVATE ACCESS REVOKED
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $existingAccess &&
                            $existingAccess['status'] === 'revoked'
                        ) {

                            $stmt = $pdo->prepare("
                                UPDATE user_access
                                SET
                                    status = 'active',
                                    granted_by = ?,
                                    granted_at = NOW(),
                                    revoked_by = NULL,
                                    revoked_at = NULL,
                                    updated_at = NOW()
                                WHERE id = ?
                                  AND user_id = ?
                            ");

                            $stmt->execute([
                                $_SESSION['user_id'],
                                $existingAccess['id'],
                                $userId
                            ]);


                            /*
                            |--------------------------------------------------------------------------
                            | HISTORY
                            |--------------------------------------------------------------------------
                            */

                            $stmt = $pdo->prepare("
                                INSERT INTO user_approvals (
                                    user_id,
                                    approved_by,
                                    action,
                                    notes,
                                    created_at
                                )
                                VALUES (
                                    ?,
                                    ?,
                                    'approved',
                                    ?,
                                    NOW()
                                )
                            ");

                            $stmt->execute([
                                $userId,
                                $_SESSION['user_id'],
                                'Admin mengaktifkan kembali akses Smelter yang sebelumnya dicabut: '
                                . $smelter['name']
                            ]);

                            $pdo->commit();

                            $success =
                                'Akses Smelter berhasil diaktifkan kembali.';

                        } else {


                            /*
                            |--------------------------------------------------------------------------
                            | BELUM PERNAH ADA → INSERT BARU
                            |--------------------------------------------------------------------------
                            */

                            $stmt = $pdo->prepare("
                                INSERT INTO user_access (
                                    user_id,
                                    smelter_id,
                                    team_id,
                                    status,
                                    granted_by,
                                    granted_at,
                                    created_at,
                                    updated_at
                                )
                                VALUES (
                                    ?,
                                    ?,
                                    NULL,
                                    'active',
                                    ?,
                                    NOW(),
                                    NOW(),
                                    NOW()
                                )
                            ");

                            $stmt->execute([
                                $userId,
                                $smelterId,
                                $_SESSION['user_id']
                            ]);


                            /*
                            |--------------------------------------------------------------------------
                            | HISTORY
                            |--------------------------------------------------------------------------
                            */

                            $stmt = $pdo->prepare("
                                INSERT INTO user_approvals (
                                    user_id,
                                    approved_by,
                                    action,
                                    notes,
                                    created_at
                                )
                                VALUES (
                                    ?,
                                    ?,
                                    'approved',
                                    ?,
                                    NOW()
                                )
                            ");

                            $stmt->execute([
                                $userId,
                                $_SESSION['user_id'],
                                'Admin memberikan akses manual Smelter: '
                                . $smelter['name']
                            ]);

                            $pdo->commit();

                            $success =
                                'Akses Smelter berhasil diberikan.';
                        }

                    } catch (Throwable $e) {

                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error = $e->getMessage();
                    }
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | REVOKE SPV - SMELTER
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'revoke_smelter') {

            if ($user['role_name'] !== 'spv') {

                $error = 'Aksi ini hanya untuk user SPV.';

            } else {

                $accessId = filter_input(
                    INPUT_POST,
                    'access_id',
                    FILTER_VALIDATE_INT
                );

                if (!$accessId) {

                    $error = 'Access ID tidak valid.';

                } else {

                    try {

                        $pdo->beginTransaction();


                        /*
                        |--------------------------------------------------------------------------
                        | AMBIL ACCESS
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            SELECT
                                ua.id,
                                ua.smelter_id,
                                ua.team_id,
                                ua.status,
                                s.name AS smelter_name
                            FROM user_access ua
                            INNER JOIN smelters s
                                ON s.id = ua.smelter_id
                            WHERE ua.id = ?
                              AND ua.user_id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $accessId,
                            $userId
                        ]);

                        $access = $stmt->fetch();

                        if (!$access) {
                            throw new Exception(
                                'Record akses tidak ditemukan.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | PASTIKAN INI ACCESS SPV
                        |--------------------------------------------------------------------------
                        */

                        if ($access['team_id'] !== null) {
                            throw new Exception(
                                'Record tersebut bukan akses Smelter SPV.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CEK STATUS
                        |--------------------------------------------------------------------------
                        */

                        if ($access['status'] !== 'active') {
                            throw new Exception(
                                'Akses tersebut sudah tidak aktif.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | REVOKE
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE user_access
                            SET
                                status = 'revoked',
                                revoked_by = ?,
                                revoked_at = NOW(),
                                updated_at = NOW()
                            WHERE id = ?
                              AND user_id = ?
                              AND status = 'active'
                        ");

                        $stmt->execute([
                            $_SESSION['user_id'],
                            $accessId,
                            $userId
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | HISTORY
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO user_approvals (
                                user_id,
                                approved_by,
                                action,
                                notes,
                                created_at
                            )
                            VALUES (
                                ?,
                                ?,
                                'suspended',
                                ?,
                                NOW()
                            )
                        ");

                        $stmt->execute([
                            $userId,
                            $_SESSION['user_id'],
                            'Admin mencabut akses manual Smelter: '
                            . $access['smelter_name']
                        ]);

                        $pdo->commit();

                        $success =
                            'Akses Smelter berhasil dicabut.';

                    } catch (Throwable $e) {

                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error = $e->getMessage();
                    }
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | GRANT FOREMAN - TEAM
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'grant_team') {

            if ($user['role_name'] !== 'foreman') {

                $error = 'Aksi ini hanya untuk user Foreman.';

            } elseif ($user['status'] !== 'active') {

                $error = 'User harus berstatus Active untuk diberikan akses.';

            } else {

                $smelterId = filter_input(
                    INPUT_POST,
                    'smelter_id',
                    FILTER_VALIDATE_INT
                );

                $teamId = filter_input(
                    INPUT_POST,
                    'team_id',
                    FILTER_VALIDATE_INT
                );

                if (!$smelterId || !$teamId) {

                    $error = 'Smelter dan Team wajib dipilih.';

                } else {

                    try {

                        $pdo->beginTransaction();


                        /*
                        |--------------------------------------------------------------------------
                        | CEK SMELTER
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            SELECT
                                s.id,
                                s.name,
                                s.division_id,
                                s.status
                            FROM smelters s
                            WHERE s.id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $smelterId
                        ]);

                        $smelter = $stmt->fetch();

                        if (!$smelter) {
                            throw new Exception(
                                'Smelter tidak ditemukan.'
                            );
                        }

                        if ($smelter['status'] !== 'active') {
                            throw new Exception(
                                'Smelter tidak aktif.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CEK DIVISI
                        |--------------------------------------------------------------------------
                        */

                        if (
                            (int) $smelter['division_id']
                            !==
                            (int) $user['division_id']
                        ) {
                            throw new Exception(
                                'Smelter harus berada dalam divisi user.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CEK TEAM
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            SELECT
                                t.id,
                                t.name,
                                t.smelter_id,
                                t.status
                            FROM teams t
                            WHERE t.id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $teamId
                        ]);

                        $team = $stmt->fetch();

                        if (!$team) {
                            throw new Exception(
                                'Team tidak ditemukan.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | TEAM HARUS MILIK SMELTER
                        |--------------------------------------------------------------------------
                        */

                        if (
                            (int) $team['smelter_id']
                            !==
                            (int) $smelterId
                        ) {
                            throw new Exception(
                                'Team tidak berada pada Smelter yang dipilih.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | TEAM HARUS AKTIF
                        |--------------------------------------------------------------------------
                        */

                        if ($team['status'] !== 'active') {
                            throw new Exception(
                                'Team tidak aktif.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CARI ACCESS YANG SUDAH PERNAH ADA
                        |--------------------------------------------------------------------------
                        |
                        | Jangan hanya mencari status ACTIVE.
                        |
                        | Jika REVOKED ditemukan, kita aktifkan
                        | kembali record lama.
                        |
                        */

                        $stmt = $pdo->prepare("
                            SELECT
                                id,
                                status
                            FROM user_access
                            WHERE user_id = ?
                              AND smelter_id = ?
                              AND team_id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $userId,
                            $smelterId,
                            $teamId
                        ]);

                        $existingAccess = $stmt->fetch();


                        /*
                        |--------------------------------------------------------------------------
                        | SUDAH ACTIVE
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $existingAccess &&
                            $existingAccess['status'] === 'active'
                        ) {
                            throw new Exception(
                                'User sudah memiliki akses aktif ke Team tersebut.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | REACTIVATE ACCESS REVOKED
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $existingAccess &&
                            $existingAccess['status'] === 'revoked'
                        ) {

                            $stmt = $pdo->prepare("
                                UPDATE user_access
                                SET
                                    status = 'active',
                                    granted_by = ?,
                                    granted_at = NOW(),
                                    revoked_by = NULL,
                                    revoked_at = NULL,
                                    updated_at = NOW()
                                WHERE id = ?
                                  AND user_id = ?
                            ");

                            $stmt->execute([
                                $_SESSION['user_id'],
                                $existingAccess['id'],
                                $userId
                            ]);


                            /*
                            |--------------------------------------------------------------------------
                            | HISTORY
                            |--------------------------------------------------------------------------
                            */

                            $stmt = $pdo->prepare("
                                INSERT INTO user_approvals (
                                    user_id,
                                    approved_by,
                                    action,
                                    notes,
                                    created_at
                                )
                                VALUES (
                                    ?,
                                    ?,
                                    'approved',
                                    ?,
                                    NOW()
                                )
                            ");

                            $stmt->execute([
                                $userId,
                                $_SESSION['user_id'],
                                'Admin mengaktifkan kembali akses Team yang sebelumnya dicabut: '
                                . $team['name']
                                . ' pada Smelter: '
                                . $smelter['name']
                            ]);

                            $pdo->commit();

                            $success =
                                'Akses Team berhasil diaktifkan kembali.';

                        } else {


                            /*
                            |--------------------------------------------------------------------------
                            | BELUM PERNAH ADA → INSERT BARU
                            |--------------------------------------------------------------------------
                            */

                            $stmt = $pdo->prepare("
                                INSERT INTO user_access (
                                    user_id,
                                    smelter_id,
                                    team_id,
                                    status,
                                    granted_by,
                                    granted_at,
                                    created_at,
                                    updated_at
                                )
                                VALUES (
                                    ?,
                                    ?,
                                    ?,
                                    'active',
                                    ?,
                                    NOW(),
                                    NOW(),
                                    NOW()
                                )
                            ");

                            $stmt->execute([
                                $userId,
                                $smelterId,
                                $teamId,
                                $_SESSION['user_id']
                            ]);


                            /*
                            |--------------------------------------------------------------------------
                            | HISTORY
                            |--------------------------------------------------------------------------
                            */

                            $stmt = $pdo->prepare("
                                INSERT INTO user_approvals (
                                    user_id,
                                    approved_by,
                                    action,
                                    notes,
                                    created_at
                                )
                                VALUES (
                                    ?,
                                    ?,
                                    'approved',
                                    ?,
                                    NOW()
                                )
                            ");

                            $stmt->execute([
                                $userId,
                                $_SESSION['user_id'],
                                'Admin memberikan akses manual Team: '
                                . $team['name']
                                . ' pada Smelter: '
                                . $smelter['name']
                            ]);

                            $pdo->commit();

                            $success =
                                'Akses Team berhasil diberikan.';
                        }

                    } catch (Throwable $e) {

                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error = $e->getMessage();
                    }
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | REVOKE FOREMAN - TEAM
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'revoke_team') {

            if ($user['role_name'] !== 'foreman') {

                $error = 'Aksi ini hanya untuk user Foreman.';

            } else {

                $accessId = filter_input(
                    INPUT_POST,
                    'access_id',
                    FILTER_VALIDATE_INT
                );

                if (!$accessId) {

                    $error = 'Access ID tidak valid.';

                } else {

                    try {

                        $pdo->beginTransaction();


                        /*
                        |--------------------------------------------------------------------------
                        | AMBIL ACCESS
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            SELECT
                                ua.id,
                                ua.smelter_id,
                                ua.team_id,
                                ua.status,
                                s.name AS smelter_name,
                                t.name AS team_name
                            FROM user_access ua
                            INNER JOIN smelters s
                                ON s.id = ua.smelter_id
                            LEFT JOIN teams t
                                ON t.id = ua.team_id
                            WHERE ua.id = ?
                              AND ua.user_id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $accessId,
                            $userId
                        ]);

                        $access = $stmt->fetch();

                        if (!$access) {
                            throw new Exception(
                                'Record akses tidak ditemukan.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | PASTIKAN ACCESS FOREMAN
                        |--------------------------------------------------------------------------
                        */

                        if ($access['team_id'] === null) {
                            throw new Exception(
                                'Record tersebut bukan akses Team Foreman.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | CEK STATUS
                        |--------------------------------------------------------------------------
                        */

                        if ($access['status'] !== 'active') {
                            throw new Exception(
                                'Akses tersebut sudah tidak aktif.'
                            );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | REVOKE
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            UPDATE user_access
                            SET
                                status = 'revoked',
                                revoked_by = ?,
                                revoked_at = NOW(),
                                updated_at = NOW()
                            WHERE id = ?
                              AND user_id = ?
                              AND status = 'active'
                        ");

                        $stmt->execute([
                            $_SESSION['user_id'],
                            $accessId,
                            $userId
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | HISTORY
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO user_approvals (
                                user_id,
                                approved_by,
                                action,
                                notes,
                                created_at
                            )
                            VALUES (
                                ?,
                                ?,
                                'suspended',
                                ?,
                                NOW()
                            )
                        ");

                        $stmt->execute([
                            $userId,
                            $_SESSION['user_id'],
                            'Admin mencabut akses manual Team: '
                            . ($access['team_name'] ?? '-')
                            . ' pada Smelter: '
                            . $access['smelter_name']
                        ]);

                        $pdo->commit();

                        $success =
                            'Akses Team berhasil dicabut.';

                    } catch (Throwable $e) {

                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error = $e->getMessage();
                    }
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | ACTION TIDAK DIKENAL
        |--------------------------------------------------------------------------
        */

        elseif ($action !== '') {

            $error = 'Aksi tidak dikenali.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REDIRECT SETELAH PROSES
    |--------------------------------------------------------------------------
    */

    if ($success !== '') {

        $_SESSION['flash_success'] = $success;

        header(
            'Location: user-access?id=' . $userId
        );

        exit;
    }

    if ($error !== '') {

        $_SESSION['flash_error'] = $error;

        header(
            'Location: user-access?id=' . $userId
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/

$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error'] ?? '';

unset(
    $_SESSION['flash_success'],
    $_SESSION['flash_error']
);


/*
|--------------------------------------------------------------------------
| AMBIL SEMUA ACCESS USER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ua.id AS access_id,
        ua.smelter_id,
        ua.team_id,
        ua.status,
        ua.granted_at,
        ua.revoked_at,

        s.name AS smelter_name,

        t.name AS team_name,

        d.name AS division_name,

        granted.name AS granted_by_name,

        revoked.name AS revoked_by_name

    FROM user_access ua

    INNER JOIN smelters s
        ON s.id = ua.smelter_id

    INNER JOIN divisions d
        ON d.id = s.division_id

    LEFT JOIN teams t
        ON t.id = ua.team_id

    LEFT JOIN users granted
        ON granted.id = ua.granted_by

    LEFT JOIN users revoked
        ON revoked.id = ua.revoked_by

    WHERE ua.user_id = ?

    ORDER BY
        CASE
            WHEN ua.status = 'active' THEN 0
            ELSE 1
        END,
        d.name ASC,
        s.name ASC,
        t.name ASC,
        ua.id DESC
");

$stmt->execute([
    $userId
]);

$userAccess = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| SPV: AMBIL SMELTER DALAM DIVISI USER
|--------------------------------------------------------------------------
*/

$smelters = [];

if ($user['role_name'] === 'spv') {

    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.name,
            s.status,
            d.name AS division_name
        FROM smelters s

        INNER JOIN divisions d
            ON d.id = s.division_id

        WHERE s.division_id = ?

        ORDER BY s.name ASC
    ");

    $stmt->execute([
        $user['division_id']
    ]);

    $smelters = $stmt->fetchAll();
}


/*
|--------------------------------------------------------------------------
| FOREMAN: AMBIL SMELTER DALAM DIVISI USER
|--------------------------------------------------------------------------
*/

$foremanSmelters = [];

if ($user['role_name'] === 'foreman') {

    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.name,
            s.status
        FROM smelters s

        WHERE s.division_id = ?
          AND s.status = 'active'

        ORDER BY s.name ASC
    ");

    $stmt->execute([
        $user['division_id']
    ]);

    $foremanSmelters = $stmt->fetchAll();
}


/*
|--------------------------------------------------------------------------
| HITUNG ACTIVE ACCESS
|--------------------------------------------------------------------------
*/

$activeAccess = 0;

foreach ($userAccess as $access) {

    if ($access['status'] === 'active') {
        $activeAccess++;
    }
}

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Kelola Access - <?= e($user['name']) ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body class="bg-light">


<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <a
            href="dashboard"
            class="navbar-brand"
        >
            Smelter Management
        </a>

        <div class="text-white">

            <?= e($_SESSION['name'] ?? 'Admin') ?>

            &nbsp; | &nbsp;

            <a
                href="../auth/logout"
                class="text-white"
            >
                Logout
            </a>

        </div>

    </div>

</nav>


<div class="container-fluid py-4">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="mb-1">
                Kelola Access
            </h2>

            <p class="text-muted mb-0">
                Kelola akses Smelter / Team user secara manual.
            </p>

        </div>

        <div>

            <a
                href="user-detail?id=<?= (int) $user['id'] ?>"
                class="btn btn-secondary"
            >
                ← Detail User
            </a>

            <a
                href="users"
                class="btn btn-outline-secondary"
            >
                Daftar User
            </a>

        </div>

    </div>


    <!-- =========================================================
         FLASH MESSAGE
    ========================================================== -->

    <?php if ($success): ?>

        <div
            class="alert alert-success alert-dismissible fade show"
            role="alert"
        >

            <?= e($success) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div
            class="alert alert-danger alert-dismissible fade show"
            role="alert"
        >

            <?= e($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         USER INFORMATION
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Informasi User
            </strong>

        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-3">

                    <small class="text-muted">
                        NIK
                    </small>

                    <div>
                        <strong>
                            <?= e($user['nik']) ?>
                        </strong>
                    </div>

                </div>


                <div class="col-md-3">

                    <small class="text-muted">
                        Nama
                    </small>

                    <div>
                        <?= e($user['name']) ?>
                    </div>

                </div>


                <div class="col-md-3">

                    <small class="text-muted">
                        Role
                    </small>

                    <div>

                        <?php if ($user['role_name'] === 'spv'): ?>

                            <span class="badge bg-primary">
                                SPV
                            </span>

                        <?php elseif ($user['role_name'] === 'foreman'): ?>

                            <span class="badge bg-info">
                                Foreman
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <div class="col-md-3">

                    <small class="text-muted">
                        Status
                    </small>

                    <div>

                        <?php if ($user['status'] === 'active'): ?>

                            <span class="badge bg-success">
                                Active
                            </span>

                        <?php elseif ($user['status'] === 'pending'): ?>

                            <span class="badge bg-warning text-dark">
                                Pending
                            </span>

                        <?php elseif ($user['status'] === 'suspended'): ?>

                            <span class="badge bg-secondary">
                                Suspended
                            </span>

                        <?php elseif ($user['status'] === 'rejected'): ?>

                            <span class="badge bg-danger">
                                Rejected
                            </span>

                        <?php elseif ($user['status'] === 'deleted'): ?>

                            <span class="badge bg-dark">
                                Deleted
                            </span>

                        <?php else: ?>

                            <span class="badge bg-light text-dark">
                                <?= e($user['status']) ?>
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <div class="col-md-6">

                    <small class="text-muted">
                        Email
                    </small>

                    <div>
                        <?= e($user['email']) ?>
                    </div>

                </div>


                <div class="col-md-6">

                    <small class="text-muted">
                        Divisi
                    </small>

                    <div>
                        <?= e($user['division_name'] ?? '-') ?>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         WARNING USER NON ACTIVE
    ========================================================== -->

    <?php if ($user['status'] !== 'active'): ?>

        <div class="alert alert-warning">

            <strong>User belum Active.</strong>

            Grant access manual hanya diperbolehkan untuk user
            yang berstatus <strong>Active</strong>.

        </div>

    <?php endif; ?>


    <!-- =========================================================
         GRANT ACCESS SPV
    ========================================================== -->

    <?php if ($user['role_name'] === 'spv'): ?>

        <div class="card shadow-sm mb-4">

            <div class="card-header bg-primary text-white">

                <strong>
                    Grant Access Smelter untuk SPV
                </strong>

            </div>

            <div class="card-body">

                <p class="text-muted">

                    SPV mendapatkan akses ke seluruh Team yang
                    berada di dalam Smelter yang dipilih.

                </p>


                <form
                    method="POST"
                    class="row g-3"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="grant_smelter"
                    >


                    <div class="col-md-8">

                        <label class="form-label">
                            Smelter
                        </label>

                        <select
                            name="smelter_id"
                            class="form-select"
                            required
                            <?= $user['status'] !== 'active' ? 'disabled' : '' ?>
                        >

                            <option value="">
                                -- Pilih Smelter --
                            </option>

                            <?php foreach ($smelters as $smelter): ?>

                                <option
                                    value="<?= (int) $smelter['id'] ?>"
                                    <?= $smelter['status'] !== 'active' ? 'disabled' : '' ?>
                                >

                                    <?= e($smelter['name']) ?>

                                    <?php if ($smelter['status'] !== 'active'): ?>

                                        (Inactive)

                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-md-4 d-flex align-items-end">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                            <?= $user['status'] !== 'active' ? 'disabled' : '' ?>
                        >

                            + Grant Smelter

                        </button>

                    </div>

                </form>

            </div>

        </div>


    <?php elseif ($user['role_name'] === 'foreman'): ?>


        <!-- =====================================================
             GRANT ACCESS FOREMAN
        ====================================================== -->

        <div class="card shadow-sm mb-4">

            <div class="card-header bg-primary text-white">

                <strong>
                    Grant Access Team untuk Foreman
                </strong>

            </div>

            <div class="card-body">

                <p class="text-muted">

                    Foreman hanya mendapatkan akses ke Team
                    yang dipilih secara spesifik.

                </p>


                <form
                    method="POST"
                    class="row g-3"
                    id="grantTeamForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="grant_team"
                    >


                    <div class="col-md-4">

                        <label class="form-label">
                            Smelter
                        </label>

                        <select
                            name="smelter_id"
                            id="grant_smelter_id"
                            class="form-select"
                            required
                            <?= $user['status'] !== 'active' ? 'disabled' : '' ?>
                        >

                            <option value="">
                                -- Pilih Smelter --
                            </option>

                            <?php foreach ($foremanSmelters as $smelter): ?>

                                <option
                                    value="<?= (int) $smelter['id'] ?>"
                                >
                                    <?= e($smelter['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-md-5">

                        <label class="form-label">
                            Team
                        </label>

                        <select
                            name="team_id"
                            id="grant_team_id"
                            class="form-select"
                            required
                            disabled
                        >

                            <option value="">
                                -- Pilih Smelter Terlebih Dahulu --
                            </option>

                        </select>

                    </div>


                    <div class="col-md-3 d-flex align-items-end">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                            <?= $user['status'] !== 'active' ? 'disabled' : '' ?>
                        >

                            + Grant Team

                        </button>

                    </div>

                </form>

            </div>

        </div>


    <?php endif; ?>


    <!-- =========================================================
         ACCESS USER
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header d-flex justify-content-between">

            <strong>
                Access User
            </strong>

            <span class="badge bg-success">

                <?= $activeAccess ?>
                Active

            </span>

        </div>


        <div class="card-body">

            <?php if (!$userAccess): ?>

                <div class="alert alert-secondary mb-0">

                    User belum mempunyai record access.

                </div>

            <?php else: ?>


                <div class="table-responsive">

                    <table
                        class="table table-bordered table-hover align-middle"
                    >

                        <thead class="table-dark">

                            <tr>

                                <th>
                                    Divisi
                                </th>

                                <th>
                                    Smelter
                                </th>

                                <th>
                                    Team
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Diberikan
                                </th>

                                <th>
                                    Oleh
                                </th>

                                <th>
                                    Dicabut
                                </th>

                                <th width="120">
                                    Aksi
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($userAccess as $access): ?>

                            <tr>

                                <td>
                                    <?= e($access['division_name']) ?>
                                </td>


                                <td>
                                    <?= e($access['smelter_name']) ?>
                                </td>


                                <td>

                                    <?php if ($user['role_name'] === 'spv'): ?>

                                        <span class="text-muted">
                                            Semua Team
                                        </span>

                                    <?php else: ?>

                                        <?= e(
                                            $access['team_name'] ?? '-'
                                        ) ?>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php if (
                                        $access['status'] === 'active'
                                    ): ?>

                                        <span class="badge bg-success">
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-secondary">
                                            Revoked
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>
                                    <?= e(
                                        $access['granted_at'] ?? '-'
                                    ) ?>
                                </td>


                                <td>
                                    <?= e(
                                        $access['granted_by_name'] ?? '-'
                                    ) ?>
                                </td>


                                <td>
                                    <?= e(
                                        $access['revoked_at'] ?? '-'
                                    ) ?>
                                </td>


                                <td>

                                    <?php if (
                                        $access['status'] === 'active'
                                    ): ?>


                                        <?php if (
                                            $user['role_name'] === 'spv'
                                        ): ?>

                                            <form
                                                method="POST"
                                                onsubmit="return confirm(
                                                    'Yakin ingin mencabut akses Smelter ini?'
                                                );"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="revoke_smelter"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="access_id"
                                                    value="<?= (int) $access['access_id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-danger"
                                                >
                                                    Revoke
                                                </button>

                                            </form>


                                        <?php else: ?>


                                            <form
                                                method="POST"
                                                onsubmit="return confirm(
                                                    'Yakin ingin mencabut akses Team ini?'
                                                );"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="revoke_team"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="access_id"
                                                    value="<?= (int) $access['access_id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-danger"
                                                >
                                                    Revoke
                                                </button>

                                            </form>


                                        <?php endif; ?>


                                    <?php else: ?>

                                        <span class="text-muted">
                                            -
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         INFORMASI ATURAN ACCESS
    ========================================================== -->

    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                Aturan Access
            </strong>

        </div>

        <div class="card-body">

            <?php if ($user['role_name'] === 'spv'): ?>

                <ul class="mb-0">

                    <li>
                        SPV hanya dapat diberikan akses Smelter
                        dalam Divisinya.
                    </li>

                    <li>
                        Akses Smelter SPV berarti akses ke seluruh
                        Team aktif di Smelter tersebut.
                    </li>

                    <li>
                        Satu Smelter tidak boleh mempunyai dua
                        record access aktif untuk user yang sama.
                    </li>

                    <li>
                        Jika akses sebelumnya Revoked, Grant ulang
                        akan mengaktifkan kembali record tersebut.
                    </li>

                    <li>
                        Revoke tidak menghapus record access.
                        Record akan disimpan sebagai histori.
                    </li>

                </ul>


            <?php elseif ($user['role_name'] === 'foreman'): ?>

                <ul class="mb-0">

                    <li>
                        Foreman hanya dapat diberikan akses Team
                        dalam Divisinya.
                    </li>

                    <li>
                        Team harus berasal dari Smelter yang dipilih.
                    </li>

                    <li>
                        Foreman hanya dapat melihat Team yang
                        diberikan secara eksplisit.
                    </li>

                    <li>
                        Satu Team tidak boleh mempunyai dua
                        record access aktif untuk user yang sama.
                    </li>

                    <li>
                        Jika akses sebelumnya Revoked, Grant ulang
                        akan mengaktifkan kembali record tersebut.
                    </li>

                    <li>
                        Revoke tidak menghapus record access.
                        Record akan disimpan sebagai histori.
                    </li>

                </ul>

            <?php endif; ?>

        </div>

    </div>


</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

/*
|--------------------------------------------------------------------------
| FOREMAN - LOAD TEAM
|--------------------------------------------------------------------------
*/

const grantSmelter = document.getElementById(
    'grant_smelter_id'
);

const grantTeam = document.getElementById(
    'grant_team_id'
);


if (grantSmelter && grantTeam) {

    grantSmelter.addEventListener(
        'change',
        async function () {

            const smelterId = this.value;

            grantTeam.innerHTML =
                '<option value="">Memuat Team...</option>';

            grantTeam.disabled = true;


            if (!smelterId) {

                grantTeam.innerHTML =
                    '<option value="">-- Pilih Smelter Terlebih Dahulu --</option>';

                return;
            }


            try {

                const response = await fetch(
                    '../api/get-register-teams?smelter_id='
                    + encodeURIComponent(smelterId)
                );


                const result = await response.json();


                if (
                    !result.success ||
                    !Array.isArray(result.data)
                ) {

                    throw new Error(
                        result.message ||
                        'Gagal mengambil Team.'
                    );
                }


                grantTeam.innerHTML =
                    '<option value="">-- Pilih Team --</option>';


                if (result.data.length === 0) {

                    grantTeam.innerHTML =
                        '<option value="">Tidak ada Team aktif</option>';

                    grantTeam.disabled = true;

                    return;
                }


                result.data.forEach(
                    function (team) {

                        const option =
                            document.createElement('option');

                        option.value = team.id;
                        option.textContent = team.name;

                        grantTeam.appendChild(option);
                    }
                );


                grantTeam.disabled = false;


            } catch (error) {

                console.error(error);

                grantTeam.innerHTML =
                    '<option value="">Gagal memuat Team</option>';

                grantTeam.disabled = true;
            }

        }
    );

}

</script>


</body>

</html>