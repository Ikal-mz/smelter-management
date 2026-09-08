<?php

require_once __DIR__ . '/../middleware/admin.php';
require_once __DIR__ . '/../config/database.php';

$error = '';
$success = '';

$editTeam = null;


/*
|--------------------------------------------------------------------------
| DELETE / NONAKTIFKAN TEAM
|--------------------------------------------------------------------------
|
| Kita tidak melakukan DELETE sebenarnya.
| Team dibuat inactive agar history tetap aman.
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {

    $action = $_POST['action'];

    if ($action === 'toggle_status') {

        $teamId = filter_input(
            INPUT_POST,
            'team_id',
            FILTER_VALIDATE_INT
        );

        if (!$teamId) {

            $error = 'Team tidak valid.';

        } else {

            $stmt = $pdo->prepare("
                SELECT status
                FROM teams
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$teamId]);

            $team = $stmt->fetch();

            if (!$team) {

                $error = 'Team tidak ditemukan.';

            } else {

                $newStatus =
                    $team['status'] === 'active'
                    ? 'inactive'
                    : 'active';

                $stmt = $pdo->prepare("
                    UPDATE teams
                    SET status = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $newStatus,
                    $teamId
                ]);

                $success =
                    'Status Team berhasil diperbarui.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Tambah Team
    |--------------------------------------------------------------------------
    */

    if ($action === 'create') {

        $divisionId = filter_input(
            INPUT_POST,
            'division_id',
            FILTER_VALIDATE_INT
        );

        $smelterId = filter_input(
            INPUT_POST,
            'smelter_id',
            FILTER_VALIDATE_INT
        );

        $name = trim(
            $_POST['name'] ?? ''
        );

        $link = trim(
            $_POST['link'] ?? ''
        );


        if (
            !$divisionId ||
            !$smelterId ||
            $name === '' ||
            $link === ''
        ) {

            $error =
                'Divisi, Smelter, Nama Team, dan Link wajib diisi.';

        } elseif (!filter_var($link, FILTER_VALIDATE_URL)) {

            $error = 'Format link tidak valid.';

        } else {

            /*
             * Pastikan Smelter memang milik Division.
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

            if (!$stmt->fetch()) {

                $error =
                    'Smelter tidak sesuai dengan Divisi.';

            } else {

                /*
                 * Cek nama Team duplikat.
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

                if ($stmt->fetch()) {

                    $error =
                        'Nama Team sudah digunakan pada Smelter tersebut.';

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO teams (
                            smelter_id,
                            name,
                            link,
                            status
                        )
                        VALUES (?, ?, ?, 'active')
                    ");

                    $stmt->execute([
                        $smelterId,
                        $name,
                        $link
                    ]);

                    $success =
                        'Team berhasil ditambahkan.';
                }
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Update Team
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        $teamId = filter_input(
            INPUT_POST,
            'team_id',
            FILTER_VALIDATE_INT
        );

        $divisionId = filter_input(
            INPUT_POST,
            'division_id',
            FILTER_VALIDATE_INT
        );

        $smelterId = filter_input(
            INPUT_POST,
            'smelter_id',
            FILTER_VALIDATE_INT
        );

        $name = trim(
            $_POST['name'] ?? ''
        );

        $link = trim(
            $_POST['link'] ?? ''
        );


        if (
            !$teamId ||
            !$divisionId ||
            !$smelterId ||
            $name === '' ||
            $link === ''
        ) {

            $error = 'Data Team belum lengkap.';

        } elseif (!filter_var($link, FILTER_VALIDATE_URL)) {

            $error = 'Format link tidak valid.';

        } else {

            /*
             * Pastikan Team ada.
             */

            $stmt = $pdo->prepare("
                SELECT id
                FROM teams
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $teamId
            ]);

            if (!$stmt->fetch()) {

                $error = 'Team tidak ditemukan.';

            } else {

                /*
                 * Validasi Smelter.
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

                if (!$stmt->fetch()) {

                    $error =
                        'Smelter tidak sesuai dengan Divisi.';

                } else {

                    /*
                     * Cek nama Team.
                     */

                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM teams
                        WHERE smelter_id = ?
                          AND name = ?
                          AND id != ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $smelterId,
                        $name,
                        $teamId
                    ]);

                    if ($stmt->fetch()) {

                        $error =
                            'Nama Team sudah digunakan.';

                    } else {

                        $stmt = $pdo->prepare("
                            UPDATE teams
                            SET
                                smelter_id = ?,
                                name = ?,
                                link = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $smelterId,
                            $name,
                            $link,
                            $teamId
                        ]);

                        $success =
                            'Team berhasil diperbarui.';
                    }
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Mode Edit
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['edit']) &&
    filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT
    )
) {

    $editId = (int) $_GET['edit'];

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

    $stmt->execute([
        $editId
    ]);

    $editTeam = $stmt->fetch();

    if (!$editTeam) {
        $error = 'Team tidak ditemukan.';
    }
}


/*
|--------------------------------------------------------------------------
| Ambil Division
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, name
    FROM divisions
    WHERE status = 'active'
    ORDER BY name
");

$divisions = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Ambil Semua Team
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        t.id,
        t.name AS team_name,
        t.link,
        t.status,
        s.id AS smelter_id,
        s.name AS smelter_name,
        d.id AS division_id,
        d.name AS division_name
    FROM teams t

    INNER JOIN smelters s
        ON s.id = t.smelter_id

    INNER JOIN divisions d
        ON d.id = s.division_id

    ORDER BY
        d.name,
        s.name,
        t.name
");

$teams = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Manajemen Team</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body class="bg-light">


<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <a
            href="dashboard.php"
            class="navbar-brand"
        >
            Smelter Management
        </a>

        <div class="text-white">

            <?= htmlspecialchars($_SESSION['name']) ?>

            &nbsp; | &nbsp;

            <a
                href="../auth/logout.php"
                class="text-white"
            >
                Logout
            </a>

        </div>

    </div>

</nav>


<div class="container py-4">


    <div class="d-flex justify-content-between align-items-center mb-4">

        <h2>
            Manajemen Team
        </h2>

        <a
            href="dashboard.php"
            class="btn btn-secondary"
        >
            Kembali
        </a>

    </div>


    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <?php if ($success): ?>

        <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
        </div>

    <?php endif; ?>


    <!-- FORM -->

    <div class="card shadow-sm mb-4">

        <div class="card-header">

            <strong>
                <?= $editTeam ? 'Edit Team' : 'Tambah Team' ?>
            </strong>

        </div>

        <div class="card-body">

            <form method="POST">

                <input
                    type="hidden"
                    name="action"
                    value="<?= $editTeam ? 'update' : 'create' ?>"
                >

                <?php if ($editTeam): ?>

                    <input
                        type="hidden"
                        name="team_id"
                        value="<?= $editTeam['id'] ?>"
                    >

                <?php endif; ?>


                <div class="row">


                    <!-- Division -->

                    <div class="col-md-4 mb-3">

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
                                    value="<?= $division['id'] ?>"
                                    <?= $editTeam &&
                                        $editTeam['division_id'] == $division['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= htmlspecialchars($division['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- Smelter -->

                    <div class="col-md-4 mb-3">

                        <label class="form-label">
                            Smelter
                        </label>

                        <select
                            name="smelter_id"
                            id="smelter_id"
                            class="form-select"
                            required
                            <?= !$editTeam ? 'disabled' : '' ?>
                        >

                            <?php if ($editTeam): ?>

                                <?php

                                $stmt = $pdo->prepare("
                                    SELECT id, name
                                    FROM smelters
                                    WHERE division_id = ?
                                      AND status = 'active'
                                    ORDER BY name
                                ");

                                $stmt->execute([
                                    $editTeam['division_id']
                                ]);

                                $editSmelters =
                                    $stmt->fetchAll();

                                ?>

                                <option value="">
                                    -- Pilih Smelter --
                                </option>

                                <?php foreach ($editSmelters as $smelter): ?>

                                    <option
                                        value="<?= $smelter['id'] ?>"
                                        <?= $editTeam['smelter_id'] == $smelter['id']
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars($smelter['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <option value="">
                                    -- Pilih Divisi terlebih dahulu --
                                </option>

                            <?php endif; ?>

                        </select>

                    </div>


                    <!-- Team -->

                    <div class="col-md-4 mb-3">

                        <label class="form-label">
                            Nama Team
                        </label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            value="<?= $editTeam
                                ? htmlspecialchars($editTeam['name'])
                                : '' ?>"
                            placeholder="Contoh: Team A"
                            required
                        >

                    </div>


                    <!-- Link -->

                    <div class="col-md-12 mb-3">

                        <label class="form-label">
                            Link Team
                        </label>

                        <input
                            type="url"
                            name="link"
                            class="form-control"
                            value="<?= $editTeam
                                ? htmlspecialchars($editTeam['link'])
                                : '' ?>"
                            placeholder="https://contoh.com"
                            required
                        >

                        <div class="form-text">
                            Setiap Team wajib memiliki satu link.
                        </div>

                    </div>

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <?= $editTeam ? 'Update Team' : 'Simpan Team' ?>
                </button>


                <?php if ($editTeam): ?>

                    <a
                        href="teams.php"
                        class="btn btn-secondary"
                    >
                        Batal
                    </a>

                <?php endif; ?>

            </form>

        </div>

    </div>


    <!-- LIST TEAM -->

    <div class="card shadow-sm">

        <div class="card-header">

            <strong>
                Daftar Team
            </strong>

        </div>

        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered table-hover">

                    <thead>

                        <tr>

                            <th>
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

                            <th>
                                Aksi
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$teams): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="text-center"
                            >
                                Belum ada Team.
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
                                            $team['team_name']
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
                                    >
                                        Buka Link
                                    </a>

                                </td>

                                <td>

                                    <?php if (
                                        $team['status'] === 'active'
                                    ): ?>

                                        <span class="badge bg-success">
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-secondary">
                                            Inactive
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <a
                                        href="?edit=<?= $team['id'] ?>"
                                        class="btn btn-sm btn-warning"
                                    >
                                        Edit
                                    </a>


                                    <form
                                        method="POST"
                                        class="d-inline"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="toggle_status"
                                        >

                                        <input
                                            type="hidden"
                                            name="team_id"
                                            value="<?= $team['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-secondary"
                                        >
                                            <?= $team['status'] === 'active'
                                                ? 'Nonaktifkan'
                                                : 'Aktifkan' ?>
                                        </button>

                                    </form>

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

const divisionSelect =
    document.getElementById('division_id');

const smelterSelect =
    document.getElementById('smelter_id');


divisionSelect.addEventListener(
    'change',
    async function () {

        const divisionId = this.value;

        smelterSelect.innerHTML =
            '<option value="">Memuat Smelter...</option>';

        smelterSelect.disabled = true;


        if (!divisionId) {

            smelterSelect.innerHTML =
                '<option value="">-- Pilih Divisi terlebih dahulu --</option>';

            return;
        }


        try {

            const response = await fetch(
                '../api/get-smelters.php?division_id=' +
                encodeURIComponent(divisionId)
            );

            const result =
                await response.json();


            if (!result.success) {
                throw new Error(result.message);
            }


            smelterSelect.innerHTML =
                '<option value="">-- Pilih Smelter --</option>';


            result.data.forEach(function (smelter) {

                const option =
                    document.createElement('option');

                option.value =
                    smelter.id;

                option.textContent =
                    smelter.name;

                smelterSelect.appendChild(option);

            });


            smelterSelect.disabled = false;

        } catch (error) {

            smelterSelect.innerHTML =
                '<option value="">Gagal memuat Smelter</option>';

            console.error(error);
        }

    }
);

</script>

</body>
</html>