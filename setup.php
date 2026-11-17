<?php
/**
 * setup.php - Pusat Setup & Konfigurasi Basis Data Kampus
 * STAI Sidogiri - Sistem Informasi Layar Kampus & Portal Terpadu
 */

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/config/database.php';

$msg = null;
$msgType = 'info';

// Driver saat ini
$activeDriver = 'none';
try {
    $currentPdo = getDatabasePdo();
    $activeDriver = $currentPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
} catch (Throwable $t) {
    $activeDriver = 'error: ' . $t->getMessage();
}

$reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handler Simpan Konfigurasi MySQL
if ($reqMethod === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_mysql') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int) ($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    if (empty($dbName) || empty($dbUser)) {
        $msg = 'Nama database dan username wajib diisi!';
        $msgType = 'danger';
    } else {
        // Uji koneksi MySQL
        try {
            $testDsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $testPdo = new PDO($testDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 4
            ]);

            // Jika berhasil, periksa apakah tabel sudah ada
            $stmt = $testPdo->query("SHOW TABLES");
            $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $imported = false;
            if (empty($existingTables)) {
                // Impor schema.sql & seed.sql otomatis
                $schemaFile = __DIR__ . '/database/schema.sql';
                $seedFile = __DIR__ . '/database/seed.sql';

                if (file_exists($schemaFile)) {
                    $testPdo->exec(file_get_contents($schemaFile));
                }
                if (file_exists($seedFile)) {
                    $testPdo->exec(file_get_contents($seedFile));
                }
                $imported = true;
            }

            // Simpan konfigurasi ke config/database.local.php (Aman dari overwrite Git)
            $localConfigContent = "<?php\n// Konfigurasi Database Lokal cPanel Terverifikasi\nreturn [\n"
                . "    'DB_HOST' => " . var_export($dbHost, true) . ",\n"
                . "    'DB_PORT' => {$dbPort},\n"
                . "    'DB_DATABASE' => " . var_export($dbName, true) . ",\n"
                . "    'DB_USERNAME' => " . var_export($dbUser, true) . ",\n"
                . "    'DB_PASSWORD' => " . var_export($dbPass, true) . ",\n"
                . "    'DB_CHARSET' => 'utf8mb4',\n"
                . "    'DISABLE_SUBNET_CHECK' => true,\n"
                . "];\n";

            file_put_contents(__DIR__ . '/config/database.local.php', $localConfigContent);

            $activeDriver = 'mysql';
            $msg = 'Koneksi MySQL Berhasil Dihubungkan! ' . ($imported ? 'Struktur tabel & data awal berhasil diimpor otomatis.' : 'Menggunakan tabel database yang sudah ada.');
            $msgType = 'success';

        } catch (Throwable $e) {
            $msg = 'Koneksi MySQL Gagal: ' . $e->getMessage() . '. Pastikan nama database, user, dan password di cPanel MySQL Databases sudah benar.';
            $msgType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Setup Database - STAI SIDOGIRI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --g: #009B4C;
            --g-dark: #006b34;
            --g-soft: #e6f6ed;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --card: #ffffff;
            --bg: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #f0fdf4 0%, #f8fafc 60%, #e0f2fe 100%);
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 16px;
        }
        .wrapper {
            width: 100%;
            max-width: 600px;
            background: var(--card);
            border-radius: 24px;
            padding: 36px 32px;
            border: 1px solid var(--line);
            box-shadow: 0 20px 40px -10px rgba(0, 155, 76, 0.12);
        }
        .header { text-align: center; margin-bottom: 24px; }
        .logo-box {
            width: 60px; height: 60px; background: linear-gradient(135deg, var(--g), #34d399);
            border-radius: 16px; display: grid; place-items: center; color: #fff;
            font-size: 24px; margin: 0 auto 12px;
        }
        h1 { font-size: 22px; font-weight: 800; color: var(--g-dark); }
        p.sub { font-size: 13.5px; color: var(--muted); margin-top: 4px; }

        .status-badge {
            display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px;
            border-radius: 99px; font-size: 13px; font-weight: 700; margin-top: 12px;
        }
        .badge-mysql { background: #e0f2fe; color: #0284c7; }
        .badge-sqlite { background: #fef3c7; color: #d97706; }
        .badge-error { background: #fee2e2; color: #dc2626; }

        .alert {
            padding: 14px 18px; border-radius: 14px; font-size: 13.5px; margin-bottom: 24px;
            line-height: 1.5; font-weight: 600;
        }
        .alert-success { background: var(--g-soft); color: var(--g-dark); border: 1px solid #bbf7d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; }
        input[type="text"], input[type="password"], input[type="number"] {
            width: 100%; height: 46px; padding: 0 14px; border: 1.5px solid var(--line);
            border-radius: 12px; font-size: 14px; outline: none; font-family: inherit;
        }
        input:focus { border-color: var(--g); box-shadow: 0 0 0 3px rgba(0,155,76,0.1); }

        .btn-submit {
            width: 100%; height: 48px; background: var(--g); color: #fff; border: 0;
            border-radius: 12px; font-size: 15px; font-weight: 800; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 10px;
        }
        .btn-submit:hover { background: var(--g-dark); }

        .nav-links {
            margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--line);
            display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
        }
        .nav-btn {
            padding: 10px 14px; border-radius: 10px; text-decoration: none; font-size: 13px;
            font-weight: 700; text-align: center; border: 1px solid var(--line); color: var(--ink);
            background: #fff;
        }
        .nav-btn:hover { border-color: var(--g); color: var(--g-dark); }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <div class="logo-box"><i class="fa-solid fa-database"></i></div>
            <h1>Setup Database Sistem</h1>
            <p class="sub">Hubungkan website dengan MySQL cPanel atau gunakan SQLite</p>

            <div class="status-badge <?= str_contains($activeDriver, 'mysql') ? 'badge-mysql' : (str_contains($activeDriver, 'sqlite') ? 'badge-sqlite' : 'badge-error') ?>">
                <i class="fa-solid fa-circle" style="font-size:10px"></i>
                Database Aktif: <b><?= htmlspecialchars(strtoupper($activeDriver)) ?></b>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="save_mysql">

            <div style="display:grid;grid-template-columns:3fr 1fr;gap:12px">
                <div class="form-group">
                    <label>Database Host</label>
                    <input type="text" name="db_host" value="<?= htmlspecialchars(env('DB_HOST', 'localhost')) ?>" required>
                </div>
                <div class="form-group">
                    <label>Port</label>
                    <input type="number" name="db_port" value="<?= htmlspecialchars(env('DB_PORT', 3306)) ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label>Nama Database cPanel</label>
                <input type="text" name="db_name" placeholder="contoh: dptmqocf_staidisplay" value="<?= htmlspecialchars(env('DB_DATABASE', '')) ?>" required>
            </div>

            <div class="form-group">
                <label>Username Database cPanel</label>
                <input type="text" name="db_user" placeholder="contoh: dptmqocf_dbuser" value="<?= htmlspecialchars(env('DB_USERNAME', '')) ?>" required>
            </div>

            <div class="form-group">
                <label>Kata Sandi (Password) Database</label>
                <input type="password" name="db_pass" placeholder="Masukkan password MySQL cPanel">
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-link"></i> Uji &amp; Hubungkan Database MySQL
            </button>
        </form>

        <div class="nav-links">
            <a href="login.php" class="nav-btn">🚪 Pusat Portal</a>
            <a href="index.html" class="nav-btn">📺 Layar TV Kiosk</a>
            <a href="dosen/index.php" class="nav-btn">👨‍🏫 Portal Dosen</a>
            <a href="admin/login.php" class="nav-btn">👑 Panel Admin</a>
        </div>
    </div>
</body>
</html>
