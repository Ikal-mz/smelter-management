<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/admin.php';

$adminId = (int) $_SESSION['user_id'];

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
$success = '';

/*
|--------------------------------------------------------------------------
| PROCESS REQUEST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | VALIDATE CSRF
    |--------------------------------------------------------------------------
    */

    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token'])
        || empty($token)
        || !is_string($token)
        || !hash_equals($_SESSION['csrf_token'], $token)
    ) {

        $error = 'Request tidak valid. Silakan muat ulang halaman.';

    } else {

        $action = $_POST['action'] ?? '';
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if (!$requestId) {

            $error = 'Request tidak valid.';

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | APPROVE ADDITIONAL SMELTER
                |--------------------------------------------------------------------------
                */

                if ($action === 'approve_smelter') {

                    $pdo->beginTransaction();

                    /*
                    |--------------------------------------------------------------------------
                    | Ambil request + user + smelter
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

                            u.nik,
                            u.name AS user_name,
                            u.status AS user_status,
                            u.division_id AS user_division_id,

                            r.name AS role_name,

                            s.name AS smelter_name,
                            s.division_id AS smelter_division_id,
                            s.status AS smelter_status

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

                    $stmt->execute([
                        $requestId
                    ]);

                    $request = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$request) {
                        throw new Exception(
                            'Request tidak ditemukan.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Validasi request
                    |--------------------------------------------------------------------------
                    */

                    if ($request['request_type'] !== 'additional_smelter') {
                        throw new Exception(
                            'Request bukan permintaan Smelter tambahan.'
                        );
                    }

                    if ($request['status'] !== 'pending') {
                        throw new Exception(
                            'Request sudah diproses.'
                        );
                    }

                    if ($request['role_name'] !== 'spv') {
                        throw new Exception(
                            'Hanya SPV yang dapat memiliki akses Smelter.'
                        );
                    }

                    if ($request['user_status'] !== 'active') {
                        throw new Exception(
                            'Akun SPV belum aktif.'
                        );
                    }

                    if ($request['smelter_status'] !== 'active') {
                        throw new Exception(
                            'Smelter tidak aktif.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Pastikan divisi SPV sama dengan divisi Smelter
                    |--------------------------------------------------------------------------
                    */

                    if (
                        (int) $request['user_division_id']
                        !==
                        (int) $request['smelter_division_id']
                    ) {

                        throw new Exception(
                            'Smelter berada di divisi yang berbeda dengan SPV.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Cek apakah SPV sudah memiliki akses
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
                        $request['user_id'],
                        $request['smelter_id']
                    ]);

                    if ($stmt->fetchColumn()) {

                        throw new Exception(
                            'SPV sudah memiliki akses ke Smelter tersebut.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Berikan akses Smelter
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
                        VALUES (
                            ?,
                            ?,
                            NULL,
                            'active',
                            ?,
                            NOW(),
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        $request['user_id'],
                        $request['smelter_id'],
                        $adminId
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Update request
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
                        $adminId,
                        $requestId
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Approval history
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        INSERT INTO user_approvals (
                            user_id,
                            approved_by,
                            action,
                            notes,
                            created_at
                        )
                        VALUES (
                            ?,
                            ?,
                            'approved',
                            ?,
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        $request['user_id'],
                        $adminId,
                        'Admin menyetujui request Smelter tambahan: '
                        . $request['smelter_name']
                    ]);

                    $pdo->commit();

                    $success =
                        'Request Smelter tambahan berhasil disetujui.';
                }


                /*
                |--------------------------------------------------------------------------
                | REJECT ADDITIONAL SMELTER
                |--------------------------------------------------------------------------
                */

                elseif ($action === 'reject_smelter') {

                    if ($reason === '') {
                        throw new Exception(
                            'Alasan penolakan wajib diisi.'
                        );
                    }

                    if (mb_strlen($reason) > 1000) {
                        throw new Exception(
                            'Alasan penolakan maksimal 1000 karakter.'
                        );
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
                            ar.request_type,
                            ar.status,

                            u.name AS user_name,
                            u.status AS user_status,

                            r.name AS role_name,

                            s.name AS smelter_name,
                            s.division_id AS smelter_division_id,
                            s.status AS smelter_status

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

                    $stmt->execute([
                        $requestId
                    ]);

                    $request = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$request) {
                        throw new Exception(
                            'Request tidak ditemukan.'
                        );
                    }

                    if ($request['request_type'] !== 'additional_smelter') {
                        throw new Exception(
                            'Request bukan permintaan Smelter tambahan.'
                        );
                    }

                    if ($request['status'] !== 'pending') {
                        throw new Exception(
                            'Request sudah diproses.'
                        );
                    }

                    if ($request['role_name'] !== 'spv') {
                        throw new Exception(
                            'Request ini bukan milik SPV.'
                        );
                    }

                    if ($request['user_status'] !== 'active') {
                        throw new Exception(
                            'Akun SPV tidak aktif.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Reject request
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
                        $adminId,
                        $reason,
                        $requestId
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new Exception(
                            'Request sudah diproses oleh proses lain.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Approval history
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        INSERT INTO user_approvals (
                            user_id,
                            approved_by,
                            action,
                            notes,
                            created_at
                        )
                        VALUES (
                            ?,
                            ?,
                            'rejected',
                            ?,
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        $request['user_id'],
                        $adminId,
                        'Admin menolak request Smelter tambahan: '
                        . $request['smelter_name']
                        . '. Alasan: '
                        . $reason
                    ]);

                    $pdo->commit();

                    $success =
                        'Request Smelter tambahan berhasil ditolak.';
                }


                /*
                |--------------------------------------------------------------------------
                | APPROVE ADDITIONAL TEAM
                |--------------------------------------------------------------------------
                */

                elseif ($action === 'approve_team') {

                    $pdo->beginTransaction();

                    /*
                    |--------------------------------------------------------------------------
                    | Ambil request + user + smelter + team
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

                            u.nik,
                            u.name AS user_name,
                            u.status AS user_status,
                            u.division_id AS user_division_id,

                            r.name AS role_name,

                            s.name AS smelter_name,
                            s.division_id AS smelter_division_id,
                            s.status AS smelter_status,

                            t.name AS team_name,
                            t.smelter_id AS team_smelter_id,
                            t.status AS team_status

                        FROM access_requests ar

                        INNER JOIN users u
                            ON u.id = ar.user_id

                        INNER JOIN roles r
                            ON r.id = u.role_id

                        INNER JOIN smelters s
                            ON s.id = ar.smelter_id

                        INNER JOIN teams t
                            ON t.id = ar.team_id

                        WHERE ar.id = ?

                        LIMIT 1
                    ");

                    $stmt->execute([
                        $requestId
                    ]);

                    $request = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$request) {
                        throw new Exception(
                            'Request tidak ditemukan.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Validasi request
                    |--------------------------------------------------------------------------
                    */

                    if ($request['request_type'] !== 'additional_team') {
                        throw new Exception(
                            'Request bukan permintaan Team tambahan.'
                        );
                    }

                    if ($request['status'] !== 'pending') {
                        throw new Exception(
                            'Request sudah diproses.'
                        );
                    }

                    if ($request['role_name'] !== 'foreman') {
                        throw new Exception(
                            'Hanya Foreman yang dapat memiliki akses Team.'
                        );
                    }

                    if ($request['user_status'] !== 'active') {
                        throw new Exception(
                            'Akun Foreman tidak aktif.'
                        );
                    }

                    if ($request['smelter_status'] !== 'active') {
                        throw new Exception(
                            'Smelter tidak aktif.'
                        );
                    }

                    if ($request['team_status'] !== 'active') {
                        throw new Exception(
                            'Team tidak aktif.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Pastikan Team benar-benar berada di Smelter request
                    |--------------------------------------------------------------------------
                    */

                    if (
                        (int) $request['team_smelter_id']
                        !==
                        (int) $request['smelter_id']
                    ) {

                        throw new Exception(
                            'Team tidak berada di Smelter yang sesuai.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Pastikan divisi Foreman sama dengan Smelter
                    |--------------------------------------------------------------------------
                    */

                    if (
                        (int) $request['user_division_id']
                        !==
                        (int) $request['smelter_division_id']
                    ) {

                        throw new Exception(
                            'Team berada di divisi yang berbeda dengan Foreman.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Pastikan tidak ada SPV aktif yang mengontrol Smelter
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT ua.id
                        FROM user_access ua

                        INNER JOIN users u
                            ON u.id = ua.user_id

                        INNER JOIN roles r
                            ON r.id = u.role_id

                        WHERE ua.smelter_id = ?
                          AND ua.team_id IS NULL
                          AND ua.status = 'active'
                          AND u.status = 'active'
                          AND r.name = 'spv'

                        LIMIT 1
                    ");

                    $stmt->execute([
                        $request['smelter_id']
                    ]);

                    if ($stmt->fetchColumn()) {

                        throw new Exception(
                            'Smelter ini sudah dikontrol oleh SPV. '
                            . 'Request harus diproses oleh SPV tersebut.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Cek apakah Foreman sudah memiliki akses Team
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
                        VALUES (
                            ?,
                            ?,
                            ?,
                            'active',
                            ?,
                            NOW(),
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        $request['user_id'],
                        $request['smelter_id'],
                        $request['team_id'],
                        $adminId
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Update request
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
                        $adminId,
                        $requestId
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Approval history
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        INSERT INTO user_approvals (
                            user_id,
                            approved_by,
                            action,
                            notes,
                            created_at
                        )
                        VALUES (
                            ?,
                            ?,
                            'approved',
                            ?,
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        $request['user_id'],
                        $adminId,
                        'Admin menyetujui request Team tambahan: '
                        . $request['team_name']
                        . ' - '
                        . $request['smelter_name']
                    ]);

                    $pdo->commit();

                    $success =
                        'Request Team tambahan berhasil disetujui.';
                }


                /*
                |--------------------------------------------------------------------------
                | REJECT ADDITIONAL TEAM
                |--------------------------------------------------------------------------
                */

                elseif ($action === 'reject_team') {

                    if ($reason === '') {
                        throw new Exception(
                            'Alasan penolakan wajib diisi.'
                        );
                    }

                    if (mb_strlen($reason) > 1000) {
                        throw new Exception(
                            'Alasan penolakan maksimal 1000 karakter.'
                        );
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

                            u.name AS user_name,
                            u.status AS user_status,

                            r.name AS role_name,

                            s.name AS smelter_name,
                            s.status AS smelter_status,

                            t.name AS team_name,
                            t.status AS team_status,
                            t.smelter_id AS team_smelter_id

                        FROM access_requests ar

                        INNER JOIN users u
                            ON u.id = ar.user_id

                        INNER JOIN roles r
                            ON r.id = u.role_id

                        INNER JOIN smelters s
                            ON s.id = ar.smelter_id

                        INNER JOIN teams t
                            ON t.id = ar.team_id

                        WHERE ar.id = ?

                        LIMIT 1
                    ");

                    $stmt->execute([
                        $requestId
                    ]);

                    $request = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$request) {
                        throw new Exception(
                            'Request tidak ditemukan.'
                        );
                    }

                    if ($request['request_type'] !== 'additional_team') {
                        throw new Exception(
                            'Request bukan permintaan Team tambahan.'
                        );
                    }

                    if ($request['status'] !== 'pending') {
                        throw new Exception(
                            'Request sudah diproses.'
                        );
                    }

                    if ($request['role_name'] !== 'foreman') {
                        throw new Exception(
                            'Request ini bukan milik Foreman.'
                        );
                    }

                    if ($request['user_status'] !== 'active') {
                        throw new Exception(
                            'Akun Foreman tidak aktif.'
                        );
                    }

                    if ($request['smelter_status'] !== 'active') {
                        throw new Exception(
                            'Smelter tidak aktif.'
                        );
                    }

                    if ($request['team_status'] !== 'active') {
                        throw new Exception(
                            'Team tidak aktif.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Pastikan Team sesuai dengan Smelter
                    |--------------------------------------------------------------------------
                    */

                    if (
                        (int) $request['team_smelter_id']
                        !==
                        (int) $request['smelter_id']
                    ) {

                        throw new Exception(
                            'Team tidak berada di Smelter yang sesuai.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Pastikan tidak ada SPV aktif yang mengontrol Smelter
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT ua.id
                        FROM user_access ua

                        INNER JOIN users u
                            ON u.id = ua.user_id

                        INNER JOIN roles r
                            ON r.id = u.role_id

                        WHERE ua.smelter_id = ?
                          AND ua.team_id IS NULL
                          AND ua.status = 'active'
                          AND u.status = 'active'
                          AND r.name = 'spv'

                        LIMIT 1
                    ");

                    $stmt->execute([
                        $request['smelter_id']
                    ]);

                    if ($stmt->fetchColumn()) {

                        throw new Exception(
                            'Smelter ini sudah dikontrol oleh SPV. '
                            . 'Request harus diproses oleh SPV tersebut.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Reject request
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
                        $adminId,
                        $reason,
                        $requestId
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new Exception(
                            'Request sudah diproses oleh proses lain.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Approval history
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        INSERT INTO user_approvals (
                            user_id,
                            approved_by,
                            action,
                            notes,
                            created_at
                        )
                        VALUES (
                            ?,
                            ?,
                            'rejected',
                            ?,
                            NOW()
                        )
                    ");

                    $stmt->execute([
                        $request['user_id'],
                        $adminId,
                        'Admin menolak request Team tambahan: '
                        . $request['team_name']
                        . ' - '
                        . $request['smelter_name']
                        . '. Alasan: '
                        . $reason
                    ]);

                    $pdo->commit();

                    $success =
                        'Request Team tambahan berhasil ditolak.';
                }


                /*
                |--------------------------------------------------------------------------
                | ACTION TIDAK DIKENAL
                |--------------------------------------------------------------------------
                */

                else {

                    throw new Exception(
                        'Action tidak valid.'
                    );
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $e->getMessage();
            }
        }
    }

    }



/*
|--------------------------------------------------------------------------
| LOAD PENDING SMELTER REQUESTS
|--------------------------------------------------------------------------
*/

$pendingSmelterRequests = [];

$stmt = $pdo->prepare("
    SELECT
        ar.id,
        ar.user_id,
        ar.smelter_id,
        ar.request_type,
        ar.status,
        ar.requested_reason,
        ar.created_at,

        u.nik,
        u.name AS user_name,
        u.email,

        d.name AS division_name,

        s.name AS smelter_name

    FROM access_requests ar

    INNER JOIN users u
        ON u.id = ar.user_id

    INNER JOIN roles r
        ON r.id = u.role_id

    INNER JOIN smelters s
        ON s.id = ar.smelter_id

    INNER JOIN divisions d
        ON d.id = s.division_id

    WHERE ar.request_type = 'additional_smelter'
      AND ar.status = 'pending'
      AND u.status = 'active'
      AND r.name = 'spv'
      AND s.status = 'active'

    ORDER BY ar.created_at ASC
");

$stmt->execute();

$pendingSmelterRequests =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| LOAD PENDING TEAM REQUESTS
|--------------------------------------------------------------------------
*/

$pendingTeamRequests = [];

$stmt = $pdo->prepare("
    SELECT
        ar.id,
        ar.user_id,
        ar.smelter_id,
        ar.team_id,
        ar.request_type,
        ar.status,
        ar.requested_reason,
        ar.created_at,

        u.nik,
        u.name AS user_name,
        u.email,

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

    INNER JOIN teams t
        ON t.id = ar.team_id
       AND t.smelter_id = ar.smelter_id

    WHERE ar.request_type = 'additional_team'
      AND ar.status = 'pending'
      AND u.status = 'active'
      AND r.name = 'foreman'
      AND s.status = 'active'
      AND t.status = 'active'

      AND NOT EXISTS (
          SELECT 1
          FROM user_access spv_access

          INNER JOIN users spv_user
              ON spv_user.id = spv_access.user_id

          INNER JOIN roles spv_role
              ON spv_role.id = spv_user.role_id

          WHERE spv_access.smelter_id = ar.smelter_id
            AND spv_access.team_id IS NULL
            AND spv_access.status = 'active'
            AND spv_user.status = 'active'
            AND spv_role.name = 'spv'
      )

    ORDER BY ar.created_at ASC
");

$stmt->execute();

$pendingTeamRequests =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        Access Requests - Admin
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="mb-1">
                Access Requests
            </h2>

            <p class="text-muted mb-0">
                Kelola permintaan akses tambahan.
            </p>

        </div>

        <a
            href="dashboard"
            class="btn btn-outline-secondary"
        >
            Kembali
        </a>

    </div>


    <?php if ($error !== ''): ?>

        <div
            class="alert alert-danger"
            role="alert"
        >
            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

    <?php endif; ?>


    <?php if ($success !== ''): ?>

        <div
            class="alert alert-success"
            role="alert"
        >
            <?= htmlspecialchars(
                $success,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

    <?php endif; ?>


    <!--
    |--------------------------------------------------------------------------
    | SMELTER REQUESTS
    |--------------------------------------------------------------------------
    -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <div class="d-flex justify-content-between">

                <strong>
                    Request Smelter Tambahan
                </strong>

                <span class="badge bg-primary">
                    <?= count($pendingSmelterRequests) ?>
                </span>

            </div>

        </div>

        <div class="card-body">

            <?php if (empty($pendingSmelterRequests)): ?>

                <div class="alert alert-light border mb-0">
                    Tidak ada request Smelter tambahan.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

                        <thead>

                        <tr>

                            <th>
                                NIK
                            </th>

                            <th>
                                Nama
                            </th>

                            <th>
                                Email
                            </th>

                            <th>
                                Divisi
                            </th>

                            <th>
                                Smelter
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

                        <?php foreach ($pendingSmelterRequests as $request): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['nik'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['user_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['email'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['division_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['smelter_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['requested_reason'] ?? '-',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['created_at'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>

                                    <div class="d-flex gap-1">

                                        <form
                                            method="POST"
                                            onsubmit="return confirm(
                                                'Setujui akses Smelter ini?'
                                            );"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve_smelter"
                                            >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars(
                                                    $csrfToken,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int) $request['id'] ?>"
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
                                            data-bs-target="#rejectSmelterModal"
                                            data-request-id="<?= (int) $request['id'] ?>"
                                        >
                                            Reject
                                        </button>

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


    <!--
    |--------------------------------------------------------------------------
    | TEAM REQUESTS
    |--------------------------------------------------------------------------
    -->

    <div class="card shadow-sm">

        <div class="card-header">

            <div class="d-flex justify-content-between">

                <strong>
                    Request Team Tambahan
                </strong>

                <span class="badge bg-primary">
                    <?= count($pendingTeamRequests) ?>
                </span>

            </div>

        </div>

        <div class="card-body">

            <?php if (empty($pendingTeamRequests)): ?>

                <div class="alert alert-light border mb-0">
                    Tidak ada request Team tambahan
                    yang perlu diproses Admin.
                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle">

                        <thead>

                        <tr>

                            <th>
                                NIK
                            </th>

                            <th>
                                Nama
                            </th>

                            <th>
                                Email
                            </th>

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

                        <?php foreach ($pendingTeamRequests as $request): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['nik'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['user_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['email'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['division_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['smelter_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= htmlspecialchars(
                                            $request['team_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['requested_reason'] ?? '-',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $request['created_at'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>

                                    <div class="d-flex gap-1">

                                        <form
                                            method="POST"
                                            onsubmit="return confirm(
                                                'Setujui akses Team ini?'
                                            );"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve_team"
                                            >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars(
                                                    $csrfToken,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int) $request['id'] ?>"
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
                                            data-bs-target="#rejectTeamModal"
                                            data-request-id="<?= (int) $request['id'] ?>"
                                        >
                                            Reject
                                        </button>

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

</div>


<!--
|--------------------------------------------------------------------------
| MODAL REJECT SMELTER
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="rejectSmelterModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">
                        Tolak Request Smelter
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
                        value="reject_smelter"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $csrfToken,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="request_id"
                        id="rejectSmelterRequestId"
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


<!--
|--------------------------------------------------------------------------
| MODAL REJECT TEAM
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="rejectTeamModal"
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
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $csrfToken,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="request_id"
                        id="rejectTeamRequestId"
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


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

const rejectSmelterModal =
    document.getElementById('rejectSmelterModal');

if (rejectSmelterModal) {

    rejectSmelterModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button = event.relatedTarget;

            const requestId =
                button.getAttribute(
                    'data-request-id'
                );

            document.getElementById(
                'rejectSmelterRequestId'
            ).value = requestId;

        }
    );
}


const rejectTeamModal =
    document.getElementById('rejectTeamModal');

if (rejectTeamModal) {

    rejectTeamModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button = event.relatedTarget;

            const requestId =
                button.getAttribute(
                    'data-request-id'
                );

            document.getElementById(
                'rejectTeamRequestId'
            ).value = requestId;

        }
    );
}

</script>


</body>

</html>