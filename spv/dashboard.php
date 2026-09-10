<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/spv.php';

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT
        d.id AS division_id,
        d.name AS division_name,
        s.id AS smelter_id,
        s.name AS smelter_name
    FROM user_access ua
    JOIN smelters s
        ON s.id = ua.smelter_id
    JOIN divisions d
        ON d.id = s.division_id
    WHERE ua.user_id = ?
      AND ua.team_id IS NULL
      AND ua.status = 'active'
      AND s.status = 'active'
    ORDER BY d.name, s.name
");

$stmt->execute([$userId]);
$smelters = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Ambil semua team dari smelter yang dikuasai SPV
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        d.name AS division_name,
        s.id AS smelter_id,
        s.name AS smelter_name,
        t.id AS team_id,
        t.name AS team_name,
        t.link
    FROM user_access ua
    JOIN smelters s
        ON s.id = ua.smelter_id
    JOIN divisions d
        ON d.id = s.division_id
    JOIN teams t
        ON t.smelter_id = s.id
    WHERE ua.user_id = ?
      AND ua.team_id IS NULL
      AND ua.status = 'active'
      AND s.status = 'active'
      AND t.status = 'active'
    ORDER BY d.name, s.name, t.name
");

$stmt->execute([$userId]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Hitung pending Foreman
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM access_requests ar
    JOIN users u
        ON u.id = ar.user_id
    JOIN user_access ua
        ON ua.smelter_id = ar.smelter_id
       AND ua.user_id = ?
       AND ua.team_id IS NULL
       AND ua.status = 'active'
    WHERE ar.request_type = 'additional_team'
      AND ar.status = 'pending'
      AND u.role_id = (
          SELECT id
          FROM roles
          WHERE name = 'foreman'
          LIMIT 1
      )
");

$stmt->execute([$userId]);
$pendingForemen = (int) $stmt->fetchColumn();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard SPV</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body>

<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            Dashboard SPV
        </span>

        <div class="text-white">
            <?= htmlspecialchars($_SESSION['name'] ?? 'SPV') ?>

            &nbsp; | &nbsp;

            <a href="../auth/logout" class="btn btn-sm btn-danger">
                Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">

    <div class="row">

        <div class="col-md-3 mb-3">

            <div class="card shadow-sm">
                <div class="card-body">

                    <h5>Menu SPV</h5>

                    <hr>

                    <a href="dashboard"
                       class="btn btn-primary w-100 mb-2">
                        Dashboard
                    </a>

                    <a href="foremen"
                       class="btn btn-outline-primary w-100 mb-2">
                        Approval Foreman
                        <?php if ($pendingForemen > 0): ?>
                            <span class="badge bg-danger">
                                <?= $pendingForemen ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <a href="my-access"
                        class="btn btn-outline-secondary w-100 mb-2">
                        Akses Saya
                    </a>

                    <a href="access-requests"
                        class="btn btn-outline-warning w-100">
                        Request Smelter
                    </a>

                </div>
            </div>

        </div>


        <div class="col-md-9">

            <h3 class="mb-4">
                Selamat Datang,
                <?= htmlspecialchars($_SESSION['name'] ?? '') ?>
            </h3>


            <!-- SMELTER ACCESS -->

            <div class="card shadow-sm mb-4">

                <div class="card-header">
                    <strong>Smelter yang Anda Kelola</strong>
                </div>

                <div class="card-body">

                    <?php if (!$smelters): ?>

                        <div class="alert alert-warning">
                            Anda belum memiliki akses Smelter.
                        </div>

                    <?php else: ?>

                        <div class="row">

                            <?php foreach ($smelters as $smelter): ?>

                                <div class="col-md-4 mb-3">

                                    <div class="card h-100 border-primary">

                                        <div class="card-body">

                                            <h5>
                                                <?= htmlspecialchars($smelter['smelter_name']) ?>
                                            </h5>

                                            <p class="text-muted mb-0">
                                                <?= htmlspecialchars($smelter['division_name']) ?>
                                            </p>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            </div>


            <!-- TEAM LINKS -->

            <div class="card shadow-sm">

                <div class="card-header">
                    <strong>Link Team</strong>
                </div>

                <div class="card-body">

                    <?php if (!$teams): ?>

                        <div class="alert alert-info">
                            Belum ada Team aktif pada Smelter yang Anda kelola.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-bordered table-hover">

                                <thead class="table-light">

                                    <tr>
                                        <th>Divisi</th>
                                        <th>Smelter</th>
                                        <th>Team</th>
                                        <th>Link</th>
                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($teams as $team): ?>

                                        <tr>

                                            <td>
                                                <?= htmlspecialchars($team['division_name']) ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($team['smelter_name']) ?>
                                            </td>

                                            <td>
                                                <?= htmlspecialchars($team['team_name']) ?>
                                            </td>

                                            <td>

                                                <?php if (!empty($team['link'])): ?>

                                                    <a
                                                        href="<?= htmlspecialchars($team['link']) ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="btn btn-sm btn-primary"
                                                    >
                                                        Buka Link
                                                    </a>

                                                <?php else: ?>

                                                    <span class="text-muted">
                                                        Link belum tersedia
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

        </div>

    </div>

</div>

</body>
</html>