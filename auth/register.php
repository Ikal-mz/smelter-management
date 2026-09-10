<?php

session_start();

require_once __DIR__ . '/../config/database.php';

$error = '';
$success = '';

$old = [
    'nik' => '',
    'name' => '',
    'email' => '',
    'role' => '',
    'division_id' => '',
    'smelter_id' => '',
    'team_id' => ''
];


/*
|--------------------------------------------------------------------------
| Ambil Division
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, name
    FROM divisions
    WHERE status = 'active'
    ORDER BY name ASC
");

$divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Proses Registration
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old['nik'] = trim($_POST['nik'] ?? '');
    $old['name'] = trim($_POST['name'] ?? '');
    $old['email'] = trim($_POST['email'] ?? '');
    $old['role'] = trim($_POST['role'] ?? '');

    $old['division_id'] = filter_input(
        INPUT_POST,
        'division_id',
        FILTER_VALIDATE_INT
    ) ?: '';

    $old['smelter_id'] = filter_input(
        INPUT_POST,
        'smelter_id',
        FILTER_VALIDATE_INT
    ) ?: '';

    $old['team_id'] = filter_input(
        INPUT_POST,
        'team_id',
        FILTER_VALIDATE_INT
    ) ?: '';

    $password = $_POST['password'] ?? '';
    $passwordConfirmation =
        $_POST['password_confirmation'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | Normalisasi Email
    |--------------------------------------------------------------------------
    */

    $old['email'] = strtolower(
        $old['email']
    );


    /*
    |--------------------------------------------------------------------------
    | Basic Validation
    |--------------------------------------------------------------------------
    */

    if (
        $old['nik'] === '' ||
        $old['name'] === '' ||
        $old['email'] === '' ||
        $old['role'] === '' ||
        !$old['division_id'] ||
        !$old['smelter_id'] ||
        $password === '' ||
        $passwordConfirmation === ''
    ) {

        $error =
            'Semua field wajib diisi.';

    } elseif (
        !in_array(
            $old['role'],
            ['spv', 'foreman'],
            true
        )
    ) {

        $error =
            'Role registrasi tidak valid.';

    } elseif (
        !filter_var(
            $old['email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $error =
            'Format email tidak valid.';

    } elseif (
        strlen($password) < 8
    ) {

        $error =
            'Password minimal 8 karakter.';

    } elseif (
        $password !== $passwordConfirmation
    ) {

        $error =
            'Konfirmasi password tidak sama.';
    }


    /*
    |--------------------------------------------------------------------------
    | Validasi Division
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        $stmt = $pdo->prepare("
            SELECT id
            FROM divisions
            WHERE id = ?
              AND status = 'active'
            LIMIT 1
        ");

        $stmt->execute([
            $old['division_id']
        ]);

        if (!$stmt->fetch()) {

            $error =
                'Division tidak valid.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Validasi Smelter
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        $stmt = $pdo->prepare("
            SELECT id
            FROM smelters
            WHERE id = ?
              AND division_id = ?
              AND status = 'active'
            LIMIT 1
        ");

        $stmt->execute([
            $old['smelter_id'],
            $old['division_id']
        ]);

        if (!$stmt->fetch()) {

            $error =
                'Smelter tidak sesuai dengan Division.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Validasi Team khusus Foreman
    |--------------------------------------------------------------------------
    */

    if (
        $error === '' &&
        $old['role'] === 'foreman'
    ) {

        if (!$old['team_id']) {

            $error =
                'Team wajib dipilih untuk Foreman.';

        } else {

            $stmt = $pdo->prepare("
                SELECT id
                FROM teams
                WHERE id = ?
                  AND smelter_id = ?
                  AND status = 'active'
                LIMIT 1
            ");

            $stmt->execute([
                $old['team_id'],
                $old['smelter_id']
            ]);

            if (!$stmt->fetch()) {

                $error =
                    'Team tidak sesuai dengan Smelter.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SPV tidak boleh mengirim Team
    |--------------------------------------------------------------------------
    */

    if (
        $error === '' &&
        $old['role'] === 'spv' &&
        $old['team_id']
    ) {

        $error =
            'SPV tidak menggunakan Team.';
    }


    /*
    |--------------------------------------------------------------------------
    | CEK NIK DAN EMAIL
    |--------------------------------------------------------------------------
    |
    | Kita harus menangani 3 kemungkinan:
    |
    | 1. NIK dan email tidak ditemukan
    |    -> registrasi baru
    |
    | 2. NIK/email ditemukan pada user DELETED
    |    -> boleh registrasi ulang
    |
    | 3. NIK/email ditemukan pada user aktif/pending/suspended/rejected
    |    -> ditolak
    |
    |--------------------------------------------------------------------------
    */

    $existingByNik = null;
    $existingByEmail = null;


    if ($error === '') {

        /*
        |--------------------------------------------------------------------------
        | Cari berdasarkan NIK
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
                nik,
                email,
                status,
                role_id,
                division_id

            FROM users

            WHERE nik = ?

            LIMIT 1
        ");

        $stmt->execute([
            $old['nik']
        ]);

        $existingByNik =
            $stmt->fetch(PDO::FETCH_ASSOC);


        /*
        |--------------------------------------------------------------------------
        | Cari berdasarkan Email
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                id,
                nik,
                email,
                status,
                role_id,
                division_id

            FROM users

            WHERE email = ?

            LIMIT 1
        ");

        $stmt->execute([
            $old['email']
        ]);

        $existingByEmail =
            $stmt->fetch(PDO::FETCH_ASSOC);


        /*
        |--------------------------------------------------------------------------
        | NIK DAN EMAIL MENUNJUK USER YANG BERBEDA
        |--------------------------------------------------------------------------
        */

        if (
            $existingByNik &&
            $existingByEmail &&
            (int) $existingByNik['id']
            !==
            (int) $existingByEmail['id']
        ) {

            $error =
                'NIK dan Email sudah digunakan oleh user yang berbeda. Silakan periksa kembali data Anda.';
        }


        /*
        |--------------------------------------------------------------------------
        | NIK SUDAH DIGUNAKAN
        |--------------------------------------------------------------------------
        */

        if (
            $error === '' &&
            $existingByNik &&
            (
                !$existingByEmail ||
                (int) $existingByNik['id']
                !==
                (int) $existingByEmail['id']
            )
        ) {

            if (
                $existingByNik['status']
                ===
                'deleted'
            ) {

                $error =
                    'NIK tersebut merupakan data user deleted, tetapi Email yang digunakan tidak sesuai dengan data lama. Gunakan NIK dan Email lama yang sama.';

            } else {

                $error =
                    'NIK sudah terdaftar.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | EMAIL SUDAH DIGUNAKAN
        |--------------------------------------------------------------------------
        */

        if (
            $error === '' &&
            $existingByEmail &&
            (
                !$existingByNik ||
                (int) $existingByEmail['id']
                !==
                (int) $existingByNik['id']
            )
        ) {

            if (
                $existingByEmail['status']
                ===
                'deleted'
            ) {

                $error =
                    'Email tersebut merupakan data user deleted, tetapi NIK yang digunakan tidak sesuai dengan data lama. Gunakan NIK dan Email lama yang sama.';

            } else {

                $error =
                    'Email sudah terdaftar.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | USER LAMA DITEMUKAN
        |--------------------------------------------------------------------------
        */

        if (
            $error === '' &&
            $existingByNik &&
            $existingByEmail &&
            (int) $existingByNik['id']
            ===
            (int) $existingByEmail['id']
        ) {

            $existingUser =
                $existingByNik;


            /*
            |--------------------------------------------------------------------------
            | HANYA USER DELETED BOLEH REGISTER ULANG
            |--------------------------------------------------------------------------
            */

            if (
                $existingUser['status']
                !==
                'deleted'
            ) {

                switch (
                    $existingUser['status']
                ) {

                    case 'pending':

                        $error =
                            'NIK dan Email sedang menunggu persetujuan. Silakan tunggu proses approval.';

                        break;

                    case 'active':

                        $error =
                            'NIK dan Email masih digunakan oleh akun yang aktif.';

                        break;

                    case 'suspended':

                        $error =
                            'Akun Anda sedang di-suspend. Silakan hubungi Admin.';

                        break;

                    case 'rejected':

                        $error =
                            'Registrasi sebelumnya ditolak. Silakan hubungi Admin jika ingin melakukan registrasi kembali.';

                        break;

                    default:

                        $error =
                            'NIK atau Email sudah terdaftar.';
                        break;
                }
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SIMPAN REGISTRASI
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {

            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Ambil Role ID
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT id
                FROM roles
                WHERE LOWER(name) = ?
                LIMIT 1
            ");

            $stmt->execute([
                strtolower($old['role'])
            ]);

            $role = $stmt->fetch(
                PDO::FETCH_ASSOC
            );


            if (!$role) {

                throw new Exception(
                    'Role tidak ditemukan.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Password Hash
            |--------------------------------------------------------------------------
            */

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


            /*
            |--------------------------------------------------------------------------
            | REGISTRASI ULANG USER DELETED
            |--------------------------------------------------------------------------
            */

            if (
                isset($existingUser) &&
                $existingUser['status']
                ===
                'deleted'
            ) {

                $userId =
                    (int) $existingUser['id'];


                /*
                |--------------------------------------------------------------------------
                | Batalkan request pending lama
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE access_requests

                    SET
                        status = 'cancelled'

                    WHERE user_id = ?
                      AND status = 'pending'
                ");

                $stmt->execute([
                    $userId
                ]);


                /*
                |--------------------------------------------------------------------------
                | Pastikan tidak ada access aktif
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE user_access

                    SET
                        status = 'revoked',
                        revoked_at = COALESCE(
                            revoked_at,
                            NOW()
                        ),
                        updated_at = NOW()

                    WHERE user_id = ?
                      AND status = 'active'
                ");

                $stmt->execute([
                    $userId
                ]);


                /*
                |--------------------------------------------------------------------------
                | Update User Lama
                |--------------------------------------------------------------------------
                |
                | ID USER TETAP SAMA.
                |
                | Histori user tetap tersimpan.
                |
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE users

                    SET
                        name = ?,
                        email = ?,
                        password = ?,
                        role_id = ?,
                        division_id = ?,
                        status = 'pending',
                        approved_by = NULL,
                        approved_at = NULL,
                        rejected_reason = NULL,
                        deleted_at = NULL,
                        updated_at = NOW()

                    WHERE id = ?
                      AND status = 'deleted'
                ");

                $stmt->execute([
                    $old['name'],
                    $old['email'],
                    $passwordHash,
                    $role['id'],
                    $old['division_id'],
                    $userId
                ]);


                /*
                |--------------------------------------------------------------------------
                | Buat Request Approval Baru
                |--------------------------------------------------------------------------
                */

                if (
                    $old['role']
                    ===
                    'spv'
                ) {

                    $stmt = $pdo->prepare("
                        INSERT INTO access_requests (
                            user_id,
                            smelter_id,
                            team_id,
                            request_type,
                            status,
                            requested_reason
                        )

                        VALUES (
                            ?,
                            ?,
                            NULL,
                            'additional_smelter',
                            'pending',
                            ?
                        )
                    ");

                    $stmt->execute([
                        $userId,
                        $old['smelter_id'],
                        'Registrasi ulang user deleted.'
                    ]);

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO access_requests (
                            user_id,
                            smelter_id,
                            team_id,
                            request_type,
                            status,
                            requested_reason
                        )

                        VALUES (
                            ?,
                            ?,
                            ?,
                            'additional_team',
                            'pending',
                            ?
                        )
                    ");

                    $stmt->execute([
                        $userId,
                        $old['smelter_id'],
                        $old['team_id'],
                        'Registrasi ulang user deleted.'
                    ]);
                }


                $pdo->commit();


                $success =
                    'Registrasi ulang berhasil. Data akun lama tetap dipertahankan dan akun kembali berstatus pending untuk menunggu persetujuan Admin/SPV.';


                /*
                |--------------------------------------------------------------------------
                | Reset Form
                |--------------------------------------------------------------------------
                */

                $old = [
                    'nik' => '',
                    'name' => '',
                    'email' => '',
                    'role' => '',
                    'division_id' => '',
                    'smelter_id' => '',
                    'team_id' => ''
                ];

            } else {


                /*
                |--------------------------------------------------------------------------
                | REGISTRASI USER BARU
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        nik,
                        name,
                        email,
                        password,
                        role_id,
                        division_id,
                        status
                    )

                    VALUES (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'pending'
                    )
                ");

                $stmt->execute([
                    $old['nik'],
                    $old['name'],
                    $old['email'],
                    $passwordHash,
                    $role['id'],
                    $old['division_id']
                ]);


                $userId =
                    $pdo->lastInsertId();


                /*
                |--------------------------------------------------------------------------
                | Request Akses Awal
                |--------------------------------------------------------------------------
                */

                if (
                    $old['role']
                    ===
                    'spv'
                ) {

                    $stmt = $pdo->prepare("
                        INSERT INTO access_requests (
                            user_id,
                            smelter_id,
                            team_id,
                            request_type,
                            status,
                            requested_reason
                        )

                        VALUES (
                            ?,
                            ?,
                            NULL,
                            'additional_smelter',
                            'pending',
                            ?
                        )
                    ");

                    $stmt->execute([
                        $userId,
                        $old['smelter_id'],
                        'Registrasi awal SPV.'
                    ]);

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO access_requests (
                            user_id,
                            smelter_id,
                            team_id,
                            request_type,
                            status,
                            requested_reason
                        )

                        VALUES (
                            ?,
                            ?,
                            ?,
                            'additional_team',
                            'pending',
                            ?
                        )
                    ");

                    $stmt->execute([
                        $userId,
                        $old['smelter_id'],
                        $old['team_id'],
                        'Registrasi awal Foreman.'
                    ]);
                }


                $pdo->commit();


                $success =
                    'Registrasi berhasil. Akun Anda sedang menunggu persetujuan.';


                /*
                |--------------------------------------------------------------------------
                | Reset Form
                |--------------------------------------------------------------------------
                */

                $old = [
                    'nik' => '',
                    'name' => '',
                    'email' => '',
                    'role' => '',
                    'division_id' => '',
                    'smelter_id' => '',
                    'team_id' => ''
                ];
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }


            /*
            |--------------------------------------------------------------------------
            | Tampilkan error database yang lebih aman
            |--------------------------------------------------------------------------
            */

            if (
                $e instanceof PDOException &&
                $e->getCode() === '23000'
            ) {

                $error =
                    'NIK atau Email sudah digunakan oleh akun lain. Silakan periksa kembali data Anda.';

            } else {

                $error =
                    'Registrasi gagal. Silakan coba kembali.';
            }
        }
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

    <title>
        Registrasi - Smelter Management
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>


<body class="bg-light">


<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-lg-7">

            <div class="card shadow">

                <div class="card-body p-4">


                    <h3 class="mb-4 text-center">
                        Registrasi Akun
                    </h3>


                    <?php if ($error): ?>

                        <div class="alert alert-danger">

                            <?= htmlspecialchars(
                                $error
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <?php if ($success): ?>

                        <div class="alert alert-success">

                            <?= htmlspecialchars(
                                $success
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <form
                        method="POST"
                        id="registerForm"
                    >


                        <!-- =====================================================
                             NIK
                        ====================================================== -->

                        <div class="mb-3">

                            <label class="form-label">
                                NIK Karyawan
                            </label>

                            <input
                                type="text"
                                name="nik"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $old['nik']
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- =====================================================
                             NAMA
                        ====================================================== -->

                        <div class="mb-3">

                            <label class="form-label">
                                Nama Lengkap Karyawan
                            </label>

                            <input
                                type="text"
                                name="name"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $old['name']
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- =====================================================
                             ROLE
                        ====================================================== -->

                        <div class="mb-3">

                            <label class="form-label">
                                Tingkatan / Role
                            </label>

                            <select
                                name="role"
                                id="role"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    -- Pilih Role --
                                </option>

                                <option
                                    value="spv"
                                    <?= $old['role'] === 'spv'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    SPV
                                </option>

                                <option
                                    value="foreman"
                                    <?= $old['role'] === 'foreman'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Foreman
                                </option>

                            </select>

                        </div>


                        <!-- =====================================================
                             DIVISION
                        ====================================================== -->

                        <div class="mb-3">

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

                                <?php foreach (
                                    $divisions
                                    as $division
                                ): ?>

                                    <option
                                        value="<?= (int) $division['id'] ?>"
                                        <?= (string)
                                            $old['division_id']
                                            ===
                                            (string)
                                            $division['id']
                                            ? 'selected'
                                            : '' ?>
                                    >

                                        <?= htmlspecialchars(
                                            $division['name']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- =====================================================
                             SMELTER
                        ====================================================== -->

                        <div class="mb-3">

                            <label class="form-label">
                                Smelter
                            </label>

                            <select
                                name="smelter_id"
                                id="smelter_id"
                                class="form-select"
                                required
                                disabled
                            >

                                <option value="">
                                    -- Pilih Divisi terlebih dahulu --
                                </option>

                            </select>

                        </div>


                        <!-- =====================================================
                             TEAM
                        ====================================================== -->

                        <div
                            class="mb-3"
                            id="teamContainer"
                        >

                            <label class="form-label">
                                Tim
                            </label>

                            <select
                                name="team_id"
                                id="team_id"
                                class="form-select"
                                disabled
                            >

                                <option value="">
                                    -- Pilih Smelter terlebih dahulu --
                                </option>

                            </select>

                        </div>


                        <!-- =====================================================
                             EMAIL
                        ====================================================== -->

                        <div class="mb-3">

                            <label class="form-label">
                                Email
                            </label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $old['email']
                                ) ?>"
                                required
                            >

                        </div>


                        <!-- =====================================================
                             PASSWORD
                        ====================================================== -->

                        <div class="mb-3">

                            <label class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                minlength="8"
                                required
                            >

                        </div>


                        <!-- =====================================================
                             CONFIRM PASSWORD
                        ====================================================== -->

                        <div class="mb-4">

                            <label class="form-label">
                                Konfirmasi Password
                            </label>

                            <input
                                type="password"
                                name="password_confirmation"
                                class="form-control"
                                minlength="8"
                                required
                            >

                        </div>


                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >
                            Daftar
                        </button>


                    </form>


                    <div class="text-center mt-3">

                        <a href="login">
                            Sudah punya akun? Login
                        </a>

                    </div>


                </div>

            </div>

        </div>

    </div>

</div>


<script>

const roleSelect =
    document.getElementById('role');

const divisionSelect =
    document.getElementById('division_id');

const smelterSelect =
    document.getElementById('smelter_id');

const teamSelect =
    document.getElementById('team_id');

const teamContainer =
    document.getElementById('teamContainer');


/*
|--------------------------------------------------------------------------
| Tampilkan / sembunyikan Team
|--------------------------------------------------------------------------
*/

function updateRoleFields() {

    if (
        roleSelect.value === 'foreman'
    ) {

        teamContainer.style.display =
            'block';

        teamSelect.required = true;

    } else {

        teamContainer.style.display =
            'none';

        teamSelect.required = false;

        teamSelect.value = '';
    }
}


/*
|--------------------------------------------------------------------------
| Load Smelter
|--------------------------------------------------------------------------
*/

async function loadSmelters(
    divisionId
) {

    smelterSelect.innerHTML =
        '<option value="">Memuat Smelter...</option>';

    smelterSelect.disabled = true;


    teamSelect.innerHTML =
        '<option value="">-- Pilih Smelter terlebih dahulu --</option>';

    teamSelect.disabled = true;


    if (!divisionId) {

        smelterSelect.innerHTML =
            '<option value="">-- Pilih Divisi terlebih dahulu --</option>';

        return;
    }


    try {

        const response =
            await fetch(
                '../api/get-smelters?division_id=' +
                encodeURIComponent(
                    divisionId
                )
            );


        const result =
            await response.json();


        if (!result.success) {

            throw new Error(
                result.message
            );
        }


        smelterSelect.innerHTML =
            '<option value="">-- Pilih Smelter --</option>';


        result.data.forEach(
            function (smelter) {

                const option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    smelter.id;

                option.textContent =
                    smelter.name;

                smelterSelect.appendChild(
                    option
                );
            }
        );


        smelterSelect.disabled =
            false;

    } catch (error) {

        smelterSelect.innerHTML =
            '<option value="">Gagal memuat Smelter</option>';

        console.error(
            'Load Smelter Error:',
            error
        );
    }
}


/*
|--------------------------------------------------------------------------
| Load Team
|--------------------------------------------------------------------------
*/

async function loadTeams(
    smelterId
) {

    teamSelect.innerHTML =
        '<option value="">Memuat Team...</option>';

    teamSelect.disabled = true;


    if (!smelterId) {

        teamSelect.innerHTML =
            '<option value="">-- Pilih Smelter terlebih dahulu --</option>';

        return;
    }


    try {

        const response =
            await fetch(
                '../api/get-register-teams?smelter_id=' +
                encodeURIComponent(
                    smelterId
                )
            );


        const result =
            await response.json();


        if (!result.success) {

            throw new Error(
                result.message
            );
        }


        teamSelect.innerHTML =
            '<option value="">-- Pilih Team --</option>';


        result.data.forEach(
            function (team) {

                const option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    team.id;

                option.textContent =
                    team.name;

                teamSelect.appendChild(
                    option
                );
            }
        );


        teamSelect.disabled =
            false;

    } catch (error) {

        teamSelect.innerHTML =
            '<option value="">Gagal memuat Team</option>';

        console.error(
            'Load Team Error:',
            error
        );
    }
}


/*
|--------------------------------------------------------------------------
| Event Role
|--------------------------------------------------------------------------
*/

roleSelect.addEventListener(
    'change',
    updateRoleFields
);


/*
|--------------------------------------------------------------------------
| Event Division
|--------------------------------------------------------------------------
*/

divisionSelect.addEventListener(
    'change',
    function () {

        loadSmelters(
            this.value
        );

    }
);


/*
|--------------------------------------------------------------------------
| Event Smelter
|--------------------------------------------------------------------------
*/

smelterSelect.addEventListener(
    'change',
    function () {

        if (
            roleSelect.value === 'foreman'
        ) {

            loadTeams(
                this.value
            );
        }

    }
);


/*
|--------------------------------------------------------------------------
| Initial State
|--------------------------------------------------------------------------
*/

updateRoleFields();

</script>


</body>

</html>