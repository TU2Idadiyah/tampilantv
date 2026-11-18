<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

$message = '';
$error = '';
$newDisplayKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $upsertPengaturan = function (string $k, string $v, string $t) use ($pdo): void {
        $stmtCek = $pdo->prepare("SELECT `kunci` FROM `pengaturan` WHERE `kunci` = :k LIMIT 1");
        $stmtCek->execute(['k' => $k]);
        if ($stmtCek->fetchColumn() !== false) {
            $pdo->prepare("UPDATE `pengaturan` SET `nilai` = :v, `tipe` = :t, `updated_at` = NOW() WHERE `kunci` = :k")
                ->execute(['k' => $k, 'v' => $v, 't' => $t]);
        } else {
            $pdo->prepare("INSERT INTO `pengaturan` (`kunci`, `nilai`, `tipe`, `updated_at`) VALUES (:k, :v, :t, NOW())")
                ->execute(['k' => $k, 'v' => $v, 't' => $t]);
        }
    };

    if ($action === 'simpan_pengaturan') {
        $settings = [
            'nama_kampus' => trim($_POST['nama_kampus'] ?? 'STAI SIDOGIRI'),
            'subjudul_kampus' => trim($_POST['subjudul_kampus'] ?? ''),
            'kota' => trim($_POST['kota'] ?? 'Pasuruan'),
            'slide_detik' => (int)($_POST['slide_detik'] ?? 10),
            'halaman_detik' => (int)($_POST['halaman_detik'] ?? 10),
            'qr_rotasi_detik' => (int)($_POST['qr_rotasi_detik'] ?? 30),
            'hijriah_offset' => (int)($_POST['hijriah_offset'] ?? 0),
            'video_aktif' => isset($_POST['video_aktif']),
            'validasi_subnet_ip' => isset($_POST['validasi_subnet_ip'])
        ];

        foreach ($settings as $k => $v) {
            $upsertPengaturan(
                $k,
                json_encode($v),
                is_bool($v) ? 'boolean' : (is_int($v) ? 'integer' : 'string')
            );
        }

        // Upload Logo Baru (jika ada)
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo_file'];
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowedMimes)) {
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
                $targetDir = dirname(__DIR__) . '/storage/media';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                $targetPath = $targetDir . '/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $relPath = 'media/' . $filename;
                    // Nonaktifkan logo sebelumnya
                    $pdo->exec("UPDATE `media` SET `aktif` = 0 WHERE `koleksi` = 'logo'");
                    // Simpan record media logo baru
                    $stmtMedia = $pdo->prepare("INSERT INTO `media` (`koleksi`, `path`, `mime`, `ukuran_byte`, `durasi_detik`, `aktif`, `diunggah_oleh`, `created_at`, `updated_at`)
                                                VALUES ('logo', :path, :mime, :size, 0, 1, :uid, NOW(), NOW())");
                    $stmtMedia->execute([
                        'path' => $relPath,
                        'mime' => $mime,
                        'size' => $file['size'],
                        'uid' => $admin['id']
                    ]);

                    // Simpan juga ke kunci pengaturan logo_url
                    $upsertPengaturan('logo_url', json_encode('storage/' . $relPath), 'string');
                }
            } else {
                $error = "Format berkas logo tidak valid. Harap gunakan gambar PNG, JPG, WebP, atau SVG.";
            }
        }

        // Catat Audit
        $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `meta`, `created_at`)
                       VALUES ('admin', :uid, 'update_pengaturan', 'pengaturan', :meta, NOW())")->execute([
            'uid' => $admin['id'],
            'meta' => json_encode($settings)
        ]);

        if (!$error) {
            $message = 'Pengaturan sistem & logo kampus berhasil diperbarui. Layar TV akan otomatis mengambil perubahan dalam 3-5 detik!';
        }
    } elseif ($action === 'hapus_logo') {
        $pdo->exec("UPDATE `media` SET `aktif` = 0 WHERE `koleksi` = 'logo'");
        $pdo->prepare("DELETE FROM `pengaturan` WHERE `kunci` = 'logo_url'")->execute();
        $message = "Logo kampus kustom berhasil dihapus dan dikembalikan ke logo default teks.";
    } elseif ($action === 'generate_key') {
        $newDisplayKey = bin2hex(random_bytes(16));
        $hashKey = hash('sha256', $newDisplayKey);

        $upsertPengaturan('hash_kunci_layar', json_encode($hashKey), 'string');

        $message = "Kunci layar TV baru berhasil dibuat!";
    }
}

// Ambil pengaturan saat ini
$rows = $pdo->query("SELECT `kunci`, `nilai` FROM `pengaturan`")->fetchAll();
$cfg = [];
foreach ($rows as $r) {
    $cfg[$r['kunci']] = json_decode($r['nilai'], true);
}

// Logo Aktif
$logoRow = $pdo->query("SELECT `id`, `path` FROM `media` WHERE `koleksi` = 'logo' AND `aktif` = 1 ORDER BY `id` DESC LIMIT 1")->fetch();
$activeLogoUrl = null;
if ($logoRow && !empty($logoRow['path'])) {
    $activeLogoUrl = str_starts_with($logoRow['path'], 'http') ? $logoRow['path'] : '../storage/' . ltrim($logoRow['path'], '/');
} elseif (!empty($cfg['logo_url'])) {
    $activeLogoUrl = '../' . ltrim($cfg['logo_url'], '/');
}
?>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($newDisplayKey): ?>
    <div class="card" style="border-color:var(--g);background:var(--g-soft)">
        <h3>🔑 Kunci Layar TV Baru</h3>
        <p style="font-size:14px;color:var(--ink);margin-bottom:12px">
            Gunakan URL berikut saat membuka browser kiosk di TV (parameter <code>?k=</code> akan otomatis tersimpan di TV lalu dibersihkan):
        </p>
        <div style="background:#fff;padding:12px;border-radius:8px;font-family:monospace;font-size:14px;user-select:all;border:1px solid var(--line)">
            <?= htmlspecialchars(getBaseUrl()) ?>/?k=<?= $newDisplayKey ?>
        </div>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div class="card">
        <h3>🏛️ Pengaturan Brand & Tampilan Layar TV</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="simpan_pengaturan">

            <div style="background:var(--bg);padding:16px;border-radius:12px;margin-bottom:20px;border:1px solid var(--line)">
                <label style="font-weight:700;font-size:14px;display:block;margin-bottom:8px">Logo Kampus</label>
                <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
                    <div style="width:84px;height:84px;border-radius:18px;background:#fff;border:2px dashed var(--line);display:grid;place-items:center;overflow:hidden;flex-shrink:0">
                        <?php if ($activeLogoUrl): ?>
                            <img src="<?= htmlspecialchars($activeLogoUrl) ?>" style="width:100%;height:100%;object-fit:contain;padding:4px" alt="Logo Aktif">
                        <?php else: ?>
                            <div style="font-size:18px;font-weight:800;color:var(--g)">STAI</div>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:200px">
                        <input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                        <small style="display:block;margin-top:6px;color:var(--muted)">
                            Format: PNG (transparan disarankan), JPG, SVG, WebP. Maksimal 5 MB.
                        </small>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Nama Kampus / Brand Utama</label>
                <input type="text" name="nama_kampus" value="<?= htmlspecialchars($cfg['nama_kampus'] ?? 'STAI SIDOGIRI') ?>" required>
            </div>

            <div class="form-group">
                <label>Subjudul Kampus</label>
                <input type="text" name="subjudul_kampus" value="<?= htmlspecialchars($cfg['subjudul_kampus'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Kota Kampus (Untuk Cuaca)</label>
                <input type="text" name="kota" value="<?= htmlspecialchars($cfg['kota'] ?? 'Pasuruan') ?>" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                <div class="form-group">
                    <label>Durasi Slide (Detik)</label>
                    <input type="number" name="slide_detik" value="<?= (int)($cfg['slide_detik'] ?? 10) ?>" min="5" max="120" required>
                </div>
                <div class="form-group">
                    <label>Durasi Halaman (Detik)</label>
                    <input type="number" name="halaman_detik" value="<?= (int)($cfg['halaman_detik'] ?? 10) ?>" min="5" max="120" required>
                </div>
                <div class="form-group">
                    <label>Rotasi QR (Detik)</label>
                    <input type="number" name="qr_rotasi_detik" value="<?= (int)($cfg['qr_rotasi_detik'] ?? 30) ?>" min="15" max="120" required>
                </div>
            </div>

            <div class="form-group">
                <label>Koreksi Hari Hijriah</label>
                <select name="hijriah_offset">
                    <option value="-1" <?= ($cfg['hijriah_offset'] ?? 0) == -1 ? 'selected' : '' ?>>-1 Hari (Mundur 1 hari)</option>
                    <option value="0" <?= ($cfg['hijriah_offset'] ?? 0) == 0 ? 'selected' : '' ?>>0 (Standar Umal Qura)</option>
                    <option value="1" <?= ($cfg['hijriah_offset'] ?? 0) == 1 ? 'selected' : '' ?>>+1 Hari (Maju 1 hari)</option>
                </select>
            </div>

            <div class="form-group" style="margin-top:20px">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                    <input type="checkbox" name="video_aktif" value="1" <?= !empty($cfg['video_aktif']) ? 'checked' : '' ?> style="width:20px;height:20px">
                    <span><b>Aktifkan Slide Video Profil Kampus</b> (Muncul di putaran slide tengah)</span>
                </label>
            </div>

            <div class="form-group" style="margin-top:16px;background:var(--bg);padding:14px;border-radius:10px;border:1px solid var(--line)">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                    <input type="checkbox" name="validasi_subnet_ip" value="1" <?= (!isset($cfg['validasi_subnet_ip']) || !empty($cfg['validasi_subnet_ip'])) ? 'checked' : '' ?> style="width:20px;height:20px;margin-top:2px">
                    <div>
                        <b>Validasi Jaringan Wi-Fi Kampus (Subnet IP) saat Scan QR</b>
                        <small style="display:block;color:var(--muted);margin-top:4px;font-size:12px;line-height:1.4">
                            Centang jika TV Kiosk dan HP Pengurus berada di dalam Wi-Fi lokal kampus yang sama (192.168.x.x / 10.x.x.x).<br>
                            <b>Catatan Hosting:</b> Jika sistem di-hosting di VPS / cloud publik dan pengurus melakukan scan menggunakan paket data seluler (4G/5G), <u>hilangkan centang</u> ini agar scan tidak ditolak karena IP seluler/publik.
                        </small>
                    </div>
                </label>
            </div>

            <button type="submit" class="btn" style="margin-top:16px;padding:12px 24px">Simpan Semua Pengaturan</button>
        </form>

        <?php if ($activeLogoUrl): ?>
            <form method="POST" style="margin-top:16px;display:inline-block" onsubmit="return confirm('Hapus logo kustom dan kembali ke logo bawaan?')">
                <input type="hidden" name="action" value="hapus_logo">
                <button type="submit" class="btn btn-secondary" style="color:var(--danger);font-size:12px;padding:6px 12px">🗑️ Hapus Logo Kustom (Gunakan Default)</button>
            </form>
        <?php endif; ?>
    </div>

    <div>
        <div class="card">
            <h3>🔑 Kunci Layar TV Kiosk</h3>
            <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
                TV Kiosk di kampus memerlukan kunci otentikasi layar untuk mengakses API state.
            </p>
            <form method="POST" onsubmit="return confirm('Membuat kunci baru akan membatalkan kunci layar lama. Lanjutkan?')">
                <input type="hidden" name="action" value="generate_key">
                <button type="submit" class="btn btn-secondary" style="width:100%">🔄 Buat Ulang Kunci Layar TV</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
