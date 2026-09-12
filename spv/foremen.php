<?php

require_once __DIR__ . '/../middleware/spv.php';
require_once __DIR__ . '/../config/database.php';

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
$error = '';

/*
|--------------------------------------------------------------------------
| AMBIL ROLE FOREMAN
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id
    FROM roles
    WHERE name = 'foreman'
    LIMIT 1
");
$stmt->execute();
$foremanRoleId = $stmt->fetchColumn();

if (!$foremanRoleId) {
    http_response_code(500);
    exit('Role Foreman tidak ditemukan.');
}

$foremanRoleId = (int) $foremanRoleId;

/*
|--------------------------------------------------------------------------
| AMBIL DIVISI SPV
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        u.division_id,
        d.name AS division_name
    FROM users u
    INNER JOIN divisions d
        ON d.id = u.division_id
    WHERE u.id = ?
      AND u.status = 'active'
      AND d.status = 'active'
    LIMIT 1
");
$stmt->execute([$userId]);
$spv = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$spv) {
    http_response_code(403);
    exit('Data SPV tidak ditemukan atau akun tidak aktif.');
}

$divisionId = (int) $spv['division_id'];
$divisionName = $spv['division_name'];

/*
|--------------------------------------------------------------------------
| POST PROCESSING
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | VALIDASI CSRF
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

    $action = trim($_POST['action'] ?? '');
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if ($requestId <= 0) {
        $error = 'Request tidak valid.';
    } else {

        try {
            $pdo->beginTransaction();

            /*
            |------------------------------------------------------------------
            | Ambil request + lock.
            | Scope SPV diverifikasi kembali di server.
            |------------------------------------------------------------------
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
                    u.division_id AS user_division_id,
                    u.role_id,
                    u.status AS user_status,

                    r.name AS role_name,

                    s.division_id AS smelter_division_id,
                    s.status AS smelter_status,

                    t.smelter_id AS team_smelter_id,
                    t.status AS team_status

                FROM access_requests ar

                INNER JOIN users u
                    ON u.id = ar.user_id

                INNER JOIN roles r
                    ON r.id = u.role_id

                INNER JOIN smelters s
                    ON s.id = ar.smelter_id

                LEFT JOIN teams t
                    ON t.id = ar.team_id

                WHERE ar.id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception('Request tidak ditemukan.');
            }

            /*
            |------------------------------------------------------------------
            | Request harus additional_team + pending
            |------------------------------------------------------------------
            */

            if ($request['request_type'] !== 'additional_team') {
                throw new Exception('Jenis request tidak valid.');
            }

            if ($request['status'] !== 'pending') {
                throw new Exception('Request ini sudah diproses.');
            }

            /*
            |------------------------------------------------------------------
            | Harus Foreman
            |------------------------------------------------------------------
            */

            if (
                $request['role_name'] !== 'foreman' ||
                (int) $request['role_id'] !== $foremanRoleId
            ) {
                throw new Exception('User yang diproses bukan Foreman.');
            }

            /*
            |------------------------------------------------------------------
            | Untuk approve, Foreman harus masih pending.
            | Untuk reject, user juga harus masih pending.
            |------------------------------------------------------------------
            */

            if ($request['user_status'] !== 'pending') {
                throw new Exception('Status Foreman sudah berubah.');
            }

            /*
            |------------------------------------------------------------------
            | Smelter harus aktif
            |------------------------------------------------------------------
            */

            if ($request['smelter_status'] !== 'active') {
                throw new Exception('Smelter tujuan tidak aktif.');
            }

            /*
            |------------------------------------------------------------------
            | Foreman, Smelter dan SPV harus satu divisi
            |------------------------------------------------------------------
            */

            if ((int) $request['user_division_id'] !== $divisionId) {
                throw new Exception('Foreman berasal dari divisi yang berbeda.');
            }

            if ((int) $request['smelter_division_id'] !== $divisionId) {
                throw new Exception('Request berada di luar divisi Anda.');
            }

            /*
            |------------------------------------------------------------------
            | LOCK USER, SMELTER, DAN TEAM
            |------------------------------------------------------------------
            | Data pada JOIN di atas adalah snapshot awal. Lock dan baca ulang
            | object penting di dalam transaksi agar approval tidak memakai
            | status/role/divisi yang sudah berubah karena request bersamaan.
            |------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    role_id,
                    status,
                    division_id
                FROM users
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$request['user_id']]);
            $lockedUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedUser) {
                throw new Exception('Data Foreman tidak ditemukan.');
            }

            if ((int) $lockedUser['role_id'] !== $foremanRoleId) {
                throw new Exception('User yang diproses bukan Foreman.');
            }

            if ($lockedUser['status'] !== 'pending') {
                throw new Exception('Status Foreman sudah berubah.');
            }

            if ((int) $lockedUser['division_id'] !== $divisionId) {
                throw new Exception('Foreman berasal dari divisi yang berbeda.');
            }

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    division_id,
                    status
                FROM smelters
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$request['smelter_id']]);
            $lockedSmelter = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedSmelter) {
                throw new Exception('Smelter tujuan tidak ditemukan.');
            }

            if ($lockedSmelter['status'] !== 'active') {
                throw new Exception('Smelter tujuan tidak aktif.');
            }

            if ((int) $lockedSmelter['division_id'] !== $divisionId) {
                throw new Exception('Request berada di luar divisi Anda.');
            }

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    smelter_id,
                    status
                FROM teams
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$request['team_id']]);
            $lockedTeam = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedTeam) {
                throw new Exception('Team Foreman tidak ditemukan.');
            }

            if ((int) $lockedTeam['smelter_id'] !== (int) $lockedSmelter['id']) {
                throw new Exception('Team tidak berada pada Smelter yang dipilih.');
            }

            if ($lockedTeam['status'] !== 'active') {
                throw new Exception('Team tujuan tidak aktif.');
            }

            /*
            |------------------------------------------------------------------
            | Team wajib valid dan milik Smelter request
            |------------------------------------------------------------------
            */

            /*
            |------------------------------------------------------------------
            | SPV harus benar-benar menguasai Smelter target.
            | team_id NULL = akses level Smelter untuk SPV.
            |------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM user_access
                WHERE user_id = ?
                  AND smelter_id = ?
                  AND team_id IS NULL
                  AND status = 'active'
                LIMIT 1
                FOR UPDATE
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
            |------------------------------------------------------------------
            | APPROVE
            |------------------------------------------------------------------
            */

            if ($action === 'approve') {

                /*
                |--------------------------------------------------------------
                | Cek user lagi dengan lock.
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT status
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([$request['user_id']]);
                $userStatus = $stmt->fetchColumn();

                if ($userStatus !== 'pending') {
                    throw new Exception('Status Foreman sudah berubah.');
                }

                /*
                |--------------------------------------------------------------
                | Cek user_access existing.
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        status
                    FROM user_access
                    WHERE user_id = ?
                      AND smelter_id = ?
                      AND team_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    $request['user_id'],
                    $request['smelter_id'],
                    $request['team_id']
                ]);

                $existingAccess = $stmt->fetch(PDO::FETCH_ASSOC);

                /*
                |--------------------------------------------------------------
                | Jika sudah aktif -> jangan insert duplikat.
                |--------------------------------------------------------------
                */

                if (
                    $existingAccess &&
                    $existingAccess['status'] === 'active'
                ) {
                    throw new Exception(
                        'Foreman sudah memiliki akses ke Team tersebut.'
                    );
                }

                /*
                |--------------------------------------------------------------
                | Jika revoked -> REACTIVATE.
                |--------------------------------------------------------------
                */

                if (
                    $existingAccess &&
                    $existingAccess['status'] === 'revoked'
                ) {

                    $stmt = $pdo->prepare("
                        UPDATE user_access
                        SET
                            status = 'active',
                            granted_by = ?,
                            granted_at = NOW(),
                            revoked_by = NULL,
                            revoked_at = NULL,
                            updated_at = NOW()
                        WHERE id = ?
                          AND status = 'revoked'
                    ");

                    $stmt->execute([
                        $userId,
                        $existingAccess['id']
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new Exception(
                            'Akses Foreman gagal diaktifkan kembali.'
                        );
                    }

                } elseif (!$existingAccess) {

                    /*
                    |----------------------------------------------------------
                    | Belum pernah ada -> INSERT satu kali.
                    |----------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        INSERT INTO user_access (
                            user_id,
                            smelter_id,
                            team_id,
                            status,
                            granted_by,
                            granted_at,
                            created_at,
                            updated_at
                        )
                        VALUES (?, ?, ?, 'active', ?, NOW(), NOW(), NOW())
                    ");

                    $stmt->execute([
                        $request['user_id'],
                        $request['smelter_id'],
                        $request['team_id'],
                        $userId
                    ]);
                }

                /*
                |--------------------------------------------------------------
                | Aktifkan Foreman.
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET
                        status = 'active',
                        approved_by = ?,
                        approved_at = NOW(),
                        rejected_reason = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                      AND status = 'pending'
                ");

                $stmt->execute([
                    $userId,
                    $request['user_id']
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new Exception('Foreman gagal diaktifkan.');
                }

                /*
                |--------------------------------------------------------------
                | Update request hanya jika masih pending.
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE access_requests
                    SET
                        status = 'approved',
                        approved_by = ?,
                        approved_at = NOW(),
                        rejected_reason = NULL
                    WHERE id = ?
                      AND status = 'pending'
                ");

                $stmt->execute([
                    $userId,
                    $requestId
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new Exception('Request gagal diperbarui.');
                }

                /*
                |--------------------------------------------------------------
                | Approval history
                |--------------------------------------------------------------
                */

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
            |------------------------------------------------------------------
            | REJECT
            |------------------------------------------------------------------
            */

            if ($action === 'reject') {

                $reason = trim($_POST['reason'] ?? '');

                if ($reason === '') {
                    throw new Exception('Alasan penolakan wajib diisi.');
                }

                if (mb_strlen($reason) > 1000) {
                    throw new Exception('Alasan penolakan terlalu panjang.');
                }

                /*
                |--------------------------------------------------------------
                | Tolak user hanya jika masih pending.
                |--------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET
                        status = 'rejected',
                        rejected_reason = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND status = 'pending'
                ");

                $stmt->execute([
                    $reason,
                    $request['user_id']
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new Exception('Foreman gagal ditolak karena status sudah berubah.');
                }

                /*
                |--------------------------------------------------------------
                | Update request hanya jika masih pending.
                |--------------------------------------------------------------
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
                    throw new Exception('Request gagal ditolak.');
                }

                /*
                |--------------------------------------------------------------
                | Rejection history
                |--------------------------------------------------------------
                */

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

            throw new Exception('Action tidak valid.');

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'SPV foremen error: ' . $e->getMessage()
            );

            $error = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL FOREMAN PENDING DALAM SCOPE SPV
|--------------------------------------------------------------------------
*/

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

    INNER JOIN users u
        ON u.id = ar.user_id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    INNER JOIN divisions d
        ON d.id = s.division_id

    INNER JOIN teams t
        ON t.id = ar.team_id

    WHERE ar.request_type = 'additional_team'
      AND ar.status = 'pending'
      AND u.role_id = ?
      AND u.status = 'pending'
      AND u.division_id = ?
      AND s.division_id = ?
      AND s.status = 'active'
      AND t.smelter_id = s.id
      AND t.status = 'active'

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

$stmt->execute([
    $foremanRoleId,
    $divisionId,
    $divisionId,
    $userId
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

<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <span class="navbar-brand">
            Approval Foreman
        </span>

        <div>
            <span class="text-white me-3">
                <?= htmlspecialchars($divisionName) ?>
            </span>

            <a
                href="dashboard"
                class="btn btn-outline-light btn-sm"
            >
                Dashboard
            </a>
        </div>

    </div>

</nav>


<div class="container-fluid mt-4 mb-5">

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

                <div class="alert alert-info mb-0">
                    Tidak ada permintaan Foreman yang menunggu approval.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

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

                                <th style="width: 180px;">
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
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>"
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
                                        data-user-name="<?= htmlspecialchars($foreman['name'], ENT_QUOTES) ?>"
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
    aria-hidden="true"
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
                        name="csrf_token"
                        value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>"
                    >

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

                    <p class="mb-2">
                        Anda akan menolak Foreman:
                    </p>

                    <strong id="reject_user_name"></strong>

                    <div class="mt-3">

                        <label class="form-label">
                            Alasan Penolakan
                        </label>

                        <textarea
                            name="reason"
                            class="form-control"
                            rows="4"
                            maxlength="1000"
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

if (rejectModal) {
    rejectModal.addEventListener('show.bs.modal', function (event) {

        const button = event.relatedTarget;

        if (!button) {
            return;
        }

        const requestId =
            button.getAttribute('data-request-id');

        const userName =
            button.getAttribute('data-user-name') || '';

        document.getElementById('reject_request_id').value =
            requestId || '';

        document.getElementById('reject_user_name').textContent =
            userName;
    });
}

</script>

</body>
</html>