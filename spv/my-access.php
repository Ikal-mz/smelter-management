<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/spv.php';

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT
        s.id AS smelter_id,
        s.name AS smelter_name,
        d.name AS division_name
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

$access = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <title>Akses Saya</title>

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body>

<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <span class="navbar-brand">
            Akses Saya
        </span>

        <a
            href="dashboard"
            class="btn btn-outline-light btn-sm"
        >
            Dashboard
        </a>

    </div>

</nav>


<div class="container mt-4">

    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                Smelter yang Saya Kelola
            </strong>

        </div>


        <div class="card-body">

            <?php if (!$access): ?>

                <div class="alert alert-warning">
                    Anda belum memiliki akses Smelter.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered">

                        <thead class="table-light">

                            <tr>

                                <th>#</th>

                                <th>Divisi</th>

                                <th>Smelter</th>

                                <th>Status</th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($access as $index => $item): ?>

                            <tr>

                                <td>
                                    <?= $index + 1 ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $item['division_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $item['smelter_name']
                                    ) ?>
                                </td>

                                <td>

                                    <span class="badge bg-success">
                                        Aktif
                                    </span>

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

</body>
</html>