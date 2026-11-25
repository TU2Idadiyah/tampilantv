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
      background: #fff;
      -webkit-overflow-scrolling: touch;
    }
    table.table-presensi {
      width: 100%;
      min-width: 680px;
      border-collapse: collapse;
      font-size: 13.5px;
      background: #fff;
    }
    table.table-presensi th {
      background: #f8fafc;
      padding: 12px 14px;
      text-align: left;
      font-weight: 800;
      color: #334155;
      border-bottom: 2px solid var(--line);
      white-space: nowrap;
      font-size: 13px;
    }
    table.table-presensi td {
      padding: 12px 14px;
      border-bottom: 1px solid var(--line);
      vertical-align: middle;
    }
    table.table-presensi tr:last-child td { border-bottom: none; }
    table.table-presensi tr:hover td { background: #fbfdfc; }

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

    /* Mobile Responsive Optimizations (Layar HP) */
    .mobile-sticky-action-bar {
      display: none;
    }
    @media (max-width: 860px) {
      .grid-layout { grid-template-columns: 1fr; }
      .class-list-box {
        display: flex;
        overflow-x: auto;
        gap: 10px;
        padding-bottom: 6px;
        -webkit-overflow-scrolling: touch;
      }
      .class-item {
        flex: 0 0 240px;
        margin-bottom: 0;
      }
    }
    @media (max-width: 640px) {
      body {
        padding: 10px 8px 85px 8px !important;
      }
      .wrapper {
        gap: 12px;
      }
      .card {
        padding: 16px 14px !important;
        border-radius: 16px !important;
      }
      .top-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px !important;
        padding: 14px 12px !important;
      }
      .brand-box {
        justify-content: flex-start !important;
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
      .form-row {
        flex-direction: column !important;
        gap: 10px !important;
      }
      .form-row > * {
        width: 100% !important;
      }

      /* Transformasi Tabel Mahasiswa Menjadi Kartu Interaktif HP */
      .table-container {
        border: none !important;
        background: transparent !important;
        overflow-x: visible !important;
      }
      table.table-presensi {
        min-width: 100% !important;
        display: block !important;
        background: transparent !important;
      }
      table.table-presensi thead {
        display: none !important;
      }
      table.table-presensi tbody {
        display: flex !important;
        flex-direction: column !important;
        gap: 10px !important;
      }
      table.table-presensi tr.row-student {
        display: flex !important;
        flex-direction: column !important;
        background: #ffffff !important;
        border: 1.5px solid var(--line) !important;
        border-radius: 16px !important;
        padding: 14px !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03) !important;
        gap: 10px !important;
        transition: all .2s ease !important;
      }
      table.table-presensi tr.row-student:focus-within {
        border-color: var(--brand) !important;
        box-shadow: 0 4px 14px rgba(0, 155, 76, 0.12) !important;
      }
      table.table-presensi td {
        display: block !important;
        padding: 0 !important;
        border: none !important;
      }
      .col-no {
        display: none !important;
      }
      .col-nama {
        margin-bottom: -2px;
      }
      .mhs-nama-text {
        font-size: 15px !important;
        font-weight: 800 !important;
        color: var(--ink) !important;
        line-height: 1.35 !important;
      }
      .col-nim {
        margin-top: -6px;
      }
      .mhs-nim-text {
        font-size: 12px !important;
        color: var(--muted) !important;
        background: #f1f5f9;
        padding: 2px 8px;
        border-radius: 6px;
        display: inline-block;
      }
      .status-group {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        width: 100% !important;
        background: #f1f5f9 !important;
        border-radius: 12px !important;
        padding: 4px !important;
        gap: 6px !important;
      }
      .status-option {
        flex: 1 !important;
      }
      .status-label {
        width: 100% !important;
        height: 42px !important;
        font-size: 14px !important;
        font-weight: 800 !important;
        border-radius: 9px !important;
      }
      .input-catatan {
        font-size: 13px !important;
        height: 38px !important;
        border-radius: 10px !important;
        background: #f8fafc !important;
      }
      .input-catatan:focus {
        background: #fff !important;
      }

      /* Floating Sticky Action Bar */
      .mobile-sticky-action-bar {
        display: flex;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 1000;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(14px);
        border-top: 1.5px solid var(--line);
        padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px));
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        box-shadow: 0 -6px 24px rgba(0, 0, 0, 0.08);
      }
      .m-stats {
        display: flex;
        align-items: center;
        gap: 5px;
      }
      .m-stat-pill {
        padding: 5px 8px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 3px;
      }
      .m-stat-pill.hadir { background: #dcfce7; color: #15803d; }
      .m-stat-pill.izin  { background: #e0f2fe; color: #0369a1; }
      .m-stat-pill.sakit { background: #fef3c7; color: #b45309; }
      .m-stat-pill.alpa  { background: #fee2e2; color: #b91c1c; }
      .m-btn-save {
        padding: 10px 18px !important;
        font-size: 13.5px !important;
        height: 42px !important;
        border-radius: 12px !important;
        flex-shrink: 0;
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
          <div class="class-list-box">
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

      <!-- Info Ringkas Kelas Aktif -->
      <?php if ($activeJadwal): ?>
        <div class="card" style="margin-top:16px">
          <div class="card-title">
            <span><i class="fa-solid fa-users" style="color:var(--sky)"></i> Mahasiswa Kelas</span>
            <span style="font-size:12px;font-weight:800;color:var(--brand-dark)"><?= count($rosterMahasiswa) ?> Mhs</span>
          </div>
          <p style="font-size:12.5px;color:var(--muted);margin-bottom:10px">
            Daftar mahasiswa terdaftar di kelas <b><?= htmlspecialchars($activeJadwal['kelas']) ?></b>.
          </p>
          <div style="font-size:12px;color:#475569;background:#f8fafc;padding:10px 14px;border-radius:10px;line-height:1.5;border:1px solid var(--line)">
            <i class="fa-solid fa-shield-halved" style="color:var(--brand);margin-right:4px"></i>
            Data mahasiswa dikelola secara terpusat oleh Admin Kampus melalui menu <b>Impor Data Mahasiswa</b>.
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
                  <a href="rekap_presensi.php?jadwal_id=<?= $selectedJadwalId ?>" class="btn-secondary" style="font-size:12.5px;padding:6px 12px;color:#0284c7;background:#e0f2fe;border-color:#bae6fd;font-weight:700" title="Lihat Rekap Presensi Lengkap Kelas Ini">
                    <i class="fa-solid fa-table-cells"></i> <span>Lihat Rekap Kelas</span>
                  </a>
                  <button type="button" class="btn-secondary" onclick="markAllStatus('hadir')" style="font-size:12.5px;padding:6px 12px;color:#15803d;background:#dcfce7;border-color:#bbf7d0;font-weight:700">
                    <i class="fa-solid fa-check-double"></i> <span>Set Semua Hadir</span>
                  </button>
                </div>
              </div>

              <?php
                // Tentukan daftar mahasiswa yang ditampilkan di form
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
                <div style="background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:12px;padding:28px 16px;text-align:center;color:#64748b;margin:12px 0">
                  <i class="fa-solid fa-users-slash" style="font-size:32px;margin-bottom:8px;color:#94a3b8"></i>
                  <div style="font-weight:800;font-size:14px;color:#334155">Belum Ada Mahasiswa Terdaftar di Kelas Ini</div>
                  <p style="font-size:12.5px;margin:6px auto 0;max-width:440px;line-height:1.5">
                    Data mahasiswa dikelola terpusat oleh Admin Kampus melalui menu <b>Impor Data Mahasiswa</b>. Hubungi operator akademik untuk mengimpor atau memperbarui daftar mahasiswa kelas <b><?= htmlspecialchars($activeJadwal['kelas']) ?></b>.
                  </p>
                </div>
              <?php else: ?>
                <div class="table-container">
                  <table class="table-presensi">
                    <thead>
                      <tr>
                        <th style="width:44px;text-align:center">No</th>
                        <th style="width:140px;white-space:nowrap">NIM</th>
                        <th style="min-width:200px">Nama Mahasiswa</th>
                        <th style="width:180px;text-align:center">Status Kehadiran</th>
                        <th style="min-width:140px">Keterangan</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($displayStudents as $sIdx => $st): ?>
                        <tr class="row-student">
                          <td class="col-no" style="text-align:center;font-weight:700;color:var(--muted);font-size:13px"><?= $sIdx + 1 ?></td>
                          <td class="col-nim" style="white-space:nowrap">
                            <input type="hidden" name="mhs_nim[]" value="<?= htmlspecialchars($st['nim'] ?? '') ?>">
                            <span class="mhs-nim-text" style="font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13.5px;font-weight:700;color:#1e293b;letter-spacing:0.5px">
                              <?= htmlspecialchars($st['nim'] ?: '-') ?>
                            </span>
                          </td>
                          <td class="col-nama">
                            <input type="hidden" name="mhs_nama[]" value="<?= htmlspecialchars($st['nama']) ?>">
                            <span class="mhs-nama-text" style="font-size:14px;font-weight:700;color:#0f172a;display:block;line-height:1.4">
                              <?= htmlspecialchars($st['nama']) ?>
                            </span>
                          </td>
                          <td class="col-status" style="text-align:center">
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
                          <td class="col-catatan">
                            <input type="text" name="catatan_mhs[]" value="<?= htmlspecialchars($st['catatan'] ?? '') ?>" class="form-control input-catatan" style="padding:6px 10px;font-size:12.5px;height:34px" placeholder="Catatan (opsional)">
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

<script>
  function updateAttendanceCounts() {
    let h = 0, i = 0, s = 0, a = 0;
    document.querySelectorAll('.row-student input[type="radio"]:checked').forEach(r => {
      if (r.value === 'hadir') h++;
      else if (r.value === 'izin') i++;
      else if (r.value === 'sakit') s++;
      else if (r.value === 'alpa') a++;
    });
    const elH = document.getElementById('mCountHadir');
    const elI = document.getElementById('mCountIzin');
    const elS = document.getElementById('mCountSakit');
    const elA = document.getElementById('mCountAlpa');
    if (elH) elH.textContent = h;
    if (elI) elI.textContent = i;
    if (elS) elS.textContent = s;
    if (elA) elA.textContent = a;
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
  });
</script>

</body>
</html>
