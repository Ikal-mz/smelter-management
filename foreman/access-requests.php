<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/foreman.php';

$userId = $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| Ambil divisi Foreman
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT division_id
    FROM users
    WHERE id = ?
      AND status = 'active'
    LIMIT 1
");

$stmt->execute([$userId]);

$divisionId = $stmt->fetchColumn();

if (!$divisionId) {
    die('Divisi Foreman tidak ditemukan.');
}


/*
|--------------------------------------------------------------------------
| REQUEST TEAM TAMBAHAN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'request_team') {

        $smelterId = (int) ($_POST['smelter_id'] ?? 0);
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        try {

            if (!$smelterId || !$teamId) {
                throw new Exception(
                    'Smelter dan Team wajib dipilih.'
                );
            }

            if ($reason === '') {
                throw new Exception(
                    'Alasan request wajib diisi.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan Smelter ada di divisi Foreman
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
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

            if (!$stmt->fetchColumn()) {
                throw new Exception(
                    'Smelter tidak valid atau berbeda divisi.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan Team berada di Smelter tersebut
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM teams
                WHERE id = ?
                  AND smelter_id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $stmt->execute([
                $teamId,
                $smelterId
            ]);

            if (!$stmt->fetchColumn()) {
                throw new Exception(
                    'Team tidak berada pada Smelter yang dipilih.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan belum punya Team
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM user_access
                WHERE user_id = ?
                  AND team_id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $stmt->execute([
                $userId,
                $teamId
            ]);

            if ($stmt->fetchColumn()) {
                throw new Exception(
                    'Anda sudah memiliki akses ke Team tersebut.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan request pending tidak duplikat
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM access_requests
                WHERE user_id = ?
                  AND smelter_id = ?
                  AND team_id = ?
                  AND request_type = 'additional_team'
                  AND status = 'pending'
                LIMIT 1
            ");

            $stmt->execute([
                $userId,
                $smelterId,
                $teamId
            ]);

            if ($stmt->fetchColumn()) {
                throw new Exception(
                    'Request Team tersebut masih pending.'
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
                VALUES (?, ?, ?, 'additional_team', 'pending', ?, NOW())
            ");

            $stmt->execute([
                $userId,
                $smelterId,
                $teamId,
                $reason
            ]);


            header(
                'Location: access-requests.php?success=requested'
            );

            exit;


        } catch (Exception $e) {

            $error = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| Semua Smelter dalam divisi Foreman
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        name
    FROM smelters
    WHERE division_id = ?
      AND status = 'active'
    ORDER BY name
");

$stmt->execute([$divisionId]);

$smelters = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Team yang sudah dimiliki
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ua.id,
        s.name AS smelter_name,
        t.name AS team_name
    FROM user_access ua

    JOIN smelters s
        ON s.id = ua.smelter_id

    JOIN teams t
        ON t.id = ua.team_id

    WHERE ua.user_id = ?
      AND ua.team_id IS NOT NULL
      AND ua.status = 'active'

    ORDER BY s.name, t.name
");

$stmt->execute([$userId]);

$currentTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Riwayat Request
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ar.id,
        s.name AS smelter_name,
        t.name AS team_name,
        ar.status,
        ar.requested_reason,
        ar.created_at,
        ar.rejected_reason
    FROM access_requests ar

    JOIN smelters s
        ON s.id = ar.smelter_id

    JOIN teams t
        ON t.id = ar.team_id

    WHERE ar.user_id = ?
      AND ar.request_type = 'additional_team'

    ORDER BY ar.created_at DESC
");

$stmt->execute([$userId]);

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <title>Request Team</title>

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
            Request Team
        </span>

        <a
            href="dashboard.php"
            class="btn btn-outline-light btn-sm"
        >
            Dashboard
        </a>

    </div>

</nav>


<div class="container mt-4">


    <?php if (isset($_GET['success'])): ?>

        <div class="alert alert-success">
            Request Team berhasil dikirim.
        </div>

    <?php endif; ?>


    <?php if (!empty($error)): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <!-- TEAM SAAT INI -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">
            <strong>Team yang Saya Miliki</strong>
        </div>

        <div class="card-body">

            <?php if (!$currentTeams): ?>

                <span class="text-muted">
                    Belum memiliki Team.
                </span>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered">

                        <thead>

                            <tr>
                                <th>Smelter</th>
                                <th>Team</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($currentTeams as $team): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $team['smelter_name']
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $team['team_name']
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


    <!-- REQUEST FORM -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Request Team Tambahan
            </strong>

        </div>

        <div class="card-body">

            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="request_team"
                >


                <div class="mb-3">

                    <label class="form-label">
                        Divisi
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        value="Divisi Anda"
                        disabled
                    >

                    <small class="text-muted">
                        Divisi tidak dapat diganti.
                    </small>

                </div>


                <div class="mb-3">

                    <label class="form-label">
                        Smelter
                    </label>

                    <select
                        name="smelter_id"
                        id="smelter_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            -- Pilih Smelter --
                        </option>

                        <?php foreach ($smelters as $smelter): ?>

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


                <div class="mb-3">

                    <label class="form-label">
                        Team
                    </label>

                    <select
                        name="team_id"
                        id="team_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            -- Pilih Smelter terlebih dahulu --
                        </option>

                    </select>

                </div>


                <div class="mb-3">

                    <label class="form-label">
                        Alasan
                    </label>

                    <textarea
                        name="reason"
                        class="form-control"
                        rows="4"
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

        </div>

    </div>


    <!-- HISTORY -->

    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                Riwayat Request
            </strong>

        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered">

                    <thead class="table-light">

                        <tr>

                            <th>Smelter</th>
                            <th>Team</th>
                            <th>Status</th>
                            <th>Alasan</th>
                            <th>Tanggal</th>
                            <th>Keterangan</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$requests): ?>

                        <tr>

                            <td
                                colspan="6"
                                class="text-center text-muted"
                            >
                                Belum ada request.
                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php foreach ($requests as $request): ?>

                        <tr>

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

                                <?php if ($request['status'] === 'pending'): ?>

                                    <span class="badge bg-warning text-dark">
                                        Pending
                                    </span>

                                <?php elseif ($request['status'] === 'approved'): ?>

                                    <span class="badge bg-success">
                                        Approved
                                    </span>

                                <?php elseif ($request['status'] === 'rejected'): ?>

                                    <span class="badge bg-danger">
                                        Rejected
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
                                <?= htmlspecialchars(
                                    $request['rejected_reason'] ?? '-'
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<script>

document.getElementById('smelter_id').addEventListener(
    'change',
    function () {

        const smelterId = this.value;

        const teamSelect =
            document.getElementById('team_id');

        teamSelect.innerHTML =
            '<option value="">Loading...</option>';


        if (!smelterId) {

            teamSelect.innerHTML =
                '<option value="">-- Pilih Smelter terlebih dahulu --</option>';

            return;
        }


        fetch(
            '../api/get-teams.php?smelter_id='
            + encodeURIComponent(smelterId)
        )

        .then(response => response.json())

        .then(data => {

            teamSelect.innerHTML =
                '<option value="">-- Pilih Team --</option>';


            if (!data.success) {

                teamSelect.innerHTML =
                    '<option value="">Team tidak tersedia</option>';

                return;
            }


            data.data.forEach(team => {

                const option =
                    document.createElement('option');

                option.value = team.id;

                option.textContent = team.name;

                teamSelect.appendChild(option);

            });

        })

        .catch(error => {

            console.error(error);

            teamSelect.innerHTML =
                '<option value="">Gagal mengambil Team</option>';

        });

    }
);

</script>

</body>
</html>