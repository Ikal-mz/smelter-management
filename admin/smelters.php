<?php

require_once __DIR__ . '/../middleware/admin.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Koneksi database tidak tersedia.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $type, string $message): void
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_message'] = $message;

    header('Location: smelters');
    exit;
}

$flashType = $_SESSION['flash_type'] ?? '';
$flashMessage = $_SESSION['flash_message'] ?? '';

unset($_SESSION['flash_type'], $_SESSION['flash_message']);

/*
|--------------------------------------------------------------------------
| PROCESS ACTION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $token)) {
        redirectWithMessage('danger', 'Token keamanan tidak valid.');
    }

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | ADD SMELTER
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        $divisionId = (int) ($_POST['division_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($divisionId <= 0) {
            redirectWithMessage('danger', 'Divisi wajib dipilih.');
        }

        if ($name === '') {
            redirectWithMessage('danger', 'Nama Smelter wajib diisi.');
        }

        if (mb_strlen($name) > 100) {
            redirectWithMessage('danger', 'Nama Smelter maksimal 100 karakter.');
        }

        // Pastikan divisi ada dan aktif
        $stmt = $pdo->prepare("
            SELECT id, name
            FROM divisions
            WHERE id = ?
              AND status = 'active'
            LIMIT 1
        ");

        $stmt->execute([$divisionId]);

        $division = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$division) {
            redirectWithMessage(
                'danger',
                'Divisi tidak ditemukan atau sedang nonaktif.'
            );
        }

        // Cek nama smelter pada divisi yang sama
        $stmt = $pdo->prepare("
            SELECT id
            FROM smelters
            WHERE division_id = ?
              AND LOWER(name) = LOWER(?)
            LIMIT 1
        ");

        $stmt->execute([$divisionId, $name]);

        if ($stmt->fetch()) {
            redirectWithMessage(
                'warning',
                'Smelter dengan nama tersebut sudah ada pada divisi ini.'
            );
        }

        try {

            $stmt = $pdo->prepare("
                INSERT INTO smelters
                    (division_id, name, status, created_at, updated_at)
                VALUES
                    (?, ?, 'active', NOW(), NOW())
            ");

            $stmt->execute([
                $divisionId,
                $name
            ]);

            redirectWithMessage(
                'success',
                'Smelter berhasil ditambahkan.'
            );

        } catch (PDOException $e) {

            redirectWithMessage(
                'danger',
                'Gagal menambahkan Smelter. ' . $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT SMELTER
    |--------------------------------------------------------------------------
    */

    if ($action === 'edit') {

        $id = (int) ($_POST['id'] ?? 0);
        $divisionId = (int) ($_POST['division_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($id <= 0) {
            redirectWithMessage('danger', 'ID Smelter tidak valid.');
        }

        if ($divisionId <= 0) {
            redirectWithMessage('danger', 'Divisi wajib dipilih.');
        }

        if ($name === '') {
            redirectWithMessage('danger', 'Nama Smelter wajib diisi.');
        }

        if (mb_strlen($name) > 100) {
            redirectWithMessage(
                'danger',
                'Nama Smelter maksimal 100 karakter.'
            );
        }

        // Pastikan Smelter ada
        $stmt = $pdo->prepare("
            SELECT id
            FROM smelters
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$id]);

        if (!$stmt->fetch()) {
            redirectWithMessage(
                'danger',
                'Smelter tidak ditemukan.'
            );
        }

        // Pastikan divisi tujuan aktif
        $stmt = $pdo->prepare("
            SELECT id
            FROM divisions
            WHERE id = ?
              AND status = 'active'
            LIMIT 1
        ");

        $stmt->execute([$divisionId]);

        if (!$stmt->fetch()) {
            redirectWithMessage(
                'danger',
                'Divisi tujuan tidak ditemukan atau sedang nonaktif.'
            );
        }

        // Cek duplikasi nama dalam divisi tujuan
        $stmt = $pdo->prepare("
            SELECT id
            FROM smelters
            WHERE division_id = ?
              AND LOWER(name) = LOWER(?)
              AND id <> ?
            LIMIT 1
        ");

        $stmt->execute([
            $divisionId,
            $name,
            $id
        ]);

        if ($stmt->fetch()) {
            redirectWithMessage(
                'warning',
                'Nama Smelter tersebut sudah digunakan pada divisi tujuan.'
            );
        }

        try {

            $stmt = $pdo->prepare("
                UPDATE smelters
                SET division_id = ?,
                    name = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $divisionId,
                $name,
                $id
            ]);

            redirectWithMessage(
                'success',
                'Smelter berhasil diperbarui.'
            );

        } catch (PDOException $e) {

            redirectWithMessage(
                'danger',
                'Gagal memperbarui Smelter. ' . $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | TOGGLE STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle_status') {

        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            redirectWithMessage(
                'danger',
                'ID Smelter tidak valid.'
            );
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM smelters
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$id]);

        $smelter = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$smelter) {
            redirectWithMessage(
                'danger',
                'Smelter tidak ditemukan.'
            );
        }

        $currentStatus = $smelter['status'] ?? 'inactive';

        /*
        |--------------------------------------------------------------------------
        | DEACTIVATE
        |--------------------------------------------------------------------------
        */

        if ($currentStatus === 'active') {

            // Jangan nonaktifkan jika masih ada Team aktif
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM teams
                WHERE smelter_id = ?
                  AND status = 'active'
            ");

            $stmt->execute([$id]);

            $activeTeams = (int) $stmt->fetchColumn();

            if ($activeTeams > 0) {

                redirectWithMessage(
                    'warning',
                    'Smelter tidak dapat dinonaktifkan karena masih memiliki '
                    . $activeTeams
                    . ' Team aktif. Nonaktifkan Team terlebih dahulu.'
                );
            }

            // Jangan nonaktifkan jika masih ada akses aktif
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM user_access
                WHERE smelter_id = ?
                  AND status = 'active'
            ");

            $stmt->execute([$id]);

            $activeAccess = (int) $stmt->fetchColumn();

            if ($activeAccess > 0) {

                redirectWithMessage(
                    'warning',
                    'Smelter tidak dapat dinonaktifkan karena masih memiliki '
                    . $activeAccess
                    . ' akses user aktif.'
                );
            }

            // Jangan nonaktifkan jika masih ada request pending
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM access_requests
                WHERE smelter_id = ?
                  AND status = 'pending'
            ");

            $stmt->execute([$id]);

            $pendingRequests = (int) $stmt->fetchColumn();

            if ($pendingRequests > 0) {

                redirectWithMessage(
                    'warning',
                    'Smelter tidak dapat dinonaktifkan karena masih memiliki '
                    . $pendingRequests
                    . ' request akses pending.'
                );
            }

            $newStatus = 'inactive';

        } else {

            /*
            |--------------------------------------------------------------------------
            | ACTIVATE
            |--------------------------------------------------------------------------
            */

            // Pastikan divisinya masih aktif
            $stmt = $pdo->prepare("
                SELECT d.id
                FROM divisions d
                INNER JOIN smelters s
                    ON s.division_id = d.id
                WHERE s.id = ?
                  AND d.status = 'active'
                LIMIT 1
            ");

            $stmt->execute([$id]);

            if (!$stmt->fetch()) {
                redirectWithMessage(
                    'warning',
                    'Smelter tidak dapat diaktifkan karena divisinya sedang nonaktif.'
                );
            }

            $newStatus = 'active';
        }

        try {

            $stmt = $pdo->prepare("
                UPDATE smelters
                SET status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $newStatus,
                $id
            ]);

            if ($newStatus === 'active') {

                redirectWithMessage(
                    'success',
                    'Smelter berhasil diaktifkan.'
                );
            }

            redirectWithMessage(
                'success',
                'Smelter berhasil dinonaktifkan.'
            );

        } catch (PDOException $e) {

            redirectWithMessage(
                'danger',
                'Gagal mengubah status Smelter. ' . $e->getMessage()
            );
        }
    }

    redirectWithMessage(
        'danger',
        'Aksi tidak dikenali.'
    );
}

/*
|--------------------------------------------------------------------------
| GET DIVISIONS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        id,
        name,
        status
    FROM divisions
    WHERE status = 'active'
    ORDER BY name ASC
");

$divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| GET SMELTERS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        s.id,
        s.division_id,
        s.name,
        s.status,
        s.created_at,
        s.updated_at,

        d.name AS division_name,

        (
            SELECT COUNT(*)
            FROM teams t
            WHERE t.smelter_id = s.id
        ) AS total_teams,

        (
            SELECT COUNT(*)
            FROM teams t
            WHERE t.smelter_id = s.id
              AND t.status = 'active'
        ) AS active_teams,

        (
            SELECT COUNT(*)
            FROM user_access ua
            WHERE ua.smelter_id = s.id
              AND ua.status = 'active'
        ) AS active_access

    FROM smelters s

    INNER JOIN divisions d
        ON d.id = s.division_id

    ORDER BY
        d.id ASC,
        s.id ASC
");

$smelters = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$totalSmelters = count($smelters);

$activeSmelters = 0;
$inactiveSmelters = 0;
$totalTeams = 0;

foreach ($smelters as $smelter) {

    if ($smelter['status'] === 'active') {
        $activeSmelters++;
    } else {
        $inactiveSmelters++;
    }

    $totalTeams += (int) $smelter['total_teams'];
}

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Kelola Smelter - Admin</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            background: #f5f6f8;
        }

        .sidebar {
            min-height: 100vh;
            background: #212529;
        }

        .sidebar a {
            color: rgba(255,255,255,.75);
            text-decoration: none;
            display: block;
            padding: 10px 16px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: rgba(255,255,255,.1);
            color: #fff;
        }

        .stat-card {
            border: 0;
            border-radius: 12px;
        }

        .table-card {
            border: 0;
            border-radius: 12px;
            overflow: hidden;
        }

    </style>

</head>

<body>

<div class="container-fluid">

    <div class="row">

        <!-- SIDEBAR -->
        <aside class="col-md-3 col-lg-2 px-0 sidebar">

            <div class="p-3 text-white">

                <h5 class="mb-0">
                    Admin Panel
                </h5>

                <small class="text-white-50">
                    Smelter Management
                </small>

            </div>

            <nav>

                <a href="dashboard">
                    Dashboard
                </a>

                <a href="users">
                    Kelola User
                </a>

                <a href="divisions">
                    Kelola Divisi
                </a>

                <a
                    href="smelters"
                    class="active"
                >
                    Kelola Smelter
                </a>

                <a href="teams">
                    Kelola Team
                </a>

                <a href="access-requests">
                    Access Request
                </a>

            </nav>

        </aside>


        <!-- MAIN CONTENT -->
        <main class="col-md-9 col-lg-10 px-md-4 py-4">

            <!-- HEADER -->
            <div class="d-flex justify-content-between align-items-center mb-4">

                <div>

                    <h2 class="mb-1">
                        Kelola Smelter
                    </h2>

                    <p class="text-muted mb-0">
                        Mengelola Smelter berdasarkan Divisi.
                    </p>

                </div>

                <button
                    class="btn btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#addSmelterModal"
                >
                    + Tambah Smelter
                </button>

            </div>


            <!-- FLASH MESSAGE -->
            <?php if ($flashMessage): ?>

                <div
                    class="alert alert-<?= e($flashType ?: 'info') ?> alert-dismissible fade show"
                    role="alert"
                >

                    <?= e($flashMessage) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>

                </div>

            <?php endif; ?>


            <!-- STATISTICS -->
            <div class="row g-3 mb-4">

                <div class="col-md-3">

                    <div class="card stat-card shadow-sm">

                        <div class="card-body">

                            <div class="text-muted small">
                                Total Smelter
                            </div>

                            <div class="fs-3 fw-bold">
                                <?= $totalSmelters ?>
                            </div>

                        </div>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="card stat-card shadow-sm">

                        <div class="card-body">

                            <div class="text-muted small">
                                Smelter Aktif
                            </div>

                            <div class="fs-3 fw-bold text-success">
                                <?= $activeSmelters ?>
                            </div>

                        </div>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="card stat-card shadow-sm">

                        <div class="card-body">

                            <div class="text-muted small">
                                Smelter Nonaktif
                            </div>

                            <div class="fs-3 fw-bold text-secondary">
                                <?= $inactiveSmelters ?>
                            </div>

                        </div>

                    </div>

                </div>


                <div class="col-md-3">

                    <div class="card stat-card shadow-sm">

                        <div class="card-body">

                            <div class="text-muted small">
                                Total Team
                            </div>

                            <div class="fs-3 fw-bold">
                                <?= $totalTeams ?>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- TABLE -->
            <div class="card table-card shadow-sm">

                <div class="card-header bg-white py-3">

                    <strong>
                        Daftar Smelter
                    </strong>

                </div>


                <div class="card-body p-0">

                    <div class="table-responsive">

                        <table class="table table-hover align-middle mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th width="60">
                                        #
                                    </th>

                                    <th>
                                        Divisi
                                    </th>

                                    <th>
                                        Smelter
                                    </th>

                                    <th class="text-center">
                                        Team
                                    </th>

                                    <th class="text-center">
                                        Akses
                                    </th>

                                    <th class="text-center">
                                        Status
                                    </th>

                                    <th class="text-center">
                                        Aksi
                                    </th>

                                </tr>

                            </thead>


                            <tbody>

                            <?php if (!$smelters): ?>

                                <tr>

                                    <td
                                        colspan="7"
                                        class="text-center text-muted py-5"
                                    >
                                        Belum ada Smelter.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($smelters as $index => $smelter): ?>

                                    <tr>

                                        <td>
                                            <?= $index + 1 ?>
                                        </td>


                                        <td>

                                            <span class="fw-semibold">
                                                <?= e($smelter['division_name']) ?>
                                            </span>

                                        </td>


                                        <td>

                                            <div class="fw-semibold">
                                                <?= e($smelter['name']) ?>
                                            </div>

                                            <small class="text-muted">
                                                ID:
                                                <?= (int) $smelter['id'] ?>
                                            </small>

                                        </td>


                                        <td class="text-center">

                                            <span class="fw-semibold">
                                                <?= (int) $smelter['active_teams'] ?>
                                            </span>

                                            <small class="text-muted">
                                                /
                                                <?= (int) $smelter['total_teams'] ?>
                                            </small>

                                        </td>


                                        <td class="text-center">

                                            <?= (int) $smelter['active_access'] ?>

                                        </td>


                                        <td class="text-center">

                                            <?php if ($smelter['status'] === 'active'): ?>

                                                <span class="badge bg-success">
                                                    Aktif
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-secondary">
                                                    Nonaktif
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <td class="text-center">

                                            <div class="d-flex justify-content-center gap-1">

                                                <!-- EDIT -->
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editSmelterModal"
                                                    data-id="<?= (int) $smelter['id'] ?>"
                                                    data-division="<?= (int) $smelter['division_id'] ?>"
                                                    data-name="<?= e($smelter['name']) ?>"
                                                >
                                                    Edit
                                                </button>


                                                <!-- STATUS -->
                                                <form
                                                    method="POST"
                                                    onsubmit="return confirm('Yakin ingin mengubah status Smelter ini?');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e($csrfToken) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="toggle_status"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?= (int) $smelter['id'] ?>"
                                                    >


                                                    <?php if ($smelter['status'] === 'active'): ?>

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-danger"
                                                        >
                                                            Nonaktifkan
                                                        </button>

                                                    <?php else: ?>

                                                        <button
                                                            type="submit"
                                                            class="btn btn-sm btn-outline-success"
                                                        >
                                                            Aktifkan
                                                        </button>

                                                    <?php endif; ?>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </main>

    </div>

</div>


<!-- ========================================================= -->
<!-- ADD SMELTER MODAL -->
<!-- ========================================================= -->

<div
    class="modal fade"
    id="addSmelterModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">
                        Tambah Smelter
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
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="add"
                    >


                    <div class="mb-3">

                        <label class="form-label">
                            Divisi
                        </label>

                        <select
                            name="division_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                -- Pilih Divisi --
                            </option>

                            <?php foreach ($divisions as $division): ?>

                                <option
                                    value="<?= (int) $division['id'] ?>"
                                >
                                    <?= e($division['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Nama Smelter
                        </label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            maxlength="100"
                            placeholder="Contoh: Smelter 3"
                            required
                        >

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
                        class="btn btn-primary"
                    >
                        Simpan
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- ========================================================= -->
<!-- EDIT SMELTER MODAL -->
<!-- ========================================================= -->

<div
    class="modal fade"
    id="editSmelterModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">
                        Edit Smelter
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
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="edit"
                    >

                    <input
                        type="hidden"
                        name="id"
                        id="editSmelterId"
                    >


                    <div class="mb-3">

                        <label class="form-label">
                            Divisi
                        </label>

                        <select
                            name="division_id"
                            id="editSmelterDivision"
                            class="form-select"
                            required
                        >

                            <option value="">
                                -- Pilih Divisi --
                            </option>

                            <?php foreach ($divisions as $division): ?>

                                <option
                                    value="<?= (int) $division['id'] ?>"
                                >
                                    <?= e($division['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Nama Smelter
                        </label>

                        <input
                            type="text"
                            name="name"
                            id="editSmelterName"
                            class="form-control"
                            maxlength="100"
                            required
                        >

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
                        class="btn btn-primary"
                    >
                        Simpan Perubahan
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

const editSmelterModal =
    document.getElementById('editSmelterModal');

if (editSmelterModal) {

    editSmelterModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button = event.relatedTarget;

            const id =
                button.getAttribute('data-id');

            const division =
                button.getAttribute('data-division');

            const name =
                button.getAttribute('data-name');

            document.getElementById(
                'editSmelterId'
            ).value = id;

            document.getElementById(
                'editSmelterDivision'
            ).value = division;

            document.getElementById(
                'editSmelterName'
            ).value = name;

        }
    );

}

</script>

</body>
</html>