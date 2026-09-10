<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/spv.php';

$userId = $_SESSION['user_id'];


// Ambil role ID Foreman
$stmt = $pdo->prepare("
    SELECT id
    FROM roles
    WHERE name = 'foreman'
    LIMIT 1
");

$stmt->execute();
$foremanRoleId = $stmt->fetchColumn();


// ============================================================
// APPROVE / REJECT
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if (!$requestId) {
        die('Request tidak valid.');
    }

    try {

        $pdo->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | Ambil request + pastikan request berada di Smelter yang dikuasai SPV
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
                u.nik,
                u.email,
                u.division_id,
                u.role_id,

                s.division_id AS smelter_division_id,
                t.smelter_id AS team_smelter_id

            FROM access_requests ar

            JOIN users u
                ON u.id = ar.user_id

            JOIN smelters s
                ON s.id = ar.smelter_id

            LEFT JOIN teams t
                ON t.id = ar.team_id

            JOIN user_access ua
                ON ua.smelter_id = ar.smelter_id
               AND ua.user_id = ?
               AND ua.team_id IS NULL
               AND ua.status = 'active'

            WHERE ar.id = ?
              AND ar.status = 'pending'

            LIMIT 1
        ");

        $stmt->execute([
            $userId,
            $requestId
        ]);

        $request = $stmt->fetch(PDO::FETCH_ASSOC);


        if (!$request) {
            throw new Exception(
                'Request tidak ditemukan atau bukan berada di bawah akses Anda.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Validasi Role
        |--------------------------------------------------------------------------
        */

        if ((int) $request['role_id'] !== (int) $foremanRoleId) {
            throw new Exception(
                'User yang diproses bukan Foreman.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Validasi Divisi
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT division_id
            FROM users
            WHERE id = ?
        ");

        $stmt->execute([$userId]);

        $spvDivisionId = $stmt->fetchColumn();


        if ((int) $spvDivisionId !== (int) $request['division_id']) {
            throw new Exception(
                'Foreman berasal dari divisi yang berbeda.'
            );
        }


        if (
            (int) $request['smelter_division_id']
            !==
            (int) $request['division_id']
        ) {
            throw new Exception(
                'Smelter tidak berada pada divisi Foreman.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Validasi Team
        |--------------------------------------------------------------------------
        */

        if (!$request['team_id']) {
            throw new Exception(
                'Team Foreman tidak ditemukan.'
            );
        }


        if (
            (int) $request['team_smelter_id']
            !==
            (int) $request['smelter_id']
        ) {
            throw new Exception(
                'Team tidak berada pada Smelter yang dipilih.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | APPROVE
        |--------------------------------------------------------------------------
        */

        if ($action === 'approve') {

            // Pastikan user masih pending
            $stmt = $pdo->prepare("
                SELECT status
                FROM users
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->execute([
                $request['user_id']
            ]);

            $userStatus = $stmt->fetchColumn();

            if ($userStatus !== 'pending') {
                throw new Exception(
                    'Status user sudah berubah.'
                );
            }


            // Aktifkan user
            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    status = 'active',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $userId,
                $request['user_id']
            ]);


            // Buat akses Foreman ke Team
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
                $request['user_id'],
                $request['smelter_id'],
                $request['team_id'],
                $userId
            ]);


            // Update request
            $stmt = $pdo->prepare("
                UPDATE access_requests
                SET
                    status = 'approved',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $userId,
                $requestId
            ]);


            // Approval history
            $stmt = $pdo->prepare("
                INSERT INTO user_approvals (
                    user_id,
                    approved_by,
                    action,
                    notes,
                    created_at
                )
                VALUES (?, ?, 'approved', ?, NOW())
            ");

            $stmt->execute([
                $request['user_id'],
                $userId,
                'Foreman disetujui oleh SPV.'
            ]);


            $pdo->commit();

            header('Location: foremen?success=approved');
            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | REJECT
        |--------------------------------------------------------------------------
        */

        if ($action === 'reject') {

            $reason = trim(
                $_POST['reason'] ?? ''
            );

            if ($reason === '') {
                throw new Exception(
                    'Alasan penolakan wajib diisi.'
                );
            }


            // User menjadi rejected
            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    status = 'rejected',
                    rejected_reason = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $reason,
                $request['user_id']
            ]);


            // Request ditolak
            $stmt = $pdo->prepare("
                UPDATE access_requests
                SET
                    status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW(),
                    rejected_reason = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $userId,
                $reason,
                $requestId
            ]);


            // Approval history
            $stmt = $pdo->prepare("
                INSERT INTO user_approvals (
                    user_id,
                    approved_by,
                    action,
                    notes,
                    created_at
                )
                VALUES (?, ?, 'rejected', ?, NOW())
            ");

            $stmt->execute([
                $request['user_id'],
                $userId,
                $reason
            ]);


            $pdo->commit();

            header('Location: foremen?success=rejected');
            exit;
        }


        throw new Exception(
            'Action tidak valid.'
        );


    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    }
}


// ============================================================
// AMBIL FOREMAN PENDING
// ============================================================

$stmt = $pdo->prepare("
    SELECT
        ar.id AS request_id,

        u.id AS user_id,
        u.nik,
        u.name,
        u.email,

        d.name AS division_name,

        s.id AS smelter_id,
        s.name AS smelter_name,

        t.id AS team_id,
        t.name AS team_name,

        ar.requested_reason,
        ar.created_at

    FROM access_requests ar

    JOIN users u
        ON u.id = ar.user_id

    JOIN smelters s
        ON s.id = ar.smelter_id

    JOIN divisions d
        ON d.id = s.division_id

    JOIN teams t
        ON t.id = ar.team_id

    JOIN user_access ua
        ON ua.smelter_id = ar.smelter_id
       AND ua.user_id = ?
       AND ua.team_id IS NULL
       AND ua.status = 'active'

    WHERE ar.request_type = 'additional_team'
      AND ar.status = 'pending'
      AND u.role_id = ?
      AND u.status = 'pending'

    ORDER BY ar.created_at ASC
");

$stmt->execute([
    $userId,
    $foremanRoleId
]);

$foremen = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <title>Approval Foreman</title>

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
            Approval Foreman
        </span>

        <a
            href="dashboard"
            class="btn btn-outline-light btn-sm"
        >
            Dashboard
        </a>

    </div>

</nav>


<div class="container-fluid mt-4">


    <?php if (isset($_GET['success'])): ?>

        <?php if ($_GET['success'] === 'approved'): ?>

            <div class="alert alert-success">
                Foreman berhasil disetujui.
            </div>

        <?php elseif ($_GET['success'] === 'rejected'): ?>

            <div class="alert alert-success">
                Foreman berhasil ditolak.
            </div>

        <?php endif; ?>

    <?php endif; ?>


    <?php if (!empty($error)): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                Permintaan Foreman
            </strong>

        </div>


        <div class="card-body">

            <?php if (!$foremen): ?>

                <div class="alert alert-info">
                    Tidak ada permintaan Foreman yang menunggu approval.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover">

                        <thead class="table-light">

                            <tr>

                                <th>NIK</th>

                                <th>Nama</th>

                                <th>Email</th>

                                <th>Divisi</th>

                                <th>Smelter</th>

                                <th>Team</th>

                                <th>Alasan</th>

                                <th>Tanggal</th>

                                <th width="180">
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($foremen as $foreman): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($foreman['nik']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($foreman['name']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($foreman['email']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($foreman['division_name']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($foreman['smelter_name']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($foreman['team_name']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $foreman['requested_reason'] ?? '-'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($foreman['created_at']) ?>
                                </td>

                                <td>

                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="return confirm('Setujui Foreman ini?')"
                                    >

                                        <input
                                            type="hidden"
                                            name="request_id"
                                            value="<?= (int) $foreman['request_id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="approve"
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
                                        data-bs-target="#rejectModal"
                                        data-request-id="<?= (int) $foreman['request_id'] ?>"
                                    >
                                        Reject
                                    </button>

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


<!-- REJECT MODAL -->

<div
    class="modal fade"
    id="rejectModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">
                        Tolak Foreman
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
                        name="request_id"
                        id="reject_request_id"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="reject"
                    >


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
                        Tolak
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

const rejectModal = document.getElementById('rejectModal');

rejectModal.addEventListener('show.bs.modal', function (event) {

    const button = event.relatedTarget;

    const requestId =
        button.getAttribute('data-request-id');

    document.getElementById('reject_request_id').value =
        requestId;

});

</script>

</body>
</html>