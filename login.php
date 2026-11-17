<?php
/**
 * login.php - Pusat Gerbang Masuk & Pilihan Role (Untuk Siapa Saja)
 * STAI Sidogiri - Sistem Informasi Layar Kampus
 */

require_once __DIR__ . '/config/database.php';

$pdo = null;
$campusName = 'STAI SIDOGIRI';
$campusSub = 'Sekolah Tinggi Agama Islam Sidogiri · Pasuruan, Jawa Timur';
$campusLogo = null;

try {
    $pdo = getDatabasePdo();
    $rowCamp = $pdo->query("SELECT `kunci`, `nilai` FROM `pengaturan` WHERE `kunci` IN ('nama_kampus', 'subjudul_kampus')")->fetchAll();
    foreach ($rowCamp as $rc) {
        if ($rc['kunci'] === 'nama_kampus') $campusName = json_decode($rc['nilai'], true) ?: $campusName;
        if ($rc['kunci'] === 'subjudul_kampus') $campusSub = json_decode($rc['nilai'], true) ?: $campusSub;
    }
    $rowLogo = $pdo->query("SELECT `path` FROM `media` WHERE `koleksi` = 'logo' AND `aktif` = 1 ORDER BY `id` DESC LIMIT 1")->fetch();
    if ($rowLogo && !empty($rowLogo['path'])) {
        $campusLogo = str_starts_with($rowLogo['path'], 'http') ? $rowLogo['path'] : 'storage/' . ltrim($rowLogo['path'], '/');
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pusat Masuk &amp; Portal - <?= htmlspecialchars($campusName) ?></title>
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
            --sky: #0284c7;
            --sky-soft: #e0f2fe;
            --amber: #d97706;
            --amber-soft: #fef3c7;
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
            padding: 24px 16px;
        }
        .wrapper {
            width: 100%;
            max-width: 640px;
            background: var(--card);
            border-radius: 28px;
            padding: 36px 32px;
            border: 1px solid var(--line);
            box-shadow: 0 20px 40px -10px rgba(0, 155, 76, 0.12), 0 1px 3px rgba(0,0,0,0.05);
        }
        .header {
            text-align: center;
            margin-bottom: 28px;
        }
        .logo-box {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--g), #34d399);
            border-radius: 18px;
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 22px;
            font-weight: 800;
            margin: 0 auto 14px;
            box-shadow: 0 8px 20px rgba(0, 155, 76, 0.25);
            overflow: hidden;
        }
        .logo-box img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
        h1 { font-size: 22px; font-weight: 800; color: var(--g-dark); }
        p.sub { font-size: 13.5px; color: var(--muted); margin-top: 4px; }

        .role-notice {
            background: var(--g-soft);
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 13px;
            color: var(--g-dark);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .portal-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .portal-card {
            background: #fff;
            border: 1.5px solid var(--line);
            border-radius: 18px;
            padding: 16px 20px;
            text-decoration: none;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all .25s ease;
            position: relative;
        }
        .portal-card:hover {
            border-color: var(--g);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 155, 76, 0.1);
        }
        .portal-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .portal-info { flex: 1; }
        .portal-info b { display: block; font-size: 15.5px; font-weight: 800; color: var(--ink); }
        .portal-info small { font-size: 12.5px; color: var(--muted); margin-top: 2px; display: block; line-height: 1.35; }
        .portal-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            margin-left: 8px;
            vertical-align: middle;
        }

        .pi-dosen { background: #e0f2fe; color: #0284c7; }
        .pi-admin { background: #e6f6ed; color: var(--g-dark); }
        .pi-absen { background: #fef3c7; color: #b45309; }
        .pi-tv    { background: #f1f5f9; color: #475569; }

        .arrow { color: #94a3b8; font-size: 16px; transition: transform .2s; }
        .portal-card:hover .arrow { transform: translateX(4px); color: var(--g); }

        .footer-note {
            text-align: center;
            font-size: 12px;
            color: var(--muted);
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <div class="logo-box">
                <?php if ($campusLogo): ?>
                    <img src="<?= htmlspecialchars($campusLogo) ?>" alt="Logo">
                <?php else: ?>
                    STAI
                <?php endif; ?>
            </div>
            <h1><?= htmlspecialchars($campusName) ?></h1>
            <p class="sub"><?= htmlspecialchars($campusSub) ?></p>
        </div>

        <div class="role-notice">
            <i class="fa-solid fa-circle-info" style="font-size:16px"></i>
            <span>Portal ini terbuka untuk siapa saja. Silakan pilih portal dan peran (role) yang ingin Anda gunakan.</span>
        </div>

        <div class="portal-grid">
            <!-- 1. Portal Dosen -->
            <a href="dosen/index.php" class="portal-card">
                <div class="portal-icon pi-dosen">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>
                <div class="portal-info">
                    <b>Portal Dosen <span class="portal-badge" style="background:#e0f2fe;color:#0284c7">Dosen Pengajar</span></b>
                    <small>Konfirmasi jadwal perkuliahan hari ini (Hadir Offline, Online, Tukar Jam, atau Izin).</small>
                </div>
                <div class="arrow"><i class="fa-solid fa-arrow-right"></i></div>
            </a>

            <!-- 2. Panel Admin -->
            <a href="admin/login.php" class="portal-card">
                <div class="portal-icon pi-admin">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div class="portal-info">
                    <b>Panel Admin &amp; Operator <span class="portal-badge" style="background:#e6f6ed;color:var(--g-dark)">Admin / Operator</span></b>
                    <small>Kelola master data, agenda, display running text, video profil, dan koreksi kehadiran.</small>
                </div>
                <div class="arrow"><i class="fa-solid fa-arrow-right"></i></div>
            </a>

            <!-- 3. Presensi Pengurus -->
            <a href="absen/index.php" class="portal-card">
                <div class="portal-icon pi-absen">
                    <i class="fa-solid fa-qrcode"></i>
                </div>
                <div class="portal-info">
                    <b>Presensi Pengurus &amp; Tendik <span class="portal-badge" style="background:#fef3c7;color:#b45309">Staf / Tendik</span></b>
                    <small>Pindai (scan) QR code kehadiran masuk dan pulang dari HP masing-masing.</small>
                </div>
                <div class="arrow"><i class="fa-solid fa-arrow-right"></i></div>
            </a>

            <!-- 4. Layar TV Kiosk -->
            <a href="index.html" class="portal-card">
                <div class="portal-icon pi-tv">
                    <i class="fa-solid fa-tv"></i>
                </div>
                <div class="portal-info">
                    <b>Layar TV Kiosk Kampus <span class="portal-badge" style="background:#f1f5f9;color:#475569">Publik / TV</span></b>
                    <small>Tampilan layar informasi utama di lobi kampus (jadwal kuliah, dosen hari ini, pengurus).</small>
                </div>
                <div class="arrow"><i class="fa-solid fa-arrow-right"></i></div>
            </a>
        </div>

        <div class="footer-note">
            Sistem Informasi Layar Kampus STAI Sidogiri v1.0 &middot; <a href="setup.php" style="color:var(--muted);text-decoration:none"><i class="fa-solid fa-gear"></i> Setup Database</a>
        </div>
    </div>
</body>
</html>
