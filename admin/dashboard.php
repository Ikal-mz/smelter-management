<?php

require_once __DIR__ . '/../middleware/admin.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function requestTypeLabel(string $type): string
{
    return match ($type) {

        'additional_smelter' =>
            '<span class="badge bg-primary">Tambahan Smelter</span>',

        'additional_team' =>
            '<span class="badge bg-info text-dark">Tambahan Team</span>',

        default =>
            '<span class="badge bg-secondary">' . e($type) . '</span>',
    };
}

function dashboardRoleBadge(string $role): string
{
    return match (strtolower($role)) {

        'spv' =>
            '<span class="badge bg-primary">SPV</span>',

        'foreman' =>
            '<span class="badge bg-info text-dark">Foreman</span>',

        default =>
            '<span class="badge bg-secondary">' . e($role) . '</span>',
    };
}

/*
|--------------------------------------------------------------------------
| STATISTIK USER
|--------------------------------------------------------------------------
|
| Satu query untuk seluruh status user.
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        COUNT(CASE WHEN status <> 'deleted' THEN 1 END) AS total_users,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_users,
        COUNT(CASE WHEN status = 'active' THEN 1 END) AS active_users,
        COUNT(CASE WHEN status = 'suspended' THEN 1 END) AS suspended_users,
        COUNT(CASE WHEN status = 'deleted' THEN 1 END) AS deleted_users
    FROM users
");

$userStats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalUsers     = (int) ($userStats['total_users'] ?? 0);
$pendingUsers   = (int) ($userStats['pending_users'] ?? 0);
$activeUsers    = (int) ($userStats['active_users'] ?? 0);
$suspendedUsers = (int) ($userStats['suspended_users'] ?? 0);
$deletedUsers   = (int) ($userStats['deleted_users'] ?? 0);


/*
|--------------------------------------------------------------------------
| STATISTIK USER PER ROLE
|--------------------------------------------------------------------------
|
| Sekaligus mengambil:
| - SPV aktif
| - Foreman aktif
| - SPV pending
| - Foreman pending
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        LOWER(r.name) AS role_name,

        COUNT(
            CASE
                WHEN u.status = 'active'
                THEN 1
            END
        ) AS active_total,

        COUNT(
            CASE
                WHEN u.status = 'pending'
                THEN 1
            END
        ) AS pending_total

    FROM users u

    INNER JOIN roles r
        ON r.id = u.role_id

    WHERE LOWER(r.name) IN ('spv', 'foreman')

    GROUP BY LOWER(r.name)
");

$roleStats = [
    'spv' => [
        'active'  => 0,
        'pending' => 0,
    ],
    'foreman' => [
        'active'  => 0,
        'pending' => 0,
    ],
];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

    $role = strtolower($row['role_name']);

    if (!isset($roleStats[$role])) {
        continue;
    }

    $roleStats[$role]['active'] =
        (int) $row['active_total'];

    $roleStats[$role]['pending'] =
        (int) $row['pending_total'];
}

$totalSpv = $roleStats['spv']['active'];
$totalForeman = $roleStats['foreman']['active'];

$pendingSpv = $roleStats['spv']['pending'];
$pendingForeman = $roleStats['foreman']['pending'];

$pendingByRole = [
    'spv'     => $pendingSpv,
    'foreman' => $pendingForeman,
];


/*
|--------------------------------------------------------------------------
| STATISTIK ORGANISASI
|--------------------------------------------------------------------------
|
| Satu query untuk:
| - Divisi aktif
| - Smelter aktif
| - Team aktif
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT

        (
            SELECT COUNT(*)
            FROM divisions
            WHERE status = 'active'
        ) AS total_divisions,

        (
            SELECT COUNT(*)
            FROM smelters
            WHERE status = 'active'
        ) AS total_smelters,

        (
            SELECT COUNT(*)
            FROM teams
            WHERE status = 'active'
        ) AS total_teams
");

$organizationStats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalDivisions =
    (int) ($organizationStats['total_divisions'] ?? 0);

$totalSmelters =
    (int) ($organizationStats['total_smelters'] ?? 0);

$totalTeams =
    (int) ($organizationStats['total_teams'] ?? 0);


/*
|--------------------------------------------------------------------------
| REQUEST AKSES ADMIN
|--------------------------------------------------------------------------
|
| Satu query untuk:
| - Semua request pending
| - Request tambahan Smelter SPV
| - Request tambahan Team Foreman
|   yang belum memiliki SPV aktif
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT

        COUNT(*) AS total_pending,

        COUNT(
            CASE
                WHEN ar.request_type = 'additional_smelter'
                  AND LOWER(r.name) = 'spv'
                  AND u.status = 'active'
                  AND s.status = 'active'
                THEN 1
            END
        ) AS pending_smelter,

        COUNT(
            CASE
                WHEN ar.request_type = 'additional_team'
                  AND LOWER(r.name) = 'foreman'
                  AND u.status = 'active'
                  AND s.status = 'active'
                  AND t.status = 'active'

                  AND NOT EXISTS (

                      SELECT 1

                      FROM users spv

                      INNER JOIN roles spv_role
                          ON spv_role.id = spv.role_id

                      INNER JOIN user_access spv_access
                          ON spv_access.user_id = spv.id

                      WHERE LOWER(spv_role.name) = 'spv'
                        AND spv.status = 'active'
                        AND spv.division_id = u.division_id
                        AND spv_access.smelter_id = ar.smelter_id
                        AND spv_access.team_id IS NULL
                        AND spv_access.status = 'active'
                  )

                THEN 1
            END
        ) AS pending_team

    FROM access_requests ar

    INNER JOIN users u
        ON u.id = ar.user_id

    INNER JOIN roles r
        ON r.id = u.role_id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    LEFT JOIN teams t
        ON t.id = ar.team_id

    WHERE ar.status = 'pending'
");

$requestStats = $stmt->fetch(PDO::FETCH_ASSOC);

$pendingAccessRequests =
    (int) ($requestStats['total_pending'] ?? 0);

$pendingSmelterRequests =
    (int) ($requestStats['pending_smelter'] ?? 0);

$pendingTeamRequests =
    (int) ($requestStats['pending_team'] ?? 0);

$totalAdminRequests =
    $pendingSmelterRequests +
    $pendingTeamRequests;


/*
|--------------------------------------------------------------------------
| REQUEST TERBARU YANG PERLU DIPROSES ADMIN
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        ar.id,
        ar.user_id,
        ar.smelter_id,
        ar.team_id,
        ar.request_type,
        ar.requested_reason,
        ar.created_at,

        u.nik,
        u.name AS user_name,
        u.status AS user_status,

        r.name AS role_name,

        d.name AS division_name,

        s.name AS smelter_name,

        t.name AS team_name

    FROM access_requests ar

    INNER JOIN users u
        ON u.id = ar.user_id

    INNER JOIN roles r
        ON r.id = u.role_id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    INNER JOIN divisions d
        ON d.id = s.division_id

    LEFT JOIN teams t
        ON t.id = ar.team_id

    WHERE ar.status = 'pending'

      AND (

          /*
          | SPV tambahan Smelter
          */

          (
              ar.request_type = 'additional_smelter'
              AND LOWER(r.name) = 'spv'
              AND u.status = 'active'
              AND s.status = 'active'
          )

          OR

          /*
          | Foreman tambahan Team
          | Hanya jika belum ada SPV aktif
          */

          (
              ar.request_type = 'additional_team'
              AND LOWER(r.name) = 'foreman'
              AND u.status = 'active'
              AND s.status = 'active'
              AND t.status = 'active'

              AND NOT EXISTS (

                  SELECT 1

                  FROM users spv

                  INNER JOIN roles spv_role
                      ON spv_role.id = spv.role_id

                  INNER JOIN user_access spv_access
                      ON spv_access.user_id = spv.id

                  WHERE LOWER(spv_role.name) = 'spv'
                    AND spv.status = 'active'
                    AND spv.division_id = u.division_id
                    AND spv_access.smelter_id = ar.smelter_id
                    AND spv_access.team_id IS NULL
                    AND spv_access.status = 'active'
              )
          )
      )

    ORDER BY ar.created_at DESC

    LIMIT 10
");

$latestRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Admin Dashboard</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            min-height: 100vh;
        }

        .sidebar {
            min-height: calc(100vh - 56px);
        }

        .stat-card {
            transition: .2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
        }

        .menu-link {
            text-align: left;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

    </style>

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

            <?= e($_SESSION['name'] ?? 'Administrator') ?>

            &nbsp; | &nbsp;

            <a
                href="../auth/logout.php"
                class="text-white text-decoration-none"
            >
                Logout
            </a>

        </div>

    </div>

</nav>


<div class="container-fluid">

    <div class="row">


        <!-- =================================================
             SIDEBAR
        ================================================== -->

        <aside class="col-md-2 bg-dark p-3 sidebar">

            <h5 class="text-white">
                ADMIN
            </h5>

            <hr class="text-white">

            <div class="d-grid gap-2">

                <a
                    href="dashboard.php"
                    class="btn btn-warning menu-link"
                >
                    Dashboard
                </a>


                <a
                    href="users.php"
                    class="btn btn-outline-warning menu-link"
                >
                    User Management

                    <?php if ($pendingUsers > 0): ?>

                        <span class="badge bg-danger float-end">
                            <?= $pendingUsers ?>
                        </span>

                    <?php endif; ?>

                </a>


                <a
                    href="divisions.php"
                    class="btn btn-outline-warning menu-link"
                >
                    Divisi
                </a>


                <a
                    href="smelters.php"
                    class="btn btn-outline-warning menu-link"
                >
                    Smelter
                </a>


                <a
                    href="teams.php"
                    class="btn btn-outline-warning menu-link"
                >
                    Team
                </a>


                <a
                    href="access-requests.php"
                    class="btn btn-outline-warning menu-link"
                >
                    Access Request

                    <?php if ($totalAdminRequests > 0): ?>

                        <span class="badge bg-danger float-end">
                            <?= $totalAdminRequests ?>
                        </span>

                    <?php endif; ?>

                </a>

            </div>

        </aside>


        <!-- =================================================
             CONTENT
        ================================================== -->

        <main class="col-md-10 p-4">


            <!-- HEADER -->

            <div class="mb-4">

                <h2 class="mb-1">
                    Dashboard Admin
                </h2>

                <p class="text-muted mb-0">
                    Ringkasan sistem dan pekerjaan yang perlu diproses.
                </p>

            </div>


            <!-- =================================================
                 ALERT PEKERJAAN ADMIN
            ================================================== -->

            <?php if (
                $pendingUsers > 0 ||
                $totalAdminRequests > 0
            ): ?>

                <div class="alert alert-warning shadow-sm">

                    <strong>
                        Perlu perhatian Admin
                    </strong>

                    <div class="mt-2">

                        <?php if ($pendingUsers > 0): ?>

                            <div>
                                •
                                <strong>
                                    <?= $pendingUsers ?>
                                </strong>
                                registrasi user menunggu approval.
                            </div>

                        <?php endif; ?>


                        <?php if ($pendingSmelterRequests > 0): ?>

                            <div>
                                •
                                <strong>
                                    <?= $pendingSmelterRequests ?>
                                </strong>
                                request Smelter tambahan SPV
                                menunggu approval Admin.
                            </div>

                        <?php endif; ?>


                        <?php if ($pendingTeamRequests > 0): ?>

                            <div>
                                •
                                <strong>
                                    <?= $pendingTeamRequests ?>
                                </strong>
                                request Team tambahan Foreman
                                menunggu approval Admin.
                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            <?php else: ?>

                <div class="alert alert-success shadow-sm">

                    <strong>
                        Semua aman.
                    </strong>

                    Tidak ada approval atau request akses
                    yang perlu diproses Admin saat ini.

                </div>

            <?php endif; ?>


            <!-- =================================================
                 USER STATISTICS
            ================================================== -->

            <h5 class="mb-3">
                Statistik User
            </h5>

            <div class="row g-3 mb-4">


                <!-- TOTAL USER -->

                <div class="col-6 col-lg-3">

                    <div class="card shadow-sm stat-card h-100">

                        <div class="card-body">

                            <small class="text-muted">
                                Total User
                            </small>

                            <div class="stat-number">
                                <?= $totalUsers ?>
                            </div>

                            <small class="text-muted">
                                Tidak termasuk deleted
                            </small>

                        </div>

                    </div>

                </div>


                <!-- PENDING -->

                <div class="col-6 col-lg-3">

                    <a
                        href="users.php?status=pending"
                        class="text-decoration-none text-dark"
                    >

                        <div class="card shadow-sm border-warning stat-card h-100">

                            <div class="card-body">

                                <small class="text-muted">
                                    Pending Registrasi
                                </small>

                                <div class="stat-number text-warning">
                                    <?= $pendingUsers ?>
                                </div>

                                <small class="text-muted">
                                    SPV: <?= $pendingSpv ?>
                                    |
                                    Foreman: <?= $pendingForeman ?>
                                </small>

                            </div>

                        </div>

                    </a>

                </div>


                <!-- ACTIVE -->

                <div class="col-6 col-lg-3">

                    <a
                        href="users.php?status=active"
                        class="text-decoration-none text-dark"
                    >

                        <div class="card shadow-sm border-success stat-card h-100">

                            <div class="card-body">

                                <small class="text-muted">
                                    User Aktif
                                </small>

                                <div class="stat-number text-success">
                                    <?= $activeUsers ?>
                                </div>

                                <small class="text-muted">
                                    SPV: <?= $totalSpv ?>
                                    |
                                    Foreman: <?= $totalForeman ?>
                                </small>

                            </div>

                        </div>

                    </a>

                </div>


                <!-- SUSPENDED -->

                <div class="col-6 col-lg-3">

                    <a
                        href="users.php?status=suspended"
                        class="text-decoration-none text-dark"
                    >

                        <div class="card shadow-sm border-secondary stat-card h-100">

                            <div class="card-body">

                                <small class="text-muted">
                                    Suspended
                                </small>

                                <div class="stat-number text-secondary">
                                    <?= $suspendedUsers ?>
                                </div>

                                <small class="text-muted">
                                    User dinonaktifkan
                                </small>

                            </div>

                        </div>

                    </a>

                </div>

            </div>


            <!-- =================================================
                 STRUKTUR ORGANISASI
            ================================================== -->

            <h5 class="mb-3">
                Struktur Organisasi
            </h5>

            <div class="row g-3 mb-4">


                <div class="col-6 col-lg-4">

                    <a
                        href="divisions.php"
                        class="text-decoration-none text-dark"
                    >

                        <div class="card shadow-sm stat-card h-100">

                            <div class="card-body">

                                <small class="text-muted">
                                    Divisi Aktif
                                </small>

                                <div class="stat-number">
                                    <?= $totalDivisions ?>
                                </div>

                            </div>

                        </div>

                    </a>

                </div>


                <div class="col-6 col-lg-4">

                    <a
                        href="smelters.php"
                        class="text-decoration-none text-dark"
                    >

                        <div class="card shadow-sm stat-card h-100">

                            <div class="card-body">

                                <small class="text-muted">
                                    Smelter Aktif
                                </small>

                                <div class="stat-number">
                                    <?= $totalSmelters ?>
                                </div>

                            </div>

                        </div>

                    </a>

                </div>


                <div class="col-6 col-lg-4">

                    <a
                        href="teams.php"
                        class="text-decoration-none text-dark"
                    >

                        <div class="card shadow-sm stat-card h-100">

                            <div class="card-body">

                                <small class="text-muted">
                                    Team Aktif
                                </small>

                                <div class="stat-number">
                                    <?= $totalTeams ?>
                                </div>

                            </div>

                        </div>

                    </a>

                </div>

            </div>


            <!-- =================================================
                 REQUEST AKSES
            ================================================== -->

            <h5 class="mb-3">
                Request Akses
            </h5>

            <div class="row g-3 mb-4">


                <!-- SEMUA REQUEST -->

                <div class="col-6 col-lg-4">

                    <a
                        href="access-requests.php"
                        class="text-decoration-none text-dark"
                    >

                        <div class="card shadow-sm border-warning stat-card h-100">

                            <div class="card-body">

                                <small class="text-muted">
                                    Semua Pending Request
                                </small>

                                <div class="stat-number text-warning">
                                    <?= $pendingAccessRequests ?>
                                </div>

                                <small class="text-muted">
                                    Seluruh request pending
                                </small>

                            </div>

                        </div>

                    </a>

                </div>


                <!-- SPV -->

                <div class="col-6 col-lg-4">

                    <a
                        href="access-requests.php"
                        class="text-decoration-none text-dark"
                    >

                        <div class="card shadow-sm border-primary stat-card h-100">

                            <div class="card-body">

                                <small class="text-muted">
                                    Smelter Tambahan SPV
                                </small>

                                <div class="stat-number text-primary">
                                    <?= $pendingSmelterRequests ?>
                                </div>

                                <small class="text-muted">
                                    Menunggu approval Admin
                                </small>

                            </div>

                        </div>

                    </a>

                </div>


                <!-- FOREMAN -->

                <div class="col-6 col-lg-4">

                    <a
                        href="access-requests.php"
                        class="text-decoration-none text-dark"
                    >

                        <div class="card shadow-sm border-info stat-card h-100">

                            <div class="card-body">

                                <small class="text-muted">
                                    Team Tambahan Foreman
                                </small>

                                <div class="stat-number text-info">
                                    <?= $pendingTeamRequests ?>
                                </div>

                                <small class="text-muted">
                                    Hanya jika belum ada SPV
                                </small>

                            </div>

                        </div>

                    </a>

                </div>

            </div>


            <!-- =================================================
                 REQUEST TERBARU
            ================================================== -->

            <div class="card shadow-sm mb-4">

                <div class="card-header">

                    <div class="d-flex justify-content-between align-items-center">

                        <strong>
                            Request Terbaru yang Perlu Diproses
                        </strong>

                        <a
                            href="access-requests.php"
                            class="btn btn-sm btn-outline-primary"
                        >
                            Lihat Semua
                        </a>

                    </div>

                </div>


                <div class="card-body">

                    <?php if (!$latestRequests): ?>

                        <div class="text-center text-muted py-4">

                            Tidak ada request yang perlu diproses Admin.

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover">

                                <thead class="table-dark">

                                    <tr>

                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Divisi</th>
                                        <th>Request</th>
                                        <th>Tujuan</th>
                                        <th>Alasan</th>
                                        <th>Tanggal</th>
                                        <th>Aksi</th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php foreach ($latestRequests as $request): ?>

                                    <tr>

                                        <td>

                                            <strong>
                                                <?= e($request['user_name']) ?>
                                            </strong>

                                            <br>

                                            <small class="text-muted">
                                                <?= e($request['nik']) ?>
                                            </small>

                                        </td>


                                        <td>
                                            <?= dashboardRoleBadge(
                                                $request['role_name']
                                            ) ?>
                                        </td>


                                        <td>
                                            <?= e($request['division_name']) ?>
                                        </td>


                                        <td>
                                            <?= requestTypeLabel(
                                                $request['request_type']
                                            ) ?>
                                        </td>


                                        <td>

                                            <strong>
                                                <?= e($request['smelter_name']) ?>
                                            </strong>

                                            <?php if (!empty($request['team_name'])): ?>

                                                <br>

                                                <small class="text-muted">
                                                    Team:
                                                    <?= e($request['team_name']) ?>
                                                </small>

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <?php if (
                                                !empty($request['requested_reason'])
                                            ): ?>

                                                <span
                                                    title="<?= e(
                                                        $request['requested_reason']
                                                    ) ?>"
                                                >
                                                    <?= e(
                                                        mb_strimwidth(
                                                            $request['requested_reason'],
                                                            0,
                                                            40,
                                                            '...'
                                                        )
                                                    ) ?>
                                                </span>

                                            <?php else: ?>

                                                <span class="text-muted">
                                                    -
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <small>
                                                <?= e(
                                                    date(
                                                        'd-m-Y H:i',
                                                        strtotime(
                                                            $request['created_at']
                                                        )
                                                    )
                                                ) ?>
                                            </small>

                                        </td>


                                        <td>

                                            <a
                                                href="access-requests.php"
                                                class="btn btn-sm btn-primary"
                                            >
                                                Proses
                                            </a>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            </div>


            <!-- =================================================
                 INFORMASI SISTEM
            ================================================== -->

            <div class="card shadow-sm">

                <div class="card-body">

                    <h5 class="mb-3">
                        Informasi Sistem
                    </h5>

                    <div class="row g-3">


                        <div class="col-md-4">

                            <div class="border rounded p-3">

                                <small class="text-muted">
                                    Deleted User
                                </small>

                                <h4 class="mb-0">
                                    <?= $deletedUsers ?>
                                </h4>

                                <small class="text-muted">
                                    Soft-delete
                                </small>

                            </div>

                        </div>


                        <div class="col-md-4">

                            <div class="border rounded p-3">

                                <small class="text-muted">
                                    SPV Aktif
                                </small>

                                <h4 class="mb-0">
                                    <?= $totalSpv ?>
                                </h4>

                                <small class="text-muted">
                                    Pengelola Smelter
                                </small>

                            </div>

                        </div>


                        <div class="col-md-4">

                            <div class="border rounded p-3">

                                <small class="text-muted">
                                    Foreman Aktif
                                </small>

                                <h4 class="mb-0">
                                    <?= $totalForeman ?>
                                </h4>

                                <small class="text-muted">
                                    Pengelola Team
                                </small>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


        </main>

    </div>

</div>


</body>

</html>