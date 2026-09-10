<?php

require_once __DIR__ . '/../middleware/admin.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Koneksi database tidak tersedia.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/
function redirectTeams(array $params = []): never
{
    $query = http_build_query($params);

    header(
        'Location: teams' . ($query ? '?' . $query : '')
    );
    exit;
}

function postValue(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function getInt(string $key): int
{
    return (int) ($_GET[$key] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Flash Message
|--------------------------------------------------------------------------
*/
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';

unset($_SESSION['success'], $_SESSION['error']);

/*
|--------------------------------------------------------------------------
| Handle POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !$postedToken ||
        !hash_equals($_SESSION['csrf_token'], $postedToken)
    ) {
        $_SESSION['error'] = 'Token keamanan tidak valid.';
        redirectTeams();
    }

    $action = postValue('action');

    try {

        /*
        |--------------------------------------------------------------------------
        | ADD TEAM
        |--------------------------------------------------------------------------
        */
        if ($action === 'add') {

            $divisionId = (int) ($_POST['division_id'] ?? 0);
            $smelterId  = (int) ($_POST['smelter_id'] ?? 0);
            $name       = postValue('name');
            $link       = postValue('link');

            if ($divisionId <= 0) {
                throw new RuntimeException('Divisi wajib dipilih.');
            }

            if ($smelterId <= 0) {
                throw new RuntimeException('Smelter wajib dipilih.');
            }

            if ($name === '') {
                throw new RuntimeException('Nama team wajib diisi.');
            }

            if ($link === '') {
                throw new RuntimeException('Link team wajib diisi.');
            }

            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Format link tidak valid.');
            }

            /*
            |--------------------------------------------------------------------------
            | Pastikan smelter benar-benar milik divisi
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
                throw new RuntimeException(
                    'Smelter tidak valid untuk divisi yang dipilih.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Cek duplicate team
            |--------------------------------------------------------------------------
            */
            $stmt = $pdo->prepare("
                SELECT id
                FROM teams
                WHERE smelter_id = ?
                  AND name = ?
                LIMIT 1
            ");

            $stmt->execute([
                $smelterId,
                $name
            ]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Team dengan nama tersebut sudah ada pada smelter ini.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Insert
            |--------------------------------------------------------------------------
            */
            $stmt = $pdo->prepare("
                INSERT INTO teams (
                    smelter_id,
                    name,
                    link,
                    status,
                    created_at,
                    updated_at
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    'active',
                    NOW(),
                    NOW()
                )
            ");

            $stmt->execute([
                $smelterId,
                $name,
                $link
            ]);

            $_SESSION['success'] = 'Team berhasil ditambahkan.';

            redirectTeams([
                'division_id' => $divisionId,
                'smelter_id'  => $smelterId
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | EDIT TEAM
        |--------------------------------------------------------------------------
        */
        if ($action === 'edit') {

            $teamId     = (int) ($_POST['team_id'] ?? 0);
            $divisionId = (int) ($_POST['division_id'] ?? 0);
            $smelterId  = (int) ($_POST['smelter_id'] ?? 0);
            $name       = postValue('name');
            $link       = postValue('link');

            if ($teamId <= 0) {
                throw new RuntimeException('Team tidak valid.');
            }

            if ($divisionId <= 0) {
                throw new RuntimeException('Divisi wajib dipilih.');
            }

            if ($smelterId <= 0) {
                throw new RuntimeException('Smelter wajib dipilih.');
            }

            if ($name === '') {
                throw new RuntimeException('Nama team wajib diisi.');
            }

            if ($link === '') {
                throw new RuntimeException('Link team wajib diisi.');
            }

            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Format link tidak valid.');
            }

            /*
            |--------------------------------------------------------------------------
            | Pastikan team exists
            |--------------------------------------------------------------------------
            */
            $stmt = $pdo->prepare("
                SELECT id
                FROM teams
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$teamId]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Team tidak ditemukan.');
            }

            /*
            |--------------------------------------------------------------------------
            | Pastikan smelter milik divisi
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
                throw new RuntimeException(
                    'Smelter tidak valid untuk divisi yang dipilih.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Cek duplicate nama team selain team yang sedang diedit
            |--------------------------------------------------------------------------
            */
            $stmt = $pdo->prepare("
                SELECT id
                FROM teams
                WHERE smelter_id = ?
                  AND name = ?
                  AND id <> ?
                LIMIT 1
            ");

            $stmt->execute([
                $smelterId,
                $name,
                $teamId
            ]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Nama team tersebut sudah digunakan pada smelter ini.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Update
            |--------------------------------------------------------------------------
            */
            $stmt = $pdo->prepare("
                UPDATE teams
                SET
                    smelter_id = ?,
                    name = ?,
                    link = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $smelterId,
                $name,
                $link,
                $teamId
            ]);

            $_SESSION['success'] = 'Team berhasil diperbarui.';

            redirectTeams([
                'division_id' => $divisionId,
                'smelter_id'  => $smelterId
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | TOGGLE STATUS
        |--------------------------------------------------------------------------
        */
        if ($action === 'toggle_status') {

            $teamId = (int) ($_POST['team_id'] ?? 0);

            if ($teamId <= 0) {
                throw new RuntimeException('Team tidak valid.');
            }

            /*
            |--------------------------------------------------------------------------
            | Ambil status saat ini
            |--------------------------------------------------------------------------
            */
            $stmt = $pdo->prepare("
                SELECT
                    t.id,
                    t.status,
                    s.division_id,
                    t.smelter_id
                FROM teams t
                INNER JOIN smelters s
                    ON s.id = t.smelter_id
                WHERE t.id = ?
                LIMIT 1
            ");

            $stmt->execute([$teamId]);

            $team = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$team) {
                throw new RuntimeException('Team tidak ditemukan.');
            }

            $newStatus = $team['status'] === 'active'
                ? 'inactive'
                : 'active';

            $stmt = $pdo->prepare("
                UPDATE teams
                SET
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $newStatus,
                $teamId
            ]);

            $_SESSION['success'] =
                $newStatus === 'active'
                    ? 'Team berhasil diaktifkan.'
                    : 'Team berhasil dinonaktifkan.';

            redirectTeams([
                'division_id' => (int) $team['division_id'],
                'smelter_id'  => (int) $team['smelter_id']
            ]);
        }

        throw new RuntimeException('Aksi tidak dikenali.');

    } catch (Throwable $e) {

        $_SESSION['error'] = $e->getMessage();

        redirectTeams([
            'division_id' => (int) ($_POST['division_id'] ?? 0),
            'smelter_id'  => (int) ($_POST['smelter_id'] ?? 0)
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
*/
$selectedDivision = getInt('division_id');
$selectedSmelter  = getInt('smelter_id');

/*
|--------------------------------------------------------------------------
| Get Divisions
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT
        id,
        name
    FROM divisions
    WHERE status = 'active'
    ORDER BY name ASC
");

$divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Get Smelters
|--------------------------------------------------------------------------
*/
$smelters = [];

if ($selectedDivision > 0) {

    $stmt = $pdo->prepare("
        SELECT
            id,
            name
        FROM smelters
        WHERE division_id = ?
          AND status = 'active'
        ORDER BY name ASC
    ");

    $stmt->execute([$selectedDivision]);

    $smelters = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Get Teams
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        t.id,
        t.smelter_id,
        t.name,
        t.link,
        t.status,
        t.created_at,
        t.updated_at,

        s.name AS smelter_name,
        s.division_id,

        d.name AS division_name

    FROM teams t

    INNER JOIN smelters s
        ON s.id = t.smelter_id

    INNER JOIN divisions d
        ON d.id = s.division_id

    WHERE 1 = 1
";

$params = [];

if ($selectedDivision > 0) {
    $sql .= " AND d.id = ?";
    $params[] = $selectedDivision;
}

if ($selectedSmelter > 0) {
    $sql .= " AND s.id = ?";
    $params[] = $selectedSmelter;
}

$sql .= "
    ORDER BY
        d.name ASC,
        s.name ASC,
        t.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/
$totalTeams = count($teams);

$activeTeams = 0;
$inactiveTeams = 0;

foreach ($teams as $team) {
    if ($team['status'] === 'active') {
        $activeTeams++;
    } else {
        $inactiveTeams++;
    }
}

/*
|--------------------------------------------------------------------------
| Edit Data
|--------------------------------------------------------------------------
*/
$editTeam = null;

$editId = getInt('edit');

if ($editId > 0) {

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.smelter_id,
            t.name,
            t.link,
            t.status,
            s.division_id
        FROM teams t
        INNER JOIN smelters s
            ON s.id = t.smelter_id
        WHERE t.id = ?
        LIMIT 1
    ");

    $stmt->execute([$editId]);

    $editTeam = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editTeam) {
        $selectedDivision = (int) $editTeam['division_id'];
        $selectedSmelter  = (int) $editTeam['smelter_id'];
    }
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Manajemen Team - Admin</title>

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
            color: #adb5bd;
            text-decoration: none;
            display: block;
            padding: 10px 16px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #343a40;
            color: #fff;
        }

        .stat-card {
            border: 0;
            border-radius: 12px;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

        .team-link {
            max-width: 280px;
            display: inline-block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
</head>

<body>

<div class="container-fluid">
    <div class="row">

        <!-- SIDEBAR -->
        <aside class="col-md-2 col-lg-2 px-0 sidebar">

            <div class="p-3 text-white">
                <h5 class="mb-0">Admin Panel</h5>
            </div>

            <nav>

                <a href="dashboard">
                    Dashboard
                </a>

                <a href="users">
                    User Management
                </a>

                <a href="divisions">
                    Divisi
                </a>

                <a href="smelters">
                    Smelter
                </a>

                <a href="teams" class="active">
                    Team
                </a>

                <a href="access-requests">
                    Access Request
                </a>

            </nav>

        </aside>

        <!-- CONTENT -->
        <main class="col-md-10 col-lg-10 p-4">

            <div class="d-flex justify-content-between align-items-center mb-4">

                <div>
                    <h2 class="mb-1">
                        Manajemen Team
                    </h2>

                    <p class="text-muted mb-0">
                        Kelola team dan link berdasarkan smelter.
                    </p>
                </div>

                <?php if (!$editTeam): ?>

                    <a
                        href="teams#team-form"
                        class="btn btn-primary"
                    >
                        + Tambah Team
                    </a>

                <?php else: ?>

                    <a
                        href="teams"
                        class="btn btn-outline-secondary"
                    >
                        Batal Edit
                    </a>

                <?php endif; ?>

            </div>

            <!-- FLASH -->
            <?php if ($success): ?>

                <div class="alert alert-success alert-dismissible fade show">
                    <?= htmlspecialchars($success) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>
                </div>

            <?php endif; ?>

            <?php if ($error): ?>

                <div class="alert alert-danger alert-dismissible fade show">
                    <?= htmlspecialchars($error) ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                    ></button>
                </div>

            <?php endif; ?>

            <!-- STATISTICS -->
            <div class="row g-3 mb-4">

                <div class="col-md-4">

                    <div class="card stat-card shadow-sm">
                        <div class="card-body">

                            <small class="text-muted">
                                Total Team
                            </small>

                            <h3 class="mb-0">
                                <?= $totalTeams ?>
                            </h3>

                        </div>
                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card stat-card shadow-sm">
                        <div class="card-body">

                            <small class="text-muted">
                                Team Aktif
                            </small>

                            <h3 class="mb-0 text-success">
                                <?= $activeTeams ?>
                            </h3>

                        </div>
                    </div>

                </div>

                <div class="col-md-4">

                    <div class="card stat-card shadow-sm">
                        <div class="card-body">

                            <small class="text-muted">
                                Team Nonaktif
                            </small>

                            <h3 class="mb-0 text-secondary">
                                <?= $inactiveTeams ?>
                            </h3>

                        </div>
                    </div>

                </div>

            </div>

            <!-- FORM -->
            <div
                class="card shadow-sm mb-4"
                id="team-form"
            >

                <div class="card-header bg-white">

                    <strong>
                        <?= $editTeam ? 'Edit Team' : 'Tambah Team' ?>
                    </strong>

                </div>

                <div class="card-body">

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="<?= $editTeam ? 'edit' : 'add' ?>"
                        >

                        <?php if ($editTeam): ?>

                            <input
                                type="hidden"
                                name="team_id"
                                value="<?= (int) $editTeam['id'] ?>"
                            >

                        <?php endif; ?>

                        <div class="row g-3">

                            <!-- DIVISION -->
                            <div class="col-md-4">

                                <label class="form-label">
                                    Divisi
                                </label>

                                <select
                                    name="division_id"
                                    id="division_id"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        -- Pilih Divisi --
                                    </option>

                                    <?php foreach ($divisions as $division): ?>

                                        <option
                                            value="<?= (int) $division['id'] ?>"
                                            <?= (
                                                $selectedDivision === (int) $division['id']
                                            ) ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($division['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <!-- SMELTER -->
                            <div class="col-md-4">

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
                                            <?= (
                                                $selectedSmelter === (int) $smelter['id']
                                            ) ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($smelter['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <!-- TEAM -->
                            <div class="col-md-4">

                                <label class="form-label">
                                    Nama Team
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?= htmlspecialchars(
                                        $editTeam['name'] ?? ''
                                    ) ?>"
                                    placeholder="Contoh: Team A"
                                    required
                                >

                            </div>

                            <!-- LINK -->
                            <div class="col-md-12">

                                <label class="form-label">
                                    Link Team
                                </label>

                                <input
                                    type="url"
                                    name="link"
                                    class="form-control"
                                    value="<?= htmlspecialchars(
                                        $editTeam['link'] ?? ''
                                    ) ?>"
                                    placeholder="https://..."
                                    required
                                >

                                <div class="form-text">
                                    Satu team hanya memiliki satu link.
                                </div>

                            </div>

                        </div>

                        <div class="mt-4">

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                <?= $editTeam
                                    ? 'Simpan Perubahan'
                                    : 'Tambah Team'
                                ?>
                            </button>

                            <?php if ($editTeam): ?>

                                <a
                                    href="teams"
                                    class="btn btn-secondary"
                                >
                                    Batal
                                </a>

                            <?php endif; ?>

                        </div>

                    </form>

                </div>

            </div>

            <!-- FILTER -->
            <div class="card shadow-sm mb-4">

                <div class="card-header bg-white">

                    <strong>
                        Filter Team
                    </strong>

                </div>

                <div class="card-body">

                    <form
                        method="GET"
                        class="row g-3"
                    >

                        <div class="col-md-5">

                            <label class="form-label">
                                Divisi
                            </label>

                            <select
                                name="division_id"
                                id="filter_division_id"
                                class="form-select"
                            >

                                <option value="">
                                    Semua Divisi
                                </option>

                                <?php foreach ($divisions as $division): ?>

                                    <option
                                        value="<?= (int) $division['id'] ?>"
                                        <?= (
                                            $selectedDivision === (int) $division['id']
                                        ) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($division['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-5">

                            <label class="form-label">
                                Smelter
                            </label>

                            <select
                                name="smelter_id"
                                id="filter_smelter_id"
                                class="form-select"
                            >

                                <option value="">
                                    Semua Smelter
                                </option>

                                <?php foreach ($smelters as $smelter): ?>

                                    <option
                                        value="<?= (int) $smelter['id'] ?>"
                                        <?= (
                                            $selectedSmelter === (int) $smelter['id']
                                        ) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($smelter['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-md-2 d-flex align-items-end">

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >
                                Filter
                            </button>

                        </div>

                    </form>

                </div>

            </div>

            <!-- TABLE -->
            <div class="card shadow-sm">

                <div class="card-header bg-white">

                    <div class="d-flex justify-content-between">

                        <strong>
                            Daftar Team
                        </strong>

                        <span class="text-muted">
                            <?= $totalTeams ?> team
                        </span>

                    </div>

                </div>

                <div class="card-body p-0">

                    <div class="table-responsive">

                        <table class="table table-hover mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th width="50">
                                        #
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
                                        Link
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th
                                        class="text-end"
                                        width="180"
                                    >
                                        Aksi
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php if (!$teams): ?>

                                <tr>

                                    <td
                                        colspan="7"
                                        class="text-center py-5 text-muted"
                                    >
                                        Belum ada team.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($teams as $index => $team): ?>

                                    <tr>

                                        <td>
                                            <?= $index + 1 ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                $team['division_name']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                $team['smelter_name']
                                            ) ?>
                                        </td>

                                        <td>

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $team['name']
                                                ) ?>
                                            </strong>

                                        </td>

                                        <td>

                                            <a
                                                href="<?= htmlspecialchars(
                                                    $team['link']
                                                ) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="team-link"
                                                title="<?= htmlspecialchars(
                                                    $team['link']
                                                ) ?>"
                                            >
                                                <?= htmlspecialchars(
                                                    $team['link']
                                                ) ?>
                                            </a>

                                        </td>

                                        <td>

                                            <?php if ($team['status'] === 'active'): ?>

                                                <span class="badge bg-success">
                                                    Aktif
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-secondary">
                                                    Nonaktif
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td class="text-end">

                                            <div class="d-flex justify-content-end gap-1">

                                                <a
                                                    href="teams?edit=<?= (int) $team['id'] ?>"
                                                    class="btn btn-sm btn-outline-primary"
                                                >
                                                    Edit
                                                </a>

                                                <form
                                                    method="POST"
                                                    class="d-inline"
                                                    onsubmit="return confirm(
                                                        'Yakin ingin mengubah status team ini?'
                                                    );"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= htmlspecialchars(
                                                            $csrfToken
                                                        ) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="toggle_status"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="team_id"
                                                        value="<?= (int) $team['id'] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="division_id"
                                                        value="<?= (int) $team['division_id'] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="smelter_id"
                                                        value="<?= (int) $team['smelter_id'] ?>"
                                                    >

                                                    <?php if ($team['status'] === 'active'): ?>

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

<script>
document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | Generic dependent dropdown
    |--------------------------------------------------------------------------
    */
    async function loadSmelters(divisionSelect, smelterSelect) {

        const divisionId = divisionSelect.value;

        smelterSelect.innerHTML = '';

        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = '-- Pilih Smelter --';

        smelterSelect.appendChild(defaultOption);

        if (!divisionId) {
            return;
        }

        try {

            const response = await fetch(
                '../api/get-smelters?division_id=' +
                encodeURIComponent(divisionId)
            );

            if (!response.ok) {
                throw new Error('Gagal mengambil data smelter.');
            }

            const result = await response.json();

            // API mengembalikan:
            // {
            //     success: true,
            //     data: [...]
            // }

            if (!result.success || !Array.isArray(result.data)) {
                throw new Error(
                    result.message || 'Format data smelter tidak valid.'
                );
            }

            result.data.forEach(function (smelter) {

                const option = document.createElement('option');

                option.value = smelter.id;
                option.textContent = smelter.name;

                smelterSelect.appendChild(option);

            });

        } catch (error) {

            console.error('Error memuat smelter:', error);

            smelterSelect.innerHTML = '';

            const errorOption = document.createElement('option');
            errorOption.value = '';
            errorOption.textContent = 'Gagal memuat smelter';

            smelterSelect.appendChild(errorOption);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Form: Divisi -> Smelter
    |--------------------------------------------------------------------------
    */
    const formDivision = document.getElementById('division_id');
    const formSmelter  = document.getElementById('smelter_id');

    if (formDivision && formSmelter) {

        formDivision.addEventListener('change', async function () {

            await loadSmelters(
                formDivision,
                formSmelter
            );

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Filter: Divisi -> Smelter
    |--------------------------------------------------------------------------
    */
    const filterDivision = document.getElementById('filter_division_id');
    const filterSmelter  = document.getElementById('filter_smelter_id');

    if (filterDivision && filterSmelter) {

        filterDivision.addEventListener('change', async function () {

            const previousValue = filterSmelter.value;

            await loadSmelters(
                filterDivision,
                filterSmelter
            );

            /*
            | Jika smelter sebelumnya masih tersedia,
            | pertahankan pilihan tersebut.
            */
            const exists = Array.from(
                filterSmelter.options
            ).some(function (option) {
                return option.value === previousValue;
            });

            if (exists) {
                filterSmelter.value = previousValue;
            }

        });

    }

});
</script>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>