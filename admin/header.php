<?php
require_once __DIR__ . '/auth.php';
$admin = requireAdminAuth();
$currentScript = basename($_SERVER['PHP_SELF']);

$adminPdoHeader = getAdminPdo();
$campusNameHeader = 'STAI SIDOGIRI';
$campusLogoHeader = null;
try {
    $rowCamp = $adminPdoHeader->query("SELECT `nilai` FROM `pengaturan` WHERE `kunci` = 'nama_kampus'")->fetch();
    if ($rowCamp && !empty($rowCamp['nilai'])) {
        $campusNameHeader = json_decode($rowCamp['nilai'], true) ?: 'STAI SIDOGIRI';
    }
    $rowLogo = $adminPdoHeader->query("SELECT `path` FROM `media` WHERE `koleksi` = 'logo' AND `aktif` = 1 ORDER BY `id` DESC LIMIT 1")->fetch();
    if ($rowLogo && !empty($rowLogo['path'])) {
        $campusLogoHeader = str_starts_with($rowLogo['path'], 'http') ? $rowLogo['path'] : '../storage/' . ltrim($rowLogo['path'], '/');
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Admin - Sistem Informasi STAI Sidogiri</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --g: #009B4C; --g-dark: #006b34; --g-soft: #e6f6ed;
            --ink: #16261d; --muted: #62756a; --line: #dfeae3;
            --bg: #f8faf9; --card: #ffffff; --danger: #dc2626;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--ink); display: flex; min-height: 100vh; }
        
        /* Sidebar */
        aside { width: 260px; background: #fff; border-right: 1px solid var(--line); display: flex; flex-direction: column; flex-shrink: 0; }
        .brand { padding: 24px; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 12px; }
        .brand-logo { width: 44px; height: 44px; background: linear-gradient(135deg, var(--g), #37c47a); color: #fff; font-size: 18px; font-weight: 800; border-radius: 12px; display: grid; place-items: center; }
        .brand-text h2 { font-size: 16px; font-weight: 800; color: var(--g-dark); }
        .brand-text small { font-size: 12px; color: var(--muted); }
        
        nav { padding: 16px 12px; flex: 1; display: flex; flex-direction: column; gap: 4px; }
        nav a { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 10px; color: var(--ink); text-decoration: none; font-size: 14px; font-weight: 600; transition: all .2s; }
        nav a:hover { background: var(--g-soft); color: var(--g-dark); }
        nav a.active { background: var(--g); color: #fff; }
        nav .nav-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; padding: 12px 14px 4px; letter-spacing: 0.5px; }
        
        .sidebar-footer { padding: 16px; border-top: 1px solid var(--line); font-size: 13px; }
        .user-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .user-name { font-weight: 700; font-size: 13px; color: var(--ink); }
        .user-role { font-size: 11px; background: var(--g-soft); color: var(--g-dark); padding: 2px 8px; border-radius: 99px; font-weight: 700; }
        .btn-sm-logout { color: var(--danger); text-decoration: none; font-weight: 600; font-size: 13px; }
        
        /* Main Content */
        main { flex: 1; display: flex; flex-direction: column; overflow-x: hidden; }
        header { height: 64px; background: #fff; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; padding: 0 32px; }
        .page-title { font-size: 18px; font-weight: 800; color: var(--g-dark); }
        .top-actions { display: flex; align-items: center; gap: 12px; }
        .btn-kiosk { display: inline-flex; align-items: center; gap: 6px; background: var(--g-soft); color: var(--g-dark); padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; }
        
        .content { padding: 32px; max-width: 1200px; width: 100%; margin: 0 auto; }
        
        /* UI Components */
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.03); margin-bottom: 24px; }
        .card h3 { font-size: 16px; font-weight: 800; margin-bottom: 16px; color: var(--ink); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 20px; }
        .stat-card small { font-size: 13px; color: var(--muted); font-weight: 600; }
        .stat-card b { display: block; font-size: 32px; font-weight: 800; color: var(--g-dark); margin-top: 4px; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 14px; border-bottom: 1px solid var(--line); text-align: left; font-size: 14px; }
        th { font-size: 12px; color: var(--muted); text-transform: uppercase; font-weight: 700; }
        
        .badge { padding: 4px 10px; border-radius: 99px; font-size: 12px; font-weight: 700; display: inline-block; }
        .badge.ok { background: var(--g-soft); color: var(--g-dark); }
        .badge.off { background: #f3f4f6; color: var(--muted); }
        .badge.err { background: #fee2e2; color: #991b1b; }
        
        .btn { display: inline-flex; align-items: center; justify-content: center; background: var(--g); color: #fff; border: 0; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; }
        .btn-danger { background: var(--danger); }
        .btn-secondary { background: #e5e7eb; color: #374151; }
        
        .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 20px; font-weight: 600; }
        .alert.ok { background: #dcfce7; color: #166534; }
        .alert.err { background: #fee2e2; color: #991b1b; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 14px; outline: none;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--g); }
    </style>
</head>
<body>
    <aside>
        <div class="brand">
            <div class="brand-logo" style="overflow:hidden;padding:2px">
                <?php if ($campusLogoHeader): ?>
                    <img src="<?= htmlspecialchars($campusLogoHeader) ?>" style="width:100%;height:100%;object-fit:contain;background:#fff;border-radius:10px" alt="Logo">
                <?php else: ?>
                    STAI
                <?php endif; ?>
            </div>
            <div class="brand-text">
                <h2><?= htmlspecialchars($campusNameHeader) ?></h2>
                <small>Panel Admin Layar</small>
            </div>
        </div>

        <nav>
            <div class="nav-label">Menu Utama</div>
            <a href="index.php" class="<?= $currentScript === 'index.php' ? 'active' : '' ?>">📊 Dashboard</a>
            <a href="absensi.php" class="<?= $currentScript === 'absensi.php' ? 'active' : '' ?>">📋 Presensi Pengurus</a>
            <a href="absensi_dosen.php" class="<?= $currentScript === 'absensi_dosen.php' ? 'active' : '' ?>">🎓 Rekap Presensi Dosen</a>
            <a href="pengurus.php" class="<?= $currentScript === 'pengurus.php' ? 'active' : '' ?>">👥 Kelola Pengurus & Foto</a>
            <a href="dosen.php" class="<?= $currentScript === 'dosen.php' ? 'active' : '' ?>">👨‍🏫 Kelola Dosen & Foto</a>
            <a href="prodi.php" class="<?= $currentScript === 'prodi.php' ? 'active' : '' ?>">🎓 Program Studi (Prodi)</a>

            <div class="nav-label">Data & Media</div>
            <a href="import.php" class="<?= $currentScript === 'import.php' ? 'active' : '' ?>">📥 Impor CSV (Jadwal/Mhs)</a>
            <a href="agenda.php" class="<?= $currentScript === 'agenda.php' ? 'active' : '' ?>">📅 Agenda & Event</a>
            <a href="pengumuman.php" class="<?= $currentScript === 'pengumuman.php' ? 'active' : '' ?>">📢 Teks Pengumuman</a>
            <a href="media.php" class="<?= $currentScript === 'media.php' ? 'active' : '' ?>">🎬 Video Profil & Media</a>
            <a href="pengaturan.php" class="<?= $currentScript === 'pengaturan.php' ? 'active' : '' ?>">⚙️ Pengaturan & Logo</a>

            <div class="nav-label">Monitoring</div>
            <a href="audit.php" class="<?= $currentScript === 'audit.php' ? 'active' : '' ?>">🛡️ Status Layar & Audit</a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($admin['name']) ?></span>
                <span class="user-role"><?= htmlspecialchars($admin['role']) ?></span>
            </div>
            <a href="logout.php" class="btn-sm-logout">Keluar (Logout) &rarr;</a>
        </div>
    </aside>

    <main>
        <header>
            <div class="page-title">
                <?php
                    echo match($currentScript) {
                        'index.php' => 'Dashboard Sistem',
                        'absensi.php' => 'Presensi Pengurus & Koreksi Manual',
                        'absensi_dosen.php' => 'Rekapitulasi & Presensi Dosen Kampus',
                        'pengurus.php' => 'Pengurus, Foto, PIN, & Perangkat HP',
                        'dosen.php' => 'Master Data Dosen & Foto Pengajar',
                        'prodi.php' => 'Master Data Program Studi (Prodi)',
                        'import.php' => 'Modul Impor CSV Jadwal & Mahasiswa',
                        'agenda.php' => 'Pengaturan Agenda & Event Kampus',
                        'pengumuman.php' => 'Pengaturan Teks Berjalan (Ticker Bawah)',
                        'media.php' => 'Video Profil Kampus & Media',
                        'pengaturan.php' => 'Pengaturan Display & Layar Kampus',
                        'audit.php' => 'Audit Trail & Status Konektivitas TV',
                        default => 'Panel Administrasi'
                    };
                ?>
            </div>
            <div class="top-actions">
                <a href="../login.php" class="btn-kiosk" style="background:#f1f5f9;color:#334155">🚪 Pusat Portal</a>
                <a href="../index.html" target="_blank" class="btn-kiosk">📺 Pratinjau Layar TV</a>
                <a href="../dosen/index.php" target="_blank" class="btn-kiosk" style="background:#e0f2fe;color:#0369a1">👨‍🏫 Portal Dosen</a>
                <a href="../absen/index.php" target="_blank" class="btn-kiosk" style="background:#f0fdf4;color:#166534">📱 Web HP Absen</a>
            </div>
        </header>
        <div class="content">

