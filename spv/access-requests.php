<?php

/**
 * SPV - ACCESS REQUESTS
 *
 * Fungsi:
 * 1. SPV dapat request Smelter tambahan dalam divisi yang sama.
 * 2. SPV dapat approve/reject request Team tambahan dari Foreman
 *    hanya untuk Smelter yang memang dikuasai SPV.
 * 3. Menggunakan CSRF protection.
 * 4. Menggunakan prepared statement.
 * 5. Mendukung reactivation user_access yang sebelumnya revoked.
 */

require_once __DIR__ . '/../middleware/spv.php';
require_once __DIR__ . '/../config/database.php';

$userId = (int) $_SESSION['user_id'];


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
| AMBIL DATA SPV
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.division_id,
        d.name AS division_name
    FROM users u
    INNER JOIN divisions d
        ON d.id = u.division_id
    WHERE u.id = ?
      AND u.status = 'active'
      AND d.status = 'active'
    LIMIT 1
");

$stmt->execute([$userId]);

$spv = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spv) {
    http_response_code(403);
    exit('Data SPV tidak ditemukan atau akun tidak aktif.');
}

$divisionId = (int) $spv['division_id'];
$divisionName = $spv['division_name'];

$error = '';


/*
|--------------------------------------------------------------------------
| HELPER REDIRECT
|--------------------------------------------------------------------------
*/

function redirectSuccess(string $type): void
{
    header(
        'Location: access-requests?success=' .
        urlencode($type)
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| POST PROCESSING
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | VALIDASI CSRF
    |--------------------------------------------------------------------------
    */

    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        empty($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Request tidak valid. Silakan muat ulang halaman.');
    }


    $action = trim($_POST['action'] ?? '');


    /*
    |--------------------------------------------------------------------------
    | APPROVE REQUEST TEAM FOREMAN
    |--------------------------------------------------------------------------
    */

    if ($action === 'approve_team') {

        $requestId = (int) ($_POST['request_id'] ?? 0);

        try {

            if ($requestId <= 0) {
                throw new Exception('Request tidak valid.');
            }

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Ambil request dan lock row
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    ar.id,
                    ar.user_id,
                    ar.smelter_id,
                    ar.team_id,
                    ar.request_type,
                    ar.status,

                    u.division_id AS user_division_id,
                    u.status AS user_status,

                    r.name AS role_name,

                    s.division_id AS smelter_division_id,
                    s.status AS smelter_status

                FROM access_requests ar

                INNER JOIN users u
                    ON u.id = ar.user_id

                INNER JOIN roles r
                    ON r.id = u.role_id

                INNER JOIN smelters s
                    ON s.id = ar.smelter_id

                WHERE ar.id = ?

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([$requestId]);

            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception(
                    'Request tidak ditemukan.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validasi jenis request
            |--------------------------------------------------------------------------
            */

            if (
                $request['request_type']
                !== 'additional_team'
            ) {
                throw new Exception(
                    'Jenis request tidak valid.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validasi status request
            |--------------------------------------------------------------------------
            */

            if (
                $request['status']
                !== 'pending'
            ) {
                throw new Exception(
                    'Request ini sudah diproses.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validasi Foreman
            |--------------------------------------------------------------------------
            */

            if (
                $request['role_name']
                !== 'foreman'
            ) {
                throw new Exception(
                    'Request hanya dapat diajukan oleh Foreman.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validasi status Foreman
            |--------------------------------------------------------------------------
            */

            if (
                $request['user_status']
                !== 'active'
            ) {
                throw new Exception(
                    'Akun Foreman tidak aktif.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validasi Smelter aktif
            |--------------------------------------------------------------------------
            */

            if (
                $request['smelter_status']
                !== 'active'
            ) {
                throw new Exception(
                    'Smelter tujuan tidak aktif.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Foreman dan Smelter harus satu divisi
            |--------------------------------------------------------------------------
            */

            if (
                (int) $request['user_division_id']
                !==
                (int) $request['smelter_division_id']
            ) {
                throw new Exception(
                    'Foreman tidak berada pada divisi Smelter tersebut.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Smelter harus berada di divisi SPV
            |--------------------------------------------------------------------------
            */

            if (
                (int) $request['smelter_division_id']
                !== $divisionId
            ) {
                throw new Exception(
                    'Request berada di luar divisi Anda.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | SPV HARUS menguasai Smelter tersebut
            |--------------------------------------------------------------------------
            |
            | SPV access:
            | team_id IS NULL
            | status = active
            |
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM user_access
                WHERE user_id = ?
                  AND smelter_id = ?
                  AND team_id IS NULL
                  AND status = 'active'
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $userId,
                $request['smelter_id']
            ]);

            if (!$stmt->fetchColumn()) {
                throw new Exception(
                    'Anda tidak memiliki kewenangan untuk Smelter tersebut.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validasi Team
            |--------------------------------------------------------------------------
            */

            if (
                empty($request['team_id'])
            ) {
                throw new Exception(
                    'Team pada request tidak valid.'
                );
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM teams
                WHERE id = ?
                  AND smelter_id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $stmt->execute([
                $request['team_id'],
                $request['smelter_id']
            ]);

            if (!$stmt->fetchColumn()) {
                throw new Exception(
                    'Team tidak valid atau bukan bagian dari Smelter tersebut.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CEK USER_ACCESS YANG SUDAH PERNAH ADA
            |--------------------------------------------------------------------------
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
                FOR UPDATE
            ");

            $stmt->execute([
                $request['user_id'],
                $request['smelter_id'],
                $request['team_id']
            ]);

            $existingAccess = $stmt->fetch(PDO::FETCH_ASSOC);


            /*
            |--------------------------------------------------------------------------
            | Jika sudah aktif -> tolak
            |--------------------------------------------------------------------------
            */

            if (
                $existingAccess &&
                $existingAccess['status'] === 'active'
            ) {
                throw new Exception(
                    'Foreman sudah memiliki akses ke Team tersebut.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Jika revoked -> REACTIVATE
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
                      AND status = 'revoked'
                ");

                $stmt->execute([
                    $userId,
                    $existingAccess['id']
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new Exception(
                        'Akses lama gagal diaktifkan kembali.'
                    );
                }


            /*
            |--------------------------------------------------------------------------
            | Jika belum pernah ada -> INSERT
            |--------------------------------------------------------------------------
            */

            } elseif (!$existingAccess) {

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
                    $request['user_id'],
                    $request['smelter_id'],
                    $request['team_id'],
                    $userId
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | UPDATE REQUEST
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE access_requests
                SET
                    status = 'approved',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
                  AND status = 'pending'
            ");

            $stmt->execute([
                $userId,
                $requestId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new Exception(
                    'Request gagal diperbarui.'
                );
            }


            $pdo->commit();

            redirectSuccess('team_approved');


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'SPV approve team error: ' .
                $e->getMessage()
            );

            $error = $e->getMessage();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REJECT REQUEST TEAM FOREMAN
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'reject_team') {

        $requestId = (int) ($_POST['request_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        try {

            if ($requestId <= 0) {
                throw new Exception(
                    'Request tidak valid.'
                );
            }

            if ($reason === '') {
                throw new Exception(
                    'Alasan penolakan wajib diisi.'
                );
            }

            if (mb_strlen($reason) > 1000) {
                throw new Exception(
                    'Alasan penolakan terlalu panjang.'
                );
            }

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Ambil request dan lock
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    ar.id,
                    ar.smelter_id,
                    ar.request_type,
                    ar.status,

                    u.status AS user_status,
                    r.name AS role_name,

                    s.division_id AS smelter_division_id,
                    s.status AS smelter_status

                FROM access_requests ar

                INNER JOIN users u
                    ON u.id = ar.user_id

                INNER JOIN roles r
                    ON r.id = u.role_id

                INNER JOIN smelters s
                    ON s.id = ar.smelter_id

                WHERE ar.id = ?

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([$requestId]);

            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception(
                    'Request tidak ditemukan.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validasi request
            |--------------------------------------------------------------------------
            */

            if (
                $request['request_type']
                !== 'additional_team'
            ) {
                throw new Exception(
                    'Jenis request tidak valid.'
                );
            }

            if (
                $request['status']
                !== 'pending'
            ) {
                throw new Exception(
                    'Request ini sudah diproses.'
                );
            }

            if (
                $request['role_name']
                !== 'foreman'
            ) {
                throw new Exception(
                    'Request hanya dapat diajukan oleh Foreman.'
                );
            }

            if (
                $request['smelter_status']
                !== 'active'
            ) {
                throw new Exception(
                    'Smelter tujuan tidak aktif.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validasi divisi
            |--------------------------------------------------------------------------
            */

            if (
                (int) $request['smelter_division_id']
                !== $divisionId
            ) {
                throw new Exception(
                    'Request berada di luar divisi Anda.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan SPV menguasai Smelter
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM user_access
                WHERE user_id = ?
                  AND smelter_id = ?
                  AND team_id IS NULL
                  AND status = 'active'
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $userId,
                $request['smelter_id']
            ]);

            if (!$stmt->fetchColumn()) {
                throw new Exception(
                    'Anda tidak memiliki kewenangan untuk request tersebut.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Reject request
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE access_requests
                SET
                    status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW(),
                    rejected_reason = ?
                WHERE id = ?
                  AND status = 'pending'
            ");

            $stmt->execute([
                $userId,
                $reason,
                $requestId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new Exception(
                    'Request gagal ditolak.'
                );
            }


            $pdo->commit();

            redirectSuccess('team_rejected');


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'SPV reject team error: ' .
                $e->getMessage()
            );

            $error = $e->getMessage();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REQUEST SMELTER TAMBAHAN
    |--------------------------------------------------------------------------
    */

    elseif ($action === 'request_smelter') {

        $smelterId = (int) ($_POST['smelter_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        try {

            if ($smelterId <= 0) {
                throw new Exception(
                    'Smelter wajib dipilih.'
                );
            }

            if ($reason === '') {
                throw new Exception(
                    'Alasan permintaan wajib diisi.'
                );
            }

            if (mb_strlen($reason) > 1000) {
                throw new Exception(
                    'Alasan permintaan terlalu panjang.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan Smelter berada pada divisi SPV
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    division_id,
                    status
                FROM smelters
                WHERE id = ?
                  AND division_id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $stmt->execute([
                $smelterId,
                $divisionId
            ]);

            $smelter = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$smelter) {
                throw new Exception(
                    'Smelter tidak valid atau berada di divisi lain.'
                );
            }


            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Cek akses aktif
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM user_access
                WHERE user_id = ?
                  AND smelter_id = ?
                  AND team_id IS NULL
                  AND status = 'active'
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $userId,
                $smelterId
            ]);

            if ($stmt->fetchColumn()) {
                throw new Exception(
                    'Anda sudah memiliki akses ke Smelter tersebut.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Cek request pending
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM access_requests
                WHERE user_id = ?
                  AND smelter_id = ?
                  AND team_id IS NULL
                  AND request_type = 'additional_smelter'
                  AND status = 'pending'
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $userId,
                $smelterId
            ]);

            if ($stmt->fetchColumn()) {
                throw new Exception(
                    'Permintaan untuk Smelter tersebut masih menunggu approval.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Simpan request
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO access_requests (
                    user_id,
                    smelter_id,
                    team_id,
                    request_type,
                    status,
                    requested_reason,
                    created_at
                )
                VALUES (
                    ?,
                    ?,
                    NULL,
                    'additional_smelter',
                    'pending',
                    ?,
                    NOW()
                )
            ");

            $stmt->execute([
                $userId,
                $smelterId,
                $reason
            ]);


            $pdo->commit();

            redirectSuccess('requested');


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'SPV request smelter error: ' .
                $e->getMessage()
            );

            $error = $e->getMessage();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ACTION TIDAK DIKENAL
    |--------------------------------------------------------------------------
    */

    elseif ($action !== '') {

        $error = 'Action tidak valid.';
    }
}


/*
|--------------------------------------------------------------------------
| SMELTER YANG SUDAH DIMILIKI SPV
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.name
    FROM user_access ua

    INNER JOIN smelters s
        ON s.id = ua.smelter_id

    WHERE ua.user_id = ?
      AND ua.team_id IS NULL
      AND ua.status = 'active'
      AND s.status = 'active'

    ORDER BY s.name
");

$stmt->execute([$userId]);

$currentSmelters = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| SMELTER YANG TERSEDIA UNTUK DIMINTA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.name
    FROM smelters s

    WHERE s.division_id = ?
      AND s.status = 'active'

      AND NOT EXISTS (
          SELECT 1
          FROM user_access ua
          WHERE ua.user_id = ?
            AND ua.smelter_id = s.id
            AND ua.team_id IS NULL
            AND ua.status = 'active'
      )

    ORDER BY s.name
");

$stmt->execute([
    $divisionId,
    $userId
]);

$availableSmelters = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| RIWAYAT REQUEST SMELTER MILIK SPV
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ar.id,
        s.name AS smelter_name,
        ar.status,
        ar.requested_reason,
        ar.created_at,
        ar.approved_at,
        ar.rejected_reason
    FROM access_requests ar

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    WHERE ar.user_id = ?
      AND ar.request_type = 'additional_smelter'

    ORDER BY ar.created_at DESC
");

$stmt->execute([$userId]);

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| REQUEST TEAM TAMBAHAN FOREMAN
|--------------------------------------------------------------------------
|
| Hanya menampilkan request:
| - additional_team
| - pending
| - Smelter yang dikuasai SPV
|
*/

$stmt = $pdo->prepare("
    SELECT
        ar.id,
        ar.user_id,
        ar.smelter_id,
        ar.team_id,

        u.nik,
        u.name AS foreman_name,

        s.name AS smelter_name,
        t.name AS team_name,

        ar.requested_reason,
        ar.created_at

    FROM access_requests ar

    INNER JOIN users u
        ON u.id = ar.user_id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    INNER JOIN teams t
        ON t.id = ar.team_id

    WHERE ar.request_type = 'additional_team'
      AND ar.status = 'pending'

      AND EXISTS (
          SELECT 1
          FROM user_access ua
          WHERE ua.user_id = ?
            AND ua.smelter_id = ar.smelter_id
            AND ua.team_id IS NULL
            AND ua.status = 'active'
      )

    ORDER BY ar.created_at ASC
");

$stmt->execute([$userId]);

$teamRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <title>
        Request Access - SPV
    </title>

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <span class="navbar-brand">
            Request Access SPV
        </span>

        <div>

            <a
                href="dashboard"
                class="btn btn-outline-light btn-sm"
            >
                Dashboard
            </a>

        </div>

    </div>

</nav>


<div class="container mt-4 mb-5">


    <!-- =====================================================
         SUCCESS MESSAGE
    ====================================================== -->

    <?php if (isset($_GET['success'])): ?>

        <?php if ($_GET['success'] === 'requested'): ?>

            <div class="alert alert-success">
                Permintaan Smelter berhasil dikirim ke Admin.
            </div>

        <?php elseif ($_GET['success'] === 'team_approved'): ?>

            <div class="alert alert-success">
                Request Team Foreman berhasil disetujui.
            </div>

        <?php elseif ($_GET['success'] === 'team_rejected'): ?>

            <div class="alert alert-success">
                Request Team Foreman berhasil ditolak.
            </div>

        <?php endif; ?>

    <?php endif; ?>


    <!-- =====================================================
         ERROR MESSAGE
    ====================================================== -->

    <?php if (!empty($error)): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <!-- =====================================================
         DIVISION INFO
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-body">

            <div class="row">

                <div class="col-md-6">

                    <small class="text-muted">
                        Divisi
                    </small>

                    <div class="fw-bold">
                        <?= htmlspecialchars($divisionName) ?>
                    </div>

                </div>

                <div class="col-md-6">

                    <small class="text-muted">
                        User
                    </small>

                    <div class="fw-bold">
                        <?= htmlspecialchars($_SESSION['name'] ?? '') ?>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         CURRENT SMELTERS
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Smelter yang Anda Miliki
            </strong>

        </div>

        <div class="card-body">

            <?php if (!$currentSmelters): ?>

                <span class="text-muted">
                    Belum ada akses Smelter.
                </span>

            <?php else: ?>

                <?php foreach ($currentSmelters as $smelter): ?>

                    <span class="badge bg-primary me-2 mb-2">

                        <?= htmlspecialchars(
                            $smelter['name']
                        ) ?>

                    </span>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         APPROVAL TEAM FOREMAN
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Approval Team Tambahan Foreman
            </strong>

        </div>

        <div class="card-body">

            <?php if (!$teamRequests): ?>

                <div class="alert alert-info mb-0">

                    Tidak ada request Team tambahan
                    yang menunggu approval.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

                        <thead class="table-light">

                            <tr>

                                <th>
                                    Foreman
                                </th>

                                <th>
                                    NIK
                                </th>

                                <th>
                                    Smelter
                                </th>

                                <th>
                                    Team
                                </th>

                                <th>
                                    Alasan
                                </th>

                                <th>
                                    Tanggal
                                </th>

                                <th>
                                    Aksi
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($teamRequests as $request): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['foreman_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['nik']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['smelter_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['team_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['requested_reason'] ?? '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['created_at']
                                    ) ?>
                                </td>

                                <td>

                                    <div class="d-flex gap-2">


                                        <!-- =========================
                                             APPROVE
                                        ========================== -->

                                        <form
                                            method="POST"
                                            class="d-inline"
                                            onsubmit="return confirm('Setujui request Team ini?')"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve_team"
                                            >

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int) $request['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-success"
                                            >
                                                Approve
                                            </button>

                                        </form>


                                        <!-- =========================
                                             REJECT
                                        ========================== -->

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#rejectModal<?= (int) $request['id'] ?>"
                                        >
                                            Reject
                                        </button>

                                    </div>


                                    <!-- =========================
                                         REJECT MODAL
                                    ========================== -->

                                    <div
                                        class="modal fade"
                                        id="rejectModal<?= (int) $request['id'] ?>"
                                        tabindex="-1"
                                        aria-hidden="true"
                                    >

                                        <div class="modal-dialog">

                                            <div class="modal-content">

                                                <form method="POST">

                                                    <div class="modal-header">

                                                        <h5 class="modal-title">
                                                            Tolak Request Team
                                                        </h5>

                                                        <button
                                                            type="button"
                                                            class="btn-close"
                                                            data-bs-dismiss="modal"
                                                        ></button>

                                                    </div>


                                                    <div class="modal-body">

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= htmlspecialchars($csrfToken) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="reject_team"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="request_id"
                                                            value="<?= (int) $request['id'] ?>"
                                                        >


                                                        <div class="mb-3">

                                                            <label class="form-label">

                                                                Alasan Penolakan

                                                            </label>

                                                            <textarea
                                                                name="reason"
                                                                class="form-control"
                                                                rows="4"
                                                                maxlength="1000"
                                                                required
                                                            ></textarea>

                                                        </div>

                                                    </div>


                                                    <div class="modal-footer">

                                                        <button
                                                            type="button"
                                                            class="btn btn-secondary"
                                                            data-bs-dismiss="modal"
                                                        >
                                                            Batal
                                                        </button>

                                                        <button
                                                            type="submit"
                                                            class="btn btn-danger"
                                                        >
                                                            Tolak Request
                                                        </button>

                                                    </div>

                                                </form>

                                            </div>

                                        </div>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         REQUEST SMELTER TAMBAHAN
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Request Smelter Tambahan
            </strong>

        </div>

        <div class="card-body">

            <?php if (!$availableSmelters): ?>

                <div class="alert alert-info">

                    Tidak ada Smelter lain yang tersedia
                    pada divisi Anda.

                </div>

            <?php else: ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="request_smelter"
                    >


                    <!-- DIVISION -->

                    <div class="mb-3">

                        <label class="form-label">

                            Divisi

                        </label>

                        <input
                            type="text"
                            class="form-control"
                            value="<?= htmlspecialchars($divisionName) ?>"
                            disabled
                        >

                        <small class="text-muted">

                            Divisi tidak dapat diganti.

                        </small>

                    </div>


                    <!-- SMELTER -->

                    <div class="mb-3">

                        <label class="form-label">

                            Smelter

                        </label>

                        <select
                            name="smelter_id"
                            class="form-select"
                            required
                        >

                            <option value="">

                                -- Pilih Smelter --

                            </option>

                            <?php foreach ($availableSmelters as $smelter): ?>

                                <option
                                    value="<?= (int) $smelter['id'] ?>"
                                >

                                    <?= htmlspecialchars(
                                        $smelter['name']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- REASON -->

                    <div class="mb-3">

                        <label class="form-label">

                            Alasan

                        </label>

                        <textarea
                            name="reason"
                            class="form-control"
                            rows="4"
                            maxlength="1000"
                            required
                        ></textarea>

                    </div>


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        Kirim Request

                    </button>

                </form>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         REQUEST HISTORY
    ====================================================== -->

    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                Riwayat Request Smelter
            </strong>

        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered align-middle">

                    <thead class="table-light">

                        <tr>

                            <th>
                                Smelter
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Alasan
                            </th>

                            <th>
                                Tanggal
                            </th>

                            <th>
                                Keterangan
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$requests): ?>

                        <tr>

                            <td
                                colspan="5"
                                class="text-center text-muted"
                            >

                                Belum ada request.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($requests as $request): ?>

                            <tr>

                                <td>

                                    <?= htmlspecialchars(
                                        $request['smelter_name']
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        $request['status']
                                        === 'pending'
                                    ): ?>

                                        <span class="badge bg-warning text-dark">
                                            Pending
                                        </span>

                                    <?php elseif (
                                        $request['status']
                                        === 'approved'
                                    ): ?>

                                        <span class="badge bg-success">
                                            Approved
                                        </span>

                                    <?php elseif (
                                        $request['status']
                                        === 'rejected'
                                    ): ?>

                                        <span class="badge bg-danger">
                                            Rejected
                                        </span>

                                    <?php elseif (
                                        $request['status']
                                        === 'cancelled'
                                    ): ?>

                                        <span class="badge bg-secondary">
                                            Cancelled
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-secondary">

                                            <?= htmlspecialchars(
                                                $request['status']
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $request['requested_reason'] ?? '-'
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $request['created_at']
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        $request['status']
                                        === 'rejected'
                                    ): ?>

                                        <span class="text-danger">

                                            <?= htmlspecialchars(
                                                $request['rejected_reason'] ?? '-'
                                            ) ?>

                                        </span>

                                    <?php elseif (
                                        $request['status']
                                        === 'approved'
                                    ): ?>

                                        <span class="text-success">

                                            Disetujui

                                            <?php if (
                                                !empty($request['approved_at'])
                                            ): ?>

                                                pada
                                                <?= htmlspecialchars(
                                                    $request['approved_at']
                                                ) ?>

                                            <?php endif; ?>

                                        </span>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            -
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>