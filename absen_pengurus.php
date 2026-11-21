<?php
/**
 * absen_pengurus.php - Layar Khusus Tampilan Absen & QR Code Pengurus
 * STAI Sidogiri - Sistem Informasi Layar Kampus
 * 
 * Sebelah Kiri: Daftar nama-nama pengurus & tendik dengan filter dan status kehadiran hari ini.
 * Sebelah Kanan: Tampilan kode QR dinamis (HMAC 30s) pengurus yang dipilih beserta profil dan instruksi scan.
 */

require_once __DIR__ . '/config/database.php';

$pdo = null;
$campusName = 'STAI SIDOGIRI';
$campusSub = 'Sekolah Tinggi Agama Islam Sidogiri · Pasuruan, Jawa Timur';
$campusLogo = null;
$pengurusList = [];

try {
    $pdo = getDatabasePdo();

    // 1. Ambil Pengaturan Kampus & Logo
    $rowCamp = $pdo->query("SELECT `kunci`, `nilai` FROM `pengaturan` WHERE `kunci` IN ('nama_kampus', 'subjudul_kampus', 'hijriah_offset')")->fetchAll();
    $hijriahOffset = 0;
    foreach ($rowCamp as $rc) {
        if ($rc['kunci'] === 'nama_kampus') $campusName = json_decode($rc['nilai'], true) ?: $campusName;
        if ($rc['kunci'] === 'subjudul_kampus') $campusSub = json_decode($rc['nilai'], true) ?: $campusSub;
        if ($rc['kunci'] === 'hijriah_offset') $hijriahOffset = (int) json_decode($rc['nilai'], true);
    }

    $rowLogo = $pdo->query("SELECT `path` FROM `media` WHERE `koleksi` = 'logo' AND `aktif` = 1 ORDER BY `id` DESC LIMIT 1")->fetch();
    if ($rowLogo && !empty($rowLogo['path'])) {
        $campusLogo = str_starts_with($rowLogo['path'], 'http') ? $rowLogo['path'] : 'storage/' . ltrim($rowLogo['path'], '/');
    }

    // 2. Ambil Pengurus Aktif beserta Absensi Hari Ini
    date_default_timezone_set('Asia/Jakarta');
    $today = date('Y-m-d');

    $sqlPengurus = "SELECT p.id, p.nama, p.jabatan, p.nip, p.foto_path, p.urutan
                    FROM `pengurus` p
                    WHERE p.aktif = 1 AND p.deleted_at IS NULL
                    ORDER BY p.urutan ASC, p.id ASC";
    $pengurusRows = $pdo->query($sqlPengurus)->fetchAll();

    // Ambil riwayat absensi hari ini
    $sqlSesi = "SELECT * FROM `absensi_pengurus` WHERE `tanggal` = :today ORDER BY `sesi_ke` ASC, `id` ASC";
    $stmtSesi = $pdo->prepare($sqlSesi);
    $stmtSesi->execute(['today' => $today]);
    $allSessions = $stmtSesi->fetchAll();

    $sessionMap = [];
    foreach ($allSessions as $s) {
        $sessionMap[$s['pengurus_id']][] = $s;
    }

    foreach ($pengurusRows as $p) {
        $pId = (int) $p['id'];
        $pSessions = $sessionMap[$pId] ?? [];
        $fotoUrl = !empty($p['foto_path']) ? (str_starts_with($p['foto_path'], 'http') ? $p['foto_path'] : 'storage/' . ltrim($p['foto_path'], '/')) : null;

        $status = 'belum';
        $hadir = false;
        $waktuMasuk = null;
        $waktuPulang = null;
        $sesiKe = null;
        $totalDurasiMenit = 0;

        if (!empty($pSessions)) {
            $lastSession = end($pSessions);
            $sesiKe = (int) ($lastSession['sesi_ke'] ?? count($pSessions));

            if ($lastSession['status'] === 'masuk') {
                $status = 'masuk';
                $hadir = true;
                $waktuMasuk = substr($lastSession['waktu_masuk'] ?? '', 0, 5);
            } elseif ($lastSession['status'] === 'pulang') {
                $status = 'pulang';
                $hadir = false;
                $waktuMasuk = substr($lastSession['waktu_masuk'] ?? '', 0, 5);
                $waktuPulang = substr($lastSession['waktu_pulang'] ?? '', 0, 5);
            }

            foreach ($pSessions as $sess) {
                if (!empty($sess['durasi_menit'])) {
                    $totalDurasiMenit += (int) $sess['durasi_menit'];
                }
            }
        }

        $durasiFormatted = null;
        if ($totalDurasiMenit > 0) {
            $jam = intdiv($totalDurasiMenit, 60);
            $menit = $totalDurasiMenit % 60;
            $durasiFormatted = ($jam > 0 ? "{$jam} jam " : "") . "{$menit} mnt";
        }

        $pengurusList[] = [
            'id' => $pId,
            'nama' => $p['nama'],
            'jabatan' => $p['jabatan'] ?: 'Staf Pengurus',
            'nip' => $p['nip'] ?: '-',
            'foto_url' => $fotoUrl,
            'status' => $status, // 'belum' | 'masuk' | 'pulang'
            'hadir' => $hadir,
            'waktu_masuk' => $waktuMasuk,
            'waktu_pulang' => $waktuPulang,
            'sesi_ke' => $sesiKe,
            'total_sesi' => count($pSessions),
            'durasi_formatted' => $durasiFormatted
        ];
    }

} catch (Throwable $e) {
    // Fallback jika database belum ada atau terjadi kendala
    $cacheFile = __DIR__ . '/storage/cache_state.json';
    if (file_exists($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (!empty($cached['pengurus'])) {
            $pengurusList = $cached['pengurus'];
        }
        if (!empty($cached['kampus'])) {
            $campusName = $cached['kampus']['nama'] ?? $campusName;
            $campusSub = $cached['kampus']['subjudul'] ?? $campusSub;
            $campusLogo = $cached['kampus']['logo_url'] ?? $campusLogo;
        }
    }
}

// Hitung rekap awal
$countTotal = count($pengurusList);
$countHadir = 0;
$countPulang = 0;
$countBelum = 0;
foreach ($pengurusList as $p) {
    if ($p['status'] === 'masuk') $countHadir++;
    elseif ($p['status'] === 'pulang') $countPulang++;
    else $countBelum++;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Layar Presensi Pengurus &amp; Tendik - <?= htmlspecialchars($campusName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    
    <!-- Typography & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- QR Code Library Lokal -->
    <script src="js/qrcode.min.js"></script>

    <style>
        :root {
            /* Palette Emerald Modern */
            --brand-900: #0f3920;
            --brand-800: #14532d;
            --brand-700: #166534;
            --brand-600: #15803d;
            --brand-500: #009B4C;
            --brand-400: #22c55e;
            --brand-100: #dcfce7;
            --brand-soft: #f0fdf4;
            
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-500: #64748b;
            --slate-400: #94a3b8;
            --slate-300: #cbd5e1;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --slate-50:  #f8fafc;
            --card-bg:   #ffffff;

            --amber-600: #d97706;
            --amber-500: #f59e0b;
            --amber-100: #fef3c7;
            
            --radius-lg: 24px;
            --radius-md: 16px;
            --radius-sm: 12px;
            --radius-pill: 9999px;

            --shadow-soft: 0 10px 30px -10px rgba(0, 0, 0, 0.05), 0 2px 8px -2px rgba(0, 0, 0, 0.03);
            --shadow-hover: 0 16px 36px -10px rgba(0, 155, 76, 0.12), 0 4px 12px -2px rgba(0, 0, 0, 0.04);
            --shadow-active: 0 12px 28px -6px rgba(0, 155, 76, 0.22);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(145deg, #f0fdf4 0%, #f8fafc 40%, #e8f5e9 100%);
            color: var(--slate-900);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* Ambient subtle glowing background decor */
        .ambient-bg {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        .ambient-blob {
            position: absolute;
            filter: blur(80px);
            opacity: 0.45;
            border-radius: 50%;
        }
        .b1 { width: 500px; height: 500px; background: rgba(34, 197, 94, 0.25); top: -100px; left: -100px; }
        .b2 { width: 600px; height: 600px; background: rgba(56, 189, 248, 0.20); bottom: -150px; right: -100px; }
        .b3 { width: 400px; height: 400px; background: rgba(250, 204, 21, 0.15); top: 30%; right: 20%; }

        /* ===== TOP HEADER BAR ===== */
        header.top-nav {
            position: relative;
            z-index: 10;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--slate-200);
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        }

        .brand-container {
            display: flex;
            align-items: center;
            gap: 16px;
            text-decoration: none;
            color: inherit;
        }
        .brand-logo-box {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--brand-500), #34d399);
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 18px;
            font-weight: 800;
            overflow: hidden;
            box-shadow: 0 6px 14px rgba(0, 155, 76, 0.25);
            flex-shrink: 0;
        }
        .brand-logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #fff;
            padding: 2px;
        }
        .brand-text h1 {
            font-size: 19px;
            font-weight: 800;
            color: var(--brand-900);
            letter-spacing: -0.3px;
            line-height: 1.2;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .badge-live-tv {
            font-size: 11px;
            font-weight: 700;
            background: #fee2e2;
            color: #dc2626;
            padding: 2px 8px;
            border-radius: var(--radius-pill);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            animation: pulse-red 2s infinite;
        }
        .badge-live-tv::before {
            content: '';
            width: 6px;
            height: 6px;
            background: #dc2626;
            border-radius: 50%;
        }
        @keyframes pulse-red {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        .brand-text p {
            font-size: 12.5px;
            color: var(--slate-500);
            font-weight: 500;
        }

        /* Center: Date, Hijri & Live Clock */
        .header-datetime {
            display: flex;
            align-items: center;
            gap: 18px;
            background: var(--slate-50);
            border: 1px solid var(--slate-200);
            padding: 8px 18px;
            border-radius: var(--radius-pill);
        }
        .date-box {
            text-align: right;
            border-right: 1.5px solid var(--slate-300);
            padding-right: 16px;
        }
        .date-masehi {
            font-size: 13px;
            font-weight: 700;
            color: var(--slate-800);
        }
        .date-hijri {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--brand-700);
        }
        .clock-box {
            font-size: 20px;
            font-weight: 800;
            color: var(--brand-900);
            font-variant-numeric: tabular-nums;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .clock-box small {
            font-size: 11px;
            font-weight: 700;
            color: var(--slate-500);
            margin-left: 2px;
        }

        /* Right Actions */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 15px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid var(--slate-200);
            background: #fff;
            color: var(--slate-700);
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .btn-action:hover {
            border-color: var(--brand-500);
            color: var(--brand-700);
            background: var(--brand-soft);
            transform: translateY(-1px);
        }
        .btn-action.active {
            background: var(--brand-100);
            color: var(--brand-800);
            border-color: var(--brand-500);
        }
        .btn-portal {
            background: var(--brand-500);
            color: #fff;
            border-color: var(--brand-600);
        }
        .btn-portal:hover {
            background: var(--brand-600);
            color: #fff;
        }

        /* ===== FLOATING CELEBRATION TOAST ===== */
        #eventToast {
            position: fixed;
            top: 86px;
            left: 50%;
            transform: translateX(-50%) translateY(-100px);
            z-index: 1000;
            background: #ffffff;
            border: 2px solid var(--brand-500);
            border-radius: 999px;
            padding: 12px 28px;
            box-shadow: 0 20px 40px rgba(0, 155, 76, 0.25);
            display: flex;
            align-items: center;
            gap: 14px;
            opacity: 0;
            pointer-events: none;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        #eventToast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
            pointer-events: auto;
        }
        .toast-icon {
            width: 36px;
            height: 36px;
            background: var(--brand-100);
            color: var(--brand-700);
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 18px;
        }
        .toast-content b {
            display: block;
            font-size: 14.5px;
            color: var(--brand-900);
        }
        .toast-content small {
            font-size: 12.5px;
            color: var(--slate-600);
        }

        /* ===== MAIN SPLIT CONTAINER ===== */
        main.app-layout {
            position: relative;
            z-index: 5;
            flex: 1;
            display: grid;
            grid-template-columns: 460px 1fr;
            gap: 24px;
            padding: 24px;
            max-width: 1920px;
            width: 100%;
            margin: 0 auto;
            height: calc(100vh - 80px);
            overflow: hidden;
        }

        @media (max-width: 1200px) {
            main.app-layout {
                grid-template-columns: 400px 1fr;
                gap: 18px;
                padding: 18px;
            }
        }
        @media (max-width: 900px) {
            main.app-layout {
                grid-template-columns: 1fr;
                height: auto;
                overflow: visible;
            }
        }

        /* ===== SEBELAH KIRI: DAFTAR NAMA PENGURUS ===== */
        .panel-left {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: var(--radius-lg);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        .panel-left-header {
            padding: 20px 20px 14px;
            border-bottom: 1px solid var(--slate-200);
            background: #ffffff;
        }
        .pl-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .pl-title {
            font-size: 17px;
            font-weight: 800;
            color: var(--brand-900);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .pl-count {
            background: var(--brand-100);
            color: var(--brand-800);
            font-size: 12px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: var(--radius-pill);
        }

        /* Search Box */
        .search-box {
            position: relative;
            margin-bottom: 12px;
        }
        .search-box i.fa-search {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate-400);
            font-size: 14px;
        }
        .search-box input {
            width: 100%;
            height: 42px;
            padding: 0 38px 0 38px;
            border: 1.5px solid var(--slate-200);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            color: var(--slate-800);
            outline: none;
            transition: all 0.2s ease;
            background: var(--slate-50);
        }
        .search-box input:focus {
            border-color: var(--brand-500);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0, 155, 76, 0.12);
        }
        .search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate-400);
            cursor: pointer;
            display: none;
            font-size: 14px;
            padding: 4px;
        }
        .search-clear:hover { color: var(--slate-700); }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            align-items: center;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .filter-tabs::-webkit-scrollbar { height: 0; }
        .tab-btn {
            background: var(--slate-100);
            border: none;
            padding: 6px 12px;
            border-radius: var(--radius-pill);
            font-size: 12px;
            font-weight: 700;
            color: var(--slate-600);
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        .tab-btn:hover {
            background: var(--slate-200);
            color: var(--slate-800);
        }
        .tab-btn.active {
            background: var(--brand-600);
            color: #fff;
        }
        .tab-badge {
            background: rgba(0,0,0,0.08);
            padding: 1px 6px;
            border-radius: 99px;
            font-size: 10.5px;
        }
        .tab-btn.active .tab-badge {
            background: rgba(255,255,255,0.25);
            color: #fff;
        }

        /* Scrollable List */
        .panel-left-list {
            flex: 1;
            overflow-y: auto;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .panel-left-list::-webkit-scrollbar {
            width: 6px;
        }
        .panel-left-list::-webkit-scrollbar-thumb {
            background: var(--slate-300);
            border-radius: 99px;
        }

        /* Item Pengurus */
        .pengurus-item {
            background: #ffffff;
            border: 1.5px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            user-select: none;
        }
        .pengurus-item:hover {
            border-color: var(--brand-400);
            transform: translateX(4px);
            box-shadow: var(--shadow-hover);
        }
        .pengurus-item.active {
            background: var(--brand-soft);
            border-color: var(--brand-500);
            box-shadow: var(--shadow-active);
            transform: translateX(6px);
        }
        .pengurus-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 12px;
            bottom: 12px;
            width: 4px;
            background: var(--brand-500);
            border-radius: 0 4px 4px 0;
        }

        /* Avatar thumbnail */
        .item-avatar {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: var(--slate-100);
            border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            display: grid;
            place-items: center;
            font-weight: 800;
            color: var(--brand-700);
            font-size: 16px;
            flex-shrink: 0;
            overflow: hidden;
            position: relative;
        }
        .item-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .item-avatar .status-dot {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #fff;
        }
        .dot-masuk  { background: var(--brand-500); }
        .dot-pulang { background: var(--amber-500); }
        .dot-belum  { background: var(--slate-300); }

        .item-info {
            flex: 1;
            min-width: 0;
        }
        .item-name {
            font-size: 14.5px;
            font-weight: 800;
            color: var(--slate-900);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
        }
        .pengurus-item.active .item-name {
            color: var(--brand-900);
        }
        .item-sub {
            font-size: 12px;
            color: var(--slate-500);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .item-status-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: var(--radius-pill);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }
        .pill-masuk {
            background: var(--brand-100);
            color: var(--brand-800);
            border: 1px solid #bbf7d0;
        }
        .pill-pulang {
            background: var(--amber-100);
            color: var(--amber-600);
            border: 1px solid #fde68a;
        }
        .pill-belum {
            background: var(--slate-100);
            color: var(--slate-500);
            border: 1px solid var(--slate-200);
        }

        .item-arrow {
            color: var(--slate-300);
            font-size: 14px;
            transition: all 0.2s;
        }
        .pengurus-item.active .item-arrow {
            color: var(--brand-600);
            transform: translateX(3px);
        }

        .empty-search-msg {
            padding: 40px 20px;
            text-align: center;
            color: var(--slate-400);
            font-size: 14px;
            display: none;
        }

        /* ===== SEBELAH KANAN: TAMPILAN QR CODE & DETAIL PENGURUS ===== */
        .panel-right {
            background: rgba(255, 255, 255, 0.90);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: var(--radius-lg);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            padding: 32px 36px;
            box-shadow: var(--shadow-soft);
            position: relative;
        }
        .panel-right::-webkit-scrollbar {
            width: 6px;
        }
        .panel-right::-webkit-scrollbar-thumb {
            background: var(--slate-300);
            border-radius: 99px;
        }

        /* Profile Showcase Header */
        .showcase-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding-bottom: 24px;
            border-bottom: 1.5px dashed var(--slate-200);
        }
        .profile-hero {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .hero-avatar {
            width: 86px;
            height: 86px;
            border-radius: 24px;
            background: linear-gradient(135deg, var(--brand-500), #10b981);
            color: #fff;
            display: grid;
            place-items: center;
            font-size: 32px;
            font-weight: 800;
            overflow: hidden;
            border: 3px solid #fff;
            box-shadow: 0 8px 24px rgba(0, 155, 76, 0.20);
            flex-shrink: 0;
        }
        .hero-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .hero-text h2 {
            font-size: 24px;
            font-weight: 900;
            color: var(--slate-900);
            letter-spacing: -0.4px;
            line-height: 1.25;
        }
        .hero-text p.jabatan {
            font-size: 15.5px;
            font-weight: 700;
            color: var(--brand-700);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hero-text p.nip {
            font-size: 12.5px;
            color: var(--slate-500);
            font-weight: 600;
            margin-top: 2px;
        }

        /* Status Badge Besar */
        .hero-status-box {
            text-align: right;
            flex-shrink: 0;
        }
        .big-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: var(--radius-pill);
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.2px;
        }
        .badge-masuk-big {
            background: var(--brand-100);
            color: var(--brand-800);
            border: 1.5px solid #86efac;
        }
        .badge-pulang-big {
            background: var(--amber-100);
            color: var(--amber-600);
            border: 1.5px solid #fde68a;
        }
        .badge-belum-big {
            background: var(--slate-100);
            color: var(--slate-600);
            border: 1.5px solid var(--slate-300);
        }
        .hero-status-time {
            font-size: 12.5px;
            color: var(--slate-500);
            font-weight: 600;
            margin-top: 6px;
        }

        /* ===== QR SHOWCASE CENTER CARD ===== */
        .qr-card-section {
            margin: 28px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .qr-frame-box {
            background: #ffffff;
            border: 2px solid var(--brand-100);
            border-radius: 28px;
            padding: 24px 32px 28px;
            box-shadow: 0 16px 40px -10px rgba(0, 155, 76, 0.12), 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            max-width: 440px;
            width: 100%;
            transition: all 0.3s ease;
        }
        .qr-frame-box:hover {
            border-color: var(--brand-400);
            box-shadow: 0 20px 48px -10px rgba(0, 155, 76, 0.18);
        }

        .qr-top-tag {
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--brand-700);
            background: var(--brand-soft);
            padding: 4px 14px;
            border-radius: var(--radius-pill);
            margin-bottom: 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* QR Canvas Wrapper */
        .qr-canvas-wrapper {
            width: 250px;
            height: 250px;
            background: #ffffff;
            padding: 12px;
            border-radius: 20px;
            border: 1.5px solid var(--slate-200);
            display: grid;
            place-items: center;
            box-shadow: inset 0 2px 6px rgba(0,0,0,0.02);
            position: relative;
        }
        .qr-canvas-wrapper img, .qr-canvas-wrapper canvas {
            max-width: 100%;
            max-height: 100%;
            display: block;
            border-radius: 8px;
        }

        /* Countdown Indicator */
        .qr-timer-box {
            margin-top: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 700;
            color: var(--slate-600);
        }
        .timer-circle {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2.5px solid var(--brand-500);
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .timer-val {
            color: var(--brand-700);
            font-variant-numeric: tabular-nums;
            font-weight: 800;
        }

        /* Quick Direct Buttons under QR */
        .qr-quick-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 18px;
            width: 100%;
        }
        .btn-test-scan {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 40px;
            background: var(--brand-500);
            color: #fff;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        .btn-test-scan:hover {
            background: var(--brand-600);
            transform: translateY(-1px);
        }
        .btn-copy-link {
            width: 40px;
            height: 40px;
            background: var(--slate-100);
            color: var(--slate-700);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-sm);
            display: grid;
            place-items: center;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-copy-link:hover {
            background: var(--slate-200);
            color: var(--brand-700);
        }

        /* ===== INSTRUCTION GUIDE ===== */
        .instruction-section {
            background: #ffffff;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 20px 24px;
            margin-top: auto;
        }
        .instruction-title {
            font-size: 13.5px;
            font-weight: 800;
            color: var(--slate-700);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .steps-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }
        .step-item {
            background: var(--slate-50);
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .step-num {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--brand-100);
            color: var(--brand-800);
            font-size: 12px;
            font-weight: 800;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .step-txt b {
            display: block;
            font-size: 13px;
            font-weight: 800;
            color: var(--slate-900);
            margin-bottom: 2px;
        }
        .step-txt small {
            font-size: 11.5px;
            color: var(--slate-500);
            line-height: 1.35;
            display: block;
        }

        @media (max-width: 900px) {
            .steps-grid { grid-template-columns: 1fr; }
            .showcase-header { flex-direction: column; align-items: flex-start; text-align: left; }
            .hero-status-box { text-align: left; margin-top: 10px; }
        }
    </style>
</head>
<body>

    <!-- Ambient Glow Background -->
    <div class="ambient-bg">
        <div class="ambient-blob b1"></div>
        <div class="ambient-blob b2"></div>
        <div class="ambient-blob b3"></div>
    </div>

    <!-- Celebration / Notification Toast on Realtime Absen -->
    <div id="eventToast">
        <div class="toast-icon"><i class="fa-solid fa-check"></i></div>
        <div class="toast-content">
            <b id="toastTitle">Presensi Berhasil Dicatat!</b>
            <small id="toastMsg">Pengurus telah tercatat masuk di sistem.</small>
        </div>
    </div>

    <!-- TOP HEADER -->
    <header class="top-nav">
        <div class="brand-container">
            <div class="brand-logo-box">
                <?php if ($campusLogo): ?>
                    <img src="<?= htmlspecialchars($campusLogo) ?>" alt="Logo">
                <?php else: ?>
                    STAI
                <?php endif; ?>
            </div>
            <div class="brand-text">
                <h1>
                    <span><?= htmlspecialchars($campusName) ?></span>
                    <span class="badge-live-tv">PRESENSI PENGURUS</span>
                </h1>
                <p>Portal Khusus Tampilan Presensi Mandiri &amp; QR Code</p>
            </div>
        </div>

        <div class="header-datetime">
            <div class="date-box">
                <div class="date-masehi" id="dateMasehi">Memuat tanggal...</div>
                <div class="date-hijri" id="dateHijri">Memuat Hijriah...</div>
            </div>
            <div class="clock-box" id="clockBox">
                00:00:00 <small>WIB</small>
            </div>
        </div>

        <div class="header-actions">
            <!-- Audio Toggle -->
            <button class="btn-action active" id="btnAudio" title="Aktifkan/Matikan Suara Notifikasi">
                <i class="fa-solid fa-volume-high"></i>
                <span id="audioLabel">Suara Aktif</span>
            </button>
            <!-- Fullscreen Toggle -->
            <button class="btn-action" id="btnFullscreen" title="Tampilkan Layar Penuh (TV / Kiosk)">
                <i class="fa-solid fa-expand"></i>
                <span>Layar Penuh</span>
            </button>
            <!-- Refresh Manual -->
            <button class="btn-action" id="btnRefresh" title="Segarkan Data Sekarang">
                <i class="fa-solid fa-arrows-rotate"></i>
            </button>
            <!-- Menu Utama / Portal -->
            <a href="login.php" class="btn-action btn-portal" title="Kembali ke Pusat Portal">
                <i class="fa-solid fa-house"></i>
                <span>Portal</span>
            </a>
        </div>
    </header>

    <!-- MAIN TWO-COLUMN LAYOUT -->
    <main class="app-layout">

        <!-- ================= SEBELAH KIRI: DAFTAR PENGURUS ================= -->
        <section class="panel-left">
            <div class="panel-left-header">
                <div class="pl-title-row">
                    <div class="pl-title">
                        <i class="fa-solid fa-users" style="color:var(--brand-600)"></i>
                        <span>Daftar Pengurus</span>
                    </div>
                    <span class="pl-count" id="totalCountBadge"><?= $countTotal ?> Pengurus</span>
                </div>

                <!-- Input Pencarian -->
                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Cari nama atau jabatan pengurus..." autocomplete="off">
                    <i class="fa-solid fa-circle-xmark search-clear" id="searchClear" title="Bersihkan"></i>
                </div>

                <!-- Filter Tabs -->
                <div class="filter-tabs">
                    <button class="tab-btn active" data-filter="all">
                        Semua <span class="tab-badge" id="badgeAll"><?= $countTotal ?></span>
                    </button>
                    <button class="tab-btn" data-filter="masuk">
                        🟢 Hadir <span class="tab-badge" id="badgeHadir"><?= $countHadir ?></span>
                    </button>
                    <button class="tab-btn" data-filter="belum">
                        ⚪ Belum <span class="tab-badge" id="badgeBelum"><?= $countBelum ?></span>
                    </button>
                    <button class="tab-btn" data-filter="pulang">
                        🟡 Pulang <span class="tab-badge" id="badgePulang"><?= $countPulang ?></span>
                    </button>
                </div>
            </div>

            <!-- List Container -->
            <div class="panel-left-list" id="pengurusList">
                <?php if (empty($pengurusList)): ?>
                    <div class="empty-search-msg" style="display:block">
                        <i class="fa-solid fa-user-slash" style="font-size:32px;margin-bottom:8px"></i>
                        <p>Belum ada data pengurus aktif di sistem.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pengurusList as $idx => $p): 
                        $initial = mb_substr($p['nama'], 0, 1, 'UTF-8');
                        $statusClass = 'pill-belum';
                        $statusLabel = 'Belum Hadir';
                        $dotClass = 'dot-belum';
                        if ($p['status'] === 'masuk') {
                            $statusClass = 'pill-masuk';
                            $statusLabel = 'Hadir ' . ($p['waktu_masuk'] ?: '');
                            $dotClass = 'dot-masuk';
                        } elseif ($p['status'] === 'pulang') {
                            $statusClass = 'pill-pulang';
                            $statusLabel = 'Pulang ' . ($p['waktu_pulang'] ?: '');
                            $dotClass = 'dot-pulang';
                        }
                    ?>
                        <div class="pengurus-item <?= $idx === 0 ? 'active' : '' ?>" 
                             data-id="<?= $p['id'] ?>"
                             data-nama="<?= htmlspecialchars($p['nama']) ?>"
                             data-jabatan="<?= htmlspecialchars($p['jabatan']) ?>"
                             data-nip="<?= htmlspecialchars($p['nip']) ?>"
                             data-foto="<?= htmlspecialchars($p['foto_url'] ?? '') ?>"
                             data-status="<?= $p['status'] ?>"
                             data-masuk="<?= htmlspecialchars($p['waktu_masuk'] ?? '') ?>"
                             data-pulang="<?= htmlspecialchars($p['waktu_pulang'] ?? '') ?>"
                             data-sesi="<?= (int)($p['sesi_ke'] ?? 1) ?>"
                             data-durasi="<?= htmlspecialchars($p['durasi_formatted'] ?? '') ?>">
                            
                            <div class="item-avatar">
                                <?php if (!empty($p['foto_url'])): ?>
                                    <img src="<?= htmlspecialchars($p['foto_url']) ?>" alt="Foto" onerror="this.style.display='none';this.parentElement.textContent='<?= $initial ?>'">
                                <?php else: ?>
                                    <?= $initial ?>
                                <?php endif; ?>
                                <span class="status-dot <?= $dotClass ?>"></span>
                            </div>

                            <div class="item-info">
                                <div class="item-name"><?= htmlspecialchars($p['nama']) ?></div>
                                <div class="item-sub">
                                    <span><?= htmlspecialchars($p['jabatan']) ?></span>
                                </div>
                            </div>

                            <div class="item-status-pill <?= $statusClass ?>">
                                <?= $statusLabel ?>
                            </div>

                            <div class="item-arrow">
                                <i class="fa-solid fa-chevron-right"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="empty-search-msg" id="noSearchResults">
                    <i class="fa-solid fa-search" style="font-size:28px;margin-bottom:8px"></i>
                    <p>Tidak ditemukan pengurus dengan kata kunci tersebut.</p>
                </div>
            </div>
        </section>

        <!-- ================= SEBELAH KANAN: TAMPILAN QR CODE & DETAIL ================= -->
        <section class="panel-right" id="displayPanel">

            <!-- Showcase Profile Header -->
            <div class="showcase-header">
                <div class="profile-hero">
                    <div class="hero-avatar" id="heroAvatar">
                        <span id="heroInitial">?</span>
                    </div>
                    <div class="hero-text">
                        <h2 id="heroNama">Memuat Pengurus...</h2>
                        <p class="jabatan" id="heroJabatan">
                            <i class="fa-solid fa-id-badge" style="color:var(--brand-600)"></i>
                            <span id="heroJabatanText">-</span>
                        </p>
                        <p class="nip" id="heroNip">NIP: -</p>
                    </div>
                </div>

                <div class="hero-status-box">
                    <div class="big-status-badge badge-belum-big" id="heroStatusBadge">
                        <i class="fa-solid fa-circle-notch"></i>
                        <span id="heroStatusText">Belum Hadir</span>
                    </div>
                    <div class="hero-status-time" id="heroStatusDetail">
                        Belum melakukan presensi hari ini
                    </div>
                </div>
            </div>

            <!-- QR Code Section -->
            <div class="qr-card-section">
                <div class="qr-frame-box">
                    <div class="qr-top-tag">
                        <i class="fa-solid fa-qrcode"></i>
                        <span>Scan Presensi Mandiri</span>
                    </div>

                    <!-- QR Container -->
                    <div class="qr-canvas-wrapper" id="qrWrapper">
                        <div id="qrcode"></div>
                    </div>

                    <!-- Timer Countdown Bar -->
                    <div class="qr-timer-box">
                        <div class="timer-circle"></div>
                        <span>Kode QR berganti dalam <span class="timer-val" id="countdownSec">30</span> detik</span>
                    </div>

                    <!-- Quick Link & Copy for Kiosk Testing -->
                    <div class="qr-quick-actions">
                        <a href="#" target="_blank" class="btn-test-scan" id="btnDirectScan">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            <span>Buka Link Presensi</span>
                        </a>
                        <button class="btn-copy-link" id="btnCopyQr" title="Salin URL Presensi">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Instruction Guide -->
            <div class="instruction-section">
                <div class="instruction-title">
                    <i class="fa-solid fa-circle-question" style="color:var(--brand-600)"></i>
                    <span>Panduan Presensi Cepat</span>
                </div>
                <div class="steps-grid">
                    <div class="step-item">
                        <div class="step-num">1</div>
                        <div class="step-txt">
                            <b>Pilih Nama Anda</b>
                            <small>Klik nama Anda pada daftar di sebelah kiri layar ini.</small>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num">2</div>
                        <div class="step-txt">
                            <b>Pindai (Scan) QR</b>
                            <small>Buka kamera HP terdaftar dan arahkan ke kode QR di atas.</small>
                        </div>
                    </div>
                    <div class="step-item">
                        <div class="step-num">3</div>
                        <div class="step-txt">
                            <b>Otomatis Tercatat</b>
                            <small>Status masuk / pulang akan langsung tercatat dan berganti hijau.</small>
                        </div>
                    </div>
                </div>
            </div>

        </section>

    </main>

    <!-- INITIAL DATA & LOGIC SCRIPT -->
    <script>
        // State data pengurus & token QR
        let pengurusData = <?= json_encode($pengurusList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
        let activePengurusId = pengurusData.length > 0 ? pengurusData[0].id : null;
        let qrTokens = {};
        let countdownSec = 30;
        let qrCodeInstance = null;
        let isAudioEnabled = true;
        let lastStatusMap = {};

        // Inisialisasi peta status sebelumnya untuk mendeteksi perubahan kehadiran
        pengurusData.forEach(p => {
            lastStatusMap[p.id] = p.status;
        });

        // 1. Audio Notification Synth (Web Audio API tanpa dependensi file eksternal)
        const AudioService = (function() {
            let ctx = null;
            function playChime(isCheckOut = false) {
                if (!isAudioEnabled) return;
                try {
                    if (!ctx) {
                        ctx = new (window.AudioContext || window.webkitAudioContext)();
                    }
                    if (ctx.state === 'suspended') {
                        ctx.resume();
                    }
                    const now = ctx.currentTime;
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();

                    osc.type = 'sine';
                    if (!isCheckOut) {
                        // Nada Masuk: Ascending (Do - Mi - Sol)
                        osc.frequency.setValueAtTime(523.25, now);       // C5
                        osc.frequency.setValueAtTime(659.25, now + 0.1); // E5
                        osc.frequency.setValueAtTime(783.99, now + 0.2); // G5
                    } else {
                        // Nada Pulang: Descending (Sol - Mi)
                        osc.frequency.setValueAtTime(783.99, now);       // G5
                        osc.frequency.setValueAtTime(523.25, now + 0.15); // C5
                    }

                    gain.gain.setValueAtTime(0.2, now);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.6);

                    osc.connect(gain);
                    gain.connect(ctx.destination);

                    osc.start(now);
                    osc.stop(now + 0.6);
                } catch (e) {
                    console.log("Audio play error:", e);
                }
            }
            return { playChime };
        })();

        // 2. Tampilkan Toast Event Absensi Realtime
        function showCelebrationToast(nama, statusText, isPulang = false) {
            const toast = document.getElementById('eventToast');
            const title = document.getElementById('toastTitle');
            const msg = document.getElementById('toastMsg');
            const icon = toast.querySelector('.toast-icon');

            if (isPulang) {
                title.textContent = `Presensi Pulang Berhasil!`;
                msg.textContent = `${nama} telah selesai bertugas hari ini.`;
                icon.innerHTML = `<i class="fa-solid fa-door-open" style="color:#d97706"></i>`;
                toast.style.borderColor = '#f59e0b';
            } else {
                title.textContent = `Presensi Masuk Berhasil!`;
                msg.textContent = `${nama} baru saja tercatat hadir di kampus.`;
                icon.innerHTML = `<i class="fa-solid fa-check" style="color:#15803d"></i>`;
                toast.style.borderColor = '#009B4C';
            }

            toast.classList.add('show');
            AudioService.playChime(isPulang);

            setTimeout(() => {
                toast.classList.remove('show');
            }, 5000);
        }

        // 3. Render Detail & QR Code Pengurus Terpilih di Sebelah Kanan
        function selectPengurus(id) {
            const p = pengurusData.find(item => item.id == id);
            if (!p) return;

            activePengurusId = p.id;

            // Highlight item di daftar sebelah kiri
            document.querySelectorAll('.pengurus-item').forEach(item => {
                if (item.dataset.id == p.id) {
                    item.classList.add('active');
                    item.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    item.classList.remove('active');
                }
            });

            // Update URL hash tanpa reload
            history.replaceState(null, null, `#p${p.id}`);

            // Update Panel Kanan
            const heroAvatar = document.getElementById('heroAvatar');
            const heroNama = document.getElementById('heroNama');
            const heroJabatan = document.getElementById('heroJabatanText');
            const heroNip = document.getElementById('heroNip');
            const heroBadge = document.getElementById('heroStatusBadge');
            const heroStatusText = document.getElementById('heroStatusText');
            const heroStatusDetail = document.getElementById('heroStatusDetail');
            const btnDirectScan = document.getElementById('btnDirectScan');

            heroNama.textContent = p.nama;
            heroJabatan.textContent = p.jabatan || 'Staf Pengurus';
            heroNip.textContent = `NIP: ${p.nip || '-'}`;

            // Avatar
            const initial = p.nama.charAt(0);
            if (p.foto_url) {
                heroAvatar.innerHTML = `<img src="${p.foto_url}" alt="Foto" onerror="this.parentElement.innerHTML='<span>${initial}</span>'">`;
            } else {
                heroAvatar.innerHTML = `<span>${initial}</span>`;
            }

            // Status Badge
            heroBadge.className = 'big-status-badge';
            if (p.status === 'masuk') {
                heroBadge.classList.add('badge-masuk-big');
                heroBadge.innerHTML = `<i class="fa-solid fa-circle-check"></i> <span>Sedang Hadir di Kampus</span>`;
                heroStatusDetail.textContent = `Masuk pukul ${p.waktu_masuk || '--:--'} WIB (Sesi ke-${p.sesi_ke || 1})`;
            } else if (p.status === 'pulang') {
                heroBadge.classList.add('badge-pulang-big');
                heroBadge.innerHTML = `<i class="fa-solid fa-door-open"></i> <span>Sudah Pulang</span>`;
                heroStatusDetail.textContent = `Masuk: ${p.waktu_masuk || '--:--'} → Pulang: ${p.waktu_pulang || '--:--'} WIB ${p.durasi_formatted ? '· Total ' + p.durasi_formatted : ''}`;
            } else {
                heroBadge.classList.add('badge-belum-big');
                heroBadge.innerHTML = `<i class="fa-regular fa-clock"></i> <span>Belum Presensi</span>`;
                heroStatusDetail.textContent = `Silakan scan kode QR di bawah untuk presensi masuk hari ini.`;
            }

            // Gambar QR Code
            renderActiveQr();
        }

        // 4. Render QR Code menggunakan library QRCode
        function renderActiveQr() {
            const qrWrapper = document.getElementById('qrcode');
            const btnDirectScan = document.getElementById('btnDirectScan');
            if (!qrWrapper) return;

            const targetUrl = qrTokens[activePengurusId];

            if (!targetUrl) {
                qrWrapper.innerHTML = `
                    <div style="font-size:13px;color:var(--slate-500);text-align:center;padding:40px 10px">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;color:var(--brand-600)"></i>
                        <div>Menyiapkan Kode QR...</div>
                    </div>
                `;
                btnDirectScan.removeAttribute('href');
                return;
            }

            qrWrapper.innerHTML = '';
            try {
                new QRCode(qrWrapper, {
                    text: targetUrl,
                    width: 226,
                    height: 226,
                    colorDark: "#0f3920",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.M
                });
                btnDirectScan.href = targetUrl;
            } catch (err) {
                console.error("Gagal menggambar QR Code:", err);
                qrWrapper.innerHTML = `<div style="font-size:12px;color:red">Gagal menghasilkan QR</div>`;
            }
        }

        // 5. Polling Token QR dari /api/v1/display/qr.php
        async function fetchQrTokens() {
            try {
                const res = await fetch('api/v1/display/qr.php');
                if (res.ok) {
                    const data = await res.json();
                    if (data.qr) {
                        qrTokens = data.qr;
                        countdownSec = data.waktu_berlaku_detik || 30;
                        renderActiveQr();
                    }
                }
            } catch (e) {
                console.warn("Gagal memperbarui token QR:", e);
            }
        }

        // 6. Polling Status Kehadiran dari /api/v1/display/state.php
        async function pollState() {
            try {
                const res = await fetch('api/v1/display/state.php');
                if (res.ok) {
                    const data = await res.json();
                    if (data.pengurus && Array.isArray(data.pengurus)) {
                        updatePengurusData(data.pengurus);
                    }
                }
            } catch (e) {
                console.warn("Gagal polling state presensi:", e);
            }
        }

        // 7. Update data pengurus, hitung rekap, dan cek apakah ada yang baru absen
        function updatePengurusData(newList) {
            let countHadir = 0;
            let countPulang = 0;
            let countBelum = 0;

            newList.forEach(item => {
                if (item.status === 'masuk') countHadir++;
                else if (item.status === 'pulang') countPulang++;
                else countBelum++;

                // Cek perubahan status realtime
                const prevStatus = lastStatusMap[item.id];
                if (prevStatus && prevStatus !== item.status) {
                    if (item.status === 'masuk') {
                        showCelebrationToast(item.nama, 'Masuk', false);
                    } else if (item.status === 'pulang') {
                        showCelebrationToast(item.nama, 'Pulang', true);
                    }
                }
                lastStatusMap[item.id] = item.status;
            });

            pengurusData = newList;

            // Update badge rekap tab
            document.getElementById('badgeAll').textContent = newList.length;
            document.getElementById('badgeHadir').textContent = countHadir;
            document.getElementById('badgeBelum').textContent = countBelum;
            document.getElementById('badgePulang').textContent = countPulang;
            document.getElementById('totalCountBadge').textContent = `${newList.length} Pengurus`;

            // Update atribut elemen daftar kiri tanpa merusak seleksi
            newList.forEach(p => {
                const itemEl = document.querySelector(`.pengurus-item[data-id="${p.id}"]`);
                if (itemEl) {
                    itemEl.dataset.status = p.status;
                    itemEl.dataset.masuk = p.waktu_masuk || '';
                    itemEl.dataset.pulang = p.waktu_pulang || '';
                    itemEl.dataset.sesi = p.sesi_ke || 1;
                    itemEl.dataset.durasi = p.durasi_formatted || '';

                    const pill = itemEl.querySelector('.item-status-pill');
                    const dot = itemEl.querySelector('.status-dot');

                    if (pill) {
                        pill.className = 'item-status-pill';
                        if (p.status === 'masuk') {
                            pill.classList.add('pill-masuk');
                            pill.textContent = `Hadir ${p.waktu_masuk || ''}`;
                        } else if (p.status === 'pulang') {
                            pill.classList.add('pill-pulang');
                            pill.textContent = `Pulang ${p.waktu_pulang || ''}`;
                        } else {
                            pill.classList.add('pill-belum');
                            pill.textContent = `Belum Hadir`;
                        }
                    }

                    if (dot) {
                        dot.className = 'status-dot';
                        if (p.status === 'masuk') dot.classList.add('dot-masuk');
                        else if (p.status === 'pulang') dot.classList.add('dot-pulang');
                        else dot.classList.add('dot-belum');
                    }
                }
            });

            // Update detail di panel kanan jika pengurus yang aktif datanya berubah
            const cur = newList.find(p => p.id == activePengurusId);
            if (cur) {
                const heroBadge = document.getElementById('heroStatusBadge');
                const heroStatusDetail = document.getElementById('heroStatusDetail');

                heroBadge.className = 'big-status-badge';
                if (cur.status === 'masuk') {
                    heroBadge.classList.add('badge-masuk-big');
                    heroBadge.innerHTML = `<i class="fa-solid fa-circle-check"></i> <span>Sedang Hadir di Kampus</span>`;
                    heroStatusDetail.textContent = `Masuk pukul ${cur.waktu_masuk || '--:--'} WIB (Sesi ke-${cur.sesi_ke || 1})`;
                } else if (cur.status === 'pulang') {
                    heroBadge.classList.add('badge-pulang-big');
                    heroBadge.innerHTML = `<i class="fa-solid fa-door-open"></i> <span>Sudah Pulang</span>`;
                    heroStatusDetail.textContent = `Masuk: ${cur.waktu_masuk || '--:--'} → Pulang: ${cur.waktu_pulang || '--:--'} WIB ${cur.durasi_formatted ? '· Total ' + cur.durasi_formatted : ''}`;
                } else {
                    heroBadge.classList.add('badge-belum-big');
                    heroBadge.innerHTML = `<i class="fa-regular fa-clock"></i> <span>Belum Presensi</span>`;
                    heroStatusDetail.textContent = `Silakan scan kode QR di bawah untuk presensi masuk hari ini.`;
                }
            }

            // Terapkan kembali filter pencarian & tab
            applyFilters();
        }

        // 8. Filter Pencarian & Kategori Tab
        let currentFilter = 'all';
        const searchInput = document.getElementById('searchInput');
        const searchClear = document.getElementById('searchClear');

        function applyFilters() {
            const query = (searchInput.value || '').trim().toLowerCase();
            const items = document.querySelectorAll('.pengurus-item');
            let visibleCount = 0;

            items.forEach(item => {
                const nama = (item.dataset.nama || '').toLowerCase();
                const jabatan = (item.dataset.jabatan || '').toLowerCase();
                const status = item.dataset.status || 'belum';

                const matchQuery = !query || nama.includes(query) || jabatan.includes(query);
                const matchTab = (currentFilter === 'all') || (status === currentFilter);

                if (matchQuery && matchTab) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            const noResult = document.getElementById('noSearchResults');
            if (noResult) {
                noResult.style.display = (visibleCount === 0) ? 'block' : 'none';
            }
        }

        searchInput.addEventListener('input', () => {
            searchClear.style.display = searchInput.value ? 'block' : 'none';
            applyFilters();
        });

        searchClear.addEventListener('click', () => {
            searchInput.value = '';
            searchClear.style.display = 'none';
            searchInput.focus();
            applyFilters();
        });

        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentFilter = btn.dataset.filter;
                applyFilters();
            });
        });

        // Event listener saat item pengurus di-klik
        document.getElementById('pengurusList').addEventListener('click', e => {
            const item = e.target.closest('.pengurus-item');
            if (item && item.dataset.id) {
                selectPengurus(item.dataset.id);
            }
        });

        // 9. Salin Link QR ke Clipboard
        document.getElementById('btnCopyQr').addEventListener('click', () => {
            const targetUrl = qrTokens[activePengurusId];
            if (!targetUrl) return;

            navigator.clipboard.writeText(targetUrl).then(() => {
                const btn = document.getElementById('btnCopyQr');
                const orig = btn.innerHTML;
                btn.innerHTML = `<i class="fa-solid fa-check" style="color:var(--brand-600)"></i>`;
                setTimeout(() => { btn.innerHTML = orig; }, 2000);
            });
        });

        // 10. Timer Jam, Tanggal Masehi & Hijriah
        function updateClock() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('clockBox').innerHTML = `${h}:${m}:${s} <small>WIB</small>`;

            // Tanggal Masehi
            const optMasehi = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
            document.getElementById('dateMasehi').textContent = now.toLocaleDateString('id-ID', optMasehi);

            // Tanggal Hijriah
            try {
                const hijriahOffset = <?= (int) $hijriahOffset ?>;
                const hd = new Date(now.getTime() + hijriahOffset * 864e5);
                const hijriText = new Intl.DateTimeFormat('id-ID-u-ca-islamic-umalqura', { 
                    day: 'numeric', 
                    month: 'long', 
                    year: 'numeric' 
                }).format(hd);
                document.getElementById('dateHijri').textContent = `${hijriText} H`;
            } catch (e) {
                document.getElementById('dateHijri').textContent = '';
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // 11. Countdown Perputaran Token QR (Window 30 Detik)
        const countdownEl = document.getElementById('countdownSec');
        setInterval(() => {
            countdownSec--;
            if (countdownSec <= 0) {
                countdownSec = 30;
                fetchQrTokens();
            }
            if (countdownEl) {
                countdownEl.textContent = Math.max(0, countdownSec);
            }
        }, 1000);

        // 12. Fullscreen Button Toggle
        const btnFullscreen = document.getElementById('btnFullscreen');
        btnFullscreen.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
                btnFullscreen.classList.add('active');
                btnFullscreen.querySelector('span').textContent = 'Kecilkan';
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen().catch(() => {});
                }
                btnFullscreen.classList.remove('active');
                btnFullscreen.querySelector('span').textContent = 'Layar Penuh';
            }
        });

        // 13. Audio Button Toggle
        const btnAudio = document.getElementById('btnAudio');
        btnAudio.addEventListener('click', () => {
            isAudioEnabled = !isAudioEnabled;
            if (isAudioEnabled) {
                btnAudio.classList.add('active');
                btnAudio.querySelector('i').className = 'fa-solid fa-volume-high';
                document.getElementById('audioLabel').textContent = 'Suara Aktif';
            } else {
                btnAudio.classList.remove('active');
                btnAudio.querySelector('i').className = 'fa-solid fa-volume-xmark';
                document.getElementById('audioLabel').textContent = 'Suara Mati';
            }
        });

        // 14. Manual Refresh Button
        document.getElementById('btnRefresh').addEventListener('click', async () => {
            const btn = document.getElementById('btnRefresh');
            btn.querySelector('i').classList.add('fa-spin');
            await Promise.all([fetchQrTokens(), pollState()]);
            setTimeout(() => { btn.querySelector('i').classList.remove('fa-spin'); }, 600);
        });

        // 15. Navigasi Keyboard (Panah Atas / Bawah)
        window.addEventListener('keydown', e => {
            if (e.target === searchInput) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const visibleItems = Array.from(document.querySelectorAll('.pengurus-item'))
                                         .filter(el => el.style.display !== 'none');
                if (visibleItems.length === 0) return;
                const curIdx = visibleItems.findIndex(el => el.dataset.id == activePengurusId);
                let nextIdx = 0;
                if (e.key === 'ArrowDown') {
                    nextIdx = curIdx < visibleItems.length - 1 ? curIdx + 1 : 0;
                } else {
                    nextIdx = curIdx > 0 ? curIdx - 1 : visibleItems.length - 1;
                }
                selectPengurus(visibleItems[nextIdx].dataset.id);
            } else if (e.key === '/' || e.key === 'f') {
                if (e.key === '/') {
                    e.preventDefault();
                    searchInput.focus();
                }
            }
        });

        // 16. Inisialisasi awal saat halaman dibuka
        window.addEventListener('DOMContentLoaded', async () => {
            // Cek jika ada hash di URL misal #p3 atau #3
            const hash = window.location.hash.replace('#p', '').replace('#', '');
            if (hash && pengurusData.some(p => p.id == hash)) {
                activePengurusId = hash;
            }

            selectPengurus(activePengurusId);
            await fetchQrTokens();

            // Polling background setiap 4 detik untuk update presensi realtime
            setInterval(pollState, 4000);
        });
    </script>
</body>
</html>
