<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_video') {
        if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Gagal mengunggah berkas video atau ukuran melebihi batas upload server PHP.';
        } else {
            $tmp = $_FILES['video_file']['tmp_name'];
            $origName = $_FILES['video_file']['name'];
            $size = (int)$_FILES['video_file']['size'];
            $mime = mime_content_type($tmp);

            // Validasi format MP4 (PRD Bab 11)
            if ($mime !== 'video/mp4' && !str_ends_with(strtolower($origName), '.mp4')) {
                $error = 'Berkas harus berformat video MP4 (H.264 + AAC).';
            } elseif ($size > 200 * 1024 * 1024) { // 200 MB
                $error = 'Ukuran video maksimal adalah 200 MB.';
            } else {
                $mediaDir = dirname(__DIR__) . '/storage/media';
                if (!is_dir($mediaDir)) {
                    mkdir($mediaDir, 0777, true);
                }

                $newFilename = 'profil_' . uniqid() . '.mp4';
                $destPath = $mediaDir . '/' . $newFilename;

                if (move_uploaded_file($tmp, $destPath)) {
                    $durasiDetik = (int)($_POST['durasi_detik'] ?? 180);
                    if ($durasiDetik <= 0) $durasiDetik = 180;

                    // Nonaktifkan video lama (penggantian atomik)
                    $pdo->prepare("UPDATE `media` SET `aktif` = 0 WHERE `koleksi` = 'video_profil'")->execute();

                    // Simpan video baru
                    $sqlInsert = "INSERT INTO `media`
                        (`koleksi`, `path`, `mime`, `ukuran_byte`, `durasi_detik`, `aktif`, `diunggah_oleh`, `created_at`, `updated_at`)
                        VALUES ('video_profil', :path, 'video/mp4', :size, :dur, 1, :uid, NOW(), NOW())";
                    $pdo->prepare($sqlInsert)->execute([
                        'path' => 'media/' . $newFilename,
                        'size' => $size,
                        'dur' => $durasiDetik,
                        'uid' => $admin['id']
                    ]);

                    // Aktifkan juga di pengaturan
                    $pdo->prepare("UPDATE `pengaturan` SET `nilai` = 'true', `updated_at` = NOW() WHERE `kunci` = 'video_aktif'")->execute();

                    // Catat ke audit_log
                    $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `meta`, `created_at`)
                                   VALUES ('admin', :uid, 'upload_video', 'media', :meta, NOW())")->execute([
                        'uid' => $admin['id'],
                        'meta' => json_encode(['file' => $origName, 'size' => $size, 'durasi' => $durasiDetik])
                    ]);

                    $message = 'Video profil kampus berhasil diunggah dan langsung aktif di layar TV!';
                } else {
                    $error = 'Gagal memindahkan berkas video ke folder penyimpanan.';
                }
            }
        }
    } elseif ($action === 'toggle_video') {
        $mediaId = (int)($_POST['media_id'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);

        if ($status === 1) {
            $pdo->prepare("UPDATE `media` SET `aktif` = 0 WHERE `koleksi` = 'video_profil'")->execute();
            $pdo->prepare("UPDATE `media` SET `aktif` = 1 WHERE `id` = :id")->execute(['id' => $mediaId]);
            $pdo->prepare("UPDATE `pengaturan` SET `nilai` = 'true' WHERE `kunci` = 'video_aktif'")->execute();
            $message = 'Video profil telah diaktifkan di layar TV.';
        } else {
            $pdo->prepare("UPDATE `media` SET `aktif` = 0 WHERE `id` = :id")->execute(['id' => $mediaId]);
            $pdo->prepare("UPDATE `pengaturan` SET `nilai` = 'false' WHERE `kunci` = 'video_aktif'")->execute();
            $message = 'Video profil telah dinonaktifkan dari putaran slide TV.';
        }
    }
}

// Ambil riwayat video profil
$videos = $pdo->query("SELECT m.*, u.name AS uploader FROM `media` m LEFT JOIN `users` u ON u.id = m.diunggah_oleh WHERE m.koleksi = 'video_profil' ORDER BY m.id DESC")->fetchAll();
$activeVideo = $pdo->query("SELECT * FROM `media` WHERE `koleksi` = 'video_profil' AND `aktif` = 1 LIMIT 1")->fetch();
?>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
    <!-- Form Unggah Video -->
    <div class="card">
        <h3>🎬 Unggah Video Profil Kampus (MP4)</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px;line-height:1.4">
            Video akan tayang otomatis pada slide tengah layar TV. Rekomendasi format: 1080p, H.264 + AAC, ukuran maksimal 200 MB.
        </p>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_video">

            <div class="form-group">
                <label>Pilih Berkas Video (MP4)</label>
                <input type="file" name="video_file" accept="video/mp4" required style="padding:8px">
            </div>

            <div class="form-group">
                <label>Durasi Putar Maksimal (Detik)</label>
                <input type="number" name="durasi_detik" value="180" min="10" max="600" required>
                <small style="color:var(--muted);font-size:12px">Default 180 detik (3 menit). Layar TV akan otomatis berpindah slide begitu video selesai diputar.</small>
            </div>

            <button type="submit" class="btn" style="padding:10px 20px">📤 Unggah & Terapkan ke TV</button>
        </form>
    </div>

    <!-- Pratinjau Video Aktif -->
    <div class="card">
        <h3>📺 Pratinjau Video Aktif di Layar TV</h3>
        <?php if ($activeVideo): ?>
            <div style="background:#000;border-radius:12px;overflow:hidden;margin-bottom:12px">
                <video src="../storage/<?= htmlspecialchars($activeVideo['path']) ?>" controls style="width:100%;max-height:220px;display:block"></video>
            </div>
            <div style="font-size:13px;color:var(--ink)">
                <b>Status:</b> <span class="badge ok">Aktif Tayang di TV</span><br>
                <b>Durasi:</b> <?= $activeVideo['durasi_detik'] ?> detik · <b>Ukuran:</b> <?= round($activeVideo['ukuran_byte'] / 1024 / 1024, 2) ?> MB<br>
                <small style="color:var(--muted)">Lokasi: <code>storage/<?= htmlspecialchars($activeVideo['path']) ?></code></small>
            </div>
        <?php else: ?>
            <div style="padding:40px 20px;text-align:center;background:#f3f4f6;border-radius:12px;color:var(--muted)">
                Saat ini belum ada video profil yang aktif di layar TV. Slide video akan dilewati secara otomatis.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:24px">
    <h3>📁 Riwayat Berkas Media Video</h3>
    <?php if (empty($videos)): ?>
        <p style="font-size:13px;color:var(--muted)">Belum ada berkas video profil yang diunggah.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Nama Berkas</th>
                    <th>Ukuran</th>
                    <th>Durasi</th>
                    <th>Diunggah Oleh</th>
                    <th>Status Tayang</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($videos as $v): ?>
                <tr>
                    <td><b><?= htmlspecialchars(basename($v['path'])) ?></b></td>
                    <td><?= round($v['ukuran_byte'] / 1024 / 1024, 2) ?> MB</td>
                    <td><?= $v['durasi_detik'] ?> dtk</td>
                    <td><?= htmlspecialchars($v['uploader'] ?? 'Admin') ?></td>
                    <td>
                        <span class="badge <?= $v['aktif'] ? 'ok' : 'off' ?>">
                            <?= $v['aktif'] ? '● Tayang di TV' : 'Nonaktif' ?>
                        </span>
                    </td>
                    <td>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle_video">
                            <input type="hidden" name="media_id" value="<?= $v['id'] ?>">
                            <input type="hidden" name="status" value="<?= $v['aktif'] ? 0 : 1 ?>">
                            <button type="submit" class="btn <?= $v['aktif'] ? 'btn-secondary' : '' ?>" style="padding:4px 8px;font-size:11px">
                                <?= $v['aktif'] ? 'Sembunyikan dari TV' : 'Aktifkan di TV' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

