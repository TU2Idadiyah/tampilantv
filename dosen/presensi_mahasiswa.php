<?php
/**
 * Portal Dosen - Presensi Mahasiswa & Jurnal Perkuliahan
 * STAI Sidogiri
 */

require_once dirname(__DIR__) . '/api/v1/dosen/helper.php';

try {
    $pdo = getDosenPdo();
} catch (Throwable $e) {
    $pdo = null;
}

if (!$pdo) {
    die("Koneksi database belum terhubung.");
}

// Pastikan tabel siap
ensurePresensiMahasiswaTables($pdo);

$dosen = getActiveDosen();
if (!$dosen) {
    // Jika belum login, alihkan ke index portal dosen
    header("Location: index.php");
    exit;
}

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

$namaHariIndo = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

// Ambil semua kelas yang diampu oleh dosen ini
$classes = getDosenClasses($pdo, (int)$dosen['id']);

// Tentukan jadwal yang sedang dipilih
$selectedJadwalId = isset($_GET['jadwal_id']) ? (int)$_GET['jadwal_id'] : 0;
$activeJadwal = null;

if ($selectedJadwalId > 0) {
    foreach ($classes as $c) {
        if ((int)$c['id'] === $selectedJadwalId) {
            $activeJadwal = $c;
            break;
        }
    }
}

if (!$activeJadwal && !empty($classes)) {
    $activeJadwal = $classes[0];
    $selectedJadwalId = (int)$activeJadwal['id'];
}

$flashMsg = null;
$flashType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. Simpan Presensi & Jurnal Perkuliahan
    if ($action === 'save_presensi') {
        $jadwalId = (int)($_POST['jadwal_id'] ?? 0);
        $jurnalId = (int)($_POST['jurnal_id'] ?? 0);
        $pertemuanKe = (int)($_POST['pertemuan_ke'] ?? 1);
        $tanggal = trim($_POST['tanggal'] ?? date('Y-m-d'));
        $jamMulai = trim($_POST['jam_mulai'] ?? '');
        $jamSelesai = trim($_POST['jam_selesai'] ?? '');
        $metode = trim($_POST['metode'] ?? 'offline');
        $materi = trim($_POST['materi'] ?? '');
        $catatan = trim($_POST['capaian_catatan'] ?? '');

        if ($jadwalId <= 0 || empty($materi)) {
            $flashMsg = 'Materi perkuliahan (jurnal) dan jadwal wajib diisi!';
            $flashType = 'danger';
        } else {
            try {
                if ($jurnalId > 0) {
                    // Update jurnal yang ada
                    $stmtUpdate = $pdo->prepare("UPDATE `jurnal_perkuliahan` 
                        SET pertemuan_ke = :pertemuan_ke, tanggal = :tanggal, jam_mulai = :jam_mulai, 
                            jam_selesai = :jam_selesai, materi = :materi, capaian_catatan = :catatan, 
                            metode = :metode, updated_at = CURRENT_TIMESTAMP 
                        WHERE id = :id AND dosen_id = :dosen_id");
                    $stmtUpdate->execute([
                        'pertemuan_ke' => $pertemuanKe,
                        'tanggal' => $tanggal,
                        'jam_mulai' => $jamMulai,
                        'jam_selesai' => $jamSelesai,
                        'materi' => $materi,
                        'catatan' => $catatan,
                        'metode' => $metode,
                        'id' => $jurnalId,
                        'dosen_id' => (int)$dosen['id']
                    ]);
                } else {
                    // Insert jurnal baru
                    $stmtInsert = $pdo->prepare("INSERT INTO `jurnal_perkuliahan` 
                        (jadwal_id, dosen_id, pertemuan_ke, tanggal, jam_mulai, jam_selesai, materi, capaian_catatan, metode, created_at, updated_at) 
                        VALUES (:jadwal_id, :dosen_id, :pertemuan_ke, :tanggal, :jam_mulai, :jam_selesai, :materi, :catatan, :metode, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                    $stmtInsert->execute([
                        'jadwal_id' => $jadwalId,
                        'dosen_id' => (int)$dosen['id'],
                        'pertemuan_ke' => $pertemuanKe,
                        'tanggal' => $tanggal,
                        'jam_mulai' => $jamMulai,
                        'jam_selesai' => $jamSelesai,
                        'materi' => $materi,
                        'catatan' => $catatan,
                        'metode' => $metode
                    ]);
                    $jurnalId = (int)$pdo->lastInsertId();
                }

                // Simpan presensi mahasiswa
                // Hapus presensi lama jika update
                $stmtDelP = $pdo->prepare("DELETE FROM `presensi_mahasiswa` WHERE jurnal_id = :jid");
                $stmtDelP->execute(['jid' => $jurnalId]);

                $mhsIds = $_POST['mhs_id'] ?? [];
                $mhsNims = $_POST['mhs_nim'] ?? [];
                $mhsNamas = $_POST['mhs_nama'] ?? [];
                $mhsStatuses = $_POST['status'] ?? [];
                $mhsKets = $_POST['catatan_mhs'] ?? [];

                $insertP = $pdo->prepare("INSERT INTO `presensi_mahasiswa` (jurnal_id, jadwal_id, nim, nama, status, catatan, created_at, updated_at)
                    VALUES (:jid, :jadwal_id, :nim, :nama, :status, :catatan, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

                $checkKm = $pdo->prepare("SELECT id FROM `kelas_mahasiswa` WHERE jadwal_id = :jid AND nama = :nama LIMIT 1");
                $insertKm = $pdo->prepare("INSERT INTO `kelas_mahasiswa` (jadwal_id, nim, nama, created_at) VALUES (:jid, :nim, :nama, CURRENT_TIMESTAMP)");

                foreach ($mhsNamas as $idx => $namaMhs) {
                    $namaMhs = trim($namaMhs);
                    if ($namaMhs === '') continue;
                    $nim = trim($mhsNims[$idx] ?? '');
                    $status = $mhsStatuses[$idx] ?? 'hadir';
                    $ket = trim($mhsKets[$idx] ?? '');

                    $insertP->execute([
                        'jid' => $jurnalId,
                        'jadwal_id' => $jadwalId,
                        'nim' => $nim,
                        'nama' => $namaMhs,
                        'status' => $status,
                        'catatan' => $ket
                    ]);

                    // Pastikan tersimpan di master kelas
                    $checkKm->execute(['jid' => $jadwalId, 'nama' => $namaMhs]);
                    if (!$checkKm->fetch()) {
                        $insertKm->execute(['jid' => $jadwalId, 'nim' => $nim, 'nama' => $namaMhs]);
                    }
                }

                header("Location: presensi_mahasiswa.php?jadwal_id={$jadwalId}&msg=saved");
                exit;
            } catch (Exception $e) {
                $flashMsg = 'Gagal menyimpan: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }

    // 2. Hapus Pertemuan / Jurnal
    if ($action === 'delete_jurnal') {
        $delId = (int)($_POST['del_id'] ?? 0);
        $jadwalId = (int)($_POST['jadwal_id'] ?? 0);
        if ($delId > 0) {
            try {
                $stmtDelJ = $pdo->prepare("DELETE FROM `jurnal_perkuliahan` WHERE id = :id AND dosen_id = :did");
                $stmtDelJ->execute(['id' => $delId, 'did' => (int)$dosen['id']]);
                
                $stmtDelPM = $pdo->prepare("DELETE FROM `presensi_mahasiswa` WHERE jurnal_id = :jid");
                $stmtDelPM->execute(['jid' => $delId]);

                header("Location: presensi_mahasiswa.php?jadwal_id={$jadwalId}&msg=deleted");
                exit;
            } catch (Exception $e) {
                $flashMsg = 'Gagal menghapus: ' . $e->getMessage();
                $flashType = 'danger';
            }
        }
    }

    // 3. Tambah / Salin Mahasiswa Massal ke Kelas
    if ($action === 'save_roster') {
        $jadwalId = (int)($_POST['jadwal_id'] ?? 0);
        $rawText = trim($_POST['raw_students'] ?? '');
        if ($jadwalId > 0 && !empty($rawText)) {
            $lines = explode("\n", str_replace("\r", "", $rawText));
            $addedCount = 0;
            $checkKm = $pdo->prepare("SELECT id FROM `kelas_mahasiswa` WHERE jadwal_id = :jid AND nama = :nama LIMIT 1");
            $insertKm = $pdo->prepare("INSERT INTO `kelas_mahasiswa` (jadwal_id, nim, nama, created_at) VALUES (:jid, :nim, :nama, CURRENT_TIMESTAMP)");

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $nim = '';
                $nama = $line;

                // Cek format "NIM - Nama" atau "NIM, Nama" atau "NIM\tNama"
                if (preg_match('/^([0-9\.\-\s]+)[\t\-,;]+(.+)$/', $line, $matches)) {
                    $nim = trim($matches[1]);
                    $nama = trim($matches[2]);
                }

                $checkKm->execute(['jid' => $jadwalId, 'nama' => $nama]);
                if (!$checkKm->fetch()) {
                    $insertKm->execute(['jid' => $jadwalId, 'nim' => $nim, 'nama' => $nama]);
                    $addedCount++;
                }
            }
            header("Location: presensi_mahasiswa.php?jadwal_id={$jadwalId}&msg=roster_added&count={$addedCount}");
            exit;
        }
    }

    // 4. Hapus Satu Mahasiswa dari Roster
    if ($action === 'delete_roster_mhs') {
        $mhsId = (int)($_POST['mhs_id'] ?? 0);
        $jadwalId = (int)($_POST['jadwal_id'] ?? 0);
        if ($mhsId > 0) {
            $pdo->prepare("DELETE FROM `kelas_mahasiswa` WHERE id = :id AND jadwal_id = :jid")->execute(['id' => $mhsId, 'jid' => $jadwalId]);
            header("Location: presensi_mahasiswa.php?jadwal_id={$jadwalId}&msg=roster_removed");
            exit;
        }
    }
}

// Check flash messages from URL
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'saved') {
        $flashMsg = 'Data Jurnal Perkuliahan dan Presensi Mahasiswa berhasil disimpan!';
        $flashType = 'success';
    } elseif ($_GET['msg'] === 'deleted') {
        $flashMsg = 'Data pertemuan jurnal dan presensi telah dihapus.';
        $flashType = 'info';
    } elseif ($_GET['msg'] === 'roster_added') {
        $cnt = (int)($_GET['count'] ?? 0);
        $flashMsg = "Berhasil menambahkan {$cnt} data mahasiswa ke daftar kelas.";
        $flashType = 'success';
    } elseif ($_GET['msg'] === 'roster_removed') {
        $flashMsg = 'Mahasiswa berhasil dihapus dari daftar kelas.';
        $flashType = 'info';
    }
}

// Data kelas aktif
$rosterMahasiswa = [];
$jurnalList = [];
$editJurnal = null;
$nextPertemuan = 1;

if ($activeJadwal) {
    $rosterMahasiswa = getKelasMahasiswa($pdo, (int)$activeJadwal['id']);
    $jurnalList = getJurnalList($pdo, (int)$activeJadwal['id']);

    // Cari pertemuan berikutnya
    if (!empty($jurnalList)) {
        $maxPert = 0;
        foreach ($jurnalList as $jl) {
            if ($jl['pertemuan_ke'] > $maxPert) $maxPert = (int)$jl['pertemuan_ke'];
        }
        $nextPertemuan = $maxPert + 1;
    }

    // Jika mode edit suatu pertemuan
    $editJurnalId = isset($_GET['edit_jurnal']) ? (int)$_GET['edit_jurnal'] : 0;
    if ($editJurnalId > 0) {
        $editJurnal = getJurnalDetail($pdo, $editJurnalId);
    }
}

// Handle Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $activeJadwal) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Jurnal_Presensi_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $activeJadwal['mata_kuliah'] . '_' . $activeJadwal['kelas']) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['REKAP JURNAL PERKULIAHAN & PRESENSI']);
    fputcsv($out, ['Mata Kuliah', $activeJadwal['mata_kuliah']]);
    fputcsv($out, ['Kelas', $activeJadwal['kelas']]);
    fputcsv($out, ['Dosen Pengampu', $dosen['nama'] . ' (' . $dosen['kode_nidn'] . ')']);
    fputcsv($out, ['Prodi', $activeJadwal['prodi_nama']]);
    fputcsv($out, []);
    fputcsv($out, ['No', 'Pertemuan Ke', 'Tanggal', 'Waktu', 'Metode', 'Materi Perkuliahan (Jurnal)', 'Hadir', 'Izin', 'Sakit', 'Alpa', 'Total Mhs']);
    
    $no = 1;
    foreach ($jurnalList as $j) {
        $st = $j['stat'];
        fputcsv($out, [
            $no++,
            $j['pertemuan_ke'],
            $j['tanggal'],
            ($j['jam_mulai'] ?: '-') . ' - ' . ($j['jam_selesai'] ?: '-'),
            ucfirst($j['metode']),
            $j['materi'],
            $st['hadir'],
            $st['izin'],
            $st['sakit'],
            $st['alpa'],
            $st['total']
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Presensi Mahasiswa & Jurnal Perkuliahan - <?= htmlspecialchars($campusName) ?></title>
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
      --ink: #0f172a;
      --muted: #64748b;
      --line: #e2e8f0;
      --card: #ffffff;
      --bg: #f8fafc;
      --sky: #0284c7;
      --sky-soft: #e0f2fe;
      --amber: #d97706;
      --amber-soft: #fef3c7;
      --rose: #e11d48;
      --rose-soft: #ffe4e6;
      --radius: 16px;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
      --shadow: 0 4px 14px rgba(0,0,0,0.06);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg);
      color: var(--ink);
      min-height: 100vh;
      padding: 16px;
      line-height: 1.5;
    }
    .wrapper {
      max-width: 980px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    /* Header Bar */
    .top-header {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
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
    }
    .brand-logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
    .brand-text h1 { font-size: 16px; font-weight: 800; color: var(--brand-dark); }
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
      padding: 8px 14px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all .2s;
    }
    .btn-icon:hover { background: var(--brand-soft); color: var(--brand-dark); border-color: var(--brand); }
    .btn-icon.active { background: var(--brand); color: #fff; border-color: var(--brand); }

    /* Profil Dosen */
    .dosen-pill {
      background: var(--brand-soft);
      border: 1px solid rgba(0, 155, 76, 0.2);
      border-radius: 12px;
      padding: 8px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .dosen-avatar {
      width: 32px;
      height: 32px;
      background: var(--brand);
      color: #fff;
      border-radius: 8px;
      display: grid;
      place-items: center;
      font-weight: 700;
      font-size: 13px;
      overflow: hidden;
    }
    .dosen-avatar img { width: 100%; height: 100%; object-fit: cover; }

    /* Alert / Flash */
    .alert {
      padding: 12px 18px;
      border-radius: 12px;
      font-size: 13.5px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-info { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }

    /* Main Grid Layout */
    .grid-layout {
      display: grid;
      grid-template-columns: 320px 1fr;
      gap: 16px;
    }
    @media (max-width: 860px) {
      .grid-layout { grid-template-columns: 1fr; }
    }

    /* Card Component */
    .card {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 20px;
      box-shadow: var(--shadow-sm);
    }
    .card-title {
      font-size: 15px;
      font-weight: 800;
      color: var(--ink);
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
      padding-bottom: 10px;
      border-bottom: 1px solid var(--line);
    }

    /* Class List Navigation */
    .class-item {
      display: block;
      background: var(--bg);
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 12px 14px;
      text-decoration: none;
      color: var(--ink);
      margin-bottom: 10px;
      transition: all .2s;
    }
    .class-item:hover {
      border-color: var(--brand);
      background: #f0fdf4;
    }
    .class-item.active {
      background: #f0fdf4;
      border-color: var(--brand);
      box-shadow: 0 0 0 2px rgba(0,155,76,0.15);
    }
    .class-name { font-weight: 700; font-size: 14px; color: var(--ink); }
    .class-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .class-badge {
      display: inline-block;
      font-size: 11px;
      font-weight: 800;
      padding: 2px 8px;
      border-radius: 6px;
      background: #e2e8f0;
      color: #334155;
      margin-top: 6px;
    }
    .class-item.active .class-badge {
      background: var(--brand-soft);
      color: var(--brand-dark);
    }

    /* Form Fields */
    .form-group {
      margin-bottom: 14px;
    }
    .form-group label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      color: #334155;
      margin-bottom: 6px;
    }
    .form-control {
      width: 100%;
      padding: 10px 14px;
      border: 1.5px solid var(--line);
      border-radius: 10px;
      font-size: 13.5px;
      font-family: inherit;
      color: var(--ink);
      background: #fff;
      outline: none;
      transition: border-color .2s;
    }
    .form-control:focus {
      border-color: var(--brand);
    }
    textarea.form-control {
      resize: vertical;
      min-height: 80px;
    }

    .form-row {
      display: flex;
      gap: 12px;
    }
    .form-row > * { flex: 1; }

    /* Student Attendance Table */
    .table-container {
      overflow-x: auto;
      border-radius: 12px;
      border: 1px solid var(--line);
      margin-top: 14px;
    }
    table.table-presensi {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      background: #fff;
    }
    table.table-presensi th {
      background: #f8fafc;
      padding: 10px 12px;
      text-align: left;
      font-weight: 700;
      color: #475569;
      border-bottom: 1px solid var(--line);
      white-space: nowrap;
    }
    table.table-presensi td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--line);
      vertical-align: middle;
    }
    table.table-presensi tr:last-child td { border-bottom: none; }
    table.table-presensi tr:hover td { background: #f8fafc; }

    /* Status Radio Badges */
    .status-group {
      display: inline-flex;
      background: #f1f5f9;
      border-radius: 8px;
      padding: 3px;
      gap: 3px;
    }
    .status-option {
      position: relative;
    }
    .status-option input[type="radio"] {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }
    .status-label {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 28px;
      border-radius: 6px;
      font-weight: 800;
      font-size: 12px;
      cursor: pointer;
      user-select: none;
      color: var(--muted);
      transition: all .15s ease;
    }
    .status-option input[type="radio"]:checked + .status-label.lbl-hadir {
      background: #10b981;
      color: #fff;
      box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
    }
    .status-option input[type="radio"]:checked + .status-label.lbl-izin {
      background: #0284c7;
      color: #fff;
      box-shadow: 0 2px 4px rgba(2, 132, 199, 0.3);
    }
    .status-option input[type="radio"]:checked + .status-label.lbl-sakit {
      background: #f59e0b;
      color: #fff;
      box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3);
    }
    .status-option input[type="radio"]:checked + .status-label.lbl-alpa {
      background: #ef4444;
      color: #fff;
      box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
    }

    /* Buttons */
    .btn-primary {
      background: var(--brand);
      color: #fff;
      border: none;
      padding: 12px 20px;
      border-radius: 10px;
      font-weight: 700;
      font-size: 14px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: background .2s;
    }
    .btn-primary:hover { background: var(--brand-dark); }
    .btn-secondary {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid var(--line);
      padding: 8px 14px;
      border-radius: 8px;
      font-weight: 700;
      font-size: 12.5px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      transition: all .2s;
    }
    .btn-secondary:hover { background: #e2e8f0; color: var(--ink); }

    /* Modal Styling */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.55);
      z-index: 9999;
      align-items: center;
      justify-content: center;
      padding: 16px;
      backdrop-filter: blur(2px);
    }
    .modal-overlay.active { display: flex; }
    .modal-card {
      background: #fff;
      width: 100%;
      max-width: 540px;
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.15);
      max-height: 90vh;
      overflow-y: auto;
    }

    .stat-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 11px;
      font-weight: 700;
      padding: 3px 7px;
      border-radius: 6px;
    }
    .badge-h { background: #dcfce7; color: #15803d; }
    .badge-i { background: #e0f2fe; color: #0369a1; }
    .badge-s { background: #fef3c7; color: #b45309; }
    .badge-a { background: #fee2e2; color: #b91c1c; }
  </style>
</head>
<body>

<div class="wrapper">
  <!-- Top Navigation Header -->
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
        <h1>Presensi Mahasiswa &amp; Jurnal</h1>
        <small><?= htmlspecialchars($campusName) ?> · Portal Dosen</small>
      </div>
    </div>
    
    <div class="header-links">
      <div class="dosen-pill">
        <div class="dosen-avatar">
          <?php if (!empty($dosen['foto_url'])): ?>
            <img src="<?= htmlspecialchars($dosen['foto_url']) ?>" alt="">
          <?php else: ?>
            <i class="fa-solid fa-user-tie"></i>
          <?php endif; ?>
        </div>
        <div>
          <div style="font-size:12.5px;font-weight:800;color:var(--brand-dark)"><?= htmlspecialchars($dosen['nama']) ?></div>
          <div style="font-size:11px;color:var(--muted)">NIDN: <?= htmlspecialchars($dosen['kode_nidn']) ?></div>
        </div>
      </div>
      
      <a href="index.php" class="btn-icon" title="Kembali ke Dashboard Utama Dosen">
        <i class="fa-solid fa-arrow-left"></i> <span>Kembali ke Jadwal</span>
      </a>
      <a href="../login.php" class="btn-icon" title="Gerbang Portal">
        <i class="fa-solid fa-shapes"></i>
      </a>
      <a href="../api/v1/dosen/logout.php" class="btn-icon" style="color:var(--rose)" title="Keluar">
        <i class="fa-solid fa-right-from-bracket"></i>
      </a>
    </div>
  </header>

  <?php if ($flashMsg): ?>
    <div class="alert alert-<?= $flashType ?>">
      <i class="fa-solid <?= $flashType === 'success' ? 'fa-circle-check' : ($flashType === 'danger' ? 'fa-triangle-exclamation' : 'fa-circle-info') ?>"></i>
      <span><?= htmlspecialchars($flashMsg) ?></span>
    </div>
  <?php endif; ?>

  <div class="grid-layout">
    <!-- KOLOM KIRI: DAFTAR KELAS YANG DIAMPU -->
    <aside>
      <div class="card">
        <div class="card-title">
          <span><i class="fa-solid fa-graduation-cap" style="color:var(--brand)"></i> Kelas yang Diampu</span>
          <span style="font-size:12px;font-weight:600;color:var(--muted)"><?= count($classes) ?> Kelas</span>
        </div>

        <?php if (empty($classes)): ?>
          <p style="font-size:13px;color:var(--muted);text-align:center;padding:20px 0">
            Belum ada jadwal perkuliahan aktif untuk akun dosen Anda. Silakan hubungi bagian akademik.
          </p>
        <?php else: ?>
          <div>
            <?php foreach ($classes as $c): ?>
              <?php $isActive = ((int)$c['id'] === $selectedJadwalId); ?>
              <a href="presensi_mahasiswa.php?jadwal_id=<?= $c['id'] ?>" class="class-item <?= $isActive ? 'active' : '' ?>">
                <div class="class-name"><?= htmlspecialchars($c['mata_kuliah']) ?></div>
                <div class="class-sub">
                  <?= htmlspecialchars($c['prodi_nama']) ?> · <b>Kelas <?= htmlspecialchars($c['kelas']) ?></b>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px">
                  <span class="class-badge">
                    <i class="fa-regular fa-clock"></i> <?= $namaHariIndo[(int)$c['hari']] ?? 'Hari '.$c['hari'] ?>, <?= substr($c['jam_mulai'], 0, 5) ?>
                  </span>
                  <span style="font-size:11px;color:var(--muted)">Ruang <?= htmlspecialchars($c['ruang']) ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Info Ringkas Kelas Aktif & Kelola Roster Mahasiswa -->
      <?php if ($activeJadwal): ?>
        <div class="card" style="margin-top:16px">
          <div class="card-title">
            <span><i class="fa-solid fa-users" style="color:var(--sky)"></i> Mahasiswa Kelas</span>
            <span style="font-size:12px;font-weight:700;color:var(--brand-dark)"><?= count($rosterMahasiswa) ?> Mhs</span>
          </div>
          <p style="font-size:12.5px;color:var(--muted);margin-bottom:12px">
            Daftar mahasiswa terdaftar di kelas <b><?= htmlspecialchars($activeJadwal['kelas']) ?></b>. Data ini otomatis digunakan untuk presensi setiap pertemuan.
          </p>
          <div style="display:flex;gap:8px;flex-direction:column">
            <button type="button" class="btn-secondary" onclick="openRosterModal()" style="justify-content:center">
              <i class="fa-solid fa-user-plus"></i> <span>Salin / Tambah Mahasiswa</span>
            </button>
            <?php if (!empty($rosterMahasiswa)): ?>
              <button type="button" class="btn-secondary" onclick="openManageRosterModal()" style="justify-content:center">
                <i class="fa-solid fa-list-check"></i> <span>Lihat / Kelola Roster (<?= count($rosterMahasiswa) ?>)</span>
              </button>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </aside>

    <!-- KOLOM KANAN: FORM PRESENSI & JURNAL PERKULIAHAN + RIWAYAT -->
    <main style="display:flex;flex-direction:column;gap:16px">
      <?php if (!$activeJadwal): ?>
        <div class="card" style="text-align:center;padding:40px 20px">
          <i class="fa-solid fa-book-open" style="font-size:40px;color:var(--muted);margin-bottom:12px"></i>
          <h3>Pilih Kelas yang Diampu</h3>
          <p style="font-size:13.5px;color:var(--muted);margin-top:4px">Silakan pilih kelas pada panel di sebelah kiri untuk mengisi presensi mahasiswa dan jurnal materi perkuliahan.</p>
        </div>
      <?php else: ?>

        <!-- KARTU FORM INPUT JURNAL & PRESENSI -->
        <div class="card">
          <div class="card-title">
            <div>
              <span style="color:var(--brand);margin-right:6px">
                <i class="fa-solid <?= $editJurnal ? 'fa-pen-to-square' : 'fa-calendar-plus' ?>"></i>
              </span>
              <span><?= $editJurnal ? 'Edit Pertemuan Ke-' . $editJurnal['pertemuan_ke'] : 'Input Presensi Mahasiswa & Jurnal Perkuliahan' ?></span>
            </div>
            <?php if ($editJurnal): ?>
              <a href="presensi_mahasiswa.php?jadwal_id=<?= $activeJadwal['id'] ?>" class="btn-secondary" style="font-size:11.5px;padding:4px 10px">
                <i class="fa-solid fa-plus"></i> <span>Input Pertemuan Baru</span>
              </a>
            <?php endif; ?>
          </div>

          <form action="presensi_mahasiswa.php" method="POST" id="formPresensi">
            <input type="hidden" name="action" value="save_presensi">
            <input type="hidden" name="jadwal_id" value="<?= $activeJadwal['id'] ?>">
            <input type="hidden" name="jurnal_id" value="<?= $editJurnal['id'] ?? 0 ?>">

            <!-- Baris Metadata Pertemuan -->
            <div class="form-row">
              <div class="form-group" style="flex:0 0 140px">
                <label for="pertemuan_ke"><i class="fa-solid fa-hashtag"></i> Pertemuan Ke</label>
                <select name="pertemuan_ke" id="pertemuan_ke" class="form-control" required>
                  <?php 
                    $selectedPert = $editJurnal ? (int)$editJurnal['pertemuan_ke'] : $nextPertemuan;
                    for ($p = 1; $p <= 20; $p++): 
                  ?>
                    <option value="<?= $p ?>" <?= $p === $selectedPert ? 'selected' : '' ?>>
                      Pertemuan <?= $p ?>
                    </option>
                  <?php endfor; ?>
                </select>
              </div>

              <div class="form-group">
                <label for="tanggal"><i class="fa-regular fa-calendar"></i> Tanggal Perkuliahan</label>
                <input type="date" name="tanggal" id="tanggal" class="form-control" 
                       value="<?= htmlspecialchars($editJurnal['tanggal'] ?? date('Y-m-d')) ?>" required>
              </div>

              <div class="form-group" style="flex:0 0 150px">
                <label for="metode"><i class="fa-solid fa-chalkboard-user"></i> Metode Kuliah</label>
                <select name="metode" id="metode" class="form-control">
                  <option value="offline" <?= ($editJurnal['metode'] ?? 'offline') === 'offline' ? 'selected' : '' ?>>Tatap Muka (Offline)</option>
                  <option value="online" <?= ($editJurnal['metode'] ?? '') === 'online' ? 'selected' : '' ?>>Daring (Online)</option>
                </select>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="jam_mulai"><i class="fa-regular fa-clock"></i> Jam Mulai</label>
                <input type="time" name="jam_mulai" id="jam_mulai" class="form-control"
                       value="<?= htmlspecialchars($editJurnal['jam_mulai'] ?? substr($activeJadwal['jam_mulai'], 0, 5)) ?>">
              </div>
              <div class="form-group">
                <label for="jam_selesai"><i class="fa-regular fa-clock"></i> Jam Selesai</label>
                <input type="time" name="jam_selesai" id="jam_selesai" class="form-control"
                       value="<?= htmlspecialchars($editJurnal['jam_selesai'] ?? substr($activeJadwal['jam_selesai'], 0, 5)) ?>">
              </div>
            </div>

            <!-- KOLOM UTAMA PERMINTAAN USER: JURNAL PERKULIAHAN (MATERI) -->
            <div class="form-group" style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:14px;margin:12px 0">
              <label for="materi" style="color:var(--brand-dark);font-size:13.5px;display:flex;align-items:center;gap:6px">
                <i class="fa-solid fa-book-journal-whills"></i> 
                <span><b>Jurnal Perkuliahan / Materi &amp; Pokok Bahasan yang Diajarkan</b></span>
                <span style="color:#e11d48">*wajib</span>
              </label>
              <textarea name="materi" id="materi" class="form-control" rows="3" 
                        placeholder="Tuliskan materi, topik pokok bahasan, bab/modul yang disampaikan pada pertemuan ini..." required><?= htmlspecialchars($editJurnal['materi'] ?? '') ?></textarea>
              
              <div style="margin-top:10px">
                <label for="capaian_catatan" style="font-size:12px;font-weight:600;color:var(--muted)">
                  Catatan Tambahan / Tugas Mahasiswa / Evaluasi (Opsional):
                </label>
                <input type="text" name="capaian_catatan" id="capaian_catatan" class="form-control"
                       placeholder="Contoh: Diskusi kelompok bab 2 berjalan lancar, tugas makalah dikumpulkan pekan depan."
                       value="<?= htmlspecialchars($editJurnal['capaian_catatan'] ?? '') ?>">
              </div>
            </div>

            <!-- TABEL DAFTAR PRESENSI MAHASISWA -->
            <div style="margin-top:16px">
              <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                <div>
                  <h4 style="font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:6px">
                    <i class="fa-solid fa-list-check" style="color:var(--brand)"></i>
                    <span>Tabel Presensi Mahasiswa</span>
                  </h4>
                  <small style="font-size:11.5px;color:var(--muted)">
                    Keterangan: <b>H</b> = Hadir, <b>I</b> = Izin, <b>S</b> = Sakit, <b>A</b> = Alpa
                  </small>
                </div>
                <div style="display:flex;gap:6px">
                  <button type="button" class="btn-secondary" onclick="markAllStatus('hadir')" style="font-size:12px;padding:5px 10px;color:#15803d;background:#dcfce7;border-color:#bbf7d0">
                    <i class="fa-solid fa-check-double"></i> <span>Set Semua Hadir</span>
                  </button>
                  <button type="button" class="btn-secondary" onclick="openRosterModal()" style="font-size:12px;padding:5px 10px">
                    <i class="fa-solid fa-user-plus"></i> <span>+ Mhs</span>
                  </button>
                </div>
              </div>

              <?php
                // Tentukan daftar mahasiswa yang ditampilkan di form
                // Jika edit, gabungkan presensi yang tersimpan dengan master kelas
                $displayStudents = [];
                if ($editJurnal && !empty($editJurnal['presensi'])) {
                    foreach ($editJurnal['presensi'] as $pRow) {
                        $displayStudents[] = [
                            'nim' => $pRow['nim'],
                            'nama' => $pRow['nama'],
                            'status' => $pRow['status'],
                            'catatan' => $pRow['catatan']
                        ];
                    }
                } elseif (!empty($rosterMahasiswa)) {
                    foreach ($rosterMahasiswa as $rm) {
                        $displayStudents[] = [
                            'nim' => $rm['nim'],
                            'nama' => $rm['nama'],
                            'status' => 'hadir',
                            'catatan' => ''
                        ];
                    }
                }
              ?>

              <?php if (empty($displayStudents)): ?>
                <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:16px;text-align:center;color:#92400e;margin:12px 0">
                  <i class="fa-solid fa-circle-exclamation" style="font-size:24px;margin-bottom:6px"></i>
                  <div style="font-weight:700;font-size:14px">Belum Ada Daftar Mahasiswa di Kelas Ini</div>
                  <p style="font-size:12.5px;margin:6px 0 12px">
                    Tambahkan mahasiswa ke kelas ini agar presensi bisa langsung diisi. Anda dapat menyalin dan menempel daftar nama dari Excel atau pesan teks.
                  </p>
                  <button type="button" class="btn-primary" onclick="openRosterModal()" style="font-size:12.5px;padding:8px 16px;margin:0 auto">
                    <i class="fa-solid fa-paste"></i> <span>Salin &amp; Tempel Daftar Mahasiswa Sekarang</span>
                  </button>
                </div>
              <?php else: ?>
                <div class="table-container">
                  <table class="table-presensi">
                    <thead>
                      <tr>
                        <th style="width:40px;text-align:center">No</th>
                        <th style="width:110px">NIM</th>
                        <th>Nama Mahasiswa</th>
                        <th style="width:170px;text-align:center">Status Kehadiran</th>
                        <th style="width:140px">Keterangan</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($displayStudents as $sIdx => $st): ?>
                        <tr>
                          <td style="text-align:center;font-weight:700;color:var(--muted)"><?= $sIdx + 1 ?></td>
                          <td>
                            <input type="text" name="mhs_nim[]" value="<?= htmlspecialchars($st['nim'] ?? '') ?>" class="form-control" style="padding:4px 8px;font-size:12px;height:30px" placeholder="NIM">
                          </td>
                          <td>
                            <input type="text" name="mhs_nama[]" value="<?= htmlspecialchars($st['nama']) ?>" class="form-control" style="padding:4px 8px;font-size:12px;height:30px;font-weight:600" required>
                          </td>
                          <td style="text-align:center">
                            <div class="status-group">
                              <div class="status-option">
                                <input type="radio" id="st_h_<?= $sIdx ?>" name="status[<?= $sIdx ?>]" value="hadir" <?= ($st['status'] ?? 'hadir') === 'hadir' ? 'checked' : '' ?>>
                                <label for="st_h_<?= $sIdx ?>" class="status-label lbl-hadir" title="Hadir">H</label>
                              </div>
                              <div class="status-option">
                                <input type="radio" id="st_i_<?= $sIdx ?>" name="status[<?= $sIdx ?>]" value="izin" <?= ($st['status'] ?? '') === 'izin' ? 'checked' : '' ?>>
                                <label for="st_i_<?= $sIdx ?>" class="status-label lbl-izin" title="Izin">I</label>
                              </div>
                              <div class="status-option">
                                <input type="radio" id="st_s_<?= $sIdx ?>" name="status[<?= $sIdx ?>]" value="sakit" <?= ($st['status'] ?? '') === 'sakit' ? 'checked' : '' ?>>
                                <label for="st_s_<?= $sIdx ?>" class="status-label lbl-sakit" title="Sakit">S</label>
                              </div>
                              <div class="status-option">
                                <input type="radio" id="st_a_<?= $sIdx ?>" name="status[<?= $sIdx ?>]" value="alpa" <?= ($st['status'] ?? '') === 'alpa' ? 'checked' : '' ?>>
                                <label for="st_a_<?= $sIdx ?>" class="status-label lbl-alpa" title="Alpa / Tanpa Keterangan">A</label>
                              </div>
                            </div>
                          </td>
                          <td>
                            <input type="text" name="catatan_mhs[]" value="<?= htmlspecialchars($st['catatan'] ?? '') ?>" class="form-control" style="padding:4px 8px;font-size:12px;height:30px" placeholder="-">
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>

            <!-- Tombol Simpan -->
            <div style="margin-top:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
              <span style="font-size:12px;color:var(--muted)">
                Pastikan materi jurnal dan presensi mahasiswa telah sesuai sebelum disimpan.
              </span>
              <button type="submit" class="btn-primary" style="padding:12px 24px">
                <i class="fa-solid fa-floppy-disk"></i>
                <span><?= $editJurnal ? 'Perbarui Jurnal & Presensi' : 'Simpan Jurnal & Presensi' ?></span>
              </button>
            </div>
          </form>
        </div>

        <!-- RIWAYAT JURNAL & REKAP KELAS -->
        <div class="card">
          <div class="card-title">
            <div>
              <i class="fa-solid fa-clock-rotate-left" style="color:var(--brand);margin-right:6px"></i>
              <span>Riwayat Jurnal Perkuliahan &amp; Rekap Pertemuan (<?= count($jurnalList) ?> Pertemuan)</span>
            </div>
            <?php if (!empty($jurnalList)): ?>
              <div style="display:flex;gap:6px">
                <a href="presensi_mahasiswa.php?jadwal_id=<?= $activeJadwal['id'] ?>&export=csv" class="btn-secondary" style="font-size:12px;padding:4px 10px">
                  <i class="fa-solid fa-file-csv"></i> <span>Export CSV</span>
                </a>
                <button type="button" class="btn-secondary" onclick="window.print()" style="font-size:12px;padding:4px 10px">
                  <i class="fa-solid fa-print"></i> <span>Cetak</span>
                </button>
              </div>
            <?php endif; ?>
          </div>

          <?php if (empty($jurnalList)): ?>
            <p style="font-size:13px;color:var(--muted);text-align:center;padding:24px 0">
              Belum ada pertemuan perkuliahan yang disimpan untuk kelas ini. Mulai dengan mengisi form di atas!
            </p>
          <?php else: ?>
            <div class="table-container">
              <table class="table-presensi">
                <thead>
                  <tr>
                    <th style="width:70px">Pertemuan</th>
                    <th style="width:100px">Tanggal</th>
                    <th style="width:75px">Waktu</th>
                    <th>Jurnal Materi Perkuliahan</th>
                    <th style="width:160px;text-align:center">Presensi Mhs</th>
                    <th style="width:90px;text-align:center">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($jurnalList as $jl): ?>
                    <?php $st = $jl['stat']; ?>
                    <tr>
                      <td style="font-weight:800;color:var(--brand-dark);text-align:center">
                        Ke-<?= $jl['pertemuan_ke'] ?>
                      </td>
                      <td style="font-size:12.5px">
                        <?= date('d/m/Y', strtotime($jl['tanggal'])) ?>
                        <div style="font-size:11px;color:var(--muted)"><?= ucfirst($jl['metode']) ?></div>
                      </td>
                      <td style="font-size:11.5px;color:var(--muted)">
                        <?= $jl['jam_mulai'] ? substr($jl['jam_mulai'], 0, 5) : '-' ?>
                      </td>
                      <td>
                        <div style="font-weight:700;color:var(--ink);font-size:13px"><?= nl2br(htmlspecialchars($jl['materi'])) ?></div>
                        <?php if (!empty($jl['capaian_catatan'])): ?>
                          <div style="font-size:11.5px;color:var(--muted);margin-top:4px">
                            <i class="fa-regular fa-comment-dots"></i> <?= htmlspecialchars($jl['capaian_catatan']) ?>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td style="text-align:center">
                        <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                          <span class="stat-badge badge-h" title="Hadir"><?= $st['hadir'] ?> H</span>
                          <span class="stat-badge badge-i" title="Izin"><?= $st['izin'] ?> I</span>
                          <span class="stat-badge badge-s" title="Sakit"><?= $st['sakit'] ?> S</span>
                          <span class="stat-badge badge-a" title="Alpa"><?= $st['alpa'] ?> A</span>
                        </div>
                        <div style="font-size:11px;color:var(--muted);margin-top:2px">
                          Total: <?= $st['total'] ?> mhs
                        </div>
                      </td>
                      <td style="text-align:center">
                        <div style="display:flex;gap:4px;justify-content:center">
                          <a href="presensi_mahasiswa.php?jadwal_id=<?= $activeJadwal['id'] ?>&edit_jurnal=<?= $jl['id'] ?>" 
                             class="btn-icon" style="padding:4px 8px;font-size:11px" title="Edit Jurnal & Presensi Ini">
                            <i class="fa-solid fa-pen"></i>
                          </a>
                          <form action="presensi_mahasiswa.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus data pertemuan ke-<?= $jl['pertemuan_ke'] ?> ini?')">
                            <input type="hidden" name="action" value="delete_jurnal">
                            <input type="hidden" name="jadwal_id" value="<?= $activeJadwal['id'] ?>">
                            <input type="hidden" name="del_id" value="<?= $jl['id'] ?>">
                            <button type="submit" class="btn-icon" style="padding:4px 8px;font-size:11px;color:var(--rose)" title="Hapus">
                              <i class="fa-solid fa-trash"></i>
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      <?php endif; ?>
    </main>
  </div>
</div>

<!-- MODAL 1: SALIN & TEMPEL ROSTER MAHASISWA MASSAL -->
<div class="modal-overlay" id="modalRoster">
  <div class="modal-card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h3 style="font-size:16px;font-weight:800;color:var(--ink)">
        <i class="fa-solid fa-users-viewfinder" style="color:var(--brand)"></i> Tambah / Salin Mahasiswa Massal
      </h3>
      <button type="button" class="btn-icon" onclick="closeRosterModal()">&times;</button>
    </div>

    <p style="font-size:13px;color:var(--muted);margin-bottom:12px">
      Salin daftar nama mahasiswa dari Excel, Word, atau pesan WhatsApp, lalu tempel pada kotak di bawah. Satu nama per baris.
    </p>

    <form action="presensi_mahasiswa.php" method="POST">
      <input type="hidden" name="action" value="save_roster">
      <input type="hidden" name="jadwal_id" value="<?= $activeJadwal['id'] ?? 0 ?>">

      <div class="form-group">
        <label for="raw_students">Tempel Daftar Nama Mahasiswa (Format: <code>NIM - Nama</code> atau <code>Nama Saja</code>):</label>
        <textarea name="raw_students" id="raw_students" class="form-control" rows="8" placeholder="Contoh:
202301001 - Ahmad Fauzi
202301002 - Muhammad Ridwan
202301003 - Siti Nurhaliza
atau cukup nama saja:
Ahmad Fauzi
Muhammad Ridwan" required></textarea>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
        <button type="button" class="btn-secondary" onclick="closeRosterModal()">Batal</button>
        <button type="submit" class="btn-primary">
          <i class="fa-solid fa-user-plus"></i> <span>Tambahkan ke Kelas</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL 2: KELOLA ROSTER MAHASISWA (LIHAT / HAPUS) -->
<div class="modal-overlay" id="modalManageRoster">
  <div class="modal-card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h3 style="font-size:16px;font-weight:800;color:var(--ink)">
        <i class="fa-solid fa-users-gear" style="color:var(--brand)"></i> Daftar Master Mahasiswa Kelas
      </h3>
      <button type="button" class="btn-icon" onclick="closeManageRosterModal()">&times;</button>
    </div>

    <div style="max-height:360px;overflow-y:auto;border:1px solid var(--line);border-radius:10px;margin-bottom:16px">
      <table class="table-presensi">
        <thead>
          <tr>
            <th style="width:30px">#</th>
            <th>NIM</th>
            <th>Nama Mahasiswa</th>
            <th style="width:40px;text-align:center">Hapus</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rosterMahasiswa)): ?>
            <tr><td colspan="4" style="text-align:center;color:var(--muted)">Belum ada mahasiswa.</td></tr>
          <?php else: ?>
            <?php foreach ($rosterMahasiswa as $rIdx => $rm): ?>
              <tr>
                <td style="color:var(--muted)"><?= $rIdx + 1 ?></td>
                <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($rm['nim'] ?: '-') ?></td>
                <td style="font-weight:600"><?= htmlspecialchars($rm['nama']) ?></td>
                <td style="text-align:center">
                  <form action="presensi_mahasiswa.php" method="POST" onsubmit="return confirm('Hapus <?= htmlspecialchars(addslashes($rm['nama'])) ?> dari daftar kelas?')">
                    <input type="hidden" name="action" value="delete_roster_mhs">
                    <input type="hidden" name="jadwal_id" value="<?= $activeJadwal['id'] ?? 0 ?>">
                    <input type="hidden" name="mhs_id" value="<?= $rm['id'] ?>">
                    <button type="submit" style="background:none;border:none;color:var(--rose);cursor:pointer;padding:4px" title="Hapus">
                      <i class="fa-solid fa-trash-can"></i>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center">
      <button type="button" class="btn-secondary" onclick="closeManageRosterModal(); openRosterModal();">
        <i class="fa-solid fa-plus"></i> <span>Tambah Mahasiswa Lagi</span>
      </button>
      <button type="button" class="btn-secondary" onclick="closeManageRosterModal()">Tutup</button>
    </div>
  </div>
</div>

<script>
  function markAllStatus(status) {
    const radios = document.querySelectorAll(`input[type="radio"][value="${status}"]`);
    radios.forEach(r => {
      r.checked = true;
    });
  }

  function openRosterModal() {
    document.getElementById('modalRoster').classList.add('active');
  }
  function closeRosterModal() {
    document.getElementById('modalRoster').classList.remove('active');
  }

  function openManageRosterModal() {
    document.getElementById('modalManageRoster').classList.add('active');
  }
  function closeManageRosterModal() {
    document.getElementById('modalManageRoster').classList.remove('active');
  }

  // Close modals on escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeRosterModal();
      closeManageRosterModal();
    }
  });
</script>

</body>
</html>
