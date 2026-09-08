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

function e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| STATISTIK USER
|--------------------------------------------------------------------------
*/

/*
| Total user aktif + pending + suspended + rejected
| Tidak menghitung deleted.
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE status <> 'deleted'
");

$totalUsers = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| PENDING REGISTRASI
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE status = 'pending'
");

$pendingUsers = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| USER ACTIVE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE status = 'active'
");

$activeUsers = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| USER SUSPENDED
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE status = 'suspended'
");

$suspendedUsers = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| USER DELETED
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE status = 'deleted'
");

$deletedUsers = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| SPV AKTIF
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM users u

    INNER JOIN roles r
        ON r.id = u.role_id

    WHERE u.status = 'active'
      AND LOWER(r.name) = 'spv'
");

$totalSpv = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| FOREMAN AKTIF
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM users u

    INNER JOIN roles r
        ON r.id = u.role_id

    WHERE u.status = 'active'
      AND LOWER(r.name) = 'foreman'
");

$totalForeman = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| STATISTIK ORGANISASI
|--------------------------------------------------------------------------
*/

/*
| Divisi aktif
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM divisions
    WHERE status = 'active'
");

$totalDivisions = (int) $stmt->fetchColumn();


/*
| Smelter aktif
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM smelters
    WHERE status = 'active'
");

$totalSmelters = (int) $stmt->fetchColumn();


/*
| Team aktif
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM teams
    WHERE status = 'active'
");

$totalTeams = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| REQUEST AKSES - SPV TAMBAHAN SMELTER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total

    FROM access_requests ar

    INNER JOIN users u
        ON u.id = ar.user_id

    INNER JOIN roles r
        ON r.id = u.role_id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    WHERE ar.status = 'pending'
      AND ar.request_type = 'additional_smelter'
      AND LOWER(r.name) = 'spv'
      AND u.status = 'active'
      AND s.status = 'active'
");

$pendingSmelterRequests = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| REQUEST AKSES - FOREMAN TAMBAHAN TEAM
| HANYA YANG BELUM ADA SPV
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total

    FROM access_requests ar

    INNER JOIN users u
        ON u.id = ar.user_id

    INNER JOIN roles r
        ON r.id = u.role_id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    INNER JOIN teams t
        ON t.id = ar.team_id

    WHERE ar.status = 'pending'
      AND ar.request_type = 'additional_team'
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
");

$pendingTeamRequests = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| TOTAL REQUEST YANG PERLU DIPROSES ADMIN
|--------------------------------------------------------------------------
*/

$totalAdminRequests =
    $pendingSmelterRequests
    +
    $pendingTeamRequests;


/*
|--------------------------------------------------------------------------
| REQUEST AKSES TOTAL
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT COUNT(*) AS total

    FROM access_requests

    WHERE status = 'pending'
");

$pendingAccessRequests = (int) $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| PENDING REGISTRASI PER ROLE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        LOWER(r.name) AS role_name,
        COUNT(*) AS total

    FROM users u

    INNER JOIN roles r
        ON r.id = u.role_id

    WHERE u.status = 'pending'

    GROUP BY LOWER(r.name)
");

$pendingByRole = [
    'spv'     => 0,
    'foreman' => 0
];

foreach (
    $stmt->fetchAll(PDO::FETCH_ASSOC)
    as $row
) {

    if (
        isset(
            $pendingByRole[
                $row['role_name']
            ]
        )
    ) {

        $pendingByRole[
            $row['role_name']
        ] = (int) $row['total'];
    }
}


/*
|--------------------------------------------------------------------------
| REQUEST TERBARU YANG PERLU DIPROSES ADMIN
|--------------------------------------------------------------------------
|
| Hanya:
| - additional_smelter SPV
| - additional_team Foreman tanpa SPV aktif
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
          | hanya jika belum ada SPV
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

$latestRequests =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| REQUEST TYPE LABEL
|--------------------------------------------------------------------------
*/

function requestTypeLabel(string $type): string
{
    switch ($type) {

        case 'additional_smelter':

            return
                '<span class="badge bg-primary">
                    Tambahan Smelter
                </span>';

        case 'additional_team':

            return
                '<span class="badge bg-info text-dark">
                    Tambahan Team
                </span>';

        default:

            return
                '<span class="badge bg-secondary">'
                . e($type)
                . '</span>';
    }
}


/*
|--------------------------------------------------------------------------
| ROLE BADGE
|--------------------------------------------------------------------------
*/

function dashboardRoleBadge(string $role): string
{
    switch (strtolower($role)) {

        case 'spv':

            return
                '<span class="badge bg-primary">
                    SPV
                </span>';

        case 'foreman':

            return
                '<span class="badge bg-info text-dark">
                    Foreman
                </span>';

        default:

            return
                '<span class="badge bg-secondary">'
                . e($role)
                . '</span>';
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

            <?= e(
                $_SESSION['name'] ?? 'Administrator'
            ) ?>

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

        <div
            class="col-md-2 bg-dark p-3 sidebar"
        >

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


                <a
                    href="teams.php"
                    class="btn btn-outline-warning menu-link"
                >
                    Team Management
                </a>


                <a
                    href="divisions.php"
                    class="btn btn-outline-warning menu-link"
                >
                    Division
                </a>


                <a
                    href="smelters.php"
                    class="btn btn-outline-warning menu-link"
                >
                    Smelter
                </a>


            </div>

        </div>


        <!-- =================================================
             CONTENT
        ================================================== -->

        <div class="col-md-10 p-4">


            <!-- HEADER -->

            <div
                class="d-flex justify-content-between align-items-center mb-4"
            >

                <div>

                    <h2 class="mb-1">
                        Dashboard Admin
                    </h2>

                    <p class="text-muted mb-0">
                        Ringkasan sistem dan pekerjaan yang perlu diproses.
                    </p>

                </div>

            </div>


            <!-- =================================================
                 ALERT PEKERJAAN ADMIN
            ================================================== -->

            <?php if (
                $pendingUsers > 0
                ||
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
                                registrasi user masih menunggu approval.
                            </div>

                        <?php endif; ?>


                        <?php if ($pendingSmelterRequests > 0): ?>

                            <div>
                                •
                                <strong>
                                    <?= $pendingSmelterRequests ?>
                                </strong>
                                request Smelter tambahan SPV menunggu approval.
                            </div>

                        <?php endif; ?>


                        <?php if ($pendingTeamRequests > 0): ?>

                            <div>
                                •
                                <strong>
                                    <?= $pendingTeamRequests ?>
                                </strong>
                                request Team tambahan Foreman menunggu approval Admin.
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


                <!-- TOTAL -->

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
                                    SPV:
                                    <?= $pendingByRole['spv'] ?>
                                    |
                                    Foreman:
                                    <?= $pendingByRole['foreman'] ?>
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
                                    SPV:
                                    <?= $totalSpv ?>
                                    |
                                    Foreman:
                                    <?= $totalForeman ?>
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
                 ORGANIZATION STATISTICS
            ================================================== -->

            <h5 class="mb-3">
                Struktur Organisasi
            </h5>


            <div class="row g-3 mb-4">


                <!-- DIVISION -->

                <div class="col-6 col-lg-4">

                    <div class="card shadow-sm h-100">

                        <div class="card-body">

                            <small class="text-muted">
                                Divisi Aktif
                            </small>

                            <div class="stat-number">
                                <?= $totalDivisions ?>
                            </div>

                        </div>

                    </div>

                </div>


                <!-- SMELTER -->

                <div class="col-6 col-lg-4">

                    <div class="card shadow-sm h-100">

                        <div class="card-body">

                            <small class="text-muted">
                                Smelter Aktif
                            </small>

                            <div class="stat-number">
                                <?= $totalSmelters ?>
                            </div>

                        </div>

                    </div>

                </div>


                <!-- TEAM -->

                <div class="col-6 col-lg-4">

                    <div class="card shadow-sm h-100">

                        <div class="card-body">

                            <small class="text-muted">
                                Team Aktif
                            </small>

                            <div class="stat-number">
                                <?= $totalTeams ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 ACCESS REQUEST STATISTICS
            ================================================== -->

            <h5 class="mb-3">
                Request Akses
            </h5>


            <div class="row g-3 mb-4">


                <!-- ALL REQUEST -->

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
                 LATEST REQUESTS
            ================================================== -->

            <div class="card shadow-sm mb-4">


                <div class="card-header">

                    <div
                        class="d-flex justify-content-between align-items-center"
                    >

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

                            <table
                                class="table table-bordered table-hover"
                            >

                                <thead class="table-dark">

                                    <tr>

                                        <th>
                                            User
                                        </th>

                                        <th>
                                            Role
                                        </th>

                                        <th>
                                            Divisi
                                        </th>

                                        <th>
                                            Request
                                        </th>

                                        <th>
                                            Tujuan
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


                                <?php foreach (
                                    $latestRequests
                                    as $request
                                ): ?>

                                    <tr>


                                        <!-- USER -->

                                        <td>

                                            <strong>
                                                <?= e(
                                                    $request['name']
                                                ) ?>
                                            </strong>

                                            <br>

                                            <small class="text-muted">
                                                <?= e(
                                                    $request['nik']
                                                ) ?>
                                            </small>

                                        </td>


                                        <!-- ROLE -->

                                        <td>

                                            <?= dashboardRoleBadge(
                                                $request['role_name']
                                            ) ?>

                                        </td>


                                        <!-- DIVISION -->

                                        <td>

                                            <?= e(
                                                $request['division_name']
                                            ) ?>

                                        </td>


                                        <!-- REQUEST TYPE -->

                                        <td>

                                            <?= requestTypeLabel(
                                                $request['request_type']
                                            ) ?>

                                        </td>


                                        <!-- TUJUAN -->

                                        <td>

                                            <strong>
                                                <?= e(
                                                    $request['smelter_name']
                                                ) ?>
                                            </strong>


                                            <?php if (
                                                !empty(
                                                    $request['team_name']
                                                )
                                            ): ?>

                                                <br>

                                                <small class="text-muted">

                                                    Team:
                                                    <?= e(
                                                        $request['team_name']
                                                    ) ?>

                                                </small>

                                            <?php endif; ?>

                                        </td>


                                        <!-- ALASAN -->

                                        <td>

                                            <?php if (
                                                !empty(
                                                    $request[
                                                        'requested_reason'
                                                    ]
                                                )
                                            ): ?>

                                                <span
                                                    title="<?= e(
                                                        $request[
                                                            'requested_reason'
                                                        ]
                                                    ) ?>"
                                                >
                                                    <?= e(
                                                        mb_strimwidth(
                                                            $request[
                                                                'requested_reason'
                                                            ],
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


                                        <!-- DATE -->

                                        <td>

                                            <small>

                                                <?= e(
                                                    date(
                                                        'd-m-Y H:i',
                                                        strtotime(
                                                            $request[
                                                                'created_at'
                                                            ]
                                                        )
                                                    )
                                                ) ?>

                                            </small>

                                        </td>


                                        <!-- ACTION -->

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
                 USER STATUS INFORMATION
            ================================================== -->

            <div class="card shadow-sm">


                <div class="card-body">


                    <h5>
                        Informasi Sistem
                    </h5>


                    <div class="row g-3 mt-1">


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


        </div>

    </div>

</div>


</body>

</html>