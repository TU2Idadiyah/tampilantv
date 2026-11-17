<?php
/**
 * Halaman Registrasi & Profil HP Pengurus
 * GET /absen
 */
require_once dirname(__DIR__) . '/api/v1/absen/helper.php';

try {
    $pdoAbsen = getAbsenPdo();
} catch (Throwable $e) {
    $pdoAbsen = null;
}

if (!$pdoAbsen) {
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
            <p>Portal Presensi Pengurus memerlukan koneksi basis data. Silakan hubungkan database Anda melalui Pusat Setup Database.</p>
            <a href="../setup.php" class="btn">Buka Setup Database &rarr;</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$auth = getActivePengurusFromCookie();
$pengurus = $auth ? $auth['pengurus'] : null;
$perangkat = $auth ? $auth['perangkat'] : null;
$campusName = 'STAI SIDOGIRI';
$campusSub = 'Sekolah Tinggi Agama Islam Sidogiri';
$campusLogo = null;
try {
    $rowCamp = $pdoAbsen->query("SELECT `kunci`, `nilai` FROM `pengaturan` WHERE `kunci` IN ('nama_kampus', 'subjudul_kampus')")->fetchAll();
    foreach ($rowCamp as $rc) {
        if ($rc['kunci'] === 'nama_kampus') $campusName = json_decode($rc['nilai'], true) ?: 'STAI SIDOGIRI';
        if ($rc['kunci'] === 'subjudul_kampus') $campusSub = json_decode($rc['nilai'], true) ?: $campusSub;
    }
    $rowLogo = $pdoAbsen->query("SELECT `path` FROM `media` WHERE `koleksi` = 'logo' AND `aktif` = 1 ORDER BY `id` DESC LIMIT 1")->fetch();
    if ($rowLogo && !empty($rowLogo['path'])) {
        $campusLogo = str_starts_with($rowLogo['path'], 'http') ? $rowLogo['path'] : '../storage/' . ltrim($rowLogo['path'], '/');
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Presensi Pengurus - STAI Sidogiri</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
  <style>
    :root {
      --g: #009B4C;
      --g-dark: #006b34;
      --g-soft: #e6f6ed;
      --ink: #16261d;
      --muted: #62756a;
      --line: #dfeae3;
      --card: #ffffff;
      --bg: #f3f8f5;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg);
      color: var(--ink);
      display: flex;
      justify-content: center;
      min-height: 100vh;
      padding: 20px 16px;
    }
    .card {
      width: 100%;
      max-width: 420px;
      background: var(--card);
      border-radius: 24px;
      padding: 30px 24px;
      box-shadow: 0 10px 30px rgba(0, 155, 76, 0.08);
      border: 1px solid var(--line);
      margin: auto 0;
    }
    .header { text-align: center; margin-bottom: 26px; }
    .logo-badge {
      width: 64px;
      height: 64px;
      background: linear-gradient(135deg, var(--g), #37c47a);
      color: #fff;
      font-size: 24px;
      font-weight: 800;
      border-radius: 18px;
      display: grid;
      place-items: center;
      margin: 0 auto 12px;
    }
    h1 { font-size: 22px; font-weight: 800; color: var(--g-dark); }
    p.sub { font-size: 14px; color: var(--muted); margin-top: 4px; }
    .form-group { margin-bottom: 18px; text-align: left; }
    label { display: block; font-size: 13px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
    input[type="text"], input[type="password"] {
      width: 100%;
      height: 48px;
      padding: 0 16px;
      border: 2px solid var(--line);
      border-radius: 12px;
      font-size: 16px;
      font-weight: 600;
      outline: none;
      transition: border-color .2s;
    }
    input:focus { border-color: var(--g); }
    button.btn {
      width: 100%;
      height: 50px;
      background: var(--g);
      color: #fff;
      border: none;
      border-radius: 14px;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 6px 18px rgba(0, 155, 76, 0.25);
      transition: opacity .2s, transform .1s;
    }
    button.btn:active { transform: scale(0.98); }
    .alert {
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 14px;
      margin-bottom: 16px;
      display: none;
    }
    .alert.err { background: #fee2e2; color: #991b1b; display: block; }
    .alert.ok { background: var(--g-soft); color: var(--g-dark); display: block; }

    /* Profil Aktif Card */
    .profile-box {
      background: var(--g-soft);
      border-radius: 18px;
      padding: 20px;
      text-align: center;
      margin-bottom: 20px;
    }
    .profile-box b { font-size: 18px; color: var(--g-dark); display: block; }
    .profile-box small { font-size: 14px; color: var(--muted); }
    .status-badge {
      display: inline-block;
      margin-top: 12px;
      padding: 6px 16px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 13px;
    }
    .status-badge.hadir { background: var(--g); color: #fff; }
    .status-badge.pulang { background: #fef3c7; color: #b45309; }
    .status-badge.belum { background: #fee2e2; color: #991b1b; }
    
    /* Box Sesi & Riwayat */
    .section-title {
      font-size: 14px;
      font-weight: 800;
      color: var(--g-dark);
      margin: 18px 0 10px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .session-item {
      background: #f8fafc;
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 10px 14px;
      margin-bottom: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 13px;
    }
    .session-item.active {
      background: var(--g-soft);
      border-color: #a7f3d0;
    }
    .recap-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-bottom: 16px;
    }
    .recap-card {
      background: #f8fafc;
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 12px;
      text-align: center;
    }
    .recap-card small { font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; display: block; }
    .recap-card b { font-size: 16px; color: var(--g-dark); display: block; margin-top: 2px; }

    .history-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12.5px;
      margin-top: 8px;
    }
    .history-table th, .history-table td {
      padding: 8px 10px;
      border-bottom: 1px solid var(--line);
      text-align: left;
    }
    .history-table th { color: var(--muted); font-weight: 700; font-size: 11px; text-transform: uppercase; }

    .btn-logout {
      background: transparent;
      border: 2px solid #ef4444;
      color: #ef4444;
      border-radius: 12px;
      padding: 10px;
      width: 100%;
      font-weight: 700;
      cursor: pointer;
    }
    .info-footer { text-align: center; font-size: 12px; color: var(--muted); margin-top: 24px; line-height: 1.5; }
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <div class="logo-badge" style="overflow:hidden">
        <?php if ($campusLogo): ?>
          <img src="<?= htmlspecialchars($campusLogo) ?>" style="width:100%;height:100%;object-fit:contain;background:#fff;padding:2px" alt="Logo">
        <?php else: ?>
          STAI
        <?php endif; ?>
      </div>
      <h1>Presensi Pengurus</h1>
      <p class="sub"><?= htmlspecialchars($campusName) ?></p>
    </div>

    <div id="alertBox" class="alert"></div>

    <?php if ($pengurus): ?>
      <!-- Perangkat Sudah Terdaftar -->
      <div class="profile-box">
        <?php if (!empty($pengurus['foto_path'])): ?>
          <div style="width:68px;height:68px;border-radius:50%;margin:0 auto 10px;background:#fff;border:3px solid var(--g);overflow:hidden">
            <img src="../storage/<?= htmlspecialchars(ltrim($pengurus['foto_path'], '/')) ?>" style="width:100%;height:100%;object-fit:cover" alt="<?= htmlspecialchars($pengurus['nama']) ?>">
          </div>
        <?php endif; ?>
        <b><?= htmlspecialchars($pengurus['nama']) ?></b>
        <small><?= htmlspecialchars($pengurus['jabatan']) ?> · NIP: <?= htmlspecialchars($pengurus['nip'] ?? '-') ?></small>
        <br>
        <?php if ($pengurus['status'] === 'masuk'): ?>
          <span class="status-badge hadir">✓ Sedang Hadir · Sesi <?= $pengurus['sesi_ke'] ?> (<?= htmlspecialchars($pengurus['waktu_masuk'] ?? '') ?> WIB)</span>
        <?php elseif ($pengurus['status'] === 'pulang'): ?>
          <span class="status-badge pulang">🚪 Pulang (<?= htmlspecialchars($pengurus['waktu_pulang'] ?? '') ?> WIB)</span>
          <div style="font-size:12px;color:var(--g-dark);font-weight:700;margin-top:6px">Total Durasi Hari Ini: <?= htmlspecialchars($pengurus['total_durasi_hari_ini_formatted'] ?? '0 menit') ?></div>
        <?php else: ?>
          <span class="status-badge belum">● Belum Presensi Hari Ini</span>
        <?php endif; ?>
      </div>

      <p style="font-size:13.5px;color:var(--muted);text-align:center;margin-bottom:18px;line-height:1.45">
        <?php if ($pengurus['status'] === 'masuk'): ?>
          Anda sedang dalam sesi presensi aktif. Ketika hendak <b>keluar / pulang</b>, cukup scan kode QR kartu Anda di layar TV untuk mencatat kepulangan dan menghitung durasi otomatis.
        <?php elseif ($pengurus['status'] === 'pulang'): ?>
          Presensi pulang berhasil dicatat. Jika Anda kembali bertugas hari ini, scan ulang kode QR di layar TV untuk <b>memulai sesi baru</b>.
        <?php else: ?>
          Buka kamera HP Anda lalu <b>scan kode QR kartu Anda yang tayang di layar TV</b> untuk presensi masuk.
        <?php endif; ?>
      </p>

      <!-- Sesi Presensi Hari Ini -->
      <?php if (!empty($pengurus['sesi_hari_ini'])): ?>
        <div class="section-title">
          <span>Riwayat Sesi Hari Ini</span>
          <span style="font-size:12px;color:var(--g-dark)"><?= count($pengurus['sesi_hari_ini']) ?> Sesi</span>
        </div>
        <div style="margin-bottom:16px">
          <?php foreach ($pengurus['sesi_hari_ini'] as $s): ?>
            <div class="session-item <?= $s['status'] === 'masuk' ? 'active' : '' ?>">
              <div>
                <b>Sesi <?= $s['sesi_ke'] ?></b>: <?= $s['waktu_masuk'] ?> &rarr; <?= $s['waktu_pulang'] ?: '<span style="color:var(--g-dark);font-weight:700">Sedang Aktif</span>' ?>
              </div>
              <div>
                <?php if ($s['status'] === 'pulang'): ?>
                  <span style="font-weight:700;color:var(--muted)"><?= $s['durasi_short'] ?></span>
                <?php else: ?>
                  <span style="color:var(--g);font-weight:700">Masuk</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Rekapitulasi Presensi Bulan Ini -->
      <?php if (!empty($pengurus['rekap_bulan_ini'])): ?>
        <div class="section-title">
          <span>Rekapitulasi Bulan Ini</span>
          <span style="font-size:11px;color:var(--muted)"><?= date('F Y') ?></span>
        </div>
        <div class="recap-grid">
          <div class="recap-card">
            <small>Hari Hadir</small>
            <b><?= $pengurus['rekap_bulan_ini']['total_hari'] ?> Hari</b>
          </div>
          <div class="recap-card">
            <small>Total Sesi</small>
            <b><?= $pengurus['rekap_bulan_ini']['total_sesi'] ?> Sesi</b>
          </div>
          <div class="recap-card" style="grid-column: span 2">
            <small>Total Akumulasi Durasi Kerja</small>
            <b style="color:var(--g);font-size:18px"><?= $pengurus['rekap_bulan_ini']['total_durasi_formatted'] ?></b>
          </div>
        </div>
      <?php endif; ?>

      <!-- Riwayat 7 Hari Terakhir -->
      <?php if (!empty($pengurus['riwayat_terakhir'])): ?>
        <div class="section-title">
          <span>Riwayat Presensi Terakhir</span>
        </div>
        <div style="background:#f8fafc;border:1px solid var(--line);border-radius:14px;padding:8px 12px;margin-bottom:20px;overflow-x:auto">
          <table class="history-table">
            <thead>
              <tr>
                <th>Tanggal</th>
                <th>Sesi</th>
                <th>Total Durasi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pengurus['riwayat_terakhir'] as $h): ?>
                <tr>
                  <td><b><?= date('d M Y', strtotime($h['tanggal'])) ?></b></td>
                  <td><?= $h['sesi_count'] ?> sesi</td>
                  <td><span style="font-weight:700;color:var(--g-dark)"><?= $h['durasi_formatted'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <button type="button" class="btn-logout" onclick="logoutDevice()">Cabut Pendaftaran HP Ini</button>

      <div style="margin-top:16px;text-align:center">
        <a href="../login.php" style="color:var(--g-dark);font-size:13px;font-weight:700;text-decoration:none">← Kembali ke Pusat Portal</a>
      </div>

    <?php else: ?>
      <!-- Form Login / Bind HP Pertama Kali -->
      <form id="loginForm" onsubmit="handleLogin(event)">
        <div class="form-group">
          <label for="nip">NIP Pengurus</label>
          <input type="text" id="nip" placeholder="Contoh: 1985010103" required inputmode="numeric">
        </div>
        <div class="form-group">
          <label for="pin">PIN 6 Digit</label>
          <input type="password" id="pin" placeholder="Masukkan 6 angka PIN" maxlength="6" required inputmode="numeric">
        </div>
        <button type="submit" id="btnSubmit" class="btn">Daftarkan Perangkat Ini</button>
      </form>
      <div class="info-footer">
        PIN dibuat oleh operator/admin. Satu pengurus maksimal dapat mendaftarkan 2 perangkat HP.
        <div style="margin-top:14px;display:flex;justify-content:center;gap:12px">
          <a href="../login.php" style="color:var(--g-dark);font-weight:700;text-decoration:none">← Pusat Portal</a>
          <span style="color:var(--line)">•</span>
          <a href="../index.html" style="color:var(--muted);text-decoration:none">Layar TV</a>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script>
    async function handleLogin(e) {
      e.preventDefault();
      const nip = document.getElementById('nip').value.trim();
      const pin = document.getElementById('pin').value.trim();
      const alertBox = document.getElementById('alertBox');
      const btn = document.getElementById('btnSubmit');

      btn.disabled = true;
      btn.textContent = 'Memverifikasi...';
      alertBox.className = 'alert';
      alertBox.style.display = 'none';

      try {
        const res = await fetch('../api/v1/absen/login.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ nip, pin })
        });
        const data = await res.json();

        if (res.ok) {
          alertBox.className = 'alert ok';
          alertBox.textContent = 'Perangkat berhasil terdaftar! Memuat halaman...';
          alertBox.style.display = 'block';
          setTimeout(() => {
            const redirectUrl = new URLSearchParams(window.location.search).get('redirect');
            if (redirectUrl) {
              window.location.href = redirectUrl;
            } else {
              window.location.reload();
            }
          }, 1000);
        } else {
          alertBox.className = 'alert err';
          alertBox.textContent = data.pesan || 'Gagal mendaftarkan perangkat.';
          alertBox.style.display = 'block';
          btn.disabled = false;
          btn.textContent = 'Daftarkan Perangkat Ini';
        }
      } catch (err) {
        alertBox.className = 'alert err';
        alertBox.textContent = 'Gagal menghubungi server. Pastikan Anda terhubung ke Wi-Fi kampus.';
        alertBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Daftarkan Perangkat Ini';
      }
    }

    async function logoutDevice() {
      if (!confirm('Yakin ingin mencabut pendaftaran HP ini dari sistem presensi?')) return;
      try {
        await fetch('../api/v1/absen/logout.php', { method: 'POST' });
        window.location.reload();
      } catch (e) {
        alert('Gagal mencabut perangkat.');
      }
    }
  </script>
</body>
</html>

