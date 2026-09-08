<?php

require_once __DIR__ . '/../middleware/admin.php';
require_once __DIR__ . '/../config/database.php';

$error = '';

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
        u.approved_by,
        u.approved_at,
        u.rejected_reason,
        u.deleted_at,
        u.created_at,
        u.updated_at,

        r.name AS role_name,

        d.name AS division_name,

        approver.name AS approved_by_name

    FROM users u

    INNER JOIN roles r
        ON r.id = u.role_id

    LEFT JOIN divisions d
        ON d.id = u.division_id

    LEFT JOIN users approver
        ON approver.id = u.approved_by

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
| AMBIL USER ACCESS
|--------------------------------------------------------------------------
|
| SPV:
|   team_id NULL = seluruh team Smelter
|
| Foreman:
|   team_id terisi = team tertentu
|
*/

$stmt = $pdo->prepare("
    SELECT

        ua.id AS access_id,

        ua.smelter_id,
        ua.team_id,

        ua.status AS access_status,

        ua.granted_at,
        ua.revoked_at,

        granted.name AS granted_by_name,
        revoked.name AS revoked_by_name,

        s.name AS smelter_name,

        t.name AS team_name,

        d.name AS division_name

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
| HITUNG ACCESS
|--------------------------------------------------------------------------
*/

$activeAccess = 0;
$revokedAccess = 0;

foreach ($userAccess as $access) {

    if ($access['access_status'] === 'active') {
        $activeAccess++;
    }

    if ($access['access_status'] === 'revoked') {
        $revokedAccess++;
    }
}


/*
|--------------------------------------------------------------------------
| RIWAYAT APPROVAL
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        ua.id,

        ua.action,

        ua.notes,

        ua.created_at,

        approver.name AS approver_name

    FROM user_approvals ua

    LEFT JOIN users approver
        ON approver.id = ua.approved_by

    WHERE ua.user_id = ?

    ORDER BY ua.created_at DESC
");

$stmt->execute([
    $userId
]);

$approvalHistory = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| RIWAYAT DELETION
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        udl.id,

        udl.reason,

        udl.deleted_at,

        deleter.name AS deleted_by_name

    FROM user_deletion_logs udl

    LEFT JOIN users deleter
        ON deleter.id = udl.deleted_by

    WHERE udl.user_id = ?

    ORDER BY udl.deleted_at DESC
");

$stmt->execute([
    $userId
]);

$deletionHistory = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| ACCESS REQUEST HISTORY
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        ar.id,

        ar.request_type,

        ar.status,

        ar.requested_reason,

        ar.rejected_reason,

        ar.approved_at,

        ar.created_at,

        approver.name AS approver_name,

        s.name AS smelter_name,

        t.name AS team_name

    FROM access_requests ar

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    LEFT JOIN teams t
        ON t.id = ar.team_id

    LEFT JOIN users approver
        ON approver.id = ar.approved_by

    WHERE ar.user_id = ?

    ORDER BY ar.created_at DESC
");

$stmt->execute([
    $userId
]);

$accessRequests = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| HELPER STATUS
|--------------------------------------------------------------------------
*/

function userStatusBadge(string $status): string
{
    return match ($status) {

        'active' => '<span class="badge bg-success">Active</span>',

        'pending' => '<span class="badge bg-warning text-dark">Pending</span>',

        'suspended' => '<span class="badge bg-secondary">Suspended</span>',

        'rejected' => '<span class="badge bg-danger">Rejected</span>',

        'deleted' => '<span class="badge bg-dark">Deleted</span>',

        default =>
            '<span class="badge bg-light text-dark">'
            . htmlspecialchars($status)
            . '</span>'
    };
}


function accessStatusBadge(string $status): string
{
    return match ($status) {

        'active' =>
            '<span class="badge bg-success">Active</span>',

        'revoked' =>
            '<span class="badge bg-secondary">Revoked</span>',

        default =>
            '<span class="badge bg-light text-dark">'
            . htmlspecialchars($status)
            . '</span>'
    };
}


function requestStatusBadge(string $status): string
{
    return match ($status) {

        'pending' =>
            '<span class="badge bg-warning text-dark">Pending</span>',

        'approved' =>
            '<span class="badge bg-success">Approved</span>',

        'rejected' =>
            '<span class="badge bg-danger">Rejected</span>',

        'cancelled' =>
            '<span class="badge bg-secondary">Cancelled</span>',

        default =>
            '<span class="badge bg-light text-dark">'
            . htmlspecialchars($status)
            . '</span>'
    };
}


function formatDate(?string $date): string
{
    if (!$date) {
        return '-';
    }

    return date(
        'd-m-Y H:i',
        strtotime($date)
    );
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
        Detail User - <?= htmlspecialchars($user['name']) ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>


<body class="bg-light">


<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <a
            href="dashboard.php"
            class="navbar-brand"
        >
            Smelter Management
        </a>


        <div class="text-white">

            <?= htmlspecialchars($_SESSION['name']) ?>

            &nbsp; | &nbsp;

            <a
                href="../auth/logout.php"
                class="text-white"
            >
                Logout
            </a>

        </div>

    </div>

</nav>


<div class="container-fluid py-4">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="mb-1">
                Detail User
            </h2>

            <p class="text-muted mb-0">
                Informasi user dan seluruh riwayat akses.
            </p>

        </div>


        <a
            href="users.php"
            class="btn btn-secondary"
        >
            ← Kembali
        </a>

    </div>


    <!-- =====================================================
         USER INFORMATION
    ====================================================== -->
    
    <div class="row g-4 mb-4">


        <!-- IDENTITAS -->

        <div class="col-lg-8">

            <div class="card shadow-sm h-100">

                <div class="card-header">

                    <strong>
                        <?php if (strtolower($user['role_name']) !== 'admin'): ?>

                            <a
                                href="user-access.php?id=<?= (int) $user['id'] ?>"
                                class="btn btn-primary"
                            >
                                Kelola Access
                            </a>

                        <?php endif; ?>
                    </strong>

                </div>


                <div class="card-body">

                    <div class="row mb-3">

                        <div class="col-md-4 text-muted">
                            NIK
                        </div>

                        <div class="col-md-8">

                            <strong>
                                <?= htmlspecialchars($user['nik']) ?>
                            </strong>

                        </div>

                    </div>


                    <div class="row mb-3">

                        <div class="col-md-4 text-muted">
                            Nama Lengkap
                        </div>

                        <div class="col-md-8">

                            <?= htmlspecialchars($user['name']) ?>

                        </div>

                    </div>


                    <div class="row mb-3">

                        <div class="col-md-4 text-muted">
                            Email
                        </div>

                        <div class="col-md-8">

                            <?= htmlspecialchars($user['email']) ?>

                        </div>

                    </div>


                    <div class="row mb-3">

                        <div class="col-md-4 text-muted">
                            Role
                        </div>

                        <div class="col-md-8">

                            <?php if ($user['role_name'] === 'admin'): ?>

                                <span class="badge bg-dark">
                                    Admin
                                </span>

                            <?php elseif ($user['role_name'] === 'spv'): ?>

                                <span class="badge bg-primary">
                                    SPV
                                </span>

                            <?php else: ?>

                                <span class="badge bg-info">
                                    Foreman
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="row mb-3">

                        <div class="col-md-4 text-muted">
                            Divisi
                        </div>

                        <div class="col-md-8">

                            <?= htmlspecialchars(
                                $user['division_name'] ?? '-'
                            ) ?>

                        </div>

                    </div>


                    <div class="row mb-3">

                        <div class="col-md-4 text-muted">
                            Status
                        </div>

                        <div class="col-md-8">

                            <?= userStatusBadge($user['status']) ?>

                        </div>

                    </div>


                    <div class="row mb-3">

                        <div class="col-md-4 text-muted">
                            Dibuat
                        </div>

                        <div class="col-md-8">

                            <?= formatDate($user['created_at']) ?>

                        </div>

                    </div>


                    <?php if ($user['approved_at']): ?>

                        <div class="row mb-3">

                            <div class="col-md-4 text-muted">
                                Approved
                            </div>

                            <div class="col-md-8">

                                <?= formatDate($user['approved_at']) ?>

                                <?php if ($user['approved_by_name']): ?>

                                    <br>

                                    <small class="text-muted">

                                        Oleh:
                                        <?= htmlspecialchars(
                                            $user['approved_by_name']
                                        ) ?>

                                    </small>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endif; ?>


                    <?php if ($user['rejected_reason']): ?>

                        <div class="row mb-3">

                            <div class="col-md-4 text-muted">
                                Alasan Reject
                            </div>

                            <div class="col-md-8">

                                <div class="alert alert-danger mb-0">

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $user['rejected_reason']
                                        )
                                    ) ?>

                                </div>

                            </div>

                        </div>

                    <?php endif; ?>


                    <?php if ($user['deleted_at']): ?>

                        <div class="row mb-3">

                            <div class="col-md-4 text-muted">
                                Deleted
                            </div>

                            <div class="col-md-8">

                                <?= formatDate($user['deleted_at']) ?>

                            </div>

                        </div>

                    <?php endif; ?>


                </div>

            </div>

        </div>


        <!-- ACCESS SUMMARY -->

        <div class="col-lg-4">

            <div class="card shadow-sm h-100">

                <div class="card-header">

                    <strong>
                        Ringkasan Akses
                    </strong>

                </div>


                <div class="card-body">

                    <div class="mb-4">

                        <small class="text-muted">
                            Total Record Akses
                        </small>

                        <h2>
                            <?= count($userAccess) ?>
                        </h2>

                    </div>


                    <div class="mb-4">

                        <small class="text-muted">
                            Akses Aktif
                        </small>

                        <h2 class="text-success">
                            <?= $activeAccess ?>
                        </h2>

                    </div>


                    <div>

                        <small class="text-muted">
                            Akses Dicabut
                        </small>

                        <h2 class="text-secondary">
                            <?= $revokedAccess ?>
                        </h2>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         USER ACCESS
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Akses Smelter / Team
            </strong>

        </div>


        <div class="card-body">

            <?php if (!$userAccess): ?>

                <div class="alert alert-secondary mb-0">

                    User belum memiliki record akses.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

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

                                <th>
                                    Oleh
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($userAccess as $access): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $access['division_name']
                                    ) ?>
                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $access['smelter_name']
                                    ) ?>

                                </td>


                                <td>

                                    <?php if ($user['role_name'] === 'spv'): ?>

                                        <span class="text-muted">
                                            Semua Team
                                        </span>

                                    <?php else: ?>

                                        <?= htmlspecialchars(
                                            $access['team_name'] ?? '-'
                                        ) ?>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= accessStatusBadge(
                                        $access['access_status']
                                    ) ?>

                                </td>


                                <td>

                                    <?= formatDate(
                                        $access['granted_at']
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $access['granted_by_name'] ?? '-'
                                    ) ?>

                                </td>


                                <td>

                                    <?= formatDate(
                                        $access['revoked_at']
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $access['revoked_by_name'] ?? '-'
                                    ) ?>

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
         ACCESS REQUEST HISTORY
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Riwayat Request Akses
            </strong>

        </div>


        <div class="card-body">

            <?php if (!$accessRequests): ?>

                <div class="alert alert-secondary mb-0">

                    Belum ada request akses.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

                        <thead class="table-dark">

                            <tr>

                                <th>
                                    Tanggal
                                </th>

                                <th>
                                    Jenis
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
                                    Alasan
                                </th>

                                <th>
                                    Approver
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($accessRequests as $request): ?>

                            <tr>

                                <td>

                                    <?= formatDate(
                                        $request['created_at']
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        $request['request_type']
                                        === 'additional_smelter'
                                    ): ?>

                                        <span class="badge bg-primary">
                                            Additional Smelter
                                        </span>

                                    <?php elseif (
                                        $request['request_type']
                                        === 'additional_team'
                                    ): ?>

                                        <span class="badge bg-info">
                                            Additional Team
                                        </span>

                                    <?php else: ?>

                                        <?= htmlspecialchars(
                                            $request['request_type']
                                        ) ?>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $request['smelter_name']
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $request['team_name'] ?? '-'
                                    ) ?>

                                </td>


                                <td>

                                    <?= requestStatusBadge(
                                        $request['status']
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        $request['status'] === 'rejected'
                                        &&
                                        $request['rejected_reason']
                                    ): ?>

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $request['rejected_reason']
                                            )
                                        ) ?>

                                    <?php elseif (
                                        $request['requested_reason']
                                    ): ?>

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $request['requested_reason']
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $request['approver_name'] ?? '-'
                                    ) ?>

                                    <?php if (
                                        $request['approved_at']
                                    ): ?>

                                        <br>

                                        <small class="text-muted">

                                            <?= formatDate(
                                                $request['approved_at']
                                            ) ?>

                                        </small>

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


    <!-- =====================================================
         APPROVAL HISTORY
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Riwayat Approval / Status
            </strong>

        </div>


        <div class="card-body">

            <?php if (!$approvalHistory): ?>

                <div class="alert alert-secondary mb-0">

                    Belum ada riwayat approval.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered align-middle">

                        <thead class="table-dark">

                            <tr>

                                <th>
                                    Tanggal
                                </th>

                                <th>
                                    Action
                                </th>

                                <th>
                                    Oleh
                                </th>

                                <th>
                                    Catatan
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($approvalHistory as $history): ?>

                            <tr>

                                <td>

                                    <?= formatDate(
                                        $history['created_at']
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        $history['action']
                                        === 'approved'
                                    ): ?>

                                        <span class="badge bg-success">
                                            Approved
                                        </span>

                                    <?php elseif (
                                        $history['action']
                                        === 'rejected'
                                    ): ?>

                                        <span class="badge bg-danger">
                                            Rejected
                                        </span>

                                    <?php elseif (
                                        $history['action']
                                        === 'suspended'
                                    ): ?>

                                        <span class="badge bg-secondary">
                                            Suspended
                                        </span>

                                    <?php elseif (
                                        $history['action']
                                        === 'activated'
                                    ): ?>

                                        <span class="badge bg-primary">
                                            Activated
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-light text-dark">
                                            <?= htmlspecialchars(
                                                $history['action']
                                            ) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $history['approver_name'] ?? '-'
                                    ) ?>

                                </td>


                                <td>

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $history['notes'] ?? '-'
                                        )
                                    ) ?>

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
         DELETION HISTORY
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Riwayat Penghapusan
            </strong>

        </div>


        <div class="card-body">

            <?php if (!$deletionHistory): ?>

                <div class="alert alert-secondary mb-0">

                    Belum ada riwayat penghapusan.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered align-middle">

                        <thead class="table-dark">

                            <tr>

                                <th>
                                    Tanggal
                                </th>

                                <th>
                                    Dihapus Oleh
                                </th>

                                <th>
                                    Alasan
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($deletionHistory as $history): ?>

                            <tr>

                                <td>

                                    <?= formatDate(
                                        $history['deleted_at']
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        $history['deleted_by_name'] ?? '-'
                                    ) ?>

                                </td>


                                <td>

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $history['reason']
                                        )
                                    ) ?>

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


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


</body>

</html>