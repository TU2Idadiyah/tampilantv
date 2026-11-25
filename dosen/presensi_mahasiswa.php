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
    // Ambil daftar dosen untuk pilihan masuk langsung di halaman ini
    $allDosen = [];
    try {
        $allDosen = $pdo->query("SELECT id, kode_nidn, nama, foto_path FROM `dosen` WHERE aktif = 1 AND deleted_at IS NULL ORDER BY nama ASC")->fetchAll();
    } catch (Exception $e) {}

    // Ambil info kampus & logo
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

    $loginBaseUrl = function_exists('getBaseUrl') ? getBaseUrl() : '..';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
      <meta charset="utf-8">
      <title>Masuk Presensi Mahasiswa &amp; Jurnal - <?= htmlspecialchars($campusName) ?></title>
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
          --rose: #e11d48;
          --rose-soft: #ffe4e6;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
          font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
          background: linear-gradient(135deg, #f0fdf4 0%, #f8fafc 50%, #e0f2fe 100%);
          color: var(--ink);
          min-height: 100vh;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 24px 16px;
        }
        .login-card {
          width: 100%;
          max-width: 480px;
          background: var(--card);
          border-radius: 28px;
          border: 1px solid var(--line);
          padding: 36px 28px;
          box-shadow: 0 20px 40px -10px rgba(0, 155, 76, 0.12), 0 2px 8px rgba(0,0,0,0.04);
          text-align: center;
        }
        .logo-box {
          width: 64px;
          height: 64px;
          background: linear-gradient(135deg, var(--brand), #34d399);
          border-radius: 20px;
          display: grid;
          place-items: center;
          color: #fff;
          font-size: 26px;
          font-weight: 800;
          margin: 0 auto 16px;
          box-shadow: 0 8px 20px rgba(0, 155, 76, 0.22);
          overflow: hidden;
        }
        .logo-box img { width: 100%; height: 100%; object-fit: contain; background: #fff; padding: 2px; }
        h1 { font-size: 21px; font-weight: 800; color: var(--brand-dark); line-height: 1.3; }
        p.sub { font-size: 13.5px; color: var(--muted); margin-top: 6px; margin-bottom: 22px; line-height: 1.45; }
        
        .info-pill {
          background: var(--sky-soft);
          border: 1px solid #bae6fd;
          color: #0369a1;
          border-radius: 12px;
          padding: 10px 14px;
          font-size: 13px;
          font-weight: 600;
          margin-bottom: 22px;
          display: flex;
          align-items: center;
          gap: 10px;
          text-align: left;
        }
        .form-group { margin-bottom: 16px; text-align: left; }
        .form-group label { display: block; font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
        .form-control {
          width: 100%;
          height: 48px;
          padding: 0 14px;
          border: 2px solid var(--line);
          border-radius: 12px;
          font-size: 14.5px;
          font-weight: 600;
          outline: none;
          background: #fff;
          transition: all .2s;
          font-family: inherit;
        }
        .form-control:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(0, 155, 76, 0.12); }
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
          box-shadow: 0 6px 18px rgba(0, 155, 76, 0.25);
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 8px;
          margin-top: 20px;
          transition: all .2s;
          font-family: inherit;
        }
        .btn-submit:hover { background: var(--brand-dark); transform: translateY(-1px); }
        .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
        
        .toast {
          display: none;
          padding: 12px 14px;
          border-radius: 12px;
          font-size: 13.5px;
          font-weight: 600;
          margin-bottom: 16px;
          text-align: left;
        }
        .toast.ok { background: var(--brand-soft); color: var(--brand-dark); border: 1px solid #bbf7d0; display: block; }
        .toast.err { background: var(--rose-soft); color: var(--rose); border: 1px solid #fecdd3; display: block; }
        
        .footer-links {
          margin-top: 24px;
          padding-top: 18px;
          border-top: 1px solid var(--line);
          display: flex;
          justify-content: space-around;
          font-size: 12.5px;
        }
        .footer-links a { color: var(--muted); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .footer-links a:hover { color: var(--brand-dark); }
      </style>
    </head>
    <body>
      <div class="login-card">
        <div class="logo-box">
          <?php if ($campusLogo): ?>
            <img src="<?= htmlspecialchars($campusLogo) ?>" alt="Logo">
          <?php else: ?>
            <i class="fa-solid fa-graduation-cap"></i>
          <?php endif; ?>
        </div>
        <h1>Presensi Mahasiswa &amp; Jurnal</h1>
        <p class="sub"><?= htmlspecialchars($campusName) ?></p>

        <div class="info-pill">
          <i class="fa-solid fa-circle-info" style="font-size:16px;flex-shrink:0"></i>
          <span>Silakan pilih nama dosen pengajar Anda untuk membuka data kelas dan mengisi presensi mahasiswa.</span>
        </div>

        <div class="toast" id="toastMsg"></div>

        <form id="formLoginDosen">
          <div class="form-group">
            <label for="selDosen"><i class="fa-solid fa-user-tie"></i> Pilih Nama Dosen Pengajar</label>
            <select id="selDosen" class="form-control" onchange="onSelChange(this)">
              <option value="">-- Pilih dari Daftar Dosen --</option>
              <?php foreach ($allDosen as $d): ?>
                <option value="<?= $d['id'] ?>" data-nidn="<?= htmlspecialchars($d['kode_nidn']) ?>">
                  <?= htmlspecialchars($d['nama']) ?> (<?= htmlspecialchars($d['kode_nidn']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="display:flex;align-items:center;gap:10px;margin:14px 0">
            <div style="flex:1;height:1px;background:var(--line)"></div>
            <span style="font-size:11px;color:var(--muted);font-weight:700">ATAU MASUKKAN NIDN</span>
            <div style="flex:1;height:1px;background:var(--line)"></div>
          </div>

          <div class="form-group">
            <label for="inputNidn"><i class="fa-solid fa-id-card"></i> NIDN / Kode Dosen</label>
            <input type="text" id="inputNidn" class="form-control" placeholder="Contoh: D001 atau NIDN">
          </div>

          <div class="form-group" id="pinGroup" style="display:none">
            <label for="inputPin"><i class="fa-solid fa-lock"></i> PIN Keamanan</label>
            <input type="password" id="inputPin" class="form-control" placeholder="Masukkan PIN keamanan">
          </div>

          <button type="submit" class="btn-submit" id="btnSubmit">
            <i class="fa-solid fa-arrow-right-to-bracket"></i>
            <span>Masuk ke Presensi Mahasiswa</span>
          </button>
        </form>

        <div class="footer-links">
          <a href="<?= htmlspecialchars($loginBaseUrl) ?>/dosen/index.php"><i class="fa-solid fa-chalkboard-user"></i> Portal Dosen</a>
          <a href="<?= htmlspecialchars($loginBaseUrl) ?>/login.php"><i class="fa-solid fa-shapes"></i> Pusat Portal</a>
        </div>
      </div>

      <script>
        function onSelChange(sel) {
          const opt = sel.options[sel.selectedIndex];
          const nidn = opt ? opt.dataset.nidn : '';
          const inputNidn = document.getElementById('inputNidn');
          if (inputNidn && nidn) {
            inputNidn.value = nidn;
          }
        }

        const form = document.getElementById('formLoginDosen');
        const toast = document.getElementById('toastMsg');
        const btn = document.getElementById('btnSubmit');

        function showToast(msg, type) {
          toast.className = 'toast ' + type;
          toast.textContent = msg;
        }

        form.addEventListener('submit', async (e) => {
          e.preventDefault();
          const sel = document.getElementById('selDosen');
          const dosenId = sel ? sel.value : 0;
          const nidn = document.getElementById('inputNidn').value.trim();
          const pin = document.getElementById('inputPin').value.trim();

          btn.disabled = true;
          btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Memproses...</span>';

          const loginApi = '<?= $loginBaseUrl ?>/api/v1/dosen/login.php';

          try {
            const res = await fetch(loginApi, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ dosen_id: dosenId, nidn: nidn, pin: pin })
            });
            const json = await res.json();

            if (json.success) {
              showToast(json.pesan, 'ok');
              setTimeout(() => {
                window.location.reload();
              }, 500);
            } else {
              if (json.need_pin) {
                const pinGroup = document.getElementById('pinGroup');
                if (pinGroup) pinGroup.style.display = 'block';
                document.getElementById('inputPin').focus();
              }
              showToast(json.pesan || 'Gagal masuk', 'err');
              btn.disabled = false;
              btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> <span>Masuk ke Presensi Mahasiswa</span>';
            }
          } catch (err) {
            showToast('Koneksi terputus: ' + err.message, 'err');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> <span>Masuk ke Presensi Mahasiswa</span>';
          }
        });
      </script>
    </body>
    </html>
    <?php
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
                $mhsStatusAkademik = $_POST['status_akademik'] ?? [];
                $mhsKets = $_POST['catatan_mhs'] ?? [];

                $insertP = $pdo->prepare("INSERT INTO `presensi_mahasiswa` (jurnal_id, jadwal_id, nim, nama, status, status_akademik, catatan, created_at, updated_at)
                    VALUES (:jid, :jadwal_id, :nim, :nama, :status, :status_akademik, :catatan, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

                $checkKm = $pdo->prepare("SELECT id FROM `kelas_mahasiswa` WHERE jadwal_id = :jid AND nama = :nama LIMIT 1");
                $insertKm = $pdo->prepare("INSERT INTO `kelas_mahasiswa` (jadwal_id, nim, nama, created_at) VALUES (:jid, :nim, :nama, CURRENT_TIMESTAMP)");

                foreach ($mhsNamas as $idx => $namaMhs) {
                    $namaMhs = trim($namaMhs);
                    if ($namaMhs === '') continue;
                    $nim = trim($mhsNims[$idx] ?? '');
                    $status = $mhsStatuses[$idx] ?? 'hadir';
                    $statusAkad = trim($mhsStatusAkademik[$idx] ?? 'Aktif');
                    $ket = trim($mhsKets[$idx] ?? '');

                    $insertP->execute([
                        'jid' => $jurnalId,
                        'jadwal_id' => $jadwalId,
                        'nim' => $nim,
                        'nama' => $namaMhs,
                        'status' => $status,
                        'status_akademik' => $statusAkad,
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
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
      --shadow: 0 4px 14px rgba(0,0,0,0.06);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      overflow-x: hidden;
      max-width: 100vw;
    }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg);
      color: var(--ink);
      min-height: 100vh;
      padding: 16px;
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
    }
    .wrapper {
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 16px;
      width: 100%;
    }

    /* Top Navigation Header */
    .top-header {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 14px 20px;
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
      flex-shrink: 0;
    }
    .brand-logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
    .brand-text h1 { font-size: 16px; font-weight: 800; color: var(--brand-dark); line-height: 1.2; }
    .brand-text small { font-size: 12px; color: var(--muted); font-weight: 500; }
    .header-links {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
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
      padding: 6px 14px;
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
      flex-shrink: 0;
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

    /* Card Component */
    .card {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 20px;
      box-shadow: var(--shadow-sm);
      width: 100%;
      box-sizing: border-box;
    }
    .card-title {
      font-size: 15px;
      font-weight: 800;
      color: var(--ink);
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--line);
      gap: 10px;
      flex-wrap: wrap;
    }

    /* Class Selector Tabs (Sama Persis dengan Rekap Presensi) */
    .class-selector-card {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 16px;
      box-shadow: var(--shadow-sm);
      width: 100%;
      box-sizing: border-box;
    }
    .class-selector-head {
      font-size: 12.5px;
      font-weight: 800;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .class-tabs {
      display: flex;
      gap: 12px;
      overflow-x: auto;
      padding-bottom: 8px;
      -webkit-overflow-scrolling: touch;
      scroll-snap-type: x mandatory;
    }
    .class-tabs::-webkit-scrollbar {
      height: 6px;
    }
    .class-tabs::-webkit-scrollbar-track {
      background: #f1f5f9;
      border-radius: 4px;
    }
    .class-tabs::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 4px;
    }
    .class-tab {
      background: #f8fafc;
      border: 1.5px solid var(--line);
      border-radius: 12px;
      padding: 12px 14px;
      text-decoration: none;
      color: var(--ink);
      min-width: 210px;
      flex: 0 0 230px;
      flex-shrink: 0;
      scroll-snap-align: start;
      transition: all .2s;
    }
    .class-tab:hover {
      border-color: var(--brand);
      background: var(--brand-soft);
    }
    .class-tab.active {
      border-color: var(--brand);
      background: #f0fdf4;
      box-shadow: 0 3px 8px rgba(0, 155, 76, 0.12);
    }
    .class-tab-mk {
      font-size: 13.5px;
      font-weight: 800;
      color: var(--brand-dark);
      margin-bottom: 3px;
      line-height: 1.3;
    }
    .class-tab-meta {
      font-size: 11.5px;
      color: var(--muted);
      font-weight: 600;
      display: flex;
      gap: 6px;
    }
    .class-tab-time {
      font-size: 11px;
      color: var(--muted);
      margin-top: 5px;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    /* Active Class Info Banner */
    .active-class-banner {
      background: linear-gradient(135deg, #f0fdf4 0%, #e6f6ed 100%);
      border: 1.5px solid #bbf7d0;
      border-radius: var(--radius);
      padding: 14px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      flex-wrap: wrap;
      width: 100%;
      box-sizing: border-box;
    }
    .ac-title {
      font-size: 17px;
      font-weight: 800;
      color: var(--brand-dark);
      line-height: 1.3;
    }
    .ac-meta {
      font-size: 12px;
      color: var(--muted);
      margin-top: 3px;
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }

    /* Form Fields */
    .form-group {
      margin-bottom: 12px;
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
      padding: 9px 12px;
      border: 1.5px solid var(--line);
      border-radius: 10px;
      font-size: 13px;
      font-family: inherit;
      color: var(--ink);
      background: #fff;
      outline: none;
      box-sizing: border-box;
      transition: border-color .2s;
    }
    .form-control:focus {
      border-color: var(--brand);
    }
    textarea.form-control {
      resize: vertical;
      min-height: 75px;
    }
    .form-grid-3 {
      display: grid;
      grid-template-columns: 140px 1fr 180px;
      gap: 12px;
    }
    .form-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    /* Toolbar & Search */
    .presensi-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }
    .btn-mark-all {
      background: #dcfce7;
      color: #15803d;
      border: 1.5px solid #86efac;
      padding: 9px 16px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 800;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all .2s;
    }
    .btn-mark-all:hover {
      background: #bbf7d0;
      border-color: #4ade80;
    }
    .mhs-search-bar {
      position: relative;
      width: 100%;
      margin-bottom: 12px;
    }
    .mhs-search-bar i {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--muted);
      font-size: 14px;
    }
    .mhs-search-bar input {
      width: 100%;
      padding: 10px 14px 10px 38px;
      border: 1.5px solid var(--line);
      border-radius: 12px;
      font-size: 13px;
      background: #f8fafc;
      color: var(--ink);
      outline: none;
      box-sizing: border-box;
      transition: all .2s;
    }
    .mhs-search-bar input:focus {
      background: #fff;
      border-color: var(--brand);
      box-shadow: 0 0 0 3px rgba(0, 155, 76, 0.12);
    }
    .mhs-counter-pills {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
      margin-bottom: 14px;
    }
    .mhs-counter-pill {
      background: #fff;
      border: 1.5px solid var(--line);
      border-radius: 10px;
      padding: 6px 10px;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
    }
    .mhs-counter-pill.h { border-color: #86efac; background: #f0fdf4; color: #15803d; }
    .mhs-counter-pill.i { border-color: #7dd3fc; background: #f0f9ff; color: #0369a1; }
    .mhs-counter-pill.s { border-color: #fde68a; background: #fffbeb; color: #b45309; }
    .mhs-counter-pill.a { border-color: #fca5a5; background: #fef2f2; color: #b91c1c; }
    .mhs-counter-pill b { font-size: 16px; font-weight: 800; line-height: 1; }
    .mhs-counter-pill span { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }

    /* Student Cards Grid (Sesuai Desain Gambar User) */
    .attendance-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
      gap: 16px;
      width: 100%;
      box-sizing: border-box;
    }
    @media (max-width: 640px) {
      .attendance-cards-grid {
        grid-template-columns: 1fr !important;
        gap: 14px !important;
      }
    }

    /* Single Student Card */
    .student-card {
      background: #ffffff;
      border: 1px solid #e9edf2;
      border-radius: 20px;
      padding: 22px 24px;
      box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
      display: flex;
      flex-direction: column;
      transition: all .2s ease;
      width: 100%;
      box-sizing: border-box;
    }
    .student-card:hover, .student-card:focus-within {
      border-color: #cbd5e1;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07);
    }
    .card-student-nim {
      font-size: 13.5px;
      font-weight: 600;
      color: #64748b;
      letter-spacing: 0.3px;
      margin-bottom: 4px;
      font-family: inherit;
    }
    .card-student-name {
      font-size: 17.5px;
      font-weight: 800;
      color: #0f172a;
      text-transform: uppercase;
      letter-spacing: -0.2px;
      line-height: 1.3;
      word-break: break-word;
    }
    .card-student-divider {
      height: 1px;
      background: #f1f5f9;
      margin: 16px 0 18px 0;
      width: 100%;
    }
    .card-field-group {
      margin-bottom: 16px;
    }
    .card-field-group:last-of-type {
      margin-bottom: 0;
    }
    .card-field-label {
      display: block;
      font-size: 11px;
      font-weight: 800;
      color: #7b8e9f;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      margin-bottom: 8px;
    }
    .card-select-wrap {
      position: relative;
      width: 100%;
    }
    .card-select-status {
      width: 100%;
      height: 48px;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 0 42px 0 16px;
      font-size: 15px;
      font-weight: 600;
      color: #1e293b;
      background: #ffffff;
      outline: none;
      cursor: pointer;
      -webkit-appearance: none;
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: calc(100% - 16px) center;
      transition: all .2s;
      font-family: inherit;
    }
    .card-select-status:focus {
      border-color: #009B4C;
      box-shadow: 0 0 0 3px rgba(0, 155, 76, 0.1);
    }

    /* Segmented Pill Container (PILIH KEHADIRAN) */
    .card-presence-track {
      background: #f1f4f8;
      border-radius: 16px;
      padding: 5px;
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 5px;
      align-items: center;
      box-sizing: border-box;
    }
    .card-presence-opt {
      position: relative;
    }
    .card-presence-opt input[type="radio"] {
      position: absolute;
      opacity: 0;
      width: 0;
      height: 0;
      pointer-events: none;
    }
    .presence-pill-btn {
      height: 44px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 12px;
      font-size: 16px;
      font-weight: 700;
      color: #52637a;
      cursor: pointer;
      user-select: none;
      transition: all .15s ease;
      background: transparent;
    }
    .card-presence-opt input[type="radio"]:checked + .presence-pill-btn.btn-h {
      background: #48b884;
      color: #ffffff;
      font-weight: 800;
      box-shadow: 0 4px 12px rgba(72, 184, 132, 0.35);
    }
    .card-presence-opt input[type="radio"]:checked + .presence-pill-btn.btn-i {
      background: #0284c7;
      color: #ffffff;
      font-weight: 800;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.35);
    }
    .card-presence-opt input[type="radio"]:checked + .presence-pill-btn.btn-s {
      background: #f59e0b;
      color: #ffffff;
      font-weight: 800;
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);
    }
    .card-presence-opt input[type="radio"]:checked + .presence-pill-btn.btn-a {
      background: #ef4444;
      color: #ffffff;
      font-weight: 800;
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
    }

    /* Catatan Khusus Mahasiswa */
    .card-note-wrap {
      margin-top: 12px;
    }
    .card-note-input {
      width: 100%;
      height: 36px;
      border: 1px dashed #cbd5e1;
      border-radius: 10px;
      padding: 0 12px;
      font-size: 12.5px;
      color: #334155;
      background: #fbfcfd;
      outline: none;
      box-sizing: border-box;
      transition: all .2s;
      font-family: inherit;
    }
    .card-note-input:focus {
      background: #ffffff;
      border: 1px solid #009B4C;
      box-shadow: 0 0 0 2px rgba(0, 155, 76, 0.1);
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

    /* Riwayat Jurnal Item (Sama dengan Rekap Presensi) */
    .jurnal-item {
      padding: 14px 16px;
      border: 1px solid var(--line);
      border-radius: 12px;
      background: #fff;
      margin-bottom: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
    }
    .jurnal-item:hover { border-color: var(--brand); }
    .jurnal-p-badge {
      background: var(--brand-soft);
      color: var(--brand-dark);
      font-weight: 800;
      font-size: 12px;
      padding: 4px 10px;
      border-radius: 8px;
      display: inline-block;
      margin-bottom: 6px;
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

    /* Floating Sticky Action Bar */
    .mobile-sticky-action-bar {
      display: none;
    }

    /* Mobile Responsive Optimizations (Layar HP) */
    @media (max-width: 768px) {
      body {
        padding: 10px 8px 85px 8px !important;
        overflow-x: hidden !important;
      }
      .wrapper {
        gap: 12px !important;
        max-width: 100% !important;
      }
      .card {
        padding: 16px 12px !important;
        border-radius: 16px !important;
        max-width: 100% !important;
      }
      .top-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px !important;
        padding: 14px 12px !important;
      }
      .header-links {
        display: grid !important;
        grid-template-columns: 1fr 1fr auto auto !important;
        gap: 6px !important;
        width: 100% !important;
      }
      .dosen-pill {
        grid-column: 1 / -1;
        margin-bottom: 4px;
        padding: 6px 10px;
      }
      .header-links .btn-icon {
        justify-content: center !important;
        padding: 8px 6px !important;
        font-size: 11.5px !important;
      }
      .class-selector-card {
        padding: 14px 12px !important;
      }
      .class-tab {
        min-width: 180px !important;
        flex: 0 0 190px !important;
        padding: 10px 12px !important;
      }
      .active-class-banner {
        padding: 14px 12px !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px !important;
      }
      .banner-actions {
        width: 100% !important;
        display: flex !important;
        gap: 6px !important;
      }
      .banner-actions .btn-icon {
        flex: 1 !important;
        justify-content: center !important;
      }
      .form-grid-3 {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
      }
      .form-grid-2 {
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
      }
      .presensi-toolbar {
        flex-direction: column !important;
        align-items: stretch !important;
      }
      .btn-mark-all {
        width: 100% !important;
        justify-content: center !important;
        height: 44px !important;
      }

      /* Kartu Mahasiswa di Layar HP */
      .student-card {
        padding: 18px 16px !important;
        border-radius: 18px !important;
      }
      .card-student-name {
        font-size: 16px !important;
      }
      .card-select-status {
        height: 46px !important;
        font-size: 14.5px !important;
      }
      .presence-pill-btn {
        height: 42px !important;
        font-size: 15px !important;
      }

      .jurnal-item {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px !important;
      }
      .jurnal-item > div:last-child {
        width: 100% !important;
        justify-content: space-between !important;
      }

      /* Floating Sticky Action Bar */
      .mobile-sticky-action-bar {
        display: flex !important;
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        z-index: 1000 !important;
        background: rgba(255, 255, 255, 0.96) !important;
        backdrop-filter: blur(14px) !important;
        border-top: 1.5px solid var(--line) !important;
        padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px)) !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px !important;
        box-shadow: 0 -6px 24px rgba(0, 0, 0, 0.08) !important;
      }
      .m-stats {
        display: flex !important;
        align-items: center !important;
        gap: 5px !important;
      }
      .m-stat-pill {
        padding: 5px 8px !important;
        border-radius: 8px !important;
        font-size: 11px !important;
        font-weight: 700 !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 3px !important;
      }
      .m-stat-pill.hadir { background: #dcfce7 !important; color: #15803d !important; }
      .m-stat-pill.izin  { background: #e0f2fe !important; color: #0369a1 !important; }
      .m-stat-pill.sakit { background: #fef3c7 !important; color: #b45309 !important; }
      .m-stat-pill.alpa  { background: #fee2e2 !important; color: #b91c1c !important; }
      .m-btn-save {
        padding: 10px 18px !important;
        font-size: 13.5px !important;
        height: 42px !important;
        border-radius: 12px !important;
        flex-shrink: 0 !important;
      }
    }
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
      
      <a href="rekap_presensi.php?jadwal_id=<?= $selectedJadwalId ?>" class="btn-icon" style="background:#e0f2fe;color:#0284c7;border-color:#bae6fd;font-weight:700" title="Rekapitulasi Presensi & Unduh Laporan Kelas">
        <i class="fa-solid fa-table-cells"></i> <span>Rekap Presensi Mhs</span>
      </a>
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

  <!-- Class Selector Tabs (Sama Persis dengan Rekap Presensi) -->
  <div class="class-selector-card">
    <div class="class-selector-head">
      <span><i class="fa-solid fa-chalkboard-user" style="color:var(--brand);margin-right:6px"></i> Pilih Kelas yang Diampu</span>
      <span style="font-weight:700;color:var(--brand)"><?= count($classes) ?> Kelas Tersedia</span>
    </div>

    <div class="class-tabs">
      <?php if (empty($classes)): ?>
        <p style="font-size:13px;color:var(--muted);padding:8px 0">Tidak ada jadwal perkuliahan yang diampu.</p>
      <?php else: ?>
        <?php foreach ($classes as $c): ?>
          <?php $isActive = ((int)$c['id'] === $selectedJadwalId); ?>
          <a href="presensi_mahasiswa.php?jadwal_id=<?= $c['id'] ?>" class="class-tab <?= $isActive ? 'active' : '' ?>">
            <div class="class-tab-mk"><?= htmlspecialchars($c['mata_kuliah']) ?></div>
            <div class="class-tab-meta">
              <span><b>Kelas:</b> <?= htmlspecialchars($c['kelas']) ?></span>
              <span>·</span>
              <span><?= htmlspecialchars($c['prodi_nama']) ?></span>
            </div>
            <div class="class-tab-time">
              <i class="fa-regular fa-clock"></i> <?= $namaHariIndo[$c['hari']] ?? 'Hari ' . $c['hari'] ?>, <?= substr($c['jam_mulai'],0,5) ?>-<?= substr($c['jam_selesai'],0,5) ?> (<?= htmlspecialchars($c['ruang']) ?>)
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$activeJadwal): ?>
    <div class="card" style="text-align:center;padding:40px 20px">
      <i class="fa-solid fa-book-open" style="font-size:40px;color:var(--muted);margin-bottom:12px"></i>
      <h3>Pilih Kelas yang Diampu</h3>
      <p style="font-size:13.5px;color:var(--muted);margin-top:4px">Silakan pilih kelas pada pilihan di atas untuk mengisi presensi mahasiswa dan jurnal materi perkuliahan.</p>
    </div>
  <?php else: ?>

    <!-- Banner Ringkasan Kelas Aktif -->
    <div class="active-class-banner">
      <div>
        <h2 class="ac-title"><?= htmlspecialchars($activeJadwal['mata_kuliah']) ?></h2>
        <div class="ac-meta">
          <span><b>Kelas:</b> <?= htmlspecialchars($activeJadwal['kelas']) ?></span>
          <span>·</span>
          <span><?= htmlspecialchars($activeJadwal['prodi_nama']) ?></span>
          <span>·</span>
          <span><b><?= count($rosterMahasiswa) ?></b> Mahasiswa Terdaftar</span>
          <span>·</span>
          <span>Ruang <?= htmlspecialchars($activeJadwal['ruang']) ?></span>
        </div>
      </div>
      <div class="banner-actions" style="display:flex;gap:6px">
        <a href="rekap_presensi.php?jadwal_id=<?= $selectedJadwalId ?>" class="btn-icon" style="background:#e0f2fe;color:#0284c7;border-color:#bae6fd;font-weight:700">
          <i class="fa-solid fa-table-cells"></i> <span>Rekap 16 Pertemuan</span>
        </a>
      </div>
    </div>

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

        <!-- Baris Metadata Pertemuan 1 -->
        <div class="form-grid-3">
          <div class="form-group">
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

          <div class="form-group">
            <label for="metode"><i class="fa-solid fa-chalkboard-user"></i> Metode Kuliah</label>
            <select name="metode" id="metode" class="form-control">
              <option value="offline" <?= ($editJurnal['metode'] ?? 'offline') === 'offline' ? 'selected' : '' ?>>Tatap Muka (Offline)</option>
              <option value="online" <?= ($editJurnal['metode'] ?? '') === 'online' ? 'selected' : '' ?>>Daring (Online)</option>
            </select>
          </div>
        </div>

        <!-- Baris Metadata Pertemuan 2: Jam -->
        <div class="form-grid-2">
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

        <!-- JURNAL PERKULIAHAN (MATERI) -->
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

        <!-- DAFTAR PRESENSI MAHASISWA -->
        <div style="margin-top:20px">
          <?php
            // Tentukan daftar mahasiswa yang ditampilkan di form
            $displayStudents = [];
            if ($editJurnal && !empty($editJurnal['presensi'])) {
                foreach ($editJurnal['presensi'] as $pRow) {
                    $displayStudents[] = [
                        'nim' => $pRow['nim'],
                        'nama' => $pRow['nama'],
                        'status' => $pRow['status'],
                        'status_akademik' => $pRow['status_akademik'] ?? 'Aktif',
                        'catatan' => $pRow['catatan']
                    ];
                }
            } elseif (!empty($rosterMahasiswa)) {
                foreach ($rosterMahasiswa as $rm) {
                    $displayStudents[] = [
                        'nim' => $rm['nim'],
                        'nama' => $rm['nama'],
                        'status' => 'hadir',
                        'status_akademik' => 'Aktif',
                        'catatan' => ''
                    ];
                }
            }
          ?>

          <div class="presensi-toolbar">
            <div>
              <h4 style="font-size:15px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:6px">
                <i class="fa-solid fa-list-check" style="color:var(--brand)"></i>
                <span>Presensi Mahasiswa (<?= count($displayStudents) ?> Mahasiswa)</span>
              </h4>
              <small style="font-size:11.5px;color:var(--muted)">
                Pilih status: <b>H</b> (Hadir), <b>I</b> (Izin), <b>S</b> (Sakit), <b>A</b> (Alpa)
              </small>
            </div>
            <div>
              <button type="button" class="btn-mark-all" onclick="markAllStatus('hadir')" title="Klik untuk mengubah semua mahasiswa menjadi Hadir">
                <i class="fa-solid fa-check-double"></i> <span>Tandai Semua Hadir</span>
              </button>
            </div>
          </div>

          <!-- Live KPI Ringkasan Presensi -->
          <div class="mhs-counter-pills">
            <div class="mhs-counter-pill h">
              <b id="topCountHadir">0</b>
              <span>Hadir</span>
            </div>
            <div class="mhs-counter-pill i">
              <b id="topCountIzin">0</b>
              <span>Izin</span>
            </div>
            <div class="mhs-counter-pill s">
              <b id="topCountSakit">0</b>
              <span>Sakit</span>
            </div>
            <div class="mhs-counter-pill a">
              <b id="topCountAlpa">0</b>
              <span>Alpa</span>
            </div>
          </div>

          <!-- Pencarian Cepat Mahasiswa -->
          <?php if (!empty($displayStudents)): ?>
            <div class="mhs-search-bar">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" id="mhsSearchInput" placeholder="Cari nama atau NIM mahasiswa...">
            </div>
          <?php endif; ?>

          <?php if (empty($displayStudents)): ?>
            <div style="background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:12px;padding:28px 16px;text-align:center;color:#64748b;margin:12px 0">
              <i class="fa-solid fa-users-slash" style="font-size:32px;margin-bottom:8px;color:#94a3b8"></i>
              <div style="font-weight:800;font-size:14px;color:#334155">Belum Ada Mahasiswa Terdaftar di Kelas Ini</div>
              <p style="font-size:12.5px;margin:6px auto 0;max-width:440px;line-height:1.5">
                Data mahasiswa dikelola terpusat oleh Admin Kampus melalui menu <b>Impor Data Mahasiswa</b>. Hubungi operator akademik untuk mengimpor daftar mahasiswa kelas <b><?= htmlspecialchars($activeJadwal['kelas']) ?></b>.
              </p>
            </div>
          <?php else: ?>
            <div class="attendance-cards-grid" id="attendanceCardContainer">
              <?php foreach ($displayStudents as $sIdx => $st): ?>
                <div class="student-card row-student" data-name="<?= strtolower(htmlspecialchars($st['nama'])) ?>" data-nim="<?= strtolower(htmlspecialchars($st['nim'] ?? '')) ?>">
                  <input type="hidden" name="mhs_nim[]" value="<?= htmlspecialchars($st['nim'] ?? '') ?>">
                  <input type="hidden" name="mhs_nama[]" value="<?= htmlspecialchars($st['nama']) ?>">
                  
                  <div class="card-student-nim"><?= htmlspecialchars($st['nim'] ?: '-') ?></div>
                  <div class="card-student-name"><?= htmlspecialchars($st['nama']) ?></div>
                  <div class="card-student-divider"></div>

                  <div class="card-field-group">
                    <label class="card-field-label">STATUS AKADEMIK</label>
                    <div class="card-select-wrap">
                      <select name="status_akademik[]" class="card-select-status">
                        <option value="Aktif" <?= ($st['status_akademik'] ?? 'Aktif') === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                        <option value="Cuti" <?= ($st['status_akademik'] ?? '') === 'Cuti' ? 'selected' : '' ?>>Cuti</option>
                        <option value="Non-Aktif" <?= ($st['status_akademik'] ?? '') === 'Non-Aktif' ? 'selected' : '' ?>>Non-Aktif</option>
                      </select>
                    </div>
                  </div>

                  <div class="card-field-group">
                    <label class="card-field-label">PILIH KEHADIRAN</label>
                    <div class="card-presence-track">
                      <div class="card-presence-opt">
                        <input type="radio" id="st_h_<?= $sIdx ?>" name="status[<?= $sIdx ?>]" value="hadir" <?= ($st['status'] ?? 'hadir') === 'hadir' ? 'checked' : '' ?>>
                        <label for="st_h_<?= $sIdx ?>" class="presence-pill-btn btn-h" title="Hadir">H</label>
                      </div>
                      <div class="card-presence-opt">
                        <input type="radio" id="st_i_<?= $sIdx ?>" name="status[<?= $sIdx ?>]" value="izin" <?= ($st['status'] ?? '') === 'izin' ? 'checked' : '' ?>>
                        <label for="st_i_<?= $sIdx ?>" class="presence-pill-btn btn-i" title="Izin">I</label>
                      </div>
                      <div class="card-presence-opt">
                        <input type="radio" id="st_s_<?= $sIdx ?>" name="status[<?= $sIdx ?>]" value="sakit" <?= ($st['status'] ?? '') === 'sakit' ? 'checked' : '' ?>>
                        <label for="st_s_<?= $sIdx ?>" class="presence-pill-btn btn-s" title="Sakit">S</label>
                      </div>
                      <div class="card-presence-opt">
                        <input type="radio" id="st_a_<?= $sIdx ?>" name="status[<?= $sIdx ?>]" value="alpa" <?= ($st['status'] ?? '') === 'alpa' ? 'checked' : '' ?>>
                        <label for="st_a_<?= $sIdx ?>" class="presence-pill-btn btn-a" title="Alpa / Tanpa Keterangan">A</label>
                      </div>
                    </div>
                  </div>

                  <div class="card-note-wrap">
                    <input type="text" name="catatan_mhs[]" value="<?= htmlspecialchars($st['catatan'] ?? '') ?>" class="card-note-input" placeholder="Catatan khusus mahasiswa (opsional)...">
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Tombol Simpan Form -->
        <div style="margin-top:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
          <span style="font-size:12px;color:var(--muted)">
            Pastikan materi jurnal dan presensi mahasiswa telah sesuai sebelum disimpan.
          </span>
          <button type="submit" class="btn-primary" style="padding:12px 24px">
            <i class="fa-solid fa-floppy-disk"></i>
            <span><?= $editJurnal ? 'Perbarui Jurnal & Presensi' : 'Simpan Jurnal & Presensi' ?></span>
          </button>
        </div>
      </form>

      <!-- Mobile Sticky Action Bar -->
      <div class="mobile-sticky-action-bar">
        <div class="m-stats">
          <span class="m-stat-pill hadir" title="Total Hadir"><span id="mCountHadir">0</span> H</span>
          <span class="m-stat-pill izin" title="Total Izin"><span id="mCountIzin">0</span> I</span>
          <span class="m-stat-pill sakit" title="Total Sakit"><span id="mCountSakit">0</span> S</span>
          <span class="m-stat-pill alpa" title="Total Alpa"><span id="mCountAlpa">0</span> A</span>
        </div>
        <button type="button" class="btn-primary m-btn-save" onclick="document.getElementById('formPresensi').submit()">
          <i class="fa-solid fa-floppy-disk"></i>
          <span>Simpan</span>
        </button>
      </div>
    </div>

    <!-- RIWAYAT JURNAL & REKAP KELAS (KARTU RESPONSIVE) -->
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
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php foreach ($jurnalList as $jl): ?>
            <?php $st = $jl['stat']; ?>
            <div class="jurnal-item">
              <div style="flex:1;min-width:240px">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span class="jurnal-p-badge">Pertemuan Ke-<?= $jl['pertemuan_ke'] ?></span>
                  <span style="font-size:12.5px;color:var(--muted);font-weight:600">
                    <i class="fa-regular fa-calendar"></i> <?= date('d M Y', strtotime($jl['tanggal'])) ?>
                  </span>
                  <?php if (!empty($jl['jam_mulai'])): ?>
                    <span style="font-size:12px;color:var(--muted)">
                      <i class="fa-regular fa-clock"></i> <?= substr($jl['jam_mulai'],0,5) ?> - <?= substr($jl['jam_selesai'],0,5) ?>
                    </span>
                  <?php endif; ?>
                  <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;background:#f1f5f9;color:var(--muted);text-transform:uppercase">
                    <?= htmlspecialchars($jl['metode'] ?: 'offline') ?>
                  </span>
                </div>

                <div style="margin-top:6px;font-size:13.5px;font-weight:700;color:#0f172a;line-height:1.4">
                  <?= nl2br(htmlspecialchars($jl['materi'])) ?>
                </div>

                <?php if (!empty($jl['capaian_catatan'])): ?>
                  <div style="margin-top:4px;font-size:12px;color:var(--muted)">
                    <i class="fa-regular fa-comment-dots"></i> <b>Catatan:</b> <?= htmlspecialchars($jl['capaian_catatan']) ?>
                  </div>
                <?php endif; ?>
              </div>

              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:space-between">
                <div style="display:flex;gap:4px;font-size:12px">
                  <span class="stat-badge badge-h" title="Hadir"><?= $st['hadir'] ?> H</span>
                  <span class="stat-badge badge-i" title="Izin"><?= $st['izin'] ?> I</span>
                  <span class="stat-badge badge-s" title="Sakit"><?= $st['sakit'] ?> S</span>
                  <span class="stat-badge badge-a" title="Alpa"><?= $st['alpa'] ?> A</span>
                </div>

                <div style="display:flex;gap:4px">
                  <a href="presensi_mahasiswa.php?jadwal_id=<?= $activeJadwal['id'] ?>&edit_jurnal=<?= $jl['id'] ?>" 
                     class="btn-icon" style="padding:6px 10px;font-size:12px" title="Edit Jurnal & Presensi Ini">
                    <i class="fa-solid fa-pen-to-square"></i> <span>Edit</span>
                  </a>
                  <form action="presensi_mahasiswa.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus data pertemuan ke-<?= $jl['pertemuan_ke'] ?> ini?')">
                    <input type="hidden" name="action" value="delete_jurnal">
                    <input type="hidden" name="jadwal_id" value="<?= $activeJadwal['id'] ?>">
                    <input type="hidden" name="del_id" value="<?= $jl['id'] ?>">
                    <button type="submit" class="btn-icon" style="padding:6px 10px;font-size:12px;color:var(--rose)" title="Hapus">
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>

<script>
  function updateAttendanceCounts() {
    let h = 0, i = 0, s = 0, a = 0;
    document.querySelectorAll('.row-student input[type="radio"]:checked').forEach(r => {
      if (r.value === 'hadir') h++;
      else if (r.value === 'izin') i++;
      else if (r.value === 'sakit') s++;
      else if (r.value === 'alpa') a++;
    });

    // Update bottom sticky bar
    const elH = document.getElementById('mCountHadir');
    const elI = document.getElementById('mCountIzin');
    const elS = document.getElementById('mCountSakit');
    const elA = document.getElementById('mCountAlpa');
    if (elH) elH.textContent = h;
    if (elI) elI.textContent = i;
    if (elS) elS.textContent = s;
    if (elA) elA.textContent = a;

    // Update top KPI pills
    const topH = document.getElementById('topCountHadir');
    const topI = document.getElementById('topCountIzin');
    const topS = document.getElementById('topCountSakit');
    const topA = document.getElementById('topCountAlpa');
    if (topH) topH.textContent = h;
    if (topI) topI.textContent = i;
    if (topS) topS.textContent = s;
    if (topA) topA.textContent = a;
  }

  function markAllStatus(status) {
    const radios = document.querySelectorAll(`input[type="radio"][value="${status}"]`);
    radios.forEach(r => {
      r.checked = true;
    });
    updateAttendanceCounts();
  }

  document.addEventListener('DOMContentLoaded', () => {
    updateAttendanceCounts();
    document.querySelectorAll('.row-student input[type="radio"]').forEach(r => {
      r.addEventListener('change', updateAttendanceCounts);
    });

    // Live search filter mahasiswa
    const searchInput = document.getElementById('mhsSearchInput');
    if (searchInput) {
      searchInput.addEventListener('input', function(e) {
        const q = e.target.value.toLowerCase().trim();
        document.querySelectorAll('.student-card').forEach(card => {
          const name = card.getAttribute('data-name') || '';
          const nim = card.getAttribute('data-nim') || '';
          if (name.includes(q) || nim.includes(q)) {
            card.style.display = '';
          } else {
            card.style.display = 'none';
          }
        });
      });
    }
  });
</script>

</body>
</html>