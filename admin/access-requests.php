<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/admin.php';

$adminId = (int) $_SESSION['user_id'];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| PROCESS REQUEST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (!$requestId) {
        $error = 'Request tidak valid.';
    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | APPROVE ADDITIONAL SMELTER
            |--------------------------------------------------------------------------
            */

            if ($action === 'approve_smelter') {

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | Ambil request + user + smelter
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

                        u.nik,
                        u.name AS user_name,
                        u.status AS user_status,
                        u.division_id AS user_division_id,

                        r.name AS role_name,

                        s.name AS smelter_name,
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
                ");

                $stmt->execute([
                    $requestId
                ]);

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

                if ($request['request_type'] !== 'additional_smelter') {
                    throw new Exception(
                        'Request bukan permintaan Smelter tambahan.'
                    );
                }

                if ($request['status'] !== 'pending') {
                    throw new Exception(
                        'Request sudah diproses.'
                    );
                }

                if ($request['role_name'] !== 'spv') {
                    throw new Exception(
                        'Hanya SPV yang dapat memiliki akses Smelter.'
                    );
                }

                if ($request['user_status'] !== 'active') {
                    throw new Exception(
                        'Akun SPV belum aktif.'
                    );
                }

                if ($request['smelter_status'] !== 'active') {
                    throw new Exception(
                        'Smelter tidak aktif.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Pastikan divisi SPV sama dengan divisi Smelter
                |--------------------------------------------------------------------------
                */

                if (
                    (int) $request['user_division_id']
                    !==
                    (int) $request['smelter_division_id']
                ) {

                    throw new Exception(
                        'Smelter berada di divisi yang berbeda dengan SPV.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Cek apakah SPV sudah memiliki akses
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
                ");

                $stmt->execute([
                    $request['user_id'],
                    $request['smelter_id']
                ]);

                if ($stmt->fetchColumn()) {

                    throw new Exception(
                        'SPV sudah memiliki akses ke Smelter tersebut.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Berikan akses Smelter
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
                        created_at
                    )
                    VALUES (
                        ?,
                        ?,
                        NULL,
                        'active',
                        ?,
                        NOW(),
                        NOW()
                    )
                ");

                $stmt->execute([
                    $request['user_id'],
                    $request['smelter_id'],
                    $adminId
                ]);

                /*
                |--------------------------------------------------------------------------
                | Update request
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
                    $adminId,
                    $requestId
                ]);

                /*
                |--------------------------------------------------------------------------
                | Approval history
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
                    $request['user_id'],
                    $adminId,
                    'Admin menyetujui request Smelter tambahan: '
                    . $request['smelter_name']
                ]);

                $pdo->commit();

                $success =
                    'Request Smelter tambahan berhasil disetujui.';

            }


            /*
            |--------------------------------------------------------------------------
            | REJECT ADDITIONAL SMELTER
            |--------------------------------------------------------------------------
            */

            elseif ($action === 'reject_smelter') {

                if ($reason === '') {
                    throw new Exception(
                        'Alasan penolakan wajib diisi.'
                    );
                }

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | Ambil request
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        ar.id,
                        ar.user_id,
                        ar.smelter_id,
                        ar.request_type,
                        ar.status,

                        u.name AS user_name,
                        u.status AS user_status,

                        r.name AS role_name,

                        s.name AS smelter_name,
                        s.division_id AS smelter_division_id

                    FROM access_requests ar

                    INNER JOIN users u
                        ON u.id = ar.user_id

                    INNER JOIN roles r
                        ON r.id = u.role_id

                    INNER JOIN smelters s
                        ON s.id = ar.smelter_id

                    WHERE ar.id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $requestId
                ]);

                $request = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    throw new Exception(
                        'Request tidak ditemukan.'
                    );
                }

                if ($request['request_type'] !== 'additional_smelter') {
                    throw new Exception(
                        'Request bukan permintaan Smelter tambahan.'
                    );
                }

                if ($request['status'] !== 'pending') {
                    throw new Exception(
                        'Request sudah diproses.'
                    );
                }

                if ($request['role_name'] !== 'spv') {
                    throw new Exception(
                        'Request ini bukan milik SPV.'
                    );
                }

                if ($request['user_status'] !== 'active') {
                    throw new Exception(
                        'Akun SPV belum aktif.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Update request
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
                    $adminId,
                    $reason,
                    $requestId
                ]);

                /*
                |--------------------------------------------------------------------------
                | Approval history
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
                        'rejected',
                        ?,
                        NOW()
                    )
                ");

                $stmt->execute([
                    $request['user_id'],
                    $adminId,
                    'Admin menolak request Smelter tambahan: '
                    . $request['smelter_name']
                    . '. Alasan: '
                    . $reason
                ]);

                $pdo->commit();

                $success =
                    'Request Smelter tambahan ditolak.';

            }


            /*
            |--------------------------------------------------------------------------
            | APPROVE ADDITIONAL TEAM
            |--------------------------------------------------------------------------
            |
            | Admin hanya boleh approve jika TIDAK ADA SPV aktif
            | yang mengontrol Smelter tersebut.
            |--------------------------------------------------------------------------
            */

            elseif ($action === 'approve_team') {

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | Ambil request
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

                        u.nik,
                        u.name AS user_name,
                        u.status AS user_status,
                        u.division_id AS user_division_id,

                        r.name AS role_name,

                        s.name AS smelter_name,
                        s.division_id AS smelter_division_id,
                        s.status AS smelter_status,

                        t.name AS team_name,
                        t.status AS team_status,
                        t.smelter_id AS team_smelter_id

                    FROM access_requests ar

                    INNER JOIN users u
                        ON u.id = ar.user_id

                    INNER JOIN roles r
                        ON r.id = u.role_id

                    INNER JOIN smelters s
                        ON s.id = ar.smelter_id

                    INNER JOIN teams t
                        ON t.id = ar.team_id

                    WHERE ar.id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $requestId
                ]);

                $request = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    throw new Exception(
                        'Request tidak ditemukan.'
                    );
                }

                if ($request['request_type'] !== 'additional_team') {
                    throw new Exception(
                        'Request bukan permintaan Team tambahan.'
                    );
                }

                if ($request['status'] !== 'pending') {
                    throw new Exception(
                        'Request sudah diproses.'
                    );
                }

                if ($request['role_name'] !== 'foreman') {
                    throw new Exception(
                        'Request Team hanya dapat digunakan Foreman.'
                    );
                }

                if ($request['user_status'] !== 'active') {
                    throw new Exception(
                        'Akun Foreman belum aktif.'
                    );
                }

                if ($request['smelter_status'] !== 'active') {
                    throw new Exception(
                        'Smelter tidak aktif.'
                    );
                }

                if ($request['team_status'] !== 'active') {
                    throw new Exception(
                        'Team tidak aktif.'
                    );
                }

                if (
                    (int) $request['team_smelter_id']
                    !==
                    (int) $request['smelter_id']
                ) {
                    throw new Exception(
                        'Team tidak sesuai dengan Smelter.'
                    );
                }

                if (
                    (int) $request['user_division_id']
                    !==
                    (int) $request['smelter_division_id']
                ) {
                    throw new Exception(
                        'Foreman dan Smelter berada di divisi berbeda.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Cek apakah ada SPV aktif yang mengontrol Smelter
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT ua.id
                    FROM user_access ua

                    INNER JOIN users u
                        ON u.id = ua.user_id

                    INNER JOIN roles r
                        ON r.id = u.role_id

                    WHERE ua.smelter_id = ?
                      AND ua.team_id IS NULL
                      AND ua.status = 'active'
                      AND u.status = 'active'
                      AND r.name = 'spv'

                    LIMIT 1
                ");

                $stmt->execute([
                    $request['smelter_id']
                ]);

                if ($stmt->fetchColumn()) {

                    throw new Exception(
                        'Smelter ini memiliki SPV aktif. '
                        . 'Request harus diproses oleh SPV.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Cek akses Team sudah ada
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT id
                    FROM user_access
                    WHERE user_id = ?
                      AND smelter_id = ?
                      AND team_id = ?
                      AND status = 'active'
                    LIMIT 1
                ");

                $stmt->execute([
                    $request['user_id'],
                    $request['smelter_id'],
                    $request['team_id']
                ]);

                if ($stmt->fetchColumn()) {

                    throw new Exception(
                        'Foreman sudah memiliki akses ke Team tersebut.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Berikan akses Team
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
                        created_at
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        'active',
                        ?,
                        NOW(),
                        NOW()
                    )
                ");

                $stmt->execute([
                    $request['user_id'],
                    $request['smelter_id'],
                    $request['team_id'],
                    $adminId
                ]);

                /*
                |--------------------------------------------------------------------------
                | Update request
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
                    $adminId,
                    $requestId
                ]);

                $pdo->commit();

                $success =
                    'Request Team berhasil disetujui.';

            }


            /*
            |--------------------------------------------------------------------------
            | REJECT ADDITIONAL TEAM
            |--------------------------------------------------------------------------
            */

            elseif ($action === 'reject_team') {

                if ($reason === '') {
                    throw new Exception(
                        'Alasan penolakan wajib diisi.'
                    );
                }

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | Ambil request
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

                        u.name AS user_name,
                        u.status AS user_status,

                        r.name AS role_name,

                        s.name AS smelter_name,

                        t.name AS team_name

                    FROM access_requests ar

                    INNER JOIN users u
                        ON u.id = ar.user_id

                    INNER JOIN roles r
                        ON r.id = u.role_id

                    INNER JOIN smelters s
                        ON s.id = ar.smelter_id

                    INNER JOIN teams t
                        ON t.id = ar.team_id

                    WHERE ar.id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $requestId
                ]);

                $request = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    throw new Exception(
                        'Request tidak ditemukan.'
                    );
                }

                if ($request['request_type'] !== 'additional_team') {
                    throw new Exception(
                        'Request bukan permintaan Team tambahan.'
                    );
                }

                if ($request['status'] !== 'pending') {
                    throw new Exception(
                        'Request sudah diproses.'
                    );
                }

                if ($request['role_name'] !== 'foreman') {
                    throw new Exception(
                        'Request ini bukan milik Foreman.'
                    );
                }

                if ($request['user_status'] !== 'active') {
                    throw new Exception(
                        'Akun Foreman belum aktif.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Pastikan tidak ada SPV aktif
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT ua.id
                    FROM user_access ua

                    INNER JOIN users u
                        ON u.id = ua.user_id

                    INNER JOIN roles r
                        ON r.id = u.role_id

                    WHERE ua.smelter_id = ?
                      AND ua.team_id IS NULL
                      AND ua.status = 'active'
                      AND u.status = 'active'
                      AND r.name = 'spv'

                    LIMIT 1
                ");

                $stmt->execute([
                    $request['smelter_id']
                ]);

                if ($stmt->fetchColumn()) {

                    throw new Exception(
                        'Smelter memiliki SPV aktif. '
                        . 'Request harus diproses oleh SPV.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Reject
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
                    $adminId,
                    $reason,
                    $requestId
                ]);

                $pdo->commit();

                $success =
                    'Request Team ditolak.';

            }

            else {

                throw new Exception(
                    'Action tidak dikenali.'
                );
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| REQUEST SMELTER TAMBAHAN
|--------------------------------------------------------------------------
|
| Hanya request dari SPV yang sudah ACTIVE.
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ar.id,
        ar.requested_reason,
        ar.created_at,

        u.nik,
        u.name AS user_name,
        u.email,

        d.name AS division_name,

        s.id AS smelter_id,
        s.name AS smelter_name

    FROM access_requests ar

    INNER JOIN users u
        ON u.id = ar.user_id

    INNER JOIN roles r
        ON r.id = u.role_id

    INNER JOIN divisions d
        ON d.id = u.division_id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    WHERE ar.request_type = 'additional_smelter'
      AND ar.status = 'pending'
      AND u.status = 'active'
      AND r.name = 'spv'

    ORDER BY ar.created_at ASC
");

$stmt->execute();

$smelterRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| REQUEST TEAM TAMBAHAN
|--------------------------------------------------------------------------
|
| Hanya request Foreman yang belum memiliki SPV aktif
| pada Smelter tujuan.
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ar.id,
        ar.requested_reason,
        ar.created_at,

        u.nik,
        u.name AS user_name,

        d.name AS division_name,

        s.id AS smelter_id,
        s.name AS smelter_name,

        t.id AS team_id,
        t.name AS team_name

    FROM access_requests ar

    INNER JOIN users u
        ON u.id = ar.user_id

    INNER JOIN roles r
        ON r.id = u.role_id

    INNER JOIN divisions d
        ON d.id = u.division_id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    INNER JOIN teams t
        ON t.id = ar.team_id

    WHERE ar.request_type = 'additional_team'
      AND ar.status = 'pending'
      AND u.status = 'active'
      AND r.name = 'foreman'

      AND NOT EXISTS (
          SELECT 1
          FROM user_access ua

          INNER JOIN users spv
              ON spv.id = ua.user_id

          INNER JOIN roles spv_role
              ON spv_role.id = spv.role_id

          WHERE ua.smelter_id = ar.smelter_id
            AND ua.team_id IS NULL
            AND ua.status = 'active'
            AND spv.status = 'active'
            AND spv_role.name = 'spv'
      )

    ORDER BY ar.created_at ASC
");

$stmt->execute();

$teamRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Access Requests - Admin
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body class="bg-light">


<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <span class="navbar-brand">
            Smelter Management - Admin
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


<div class="container py-4">


    <?php if ($success): ?>

        <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <!--
    |--------------------------------------------------------------------------
    | REQUEST SMELTER
    |--------------------------------------------------------------------------
    -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Approval Smelter Tambahan SPV
            </strong>

        </div>

        <div class="card-body">


            <?php if (!$smelterRequests): ?>

                <div class="alert alert-info mb-0">
                    Tidak ada request Smelter tambahan.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover">

                        <thead class="table-light">

                            <tr>

                                <th>NIK</th>

                                <th>SPV</th>

                                <th>Divisi</th>

                                <th>Smelter</th>

                                <th>Alasan</th>

                                <th>Tanggal</th>

                                <th>Aksi</th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($smelterRequests as $request): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['nik']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['user_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['division_name']
                                    ) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= htmlspecialchars(
                                            $request['smelter_name']
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['requested_reason']
                                        ?? '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['created_at']
                                    ) ?>
                                </td>

                                <td>

                                    <div class="d-flex gap-1">

                                        <form
                                            method="POST"
                                            onsubmit="return confirm(
                                                'Setujui akses Smelter ini?'
                                            );"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve_smelter"
                                            >

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int) $request['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-success btn-sm"
                                            >
                                                Approve
                                            </button>

                                        </form>


                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#rejectSmelterModal"
                                            data-request-id="<?= (int) $request['id'] ?>"
                                        >
                                            Reject
                                        </button>

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


    <!--
    |--------------------------------------------------------------------------
    | REQUEST TEAM
    |--------------------------------------------------------------------------
    -->

    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                Approval Team Tambahan Foreman
            </strong>

        </div>

        <div class="card-body">


            <?php if (!$teamRequests): ?>

                <div class="alert alert-info mb-0">
                    Tidak ada request Team yang perlu diproses Admin.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover">

                        <thead class="table-light">

                            <tr>

                                <th>NIK</th>

                                <th>Foreman</th>

                                <th>Divisi</th>

                                <th>Smelter</th>

                                <th>Team</th>

                                <th>Alasan</th>

                                <th>Tanggal</th>

                                <th>Aksi</th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($teamRequests as $request): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['nik']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['user_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['division_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['smelter_name']
                                    ) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= htmlspecialchars(
                                            $request['team_name']
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['requested_reason']
                                        ?? '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['created_at']
                                    ) ?>
                                </td>

                                <td>

                                    <div class="d-flex gap-1">

                                        <form
                                            method="POST"
                                            onsubmit="return confirm(
                                                'Setujui akses Team ini?'
                                            );"
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
                                                class="btn btn-success btn-sm"
                                            >
                                                Approve
                                            </button>

                                        </form>


                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#rejectTeamModal"
                                            data-request-id="<?= (int) $request['id'] ?>"
                                        >
                                            Reject
                                        </button>

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

</div>


<!--
|--------------------------------------------------------------------------
| MODAL REJECT SMELTER
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="rejectSmelterModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">
                        Tolak Request Smelter
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
                        name="action"
                        value="reject_smelter"
                    >

                    <input
                        type="hidden"
                        name="request_id"
                        id="rejectSmelterRequestId"
                    >

                    <div class="mb-3">

                        <label class="form-label">
                            Alasan Penolakan
                        </label>

                        <textarea
                            name="reason"
                            class="form-control"
                            rows="4"
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


<!--
|--------------------------------------------------------------------------
| MODAL REJECT TEAM
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="rejectTeamModal"
    tabindex="-1"
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
                        name="action"
                        value="reject_team"
                    >

                    <input
                        type="hidden"
                        name="request_id"
                        id="rejectTeamRequestId"
                    >

                    <div class="mb-3">

                        <label class="form-label">
                            Alasan Penolakan
                        </label>

                        <textarea
                            name="reason"
                            class="form-control"
                            rows="4"
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


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

const rejectSmelterModal =
    document.getElementById('rejectSmelterModal');

if (rejectSmelterModal) {

    rejectSmelterModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button = event.relatedTarget;

            const requestId =
                button.getAttribute(
                    'data-request-id'
                );

            document.getElementById(
                'rejectSmelterRequestId'
            ).value = requestId;

        }
    );
}


const rejectTeamModal =
    document.getElementById('rejectTeamModal');

if (rejectTeamModal) {

    rejectTeamModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button = event.relatedTarget;

            const requestId =
                button.getAttribute(
                    'data-request-id'
                );

            document.getElementById(
                'rejectTeamRequestId'
            ).value = requestId;

        }
    );
}

</script>


</body>

</html>