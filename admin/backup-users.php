<?php

require_once __DIR__ . '/../middleware/admin.php';
require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| SESSION & CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| PROSES APPROVAL / REJECTION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | VALIDASI CSRF
    |--------------------------------------------------------------------------
    */

    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token'])
        || empty($token)
        || !hash_equals($_SESSION['csrf_token'], $token)
    ) {

        $error = 'Request tidak valid. Silakan muat ulang halaman.';

    } else {

        $userId = filter_input(
            INPUT_POST,
            'user_id',
            FILTER_VALIDATE_INT
        );

        if (!$userId) {

            $error = 'User tidak valid.';

        } elseif (!in_array($action, ['approve', 'reject'], true)) {

            $error = 'Action tidak valid.';

        } else {

            try {

                $pdo->beginTransaction();

                /*
                |--------------------------------------------------------------------------
                | Ambil data user
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
                        r.id AS role_id,
                        r.name AS role_name
                    FROM users u
                    INNER JOIN roles r
                        ON r.id = u.role_id
                    WHERE u.id = ?
                    LIMIT 1
                ");

                $stmt->execute([$userId]);

                $user = $stmt->fetch();

                if (!$user) {
                    throw new Exception(
                        'User tidak ditemukan.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Hanya pending yang dapat diproses
                |--------------------------------------------------------------------------
                */

                if ($user['status'] !== 'pending') {
                    throw new Exception(
                        'User ini sudah tidak berstatus pending.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Ambil request awal user
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        ar.id,
                        ar.smelter_id,
                        ar.team_id,
                        ar.request_type,
                        ar.status,
                        s.name AS smelter_name
                    FROM access_requests ar
                    INNER JOIN smelters s
                        ON s.id = ar.smelter_id
                    WHERE ar.user_id = ?
                      AND ar.status = 'pending'
                    ORDER BY ar.id ASC
                    LIMIT 1
                ");

                $stmt->execute([$userId]);

                $accessRequest = $stmt->fetch();

                if (!$accessRequest) {
                    throw new Exception(
                        'Request akses awal user tidak ditemukan.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | REJECT
                |--------------------------------------------------------------------------
                */

                if ($action === 'reject') {

                    $reason = trim(
                        $_POST['rejected_reason'] ?? ''
                    );

                    if ($reason === '') {
                        throw new Exception(
                            'Alasan penolakan wajib diisi.'
                        );
                    }

                    /*
                    | Update user
                    */

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET
                            status = 'rejected',
                            rejected_reason = ?,
                            approved_by = ?,
                            approved_at = NULL
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $reason,
                        $_SESSION['user_id'],
                        $userId
                    ]);

                    /*
                    | Update access request
                    */

                    $stmt = $pdo->prepare("
                        UPDATE access_requests
                        SET
                            status = 'rejected',
                            rejected_reason = ?,
                            approved_by = ?,
                            approved_at = NULL
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $reason,
                        $_SESSION['user_id'],
                        $accessRequest['id']
                    ]);

                    /*
                    | Simpan approval history
                    */

                    $stmt = $pdo->prepare("
                        INSERT INTO user_approvals (
                            user_id,
                            approved_by,
                            action,
                            notes
                        )
                        VALUES (?, ?, 'rejected', ?)
                    ");

                    $stmt->execute([
                        $userId,
                        $_SESSION['user_id'],
                        $reason
                    ]);

                    $pdo->commit();

                    $success =
                        'User berhasil ditolak.';
                }

                /*
                |--------------------------------------------------------------------------
                | APPROVE
                |--------------------------------------------------------------------------
                */

                if ($action === 'approve') {

                    /*
                    |--------------------------------------------------------------------------
                    | SPV
                    |--------------------------------------------------------------------------
                    |
                    | SPV dapat langsung disetujui Admin.
                    |
                    | Setelah approve:
                    |
                    | users.status = active
                    |
                    | user_access:
                    |   smelter_id = smelter awal
                    |   team_id    = NULL
                    |--------------------------------------------------------------------------
                    */

                    if ($user['role_name'] === 'spv') {

                        if (
                            $accessRequest['request_type']
                            !== 'additional_smelter'
                        ) {

                            throw new Exception(
                                'Request akses SPV tidak valid.'
                            );
                        }

                        /*
                        | Pastikan belum punya akses ke smelter tersebut
                        */

                        $stmt = $pdo->prepare("
                            SELECT id
                            FROM user_access
                            WHERE user_id = ?
                              AND smelter_id = ?
                              AND team_id IS NULL
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $userId,
                            $accessRequest['smelter_id']
                        ]);

                        if ($stmt->fetch()) {

                            throw new Exception(
                                'Akses Smelter sudah tersedia.'
                            );
                        }

                        /*
                        | Aktifkan User
                        */

                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET
                                status = 'active',
                                approved_by = ?,
                                approved_at = NOW(),
                                rejected_reason = NULL
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $_SESSION['user_id'],
                            $userId
                        ]);

                        /*
                        | Buat akses Smelter
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO user_access (
                                user_id,
                                smelter_id,
                                team_id,
                                status,
                                granted_by,
                                granted_at
                            )
                            VALUES (?, ?, NULL, 'active', ?, NOW())
                        ");

                        $stmt->execute([
                            $userId,
                            $accessRequest['smelter_id'],
                            $_SESSION['user_id']
                        ]);

                        /*
                        | Update Request
                        */

                        $stmt = $pdo->prepare("
                            UPDATE access_requests
                            SET
                                status = 'approved',
                                approved_by = ?,
                                approved_at = NOW()
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $_SESSION['user_id'],
                            $accessRequest['id']
                        ]);

                        /*
                        | Approval History
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO user_approvals (
                                user_id,
                                approved_by,
                                action,
                                notes
                            )
                            VALUES (?, ?, 'approved', ?)
                        ");

                        $stmt->execute([
                            $userId,
                            $_SESSION['user_id'],
                            'SPV disetujui oleh Admin.'
                        ]);

                        $pdo->commit();

                        $success =
                            'SPV berhasil disetujui dan akses Smelter telah diberikan.';
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | FOREMAN
                    |--------------------------------------------------------------------------
                    |
                    | Admin hanya boleh approve Foreman apabila tidak ada
                    | SPV aktif yang mengontrol Smelter tersebut.
                    |--------------------------------------------------------------------------
                    */

                    elseif ($user['role_name'] === 'foreman') {

                        if (
                            $accessRequest['request_type']
                            !== 'additional_team'
                        ) {

                            throw new Exception(
                                'Request akses Foreman tidak valid.'
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Cari SPV aktif pada Smelter tersebut
                        |--------------------------------------------------------------------------
                        */

                        $stmt = $pdo->prepare("
                            SELECT
                                u.id,
                                u.name
                            FROM users u
                            INNER JOIN roles r
                                ON r.id = u.role_id
                            INNER JOIN user_access ua
                                ON ua.user_id = u.id
                            WHERE r.name = 'spv'
                              AND u.status = 'active'
                              AND u.division_id = ?
                              AND ua.smelter_id = ?
                              AND ua.team_id IS NULL
                              AND ua.status = 'active'
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $user['division_id'],
                            $accessRequest['smelter_id']
                        ]);

                        $spv = $stmt->fetch();

                        if ($spv) {

                            /*
                            | Jangan izinkan Admin approve.
                            | Request akan ditangani oleh SPV.
                            */

                            throw new Exception(
                                'Smelter ini memiliki SPV aktif. Foreman harus disetujui oleh SPV.'
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Tidak ada SPV
                        |--------------------------------------------------------------------------
                        |
                        | Admin menjadi approver.
                        |--------------------------------------------------------------------------
                        */

                        /*
                        | Aktifkan user
                        */

                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET
                                status = 'active',
                                approved_by = ?,
                                approved_at = NOW(),
                                rejected_reason = NULL
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $_SESSION['user_id'],
                            $userId
                        ]);

                        /*
                        | Buat akses Team
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO user_access (
                                user_id,
                                smelter_id,
                                team_id,
                                status,
                                granted_by,
                                granted_at
                            )
                            VALUES (?, ?, ?, 'active', ?, NOW())
                        ");

                        $stmt->execute([
                            $userId,
                            $accessRequest['smelter_id'],
                            $accessRequest['team_id'],
                            $_SESSION['user_id']
                        ]);

                        /*
                        | Update request
                        */

                        $stmt = $pdo->prepare("
                            UPDATE access_requests
                            SET
                                status = 'approved',
                                approved_by = ?,
                                approved_at = NOW()
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $_SESSION['user_id'],
                            $accessRequest['id']
                        ]);

                        /*
                        | Approval history
                        */

                        $stmt = $pdo->prepare("
                            INSERT INTO user_approvals (
                                user_id,
                                approved_by,
                                action,
                                notes
                            )
                            VALUES (?, ?, 'approved', ?)
                        ");

                        $stmt->execute([
                            $userId,
                            $_SESSION['user_id'],
                            'Foreman disetujui Admin karena belum terdapat SPV aktif pada Smelter.'
                        ]);

                        $pdo->commit();

                        $success =
                            'Foreman berhasil disetujui dan akses Team telah diberikan.';
                    }

                    else {

                        throw new Exception(
                            'Role user tidak dapat diproses.'
                        );
                    }
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
| Ambil Pending Users
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        u.id,
        u.nik,
        u.name,
        u.email,
        u.status,

        r.name AS role_name,

        d.name AS division_name,

        s.name AS smelter_name,

        t.name AS team_name,

        ar.id AS request_id,

        ar.request_type,

        ar.created_at AS request_created_at,

        (
            SELECT COUNT(*)
            FROM users spv_user
            INNER JOIN roles spv_role
                ON spv_role.id = spv_user.role_id
            INNER JOIN user_access spv_access
                ON spv_access.user_id = spv_user.id
            WHERE spv_role.name = 'spv'
              AND spv_user.status = 'active'
              AND spv_user.division_id = u.division_id
              AND spv_access.smelter_id = ar.smelter_id
              AND spv_access.team_id IS NULL
              AND spv_access.status = 'active'
        ) AS active_spv_count

    FROM users u

    INNER JOIN roles r
        ON r.id = u.role_id

    INNER JOIN divisions d
        ON d.id = u.division_id

    INNER JOIN access_requests ar
        ON ar.user_id = u.id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    LEFT JOIN teams t
        ON t.id = ar.team_id

    WHERE u.status = 'pending'
      AND ar.status = 'pending'

    ORDER BY ar.created_at ASC
");

$pendingUsers = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Approval User</title>

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

            <?= htmlspecialchars($_SESSION['name']) ?>

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


<div class="container py-4">


    <div class="d-flex justify-content-between align-items-center mb-4">

        <h2>
            Approval User
        </h2>

        <a
            href="dashboard"
            class="btn btn-secondary"
        >
            Kembali
        </a>

    </div>


    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($success): ?>

        <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
        </div>

    <?php endif; ?>


    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                User Menunggu Approval
            </strong>

        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered table-hover align-middle">

                    <thead>

                        <tr>

                            <th>
                                NIK
                            </th>

                            <th>
                                Nama
                            </th>

                            <th>
                                Role
                            </th>

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
                                Status SPV
                            </th>

                            <th>
                                Aksi
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$pendingUsers): ?>

                        <tr>

                            <td
                                colspan="8"
                                class="text-center"
                            >
                                Tidak ada user yang menunggu approval.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($pendingUsers as $user): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $user['nik']
                                    ) ?>
                                </td>

                                <td>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $user['name']
                                        ) ?>
                                    </strong>

                                    <br>

                                    <small class="text-muted">
                                        <?= htmlspecialchars(
                                            $user['email']
                                        ) ?>
                                    </small>

                                </td>

                                <td>

                                    <?php if (
                                        $user['role_name'] === 'spv'
                                    ): ?>

                                        <span class="badge bg-primary">
                                            SPV
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-info">
                                            Foreman
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $user['division_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $user['smelter_name']
                                    ) ?>
                                </td>

                                <td>

                                    <?php if (
                                        $user['role_name'] === 'foreman'
                                    ): ?>

                                        <?= htmlspecialchars(
                                            $user['team_name'] ?? '-'
                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            Semua Team
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php if (
                                        $user['role_name'] === 'spv'
                                    ): ?>

                                        <span class="badge bg-secondary">
                                            Tidak berlaku
                                        </span>

                                    <?php elseif (
                                        $user['active_spv_count'] > 0
                                    ): ?>

                                        <span class="badge bg-success">
                                            SPV tersedia
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-warning text-dark">
                                            Tidak ada SPV
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php
                                    $canAdminApprove =
                                        $user['role_name'] === 'spv'
                                        ||
                                        (
                                            $user['role_name'] === 'foreman'
                                            &&
                                            $user['active_spv_count'] == 0
                                        );
                                    ?>

                                    <?php if ($canAdminApprove): ?>

                                        <form
                                            method="POST"
                                            class="d-inline"
                                        >

                                            <!-- CSRF -->
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars(
                                                    $csrfToken,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int) $user['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-success"
                                                onclick="return confirm('Approve user ini?')"
                                            >
                                                Approve
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="badge bg-warning text-dark">
                                            Menunggu SPV
                                        </span>

                                    <?php endif; ?>


                                    <button
                                        type="button"
                                        class="btn btn-sm btn-danger"
                                        data-bs-toggle="modal"
                                        data-bs-target="#rejectModal"
                                        data-user-id="<?= (int) $user['id'] ?>"
                                        data-user-name="<?= htmlspecialchars(
                                            $user['name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >
                                        Reject
                                    </button>

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


<!-- =====================================================
     MODAL REJECT
====================================================== -->

<div
    class="modal fade"
    id="rejectModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <!-- CSRF -->
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $csrfToken,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <div class="modal-header">

                    <h5 class="modal-title">
                        Tolak User
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
                        value="reject"
                    >

                    <input
                        type="hidden"
                        name="user_id"
                        id="reject_user_id"
                    >

                    <p>
                        Anda akan menolak user:
                    </p>

                    <strong id="reject_user_name"></strong>

                    <div class="mt-3">

                        <label class="form-label">
                            Alasan Penolakan
                        </label>

                        <textarea
                            name="rejected_reason"
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
                        Tolak User
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>