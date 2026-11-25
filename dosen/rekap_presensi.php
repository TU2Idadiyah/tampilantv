<?php
/**
 * Portal Dosen - Rekap Presensi Mahasiswa & Jurnal Perkuliahan
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
      <title>Masuk Rekapitulasi Presensi Mahasiswa - <?= htmlspecialchars($campusName) ?></title>
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
          background: linear-gradient(135deg, var(--sky), #38bdf8);
          border-radius: 20px;
          display: grid;
          place-items: center;
          color: #fff;
          font-size: 26px;
          font-weight: 800;
          margin: 0 auto 16px;
          box-shadow: 0 8px 20px rgba(2, 132, 199, 0.22);
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
          background: var(--sky);
          color: #fff;
          border: none;
          border-radius: 14px;
          font-size: 15px;
          font-weight: 800;
          cursor: pointer;
          box-shadow: 0 6px 18px rgba(2, 132, 199, 0.25);
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 8px;
          margin-top: 20px;
          transition: all .2s;
          font-family: inherit;
        }
        .btn-submit:hover { background: #0369a1; transform: translateY(-1px); }
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
            <i class="fa-solid fa-table-cells"></i>
          <?php endif; ?>
        </div>
        <h1>Rekapitulasi Presensi</h1>
        <p class="sub"><?= htmlspecialchars($campusName) ?></p>

        <div class="info-pill">
          <i class="fa-solid fa-circle-info" style="font-size:16px;flex-shrink:0"></i>
          <span>Silakan pilih nama dosen pengajar Anda untuk membuka rekapitulasi kehadiran mahasiswa per mata kuliah.</span>
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
            <span>Masuk ke Rekap Presensi</span>
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
              btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> <span>Masuk ke Rekap Presensi</span>';
            }
          } catch (err) {
            showToast('Koneksi terputus: ' + err.message, 'err');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> <span>Masuk ke Rekap Presensi</span>';
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

// Ambil daftar jurnal / pertemuan untuk jadwal ini
$jurnals = [];
$students = [];
$matrix = []; // [nim_or_nama][jurnal_id] => status
$studentStats = []; // [nim_or_nama] => [hadir, izin, sakit, alpa, total, pct]
$classAvgPct = 0;
$warningStudentCount = 0;

if ($activeJadwal) {
    // 1. Ambil jurnal perkuliahan terurut
    $stmtJ = $pdo->prepare("SELECT * FROM `jurnal_perkuliahan` WHERE jadwal_id = :jid ORDER BY pertemuan_ke ASC, tanggal ASC");
    $stmtJ->execute(['jid' => $selectedJadwalId]);
    $jurnals = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

    // 2. Ambil roster mahasiswa
    $students = getKelasMahasiswa($pdo, $selectedJadwalId);

    // 3. Ambil seluruh data presensi untuk kelas ini
    $stmtP = $pdo->prepare("SELECT * FROM `presensi_mahasiswa` WHERE jadwal_id = :jid");
    $stmtP->execute(['jid' => $selectedJadwalId]);
    $presensiRows = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Pastikan jika ada mahasiswa di presensi_mahasiswa yang belum ada di $students, kita gabungkan
    $existingKeys = [];
    foreach ($students as $st) {
        $key = !empty($st['nim']) ? $st['nim'] : mb_strtolower(trim($st['nama']));
        $existingKeys[$key] = true;
    }
    foreach ($presensiRows as $pr) {
        $key = !empty($pr['nim']) ? $pr['nim'] : mb_strtolower(trim($pr['nama']));
        if (!isset($existingKeys[$key])) {
            $students[] = [
                'id' => 0,
                'jadwal_id' => $selectedJadwalId,
                'nim' => $pr['nim'],
                'nama' => $pr['nama']
            ];
            $existingKeys[$key] = true;
        }
    }

    // Urutkan mahasiswa berdasarkan nama
    usort($students, function($a, $b) {
        return strcasecmp($a['nama'], $b['nama']);
    });

    // Petakan kehadiran ke matrix
    foreach ($presensiRows as $pr) {
        $key = !empty($pr['nim']) ? $pr['nim'] : mb_strtolower(trim($pr['nama']));
        $matrix[$key][$pr['jurnal_id']] = [
            'status' => $pr['status'],
            'catatan' => $pr['catatan']
        ];
    }

    $totalMeetings = count($jurnals);
    $totalStudentPctSum = 0;

    // Hitung statistik per mahasiswa
    foreach ($students as $st) {
        $key = !empty($st['nim']) ? $st['nim'] : mb_strtolower(trim($st['nama']));
        $h = 0; $i = 0; $s = 0; $a = 0;

        foreach ($jurnals as $jn) {
            $jId = $jn['id'];
            $stRec = $matrix[$key][$jId] ?? null;
            if ($stRec) {
                $stStatus = strtolower($stRec['status']);
                if ($stStatus === 'hadir') $h++;
                elseif ($stStatus === 'izin') $i++;
                elseif ($stStatus === 'sakit') $s++;
                elseif ($stStatus === 'alpa') $a++;
            }
        }

        $totRecorded = $h + $i + $s + $a;
        // Basis persentase: pertemuan yang sudah terlaksana ($totalMeetings)
        $pct = $totalMeetings > 0 ? round(($h / $totalMeetings) * 100, 1) : 0;

        if ($totalMeetings > 0 && $pct < 75) {
            $warningStudentCount++;
        }

        $studentStats[$key] = [
            'hadir' => $h,
            'izin' => $i,
            'sakit' => $s,
            'alpa' => $a,
            'total_sesi' => $totRecorded,
            'pct' => $pct
        ];
        $totalStudentPctSum += $pct;
    }

    if (count($students) > 0) {
        $classAvgPct = round($totalStudentPctSum / count($students), 1);
    }
}

// 4. EKSPOR CSV JIKA DIMINTA
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $activeJadwal) {
    $cleanFileName = 'Rekap_Presensi_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $activeJadwal['mata_kuliah'] . '_' . $activeJadwal['kelas']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $cleanFileName . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    $fp = fopen('php://output', 'w');

    fputcsv($fp, ['REKAPITULASI PRESENSI MAHASISWA - ' . $campusName], ';');
    fputcsv($fp, ['Mata Kuliah', $activeJadwal['mata_kuliah']], ';');
    fputcsv($fp, ['Kelas', $activeJadwal['kelas']], ';');
    fputcsv($fp, ['Dosen Pengampu', $dosen['nama'] . ' (NIDN: ' . $dosen['kode_nidn'] . ')'], ';');
    fputcsv($fp, ['Program Studi', $activeJadwal['prodi_nama']], ';');
    fputcsv($fp, ['Total Pertemuan', count($jurnals) . ' Pertemuan'], ';');
    fputcsv($fp, ['Waktu Unduh', date('d/m/Y H:i:s')], ';');
    fputcsv($fp, [], ';');

    // Header Kolom
    $headerRow = ['No', 'NIM', 'Nama Mahasiswa'];
    foreach ($jurnals as $jn) {
        $headerRow[] = 'P' . $jn['pertemuan_ke'] . ' (' . date('d/m', strtotime($jn['tanggal'])) . ')';
    }
    $headerRow[] = 'Hadir (H)';
    $headerRow[] = 'Izin (I)';
    $headerRow[] = 'Sakit (S)';
    $headerRow[] = 'Alpa (A)';
    $headerRow[] = 'Total Hadir';
    $headerRow[] = 'Persentase (%)';
    $headerRow[] = 'Keterangan';
    fputcsv($fp, $headerRow, ';');

    foreach ($students as $idx => $st) {
        $key = !empty($st['nim']) ? $st['nim'] : mb_strtolower(trim($st['nama']));
        $stat = $studentStats[$key] ?? ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'pct' => 0];
        
        $row = [
            $idx + 1,
            $st['nim'] ?: '-',
            $st['nama']
        ];

        foreach ($jurnals as $jn) {
            $stRec = $matrix[$key][$jn['id']] ?? null;
            if ($stRec) {
                $code = strtoupper(substr($stRec['status'], 0, 1));
                $row[] = $code;
            } else {
                $row[] = '-';
            }
        }

        $row[] = $stat['hadir'];
        $row[] = $stat['izin'];
        $row[] = $stat['sakit'];
        $row[] = $stat['alpa'];
        $row[] = $stat['hadir'] . '/' . count($jurnals);
        $row[] = $stat['pct'] . '%';
        $row[] = $stat['pct'] >= 75 ? 'Memenuhi Syarat' : 'Kurang (<75%)';

        fputcsv($fp, $row, ';');
    }

    // Jurnal Perkuliahan List
    fputcsv($fp, [], ';');
    fputcsv($fp, ['RINGKASAN JURNAL PERKULIAHAN (MATERI)'], ';');
    fputcsv($fp, ['Pertemuan Ke', 'Tanggal', 'Jam', 'Metode', 'Materi / Pokok Bahasan', 'Catatan Capaian / Evaluasi'], ';');
    foreach ($jurnals as $jn) {
        fputcsv($fp, [
            'Pertemuan ' . $jn['pertemuan_ke'],
            date('d/m/Y', strtotime($jn['tanggal'])),
            $jn['jam_mulai'] . ' - ' . $jn['jam_selesai'],
            strtoupper($jn['metode'] ?: 'OFFLINE'),
            $jn['materi'],
            $jn['capaian_catatan'] ?: '-'
        ], ';');
    }

    fclose($fp);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Rekap Presensi Mahasiswa - <?= htmlspecialchars($campusName) ?></title>
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
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg);
      color: var(--ink);
      min-height: 100vh;
      padding: 16px;
      -webkit-font-smoothing: antialiased;
    }

    .wrapper {
      width: 100%;
      max-width: 1280px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 16px;
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
      gap: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .brand-box { display: flex; align-items: center; gap: 12px; }
    .brand-logo {
      width: 44px; height: 44px;
      background: linear-gradient(135deg, var(--brand), #34d399);
      border-radius: 12px;
      display: grid; place-items: center;
      color: #fff; font-weight: 800; font-size: 15px;
      overflow: hidden; flex-shrink: 0;
    }
    .brand-logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
    .brand-text h1 { font-size: 17px; font-weight: 800; color: var(--brand-dark); line-height: 1.2; }
    .brand-text small { font-size: 12px; color: var(--muted); font-weight: 600; }

    .header-links { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .dosen-pill {
      display: flex; align-items: center; gap: 10px;
      background: var(--brand-soft);
      border: 1px solid rgba(0, 155, 76, 0.2);
      padding: 6px 14px; border-radius: 12px;
    }
    .dosen-avatar {
      width: 32px; height: 32px; border-radius: 50%;
      background: #fff; color: var(--brand-dark);
      display: grid; place-items: center; font-size: 13px; font-weight: 700;
      overflow: hidden;
    }
    .dosen-avatar img { width: 100%; height: 100%; object-fit: cover; }

    .btn-icon {
      background: var(--card);
      color: var(--muted);
      border: 1px solid var(--line);
      padding: 8px 12px;
      border-radius: 10px;
      font-size: 12.5px;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all .2s;
    }
    .btn-icon:hover { background: var(--brand-soft); color: var(--brand-dark); border-color: var(--brand); }
    .btn-primary {
      background: var(--brand);
      color: #fff;
      border: none;
      padding: 9px 16px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 700;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      box-shadow: 0 4px 10px rgba(0, 155, 76, 0.2);
      transition: all .2s;
    }
    .btn-primary:hover { background: var(--brand-dark); }

    /* Class Selector Tabs */
    .class-selector-card {
      background: var(--card);
      border-radius: var(--radius);
      border: 1px solid var(--line);
      padding: 16px;
    }
    .class-tabs {
      display: flex;
      gap: 10px;
      overflow-x: auto;
      padding-bottom: 4px;
      -webkit-overflow-scrolling: touch;
    }
    .class-tab {
      background: #f8fafc;
      border: 1.5px solid var(--line);
      border-radius: 12px;
      padding: 10px 14px;
      text-decoration: none;
      color: var(--ink);
      min-width: 200px;
      flex-shrink: 0;
      transition: all .2s;
    }
    .class-tab:hover { border-color: var(--brand); background: var(--brand-soft); }
    .class-tab.active {
      border-color: var(--brand);
      background: #f0fdf4;
      box-shadow: 0 3px 8px rgba(0, 155, 76, 0.12);
    }
    .class-tab-mk { font-size: 13.5px; font-weight: 800; color: var(--brand-dark); margin-bottom: 3px; }
    .class-tab-meta { font-size: 11.5px; color: var(--muted); font-weight: 600; display: flex; gap: 6px; }

    /* KPI Summary Cards */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 12px;
    }
    .kpi-card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 16px 18px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .kpi-icon {
      width: 44px; height: 44px; border-radius: 12px;
      display: grid; place-items: center; font-size: 18px;
      flex-shrink: 0;
    }
    .kpi-val { font-size: 22px; font-weight: 800; color: var(--ink); line-height: 1.2; }
    .kpi-lbl { font-size: 12px; font-weight: 600; color: var(--muted); margin-top: 2px; }

    /* Card Box */
    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--line);
    }
    .card-title {
      font-size: 16px;
      font-weight: 800;
      color: var(--ink);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* Table Matrix */
    .table-container {
      width: 100%;
      overflow-x: auto;
      border: 1px solid var(--line);
      border-radius: 12px;
      -webkit-overflow-scrolling: touch;
    }
    .matrix-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      min-width: 800px;
    }
    .matrix-table th, .matrix-table td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--line);
      text-align: left;
    }
    .matrix-table th {
      background: #f8fafc;
      font-weight: 800;
      font-size: 11.5px;
      text-transform: uppercase;
      color: var(--muted);
      letter-spacing: 0.4px;
      white-space: nowrap;
    }
    .matrix-table tbody tr:hover { background: #fdfdfd; }
    .matrix-table td.center, .matrix-table th.center { text-align: center; }

    /* Status Badges */
    .badge-h { background: #dcfce7; color: #15803d; font-weight: 800; padding: 3px 6px; border-radius: 6px; font-size: 11.5px; display: inline-block; min-width: 22px; text-align: center; }
    .badge-i { background: #e0f2fe; color: #0369a1; font-weight: 800; padding: 3px 6px; border-radius: 6px; font-size: 11.5px; display: inline-block; min-width: 22px; text-align: center; }
    .badge-s { background: #fef3c7; color: #b45309; font-weight: 800; padding: 3px 6px; border-radius: 6px; font-size: 11.5px; display: inline-block; min-width: 22px; text-align: center; }
    .badge-a { background: #fee2e2; color: #b91c1c; font-weight: 800; padding: 3px 6px; border-radius: 6px; font-size: 11.5px; display: inline-block; min-width: 22px; text-align: center; }
    .badge-none { color: #cbd5e1; font-weight: 700; }

    .pct-pill {
      padding: 4px 10px;
      border-radius: 99px;
      font-size: 12px;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .pct-green { background: #dcfce7; color: #15803d; }
    .pct-blue { background: #e0f2fe; color: #0369a1; }
    .pct-amber { background: #fef3c7; color: #b45309; }
    .pct-rose { background: #fee2e2; color: #b91c1c; }

    /* Jurnal Timeline */
    .jurnal-item {
      padding: 14px 16px;
      border: 1px solid var(--line);
      border-radius: 12px;
      background: #fff;
      margin-bottom: 10px;
      display: flex;
      justify-content: space-between;
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

    /* Print Header Styles (Only for print) */
    .print-only { display: none; }
    @media print {
      body { background: #fff; padding: 0; color: #000; }
      .wrapper { max-width: 100%; margin: 0; }
      .top-header, .class-selector-card, .btn-icon, .btn-primary, .no-print { display: none !important; }
      .card { border: none; box-shadow: none; padding: 0; }
      .print-only { display: block; }
      .print-header { text-align: center; border-bottom: 2.5px solid #000; padding-bottom: 12px; margin-bottom: 16px; }
      .print-header h2 { font-size: 18px; font-weight: 800; text-transform: uppercase; margin-bottom: 4px; }
      .print-header p { font-size: 12px; color: #333; margin: 2px 0; }
      .print-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 12px; margin-bottom: 16px; }
      .matrix-table { font-size: 11px; min-width: 100%; border: 1px solid #000; }
      .matrix-table th, .matrix-table td { border: 1px solid #000; padding: 5px 8px; }
      .matrix-table th { background: #eee !important; color: #000; }
      .badge-h, .badge-i, .badge-s, .badge-a { background: none !important; color: #000 !important; font-weight: bold; border: none; }
      .print-signatures { display: grid; grid-template-columns: 1fr 1fr; margin-top: 40px; page-break-inside: avoid; font-size: 12px; text-align: center; }
      .sign-box { padding-top: 60px; }
    }

    /* Mobile Responsive Optimizations (Layar HP) */
    .mobile-scroll-hint { display: none; }
    .mobile-nim-sub { display: none; }

    @media (max-width: 768px) {
      body {
        padding: 10px 8px;
      }
      .wrapper {
        gap: 12px;
      }
      .top-header {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
        padding: 14px 12px;
      }
      .brand-box {
        justify-content: flex-start;
      }
      .header-links {
        display: grid;
        grid-template-columns: 1fr 1fr auto auto;
        gap: 6px;
        width: 100%;
      }
      .dosen-pill {
        grid-column: 1 / -1;
        margin-bottom: 4px;
        padding: 6px 10px;
      }
      .header-links .btn-icon {
        justify-content: center;
        padding: 8px 6px;
        font-size: 11.5px;
      }
      .card {
        padding: 16px 12px;
        border-radius: 16px;
      }
      .card-head {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }
      .card-head > div:last-child {
        width: 100%;
        display: grid !important;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
      }
      .card-head > div:last-child .btn-primary {
        grid-column: 1 / -1;
        justify-content: center;
        padding: 10px 14px;
      }
      .card-head > div:last-child .btn-icon {
        justify-content: center;
        padding: 8px 6px;
        font-size: 11.5px;
      }
      .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
      }
      .kpi-card {
        padding: 12px 10px;
        gap: 10px;
        border-radius: 14px;
      }
      .kpi-icon {
        width: 36px;
        height: 36px;
        font-size: 15px;
        border-radius: 10px;
      }
      .kpi-val {
        font-size: 18px;
      }
      .kpi-lbl {
        font-size: 11px;
      }
      .class-tab {
        min-width: 170px;
        padding: 8px 10px;
      }
      .mobile-scroll-hint {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11.5px;
        color: var(--brand-dark);
        background: var(--brand-soft);
        border: 1px dashed var(--brand);
        border-radius: 8px;
        padding: 8px 12px;
        margin-bottom: 10px;
      }
      .mobile-nim-sub {
        display: block;
        font-size: 10.5px;
        color: var(--muted);
        font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
        font-weight: 600;
        margin-top: 2px;
      }
      /* Sticky Kolom Nama Mahasiswa di Matriks Tabel */
      .matrix-table {
        font-size: 12px;
      }
      .matrix-table th:nth-child(1),
      .matrix-table td:nth-child(1),
      .matrix-table th:nth-child(2),
      .matrix-table td:nth-child(2) {
        display: none;
      }
      .matrix-table th:nth-child(3),
      .matrix-table td:nth-child(3) {
        position: sticky;
        left: 0;
        z-index: 2;
        background: #f8fafc;
        border-right: 1.5px solid var(--line);
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.06);
        min-width: 130px;
        max-width: 160px;
      }
      .matrix-table tbody td:nth-child(3) {
        background: #fff;
      }
      .matrix-table tbody tr:hover td:nth-child(3) {
        background: #fdfdfd;
      }
      .jurnal-item {
        flex-direction: column;
        gap: 10px;
      }
      .jurnal-item > div:last-child {
        width: 100%;
        justify-content: space-between;
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
        <h1>Rekap Presensi Mahasiswa</h1>
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
      
      <a href="presensi_mahasiswa.php?jadwal_id=<?= $selectedJadwalId ?>" class="btn-icon" style="color:var(--brand-dark);border-color:var(--brand);font-weight:700">
        <i class="fa-solid fa-clipboard-user"></i> <span>Input Presensi</span>
      </a>
      <a href="index.php" class="btn-icon" title="Kembali ke Dashboard Utama Dosen">
        <i class="fa-solid fa-arrow-left"></i> <span>Jadwal Harian</span>
      </a>
      <a href="../login.php" class="btn-icon" title="Gerbang Portal">
        <i class="fa-solid fa-shapes"></i>
      </a>
      <a href="../api/v1/dosen/logout.php" class="btn-icon" style="color:var(--rose)" title="Keluar">
        <i class="fa-solid fa-right-from-bracket"></i>
      </a>
    </div>
  </header>

  <!-- Class Selector Tabs -->
  <div class="class-selector-card no-print">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <span style="font-size:13px;font-weight:700;color:var(--muted)">
        <i class="fa-solid fa-graduation-cap" style="color:var(--brand)"></i> Pilih Mata Kuliah &amp; Kelas:
      </span>
      <span style="font-size:12px;font-weight:700;color:var(--brand)"><?= count($classes) ?> Kelas Tersedia</span>
    </div>
    <div class="class-tabs">
      <?php if (empty($classes)): ?>
        <p style="font-size:13px;color:var(--muted)">Belum ada jadwal perkuliahan aktif yang ditugaskan ke Anda.</p>
      <?php else: ?>
        <?php foreach ($classes as $c): ?>
          <a href="rekap_presensi.php?jadwal_id=<?= $c['id'] ?>" class="class-tab <?= (int)$c['id'] === $selectedJadwalId ? 'active' : '' ?>">
            <div class="class-tab-mk"><?= htmlspecialchars($c['mata_kuliah']) ?></div>
            <div class="class-tab-meta">
              <span><b>Kelas:</b> <?= htmlspecialchars($c['kelas']) ?></span>
              <span>·</span>
              <span><?= htmlspecialchars($c['prodi_nama']) ?></span>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">
              <?= $namaHariIndo[$c['hari']] ?? 'Hari ' . $c['hari'] ?>, <?= substr($c['jam_mulai'],0,5) ?>-<?= substr($c['jam_selesai'],0,5) ?> (<?= htmlspecialchars($c['ruang']) ?>)
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($activeJadwal): ?>
    <!-- KPI Summary Grid -->
    <div class="kpi-grid no-print">
      <div class="kpi-card">
        <div class="kpi-icon" style="background:#dcfce7;color:#15803d">
          <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div>
          <div class="kpi-val"><?= count($jurnals) ?></div>
          <div class="kpi-lbl">Pertemuan Terlaksana</div>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-icon" style="background:#e0f2fe;color:#0284c7">
          <i class="fa-solid fa-users"></i>
        </div>
        <div>
          <div class="kpi-val"><?= count($students) ?></div>
          <div class="kpi-lbl">Mahasiswa Terdaftar</div>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-icon" style="background:#fef3c7;color:#b45309">
          <i class="fa-solid fa-chart-pie"></i>
        </div>
        <div>
          <div class="kpi-val"><?= $classAvgPct ?>%</div>
          <div class="kpi-lbl">Rata-rata Kehadiran Kelas</div>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-icon" style="background:<?= $warningStudentCount > 0 ? '#fee2e2' : '#f1f5f9' ?>;color:<?= $warningStudentCount > 0 ? '#b91c1c' : '#64748b' ?>">
          <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div>
          <div class="kpi-val" style="color:<?= $warningStudentCount > 0 ? '#b91c1c' : 'inherit' ?>"><?= $warningStudentCount ?></div>
          <div class="kpi-lbl">Kehadiran Rendah (&lt;75%)</div>
        </div>
      </div>
    </div>

    <!-- Print Header (Muncul Saat Dicetak) -->
    <div class="print-only">
      <div class="print-header">
        <h2><?= htmlspecialchars($campusName) ?></h2>
        <p><?= htmlspecialchars($campusSub) ?></p>
        <p style="font-weight:700;margin-top:6px;font-size:14px">REKAPITULASI PRESENSI MAHASISWA &amp; JURNAL PERKULIAHAN</p>
      </div>

      <div class="print-meta-grid">
        <div>
          <div><b>Mata Kuliah:</b> <?= htmlspecialchars($activeJadwal['mata_kuliah']) ?></div>
          <div><b>Kelas / Ruang:</b> <?= htmlspecialchars($activeJadwal['kelas']) ?> / <?= htmlspecialchars($activeJadwal['ruang']) ?></div>
          <div><b>Program Studi:</b> <?= htmlspecialchars($activeJadwal['prodi_nama']) ?></div>
        </div>
        <div style="text-align:right">
          <div><b>Dosen Pengampu:</b> <?= htmlspecialchars($dosen['nama']) ?></div>
          <div><b>NIDN:</b> <?= htmlspecialchars($dosen['kode_nidn']) ?></div>
          <div><b>Total Pertemuan:</b> <?= count($jurnals) ?> Sesi</div>
        </div>
      </div>
    </div>

    <!-- Card Matrix Kehadiran Mahasiswa -->
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">
            <i class="fa-solid fa-table-cells" style="color:var(--brand)"></i>
            <span>Matriks Presensi Mahasiswa</span>
          </div>
          <small style="color:var(--muted);font-size:12px">
            Mata Kuliah: <b><?= htmlspecialchars($activeJadwal['mata_kuliah']) ?></b> (Kelas <?= htmlspecialchars($activeJadwal['kelas']) ?>)
          </small>
        </div>

        <div style="display:flex;gap:8px" class="no-print">
          <a href="rekap_presensi.php?jadwal_id=<?= $selectedJadwalId ?>&export=csv" class="btn-icon" style="color:#0284c7;border-color:#bae6fd;background:#f0f9ff" title="Download Format CSV / Excel">
            <i class="fa-solid fa-file-excel"></i> <span>Download CSV/Excel</span>
          </a>
          <button type="button" onclick="window.print()" class="btn-icon" style="color:#15803d;border-color:#bbf7d0;background:#f0fdf4" title="Cetak Format Laporan Resmi">
            <i class="fa-solid fa-print"></i> <span>Cetak Laporan</span>
          </button>
          <a href="presensi_mahasiswa.php?jadwal_id=<?= $selectedJadwalId ?>" class="btn-primary">
            <i class="fa-solid fa-plus"></i> <span>Input Pertemuan Baru</span>
          </a>
        </div>
      </div>

      <?php if (empty($students)): ?>
        <div style="background:#f8fafc;border:1.5px dashed var(--line);border-radius:12px;padding:32px;text-align:center;color:var(--muted)">
          <i class="fa-solid fa-users-slash" style="font-size:36px;margin-bottom:8px;color:#94a3b8"></i>
          <h4 style="font-weight:800;color:var(--ink);font-size:15px">Belum Ada Mahasiswa di Kelas Ini</h4>
          <p style="font-size:13px;margin-top:4px">
            Data mahasiswa dikelola terpusat dari Portal Admin melalui menu <b>Impor Data Mahasiswa</b>.
          </p>
        </div>
      <?php else: ?>
        <div class="mobile-scroll-hint no-print">
          <i class="fa-solid fa-arrows-left-right"></i>
          <span>Geser tabel ke samping untuk melihat rincian P1 - P16 &amp; persentase</span>
        </div>
        <div class="table-container">
          <table class="matrix-table">
            <thead>
              <tr>
                <th style="width:36px" class="center">No</th>
                <th style="width:130px">NIM</th>
                <th style="min-width:180px">Nama Mahasiswa</th>
                
                <?php if (empty($jurnals)): ?>
                  <th class="center" style="color:var(--muted)">Pertemuan</th>
                <?php else: ?>
                  <?php foreach ($jurnals as $jn): ?>
                    <th class="center" title="Pertemuan <?= $jn['pertemuan_ke'] ?> (<?= date('d/m/Y', strtotime($jn['tanggal'])) ?>): <?= htmlspecialchars($jn['materi']) ?>">
                      P<?= $jn['pertemuan_ke'] ?><br>
                      <span style="font-size:9.5px;font-weight:600;opacity:0.75"><?= date('d/m', strtotime($jn['tanggal'])) ?></span>
                    </th>
                  <?php endforeach; ?>
                <?php endif; ?>

                <th class="center" style="width:36px" title="Hadir">H</th>
                <th class="center" style="width:36px" title="Izin">I</th>
                <th class="center" style="width:36px" title="Sakit">S</th>
                <th class="center" style="width:36px" title="Alpa">A</th>
                <th class="center" style="width:65px">Total</th>
                <th class="center" style="width:90px">Persentase</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $idx => $st): ?>
                <?php
                  $key = !empty($st['nim']) ? $st['nim'] : mb_strtolower(trim($st['nama']));
                  $stat = $studentStats[$key] ?? ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'pct' => 0];
                  $pct = $stat['pct'];
                  $pctClass = $pct >= 80 ? 'pct-green' : ($pct >= 75 ? 'pct-blue' : ($pct >= 60 ? 'pct-amber' : 'pct-rose'));
                ?>
                <tr>
                  <td class="center" style="color:var(--muted);font-weight:700"><?= $idx + 1 ?></td>
                  <td style="font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-weight:700;font-size:12.5px;color:#1e293b">
                    <?= htmlspecialchars($st['nim'] ?: '-') ?>
                  </td>
                  <td style="font-weight:700;color:#0f172a">
                    <?= htmlspecialchars($st['nama']) ?>
                    <?php if (!empty($st['nim'])): ?>
                      <div class="mobile-nim-sub"><?= htmlspecialchars($st['nim']) ?></div>
                    <?php endif; ?>
                  </td>

                  <?php if (empty($jurnals)): ?>
                    <td class="center" style="color:var(--muted);font-size:12px">-</td>
                  <?php else: ?>
                    <?php foreach ($jurnals as $jn): ?>
                      <?php
                        $stRec = $matrix[$key][$jn['id']] ?? null;
                        $status = $stRec ? strtolower($stRec['status']) : null;
                      ?>
                      <td class="center">
                        <?php if ($status === 'hadir'): ?>
                          <span class="badge-h" title="Hadir">H</span>
                        <?php elseif ($status === 'izin'): ?>
                          <span class="badge-i" title="Izin: <?= htmlspecialchars($stRec['catatan'] ?? '') ?>">I</span>
                        <?php elseif ($status === 'sakit'): ?>
                          <span class="badge-s" title="Sakit: <?= htmlspecialchars($stRec['catatan'] ?? '') ?>">S</span>
                        <?php elseif ($status === 'alpa'): ?>
                          <span class="badge-a" title="Alpa / Tanpa Keterangan">A</span>
                        <?php else: ?>
                          <span class="badge-none">-</span>
                        <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endif; ?>

                  <td class="center"><b style="color:#15803d"><?= $stat['hadir'] ?></b></td>
                  <td class="center"><b style="color:#0369a1"><?= $stat['izin'] ?></b></td>
                  <td class="center"><b style="color:#b45309"><?= $stat['sakit'] ?></b></td>
                  <td class="center"><b style="color:#b91c1c"><?= $stat['alpa'] ?></b></td>
                  <td class="center" style="font-weight:700;color:var(--muted)">
                    <?= $stat['hadir'] ?> / <?= count($jurnals) ?>
                  </td>
                  <td class="center">
                    <span class="pct-pill <?= $pctClass ?>">
                      <?= $pct ?>%
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Card Riwayat Jurnal Perkuliahan (Materi) -->
    <div class="card no-print">
      <div class="card-head">
        <div class="card-title">
          <i class="fa-solid fa-book-journal-whills" style="color:var(--brand)"></i>
          <span>Riwayat Jurnal Perkuliahan &amp; Materi Diajarkan</span>
        </div>
        <span style="font-size:12.5px;font-weight:700;color:var(--muted)">
          <?= count($jurnals) ?> Pertemuan Tercatat
        </span>
      </div>

      <?php if (empty($jurnals)): ?>
        <p style="font-size:13px;color:var(--muted);text-align:center;padding:16px">
          Belum ada pertemuan perkuliahan yang diisi jurnal presensinya.
        </p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php foreach ($jurnals as $jn): ?>
            <?php
              $stmtPStat = $pdo->prepare("SELECT 
                  SUM(CASE WHEN status='hadir' THEN 1 ELSE 0 END) as h,
                  SUM(CASE WHEN status='izin' THEN 1 ELSE 0 END) as i,
                  SUM(CASE WHEN status='sakit' THEN 1 ELSE 0 END) as s,
                  SUM(CASE WHEN status='alpa' THEN 1 ELSE 0 END) as a
                  FROM `presensi_mahasiswa` WHERE jurnal_id = :jid");
              $stmtPStat->execute(['jid' => $jn['id']]);
              $pStat = $stmtPStat->fetch(PDO::FETCH_ASSOC);
            ?>
            <div class="jurnal-item">
              <div style="flex:1;min-width:260px">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span class="jurnal-p-badge">Pertemuan Ke-<?= $jn['pertemuan_ke'] ?></span>
                  <span style="font-size:12.5px;color:var(--muted);font-weight:600">
                    <i class="fa-regular fa-calendar"></i> <?= date('d M Y', strtotime($jn['tanggal'])) ?>
                  </span>
                  <?php if (!empty($jn['jam_mulai'])): ?>
                    <span style="font-size:12px;color:var(--muted)">
                      <i class="fa-regular fa-clock"></i> <?= substr($jn['jam_mulai'],0,5) ?> - <?= substr($jn['jam_selesai'],0,5) ?>
                    </span>
                  <?php endif; ?>
                  <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;background:#f1f5f9;color:var(--muted);text-transform:uppercase">
                    <?= htmlspecialchars($jn['metode'] ?: 'offline') ?>
                  </span>
                </div>

                <div style="margin-top:6px;font-size:13.5px;font-weight:700;color:#0f172a;line-height:1.4">
                  <?= nl2br(htmlspecialchars($jn['materi'])) ?>
                </div>

                <?php if (!empty($jn['capaian_catatan'])): ?>
                  <div style="margin-top:4px;font-size:12px;color:var(--muted)">
                    <b>Catatan/Tugas:</b> <?= htmlspecialchars($jn['capaian_catatan']) ?>
                  </div>
                <?php endif; ?>
              </div>

              <div style="display:flex;align-items:center;gap:12px;flex-shrink:0">
                <div style="display:flex;gap:6px;font-size:12px">
                  <span class="badge-h" title="Hadir"><?= (int)($pStat['h'] ?? 0) ?> H</span>
                  <span class="badge-i" title="Izin"><?= (int)($pStat['i'] ?? 0) ?> I</span>
                  <span class="badge-s" title="Sakit"><?= (int)($pStat['s'] ?? 0) ?> S</span>
                  <span class="badge-a" title="Alpa"><?= (int)($pStat['a'] ?? 0) ?> A</span>
                </div>

                <a href="presensi_mahasiswa.php?jadwal_id=<?= $selectedJadwalId ?>&edit_jurnal=<?= $jn['id'] ?>" class="btn-icon" style="font-size:12px;padding:6px 10px">
                  <i class="fa-solid fa-pen-to-square"></i> <span>Edit</span>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Print Signatures (Hanya muncul saat dicetak) -->
    <div class="print-only">
      <div class="print-signatures">
        <div>
          <div>Mengetahui,</div>
          <div><b>Ketua Program Studi <?= htmlspecialchars($activeJadwal['prodi_nama']) ?></b></div>
          <div class="sign-box">_________________________________</div>
        </div>
        <div>
          <div>Sidogiri, <?= date('d F Y') ?></div>
          <div><b>Dosen Pengampu Mata Kuliah</b></div>
          <div class="sign-box"><b><?= htmlspecialchars($dosen['nama']) ?></b><br>NIDN. <?= htmlspecialchars($dosen['kode_nidn']) ?></div>
        </div>
      </div>
    </div>

  <?php endif; ?>
</div>

</body>
</html>
