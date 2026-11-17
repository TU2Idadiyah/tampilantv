<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

$message = '';
$error = '';
$today = date('Y-m-d');

// Handler CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah_agenda') {
        $judul = trim($_POST['judul'] ?? '');
        $lokasi = trim($_POST['lokasi'] ?? '');
        $mulaiAt = trim($_POST['mulai_at'] ?? '');
        $selesaiAt = trim($_POST['selesai_at'] ?? '');
        $tampilDari = trim($_POST['tampil_dari'] ?? $today);
        $tampilSampai = trim($_POST['tampil_sampai'] ?? substr($mulaiAt, 0, 10));

        if (empty($judul) || empty($lokasi) || empty($mulaiAt)) {
            $error = 'Judul, lokasi, dan waktu mulai agenda wajib diisi.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO `agenda`
                (`judul`, `lokasi`, `mulai_at`, `selesai_at`, `tampil_dari`, `tampil_sampai`, `aktif`, `created_at`, `updated_at`)
                VALUES (:judul, :lokasi, :mulai, :selesai, :dari, :sampai, 1, NOW(), NOW())");
            $stmt->execute([
                'judul' => $judul,
                'lokasi' => $lokasi,
                'mulai' => str_replace('T', ' ', $mulaiAt),
                'selesai' => !empty($selesaiAt) ? str_replace('T', ' ', $selesaiAt) : null,
                'dari' => $tampilDari,
                'sampai' => $tampilSampai
            ]);

            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                           VALUES ('admin', :uid, 'create_agenda', 'agenda', :aid, :meta, NOW())")->execute([
                'uid' => $admin['id'],
                'aid' => $pdo->lastInsertId(),
                'meta' => json_encode(['judul' => $judul])
            ]);

            $message = "Agenda '{$judul}' berhasil ditambahkan!";
        }
    } elseif ($action === 'toggle_agenda') {
        $id = (int)($_POST['agenda_id'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);
        $pdo->prepare("UPDATE `agenda` SET `aktif` = :status, `updated_at` = NOW() WHERE `id` = :id")->execute([
            'status' => $status,
            'id' => $id
        ]);
        $message = "Status agenda berhasil diubah.";
    } elseif ($action === 'hapus_agenda') {
        $id = (int)($_POST['agenda_id'] ?? 0);
        $pdo->prepare("DELETE FROM `agenda` WHERE `id` = :id")->execute(['id' => $id]);
        $message = "Agenda berhasil dihapus.";
    }
}

// Ambil seluruh agenda
$agendas = $pdo->query("SELECT * FROM `agenda` ORDER BY `mulai_at` DESC")->fetchAll();
$activeTvCount = 0;
foreach ($agendas as $a) {
    if ($a['aktif'] && $today >= $a['tampil_dari'] && $today <= $a['tampil_sampai']) {
        $activeTvCount++;
    }
}
?>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card" style="background:var(--g-soft);border-color:var(--g);margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h3 style="margin-bottom:4px;color:var(--g-dark)">📅 Status Slide Agenda di Layar TV</h3>
            <p style="font-size:13px;color:var(--ink)">
                Saat ini terdapat <b><?= $activeTvCount ?> agenda/event</b> yang sedang aktif tampil di layar TV.
            </p>
        </div>
        <div>
            <?php if ($activeTvCount > 0): ?>
                <span class="badge ok" style="font-size:14px;padding:6px 14px">● Slide Agenda Aktif Tayang</span>
            <?php else: ?>
                <span class="badge err" style="font-size:14px;padding:6px 14px">○ Slide Agenda Dilewati (Otomatis Sembunyi)</span>
            <?php endif; ?>
        </div>
    </div>
    <small style="color:var(--muted);display:block;margin-top:8px">
        * Catatan: Jika tidak ada agenda yang berstatus aktif dan dalam rentang tanggal tampil hari ini, slide agenda di TV akan dilewati secara otomatis sehingga tidak menampilkan halaman kosong.
    </small>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <!-- Tabel Daftar Agenda -->
    <div class="card">
        <h3>📋 Daftar Agenda & Event Kampus</h3>
        <?php if (empty($agendas)): ?>
            <p style="font-size:14px;color:var(--muted);padding:20px 0;text-align:center">Belum ada agenda kegiatan yang dibuat.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Kegiatan & Lokasi</th>
                        <th>Waktu Kegiatan</th>
                        <th>Jendela Tayang TV</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agendas as $a): ?>
                    <?php
                        $isCurrentlyLive = $a['aktif'] && ($today >= $a['tampil_dari'] && $today <= $a['tampil_sampai']);
                        $isExpired = $today > $a['tampil_sampai'];
                    ?>
                    <tr>
                        <td>
                            <b><?= htmlspecialchars($a['judul']) ?></b><br>
                            <small style="color:var(--muted)">📍 <?= htmlspecialchars($a['lokasi']) ?></small>
                        </td>
                        <td>
                            <small>
                                <b>Mulai:</b> <?= date('d M Y, H:i', strtotime($a['mulai_at'])) ?> WIB<br>
                                <?php if ($a['selesai_at']): ?>
                                    <b>Selesai:</b> <?= date('d M Y, H:i', strtotime($a['selesai_at'])) ?> WIB
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <small>
                                <?= date('d M Y', strtotime($a['tampil_dari'])) ?> s/d<br>
                                <b><?= date('d M Y', strtotime($a['tampil_sampai'])) ?></b>
                            </small>
                        </td>
                        <td>
                            <?php if (!$a['aktif']): ?>
                                <span class="badge off">Nonaktif</span>
                            <?php elseif ($isCurrentlyLive): ?>
                                <span class="badge ok">● Tayang di TV</span>
                            <?php elseif ($isExpired): ?>
                                <span class="badge off">Lewat Jadwal</span>
                            <?php else: ?>
                                <span class="badge off">Mendatang</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px">
                                <form method="POST" style="margin:0">
                                    <input type="hidden" name="action" value="toggle_agenda">
                                    <input type="hidden" name="agenda_id" value="<?= $a['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $a['aktif'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn <?= $a['aktif'] ? 'btn-secondary' : '' ?>" style="padding:4px 8px;font-size:11px">
                                        <?= $a['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                                <form method="POST" style="margin:0" onsubmit="return confirm('Hapus agenda ini?')">
                                    <input type="hidden" name="action" value="hapus_agenda">
                                    <input type="hidden" name="agenda_id" value="<?= $a['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Form Tambah Agenda Baru -->
    <div class="card">
        <h3>➕ Tambah Agenda / Event Baru</h3>
        <form method="POST">
            <input type="hidden" name="action" value="tambah_agenda">

            <div class="form-group">
                <label>Nama / Judul Kegiatan</label>
                <input type="text" name="judul" placeholder="Contoh: Kuliah Umum Ekonomi Islam" required>
            </div>

            <div class="form-group">
                <label>Tempat / Lokasi</label>
                <input type="text" name="lokasi" placeholder="Contoh: Aula Lt. 3 STAI Sidogiri" required>
            </div>

            <div class="form-group">
                <label>Waktu Mulai Acara</label>
                <input type="datetime-local" name="mulai_at" required>
            </div>

            <div class="form-group">
                <label>Waktu Selesai Acara (Opsional)</label>
                <input type="datetime-local" name="selesai_at">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="form-group">
                    <label>Tampil di TV Dari</label>
                    <input type="date" name="tampil_dari" value="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label>Tampil Sampai</label>
                    <input type="date" name="tampil_sampai" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                </div>
            </div>

            <button type="submit" class="btn" style="width:100%;margin-top:8px">Simpan & Tayangkan Agenda</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

