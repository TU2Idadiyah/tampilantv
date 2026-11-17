<?php
/**
 * Halaman Landing Eksekusi Scan QR dari Kamera HP
 * GET /absen/scan?c=<kode_qr>
 * Selaras dengan PRD Bab 7 & 8
 */

require_once dirname(__DIR__) . '/api/v1/absen/helper.php';

$code = trim($_GET['c'] ?? '');
$auth = getActivePengurusFromCookie();

// Jika perangkat belum terdaftar, arahkan ke login dan kembali ke sini
if (!$auth) {
    $redirectUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect={$redirectUrl}");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Verifikasi Presensi - STAI Sidogiri</title>
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
      --danger: #dc2626;
      --danger-soft: #fee2e2;
      --warning: #d97706;
      --warning-soft: #fef3c7;
      --info: #2563eb;
      --info-soft: #dbeafe;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg);
      color: var(--ink);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 20px 16px;
    }
    .card {
      width: 100%;
      max-width: 420px;
      background: var(--card);
      border-radius: 28px;
      padding: 36px 24px;
      box-shadow: 0 14px 40px rgba(0, 155, 76, 0.12);
      border: 1px solid var(--line);
      text-align: center;
    }
    .icon-badge {
      width: 90px;
      height: 90px;
      border-radius: 50%;
      margin: 0 auto 20px;
      display: grid;
      place-items: center;
      font-size: 44px;
      transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .icon-badge.loading {
      background: var(--g-soft);
      animation: pulse 1.5s infinite;
    }
    .icon-badge.success {
      background: var(--g);
      color: #fff;
      transform: scale(1.05);
    }
    .icon-badge.pulang {
      background: #fef3c7;
      color: #b45309;
      transform: scale(1.05);
    }
    .icon-badge.error {
      background: var(--danger-soft);
      color: var(--danger);
    }
    .icon-badge.already {
      background: var(--info-soft);
      color: var(--info);
    }
    @keyframes pulse {
      0%, 100% { transform: scale(0.95); opacity: 0.8; }
      50% { transform: scale(1.05); opacity: 1; }
    }
    h1 { font-size: 24px; font-weight: 800; color: var(--ink); margin-bottom: 8px; }
    p.desc { font-size: 15px; color: var(--muted); line-height: 1.5; margin-bottom: 24px; }
    .meta-box {
      background: var(--g-soft);
      border-radius: 18px;
      padding: 16px;
      margin-bottom: 24px;
      text-align: left;
    }
    .meta-box b { display: block; font-size: 16px; color: var(--g-dark); }
    .meta-box small { font-size: 13px; color: var(--muted); }
    .btn {
      display: inline-block;
      width: 100%;
      height: 48px;
      line-height: 48px;
      background: var(--g);
      color: #fff;
      text-decoration: none;
      border-radius: 14px;
      font-weight: 700;
      font-size: 15px;
    }
    .btn-secondary {
      background: #e5e7eb;
      color: #374151;
      margin-top: 10px;
    }
  </style>
</head>
<body>
  <div class="card">
    <div id="iconBadge" class="icon-badge loading">⌛</div>
    <h1 id="titleText">Memverifikasi Presensi...</h1>
    <p id="descText" class="desc">Sedang memvalidasi jaringan, token QR, dan kepemilikan perangkat Anda.</p>

    <div id="metaBox" class="meta-box" style="display:none">
      <b id="metaName">-</b>
      <small id="metaDetail">-</small>
    </div>

    <a href="index.php" id="btnBack" class="btn btn-secondary" style="display:none">Buka Dashboard Saya</a>
  </div>

  <script>
    const qrCode = "<?= htmlspecialchars($code) ?>";

    async function executeScan() {
      const badge = document.getElementById('iconBadge');
      const title = document.getElementById('titleText');
      const desc = document.getElementById('descText');
      const metaBox = document.getElementById('metaBox');
      const metaName = document.getElementById('metaName');
      const metaDetail = document.getElementById('metaDetail');
      const btnBack = document.getElementById('btnBack');

      if (!qrCode) {
        badge.className = 'icon-badge error';
        badge.textContent = '❌';
        title.textContent = 'Kode QR Tidak Ditemukan';
        desc.textContent = 'Harap scan ulang kode QR resmi yang tampil di layar TV kampus.';
        btnBack.style.display = 'block';
        return;
      }

      try {
        const res = await fetch('../api/v1/absen/scan.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ c: qrCode })
        });
        const data = await res.json();

        btnBack.style.display = 'block';

        if (res.status === 200) {
          if (data.status === 'pulang') {
            // Berhasil Pulang
            badge.className = 'icon-badge pulang';
            badge.textContent = '🚪';
            title.textContent = 'Presensi Pulang Berhasil!';
            desc.innerHTML = 'Status kehadiran Anda saat ini: <b style="color:#b45309">PULANG</b>. Sesi telah diselesaikan dan total durasi dihitung otomatis.';

            if (data.pengurus) {
              metaBox.style.display = 'block';
              metaName.textContent = data.pengurus.nama;
              metaDetail.innerHTML = `
                <div style="margin-top:6px;line-height:1.6">
                  <div><b>Status:</b> <span style="color:#b45309;font-weight:700">PULANG</span> (Pukul ${data.waktu_pulang} WIB)</div>
                  <div><b>Sesi Ke:</b> ${data.sesi_ke} (Masuk: ${data.waktu_masuk} &rarr; Pulang: ${data.waktu_pulang})</div>
                  <div><b>Durasi Sesi Ini:</b> ${data.durasi_formatted}</div>
                  <div><b>Total Durasi Hari Ini:</b> <span style="color:var(--g-dark);font-weight:700">${data.total_durasi_hari_ini_formatted}</span></div>
                </div>
              `;
            }
          } else {
            // Berhasil Masuk
            badge.className = 'icon-badge success';
            badge.textContent = '✓';
            title.textContent = 'Presensi Masuk Berhasil!';
            desc.textContent = `Sesi ke-${data.sesi_ke} berhasil dicatat. Kartu Anda di layar TV sedang diperbarui secara otomatis.`;

            if (data.pengurus) {
              metaBox.style.display = 'block';
              metaName.textContent = data.pengurus.nama;
              metaDetail.innerHTML = `
                <div style="margin-top:6px;line-height:1.6">
                  <div>${data.pengurus.jabatan}</div>
                  <div><b>Status:</b> <span style="color:var(--g-dark);font-weight:700">HADIR (Sesi ${data.sesi_ke})</span></div>
                  <div><b>Jam Masuk:</b> ${data.waktu_masuk} WIB</div>
                  <div style="font-size:12px;color:var(--muted);margin-top:4px">Saat akan keluar/selesai, silakan scan kembali untuk mencatat waktu Pulang.</div>
                </div>
              `;
            }
          }
        } else if (res.status === 409 || res.status === 429) {
          // Sudah Hadir
          badge.className = 'icon-badge already';
          badge.textContent = 'ℹ️';
          title.textContent = 'Sudah Tercatat Hadir';
          desc.textContent = data.pesan || 'Anda sudah melakukan presensi hari ini.';

          if (data.pengurus) {
            metaBox.style.display = 'block';
            metaName.textContent = data.pengurus.nama;
            metaDetail.textContent = `Tercatat hadir pada pukul ${data.pengurus.waktu_absen} WIB`;
          }
        } else {
          // Gagal / Ditolak (Lapis 1 - 4)
          badge.className = 'icon-badge error';
          badge.textContent = '⚠️';
          title.textContent = 'Presensi Ditolak';
          desc.textContent = data.pesan || 'Terjadi kesalahan saat memverifikasi presensi.';
        }
      } catch (err) {
        badge.className = 'icon-badge error';
        badge.textContent = '⚡';
        title.textContent = 'Gangguan Koneksi';
        desc.textContent = 'Tidak dapat menghubungi server. Pastikan HP Anda terhubung ke Wi-Fi kampus.';
        btnBack.style.display = 'block';
      }
    }

    // Jalankan verifikasi otomatis saat halaman dimuat
    executeScan();
  </script>
</body>
</html>

