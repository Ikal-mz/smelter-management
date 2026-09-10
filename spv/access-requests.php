<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/spv.php';

$userId = $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Ambil divisi SPV
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
    die('Divisi SPV tidak ditemukan.');
}


/*
|--------------------------------------------------------------------------
| REQUEST TAMBAHAN SMELTER
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

        /*
    |--------------------------------------------------------------------------
    | APPROVAL REQUEST TEAM TAMBAHAN FOREMAN
    |--------------------------------------------------------------------------
    */

    if ($action === 'approve_team') {

        $requestId = (int) ($_POST['request_id'] ?? 0);

        try {

            if (!$requestId) {
                throw new Exception('Request tidak valid.');
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
                    u.division_id AS user_division_id,
                    u.status AS user_status,
                    r.name AS role_name,
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

            $stmt->execute([$requestId]);

            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception('Request tidak ditemukan.');
            }

            /*
            |--------------------------------------------------------------------------
            | Validasi request
            |--------------------------------------------------------------------------
            */

            if ($request['request_type'] !== 'additional_team') {
                throw new Exception('Jenis request tidak valid.');
            }

            if ($request['status'] !== 'pending') {
                throw new Exception('Request ini sudah diproses.');
            }

            if ($request['role_name'] !== 'foreman') {
                throw new Exception('Request hanya dapat diajukan oleh Foreman.');
            }

            if ($request['user_status'] !== 'active') {
                throw new Exception('Akun Foreman tidak aktif.');
            }

            /*
            |--------------------------------------------------------------------------
            | Pastikan Foreman dan Smelter berada di divisi yang sama
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
            | Pastikan SPV memang menguasai Smelter tersebut
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT ua.id
                FROM user_access ua
                WHERE ua.user_id = ?
                  AND ua.smelter_id = ?
                  AND ua.team_id IS NULL
                  AND ua.status = 'active'
                LIMIT 1
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
            | Pastikan Team benar-benar milik Smelter
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
            | Cek apakah Foreman sudah memiliki Team tersebut
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
                VALUES (?, ?, ?, 'active', ?, NOW(), NOW())
            ");

            $stmt->execute([
                $request['user_id'],
                $request['smelter_id'],
                $request['team_id'],
                $userId
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update request menjadi approved
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

            header(
                'Location: access-requests?success=team_approved'
            );
            exit;

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REJECT REQUEST TEAM TAMBAHAN
    |--------------------------------------------------------------------------
    */

    if ($action === 'reject_team') {

        $requestId = (int) ($_POST['request_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        try {

            if (!$requestId) {
                throw new Exception('Request tidak valid.');
            }

            if ($reason === '') {
                throw new Exception(
                    'Alasan penolakan wajib diisi.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Pastikan request memang milik Smelter SPV
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    ar.id
                FROM access_requests ar
                WHERE ar.id = ?
                  AND ar.request_type = 'additional_team'
                  AND ar.status = 'pending'
                  AND EXISTS (
                      SELECT 1
                      FROM user_access ua
                      WHERE ua.user_id = ?
                        AND ua.smelter_id = ar.smelter_id
                        AND ua.team_id IS NULL
                        AND ua.status = 'active'
                  )
                LIMIT 1
            ");

            $stmt->execute([
                $requestId,
                $userId
            ]);

            if (!$stmt->fetchColumn()) {
                throw new Exception(
                    'Anda tidak memiliki kewenangan untuk request tersebut.'
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
                $userId,
                $reason,
                $requestId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new Exception(
                    'Request gagal ditolak.'
                );
            }

            header(
                'Location: access-requests?success=team_rejected'
            );
            exit;

        } catch (Exception $e) {

            $error = $e->getMessage();
        }
    }

    if ($action === 'request_smelter') {

        $smelterId = (int) ($_POST['smelter_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        try {

            if (!$smelterId) {
                throw new Exception('Smelter wajib dipilih.');
            }

            if ($reason === '') {
                throw new Exception('Alasan permintaan wajib diisi.');
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan Smelter berada pada divisi SPV
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
                    'Smelter tidak valid atau berada di divisi lain.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Pastikan belum memiliki akses
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
            | Pastikan tidak ada request pending
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
                VALUES (?, ?, NULL, 'additional_smelter', 'pending', ?, NOW())
            ");

            $stmt->execute([
                $userId,
                $smelterId,
                $reason
            ]);


            header('Location: access-requests?success=requested');
            exit;


        } catch (Exception $e) {

            $error = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| Smelter yang sudah dimiliki
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT s.id, s.name
    FROM user_access ua
    JOIN smelters s
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
| Smelter yang tersedia untuk diminta
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
| Request milik SPV
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

    JOIN smelters s
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
*/

$stmt = $pdo->prepare("
    SELECT
        ar.id,
        ar.user_id,
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

    <title>Request Smelter</title>

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
            Request Smelter
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


    <?php if (isset($_GET['success'])): ?>

        <div class="alert alert-success">

            <?php if ($_GET['success'] === 'requested'): ?>

                Permintaan Smelter berhasil dikirim ke Admin.

            <?php elseif ($_GET['success'] === 'team_approved'): ?>

                Request Team Foreman berhasil disetujui.

            <?php elseif ($_GET['success'] === 'team_rejected'): ?>

                Request Team Foreman berhasil ditolak.

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <?php if (!empty($error)): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <!-- SMELTER SAAT INI -->

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

                    <span class="badge bg-primary me-2">
                        <?= htmlspecialchars($smelter['name']) ?>
                    </span>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>

    <!-- APPROVAL TEAM FOREMAN -->

<div class="card shadow-sm mb-4">

    <div class="card-header">
        <strong>Approval Team Tambahan Foreman</strong>
    </div>

    <div class="card-body">

        <?php if (!$teamRequests): ?>

            <div class="alert alert-info mb-0">
                Tidak ada request Team tambahan yang menunggu approval.
            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table table-bordered table-hover">

                    <thead class="table-light">

                        <tr>
                            <th>Foreman</th>
                            <th>NIK</th>
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

                                        <!-- APPROVE -->

                                        <form method="POST">

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
                                                onclick="return confirm('Setujui request Team ini?')"
                                            >
                                                Approve
                                            </button>

                                        </form>


                                        <!-- REJECT -->

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#rejectModal<?= (int) $request['id'] ?>"
                                        >
                                            Reject
                                        </button>

                                    </div>


                                    <!-- REJECT MODAL -->

                                    <div
                                        class="modal fade"
                                        id="rejectModal<?= (int) $request['id'] ?>"
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

    <!-- FORM REQUEST -->

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
                        name="action"
                        value="request_smelter"
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
                                    <?= htmlspecialchars($smelter['name']) ?>
                                </option>

                            <?php endforeach; ?>

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

            <?php endif; ?>

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
                                colspan="5"
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>