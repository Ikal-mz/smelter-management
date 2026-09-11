<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/foreman.php';


/*
|--------------------------------------------------------------------------
| User Login
|--------------------------------------------------------------------------
*/

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
| Ambil data Foreman dari database
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.division_id,
        u.status,
        r.name AS role_name
    FROM users u
    INNER JOIN roles r
        ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Pastikan user valid
|--------------------------------------------------------------------------
*/

if (!$user) {
    http_response_code(403);
    exit('User tidak ditemukan.');
}


/*
|--------------------------------------------------------------------------
| Pastikan user masih active
|--------------------------------------------------------------------------
*/

if ($user['status'] !== 'active') {
    http_response_code(403);
    exit('Akun Anda tidak aktif.');
}


/*
|--------------------------------------------------------------------------
| Pastikan role Foreman
|--------------------------------------------------------------------------
*/

if ($user['role_name'] !== 'foreman') {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke halaman ini.');
}


$divisionId = (int) $user['division_id'];


/*
|--------------------------------------------------------------------------
| Variable pesan
|--------------------------------------------------------------------------
*/

$error = '';

$success = '';


/*
|--------------------------------------------------------------------------
| REQUEST TEAM TAMBAHAN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | CSRF VALIDATION
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


    $action = $_POST['action'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | Request Team
    |--------------------------------------------------------------------------
    */

    if ($action === 'request_team') {

        $smelterId = (int) ($_POST['smelter_id'] ?? 0);

        $teamId = (int) ($_POST['team_id'] ?? 0);

        $reason = trim($_POST['reason'] ?? '');


        try {

            /*
            |--------------------------------------------------------------------------
            | Validasi input dasar
            |--------------------------------------------------------------------------
            */

            if ($smelterId <= 0 || $teamId <= 0) {
                throw new Exception(
                    'Smelter dan Team wajib dipilih.'
                );
            }


            if ($reason === '') {
                throw new Exception(
                    'Alasan request wajib diisi.'
                );
            }


            if (mb_strlen($reason) > 1000) {
                throw new Exception(
                    'Alasan request maksimal 1000 karakter.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Transaction
            |--------------------------------------------------------------------------
            */

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Lock user
            |--------------------------------------------------------------------------
            |
            | Pastikan user masih active saat request diproses.
            |
            */

            $stmt = $pdo->prepare("
                SELECT
                    u.id,
                    u.division_id,
                    u.status,
                    r.name AS role_name
                FROM users u
                INNER JOIN roles r
                    ON r.id = u.role_id
                WHERE u.id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$userId]);

            $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$currentUser) {
                throw new Exception(
                    'User tidak ditemukan.'
                );
            }


            if ($currentUser['status'] !== 'active') {
                throw new Exception(
                    'Akun Anda tidak aktif.'
                );
            }


            if ($currentUser['role_name'] !== 'foreman') {
                throw new Exception(
                    'Anda tidak memiliki akses untuk melakukan request ini.'
                );
            }


            $divisionId = (int) $currentUser['division_id'];


            /*
            |--------------------------------------------------------------------------
            | Pastikan Smelter berada di divisi Foreman
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    division_id,
                    name,
                    status
                FROM smelters
                WHERE id = ?
                  AND division_id = ?
                  AND status = 'active'
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $smelterId,
                $divisionId
            ]);

            $smelter = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$smelter) {
                throw new Exception(
                    'Smelter tidak valid, tidak aktif, atau berbeda divisi.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan Team berada pada Smelter yang dipilih
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    smelter_id,
                    name,
                    status
                FROM teams
                WHERE id = ?
                  AND smelter_id = ?
                  AND status = 'active'
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $teamId,
                $smelterId
            ]);

            $team = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$team) {
                throw new Exception(
                    'Team tidak valid, tidak aktif, atau bukan milik Smelter tersebut.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan Team belum dimiliki
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    ua.id,
                    ua.status
                FROM user_access ua
                WHERE ua.user_id = ?
                  AND ua.smelter_id = ?
                  AND ua.team_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $userId,
                $smelterId,
                $teamId
            ]);

            $existingAccess = $stmt->fetch(PDO::FETCH_ASSOC);


            if (
                $existingAccess &&
                $existingAccess['status'] === 'active'
            ) {
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
                FOR UPDATE
            ");

            $stmt->execute([
                $userId,
                $smelterId,
                $teamId
            ]);

            $existingRequest = $stmt->fetchColumn();


            if ($existingRequest) {
                throw new Exception(
                    'Request Team tersebut masih menunggu approval.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Simpan Request
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
                    ?,
                    'additional_team',
                    'pending',
                    ?,
                    NOW()
                )
            ");

            $stmt->execute([
                $userId,
                $smelterId,
                $teamId,
                $reason
            ]);


            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $pdo->commit();


            /*
            |--------------------------------------------------------------------------
            | Redirect
            |--------------------------------------------------------------------------
            */

            header(
                'Location: access-requests?success=requested'
            );

            exit;


        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Rollback
            |--------------------------------------------------------------------------
            */

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }


            $error = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| Ambil semua Smelter dalam divisi Foreman
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
| Team yang sudah dimiliki Foreman
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ua.id,
        ua.smelter_id,
        ua.team_id,
        s.name AS smelter_name,
        t.name AS team_name
    FROM user_access ua

    INNER JOIN smelters s
        ON s.id = ua.smelter_id

    INNER JOIN teams t
        ON t.id = ua.team_id

    WHERE ua.user_id = ?
      AND ua.team_id IS NOT NULL
      AND ua.status = 'active'
      AND s.status = 'active'
      AND t.status = 'active'
      AND s.division_id = ?

    ORDER BY s.name, t.name
");

$stmt->execute([
    $userId,
    $divisionId
]);

$currentTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Riwayat Request Foreman
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        ar.id,
        ar.smelter_id,
        ar.team_id,
        s.name AS smelter_name,
        t.name AS team_name,
        ar.status,
        ar.requested_reason,
        ar.created_at,
        ar.rejected_reason
    FROM access_requests ar

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    INNER JOIN teams t
        ON t.id = ar.team_id

    WHERE ar.user_id = ?
      AND ar.request_type = 'additional_team'
      AND s.division_id = ?

    ORDER BY ar.created_at DESC
");

$stmt->execute([
    $userId,
    $divisionId
]);

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


<!-- =========================================================
     NAVBAR
========================================================== -->

<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <span class="navbar-brand">
            Request Team
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


<div class="container mt-4">


    <!-- =====================================================
         SUCCESS MESSAGE
    ====================================================== -->

    <?php if (isset($_GET['success'])): ?>

        <div class="alert alert-success">

            Request Team berhasil dikirim.

        </div>

    <?php endif; ?>


    <!-- =====================================================
         ERROR MESSAGE
    ====================================================== -->

    <?php if (!empty($error)): ?>

        <div class="alert alert-danger">

            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         TEAM SAAT INI
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Team yang Saya Miliki
            </strong>

        </div>


        <div class="card-body">

            <?php if (!$currentTeams): ?>

                <span class="text-muted">
                    Belum memiliki Team.
                </span>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered">

                        <thead class="table-light">

                            <tr>

                                <th>
                                    Smelter
                                </th>

                                <th>
                                    Team
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($currentTeams as $team): ?>

                                <tr>

                                    <td>

                                        <?= htmlspecialchars(
                                            $team['smelter_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $team['team_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
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
         REQUEST FORM
    ====================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                Request Team Tambahan
            </strong>

        </div>


        <div class="card-body">

            <form
                method="POST"
                autocomplete="off"
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


                <!-- ACTION -->

                <input
                    type="hidden"
                    name="action"
                    value="request_team"
                >


                <!-- DIVISI -->

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


                <!-- SMELTER -->

                <div class="mb-3">

                    <label
                        for="smelter_id"
                        class="form-label"
                    >
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
                                    $smelter['name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- TEAM -->

                <div class="mb-3">

                    <label
                        for="team_id"
                        class="form-label"
                    >
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


                <!-- ALASAN -->

                <div class="mb-3">

                    <label
                        for="reason"
                        class="form-label"
                    >
                        Alasan
                    </label>


                    <textarea
                        name="reason"
                        id="reason"
                        class="form-control"
                        rows="4"
                        maxlength="1000"
                        required
                    ></textarea>


                    <div class="form-text">
                        Maksimal 1000 karakter.
                    </div>

                </div>


                <!-- SUBMIT -->

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Kirim Request
                </button>

            </form>

        </div>

    </div>


    <!-- =====================================================
         HISTORY
    ====================================================== -->

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
                                colspan="6"
                                class="text-center text-muted"
                            >

                                Belum ada request.

                            </td>

                        </tr>

                    <?php else: ?>


                        <?php foreach ($requests as $request): ?>

                            <tr>


                                <!-- SMELTER -->

                                <td>

                                    <?= htmlspecialchars(
                                        $request['smelter_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>


                                <!-- TEAM -->

                                <td>

                                    <?= htmlspecialchars(
                                        $request['team_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <?php if (
                                        $request['status'] === 'pending'
                                    ): ?>

                                        <span class="badge bg-warning text-dark">
                                            Pending
                                        </span>


                                    <?php elseif (
                                        $request['status'] === 'approved'
                                    ): ?>

                                        <span class="badge bg-success">
                                            Approved
                                        </span>


                                    <?php elseif (
                                        $request['status'] === 'rejected'
                                    ): ?>

                                        <span class="badge bg-danger">
                                            Rejected
                                        </span>


                                    <?php elseif (
                                        $request['status'] === 'cancelled'
                                    ): ?>

                                        <span class="badge bg-secondary">
                                            Cancelled
                                        </span>


                                    <?php else: ?>

                                        <span class="badge bg-secondary">
                                            <?= htmlspecialchars(
                                                $request['status'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- ALASAN -->

                                <td>

                                    <?= htmlspecialchars(
                                        $request['requested_reason'] ?? '-',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>


                                <!-- TANGGAL -->

                                <td>

                                    <?= htmlspecialchars(
                                        $request['created_at'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>


                                <!-- KETERANGAN -->

                                <td>

                                    <?php if (
                                        $request['status'] === 'rejected'
                                        && !empty($request['rejected_reason'])
                                    ): ?>

                                        <?= htmlspecialchars(
                                            $request['rejected_reason'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    <?php else: ?>

                                        -

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


<script>

/*
|--------------------------------------------------------------------------
| Load Team berdasarkan Smelter
|--------------------------------------------------------------------------
*/

const smelterSelect =
    document.getElementById('smelter_id');

const teamSelect =
    document.getElementById('team_id');


smelterSelect.addEventListener(
    'change',
    function () {

        const smelterId = this.value;


        teamSelect.innerHTML =
            '<option value="">Loading...</option>';


        if (!smelterId) {

            teamSelect.innerHTML =
                '<option value="">-- Pilih Smelter terlebih dahulu --</option>';

            return;
        }


        fetch(
            '../api/get-teams?smelter_id='
            + encodeURIComponent(smelterId)
        )

        .then(response => {

            if (!response.ok) {
                throw new Error(
                    'HTTP ' + response.status
                );
            }

            return response.json();

        })


        .then(data => {

            teamSelect.innerHTML =
                '<option value="">-- Pilih Team --</option>';


            if (
                !data.success ||
                !Array.isArray(data.data)
            ) {

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