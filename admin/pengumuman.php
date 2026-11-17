<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

$message = '';
$error = '';
$today = date('Y-m-d');

// Handler CRUD Pengumuman
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah_pengumuman') {
        $teks = trim($_POST['teks'] ?? '');
        $urutan = (int)($_POST['urutan'] ?? 1);
        $tampilDari = trim($_POST['tampil_dari'] ?? $today);
        $tampilSampai = trim($_POST['tampil_sampai'] ?? date('Y-12-31'));

        if (empty($teks)) {
            $error = 'Teks pengumuman tidak boleh kosong.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO `pengumuman`
                (`teks`, `urutan`, `tampil_dari`, `tampil_sampai`, `aktif`, `created_at`, `updated_at`)
                VALUES (:teks, :urutan, :dari, :sampai, 1, NOW(), NOW())");
            $stmt->execute([
                'teks' => $teks,
                'urutan' => $urutan,
                'dari' => $tampilDari,
                'sampai' => $tampilSampai
            ]);

            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                           VALUES ('admin', :uid, 'create_pengumuman', 'pengumuman', :pid, :meta, NOW())")->execute([
                'uid' => $admin['id'],
                'pid' => $pdo->lastInsertId(),
                'meta' => json_encode(['teks' => substr($teks, 0, 50)])
            ]);

            $message = "Teks pengumuman berhasil ditambahkan dan akan langsung tampil di running text TV!";
        }
    } elseif ($action === 'toggle_pengumuman') {
        $id = (int)($_POST['pengumuman_id'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);
        $pdo->prepare("UPDATE `pengumuman` SET `aktif` = :status, `updated_at` = NOW() WHERE `id` = :id")->execute([
            'status' => $status,
            'id' => $id
        ]);
        $message = "Status tayang pengumuman berhasil diubah.";
    } elseif ($action === 'hapus_pengumuman') {
        $id = (int)($_POST['pengumuman_id'] ?? 0);
        $pdo->prepare("DELETE FROM `pengumuman` WHERE `id` = :id")->execute(['id' => $id]);
        $message = "Pengumuman berhasil dihapus.";
    }
}

// Ambil seluruh pengumuman
$pengumumanList = $pdo->query("SELECT * FROM `pengumuman` ORDER BY `urutan` ASC, `id` DESC")->fetchAll();
$activeTickerTexts = [];
foreach ($pengumumanList as $p) {
    if ($p['aktif'] && $today >= $p['tampil_dari'] && $today <= $p['tampil_sampai']) {
        $activeTickerTexts[] = $p['teks'];
    }
}
?>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Pratinjau Running Text Ticker -->
<div class="card" style="padding:16px 20px;background:#009B4C;color:#fff;border-radius:14px;margin-bottom:20px;overflow:hidden">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:0.85;margin-bottom:6px">
        📢 Pratinjau Teks Berjalan di Bawah Layar TV:
    </div>
    <div style="background:#006b34;padding:10px 16px;border-radius:8px;font-size:16px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
        <?php if (empty($activeTickerTexts)): ?>
            <span style="opacity:0.8">Selamat Datang di STAI Sidogiri Pasuruan (Teks Default)</span>
        <?php else: ?>
            <span><?= implode(' &nbsp;◆&nbsp; ', array_map('htmlspecialchars', $activeTickerTexts)) ?></span>
        <?php endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <!-- Tabel Pengumuman -->
    <div class="card">
        <h3>📢 Daftar Pengumuman Running Text</h3>
        <?php if (empty($pengumumanList)): ?>
            <p style="font-size:14px;color:var(--muted);padding:20px 0;text-align:center">Belum ada pengumuman yang dibuat.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width:60px">Urutan</th>
                        <th>Isi Teks Pengumuman</th>
                        <th>Periode Tayang</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pengumumanList as $p): ?>
                    <?php
                        $isLive = $p['aktif'] && ($today >= $p['tampil_dari'] && $today <= $p['tampil_sampai']);
                    ?>
                    <tr>
                        <td><b>#<?= $p['urutan'] ?></b></td>
                        <td>
                            <p style="font-size:14px;line-height:1.4"><?= htmlspecialchars($p['teks']) ?></p>
                        </td>
                        <td>
                            <small>
                                <?= date('d M Y', strtotime($p['tampil_dari'])) ?> s/d<br>
                                <b><?= date('d M Y', strtotime($p['tampil_sampai'])) ?></b>
                            </small>
                        </td>
                        <td>
                            <?php if (!$p['aktif']): ?>
                                <span class="badge off">Nonaktif</span>
                            <?php elseif ($isLive): ?>
                                <span class="badge ok">● Aktif di TV</span>
                            <?php else: ?>
                                <span class="badge off">Lewat Jadwal</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px">
                                <form method="POST" style="margin:0">
                                    <input type="hidden" name="action" value="toggle_pengumuman">
                                    <input type="hidden" name="pengumuman_id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $p['aktif'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn <?= $p['aktif'] ? 'btn-secondary' : '' ?>" style="padding:4px 8px;font-size:11px">
                                        <?= $p['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                                <form method="POST" style="margin:0" onsubmit="return confirm('Hapus pengumuman ini?')">
                                    <input type="hidden" name="action" value="hapus_pengumuman">
                                    <input type="hidden" name="pengumuman_id" value="<?= $p['id'] ?>">
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

    <!-- Form Tambah Pengumuman Baru -->
    <div class="card">
        <h3>➕ Tambah Pengumuman Baru</h3>
        <form method="POST">
            <input type="hidden" name="action" value="tambah_pengumuman">

            <div class="form-group">
                <label>Isi Teks Pengumuman</label>
                <textarea name="teks" rows="4" placeholder="Tuliskan pengumuman yang akan berjalan di bagian bawah layar TV..." required></textarea>
            </div>

            <div class="form-group">
                <label>Nomor Urutan Tampil</label>
                <input type="number" name="urutan" value="<?= count($pengumumanList) + 1 ?>" min="1" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="form-group">
                    <label>Mulai Tayang Dari</label>
                    <input type="date" name="tampil_dari" value="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label>Tayang Sampai</label>
                    <input type="date" name="tampil_sampai" value="<?= date('Y-12-31') ?>" required>
                </div>
            </div>

            <button type="submit" class="btn" style="width:100%;margin-top:8px">Simpan & Tampilkan di TV</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

