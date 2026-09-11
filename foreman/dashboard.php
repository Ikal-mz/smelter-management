<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/foreman.php';


/*
|--------------------------------------------------------------------------
| User Login
|--------------------------------------------------------------------------
*/

$userId = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| Ambil informasi Foreman dari database
|--------------------------------------------------------------------------
|
| Jangan mempercayai data role/status yang hanya berasal dari session.
| Kita validasi ulang dari database.
|
*/

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.nik,
        u.name,
        u.email,
        u.division_id,
        u.status,
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


/*
|--------------------------------------------------------------------------
| User tidak ditemukan
|--------------------------------------------------------------------------
*/

if (!$user) {

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header('Location: ../auth/login');

    exit;
}


/*
|--------------------------------------------------------------------------
| Validasi status
|--------------------------------------------------------------------------
*/

if ($user['status'] !== 'active') {

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header('Location: ../auth/login');

    exit;
}


/*
|--------------------------------------------------------------------------
| Validasi Role
|--------------------------------------------------------------------------
*/

if ($user['role_name'] !== 'foreman') {

    http_response_code(403);

    exit(
        '403 - Anda tidak memiliki akses ke halaman Foreman.'
    );
}


/*
|--------------------------------------------------------------------------
| Validasi Division
|--------------------------------------------------------------------------
*/

$divisionId = (int) ($user['division_id'] ?? 0);

if ($divisionId <= 0) {

    http_response_code(403);

    exit(
        '403 - Divisi user tidak valid.'
    );
}


/*
|--------------------------------------------------------------------------
| Ambil Team yang BENAR-BENAR dimiliki Foreman
|--------------------------------------------------------------------------
|
| Syarat:
|
| 1. user_access.user_id = user login
| 2. team_id tidak NULL
| 3. user_access.status = active
| 4. Smelter aktif
| 5. Team aktif
| 6. Team memang milik Smelter
| 7. Smelter berada pada divisi user
|
*/

$stmt = $pdo->prepare("
    SELECT
        ua.id AS access_id,

        s.id AS smelter_id,
        s.name AS smelter_name,

        t.id AS team_id,
        t.name AS team_name,
        t.link AS team_link

    FROM user_access ua

    INNER JOIN smelters s
        ON s.id = ua.smelter_id

    INNER JOIN teams t
        ON t.id = ua.team_id
       AND t.smelter_id = ua.smelter_id

    WHERE ua.user_id = ?

      AND ua.team_id IS NOT NULL

      AND ua.status = 'active'

      AND s.status = 'active'

      AND t.status = 'active'

      AND s.division_id = ?

    ORDER BY
        s.name ASC,
        t.name ASC
");

$stmt->execute([
    $userId,
    $divisionId
]);

$myTeams = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        Dashboard Foreman - Smelter Management
    </title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f1f5f9;

            color: #1e293b;
        }


        /*
        |--------------------------------------------------------------------------
        | Navbar
        |--------------------------------------------------------------------------
        */

        .navbar {

            background: #0f172a;

            color: white;

            padding: 16px 30px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;
        }


        .brand {

            font-size: 20px;

            font-weight: bold;
        }


        .nav-right {

            display: flex;

            align-items: center;

            gap: 15px;
        }


        .user-name {

            font-size: 14px;

            color: #cbd5e1;
        }


        .logout {

            display: inline-block;

            padding: 8px 14px;

            border-radius: 6px;

            border: 1px solid #64748b;

            color: white;

            text-decoration: none;

            font-size: 13px;
        }


        .logout:hover {

            background: #334155;
        }


        /*
        |--------------------------------------------------------------------------
        | Container
        |--------------------------------------------------------------------------
        */

        .container {

            width: 100%;

            max-width: 1200px;

            margin: 0 auto;

            padding: 30px 20px;
        }


        /*
        |--------------------------------------------------------------------------
        | Header
        |--------------------------------------------------------------------------
        */

        .page-header {

            margin-bottom: 25px;
        }


        .page-header h1 {

            margin: 0 0 8px;

            font-size: 28px;
        }


        .page-header p {

            margin: 0;

            color: #64748b;
        }


        /*
        |--------------------------------------------------------------------------
        | User Information
        |--------------------------------------------------------------------------
        */

        .info-grid {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 15px;

            margin-bottom: 30px;
        }


        .info-card {

            background: white;

            border-radius: 10px;

            padding: 20px;

            border: 1px solid #e2e8f0;

            box-shadow:
                0 2px 8px rgba(0, 0, 0, 0.04);
        }


        .info-label {

            display: block;

            font-size: 12px;

            color: #64748b;

            margin-bottom: 7px;

            text-transform: uppercase;
        }


        .info-value {

            font-size: 16px;

            font-weight: 600;
        }


        /*
        |--------------------------------------------------------------------------
        | Section
        |--------------------------------------------------------------------------
        */

        .section-title {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 15px;
        }


        .section-title h2 {

            margin: 0;

            font-size: 20px;
        }


        .team-count {

            background: #e2e8f0;

            color: #334155;

            padding: 5px 10px;

            border-radius: 20px;

            font-size: 12px;
        }


        /*
        |--------------------------------------------------------------------------
        | Team Cards
        |--------------------------------------------------------------------------
        */

        .team-grid {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 20px;
        }


        .team-card {

            background: white;

            border: 1px solid #e2e8f0;

            border-radius: 12px;

            padding: 22px;

            box-shadow:
                0 3px 10px rgba(0, 0, 0, 0.05);
        }


        .smelter-name {

            font-size: 13px;

            color: #64748b;

            margin-bottom: 8px;
        }


        .team-name {

            font-size: 20px;

            font-weight: bold;

            margin-bottom: 20px;
        }


        .team-link {

            display: block;

            width: 100%;

            padding: 11px 15px;

            border-radius: 7px;

            background: #2563eb;

            color: white;

            text-decoration: none;

            text-align: center;

            font-size: 14px;

            font-weight: 600;
        }


        .team-link:hover {

            background: #1d4ed8;
        }


        .team-link.disabled {

            background: #94a3b8;

            cursor: not-allowed;
        }


        /*
        |--------------------------------------------------------------------------
        | Empty
        |--------------------------------------------------------------------------
        */

        .empty {

            background: white;

            border: 1px solid #e2e8f0;

            border-radius: 10px;

            padding: 40px 20px;

            text-align: center;

            color: #64748b;
        }


        /*
        |--------------------------------------------------------------------------
        | Action
        |--------------------------------------------------------------------------
        */

        .actions {

            margin-top: 30px;

            display: flex;

            gap: 10px;

            flex-wrap: wrap;
        }


        .btn {

            display: inline-block;

            padding: 11px 18px;

            border-radius: 7px;

            text-decoration: none;

            font-size: 14px;

            font-weight: 600;
        }


        .btn-warning {

            background: #f59e0b;

            color: #ffffff;
        }


        .btn-warning:hover {

            background: #d97706;
        }


        .btn-secondary {

            background: #475569;

            color: white;
        }


        .btn-secondary:hover {

            background: #334155;
        }


        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 900px) {

            .team-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }


            .info-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }

        }


        @media (max-width: 600px) {

            .navbar {

                padding: 14px 18px;

                align-items: flex-start;

                flex-direction: column;
            }


            .nav-right {

                width: 100%;

                justify-content: space-between;
            }


            .container {

                padding: 20px 15px;
            }


            .info-grid {

                grid-template-columns: 1fr;
            }


            .team-grid {

                grid-template-columns: 1fr;
            }


            .page-header h1 {

                font-size: 23px;
            }

        }

    </style>

</head>


<body>


<!-- =========================================================
     NAVBAR
========================================================== -->

<nav class="navbar">

    <div class="brand">

        Smelter Management

    </div>


    <div class="nav-right">

        <span class="user-name">

            <?= htmlspecialchars(
                $user['name'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </span>


        <a
            href="../auth/logout"
            class="logout"
        >
            Logout
        </a>

    </div>

</nav>


<!-- =========================================================
     MAIN
========================================================== -->

<main class="container">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="page-header">

        <h1>

            Dashboard Foreman

        </h1>


        <p>

            Selamat datang,

            <strong>

                <?= htmlspecialchars(
                    $user['name'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </strong>

        </p>

    </div>


    <!-- =====================================================
         INFORMASI USER
    ====================================================== -->

    <div class="info-grid">


        <div class="info-card">

            <span class="info-label">

                NIK

            </span>


            <div class="info-value">

                <?= htmlspecialchars(
                    $user['nik'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        </div>


        <div class="info-card">

            <span class="info-label">

                Divisi

            </span>


            <div class="info-value">

                <?= htmlspecialchars(
                    $user['division_name'] ?? '-',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        </div>


        <div class="info-card">

            <span class="info-label">

                Role

            </span>


            <div class="info-value">

                Foreman

            </div>

        </div>

    </div>


    <!-- =====================================================
         TEAM SAYA
    ====================================================== -->

    <div class="section-title">

        <h2>

            Team Saya

        </h2>


        <span class="team-count">

            <?= count($myTeams) ?>

            Team

        </span>

    </div>


    <?php if (!$myTeams): ?>


        <div class="empty">

            <strong>

                Belum ada Team yang diberikan.

            </strong>


            <p>

                Silakan menunggu persetujuan
                atau mengajukan request Team tambahan.

            </p>

        </div>


    <?php else: ?>


        <div class="team-grid">


            <?php foreach ($myTeams as $team): ?>


                <div class="team-card">


                    <div class="smelter-name">

                        <?= htmlspecialchars(
                            $team['smelter_name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </div>


                    <div class="team-name">

                        <?= htmlspecialchars(
                            $team['team_name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </div>


                    <?php if (!empty($team['team_link'])): ?>


                        <a
                            href="<?= htmlspecialchars(
                                $team['team_link'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="team-link"
                        >
                            Buka Link Team
                        </a>


                    <?php else: ?>


                        <span class="team-link disabled">

                            Link belum tersedia

                        </span>


                    <?php endif; ?>


                </div>


            <?php endforeach; ?>


        </div>


    <?php endif; ?>


    <!-- =====================================================
         ACTION
    ====================================================== -->

    <div class="actions">


        <a
            href="access-requests"
            class="btn btn-warning"
        >

            + Request Team Tambahan

        </a>


    </div>


</main>


</body>

</html>