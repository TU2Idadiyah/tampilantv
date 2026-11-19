<?php
/**
 * Portal Dosen - STAI Sidogiri
 * Halaman khusus dosen untuk konfirmasi kehadiran harian, mode daring/luring, dan tukar jam
 */

require_once dirname(__DIR__) . '/api/v1/dosen/helper.php';

try {
    $pdo = getDosenPdo();
} catch (Throwable $e) {
    $pdo = null;
}

if (!$pdo) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Koneksi Database Belum Terhubung - STAI Sidogiri</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
            .card { background: #fff; max-width: 480px; width: 100%; padding: 32px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); text-align: center; border: 1px solid #e2e8f0; }
            .icon { width: 64px; height: 64px; background: #fef3c7; color: #d97706; border-radius: 50%; display: grid; place-items: center; font-size: 28px; margin: 0 auto 16px; }
            h1 { font-size: 20px; font-weight: 800; margin-bottom: 8px; color: #0f172a; }
            p { font-size: 14px; color: #64748b; line-height: 1.5; margin-bottom: 24px; }
            .btn { display: inline-block; background: #009B4C; color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 12px; font-weight: 700; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon"><i class="fa-solid fa-database"></i></div>
            <h1>Database Belum Terhubung</h1>
            <p>Portal Dosen memerlukan koneksi basis data. Silakan hubungkan database Anda melalui Pusat Setup Database.</p>
            <a href="../setup.php" class="btn">Buka Setup Database &rarr;</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$dosen = getActiveDosen();

// Ambil info kampus
$campusName = 'STAI SIDOGIRI';
$campusSub = 'Sekolah Tinggi Agama Islam Sidogiri';
$campusLogo = null;
try {
    $rowCamp = $pdo->query("SELECT `kunci`, `nilai` FROM `pengaturan` WHERE `kunci` IN ('nama_kampus', 'subjudul_kampus')")->fetchAll();
    foreach ($rowCamp as $rc) {
        if ($rc['kunci'] === 'nama_kampus') $campusName = json_decode($rc['nilai'], true) ?: 'STAI SIDOGIRI';
        if ($rc['kunci'] === 'subjudul_kampus') $campusSub = json_decode($rc['nilai'], true) ?: $campusSub;
    }
    $rowLogo = $pdo->query("SELECT `path` FROM `media` WHERE `koleksi` = 'logo' AND `aktif` = 1 ORDER BY `id` DESC LIMIT 1")->fetch();
    if ($rowLogo && !empty($rowLogo['path'])) {
        $campusLogo = str_starts_with($rowLogo['path'], 'http') ? $rowLogo['path'] : '../storage/' . ltrim($rowLogo['path'], '/');
    }
} catch (Exception $e) {}

// Ambil daftar dosen untuk pilihan masuk
$allDosen = [];
if (!$dosen) {
    try {
        $allDosen = $pdo->query("SELECT id, kode_nidn, nama, foto_path FROM `dosen` WHERE aktif = 1 AND deleted_at IS NULL ORDER BY nama ASC")->fetchAll();
    } catch (Exception $e) {}
}

$today = date('Y-m-d');
$namaHariIndo = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$namaBulanIndo = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

$dayOfWeek = (int) date('N');
$dayNum = (int) date('j');
$monthNum = (int) date('n');
$yearNum = (int) date('Y');
$tanggalLengkap = $namaHariIndo[$dayOfWeek] . ', ' . $dayNum . ' ' . $namaBulanIndo[$monthNum] . ' ' . $yearNum;

$jadwalHariIni = [];
$jadwalMingguan = [];
$hasJadwalToday = false;
$pendingCount = 0;

if ($dosen) {
    $jadwalHariIni = getDosenJadwalHariIni($pdo, (int)$dosen['id'], $today);
    $jadwalMingguan = getDosenWeeklyJadwal($pdo, (int)$dosen['id']);
    $bulanIni = date('Y-m');
    $rekapSaya = getDosenRekapBulanan($pdo, (int)$dosen['id'], $bulanIni);
    $riwayatSaya = getDosenRiwayatPresensi($pdo, (int)$dosen['id'], 30);
    $hasJadwalToday = count($jadwalHariIni) > 0;
    foreach ($jadwalHariIni as $jh) {
        if (empty($jh['konfirmasi'])) $pendingCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Portal Dosen - <?= htmlspecialchars($campusName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root {
      --brand: #009B4C;
      --brand-dark: #006b34;
      --brand-soft: #e6f6ed;
      --brand-100: #dcfce7;
      --ink: #0f172a;
      --muted: #64748b;
      --line: #e2e8f0;
      --card: #ffffff;
      --bg: #f1f5f9;
      --sky: #0284c7;
      --sky-soft: #e0f2fe;
      --amber: #d97706;
      --amber-soft: #fef3c7;
      --rose: #e11d48;
      --rose-soft: #ffe4e6;
      --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
      --shadow: 0 4px 12px -2px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05);
      --shadow-lg: 0 12px 28px -4px rgba(0, 155, 76, 0.12), 0 4px 8px -2px rgba(0,0,0,0.04);
      --radius: 18px;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg);
      color: var(--ink);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 16px;
      -webkit-font-smoothing: antialiased;
    }

    .container {
      width: 100%;
      max-width: 580px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    /* Top Navbar Header */
    .top-header {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: var(--shadow-sm);
    }
    .brand-box {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .brand-logo {
      width: 44px;
      height: 44px;
      background: linear-gradient(135deg, var(--brand), #34d399);
      border-radius: 12px;
      display: grid;
      place-items: center;
      color: #fff;
      font-weight: 800;
      font-size: 15px;
      overflow: hidden;
      flex-shrink: 0;
    }
    .brand-logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
    .brand-text h1 { font-size: 16px; font-weight: 800; color: var(--brand-dark); line-height: 1.2; }
    .brand-text small { font-size: 12px; color: var(--muted); font-weight: 500; }

    .header-links {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .btn-icon {
      background: var(--bg);
      color: var(--muted);
      border: 1px solid var(--line);
      padding: 8px 12px;
      border-radius: 10px;
      font-size: 12px;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all .2s;
    }
    .btn-icon:hover { background: var(--brand-soft); color: var(--brand-dark); border-color: var(--brand); }

    /* Login Card */
    .login-card {
      background: var(--card);
      border-radius: 24px;
      border: 1px solid var(--line);
      padding: 32px 24px;
      box-shadow: var(--shadow-lg);
      text-align: center;
    }
    .login-icon-badge {
      width: 70px;
      height: 70px;
      background: linear-gradient(135deg, var(--brand), #10b981);
      border-radius: 22px;
      display: grid;
      place-items: center;
      color: #fff;
      font-size: 32px;
      margin: 0 auto 16px;
      box-shadow: 0 10px 20px rgba(0, 155, 76, 0.2);
    }
    .login-card h2 { font-size: 22px; font-weight: 800; color: var(--brand-dark); }
    .login-card p.sub { font-size: 14px; color: var(--muted); margin-top: 6px; margin-bottom: 24px; }

    .form-group {
      margin-bottom: 16px;
      text-align: left;
    }
    .form-group label {
      display: block;
      font-size: 13px;
      font-weight: 700;
      color: var(--ink);
      margin-bottom: 6px;
    }
    .form-control {
      width: 100%;
      height: 48px;
      padding: 0 14px;
      border: 2px solid var(--line);
      border-radius: 12px;
      font-size: 15px;
      font-weight: 600;
      outline: none;
      background: #fff;
      color: var(--ink);
      font-family: inherit;
      transition: all .2s;
    }
    .form-control:focus { border-color: var(--brand); box-shadow: 0 0 0 4px rgba(0, 155, 76, 0.12); }

    .btn-submit {
      width: 100%;
      height: 50px;
      background: var(--brand);
      color: #fff;
      border: none;
      border-radius: 14px;
      font-size: 15px;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 4px 14px rgba(0, 155, 76, 0.25);
      transition: all .2s;
      font-family: inherit;
    }
    .btn-submit:hover { background: var(--brand-dark); transform: translateY(-1px); }
    .btn-submit:active { transform: translateY(0); }

    /* Profile Bar (Logged In) */
    .profile-bar {
      background: linear-gradient(135deg, var(--card), #f8fafc);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 18px 20px;
      display: flex;
      align-items: center;
      gap: 16px;
      box-shadow: var(--shadow-sm);
    }
    .profile-avatar {
      width: 56px;
      height: 56px;
      border-radius: 16px;
      background: var(--brand-soft);
      color: var(--brand-dark);
      font-weight: 800;
      font-size: 20px;
      display: grid;
      place-items: center;
      overflow: hidden;
      flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .profile-info { flex: 1; min-width: 0; }
    .profile-info h2 {
      font-size: 16px;
      font-weight: 800;
      color: var(--ink);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.3;
    }
    .profile-info p {
      font-size: 13px;
      color: var(--muted);
      font-weight: 600;
      margin-top: 2px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .profile-info .nidn-pill {
      background: var(--brand-soft);
      color: var(--brand-dark);
      padding: 2px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
    }

    /* Date & Status Strip */
    .date-strip {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 4px;
      font-size: 13px;
      font-weight: 700;
      color: var(--muted);
    }
    .date-strip .today-pill {
      background: #fff;
      border: 1px solid var(--line);
      padding: 5px 12px;
      border-radius: 99px;
      display: flex;
      align-items: center;
      gap: 6px;
      color: var(--ink);
    }

    /* NOTIFIKASI JADWAL BANNER */
    .notif-banner {
      background: linear-gradient(135deg, #065f46, var(--brand-dark));
      color: #fff;
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 10px 25px -4px rgba(0, 107, 52, 0.35);
      position: relative;
      overflow: hidden;
      animation: pulseGlow 3s ease-in-out infinite;
    }
    @keyframes pulseGlow {
      0%, 100% { box-shadow: 0 10px 25px -4px rgba(0, 107, 52, 0.35); }
      50% { box-shadow: 0 12px 30px -2px rgba(0, 155, 76, 0.5); }
    }
    .notif-banner::before {
      content: "";
      position: absolute;
      top: -30px;
      right: -30px;
      width: 120px;
      height: 120px;
      background: rgba(255, 255, 255, 0.08);
      border-radius: 50%;
    }
    .notif-head {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 8px;
    }
    .notif-icon-ring {
      width: 40px;
      height: 40px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      display: grid;
      place-items: center;
      font-size: 18px;
      animation: bellShake 2.5s ease-in-out infinite;
    }
    @keyframes bellShake {
      0%, 100% { transform: rotate(0); }
      5%, 15% { transform: rotate(14deg); }
      10%, 20% { transform: rotate(-14deg); }
      25% { transform: rotate(0); }
    }
    .notif-head h3 { font-size: 17px; font-weight: 800; letter-spacing: -0.2px; }
    .notif-banner p { font-size: 13.5px; opacity: 0.92; line-height: 1.45; margin-bottom: 14px; }
    .notif-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .btn-notif-push {
      background: #ffffff;
      color: var(--brand-dark);
      border: none;
      padding: 7px 14px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 800;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all .2s;
    }
    .btn-notif-push:hover { background: #f0fdf4; }

    /* No Schedule State */
    .empty-schedule-card {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 32px 20px;
      text-align: center;
      box-shadow: var(--shadow-sm);
    }
    .empty-schedule-card i {
      font-size: 40px;
      color: #94a3b8;
      margin-bottom: 12px;
      display: block;
    }
    .empty-schedule-card h3 { font-size: 17px; font-weight: 800; color: var(--ink); }
    .empty-schedule-card p { font-size: 13.5px; color: var(--muted); margin-top: 4px; }

    /* Section Tabs */
    .tabs-nav {
      display: flex;
      background: #e2e8f0;
      padding: 4px;
      border-radius: 12px;
      gap: 4px;
    }
    .tab-btn {
      flex: 1;
      border: none;
      padding: 8px 12px;
      border-radius: 9px;
      font-size: 13px;
      font-weight: 700;
      background: transparent;
      color: var(--muted);
      cursor: pointer;
      transition: all .2s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    .tab-btn.active {
      background: #fff;
      color: var(--brand-dark);
      box-shadow: var(--shadow-sm);
    }

    /* Schedule Card */
    .sch-card {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 20px;
      box-shadow: var(--shadow);
      display: flex;
      flex-direction: column;
      gap: 14px;
      transition: border-color .2s;
    }
    .sch-card.is-confirmed { border-color: #86efac; }
    .sch-card.is-online { border-color: #7dd3fc; }
    .sch-card.is-tukar { border-color: #fcd34d; }

    .sch-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
    }
    .sch-time {
      background: var(--bg);
      border: 1px solid var(--line);
      padding: 4px 10px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 800;
      color: var(--brand-dark);
      white-space: nowrap;
    }
    .sch-title h3 {
      font-size: 16px;
      font-weight: 800;
      color: var(--ink);
      line-height: 1.3;
    }
    .sch-title p {
      font-size: 13px;
      color: var(--muted);
      margin-top: 3px;
      font-weight: 600;
    }

    .sch-meta {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--muted);
    }
    .sch-meta span {
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    /* Status Indicator Box inside card */
    .status-box {
      border-radius: 12px;
      padding: 12px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      font-size: 13px;
    }
    .status-box.waiting {
      background: #f8fafc;
      border: 1px dashed #cbd5e1;
      color: #64748b;
    }
    .status-box.ok-offline {
      background: var(--brand-soft);
      border: 1px solid #bbf7d0;
      color: #166534;
      font-weight: 700;
    }
    .status-box.ok-online {
      background: var(--sky-soft);
      border: 1px solid #bae6fd;
      color: #0369a1;
      font-weight: 700;
    }
    .status-box.tukar {
      background: var(--amber-soft);
      border: 1px solid #fde68a;
      color: #b45309;
      font-weight: 700;
    }
    .status-box.tidak {
      background: var(--rose-soft);
      border: 1px solid #fecaca;
      color: #9f1239;
      font-weight: 700;
    }

    /* Modal / Action Form */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(4px);
      z-index: 1000;
      display: none;
      place-items: center;
      padding: 16px;
    }
    .modal-overlay.active { display: grid; }

    .modal-content {
      background: #fff;
      width: 100%;
      max-width: 480px;
      border-radius: 24px;
      padding: 24px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.2);
      max-height: 90vh;
      overflow-y: auto;
      position: relative;
    }
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 18px;
      border-bottom: 1px solid var(--line);
      padding-bottom: 12px;
    }
    .modal-header h3 { font-size: 18px; font-weight: 800; color: var(--brand-dark); }
    .btn-close-modal {
      background: none;
      border: none;
      font-size: 20px;
      color: var(--muted);
      cursor: pointer;
    }

    /* Choice Buttons for Attendance */
    .choice-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 10px;
      margin-bottom: 16px;
    }
    .choice-card {
      border: 2px solid var(--line);
      border-radius: 14px;
      padding: 12px 14px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 12px;
      transition: all .2s;
    }
    .choice-card:hover { border-color: #94a3b8; }
    .choice-card.selected {
      border-color: var(--brand);
      background: var(--brand-soft);
    }
    .choice-card.selected.choice-online {
      border-color: var(--sky);
      background: var(--sky-soft);
    }
    .choice-card.selected.choice-tukar {
      border-color: var(--amber);
      background: var(--amber-soft);
    }
    .choice-card.selected.choice-tidak {
      border-color: var(--rose);
      background: var(--rose-soft);
    }
    .choice-card input[type="radio"] { display: none; }
    .choice-icon {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: #f1f5f9;
      display: grid;
      place-items: center;
      font-size: 16px;
      color: var(--ink);
      flex-shrink: 0;
    }
    .choice-card.selected .choice-icon {
      background: #fff;
    }
    .choice-text b { display: block; font-size: 14.5px; font-weight: 800; color: var(--ink); }
    .choice-text small { font-size: 12px; color: var(--muted); }

    /* Extra inputs based on choice */
    .sub-options { display: none; margin-bottom: 16px; }
    .sub-options.show { display: block; }

    /* Toast Notification */
    #toast {
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%) translateY(100px);
      background: #0f172a;
      color: #fff;
      padding: 12px 24px;
      border-radius: 99px;
      font-size: 14px;
      font-weight: 700;
      box-shadow: 0 10px 25px rgba(0,0,0,0.3);
      display: flex;
      align-items: center;
      gap: 10px;
      z-index: 2000;
      opacity: 0;
      transition: all .35s cubic-bezier(.2, .8, .2, 1);
      pointer-events: none;
      max-width: 90%;
      text-align: center;
    }
    #toast.show {
      transform: translateX(-50%) translateY(0);
      opacity: 1;
    }
    #toast.ok { background: #065f46; border: 1px solid #10b981; }
    #toast.err { background: #991b1b; border: 1px solid #f87171; }

    /* Weekly schedule styling */
    .weekly-group {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 16px 20px;
      margin-bottom: 12px;
    }
    .weekly-group h4 {
      font-size: 14px;
      font-weight: 800;
      color: var(--brand-dark);
      margin-bottom: 10px;
      padding-bottom: 6px;
      border-bottom: 1px solid var(--line);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .weekly-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 0;
      border-bottom: 1px dashed #f1f5f9;
      font-size: 13px;
    }
    .weekly-item:last-child { border-bottom: none; }
    .weekly-item b { color: var(--ink); }
    .weekly-item small { color: var(--muted); }
  </style>
</head>
<body>
  <div class="container">

    <!-- 1. Header Bar -->
    <header class="top-header">
      <div class="brand-box">
        <div class="brand-logo">
          <?php if ($campusLogo): ?>
            <img src="<?= htmlspecialchars($campusLogo) ?>" alt="Logo">
          <?php else: ?>
            STAI
          <?php endif; ?>
        </div>
        <div class="brand-text">
          <h1><?= htmlspecialchars($campusName) ?></h1>
          <small>Portal Kehadiran Dosen</small>
        </div>
      </div>
      <div class="header-links">
        <a href="../login.php" class="btn-icon" title="Buka Pusat Gerbang Portal">
          <i class="fa-solid fa-shapes"></i> <span>Portal</span>
        </a>
        <a href="../index.html" target="_blank" class="btn-icon" title="Lihat Tampilan TV Layar Kampus">
          <i class="fa-solid fa-tv"></i> <span>Layar TV</span>
        </a>
        <?php if ($dosen): ?>
          <a href="presensi_mahasiswa.php" class="btn-icon" style="background:var(--brand-soft);color:var(--brand-dark);border-color:var(--brand);font-weight:700" title="Presensi Mahasiswa & Jurnal Perkuliahan">
            <i class="fa-solid fa-clipboard-user"></i> <span>Presensi Mhs</span>
          </a>
          <a href="../api/v1/dosen/logout.php" class="btn-icon" style="color:var(--rose)" title="Keluar dari akun">
            <i class="fa-solid fa-right-from-bracket"></i>
          </a>
        <?php endif; ?>
      </div>
    </header>

    <?php if (!$dosen): ?>
      <!-- 2. LOGIN / PILIH DOSEN -->
      <section class="login-card">
        <div class="login-icon-badge">
          <i class="fa-solid fa-chalkboard-user"></i>
        </div>
        <h2>Masuk Portal Dosen</h2>
        <p class="sub">Pilih nama Anda atau masukkan NIDN untuk mengonfirmasi kehadiran mengajar hari ini.</p>

        <form id="formLogin">
          <div class="form-group">
            <label for="selectDosen"><i class="fa-solid fa-user-tie"></i> Pilih Nama Dosen Pengajar</label>
            <select id="selectDosen" class="form-control" onchange="onDosenSelectChange(this)">
              <option value="">-- Pilih dari Daftar Dosen --</option>
              <?php foreach ($allDosen as $d): ?>
                <option value="<?= $d['id'] ?>" data-nidn="<?= htmlspecialchars($d['kode_nidn']) ?>">
                  <?= htmlspecialchars($d['nama']) ?> (<?= htmlspecialchars($d['kode_nidn']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="display:flex;align-items:center;gap:10px;margin:12px 0">
            <div style="flex:1;height:1px;background:var(--line)"></div>
            <span style="font-size:12px;color:var(--muted);font-weight:700">ATAU MASUKKAN MANUAL</span>
            <div style="flex:1;height:1px;background:var(--line)"></div>
          </div>

          <div class="form-group">
            <label for="inputNidn"><i class="fa-solid fa-id-card"></i> NIDN / Kode Dosen</label>
            <input type="text" id="inputNidn" class="form-control" placeholder="Contoh: D001 atau NIDN">
          </div>

          <div class="form-group" id="pinGroup" style="display:none">
            <label for="inputPin"><i class="fa-solid fa-lock"></i> PIN Keamanan</label>
            <input type="password" id="inputPin" class="form-control" placeholder="Masukkan PIN 4-6 digit">
          </div>

          <button type="submit" class="btn-submit" id="btnSubmitLogin">
            <i class="fa-solid fa-arrow-right-to-bracket"></i>
            <span>Masuk ke Portal Dosen</span>
          </button>
        </form>
      </section>

    <?php else: ?>
      <!-- 3. DASHBOARD DOSEN TEROTENTIKASI -->

      <!-- Profil Singkat -->
      <section class="profile-bar">
        <div class="profile-avatar">
          <?php if (!empty($dosen['foto_url'])): ?>
            <img src="<?= htmlspecialchars($dosen['foto_url']) ?>" alt="<?= htmlspecialchars($dosen['nama']) ?>">
          <?php else: ?>
            <?= htmlspecialchars(mb_substr($dosen['nama'], 0, 2)) ?>
          <?php endif; ?>
        </div>
        <div class="profile-info">
          <h2><?= htmlspecialchars($dosen['nama']) ?></h2>
          <p>
            <span class="nidn-pill"><i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($dosen['kode_nidn']) ?></span>
            <span>· Dosen Pengajar</span>
          </p>
        </div>
      </section>

      <!-- Tanggal & Jam Hari Ini -->
      <div class="date-strip">
        <div class="today-pill">
          <i class="fa-regular fa-calendar-check" style="color:var(--brand)"></i>
          <span><?= $tanggalLengkap ?></span>
        </div>
        <div id="liveClock" style="font-variant-numeric:tabular-nums;font-weight:800;color:var(--brand-dark)">
          --:-- WIB
        </div>
      </div>

      <!-- NOTIFIKASI JADWAL HARI INI (Permintaan User: "ketika ada jadwal di hari itu, dosen mendapatkan notifikasi") -->
      <?php if ($hasJadwalToday): ?>
        <section class="notif-banner" id="notifBanner">
          <div class="notif-head">
            <div class="notif-icon-ring">
              <i class="fa-solid fa-bell"></i>
            </div>
            <div>
              <h3>Pemberitahuan Perkuliahan Hari Ini</h3>
              <small style="opacity:0.9">Anda terjadwal mengajar <?= count($jadwalHariIni) ?> sesi kuliah</small>
            </div>
          </div>
          <p>
            Terdapat <?= count($jadwalHariIni) ?> jadwal perkuliahan Anda pada hari <b><?= $namaHariIndo[$dayOfWeek] ?></b> ini. 
            <?php if ($pendingCount > 0): ?>
              Mohon tentukan status kehadiran Anda (Hadir Offline, Online, atau Tukar Jam) agar tertampil di layar TV kampus.
            <?php else: ?>
              Seluruh status perkuliahan hari ini telah Anda konfirmasi dan tertampil di layar TV kampus. Anda dapat memperbaruinya sewaktu-waktu jika diperlukan.
            <?php endif; ?>
          </p>
          <div class="notif-actions">
            <button class="btn-notif-push" onclick="requestBrowserNotification()">
              <i class="fa-solid fa-mobile-screen-button"></i>
              <span>Aktifkan Notifikasi di HP</span>
            </button>
            <button class="btn-notif-push" style="background:rgba(255,255,255,0.18);color:#fff" onclick="openConfirmModal(0, 'Semua Perkuliahan Hari Ini')">
              <i class="fa-solid fa-sliders"></i>
              <span>Konfirmasi Semua Sekaligus</span>
            </button>
          </div>
        </section>
      <?php else: ?>
        <section class="empty-schedule-card">
          <i class="fa-solid fa-mug-hot"></i>
          <h3>Tidak Ada Jadwal Mengajar Hari Ini</h3>
          <p>Hari ini (<?= $namaHariIndo[$dayOfWeek] ?>) Anda tidak memiliki jadwal perkuliahan aktif. Selamat beristirahat atau berkegiatan akademik lainnya.</p>
        </section>
      <?php endif; ?>

      <!-- Tab Navigasi Jadwal Hari Ini vs Jadwal Mingguan vs Rekap vs Presensi Mhs -->
      <div class="tabs-nav">
        <button class="tab-btn active" onclick="switchTab('today')" id="tabTodayBtn">
          <i class="fa-solid fa-calendar-day"></i>
          <span>Jadwal Hari Ini (<?= count($jadwalHariIni) ?>)</span>
        </button>
        <button class="tab-btn" onclick="switchTab('weekly')" id="tabWeeklyBtn">
          <i class="fa-solid fa-calendar-week"></i>
          <span>Jadwal Mingguan</span>
        </button>
        <button class="tab-btn" onclick="switchTab('rekap')" id="tabRekapBtn">
          <i class="fa-solid fa-chart-pie"></i>
          <span>Rekap Kehadiran</span>
        </button>
        <a href="presensi_mahasiswa.php" class="tab-btn" style="text-decoration:none">
          <i class="fa-solid fa-clipboard-user" style="color:var(--brand)"></i>
          <span>Presensi Mhs</span>
        </a>
      </div>

      <!-- TAB 1: JADWAL HARI INI -->
      <div id="tabTodayContent" style="display:flex;flex-direction:column;gap:14px">
        <?php if ($hasJadwalToday): ?>
          <?php foreach ($jadwalHariIni as $j): ?>
            <?php
              $conf = $j['konfirmasi'];
              $isConfirmed = !empty($conf);
              $status = $conf['status'] ?? null;
              $mode = $conf['mode'] ?? null;
              $jamGanti = $conf['jam_ganti'] ?? null;
              $ket = $conf['keterangan'] ?? null;
            ?>
            <div class="sch-card <?= $status === 'hadir' && $mode === 'online' ? 'is-online' : ($status === 'tukar_jam' ? 'is-tukar' : ($isConfirmed ? 'is-confirmed' : '')) ?>" id="cardJadwal<?= $j['id'] ?>">
              <div class="sch-header">
                <div class="sch-title">
                  <h3><?= htmlspecialchars($j['mata_kuliah']) ?></h3>
                  <p><?= htmlspecialchars($j['prodi_nama']) ?> · Kelas <?= htmlspecialchars($j['kelas']) ?></p>
                </div>
                <div class="sch-time">
                  <i class="fa-regular fa-clock"></i> <?= $j['jam_mulai'] ?>–<?= $j['jam_selesai'] ?>
                </div>
              </div>

              <div class="sch-meta">
                <span><i class="fa-solid fa-door-open" style="color:var(--brand)"></i> Ruang <?= htmlspecialchars($j['ruang']) ?></span>
                <span><i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($j['periode_kode']) ?></span>
              </div>

              <!-- Status Box -->
              <?php if (!$isConfirmed): ?>
                <div class="status-box waiting">
                  <div>
                    <i class="fa-solid fa-hourglass-half"></i>
                    <span>Belum Dikonfirmasi</span>
                  </div>
                  <button class="btn-icon" style="background:var(--brand);color:#fff;border:none" onclick="openConfirmModal(<?= $j['id'] ?>, '<?= htmlspecialchars(addslashes($j['mata_kuliah'])) ?>')">
                    <i class="fa-solid fa-check"></i> <span>Pilih Status</span>
                  </button>
                </div>
              <?php else: ?>
                <?php if ($status === 'hadir' && $mode === 'online'): ?>
                  <div class="status-box ok-online">
                    <div>
                      <i class="fa-solid fa-globe"></i>
                      <span><b>Hadir Online (Daring)</b> <?= $ket ? '· ' . htmlspecialchars($ket) : '' ?></span>
                    </div>
                    <button class="btn-icon" onclick="openConfirmModal(<?= $j['id'] ?>, '<?= htmlspecialchars(addslashes($j['mata_kuliah'])) ?>')">
                      <i class="fa-solid fa-pen"></i> <span>Ubah</span>
                    </button>
                  </div>
                <?php elseif ($status === 'hadir'): ?>
                  <div class="status-box ok-offline">
                    <div>
                      <i class="fa-solid fa-chalkboard-user"></i>
                      <span><b>Hadir Offline (Tatap Muka)</b> · Ruang <?= htmlspecialchars($j['ruang']) ?></span>
                    </div>
                    <button class="btn-icon" onclick="openConfirmModal(<?= $j['id'] ?>, '<?= htmlspecialchars(addslashes($j['mata_kuliah'])) ?>')">
                      <i class="fa-solid fa-pen"></i> <span>Ubah</span>
                    </button>
                  </div>
                <?php elseif ($status === 'tukar_jam'): ?>
                  <div class="status-box tukar">
                    <div>
                      <i class="fa-solid fa-clock-rotate-left"></i>
                      <span><b>Tukar Jam:</b> <?= htmlspecialchars($jamGanti ?: 'Dijadwalkan Ulang') ?></span>
                    </div>
                    <button class="btn-icon" onclick="openConfirmModal(<?= $j['id'] ?>, '<?= htmlspecialchars(addslashes($j['mata_kuliah'])) ?>')">
                      <i class="fa-solid fa-pen"></i> <span>Ubah</span>
                    </button>
                  </div>
                <?php else: ?>
                  <div class="status-box tidak">
                    <div>
                      <i class="fa-solid fa-user-xmark"></i>
                      <span><b>Tidak Hadir (Izin)</b> <?= $ket ? '· ' . htmlspecialchars($ket) : '' ?></span>
                    </div>
                    <button class="btn-icon" onclick="openConfirmModal(<?= $j['id'] ?>, '<?= htmlspecialchars(addslashes($j['mata_kuliah'])) ?>')">
                      <i class="fa-solid fa-pen"></i> <span>Ubah</span>
                    </button>
                  </div>
                <?php endif; ?>
              <?php endif; ?>

              <div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--line);display:flex;justify-content:flex-end">
                <a href="presensi_mahasiswa.php?jadwal_id=<?= $j['id'] ?>" class="btn-icon" style="background:var(--brand-soft);color:var(--brand-dark);border-color:var(--brand);font-weight:700">
                  <i class="fa-solid fa-clipboard-user"></i> <span>Presensi &amp; Jurnal Mahasiswa</span>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- TAB 2: JADWAL MINGGUAN -->
      <div id="tabWeeklyContent" style="display:none;flex-direction:column;gap:12px">
        <?php if (!empty($jadwalMingguan)): ?>
          <?php foreach ($jadwalMingguan as $w): ?>
            <div class="weekly-group">
              <h4><i class="fa-regular fa-calendar"></i> Hari <?= htmlspecialchars($w['hari_nama']) ?> (<?= count($w['jadwal']) ?> Kelas)</h4>
              <?php foreach ($w['jadwal'] as $wj): ?>
                <div class="weekly-item">
                  <div style="flex:1">
                    <b><?= htmlspecialchars($wj['mata_kuliah']) ?></b> (Kelas <?= htmlspecialchars($wj['kelas']) ?>)
                    <small style="display:block"><?= htmlspecialchars($wj['prodi_nama']) ?> · Ruang <?= htmlspecialchars($wj['ruang']) ?></small>
                  </div>
                  <div style="display:flex;align-items:center;gap:6px">
                    <div class="sch-time" style="font-size:11px">
                      <?= $wj['jam_mulai'] ?>–<?= $wj['jam_selesai'] ?>
                    </div>
                    <a href="presensi_mahasiswa.php?jadwal_id=<?= $wj['id'] ?>" class="btn-icon" style="padding:4px 8px;font-size:11px;background:var(--brand-soft);color:var(--brand-dark);border-color:var(--brand)" title="Presensi & Jurnal Mahasiswa">
                      <i class="fa-solid fa-clipboard-user"></i> <span>Presensi</span>
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-schedule-card">
            <p>Belum ada jadwal perkuliahan terdaftar dalam sistem.</p>
          </div>
        <?php endif; ?>
      </div>

      <!-- TAB 3: RIWAYAT & REKAP PRESENSI SAYA -->
      <div id="tabRekapContent" style="display:none;flex-direction:column;gap:14px">
        <!-- Kartu Ringkasan Bulan Ini -->
        <div style="background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:18px;box-shadow:var(--shadow-sm)">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <h4 style="font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:6px">
              <i class="fa-solid fa-chart-simple" style="color:var(--brand)"></i>
              <span>Rekapitulasi Saya Bulan Ini (<?= date('F Y') ?>)</span>
            </h4>
            <span style="font-size:12px;font-weight:700;color:var(--brand-dark);background:var(--brand-soft);padding:3px 10px;border-radius:99px">
              Total: <?= $rekapSaya['total_sesi'] ?? 0 ?> Sesi
            </span>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:12px;text-align:center">
              <span style="font-size:11px;font-weight:700;color:#166534;display:block">Hadir Tatap Muka</span>
              <b style="font-size:22px;color:#166534;font-weight:800"><?= $rekapSaya['hadir_offline'] ?? 0 ?></b>
            </div>
            <div style="background:#e0f2fe;border:1px solid #bae6fd;border-radius:12px;padding:12px;text-align:center">
              <span style="font-size:11px;font-weight:700;color:#0369a1;display:block">Hadir Online (Daring)</span>
              <b style="font-size:22px;color:#0369a1;font-weight:800"><?= $rekapSaya['hadir_online'] ?? 0 ?></b>
            </div>
            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:12px;text-align:center">
              <span style="font-size:11px;font-weight:700;color:#b45309;display:block">Tukar Jam</span>
              <b style="font-size:22px;color:#b45309;font-weight:800"><?= $rekapSaya['tukar_jam'] ?? 0 ?></b>
            </div>
            <div style="background:#ffe4e6;border:1px solid #fecaca;border-radius:12px;padding:12px;text-align:center">
              <span style="font-size:11px;font-weight:700;color:#9f1239;display:block">Tidak Hadir (Izin)</span>
              <b style="font-size:22px;color:#9f1239;font-weight:800"><?= $rekapSaya['tidak_hadir'] ?? 0 ?></b>
            </div>
          </div>
        </div>

        <!-- Daftar Riwayat Presensi Dosen -->
        <div style="background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:18px;box-shadow:var(--shadow-sm)">
          <h4 style="font-size:14px;font-weight:800;color:var(--ink);margin-bottom:12px;display:flex;align-items:center;gap:6px">
            <i class="fa-solid fa-clock-rotate-left" style="color:var(--brand)"></i>
            <span>Riwayat Konfirmasi Presensi Terbaru</span>
          </h4>

          <?php if (empty($riwayatSaya)): ?>
            <p style="font-size:13px;color:var(--muted);text-align:center;padding:20px">
              Belum ada riwayat konfirmasi presensi yang terekam.
            </p>
          <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px">
              <?php foreach ($riwayatSaya as $rw): ?>
              <?php
                $rwSt = $rw['status'];
                $rwMd = $rw['mode'];
              ?>
              <div style="border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:#fff;display:flex;flex-direction:column;gap:6px">
                <div style="display:flex;align-items:center;justify-content:space-between">
                  <b style="font-size:14px;color:var(--ink)"><?= htmlspecialchars($rw['mata_kuliah'] ?: 'Perkuliahan') ?></b>
                  <span style="font-size:12px;font-weight:700;color:var(--muted)">
                    <?= tglIndo($rw['tanggal']) ?>
                  </span>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
                  <div style="font-size:12.5px;color:var(--muted)">
                    <?php if ($rwSt === 'hadir' && $rwMd === 'online'): ?>
                      <span style="color:#0284c7;font-weight:700">🌐 Hadir Online</span>
                    <?php elseif ($rwSt === 'hadir'): ?>
                      <span style="color:#16a34a;font-weight:700">🏫 Hadir Offline</span>
                    <?php elseif ($rwSt === 'tukar_jam'): ?>
                      <span style="color:#d97706;font-weight:700">🔄 Tukar Jam</span>
                    <?php else: ?>
                      <span style="color:#dc2626;font-weight:700">❌ Tidak Hadir</span>
                    <?php endif; ?>
                    <?= $rw['waktu_absen'] ? ' · ' . substr($rw['waktu_absen'], 0, 5) . ' WIB' : '' ?>
                  </div>
                  <?php if ($rw['jam_ganti']): ?>
                    <span style="font-size:11px;font-weight:700;background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:6px">
                      Jam Baru: <?= htmlspecialchars($rw['jam_ganti']) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <?php if ($rw['keterangan']): ?>
                  <div style="font-size:12px;color:var(--muted);background:#f8fafc;padding:6px 10px;border-radius:8px;margin-top:2px">
                    <i class="fa-regular fa-comment-dots"></i> <?= htmlspecialchars($rw['keterangan']) ?>
                  </div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- MODAL FORM KONFIRMASI KEHADIRAN (Permintaan User: Opsi hadir/tidak/tukar jam lalu hadir online atau offline) -->
      <div class="modal-overlay" id="confirmModal">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h3>Konfirmasi Kehadiran</h3>
              <small id="modalSubTitle" style="color:var(--muted);font-weight:600">Mata Kuliah</small>
            </div>
            <button class="btn-close-modal" onclick="closeConfirmModal()">&times;</button>
          </div>

          <form id="formKonfirmasi" onsubmit="submitKonfirmasi(event)">
            <input type="hidden" id="konfirmasiJadwalId" value="0">

            <div class="choice-grid">
              <!-- Opsi 1: Hadir Offline (Tatap Muka) -->
              <label class="choice-card selected" id="choiceHadirOffline" onclick="selectChoice('hadir', 'offline')">
                <input type="radio" name="optStatus" value="hadir_offline" checked>
                <div class="choice-icon" style="color:var(--brand)"><i class="fa-solid fa-chalkboard-user"></i></div>
                <div class="choice-text">
                  <b>Hadir Offline (Tatap Muka)</b>
                  <small>Mengajar langsung di ruang kelas kampus</small>
                </div>
              </label>

              <!-- Opsi 2: Hadir Online (Daring) -->
              <label class="choice-card choice-online" id="choiceHadirOnline" onclick="selectChoice('hadir', 'online')">
                <input type="radio" name="optStatus" value="hadir_online">
                <div class="choice-icon" style="color:var(--sky)"><i class="fa-solid fa-globe"></i></div>
                <div class="choice-text">
                  <b>Hadir Online (Daring)</b>
                  <small>Kuliah daring via Zoom, GMeet, atau platform online</small>
                </div>
              </label>

              <!-- Opsi 3: Tukar Jam (Reschedule) -->
              <label class="choice-card choice-tukar" id="choiceTukarJam" onclick="selectChoice('tukar_jam', '')">
                <input type="radio" name="optStatus" value="tukar_jam">
                <div class="choice-icon" style="color:var(--amber)"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div class="choice-text">
                  <b>Tukar Jam / Reschedule</b>
                  <small>Kuliah diganti ke jam atau hari lain</small>
                </div>
              </label>

              <!-- Opsi 4: Tidak Hadir (Izin / Sakit) -->
              <label class="choice-card choice-tidak" id="choiceTidakHadir" onclick="selectChoice('tidak_hadir', '')">
                <input type="radio" name="optStatus" value="tidak_hadir">
                <div class="choice-icon" style="color:var(--rose)"><i class="fa-solid fa-user-xmark"></i></div>
                <div class="choice-text">
                  <b>Tidak Hadir (Izin / Sakit)</b>
                  <small>Berhalangan hadir atau ada tugas penting</small>
                </div>
              </label>
            </div>

            <!-- Sub-input: Hadir Online Link -->
            <div class="sub-options" id="subOnline">
              <div class="form-group">
                <label for="inputOnlineLink"><i class="fa-solid fa-link"></i> Link / Keterangan Kuliah Daring</label>
                <input type="text" id="inputOnlineLink" class="form-control" placeholder="Contoh: https://meet.google.com/xyz atau Via Zoom">
              </div>
            </div>

            <!-- Sub-input: Tukar Jam -->
            <div class="sub-options" id="subTukar">
              <div class="form-group">
                <label for="inputJamGanti"><i class="fa-regular fa-clock"></i> Waktu Pengganti / Jam Baru</label>
                <input type="text" id="inputJamGanti" class="form-control" placeholder="Contoh: Jam 13.00 WIB atau Kamis jam 09.00">
              </div>
            </div>

            <!-- Sub-input: Catatan Umum / Alasan -->
            <div class="form-group">
              <label for="inputKeterangan"><i class="fa-regular fa-comment-dots"></i> Catatan Tambahan (Opsional)</label>
              <input type="text" id="inputKeterangan" class="form-control" placeholder="Catatan untuk mahasiswa atau operator kampus">
            </div>

            <button type="submit" class="btn-submit" id="btnKirimKonfirmasi">
              <i class="fa-solid fa-paper-plane"></i>
              <span>Kirim &amp; Tampilkan di Layar TV</span>
            </button>
          </form>
        </div>
      </div>

    <?php endif; ?>

  </div>

  <!-- Toast Notifikasi Ringkas -->
  <div id="toast"></div>

  <script>
    // 1. Clock Update
    function updateClock() {
      const now = new Date();
      const h = String(now.getHours()).padStart(2, '0');
      const m = String(now.getMinutes()).padStart(2, '0');
      const s = String(now.getSeconds()).padStart(2, '0');
      const el = document.getElementById('liveClock');
      if (el) el.textContent = `${h}:${m}:${s} WIB`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Toast Utility
    function showToast(msg, type = 'ok') {
      const t = document.getElementById('toast');
      if (!t) return;
      t.className = type;
      t.textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3500);
    }

    // 3. Login Handlers
    function onDosenSelectChange(sel) {
      const opt = sel.options[sel.selectedIndex];
      const nidn = opt ? opt.dataset.nidn : '';
      const inputNidn = document.getElementById('inputNidn');
      if (inputNidn && nidn) {
        inputNidn.value = nidn;
      }
    }

    const formLogin = document.getElementById('formLogin');
    if (formLogin) {
      formLogin.addEventListener('submit', async (e) => {
        e.preventDefault();
        const sel = document.getElementById('selectDosen');
        const dosenId = sel ? sel.value : 0;
        const nidn = document.getElementById('inputNidn').value.trim();
        const pin = document.getElementById('inputPin').value.trim();
        const btn = document.getElementById('btnSubmitLogin');

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Memproses...</span>';

        try {
          const res = await fetch('../api/v1/dosen/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dosen_id: dosenId, nidn: nidn, pin: pin })
          });
          const json = await res.json();

          if (json.success) {
            showToast(json.pesan, 'ok');
            setTimeout(() => window.location.reload(), 600);
          } else {
            if (json.need_pin) {
              const pinGroup = document.getElementById('pinGroup');
              if (pinGroup) pinGroup.style.display = 'block';
              document.getElementById('inputPin').focus();
            }
            showToast(json.pesan || 'Gagal masuk', 'err');
          }
        } catch (err) {
          showToast('Koneksi terputus: ' + err.message, 'err');
        } finally {
          btn.disabled = false;
          btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> <span>Masuk ke Portal Dosen</span>';
        }
      });
    }

    // 4. Tab Switcher
    function switchTab(tab) {
      const tabTodayBtn = document.getElementById('tabTodayBtn');
      const tabWeeklyBtn = document.getElementById('tabWeeklyBtn');
      const tabRekapBtn = document.getElementById('tabRekapBtn');
      const tabTodayContent = document.getElementById('tabTodayContent');
      const tabWeeklyContent = document.getElementById('tabWeeklyContent');
      const tabRekapContent = document.getElementById('tabRekapContent');

      if (tabTodayBtn) tabTodayBtn.classList.remove('active');
      if (tabWeeklyBtn) tabWeeklyBtn.classList.remove('active');
      if (tabRekapBtn) tabRekapBtn.classList.remove('active');
      if (tabTodayContent) tabTodayContent.style.display = 'none';
      if (tabWeeklyContent) tabWeeklyContent.style.display = 'none';
      if (tabRekapContent) tabRekapContent.style.display = 'none';

      if (tab === 'today') {
        if (tabTodayBtn) tabTodayBtn.classList.add('active');
        if (tabTodayContent) tabTodayContent.style.display = 'flex';
      } else if (tab === 'weekly') {
        if (tabWeeklyBtn) tabWeeklyBtn.classList.add('active');
        if (tabWeeklyContent) tabWeeklyContent.style.display = 'flex';
      } else if (tab === 'rekap') {
        if (tabRekapBtn) tabRekapBtn.classList.add('active');
        if (tabRekapContent) tabRekapContent.style.display = 'flex';
      }
    }

    // 5. Modal Konfirmasi & Choice Selection
    let currentSelectedStatus = 'hadir';
    let currentSelectedMode = 'offline';

    function selectChoice(status, mode) {
      currentSelectedStatus = status;
      currentSelectedMode = mode;

      document.querySelectorAll('.choice-card').forEach(el => el.classList.remove('selected'));

      const subOnline = document.getElementById('subOnline');
      const subTukar = document.getElementById('subTukar');
      if (subOnline) subOnline.classList.remove('show');
      if (subTukar) subTukar.classList.remove('show');

      if (status === 'hadir' && mode === 'offline') {
        document.getElementById('choiceHadirOffline').classList.add('selected');
      } else if (status === 'hadir' && mode === 'online') {
        document.getElementById('choiceHadirOnline').classList.add('selected');
        if (subOnline) subOnline.classList.add('show');
      } else if (status === 'tukar_jam') {
        document.getElementById('choiceTukarJam').classList.add('selected');
        if (subTukar) subTukar.classList.add('show');
      } else if (status === 'tidak_hadir') {
        document.getElementById('choiceTidakHadir').classList.add('selected');
      }
    }

    function openConfirmModal(jadwalId, matkulTitle) {
      const modal = document.getElementById('confirmModal');
      const subTitle = document.getElementById('modalSubTitle');
      const jIdInput = document.getElementById('konfirmasiJadwalId');

      if (jIdInput) jIdInput.value = jadwalId;
      if (subTitle) subTitle.textContent = jadwalId === 0 ? 'Semua Jadwal Hari Ini' : matkulTitle;

      selectChoice('hadir', 'offline');
      if (modal) modal.classList.add('active');
    }

    function closeConfirmModal() {
      const modal = document.getElementById('confirmModal');
      if (modal) modal.classList.remove('active');
    }

    // 6. Submit Konfirmasi
    async function submitKonfirmasi(e) {
      e.preventDefault();
      const jadwalId = document.getElementById('konfirmasiJadwalId').value;
      const status = currentSelectedStatus;
      const mode = currentSelectedMode;
      const onlineLink = document.getElementById('inputOnlineLink') ? document.getElementById('inputOnlineLink').value.trim() : '';
      const jamGanti = document.getElementById('inputJamGanti') ? document.getElementById('inputJamGanti').value.trim() : '';
      const keteranganRaw = document.getElementById('inputKeterangan') ? document.getElementById('inputKeterangan').value.trim() : '';

      let keterangan = keteranganRaw;
      if (status === 'hadir' && mode === 'online' && onlineLink) {
        keterangan = onlineLink + (keteranganRaw ? ' · ' + keteranganRaw : '');
      }

      const btn = document.getElementById('btnKirimKonfirmasi');
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Menyimpan ke Layar TV...</span>';

      try {
        const res = await fetch('../api/v1/dosen/konfirmasi.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            jadwal_id: Number(jadwalId),
            status: status,
            mode: mode,
            jam_ganti: jamGanti,
            keterangan: keterangan
          })
        });

        const json = await res.json();
        if (json.success) {
          closeConfirmModal();
          showToast(json.pesan, 'ok');
          setTimeout(() => window.location.reload(), 900);
        } else {
          showToast(json.pesan || 'Gagal menyimpan status', 'err');
        }
      } catch (err) {
        showToast('Koneksi terputus: ' + err.message, 'err');
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> <span>Kirim &amp; Tampilkan di Layar TV</span>';
      }
    }

    // 7. Push / Browser Notification (Permintaan User: Dosen mendapatkan notifikasi jika ada jadwal)
    function requestBrowserNotification() {
      if (!('Notification' in window)) {
        alert('Browser di perangkat ini tidak mendukung Web Notifications.');
        return;
      }

      if (Notification.permission === 'granted') {
        triggerNativeNotification();
      } else if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(permission => {
          if (permission === 'granted') {
            triggerNativeNotification();
          } else {
            showToast('Izin notifikasi ditolak.', 'err');
          }
        });
      } else {
        alert('Notifikasi sebelumnya telah ditolak di pengaturan browser Anda. Mohon izinkan notifikasi untuk situs ini.');
      }
    }

    function triggerNativeNotification() {
      if (Notification.permission === 'granted') {
        new Notification("STAI Sidogiri - Jadwal Hari Ini", {
          body: "Anda memiliki perkuliahan hari ini! Ketuk untuk memastikan kehadiran (Hadir Offline, Online, atau Tukar Jam).",
          icon: "<?= $campusLogo ? htmlspecialchars($campusLogo) : '../assets/logo.png' ?>"
        });
        showToast('Notifikasi berhasil diaktifkan di HP Anda!', 'ok');
      }
    }

    // Cek otomatis apakah ada jadwal hari ini & trigger notifikasi jika izin sudah ada
    window.addEventListener('DOMContentLoaded', () => {
      <?php if ($dosen && $hasJadwalToday): ?>
        if ('Notification' in window && Notification.permission === 'granted') {
          new Notification("Jadwal Kuliah Hari Ini - STAI Sidogiri", {
            body: "Anda memiliki <?= count($jadwalHariIni) ?> perkuliahan hari ini. Silakan konfirmasi status kehadiran Anda.",
            icon: "<?= $campusLogo ? htmlspecialchars($campusLogo) : '../assets/logo.png' ?>"
          });
        }
      <?php endif; ?>
    });
  </script>
</body>
</html>
