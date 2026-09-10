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

    header('Location: divisions');
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
    | ADD DIVISION
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            redirectWithMessage('danger', 'Nama divisi wajib diisi.');
        }

        if (mb_strlen($name) > 100) {
            redirectWithMessage('danger', 'Nama divisi maksimal 100 karakter.');
        }

        // Cek duplikasi tanpa membedakan huruf besar/kecil
        $stmt = $pdo->prepare("
            SELECT id
            FROM divisions
            WHERE LOWER(name) = LOWER(?)
            LIMIT 1
        ");

        $stmt->execute([$name]);

        if ($stmt->fetch()) {
            redirectWithMessage('warning', 'Divisi dengan nama tersebut sudah ada.');
        }

        try {

            $stmt = $pdo->prepare("
                INSERT INTO divisions
                    (name, status, created_at, updated_at)
                VALUES
                    (?, 'active', NOW(), NOW())
            ");

            $stmt->execute([$name]);

            redirectWithMessage('success', 'Divisi berhasil ditambahkan.');

        } catch (PDOException $e) {

            redirectWithMessage(
                'danger',
                'Gagal menambahkan divisi. ' . $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT DIVISION
    |--------------------------------------------------------------------------
    */

    if ($action === 'edit') {

        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($id <= 0) {
            redirectWithMessage('danger', 'ID divisi tidak valid.');
        }

        if ($name === '') {
            redirectWithMessage('danger', 'Nama divisi wajib diisi.');
        }

        if (mb_strlen($name) > 100) {
            redirectWithMessage('danger', 'Nama divisi maksimal 100 karakter.');
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM divisions
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$id]);

        if (!$stmt->fetch()) {
            redirectWithMessage('danger', 'Divisi tidak ditemukan.');
        }

        // Cek apakah nama sudah digunakan divisi lain
        $stmt = $pdo->prepare("
            SELECT id
            FROM divisions
            WHERE LOWER(name) = LOWER(?)
              AND id <> ?
            LIMIT 1
        ");

        $stmt->execute([$name, $id]);

        if ($stmt->fetch()) {
            redirectWithMessage(
                'warning',
                'Nama divisi tersebut sudah digunakan oleh divisi lain.'
            );
        }

        try {

            $stmt = $pdo->prepare("
                UPDATE divisions
                SET name = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([$name, $id]);

            redirectWithMessage('success', 'Divisi berhasil diperbarui.');

        } catch (PDOException $e) {

            redirectWithMessage(
                'danger',
                'Gagal memperbarui divisi. ' . $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVATE / DEACTIVATE
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle_status') {

        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            redirectWithMessage('danger', 'ID divisi tidak valid.');
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM divisions
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$id]);

        $division = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$division) {
            redirectWithMessage('danger', 'Divisi tidak ditemukan.');
        }

        $currentStatus = $division['status'] ?? 'inactive';

        /*
        |--------------------------------------------------------------------------
        | DEACTIVATE
        |--------------------------------------------------------------------------
        */

        if ($currentStatus === 'active') {

            // Jangan nonaktifkan jika masih ada smelter aktif
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM smelters
                WHERE division_id = ?
                  AND status = 'active'
            ");

            $stmt->execute([$id]);

            $activeSmelters = (int) $stmt->fetchColumn();

            if ($activeSmelters > 0) {

                redirectWithMessage(
                    'warning',
                    'Divisi tidak dapat dinonaktifkan karena masih memiliki '
                    . $activeSmelters
                    . ' smelter aktif. Nonaktifkan smelter terlebih dahulu.'
                );
            }

            // Jangan nonaktifkan jika masih ada user aktif
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE division_id = ?
                  AND status = 'active'
            ");

            $stmt->execute([$id]);

            $activeUsers = (int) $stmt->fetchColumn();

            if ($activeUsers > 0) {

                redirectWithMessage(
                    'warning',
                    'Divisi tidak dapat dinonaktifkan karena masih memiliki '
                    . $activeUsers
                    . ' user aktif.'
                );
            }

            $newStatus = 'inactive';

        } else {

            /*
            |--------------------------------------------------------------------------
            | ACTIVATE
            |--------------------------------------------------------------------------
            */

            $newStatus = 'active';
        }

        try {

            $stmt = $pdo->prepare("
                UPDATE divisions
                SET status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([$newStatus, $id]);

            if ($newStatus === 'active') {
                redirectWithMessage('success', 'Divisi berhasil diaktifkan.');
            }

            redirectWithMessage('success', 'Divisi berhasil dinonaktifkan.');

        } catch (PDOException $e) {

            redirectWithMessage(
                'danger',
                'Gagal mengubah status divisi. ' . $e->getMessage()
            );
        }
    }

    redirectWithMessage('danger', 'Aksi tidak dikenali.');
}

/*
|--------------------------------------------------------------------------
| GET DIVISIONS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        d.id,
        d.name,
        d.status,
        d.created_at,
        d.updated_at,

        (
            SELECT COUNT(*)
            FROM smelters s
            WHERE s.division_id = d.id
        ) AS total_smelters,

        (
            SELECT COUNT(*)
            FROM smelters s
            WHERE s.division_id = d.id
              AND s.status = 'active'
        ) AS active_smelters,

        (
            SELECT COUNT(*)
            FROM teams t
            INNER JOIN smelters s
                ON s.id = t.smelter_id
            WHERE s.division_id = d.id
        ) AS total_teams,

        (
            SELECT COUNT(*)
            FROM teams t
            INNER JOIN smelters s
                ON s.id = t.smelter_id
            WHERE s.division_id = d.id
              AND t.status = 'active'
        ) AS active_teams,

        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.division_id = d.id
              AND u.status <> 'deleted'
        ) AS total_users

    FROM divisions d
    ORDER BY d.id ASC
");

$divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalDivisions = count($divisions);

$activeDivisions = 0;
$inactiveDivisions = 0;
$totalSmelters = 0;
$totalTeams = 0;

foreach ($divisions as $division) {

    if ($division['status'] === 'active') {
        $activeDivisions++;
    } else {
        $inactiveDivisions++;
    }

    $totalSmelters += (int) $division['total_smelters'];
    $totalTeams += (int) $division['total_teams'];
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

    <title>Kelola Divisi - Admin</title>

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

        .badge-active {
            background: #198754;
        }

        .badge-inactive {
            background: #6c757d;
        }
    </style>

</head>

<body>

<div class="container-fluid">

    <div class="row">

        <!-- SIDEBAR -->
        <aside class="col-md-3 col-lg-2 px-0 sidebar">

            <div class="p-3 text-white">
                <h5 class="mb-0">Admin Panel</h5>
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

                <a href="divisions" class="active">
                    Kelola Divisi
                </a>

                <a href="smelters">
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

        <!-- CONTENT -->
        <main class="col-md-9 col-lg-10 px-md-4 py-4">

            <!-- HEADER -->
            <div class="d-flex justify-content-between align-items-center mb-4">

                <div>
                    <h2 class="mb-1">
                        Kelola Divisi
                    </h2>

                    <p class="text-muted mb-0">
                        Mengelola divisi yang tersedia pada sistem smelter.
                    </p>
                </div>

                <button
                    class="btn btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#addDivisionModal"
                >
                    + Tambah Divisi
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
                                Total Divisi
                            </div>

                            <div class="fs-3 fw-bold">
                                <?= $totalDivisions ?>
                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-md-3">

                    <div class="card stat-card shadow-sm">

                        <div class="card-body">

                            <div class="text-muted small">
                                Divisi Aktif
                            </div>

                            <div class="fs-3 fw-bold text-success">
                                <?= $activeDivisions ?>
                            </div>

                        </div>

                    </div>

                </div>

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
                        Daftar Divisi
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
                                        Nama Divisi
                                    </th>

                                    <th class="text-center">
                                        Smelter
                                    </th>

                                    <th class="text-center">
                                        Team
                                    </th>

                                    <th class="text-center">
                                        User
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

                            <?php if (!$divisions): ?>

                                <tr>

                                    <td
                                        colspan="7"
                                        class="text-center text-muted py-5"
                                    >
                                        Belum ada divisi.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($divisions as $index => $division): ?>

                                    <tr>

                                        <td>
                                            <?= $index + 1 ?>
                                        </td>

                                        <td>

                                            <div class="fw-semibold">
                                                <?= e($division['name']) ?>
                                            </div>

                                            <small class="text-muted">
                                                ID: <?= (int) $division['id'] ?>
                                            </small>

                                        </td>

                                        <td class="text-center">

                                            <span class="fw-semibold">
                                                <?= (int) $division['active_smelters'] ?>
                                            </span>

                                            <small class="text-muted">
                                                /
                                                <?= (int) $division['total_smelters'] ?>
                                            </small>

                                        </td>

                                        <td class="text-center">

                                            <span class="fw-semibold">
                                                <?= (int) $division['active_teams'] ?>
                                            </span>

                                            <small class="text-muted">
                                                /
                                                <?= (int) $division['total_teams'] ?>
                                            </small>

                                        </td>

                                        <td class="text-center">
                                            <?= (int) $division['total_users'] ?>
                                        </td>

                                        <td class="text-center">

                                            <?php if ($division['status'] === 'active'): ?>

                                                <span class="badge badge-active">
                                                    Aktif
                                                </span>

                                            <?php else: ?>

                                                <span class="badge badge-inactive">
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
                                                    data-bs-target="#editDivisionModal"
                                                    data-id="<?= (int) $division['id'] ?>"
                                                    data-name="<?= e($division['name']) ?>"
                                                >
                                                    Edit
                                                </button>

                                                <!-- TOGGLE -->
                                                <form
                                                    method="POST"
                                                    onsubmit="return confirm('Yakin ingin mengubah status divisi ini?');"
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
                                                        value="<?= (int) $division['id'] ?>"
                                                    >

                                                    <?php if ($division['status'] === 'active'): ?>

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
<!-- ADD DIVISION MODAL -->
<!-- ========================================================= -->

<div
    class="modal fade"
    id="addDivisionModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">
                        Tambah Divisi
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
                            Nama Divisi
                        </label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            maxlength="100"
                            placeholder="Contoh: Electric Furnace"
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
<!-- EDIT DIVISION MODAL -->
<!-- ========================================================= -->

<div
    class="modal fade"
    id="editDivisionModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <form method="POST">

                <div class="modal-header">

                    <h5 class="modal-title">
                        Edit Divisi
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
                        id="editDivisionId"
                    >

                    <div class="mb-3">

                        <label class="form-label">
                            Nama Divisi
                        </label>

                        <input
                            type="text"
                            name="name"
                            id="editDivisionName"
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

const editDivisionModal = document.getElementById('editDivisionModal');

if (editDivisionModal) {

    editDivisionModal.addEventListener('show.bs.modal', function (event) {

        const button = event.relatedTarget;

        const id = button.getAttribute('data-id');
        const name = button.getAttribute('data-name');

        document.getElementById('editDivisionId').value = id;
        document.getElementById('editDivisionName').value = name;

    });

}

</script>

</body>
</html>