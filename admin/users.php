<?php

require_once __DIR__ . '/../middleware/admin.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
| HELPER
|--------------------------------------------------------------------------
*/

function redirectUsers(string $type, string $message): void
{
    header(
        'Location: users?' .
        http_build_query([
            $type => $message
        ])
    );

    exit;
}


function getTargetUser(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.nik,
            u.name,
            u.email,
            u.status,
            u.division_id,
            u.approved_by,
            u.approved_at,
            u.rejected_reason,
            u.deleted_at,

            r.id AS role_id,
            r.name AS role_name,

            d.name AS division_name

        FROM users u

        INNER JOIN roles r
            ON r.id = u.role_id

        LEFT JOIN divisions d
            ON d.id = u.division_id

        WHERE u.id = ?

        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}


function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        throw new Exception(
            'Token keamanan tidak valid. Silakan ulangi.'
        );
    }
}


function isCurrentUser(array $user): bool
{
    return (int) $user['id'] === (int) $_SESSION['user_id'];
}


function isAdminRole(array $user): bool
{
    return strtolower(
        (string) $user['role_name']
    ) === 'admin';
}


/*
|--------------------------------------------------------------------------
| RESTORE / CREATE SPV ACCESS
|--------------------------------------------------------------------------
|
| Jika access lama berstatus revoked:
|   -> aktifkan kembali
|
| Jika belum pernah ada:
|   -> INSERT baru
|
| Jika sudah active:
|   -> throw exception
|--------------------------------------------------------------------------
*/

function restoreOrCreateSpvAccess(
    PDO $pdo,
    int $userId,
    int $smelterId,
    int $adminId
): void {

    /*
    |--------------------------------------------------------------------------
    | CARI ACCESS LAMA
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            status

        FROM user_access

        WHERE user_id = ?
          AND smelter_id = ?
          AND team_id IS NULL

        ORDER BY
            CASE
                WHEN status = 'active' THEN 0
                ELSE 1
            END,
            id DESC

        LIMIT 1

        FOR UPDATE
    ");

    $stmt->execute([
        $userId,
        $smelterId
    ]);

    $existingAccess =
        $stmt->fetch(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | SUDAH ACTIVE
    |--------------------------------------------------------------------------
    */

    if (
        $existingAccess &&
        $existingAccess['status'] === 'active'
    ) {

        throw new Exception(
            'SPV sudah memiliki akses ke Smelter tersebut.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PERNAH ADA TAPI REVOKED
    |--------------------------------------------------------------------------
    */

    if ($existingAccess) {

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
        ");

        $stmt->execute([
            $adminId,
            $existingAccess['id']
        ]);

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | BELUM PERNAH ADA
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
            created_at,
            updated_at
        )

        VALUES (
            ?,
            ?,
            NULL,
            'active',
            ?,
            NOW(),
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        $userId,
        $smelterId,
        $adminId
    ]);
}


/*
|--------------------------------------------------------------------------
| RESTORE / CREATE FOREMAN TEAM ACCESS
|--------------------------------------------------------------------------
|
| Jika access lama revoked:
|   -> aktifkan kembali
|
| Jika belum pernah ada:
|   -> INSERT
|
| Jika sudah active:
|   -> throw exception
|--------------------------------------------------------------------------
*/

function restoreOrCreateForemanAccess(
    PDO $pdo,
    int $userId,
    int $smelterId,
    int $teamId,
    int $adminId
): void {

    /*
    |--------------------------------------------------------------------------
    | CARI ACCESS LAMA
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            status

        FROM user_access

        WHERE user_id = ?
          AND smelter_id = ?
          AND team_id = ?

        ORDER BY
            CASE
                WHEN status = 'active' THEN 0
                ELSE 1
            END,
            id DESC

        LIMIT 1

        FOR UPDATE
    ");

    $stmt->execute([
        $userId,
        $smelterId,
        $teamId
    ]);

    $existingAccess =
        $stmt->fetch(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | SUDAH ACTIVE
    |--------------------------------------------------------------------------
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
    |--------------------------------------------------------------------------
    | PERNAH ADA TAPI REVOKED
    |--------------------------------------------------------------------------
    */

    if ($existingAccess) {

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
        ");

        $stmt->execute([
            $adminId,
            $existingAccess['id']
        ]);

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | BELUM PERNAH ADA
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
            created_at,
            updated_at
        )

        VALUES (
            ?,
            ?,
            ?,
            'active',
            ?,
            NOW(),
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        $userId,
        $smelterId,
        $teamId,
        $adminId
    ]);
}


/*
|--------------------------------------------------------------------------
| FLASH / MESSAGE
|--------------------------------------------------------------------------
*/

$success = trim($_GET['success'] ?? '');
$error   = trim($_GET['error'] ?? '');


/*
|--------------------------------------------------------------------------
| PROSES POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim($_POST['action'] ?? '');

    try {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        */

        verifyCsrf();


        /*
        |--------------------------------------------------------------------------
        | USER ID
        |--------------------------------------------------------------------------
        */

        $userId = filter_input(
            INPUT_POST,
            'user_id',
            FILTER_VALIDATE_INT
        );

        if (!$userId) {
            throw new Exception(
                'User tidak valid.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | AMBIL USER
        |--------------------------------------------------------------------------
        */

        $user = getTargetUser(
            $pdo,
            (int) $userId
        );

        if (!$user) {
            throw new Exception(
                'User tidak ditemukan.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | ADMIN TIDAK BOLEH DIPROSES
        |--------------------------------------------------------------------------
        */

        if (isAdminRole($user)) {
            throw new Exception(
                'Akun Admin tidak dapat diproses melalui halaman ini.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | PROTEKSI DIRI SENDIRI
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $action,
                ['suspend', 'delete'],
                true
            )
            &&
            isCurrentUser($user)
        ) {

            throw new Exception(
                'Anda tidak dapat menonaktifkan atau menghapus akun sendiri.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | APPROVE
        |--------------------------------------------------------------------------
        */

        if ($action === 'approve') {

            if ($user['status'] !== 'pending') {

                throw new Exception(
                    'User ini sudah tidak berstatus pending.'
                );
            }


            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | REQUEST AKSES AWAL
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

                    s.name AS smelter_name,
                    s.division_id AS smelter_division_id,
                    s.status AS smelter_status

                FROM access_requests ar

                INNER JOIN smelters s
                    ON s.id = ar.smelter_id

                WHERE ar.user_id = ?
                  AND ar.status = 'pending'

                ORDER BY ar.id ASC

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([
                $userId
            ]);

            $accessRequest =
                $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$accessRequest) {

                throw new Exception(
                    'Request akses awal user tidak ditemukan.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | VALIDASI DIVISI
            |--------------------------------------------------------------------------
            */

            if (
                (int) $accessRequest['smelter_division_id']
                !==
                (int) $user['division_id']
            ) {

                throw new Exception(
                    'Smelter tidak berada pada divisi user.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | SMELTER AKTIF
            |--------------------------------------------------------------------------
            */

            if (
                $accessRequest['smelter_status']
                !==
                'active'
            ) {

                throw new Exception(
                    'Smelter tujuan tidak aktif.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | ROLE
            |--------------------------------------------------------------------------
            */

            $roleName = strtolower(
                (string) $user['role_name']
            );


            /*
            |--------------------------------------------------------------------------
            | APPROVE SPV
            |--------------------------------------------------------------------------
            */

            if ($roleName === 'spv') {

                if (
                    $accessRequest['request_type']
                    !==
                    'additional_smelter'
                ) {

                    throw new Exception(
                        'Request akses SPV tidak valid.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | AKTIFKAN USER TERLEBIH DAHULU
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE users

                    SET
                        status = 'active',
                        approved_by = ?,
                        approved_at = NOW(),
                        rejected_reason = NULL,
                        deleted_at = NULL,
                        updated_at = NOW()

                    WHERE id = ?
                      AND status = 'pending'
                ");

                $stmt->execute([
                    $_SESSION['user_id'],
                    $userId
                ]);


                if ($stmt->rowCount() < 1) {

                    throw new Exception(
                        'Status user gagal diubah menjadi active.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | GRANT / RESTORE SMELTER ACCESS
                |--------------------------------------------------------------------------
                |
                | BAGIAN PENTING:
                |
                | Jika access lama revoked karena user pernah
                | dihapus, maka UPDATE menjadi active.
                |
                | Tidak INSERT ulang.
                |--------------------------------------------------------------------------
                */

                restoreOrCreateSpvAccess(
                    $pdo,
                    (int) $userId,
                    (int) $accessRequest['smelter_id'],
                    (int) $_SESSION['user_id']
                );


                /*
                |--------------------------------------------------------------------------
                | REQUEST APPROVED
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
                    $_SESSION['user_id'],
                    $accessRequest['id']
                ]);


                if ($stmt->rowCount() < 1) {

                    throw new Exception(
                        'Request akses gagal diubah menjadi approved.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | HISTORY
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
                    $userId,
                    $_SESSION['user_id'],
                    'SPV disetujui oleh Admin. Akses Smelter awal diberikan atau dipulihkan kembali.'
                ]);


                $pdo->commit();


                redirectUsers(
                    'success',
                    'SPV berhasil disetujui dan akses Smelter telah diberikan.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | APPROVE FOREMAN
            |--------------------------------------------------------------------------
            |
            | Admin hanya boleh approve Foreman apabila
            | belum ada SPV aktif yang mengontrol Smelter tersebut.
            |--------------------------------------------------------------------------
            */

            if ($roleName === 'foreman') {

                if (
                    $accessRequest['request_type']
                    !==
                    'additional_team'
                ) {

                    throw new Exception(
                        'Request akses Foreman tidak valid.'
                    );
                }


                if (
                    empty(
                        $accessRequest['team_id']
                    )
                ) {

                    throw new Exception(
                        'Team pada request Foreman tidak ditemukan.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | VALIDASI TEAM
                |--------------------------------------------------------------------------
                */

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

                $stmt->execute([
                    $accessRequest['team_id']
                ]);

                $team = $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


                if (!$team) {

                    throw new Exception(
                        'Team tidak ditemukan.'
                    );
                }


                if ($team['status'] !== 'active') {

                    throw new Exception(
                        'Team tidak aktif.'
                    );
                }


                if (
                    (int) $team['smelter_id']
                    !==
                    (int) $accessRequest['smelter_id']
                ) {

                    throw new Exception(
                        'Team tidak sesuai dengan Smelter.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | CEK DIVISI TEAM
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        division_id

                    FROM smelters

                    WHERE id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $accessRequest['smelter_id']
                ]);

                $smelterDivisionId =
                    $stmt->fetchColumn();


                if (
                    (int) $smelterDivisionId
                    !==
                    (int) $user['division_id']
                ) {

                    throw new Exception(
                        'Smelter dan user berada pada divisi yang berbeda.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | CEK SPV AKTIF
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        u.id

                    FROM users u

                    INNER JOIN roles r
                        ON r.id = u.role_id

                    INNER JOIN user_access ua
                        ON ua.user_id = u.id

                    WHERE LOWER(r.name) = 'spv'
                      AND u.status = 'active'
                      AND u.division_id = ?
                      AND ua.smelter_id = ?
                      AND ua.team_id IS NULL
                      AND ua.status = 'active'

                    LIMIT 1
                ");

                $stmt->execute([
                    $user['division_id'],
                    $accessRequest['smelter_id']
                ]);

                $activeSpv =
                    $stmt->fetchColumn();


                if ($activeSpv) {

                    throw new Exception(
                        'Smelter memiliki SPV aktif. Foreman harus disetujui oleh SPV.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | AKTIFKAN FOREMAN
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE users

                    SET
                        status = 'active',
                        approved_by = ?,
                        approved_at = NOW(),
                        rejected_reason = NULL,
                        deleted_at = NULL,
                        updated_at = NOW()

                    WHERE id = ?
                      AND status = 'pending'
                ");

                $stmt->execute([
                    $_SESSION['user_id'],
                    $userId
                ]);


                if ($stmt->rowCount() < 1) {

                    throw new Exception(
                        'Status Foreman gagal diubah menjadi active.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | GRANT / RESTORE TEAM ACCESS
                |--------------------------------------------------------------------------
                |
                | BAGIAN PENTING:
                |
                | Jika user pernah memiliki Team ini dan
                | access sudah revoked, UPDATE menjadi active.
                |
                | Tidak INSERT ulang.
                |--------------------------------------------------------------------------
                */

                restoreOrCreateForemanAccess(
                    $pdo,
                    (int) $userId,
                    (int) $accessRequest['smelter_id'],
                    (int) $accessRequest['team_id'],
                    (int) $_SESSION['user_id']
                );


                /*
                |--------------------------------------------------------------------------
                | REQUEST APPROVED
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
                    $_SESSION['user_id'],
                    $accessRequest['id']
                ]);


                if ($stmt->rowCount() < 1) {

                    throw new Exception(
                        'Request akses gagal diubah menjadi approved.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | HISTORY
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
                    $userId,
                    $_SESSION['user_id'],
                    'Foreman disetujui Admin karena belum terdapat SPV aktif pada Smelter. Akses Team diberikan atau dipulihkan kembali.'
                ]);


                $pdo->commit();


                redirectUsers(
                    'success',
                    'Foreman berhasil disetujui dan akses Team telah diberikan.'
                );
            }


            throw new Exception(
                'Role user tidak dapat diproses.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | REJECT
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'reject') {

            if ($user['status'] !== 'pending') {

                throw new Exception(
                    'Hanya user pending yang dapat ditolak.'
                );
            }


            $reason = trim(
                $_POST['rejected_reason'] ?? ''
            );


            if ($reason === '') {

                throw new Exception(
                    'Alasan penolakan wajib diisi.'
                );
            }


            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | REQUEST PENDING
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    id

                FROM access_requests

                WHERE user_id = ?
                  AND status = 'pending'

                ORDER BY id ASC

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([
                $userId
            ]);

            $accessRequest =
                $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$accessRequest) {

                throw new Exception(
                    'Request akses user tidak ditemukan.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | USER REJECTED
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE users

                SET
                    status = 'rejected',
                    rejected_reason = ?,
                    approved_by = ?,
                    approved_at = NULL,
                    updated_at = NOW()

                WHERE id = ?
                  AND status = 'pending'
            ");

            $stmt->execute([
                $reason,
                $_SESSION['user_id'],
                $userId
            ]);


            /*
            |--------------------------------------------------------------------------
            | REQUEST REJECTED
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE access_requests

                SET
                    status = 'rejected',
                    rejected_reason = ?,
                    approved_by = ?,
                    approved_at = NULL

                WHERE id = ?
                  AND status = 'pending'
            ");

            $stmt->execute([
                $reason,
                $_SESSION['user_id'],
                $accessRequest['id']
            ]);


            /*
            |--------------------------------------------------------------------------
            | HISTORY
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
                $userId,
                $_SESSION['user_id'],
                $reason
            ]);


            $pdo->commit();


            redirectUsers(
                'success',
                'User berhasil ditolak.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SUSPEND
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'suspend') {

            if ($user['status'] !== 'active') {

                throw new Exception(
                    'Hanya user active yang dapat di-suspend.'
                );
            }


            $reason = trim(
                $_POST['reason'] ?? ''
            );


            if ($reason === '') {

                throw new Exception(
                    'Alasan suspend wajib diisi.'
                );
            }


            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | USER SUSPENDED
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE users

                SET
                    status = 'suspended',
                    updated_at = NOW()

                WHERE id = ?
                  AND status = 'active'
            ");

            $stmt->execute([
                $userId
            ]);


            /*
            |--------------------------------------------------------------------------
            | REVOKE SEMUA ACCESS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE user_access

                SET
                    status = 'revoked',
                    revoked_by = ?,
                    revoked_at = NOW(),
                    updated_at = NOW()

                WHERE user_id = ?
                  AND status = 'active'
            ");

            $stmt->execute([
                $_SESSION['user_id'],
                $userId
            ]);


            /*
            |--------------------------------------------------------------------------
            | HISTORY
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
                    'suspended',
                    ?,
                    NOW()
                )
            ");

            $stmt->execute([
                $userId,
                $_SESSION['user_id'],
                'User di-suspend oleh Admin. Alasan: ' . $reason
            ]);


            $pdo->commit();


            redirectUsers(
                'success',
                'User berhasil di-suspend dan seluruh akses aktif telah dicabut.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | ACTIVATE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'activate') {

            if ($user['status'] !== 'suspended') {

                throw new Exception(
                    'Hanya user suspended yang dapat diaktifkan kembali.'
                );
            }


            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | AKTIFKAN USER
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE users

                SET
                    status = 'active',
                    updated_at = NOW()

                WHERE id = ?
                  AND status = 'suspended'
            ");

            $stmt->execute([
                $userId
            ]);


            /*
            |--------------------------------------------------------------------------
            | JANGAN PULIHKAN ACCESS LAMA
            |--------------------------------------------------------------------------
            |
            | Access yang sudah revoked tetap revoked.
            | Admin dapat memberikan access melalui
            | halaman Kelola Akses.
            |--------------------------------------------------------------------------
            */


            /*
            |--------------------------------------------------------------------------
            | HISTORY
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
                    'activated',
                    ?,
                    NOW()
                )
            ");

            $stmt->execute([
                $userId,
                $_SESSION['user_id'],
                'User diaktifkan kembali oleh Admin. Akses lama tetap revoked dan tidak dipulihkan otomatis.'
            ]);


            $pdo->commit();


            redirectUsers(
                'success',
                'User berhasil diaktifkan kembali. Akses lama tetap revoked.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | SOFT DELETE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'delete') {

            if ($user['status'] === 'deleted') {

                throw new Exception(
                    'User sudah berstatus deleted.'
                );
            }


            $reason = trim(
                $_POST['reason'] ?? ''
            );


            if ($reason === '') {

                throw new Exception(
                    'Alasan penghapusan wajib diisi.'
                );
            }


            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | SOFT DELETE USER
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE users

                SET
                    status = 'deleted',
                    deleted_at = NOW(),
                    updated_at = NOW()

                WHERE id = ?
                  AND status <> 'deleted'
            ");

            $stmt->execute([
                $userId
            ]);


            if ($stmt->rowCount() < 1) {

                throw new Exception(
                    'User gagal dihapus atau sudah berstatus deleted.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | REVOKE SEMUA ACCESS
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE user_access

                SET
                    status = 'revoked',
                    revoked_by = ?,
                    revoked_at = NOW(),
                    updated_at = NOW()

                WHERE user_id = ?
                  AND status = 'active'
            ");

            $stmt->execute([
                $_SESSION['user_id'],
                $userId
            ]);


            /*
            |--------------------------------------------------------------------------
            | CANCEL SEMUA PENDING ACCESS REQUEST
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
            | DELETION LOG
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO user_deletion_logs (
                    user_id,
                    deleted_by,
                    reason,
                    deleted_at
                )

                VALUES (
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ");

            $stmt->execute([
                $userId,
                $_SESSION['user_id'],
                $reason
            ]);


            /*
            |--------------------------------------------------------------------------
            | HISTORY
            |--------------------------------------------------------------------------
            |
            | Database saat ini menggunakan ENUM:
            |
            | approved
            | rejected
            | suspended
            | activated
            |
            | Karena action 'deleted' belum tersedia,
            | digunakan 'suspended' dengan note khusus.
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
                    'suspended',
                    ?,
                    NOW()
                )
            ");

            $stmt->execute([
                $userId,
                $_SESSION['user_id'],
                'USER DELETED / SOFT DELETE. User dihapus oleh Admin. Alasan: ' . $reason
            ]);


            $pdo->commit();


            redirectUsers(
                'success',
                'User berhasil dihapus secara soft-delete.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | ACTION TIDAK VALID
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

        redirectUsers(
            'error',
            $e->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| FILTER STATUS
|--------------------------------------------------------------------------
*/

$filterStatus = $_GET['status'] ?? 'all';

$allowedStatuses = [
    'all',
    'pending',
    'active',
    'rejected',
    'suspended',
    'deleted'
];

if (
    !in_array(
        $filterStatus,
        $allowedStatuses,
        true
    )
) {

    $filterStatus = 'all';
}


/*
|--------------------------------------------------------------------------
| FILTER ROLE
|--------------------------------------------------------------------------
*/

$filterRole = $_GET['role'] ?? 'all';

$allowedRoles = [
    'all',
    'admin',
    'spv',
    'foreman'
];

if (
    !in_array(
        $filterRole,
        $allowedRoles,
        true
    )
) {

    $filterRole = 'all';
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

$search = trim(
    $_GET['search'] ?? ''
);


/*
|--------------------------------------------------------------------------
| QUERY USERS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        u.id,
        u.nik,
        u.name,
        u.email,
        u.status,
        u.division_id,
        u.approved_at,
        u.rejected_reason,
        u.deleted_at,
        u.created_at,

        r.name AS role_name,

        d.name AS division_name,

        (
            SELECT COUNT(*)

            FROM user_access ua

            WHERE ua.user_id = u.id
              AND ua.status = 'active'
        ) AS active_access_count,

        (
            SELECT COUNT(*)

            FROM user_access ua

            WHERE ua.user_id = u.id
              AND ua.status = 'revoked'
        ) AS revoked_access_count

    FROM users u

    INNER JOIN roles r
        ON r.id = u.role_id

    LEFT JOIN divisions d
        ON d.id = u.division_id

    WHERE 1 = 1
";

$params = [];


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

if ($filterStatus !== 'all') {

    $sql .= "
        AND u.status = ?
    ";

    $params[] = $filterStatus;
}


/*
|--------------------------------------------------------------------------
| ROLE
|--------------------------------------------------------------------------
*/

if ($filterRole !== 'all') {

    $sql .= "
        AND LOWER(r.name) = ?
    ";

    $params[] = strtolower(
        $filterRole
    );
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sql .= "
        AND (
            u.nik LIKE ?
            OR u.name LIKE ?
            OR u.email LIKE ?
        )
    ";

    $searchValue =
        '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}


/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY

        CASE u.status

            WHEN 'pending'
                THEN 1

            WHEN 'active'
                THEN 2

            WHEN 'suspended'
                THEN 3

            WHEN 'rejected'
                THEN 4

            WHEN 'deleted'
                THEN 5

            ELSE 6

        END,

        u.created_at DESC
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$users = $stmt->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| STATUS COUNT
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        status,
        COUNT(*) AS total

    FROM users

    GROUP BY status
");


$statusCounts = [
    'pending'   => 0,
    'active'    => 0,
    'rejected'  => 0,
    'suspended' => 0,
    'deleted'   => 0
];


foreach (
    $stmt->fetchAll(PDO::FETCH_ASSOC)
    as $row
) {

    if (
        isset(
            $statusCounts[
                $row['status']
            ]
        )
    ) {

        $statusCounts[
            $row['status']
        ] = (int) $row['total'];
    }
}


$totalUsers =
    array_sum($statusCounts);


/*
|--------------------------------------------------------------------------
| BADGE STATUS
|--------------------------------------------------------------------------
*/

function statusBadge(string $status): string
{
    switch ($status) {

        case 'pending':

            return
                '<span class="badge bg-warning text-dark">
                    Pending
                </span>';

        case 'active':

            return
                '<span class="badge bg-success">
                    Active
                </span>';

        case 'suspended':

            return
                '<span class="badge bg-secondary">
                    Suspended
                </span>';

        case 'rejected':

            return
                '<span class="badge bg-danger">
                    Rejected
                </span>';

        case 'deleted':

            return
                '<span class="badge bg-dark">
                    Deleted
                </span>';

        default:

            return
                '<span class="badge bg-light text-dark">'
                . htmlspecialchars($status)
                . '</span>';
    }
}


/*
|--------------------------------------------------------------------------
| BADGE ROLE
|--------------------------------------------------------------------------
*/

function roleBadge(string $role): string
{
    switch (strtolower($role)) {

        case 'admin':

            return
                '<span class="badge bg-dark">
                    Admin
                </span>';

        case 'spv':

            return
                '<span class="badge bg-primary">
                    SPV
                </span>';

        case 'foreman':

            return
                '<span class="badge bg-info text-dark">
                    Foreman
                </span>';

        default:

            return
                '<span class="badge bg-secondary">'
                . htmlspecialchars($role)
                . '</span>';
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

    <title>Manajemen User</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            min-height: 100vh;
        }

        .summary-card {
            transition: .2s;
        }

        .summary-card:hover {
            transform: translateY(-2px);
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

        .action-buttons {
            min-width: 260px;
        }

    </style>

</head>


<body class="bg-light">


<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <a
            href="dashboard"
            class="navbar-brand"
        >
            Smelter Management
        </a>


        <div class="text-white">

            <?= htmlspecialchars(
                $_SESSION['name'] ?? 'Administrator'
            ) ?>

            &nbsp; | &nbsp;

            <a
                href="../auth/logout"
                class="text-white text-decoration-none"
            >
                Logout
            </a>

        </div>

    </div>

</nav>


<div class="container-fluid py-4">


<!-- =========================================================
     HEADER
========================================================= -->

<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h2 class="mb-1">
            Manajemen User
        </h2>

        <p class="text-muted mb-0">
            Kelola approval, status, dan akses user.
        </p>

    </div>


    <a
        href="dashboard"
        class="btn btn-secondary"
    >
        Kembali
    </a>

</div>


<!-- =========================================================
     ALERT ERROR
========================================================= -->

<?php if ($error !== ''): ?>

    <div
        class="alert alert-danger alert-dismissible fade show"
        role="alert"
    >

        <strong>Error:</strong>

        <?= htmlspecialchars($error) ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>


<!-- =========================================================
     ALERT SUCCESS
========================================================= -->

<?php if ($success !== ''): ?>

    <div
        class="alert alert-success alert-dismissible fade show"
        role="alert"
    >

        <?= htmlspecialchars($success) ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>


<!-- =========================================================
     STATUS SUMMARY
========================================================= -->

<div class="row g-3 mb-4">


    <!-- ALL -->

    <div class="col-6 col-md-2">

        <a
            href="users?status=all"
            class="text-decoration-none"
        >

            <div class="card shadow-sm summary-card">

                <div class="card-body">

                    <small class="text-muted">
                        Semua User
                    </small>

                    <h3 class="mb-0 text-dark">
                        <?= $totalUsers ?>
                    </h3>

                </div>

            </div>

        </a>

    </div>


    <!-- PENDING -->

    <div class="col-6 col-md-2">

        <a
            href="users?status=pending"
            class="text-decoration-none"
        >

            <div class="card shadow-sm border-warning summary-card">

                <div class="card-body">

                    <small class="text-muted">
                        Pending
                    </small>

                    <h3 class="mb-0 text-warning">
                        <?= $statusCounts['pending'] ?>
                    </h3>

                </div>

            </div>

        </a>

    </div>


    <!-- ACTIVE -->

    <div class="col-6 col-md-2">

        <a
            href="users?status=active"
            class="text-decoration-none"
        >

            <div class="card shadow-sm border-success summary-card">

                <div class="card-body">

                    <small class="text-muted">
                        Active
                    </small>

                    <h3 class="mb-0 text-success">
                        <?= $statusCounts['active'] ?>
                    </h3>

                </div>

            </div>

        </a>

    </div>


    <!-- SUSPENDED -->

    <div class="col-6 col-md-2">

        <a
            href="users?status=suspended"
            class="text-decoration-none"
        >

            <div class="card shadow-sm border-secondary summary-card">

                <div class="card-body">

                    <small class="text-muted">
                        Suspended
                    </small>

                    <h3 class="mb-0 text-secondary">
                        <?= $statusCounts['suspended'] ?>
                    </h3>

                </div>

            </div>

        </a>

    </div>


    <!-- REJECTED -->

    <div class="col-6 col-md-2">

        <a
            href="users?status=rejected"
            class="text-decoration-none"
        >

            <div class="card shadow-sm border-danger summary-card">

                <div class="card-body">

                    <small class="text-muted">
                        Rejected
                    </small>

                    <h3 class="mb-0 text-danger">
                        <?= $statusCounts['rejected'] ?>
                    </h3>

                </div>

            </div>

        </a>

    </div>


    <!-- DELETED -->

    <div class="col-6 col-md-2">

        <a
            href="users?status=deleted"
            class="text-decoration-none"
        >

            <div class="card shadow-sm border-dark summary-card">

                <div class="card-body">

                    <small class="text-muted">
                        Deleted
                    </small>

                    <h3 class="mb-0 text-dark">
                        <?= $statusCounts['deleted'] ?>
                    </h3>

                </div>

            </div>

        </a>

    </div>

</div>


<!-- =========================================================
     FILTER
========================================================= -->

<div class="card shadow-sm mb-4">

    <div class="card-body">

        <form
            method="GET"
            class="row g-3 align-items-end"
        >


            <!-- SEARCH -->

            <div class="col-md-4">

                <label class="form-label">
                    Cari User
                </label>

                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="NIK, nama, atau email"
                    value="<?= htmlspecialchars($search) ?>"
                >

            </div>


            <!-- STATUS -->

            <div class="col-md-3">

                <label class="form-label">
                    Status
                </label>

                <select
                    name="status"
                    class="form-select"
                >

                    <?php foreach (
                        $allowedStatuses
                        as $status
                    ): ?>

                        <option
                            value="<?= htmlspecialchars($status) ?>"
                            <?= $filterStatus === $status
                                ? 'selected'
                                : '' ?>
                        >

                            <?= $status === 'all'
                                ? 'Semua Status'
                                : ucfirst($status)
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- ROLE -->

            <div class="col-md-3">

                <label class="form-label">
                    Role
                </label>

                <select
                    name="role"
                    class="form-select"
                >

                    <?php foreach (
                        $allowedRoles
                        as $role
                    ): ?>

                        <option
                            value="<?= htmlspecialchars($role) ?>"
                            <?= $filterRole === $role
                                ? 'selected'
                                : '' ?>
                        >

                            <?php
                            if ($role === 'all') {
                                echo 'Semua Role';
                            } else {
                                echo strtoupper($role);
                            }
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- FILTER -->

            <div class="col-md-1">

                <button
                    type="submit"
                    class="btn btn-primary w-100"
                >
                    Cari
                </button>

            </div>


            <!-- RESET -->

            <div class="col-md-1">

                <a
                    href="users"
                    class="btn btn-outline-secondary w-100"
                >
                    Reset
                </a>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     USER TABLE
========================================================= -->

<div class="card shadow-sm">

    <div class="card-header d-flex justify-content-between align-items-center">

        <strong>
            Daftar User
        </strong>

        <span class="badge bg-secondary">
            <?= count($users) ?> user
        </span>

    </div>


    <div class="card-body">

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead class="table-dark">

                    <tr>

                        <th>NIK</th>

                        <th>User</th>

                        <th>Role</th>

                        <th>Divisi</th>

                        <th>Status</th>

                        <th>Akses</th>

                        <th>Dibuat</th>

                        <th class="action-buttons">
                            Aksi
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (!$users): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="text-center text-muted py-5"
                        >
                            Tidak ada user ditemukan.
                        </td>

                    </tr>

                <?php else: ?>


                    <?php foreach ($users as $user): ?>

                        <tr>


                            <!-- NIK -->

                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $user['nik']
                                    ) ?>
                                </strong>

                            </td>


                            <!-- USER -->

                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $user['name']
                                    ) ?>
                                </strong>

                                <br>

                                <small class="text-muted">
                                    <?= htmlspecialchars(
                                        $user['email']
                                    ) ?>
                                </small>

                            </td>


                            <!-- ROLE -->

                            <td>
                                <?= roleBadge(
                                    $user['role_name']
                                ) ?>
                            </td>


                            <!-- DIVISION -->

                            <td>

                                <?= htmlspecialchars(
                                    $user['division_name']
                                    ?? '-'
                                ) ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <?= statusBadge(
                                    $user['status']
                                ) ?>


                                <?php if (
                                    $user['status']
                                    ===
                                    'rejected'
                                    &&
                                    !empty(
                                        $user['rejected_reason']
                                    )
                                ): ?>

                                    <br>

                                    <small
                                        class="text-danger"
                                        title="<?= htmlspecialchars(
                                            $user['rejected_reason']
                                        ) ?>"
                                    >
                                        Alasan tersedia
                                    </small>

                                <?php endif; ?>


                                <?php if (
                                    $user['status']
                                    ===
                                    'deleted'
                                    &&
                                    !empty(
                                        $user['deleted_at']
                                    )
                                ): ?>

                                    <br>

                                    <small class="text-muted">

                                        <?= htmlspecialchars(
                                            date(
                                                'd-m-Y H:i',
                                                strtotime(
                                                    $user['deleted_at']
                                                )
                                            )
                                        ) ?>

                                    </small>

                                <?php endif; ?>

                            </td>


                            <!-- ACCESS -->

                            <td>

                                <span
                                    class="badge bg-success"
                                    title="Akses aktif"
                                >

                                    <?= (int)
                                        $user[
                                            'active_access_count'
                                        ] ?>

                                    aktif

                                </span>


                                <?php if (
                                    (int)
                                    $user[
                                        'revoked_access_count'
                                    ] > 0
                                ): ?>

                                    <br>

                                    <span
                                        class="badge bg-secondary mt-1"
                                        title="Akses revoked"
                                    >

                                        <?= (int)
                                            $user[
                                                'revoked_access_count'
                                            ] ?>

                                        revoked

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- CREATED -->

                            <td>

                                <small>

                                    <?= htmlspecialchars(
                                        date(
                                            'd-m-Y H:i',
                                            strtotime(
                                                $user['created_at']
                                            )
                                        )
                                    ) ?>

                                </small>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <div class="d-flex flex-wrap gap-1">


                                    <!-- DETAIL -->

                                    <a
                                        href="user-detail?id=<?= (int) $user['id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        Detail
                                    </a>


                                    <!-- KELOLA AKSES -->

                                    <?php if (
                                        strtolower(
                                            $user['role_name']
                                        ) !== 'admin'
                                        &&
                                        $user['status'] !== 'deleted'
                                    ): ?>

                                        <a
                                            href="user-access?id=<?= (int) $user['id'] ?>"
                                            class="btn btn-sm btn-outline-info"
                                        >
                                            Kelola Akses
                                        </a>

                                    <?php endif; ?>


                                    <!-- =================================================
                                         PENDING
                                    ================================================== -->

                                    <?php if (
                                        $user['status']
                                        ===
                                        'pending'
                                    ): ?>


                                        <?php

                                        /*
                                        |--------------------------------------------------------------------------
                                        | REQUEST PENDING
                                        |--------------------------------------------------------------------------
                                        */

                                        $stmtRequest =
                                            $pdo->prepare("
                                                SELECT
                                                    ar.id,
                                                    ar.smelter_id,
                                                    ar.team_id,
                                                    ar.request_type,
                                                    s.name AS smelter_name

                                                FROM access_requests ar

                                                INNER JOIN smelters s
                                                    ON s.id = ar.smelter_id

                                                WHERE ar.user_id = ?
                                                  AND ar.status = 'pending'

                                                ORDER BY ar.id ASC

                                                LIMIT 1
                                            ");

                                        $stmtRequest->execute([
                                            $user['id']
                                        ]);

                                        $pendingRequest =
                                            $stmtRequest->fetch(
                                                PDO::FETCH_ASSOC
                                            );


                                        /*
                                        |--------------------------------------------------------------------------
                                        | CEK SPV AKTIF
                                        |--------------------------------------------------------------------------
                                        */

                                        $activeSpvCount = 0;


                                        if (
                                            strtolower(
                                                $user['role_name']
                                            ) === 'foreman'
                                            &&
                                            $pendingRequest
                                        ) {

                                            $stmtSpv =
                                                $pdo->prepare("
                                                    SELECT COUNT(*)

                                                    FROM users spv

                                                    INNER JOIN roles spv_role
                                                        ON spv_role.id = spv.role_id

                                                    INNER JOIN user_access spv_access
                                                        ON spv_access.user_id = spv.id

                                                    WHERE LOWER(spv_role.name) = 'spv'
                                                      AND spv.status = 'active'
                                                      AND spv.division_id = ?
                                                      AND spv_access.smelter_id = ?
                                                      AND spv_access.team_id IS NULL
                                                      AND spv_access.status = 'active'
                                                ");

                                            $stmtSpv->execute([
                                                $user['division_id'],
                                                $pendingRequest[
                                                    'smelter_id'
                                                ]
                                            ]);

                                            $activeSpvCount =
                                                (int)
                                                $stmtSpv->fetchColumn();
                                        }


                                        /*
                                        |--------------------------------------------------------------------------
                                        | BOLEH APPROVE ADMIN?
                                        |--------------------------------------------------------------------------
                                        */

                                        $canAdminApprove =
                                            false;


                                        if (
                                            strtolower(
                                                $user['role_name']
                                            ) === 'spv'
                                            &&
                                            $pendingRequest
                                        ) {

                                            $canAdminApprove =
                                                $pendingRequest[
                                                    'request_type'
                                                ]
                                                ===
                                                'additional_smelter';

                                        } elseif (
                                            strtolower(
                                                $user['role_name']
                                            ) === 'foreman'
                                            &&
                                            $pendingRequest
                                            &&
                                            $activeSpvCount === 0
                                        ) {

                                            $canAdminApprove =
                                                $pendingRequest[
                                                    'request_type'
                                                ]
                                                ===
                                                'additional_team';
                                        }

                                        ?>


                                        <?php if (
                                            $canAdminApprove
                                        ): ?>

                                            <form
                                                method="POST"
                                                class="d-inline"
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
                                                    value="approve"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?= (int) $user['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-success"
                                                    onclick="return confirm('Approve user ini?')"
                                                >
                                                    Approve
                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <?php if (
                                                strtolower(
                                                    $user['role_name']
                                                )
                                                ===
                                                'foreman'
                                            ): ?>

                                                <span
                                                    class="badge bg-warning text-dark align-self-center"
                                                >
                                                    Menunggu SPV
                                                </span>

                                            <?php endif; ?>

                                        <?php endif; ?>


                                        <!-- REJECT -->

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#rejectModal"
                                            data-user-id="<?= (int) $user['id'] ?>"
                                            data-user-name="<?= htmlspecialchars(
                                                $user['name'],
                                                ENT_QUOTES
                                            ) ?>"
                                        >
                                            Reject
                                        </button>


                                    <?php endif; ?>


                                    <!-- =================================================
                                         ACTIVE
                                    ================================================== -->

                                    <?php if (
                                        $user['status']
                                        ===
                                        'active'
                                        &&
                                        strtolower(
                                            $user['role_name']
                                        )
                                        !==
                                        'admin'
                                        &&
                                        (int)
                                        $user['id']
                                        !==
                                        (int)
                                        $_SESSION['user_id']
                                    ): ?>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-warning"
                                            data-bs-toggle="modal"
                                            data-bs-target="#suspendModal"
                                            data-user-id="<?= (int) $user['id'] ?>"
                                            data-user-name="<?= htmlspecialchars(
                                                $user['name'],
                                                ENT_QUOTES
                                            ) ?>"
                                        >
                                            Suspend
                                        </button>

                                    <?php endif; ?>


                                    <!-- =================================================
                                         SUSPENDED
                                    ================================================== -->

                                    <?php if (
                                        $user['status']
                                        ===
                                        'suspended'
                                        &&
                                        strtolower(
                                            $user['role_name']
                                        )
                                        !==
                                        'admin'
                                    ): ?>

                                        <form
                                            method="POST"
                                            class="d-inline"
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
                                                value="activate"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int) $user['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-success"
                                                onclick="return confirm('Aktifkan kembali user ini? Akses lama tetap revoked.')"
                                            >
                                                Aktifkan
                                            </button>

                                        </form>

                                    <?php endif; ?>


                                    <!-- =================================================
                                         DELETE
                                    ================================================== -->

                                    <?php if (
                                        strtolower(
                                            $user['role_name']
                                        )
                                        !==
                                        'admin'
                                        &&
                                        (int)
                                        $user['id']
                                        !==
                                        (int)
                                        $_SESSION['user_id']
                                        &&
                                        $user['status']
                                        !==
                                        'deleted'
                                    ): ?>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteModal"
                                            data-user-id="<?= (int) $user['id'] ?>"
                                            data-user-name="<?= htmlspecialchars(
                                                $user['name'],
                                                ENT_QUOTES
                                            ) ?>"
                                        >
                                            Hapus
                                        </button>

                                    <?php endif; ?>


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


</div>


<!-- =========================================================
     MODAL REJECT
========================================================= -->

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
                        Tolak User
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
                        value="<?= htmlspecialchars(
                            $csrfToken
                        ) ?>"
                    >


                    <input
                        type="hidden"
                        name="action"
                        value="reject"
                    >


                    <input
                        type="hidden"
                        name="user_id"
                        id="reject_user_id"
                    >


                    <p class="mb-1">
                        Anda akan menolak user:
                    </p>


                    <strong id="reject_user_name"></strong>


                    <div class="mt-3">

                        <label class="form-label">
                            Alasan Penolakan
                        </label>

                        <textarea
                            name="rejected_reason"
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
                        Tolak User
                    </button>

                </div>


            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     MODAL SUSPEND
========================================================= -->

<div
    class="modal fade"
    id="suspendModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">


            <form method="POST">


                <div class="modal-header">

                    <h5 class="modal-title">
                        Suspend User
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
                        value="<?= htmlspecialchars(
                            $csrfToken
                        ) ?>"
                    >


                    <input
                        type="hidden"
                        name="action"
                        value="suspend"
                    >


                    <input
                        type="hidden"
                        name="user_id"
                        id="suspend_user_id"
                    >


                    <p class="mb-1">
                        Anda akan men-suspend user:
                    </p>


                    <strong id="suspend_user_name"></strong>


                    <div class="alert alert-warning mt-3">

                        <strong>Perhatian!</strong>

                        <br>

                        Semua akses Smelter/Team aktif user
                        akan dicabut.

                        <br><br>

                        Jika user diaktifkan kembali,
                        akses lama
                        <strong>tidak</strong>
                        akan dipulihkan otomatis.

                    </div>


                    <div class="mt-3">

                        <label class="form-label">
                            Alasan Suspend
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
                        class="btn btn-warning"
                    >
                        Suspend User
                    </button>

                </div>


            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     MODAL DELETE
========================================================= -->

<div
    class="modal fade"
    id="deleteModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">


            <form method="POST">


                <div class="modal-header">

                    <h5 class="modal-title text-danger">
                        Hapus User
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
                        value="<?= htmlspecialchars(
                            $csrfToken
                        ) ?>"
                    >


                    <input
                        type="hidden"
                        name="action"
                        value="delete"
                    >


                    <input
                        type="hidden"
                        name="user_id"
                        id="delete_user_id"
                    >


                    <p class="mb-1">
                        Anda akan menghapus user:
                    </p>


                    <strong id="delete_user_name"></strong>


                    <div class="alert alert-danger mt-3">

                        <strong>Perhatian!</strong>

                        <br>

                        User akan menjadi
                        <strong>Deleted</strong>.

                        <br>

                        Seluruh akses aktif akan dicabut.

                        <br><br>

                        Data user tidak dihapus secara fisik
                        dari database.

                    </div>


                    <div class="mt-3">

                        <label class="form-label">
                            Alasan Penghapusan
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
                        Hapus User
                    </button>

                </div>


            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     BOOTSTRAP
========================================================= -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

/*
|--------------------------------------------------------------------------
| REJECT MODAL
|--------------------------------------------------------------------------
*/

const rejectModal =
    document.getElementById('rejectModal');

if (rejectModal) {

    rejectModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button =
                event.relatedTarget;

            const userId =
                button.getAttribute(
                    'data-user-id'
                );

            const userName =
                button.getAttribute(
                    'data-user-name'
                );


            document.getElementById(
                'reject_user_id'
            ).value = userId;


            document.getElementById(
                'reject_user_name'
            ).textContent = userName;

        }
    );
}


/*
|--------------------------------------------------------------------------
| SUSPEND MODAL
|--------------------------------------------------------------------------
*/

const suspendModal =
    document.getElementById('suspendModal');

if (suspendModal) {

    suspendModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button =
                event.relatedTarget;

            const userId =
                button.getAttribute(
                    'data-user-id'
                );

            const userName =
                button.getAttribute(
                    'data-user-name'
                );


            document.getElementById(
                'suspend_user_id'
            ).value = userId;


            document.getElementById(
                'suspend_user_name'
            ).textContent = userName;

        }
    );
}


/*
|--------------------------------------------------------------------------
| DELETE MODAL
|--------------------------------------------------------------------------
*/

const deleteModal =
    document.getElementById('deleteModal');

if (deleteModal) {

    deleteModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button =
                event.relatedTarget;

            const userId =
                button.getAttribute(
                    'data-user-id'
                );

            const userName =
                button.getAttribute(
                    'data-user-name'
                );


            document.getElementById(
                'delete_user_id'
            ).value = userId;


            document.getElementById(
                'delete_user_name'
            ).textContent = userName;

        }
    );
}

</script>


</body>

</html>