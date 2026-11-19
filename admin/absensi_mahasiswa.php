<?php
/**
 * absensi_mahasiswa.php - Rekapitulasi Presensi Mahasiswa Kampus
 * STAI Sidogiri - Sistem Informasi Layar Kampus
 */

require_once __DIR__ . '/auth.php';
$admin = requireAdminAuth();
$pdo = getAdminPdo();

require_once dirname(__DIR__) . '/api/v1/dosen/helper.php';
ensurePresensiMahasiswaTables($pdo);

date_default_timezone_set('Asia/Jakarta');

// Format tanggal lokal Indonesia
if (!function_exists('tglIndo')) {
    function tglIndo(string $tgl): string {
        $parts = explode('-', $tgl);
        if (count($parts) !== 3) return $tgl;
        $bulanIndo = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
            '04' => 'April', '05' => 'Mei', '06' => 'Juni',
            '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
            '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        return (int)$parts[2] . ' ' . ($bulanIndo[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
    }
}

// -------------------------------------------------------------
// 1. ENDPOINT AJAX: DETAIL RIWAYAT PRESENSI PER MAHASISWA
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'detail_mhs_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    $mhsId = (int)($_GET['id'] ?? 0);
    $stmtM = $pdo->prepare("SELECT m.*, pr.nama AS prodi_nama FROM `mahasiswa` m JOIN `prodi` pr ON pr.id = m.prodi_id WHERE m.id = :id LIMIT 1");
    $stmtM->execute(['id' => $mhsId]);
    $mhs = $stmtM->fetch(PDO::FETCH_ASSOC);

    if (!$mhs) {
        echo json_encode(['success' => false, 'message' => 'Mahasiswa tidak ditemukan.']);
        exit;
    }

    $mhsNim = trim($mhs['nim'] ?? '');
    $mhsNama = trim($mhs['nama'] ?? '');

    $whereCond = [];
    $paramsH = [];
    if (!empty($mhsNim)) {
        $whereCond[] = "pm.nim = :nim";
        $paramsH['nim'] = $mhsNim;
    }
    if (!empty($mhsNama)) {
        $whereCond[] = "LOWER(TRIM(pm.nama)) = LOWER(TRIM(:nama))";
        $paramsH['nama'] = $mhsNama;
    }

    $whereSql = !empty($whereCond) ? implode(' OR ', $whereCond) : "1=0";

    $sqlHistory = "SELECT pm.status, pm.catatan, pm.created_at,
                          jp.pertemuan_ke, jp.tanggal, jp.jam_mulai, jp.jam_selesai, jp.materi,
                          jk.mata_kuliah, jk.kelas,
                          d.nama AS dosen_nama
                   FROM `presensi_mahasiswa` pm
                   JOIN `jurnal_perkuliahan` jp ON jp.id = pm.jurnal_id
                   JOIN `jadwal_kuliah` jk ON jk.id = pm.jadwal_id
                   LEFT JOIN `dosen` d ON d.id = jk.dosen_id
                   WHERE ({$whereSql})
                   ORDER BY jp.tanggal DESC, jp.pertemuan_ke DESC";
    $stmtH = $pdo->prepare($sqlHistory);
    $stmtH->execute($paramsH);
    $logs = $stmtH->fetchAll(PDO::FETCH_ASSOC);

    // Hitung ringkasan
    $tot = count($logs);
    $h = 0; $i = 0; $s = 0; $a = 0;
    foreach ($logs as $l) {
        $st = strtolower($l['status']);
        if ($st === 'hadir') $h++;
        elseif ($st === 'izin') $i++;
        elseif ($st === 'sakit') $s++;
        elseif ($st === 'alpa') $a++;
    }
    $pct = $tot > 0 ? round(($h / $tot) * 100, 1) : 0;

    echo json_encode([
        'success' => true,
        'mahasiswa' => $mhs,
        'stat' => [
            'total' => $tot,
            'hadir' => $h,
            'izin' => $i,
            'sakit' => $s,
            'alpa' => $a,
            'pct' => $pct
        ],
        'logs' => $logs
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. AMBIL MASTER FILTER (PRODI, ANGKATAN, KELAS)
// -------------------------------------------------------------
$prodiList = $pdo->query("SELECT id, kode, nama FROM `prodi` WHERE aktif = 1 ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);

$angkatanList = $pdo->query("SELECT DISTINCT tahun_angkatan FROM `mahasiswa` WHERE aktif = 1 AND tahun_angkatan > 0 ORDER BY tahun_angkatan DESC")->fetchAll(PDO::FETCH_COLUMN);

$kelasList = $pdo->query("SELECT DISTINCT kelas FROM `mahasiswa` WHERE aktif = 1 AND kelas != '' ORDER BY kelas ASC")->fetchAll(PDO::FETCH_COLUMN);

// Parameter Filter
$tab = $_GET['tab'] ?? 'mhs'; // 'mhs', 'angkatan', 'terendah'
$f_angkatan = $_GET['f_angkatan'] ?? '';
$f_prodi = $_GET['f_prodi'] ?? '';
$f_kelas = $_GET['f_kelas'] ?? '';
$f_status = $_GET['f_status'] ?? '';
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? ($tab === 'terendah' ? 'pct_asc' : 'nama_asc');

// -------------------------------------------------------------
// 3. QUERY REKAP KESELURUHAN MAHASISWA
// -------------------------------------------------------------
$whereMhs = ["m.aktif = 1"];
$paramsMhs = [];

if ($f_angkatan !== '') {
    $whereMhs[] = "m.tahun_angkatan = :f_angkatan";
    $paramsMhs['f_angkatan'] = (int)$f_angkatan;
}
if ($f_prodi !== '') {
    $whereMhs[] = "pr.kode = :f_prodi";
    $paramsMhs['f_prodi'] = $f_prodi;
}
if ($f_kelas !== '') {
    $whereMhs[] = "m.kelas = :f_kelas";
    $paramsMhs['f_kelas'] = $f_kelas;
}
if ($q !== '') {
    $whereMhs[] = "(m.nama LIKE :q OR m.nim LIKE :q OR m.kelas LIKE :q)";
    $paramsMhs['q'] = '%' . $q . '%';
}

$sqlMhs = "SELECT 
            m.id, m.nim, m.nama, m.kelas, m.tahun_angkatan,
            pr.kode AS prodi_kode, pr.nama AS prodi_nama,
            COUNT(pm.id) AS total_sesi,
            COALESCE(SUM(CASE WHEN pm.status = 'hadir' THEN 1 ELSE 0 END), 0) AS jml_hadir,
            COALESCE(SUM(CASE WHEN pm.status = 'izin' THEN 1 ELSE 0 END), 0) AS jml_izin,
            COALESCE(SUM(CASE WHEN pm.status = 'sakit' THEN 1 ELSE 0 END), 0) AS jml_sakit,
            COALESCE(SUM(CASE WHEN pm.status = 'alpa' THEN 1 ELSE 0 END), 0) AS jml_alpa
        FROM `mahasiswa` m
        JOIN `prodi` pr ON pr.id = m.prodi_id
        LEFT JOIN `presensi_mahasiswa` pm ON (
            (m.nim IS NOT NULL AND m.nim != '' AND pm.nim = m.nim)
            OR ((m.nim IS NULL OR m.nim = '' OR pm.nim IS NULL OR pm.nim = '') AND LOWER(TRIM(pm.nama)) = LOWER(TRIM(m.nama)))
        )
        WHERE " . implode(' AND ', $whereMhs) . "
        GROUP BY m.id, m.nim, m.nama, m.kelas, m.tahun_angkatan, pr.kode, pr.nama";

$stmtMhs = $pdo->prepare($sqlMhs);
$stmtMhs->execute($paramsMhs);
$allMhsRaw = $stmtMhs->fetchAll(PDO::FETCH_ASSOC);

// Hitung persentase & status
$studentList = [];
$totalAllSesi = 0;
$totalAllHadir = 0;
$criticalCount = 0; // < 75%
$lowestAttendanceList = [];

foreach ($allMhsRaw as $m) {
    $tot = (int)$m['total_sesi'];
    $h = (int)$m['jml_hadir'];
    $pct = $tot > 0 ? round(($h / $tot) * 100, 1) : 0;
    
    // Status kategori
    if ($tot === 0) {
        $statusKat = 'belum';
        $statusLabel = 'Belum Ada Sesi';
        $statusClass = 'badge off';
    } elseif ($pct >= 75) {
        $statusKat = 'aman';
        $statusLabel = 'Aman (≥75%)';
        $statusClass = 'badge ok';
    } elseif ($pct >= 50) {
        $statusKat = 'peringatan';
        $statusLabel = 'Peringatan (50-74%)';
        $statusClass = 'badge' ;
    } else {
        $statusKat = 'kritis';
        $statusLabel = 'Kritis (<50%)';
        $statusClass = 'badge err';
    }

    if ($tot > 0 && $pct < 75) {
        $criticalCount++;
        $lowestAttendanceList[] = array_merge($m, [
            'pct' => $pct,
            'status_kat' => $statusKat,
            'status_label' => $statusLabel
        ]);
    }

    $totalAllSesi += $tot;
    $totalAllHadir += $h;

    // Filter status jika dipilih
    if ($f_status !== '' && $statusKat !== $f_status) {
        continue;
    }

    $studentList[] = array_merge($m, [
        'pct' => $pct,
        'status_kat' => $statusKat,
        'status_label' => $statusLabel,
        'status_class' => $statusClass
    ]);
}

// Urutkan studentList
usort($studentList, function($a, $b) use ($sort) {
    if ($sort === 'pct_asc') {
        if ($a['total_sesi'] == 0 && $b['total_sesi'] > 0) return 1;
        if ($b['total_sesi'] == 0 && $a['total_sesi'] > 0) return -1;
        return $a['pct'] <=> $b['pct'];
    } elseif ($sort === 'pct_desc') {
        return $b['pct'] <=> $a['pct'];
    } elseif ($sort === 'nim_asc') {
        return strcasecmp($a['nim'], $b['nim']);
    } else {
        return strcasecmp($a['nama'], $b['nama']);
    }
});

// Urutkan lowestAttendanceList berdasarkan persentase terendah
usort($lowestAttendanceList, function($a, $b) {
    if ($a['pct'] == $b['pct']) {
        return $b['jml_alpa'] <=> $a['jml_alpa'];
    }
    return $a['pct'] <=> $b['pct'];
});

// -------------------------------------------------------------
// 4. QUERY STATISTIK PER ANGKATAN
// -------------------------------------------------------------
$sqlAngkatan = "SELECT 
                    m.tahun_angkatan,
                    COUNT(DISTINCT m.id) AS total_mhs,
                    COUNT(pm.id) AS total_presensi,
                    COALESCE(SUM(CASE WHEN pm.status = 'hadir' THEN 1 ELSE 0 END), 0) AS jml_hadir,
                    COALESCE(SUM(CASE WHEN pm.status = 'izin' THEN 1 ELSE 0 END), 0) AS jml_izin,
                    COALESCE(SUM(CASE WHEN pm.status = 'sakit' THEN 1 ELSE 0 END), 0) AS jml_sakit,
                    COALESCE(SUM(CASE WHEN pm.status = 'alpa' THEN 1 ELSE 0 END), 0) AS jml_alpa
                FROM `mahasiswa` m
                LEFT JOIN `presensi_mahasiswa` pm ON (
                    (m.nim IS NOT NULL AND m.nim != '' AND pm.nim = m.nim)
                    OR ((m.nim IS NULL OR m.nim = '' OR pm.nim IS NULL OR pm.nim = '') AND LOWER(TRIM(pm.nama)) = LOWER(TRIM(m.nama)))
                )
                WHERE m.aktif = 1
                GROUP BY m.tahun_angkatan
                ORDER BY m.tahun_angkatan DESC";
$angkatanData = $pdo->query($sqlAngkatan)->fetchAll(PDO::FETCH_ASSOC);

foreach ($angkatanData as &$ak) {
    $totP = (int)$ak['total_presensi'];
    $h = (int)$ak['jml_hadir'];
    $ak['pct'] = $totP > 0 ? round(($h / $totP) * 100, 1) : 0;
}
unset($ak);

// Statistik Keseluruhan
$totalMhsAktif = count($allMhsRaw);
$campusAvgPct = $totalAllSesi > 0 ? round(($totalAllHadir / $totalAllSesi) * 100, 1) : 0;

$stmtTotJurnal = $pdo->query("SELECT COUNT(*) FROM `jurnal_perkuliahan`");
$totalJurnalSessions = (int)$stmtTotJurnal->fetchColumn();

// -------------------------------------------------------------
// 5. FITUR DOWNLOAD LAPORAN PRESENSI (CSV EXPORT)
// -------------------------------------------------------------
if (isset($_GET['action'])) {
    $act = $_GET['action'];

    if ($act === 'export_mhs') {
        // Ekspor Rekap Mahasiswa
        $filename = 'rekap_presensi_mahasiswa_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        $fp = fopen('php://output', 'w');

        fputcsv($fp, ['REKAPITULASI PRESENSI MAHASISWA - STAI SIDOGIRI'], ';');
        fputcsv($fp, ['Tanggal Unduh', date('d/m/Y H:i:s')], ';');
        fputcsv($fp, ['Filter Angkatan', $f_angkatan ?: 'Semua Angkatan'], ';');
        fputcsv($fp, ['Filter Prodi', $f_prodi ?: 'Semua Prodi'], ';');
        fputcsv($fp, ['Filter Kelas', $f_kelas ?: 'Semua Kelas'], ';');
        fputcsv($fp, [], ';');

        fputcsv($fp, [
            'No', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Angkatan', 'Kelas',
            'Hadir (H)', 'Izin (I)', 'Sakit (S)', 'Alpa (A)', 'Total Pertemuan', 'Persentase (%)', 'Status Kehadiran'
        ], ';');

        foreach ($studentList as $idx => $st) {
            fputcsv($fp, [
                $idx + 1,
                $st['nim'] ?: '-',
                $st['nama'],
                $st['prodi_nama'],
                $st['tahun_angkatan'],
                $st['kelas'],
                $st['jml_hadir'],
                $st['jml_izin'],
                $st['jml_sakit'],
                $st['jml_alpa'],
                $st['total_sesi'],
                $st['pct'] . '%',
                $st['status_label']
            ], ';');
        }
        fclose($fp);
        exit;

    } elseif ($act === 'export_angkatan') {
        // Ekspor Rekap Per Angkatan
        $filename = 'rekap_presensi_per_angkatan_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $fp = fopen('php://output', 'w');

        fputcsv($fp, ['REKAPITULASI PRESENSI MAHASISWA PER ANGKATAN - STAI SIDOGIRI'], ';');
        fputcsv($fp, ['Tanggal Unduh', date('d/m/Y H:i:s')], ';');
        fputcsv($fp, [], ';');

        fputcsv($fp, [
            'No', 'Tahun Angkatan', 'Total Mahasiswa', 'Sesi Hadir (H)', 'Sesi Izin (I)',
            'Sesi Sakit (S)', 'Sesi Alpa (A)', 'Total Presensi Terdata', 'Persentase Rata-rata (%)', 'Keterangan'
        ], ';');

        foreach ($angkatanData as $idx => $ak) {
            fputcsv($fp, [
                $idx + 1,
                'Angkatan ' . $ak['tahun_angkatan'],
                $ak['total_mhs'] . ' Mahasiswa',
                $ak['jml_hadir'],
                $ak['jml_izin'],
                $ak['jml_sakit'],
                $ak['jml_alpa'],
                $ak['total_presensi'],
                $ak['pct'] . '%',
                $ak['pct'] >= 75 ? 'Memenuhi Standar' : 'Perlu Perhatian (<75%)'
            ], ';');
        }
        fclose($fp);
        exit;

    } elseif ($act === 'export_terendah') {
        // Ekspor Siswa Kehadiran Terendah
        $filename = 'mahasiswa_kehadiran_terendah_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $fp = fopen('php://output', 'w');

        fputcsv($fp, ['DAFTAR MAHASISWA DENGAN PERSENTASE KEHADIRAN TERENDAH (<75%) - STAI SIDOGIRI'], ';');
        fputcsv($fp, ['Tanggal Unduh', date('d/m/Y H:i:s')], ';');
        fputcsv($fp, ['Total Mahasiswa Kritis/Peringatan', count($lowestAttendanceList) . ' Orang'], ';');
        fputcsv($fp, [], ';');

        fputcsv($fp, [
            'Peringkat', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Angkatan', 'Kelas',
            'Hadir', 'Izin', 'Sakit', 'Alpa', 'Total Pertemuan', 'Persentase (%)', 'Status Evaluasi'
        ], ';');

        foreach ($lowestAttendanceList as $idx => $st) {
            fputcsv($fp, [
                $idx + 1,
                $st['nim'] ?: '-',
                $st['nama'],
                $st['prodi_nama'],
                $st['tahun_angkatan'],
                $st['kelas'],
                $st['jml_hadir'],
                $st['jml_izin'],
                $st['jml_sakit'],
                $st['jml_alpa'],
                $st['total_sesi'],
                $st['pct'] . '%',
                $st['status_label']
            ], ';');
        }
        fclose($fp);
        exit;
    }
}

// -------------------------------------------------------------
// 6. TAMPILAN HALAMAN (HTML)
// -------------------------------------------------------------
require_once __DIR__ . '/header.php';
?>

<style>
/* Custom Style untuk Rekap Mahasiswa */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.kpi-card {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    font-size: 20px;
    flex-shrink: 0;
}
.kpi-val { font-size: 26px; font-weight: 800; color: var(--ink); line-height: 1.1; }
.kpi-lbl { font-size: 12.5px; font-weight: 600; color: var(--muted); margin-top: 4px; }

/* Tabs Nav */
.tabs-nav {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid var(--line);
    margin-bottom: 20px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.tab-link {
    padding: 12px 20px;
    font-size: 14px;
    font-weight: 700;
    color: var(--muted);
    text-decoration: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all .2s;
    white-space: nowrap;
}
.tab-link:hover { color: var(--g-dark); }
.tab-link.active {
    color: var(--g-dark);
    border-bottom-color: var(--g);
    background: #fff;
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
}

/* Progress bar */
.progress-bar-bg {
    width: 100%;
    height: 8px;
    background: #e2e8f0;
    border-radius: 99px;
    overflow: hidden;
    margin-top: 6px;
}
.progress-bar-fill {
    height: 100%;
    border-radius: 99px;
    transition: width .4s ease;
}

/* Modal Popup */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal-card {
    background: #fff;
    border-radius: 20px;
    max-width: 780px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 24px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}

/* Print Only Layout */
.print-only { display: none; }
@media print {
    body { background: #fff !important; }
    aside, header, .tabs-nav, .filter-card, .btn, .no-print { display: none !important; }
    main { padding: 0 !important; margin: 0 !important; width: 100% !important; }
    .content { max-width: 100% !important; padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; padding: 0 !important; }
    .print-only { display: block !important; }
    .print-header { text-align: center; border-bottom: 2.5px solid #000; padding-bottom: 12px; margin-bottom: 20px; }
    .print-header h2 { font-size: 18px; font-weight: 800; text-transform: uppercase; margin: 0; }
    .print-header p { font-size: 12px; color: #333; margin: 3px 0; }
    table { width: 100% !important; border: 1px solid #000 !important; font-size: 11px !important; }
    th, td { border: 1px solid #000 !important; padding: 6px 8px !important; }
    th { background: #eee !important; color: #000 !important; }
    .print-signatures {
        display: grid;
        grid-template-columns: 1fr 1fr;
        margin-top: 40px;
        page-break-inside: avoid;
        text-align: center;
        font-size: 12px;
    }
    .sign-box { padding-top: 60px; }
}
</style>

<div class="content">

    <!-- KPI STATS OVERVIEW -->
    <div class="kpi-grid no-print">
        <div class="kpi-card">
            <div class="kpi-icon" style="background:var(--g-soft);color:var(--g-dark)">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
            <div>
                <div class="kpi-val"><?= number_format($totalMhsAktif, 0, ',', '.') ?></div>
                <div class="kpi-lbl">Total Mahasiswa Terdaftar</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon" style="background:#e0f2fe;color:#0284c7">
                <i class="fa-solid fa-chalkboard-user"></i>
            </div>
            <div>
                <div class="kpi-val"><?= $totalJurnalSessions ?></div>
                <div class="kpi-lbl">Sesi Perkuliahan Terlaksana</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon" style="background:#fef3c7;color:#b45309">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <div>
                <div class="kpi-val"><?= $campusAvgPct ?>%</div>
                <div class="kpi-lbl">Rata-rata Kehadiran Kampus</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon" style="background:<?= $criticalCount > 0 ? '#fee2e2' : '#f1f5f9' ?>;color:<?= $criticalCount > 0 ? '#b91c1c' : '#64748b' ?>">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <div class="kpi-val" style="color:<?= $criticalCount > 0 ? '#b91c1c' : 'inherit' ?>"><?= $criticalCount ?></div>
                <div class="kpi-lbl">Siswa Kehadiran Rendah (&lt;75%)</div>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <div class="tabs-nav no-print">
        <a href="?tab=mhs&f_angkatan=<?= urlencode($f_angkatan) ?>&f_prodi=<?= urlencode($f_prodi) ?>&f_kelas=<?= urlencode($f_kelas) ?>" class="tab-link <?= $tab === 'mhs' ? 'active' : '' ?>">
            <span>🧑‍🎓 Rekap Per Siswa</span>
            <span class="badge ok" style="font-size:11px"><?= count($studentList) ?></span>
        </a>
        <a href="?tab=angkatan" class="tab-link <?= $tab === 'angkatan' ? 'active' : '' ?>">
            <span>📊 Persentase Tiap Angkatan</span>
            <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:11px"><?= count($angkatanData) ?> Angkatan</span>
        </a>
        <a href="?tab=terendah" class="tab-link <?= $tab === 'terendah' ? 'active' : '' ?>">
            <span>⚠️ Siswa Kehadiran Terendah</span>
            <?php if ($criticalCount > 0): ?>
                <span class="badge err" style="font-size:11px"><?= $criticalCount ?> Kritis/Perhatian</span>
            <?php else: ?>
                <span class="badge ok" style="font-size:11px">Nihil</span>
            <?php endif; ?>
        </a>
    </div>

    <!-- HEADER PRINT (Hanya keluar saat cetak) -->
    <div class="print-only">
        <div class="print-header">
            <h2>STAI SIDOGIRI PASURUAN</h2>
            <p>Sistem Informasi Administrasi Akademik &amp; Presensi Perkuliahan</p>
            <p style="font-weight:700;font-size:14px;margin-top:6px">
                <?php
                    if ($tab === 'angkatan') echo "LAPORAN REKAPITULASI PRESENSI MAHASISWA PER ANGKATAN";
                    elseif ($tab === 'terendah') echo "LAPORAN MAHASISWA DENGAN KEHADIRAN TERENDAH (<75%)";
                    else echo "LAPORAN REKAPITULASI KEHADIRAN MAHASISWA";
                ?>
            </p>
            <p style="font-size:11px;color:#555">Dicetak pada: <?= date('d/m/Y H:i:s') ?> oleh <?= htmlspecialchars($admin['name']) ?></p>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- TAB 1: REKAPITULASI PER SISWA -->
    <!-- ============================================================= -->
    <?php if ($tab === 'mhs'): ?>
        <div class="card filter-card no-print">
            <form method="GET" action="absensi_mahasiswa.php" style="display:flex;flex-direction:column;gap:12px">
                <input type="hidden" name="tab" value="mhs">

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <div style="flex:2;min-width:200px">
                        <input type="text" name="q" placeholder="Cari Nama / NIM / Kelas Mahasiswa..." value="<?= htmlspecialchars($q) ?>" style="width:100%;padding:9px 14px;border:1px solid var(--line);border-radius:10px;font-size:13.5px">
                    </div>

                    <div style="flex:1;min-width:140px">
                        <select name="f_angkatan" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:10px;font-size:13px">
                            <option value="">Semua Angkatan</option>
                            <?php foreach ($angkatanList as $ak): ?>
                                <option value="<?= $ak ?>" <?= (string)$f_angkatan === (string)$ak ? 'selected' : '' ?>>Angkatan <?= $ak ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex:1;min-width:140px">
                        <select name="f_prodi" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:10px;font-size:13px">
                            <option value="">Semua Prodi</option>
                            <?php foreach ($prodiList as $pr): ?>
                                <option value="<?= htmlspecialchars($pr['kode']) ?>" <?= $f_prodi === $pr['kode'] ? 'selected' : '' ?>><?= htmlspecialchars($pr['kode']) ?> - <?= htmlspecialchars($pr['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex:1;min-width:120px">
                        <select name="f_kelas" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:10px;font-size:13px">
                            <option value="">Semua Kelas</option>
                            <?php foreach ($kelasList as $kls): ?>
                                <option value="<?= htmlspecialchars($kls) ?>" <?= $f_kelas === $kls ? 'selected' : '' ?>><?= htmlspecialchars($kls) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex:1;min-width:140px">
                        <select name="f_status" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:10px;font-size:13px">
                            <option value="">Semua Status</option>
                            <option value="aman" <?= $f_status === 'aman' ? 'selected' : '' ?>>Aman (≥75%)</option>
                            <option value="peringatan" <?= $f_status === 'peringatan' ? 'selected' : '' ?>>Peringatan (50-74%)</option>
                            <option value="kritis" <?= $f_status === 'kritis' ? 'selected' : '' ?>>Kritis (&lt;50%)</option>
                            <option value="belum" <?= $f_status === 'belum' ? 'selected' : '' ?>>Belum Ada Sesi</option>
                        </select>
                    </div>

                    <div style="flex:1;min-width:140px">
                        <select name="sort" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:10px;font-size:13px">
                            <option value="nama_asc" <?= $sort === 'nama_asc' ? 'selected' : '' ?>>Nama (A-Z)</option>
                            <option value="pct_asc" <?= $sort === 'pct_asc' ? 'selected' : '' ?>>% Kehadiran Terendah</option>
                            <option value="pct_desc" <?= $sort === 'pct_desc' ? 'selected' : '' ?>>% Kehadiran Tertinggi</option>
                            <option value="nim_asc" <?= $sort === 'nim_asc' ? 'selected' : '' ?>>NIM (Terkecil)</option>
                        </select>
                    </div>

                    <div style="display:flex;gap:6px">
                        <button type="submit" class="btn" style="padding:9px 16px"><i class="fa-solid fa-filter"></i> Filter</button>
                        <a href="absensi_mahasiswa.php?tab=mhs" class="btn btn-secondary" style="padding:9px 12px" title="Reset Filter"><i class="fa-solid fa-rotate-left"></i></a>
                    </div>
                </div>
            </form>
        </div>

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
                <div>
                    <h3 style="margin-bottom:2px">Rekapitulasi Kehadiran Mahasiswa</h3>
                    <small style="color:var(--muted)">Menampilkan <b><?= count($studentList) ?></b> mahasiswa terdaftar sesuai filter.</small>
                </div>

                <div style="display:flex;gap:8px" class="no-print">
                    <a href="absensi_mahasiswa.php?action=export_mhs&f_angkatan=<?= urlencode($f_angkatan) ?>&f_prodi=<?= urlencode($f_prodi) ?>&f_kelas=<?= urlencode($f_kelas) ?>&f_status=<?= urlencode($f_status) ?>&q=<?= urlencode($q) ?>" class="btn" style="background:#0284c7" title="Download Format CSV/Excel">
                        <i class="fa-solid fa-file-excel"></i> <span>Download CSV</span>
                    </a>
                    <button type="button" onclick="window.print()" class="btn btn-secondary" title="Cetak Format Resmi">
                        <i class="fa-solid fa-print"></i> <span>Cetak Laporan</span>
                    </button>
                </div>
            </div>

            <?php if (empty($studentList)): ?>
                <div style="background:#f8fafc;border:1.5px dashed var(--line);border-radius:12px;padding:32px;text-align:center;color:var(--muted)">
                    <i class="fa-solid fa-user-xmark" style="font-size:36px;margin-bottom:8px;color:#94a3b8"></i>
                    <h4>Tidak Ditemukan Data Mahasiswa</h4>
                    <p style="font-size:13px;margin-top:4px">Silakan sesuaikan kata kunci pencarian atau reset filter.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:40px;text-align:center">No</th>
                                <th style="width:120px">NIM</th>
                                <th style="min-width:180px">Nama Mahasiswa</th>
                                <th>Prodi</th>
                                <th style="text-align:center">Angkatan</th>
                                <th>Kelas</th>
                                <th style="text-align:center" title="Hadir">H</th>
                                <th style="text-align:center" title="Izin">I</th>
                                <th style="text-align:center" title="Sakit">S</th>
                                <th style="text-align:center" title="Alpa">A</th>
                                <th style="text-align:center">Total</th>
                                <th style="min-width:130px">Persentase</th>
                                <th style="text-align:center" class="no-print">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentList as $idx => $st): ?>
                                <?php
                                    $fillColor = $st['pct'] >= 75 ? 'var(--g)' : ($st['pct'] >= 50 ? '#d97706' : '#dc2626');
                                ?>
                                <tr>
                                    <td style="text-align:center;color:var(--muted);font-weight:700"><?= $idx + 1 ?></td>
                                    <td style="font-family:monospace;font-weight:700;font-size:13px;color:#1e293b">
                                        <?= htmlspecialchars($st['nim'] ?: '-') ?>
                                    </td>
                                    <td>
                                        <b style="color:var(--ink)"><?= htmlspecialchars($st['nama']) ?></b>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:#f1f5f9;color:#334155;font-size:11px" title="<?= htmlspecialchars($st['prodi_nama']) ?>">
                                            <?= htmlspecialchars($st['prodi_kode']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;font-weight:600"><?= $st['tahun_angkatan'] ?></td>
                                    <td><?= htmlspecialchars($st['kelas']) ?></td>
                                    <td style="text-align:center;font-weight:700;color:var(--g-dark)"><?= $st['jml_hadir'] ?></td>
                                    <td style="text-align:center;font-weight:700;color:#0284c7"><?= $st['jml_izin'] ?></td>
                                    <td style="text-align:center;font-weight:700;color:#b45309"><?= $st['jml_sakit'] ?></td>
                                    <td style="text-align:center;font-weight:700;color:#b91c1c"><?= $st['jml_alpa'] ?></td>
                                    <td style="text-align:center;font-weight:700;color:var(--muted)"><?= $st['total_sesi'] ?></td>
                                    <td>
                                        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px">
                                            <b style="font-size:13px;color:<?= $fillColor ?>"><?= $st['pct'] ?>%</b>
                                            <span class="<?= $st['status_class'] ?>" style="font-size:10.5px"><?= $st['status_label'] ?></span>
                                        </div>
                                        <?php if ($st['total_sesi'] > 0): ?>
                                            <div class="progress-bar-bg">
                                                <div class="progress-bar-fill" style="width:<?= min(100, $st['pct']) ?>%;background:<?= $fillColor ?>"></div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center" class="no-print">
                                        <button type="button" onclick="showStudentDetail(<?= $st['id'] ?>)" class="btn btn-secondary" style="padding:4px 8px;font-size:12px" title="Lihat Detail Riwayat Kehadiran">
                                            <i class="fa-solid fa-eye"></i> Detail
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <!-- ============================================================= -->
    <!-- TAB 2: ANALISIS PERSENTASE TIAP ANGKATAN -->
    <!-- ============================================================= -->
    <?php elseif ($tab === 'angkatan'): ?>
        
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;margin-bottom:24px" class="no-print">
            <?php foreach ($angkatanData as $ak): ?>
                <?php
                    $akPct = $ak['pct'];
                    $color = $akPct >= 80 ? 'var(--g)' : ($akPct >= 75 ? '#0284c7' : ($akPct >= 50 ? '#d97706' : '#dc2626'));
                    $bgColor = $akPct >= 80 ? 'var(--g-soft)' : ($akPct >= 75 ? '#e0f2fe' : ($akPct >= 50 ? '#fef3c7' : '#fee2e2'));
                ?>
                <div class="card" style="margin-bottom:0">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start">
                        <div>
                            <span class="badge" style="background:<?= $bgColor ?>;color:<?= $color ?>;font-size:12px">
                                🎓 Angkatan <?= $ak['tahun_angkatan'] ?>
                            </span>
                            <div style="margin-top:10px;font-size:32px;font-weight:800;color:<?= $color ?>">
                                <?= $akPct ?>%
                            </div>
                            <small style="color:var(--muted);font-weight:600">Rata-rata Kehadiran Angkatan</small>
                        </div>
                        <div style="text-align:right">
                            <b style="font-size:18px;display:block"><?= $ak['total_mhs'] ?></b>
                            <small style="color:var(--muted)">Mahasiswa</small>
                        </div>
                    </div>

                    <div class="progress-bar-bg" style="height:10px;margin:14px 0 10px">
                        <div class="progress-bar-fill" style="width:<?= min(100, $akPct) ?>%;background:<?= $color ?>"></div>
                    </div>

                    <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--muted);border-top:1px dashed var(--line);padding-top:10px;margin-top:6px">
                        <span>Hadir: <b style="color:var(--g-dark)"><?= $ak['jml_hadir'] ?></b></span>
                        <span>Izin: <b style="color:#0284c7"><?= $ak['jml_izin'] ?></b></span>
                        <span>Sakit: <b style="color:#b45309"><?= $ak['jml_sakit'] ?></b></span>
                        <span>Alpa: <b style="color:#b91c1c"><?= $ak['jml_alpa'] ?></b></span>
                    </div>

                    <div style="margin-top:12px;text-align:right">
                        <a href="absensi_mahasiswa.php?tab=mhs&f_angkatan=<?= $ak['tahun_angkatan'] ?>" class="btn btn-secondary" style="font-size:11.5px;padding:5px 12px">
                            Lihat Siswa Angkatan Ini &rarr;
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
                <div>
                    <h3 style="margin-bottom:2px">Tabel Komparasi Kehadiran Antar Angkatan</h3>
                    <small style="color:var(--muted)">Evaluasi kedisiplinan dan persentase kehadiran per tahun angkatan mahasiswa.</small>
                </div>
                <div style="display:flex;gap:8px" class="no-print">
                    <a href="absensi_mahasiswa.php?action=export_angkatan" class="btn" style="background:#0284c7">
                        <i class="fa-solid fa-file-excel"></i> <span>Download CSV Angkatan</span>
                    </a>
                    <button type="button" onclick="window.print()" class="btn btn-secondary">
                        <i class="fa-solid fa-print"></i> <span>Cetak Tabel</span>
                    </button>
                </div>
            </div>

            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;text-align:center">No</th>
                            <th>Tahun Angkatan</th>
                            <th style="text-align:center">Total Mahasiswa</th>
                            <th style="text-align:center" title="Sesi Hadir">Hadir (H)</th>
                            <th style="text-align:center" title="Sesi Izin">Izin (I)</th>
                            <th style="text-align:center" title="Sesi Sakit">Sakit (S)</th>
                            <th style="text-align:center" title="Sesi Alpa">Alpa (A)</th>
                            <th style="text-align:center">Total Presensi</th>
                            <th style="min-width:140px">Tingkat Kehadiran (%)</th>
                            <th style="text-align:center">Status Evaluasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($angkatanData as $idx => $ak): ?>
                            <?php
                                $color = $ak['pct'] >= 75 ? 'var(--g-dark)' : ($ak['pct'] >= 50 ? '#b45309' : '#b91c1c');
                                $statusBadge = $ak['pct'] >= 75 ? '<span class="badge ok">Memenuhi Standar</span>' : '<span class="badge err">Perlu Perhatian</span>';
                            ?>
                            <tr>
                                <td style="text-align:center;color:var(--muted);font-weight:700"><?= $idx + 1 ?></td>
                                <td>
                                    <b style="font-size:14px;color:var(--ink)">Angkatan <?= $ak['tahun_angkatan'] ?></b>
                                </td>
                                <td style="text-align:center;font-weight:700"><?= $ak['total_mhs'] ?> Siswa</td>
                                <td style="text-align:center;font-weight:700;color:var(--g-dark)"><?= number_format($ak['jml_hadir']) ?></td>
                                <td style="text-align:center;font-weight:700;color:#0284c7"><?= number_format($ak['jml_izin']) ?></td>
                                <td style="text-align:center;font-weight:700;color:#b45309"><?= number_format($ak['jml_sakit']) ?></td>
                                <td style="text-align:center;font-weight:700;color:#b91c1c"><?= number_format($ak['jml_alpa']) ?></td>
                                <td style="text-align:center;font-weight:700;color:var(--muted)"><?= number_format($ak['total_presensi']) ?></td>
                                <td>
                                    <b style="font-size:14px;color:<?= $color ?>"><?= $ak['pct'] ?>%</b>
                                    <div class="progress-bar-bg">
                                        <div class="progress-bar-fill" style="width:<?= min(100, $ak['pct']) ?>%;background:<?= $color ?>"></div>
                                    </div>
                                </td>
                                <td style="text-align:center"><?= $statusBadge ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <!-- ============================================================= -->
    <!-- TAB 3: SISWA DENGAN PERSENTASE TERENDAH (<75%) -->
    <!-- ============================================================= -->
    <?php elseif ($tab === 'terendah'): ?>
        
        <div class="alert err no-print" style="display:flex;align-items:center;gap:12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:24px"></i>
            <div>
                <b>Early Warning System - Kehadiran Mahasiswa Di Bawah Standar Akademik (75%)</b>
                <p style="font-size:13px;margin-top:2px;font-weight:normal">
                    Sesuai peraturan akademik perguruan tinggi, mahasiswa dengan persentase kehadiran di bawah 75% berisiko tidak memenuhi syarat mengikuti Ujian Akhir Semester (UAS). Gunakan daftar ini untuk memanggil atau memberikan pembinaan kepada mahasiswa yang bersangkutan.
                </p>
            </div>
        </div>

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
                <div>
                    <h3 style="margin-bottom:2px;color:#991b1b">
                        <i class="fa-solid fa-arrow-down-short-wide"></i> Daftar Siswa dengan Persentase Kehadiran Terendah
                    </h3>
                    <small style="color:var(--muted)">Ditemukan <b><?= count($lowestAttendanceList) ?></b> mahasiswa dengan kehadiran di bawah 75%.</small>
                </div>

                <div style="display:flex;gap:8px" class="no-print">
                    <a href="absensi_mahasiswa.php?action=export_terendah" class="btn btn-danger" title="Download Daftar Siswa Bermasalah Kehadiran">
                        <i class="fa-solid fa-file-excel"></i> <span>Download CSV Siswa Terendah</span>
                    </a>
                    <button type="button" onclick="window.print()" class="btn btn-secondary">
                        <i class="fa-solid fa-print"></i> <span>Cetak Daftar Peringatan</span>
                    </button>
                </div>
            </div>

            <?php if (empty($lowestAttendanceList)): ?>
                <div style="background:#f0fdf4;border:1.5px dashed #bbf7d0;border-radius:12px;padding:36px;text-align:center;color:#166534">
                    <i class="fa-solid fa-circle-check" style="font-size:40px;color:#15803d;margin-bottom:8px"></i>
                    <h4 style="font-size:16px;font-weight:800">Semua Mahasiswa Memenuhi Standar Kehadiran!</h4>
                    <p style="font-size:13px;margin-top:4px">Tidak ada mahasiswa aktif yang memiliki persentase kehadiran di bawah 75%.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table>
                        <thead>
                            <tr style="background:#fff1f2">
                                <th style="width:40px;text-align:center">Rank</th>
                                <th style="width:120px">NIM</th>
                                <th style="min-width:180px">Nama Mahasiswa</th>
                                <th>Prodi</th>
                                <th style="text-align:center">Angkatan</th>
                                <th>Kelas</th>
                                <th style="text-align:center" title="Hadir">Hadir</th>
                                <th style="text-align:center" title="Izin">Izin</th>
                                <th style="text-align:center" title="Sakit">Sakit</th>
                                <th style="text-align:center" title="Alpa / Bolos">Alpa</th>
                                <th style="text-align:center">Total</th>
                                <th style="min-width:140px">Persentase</th>
                                <th style="text-align:center">Status Evaluasi</th>
                                <th style="text-align:center" class="no-print">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowestAttendanceList as $idx => $st): ?>
                                <?php
                                    $isCritical = $st['pct'] < 50;
                                    $badgeColor = $isCritical ? 'badge err' : 'badge';
                                    $pctColor = $isCritical ? '#b91c1c' : '#b45309';
                                ?>
                                <tr style="<?= $isCritical ? 'background:#fffafa;' : '' ?>">
                                    <td style="text-align:center;font-weight:800;color:<?= $isCritical ? '#b91c1c' : 'var(--muted)' ?>">
                                        #<?= $idx + 1 ?>
                                    </td>
                                    <td style="font-family:monospace;font-weight:700;font-size:13px">
                                        <?= htmlspecialchars($st['nim'] ?: '-') ?>
                                    </td>
                                    <td>
                                        <b style="color:var(--ink)"><?= htmlspecialchars($st['nama']) ?></b>
                                    </td>
                                    <td><?= htmlspecialchars($st['prodi_kode']) ?></td>
                                    <td style="text-align:center"><?= $st['tahun_angkatan'] ?></td>
                                    <td><?= htmlspecialchars($st['kelas']) ?></td>
                                    <td style="text-align:center;font-weight:700;color:var(--g-dark)"><?= $st['jml_hadir'] ?></td>
                                    <td style="text-align:center;color:#0284c7"><?= $st['jml_izin'] ?></td>
                                    <td style="text-align:center;color:#b45309"><?= $st['jml_sakit'] ?></td>
                                    <td style="text-align:center;font-weight:800;color:#b91c1c;background:#fee2e2"><?= $st['jml_alpa'] ?></td>
                                    <td style="text-align:center;font-weight:700"><?= $st['total_sesi'] ?></td>
                                    <td>
                                        <b style="font-size:14px;color:<?= $pctColor ?>"><?= $st['pct'] ?>%</b>
                                        <div class="progress-bar-bg">
                                            <div class="progress-bar-fill" style="width:<?= min(100, $st['pct']) ?>%;background:<?= $pctColor ?>"></div>
                                        </div>
                                    </td>
                                    <td style="text-align:center">
                                        <span class="<?= $badgeColor ?>" style="font-size:11px">
                                            <?= $st['status_label'] ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center" class="no-print">
                                        <button type="button" onclick="showStudentDetail(<?= $st['id'] ?>)" class="btn btn-secondary" style="padding:4px 8px;font-size:12px">
                                            <i class="fa-solid fa-eye"></i> Detail
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <!-- TANDA TANGAN CETAK (Hanya keluar saat dicetak) -->
    <div class="print-only">
        <div class="print-signatures">
            <div>
                <div>Mengetahui,</div>
                <div><b>Pembantu Ketua I (Bidang Akademik)</b></div>
                <div class="sign-box">_________________________________</div>
            </div>
            <div>
                <div>Sidogiri, <?= date('d F Y') ?></div>
                <div><b>Bagian Administrasi Akademik (BAAK)</b></div>
                <div class="sign-box"><b><?= htmlspecialchars($admin['name']) ?></b></div>
            </div>
        </div>
    </div>

</div>

<!-- ============================================================= -->
<!-- MODAL POPUP: DETAIL RIWAYAT PRESENSI PER MAHASISWA -->
<!-- ============================================================= -->
<div class="modal-overlay" id="modalStudentDetail">
    <div class="modal-card">
        <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:14px;margin-bottom:16px">
            <h3 style="font-size:17px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px">
                <i class="fa-solid fa-id-card" style="color:var(--g)"></i>
                <span>Detail Riwayat Kehadiran Mahasiswa</span>
            </h3>
            <button type="button" onclick="closeStudentModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--muted)">&times;</button>
        </div>

        <div id="modalLoading" style="text-align:center;padding:30px;color:var(--muted)">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;color:var(--g)"></i>
            <p style="margin-top:10px;font-size:13px">Memuat riwayat kehadiran...</p>
        </div>

        <div id="modalContent" style="display:none">
            <!-- Profil Singkat -->
            <div style="display:flex;justify-content:space-between;gap:16px;background:#f8faf9;border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:18px;flex-wrap:wrap">
                <div>
                    <h4 id="mNama" style="font-size:16px;font-weight:800;color:var(--g-dark)">-</h4>
                    <div style="font-size:12.5px;color:var(--muted);margin-top:4px;display:flex;gap:12px;flex-wrap:wrap">
                        <span><b>NIM:</b> <span id="mNim">-</span></span>
                        <span><b>Prodi:</b> <span id="mProdi">-</span></span>
                        <span><b>Angkatan:</b> <span id="mAngkatan">-</span></span>
                        <span><b>Kelas:</b> <span id="mKelas">-</span></span>
                    </div>
                </div>

                <div style="text-align:right">
                    <div id="mPct" style="font-size:28px;font-weight:800;color:var(--g)">0%</div>
                    <small style="color:var(--muted);font-weight:600">Persentase Kehadiran</small>
                </div>
            </div>

            <!-- Ringkasan Status H, I, S, A -->
            <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;margin-bottom:18px;text-align:center">
                <div style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;padding:8px">
                    <small style="color:#15803d;font-weight:700">Hadir (H)</small>
                    <b id="mHadir" style="display:block;font-size:18px;color:#15803d">0</b>
                </div>
                <div style="background:#e0f2fe;border:1px solid #bae6fd;border-radius:10px;padding:8px">
                    <small style="color:#0369a1;font-weight:700">Izin (I)</small>
                    <b id="mIzin" style="display:block;font-size:18px;color:#0369a1">0</b>
                </div>
                <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:8px">
                    <small style="color:#b45309;font-weight:700">Sakit (S)</small>
                    <b id="mSakit" style="display:block;font-size:18px;color:#b45309">0</b>
                </div>
                <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:10px;padding:8px">
                    <small style="color:#b91c1c;font-weight:700">Alpa (A)</small>
                    <b id="mAlpa" style="display:block;font-size:18px;color:#b91c1c">0</b>
                </div>
            </div>

            <h4 style="font-size:13.5px;font-weight:800;margin-bottom:8px">Riwayat Log Perkuliahan yang Diikuti:</h4>
            <div style="max-height:300px;overflow-y:auto;border:1px solid var(--line);border-radius:12px">
                <table style="font-size:12.5px">
                    <thead>
                        <tr>
                            <th style="width:30px">#</th>
                            <th>Mata Kuliah / Dosen</th>
                            <th>Tanggal &amp; Sesi</th>
                            <th style="min-width:140px">Materi Pokok</th>
                            <th style="text-align:center">Status</th>
                        </tr>
                    </thead>
                    <tbody id="mLogRows">
                        <!-- Disuntik lewat JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:18px;border-top:1px solid var(--line);padding-top:14px">
            <button type="button" onclick="closeStudentModal()" class="btn btn-secondary">Tutup</button>
        </div>
    </div>
</div>

<script>
function showStudentDetail(id) {
    const modal = document.getElementById('modalStudentDetail');
    const loading = document.getElementById('modalLoading');
    const content = document.getElementById('modalContent');

    modal.classList.add('active');
    loading.style.display = 'block';
    content.style.display = 'none';

    fetch(`absensi_mahasiswa.php?action=detail_mhs_ajax&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Gagal mengambil data.');
                closeStudentModal();
                return;
            }

            const m = data.mahasiswa;
            const s = data.stat;
            const logs = data.logs;

            document.getElementById('mNama').innerText = m.nama;
            document.getElementById('mNim').innerText = m.nim || '-';
            document.getElementById('mProdi').innerText = m.prodi_nama;
            document.getElementById('mAngkatan').innerText = m.tahun_angkatan;
            document.getElementById('mKelas').innerText = m.kelas;

            document.getElementById('mPct').innerText = `${s.pct}%`;
            document.getElementById('mHadir').innerText = s.hadir;
            document.getElementById('mIzin').innerText = s.izin;
            document.getElementById('mSakit').innerText = s.sakit;
            document.getElementById('mAlpa').innerText = s.alpa;

            const color = s.pct >= 75 ? 'var(--g)' : (s.pct >= 50 ? '#d97706' : '#dc2626');
            document.getElementById('mPct').style.color = color;

            const tbody = document.getElementById('mLogRows');
            tbody.innerHTML = '';

            if (logs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:16px">Belum ada riwayat kehadiran tercatat.</td></tr>';
            } else {
                logs.forEach((l, idx) => {
                    let stClass = 'badge off';
                    if (l.status === 'hadir') stClass = 'badge ok';
                    else if (l.status === 'izin') stClass = 'badge';
                    else if (l.status === 'sakit') stClass = 'badge';
                    else if (l.status === 'alpa') stClass = 'badge err';

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="color:var(--muted);text-align:center">${idx + 1}</td>
                        <td>
                            <b>${l.mata_kuliah}</b> (Kelas ${l.kelas})
                            <small style="display:block;color:var(--muted)">Dosen: ${l.dosen_nama || '-'}</small>
                        </td>
                        <td>
                            <b>Pertemuan Ke-${l.pertemuan_ke}</b>
                            <small style="display:block;color:var(--muted)">${l.tanggal}</small>
                        </td>
                        <td style="font-size:12px;line-height:1.3">${l.materi || '-'}</td>
                        <td style="text-align:center">
                            <span class="${stClass}">${l.status.toUpperCase()}</span>
                            ${l.catatan ? `<small style="display:block;color:var(--muted);font-size:10.5px">${l.catatan}</small>` : ''}
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            }

            loading.style.display = 'none';
            content.style.display = 'block';
        })
        .catch(err => {
            console.error(err);
            alert('Terjadi kesalahan saat memuat data.');
            closeStudentModal();
        });
}

function closeStudentModal() {
    document.getElementById('modalStudentDetail').classList.remove('active');
}

// Close on escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeStudentModal();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
