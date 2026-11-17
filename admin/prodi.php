<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

$message = '';
$error = '';

// Handler CRUD Program Studi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah_prodi') {
        $kode = strtoupper(trim($_POST['kode'] ?? ''));
        $nama = trim($_POST['nama'] ?? '');
        $urutan = (int)($_POST['urutan'] ?? 0);
        $aktif = isset($_POST['aktif']) ? 1 : 0;

        if (empty($kode) || empty($nama)) {
            $error = 'Kode Prodi dan Nama Program Studi wajib diisi!';
        } elseif (!preg_match('/^[A-Z0-9_-]{2,20}$/', $kode)) {
            $error = 'Kode Prodi hanya boleh berisi huruf, angka, garis bawah, atau strip (2-20 karakter). Contoh: PAI, HES, TI, PGMI.';
        } else {
            if ($urutan <= 0) {
                $maxUrutan = (int)$pdo->query("SELECT COALESCE(MAX(`urutan`), 0) FROM `prodi`")->fetchColumn();
                $urutan = $maxUrutan + 1;
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO `prodi` (`kode`, `nama`, `urutan`, `aktif`, `created_at`, `updated_at`)
                                       VALUES (:kode, :nama, :urutan, :aktif, NOW(), NOW())");
                $stmt->execute([
                    'kode' => $kode,
                    'nama' => $nama,
                    'urutan' => $urutan,
                    'aktif' => $aktif
                ]);

                $prodiId = (int)$pdo->lastInsertId();

                // Audit log
                $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                               VALUES ('admin', :uid, 'create', 'prodi', :pid, :meta, NOW())")->execute([
                    'uid' => $admin['id'],
                    'pid' => $prodiId,
                    'meta' => json_encode(['kode' => $kode, 'nama' => $nama])
                ]);

                $message = "Program Studi '{$nama}' ({$kode}) berhasil ditambahkan!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Kode Prodi '{$kode}' sudah terdaftar! Gunakan kode lain.";
                } else {
                    $error = "Gagal menyimpan prodi: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'edit_prodi') {
        $prodiId = (int)($_POST['prodi_id'] ?? 0);
        $kode = strtoupper(trim($_POST['kode'] ?? ''));
        $nama = trim($_POST['nama'] ?? '');
        $urutan = (int)($_POST['urutan'] ?? 0);
        $aktif = isset($_POST['aktif']) ? 1 : 0;

        if ($prodiId <= 0 || empty($kode) || empty($nama)) {
            $error = 'ID, Kode Prodi, dan Nama Program Studi wajib diisi!';
        } elseif (!preg_match('/^[A-Z0-9_-]{2,20}$/', $kode)) {
            $error = 'Kode Prodi hanya boleh berisi huruf, angka, garis bawah, atau strip (2-20 karakter). Contoh: PAI, HES, TI, PGMI.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE `prodi` SET `kode` = :kode, `nama` = :nama, `urutan` = :urutan, `aktif` = :aktif, `updated_at` = NOW() WHERE `id` = :id");
                $stmt->execute([
                    'kode' => $kode,
                    'nama' => $nama,
                    'urutan' => $urutan,
                    'aktif' => $aktif,
                    'id' => $prodiId
                ]);

                // Audit log
                $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                               VALUES ('admin', :uid, 'update', 'prodi', :pid, :meta, NOW())")->execute([
                    'uid' => $admin['id'],
                    'pid' => $prodiId,
                    'meta' => json_encode(['kode' => $kode, 'nama' => $nama, 'aktif' => $aktif])
                ]);

                $message = "Program Studi '{$nama}' ({$kode}) berhasil diperbarui!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Kode Prodi '{$kode}' sudah digunakan oleh prodi lain!";
                } else {
                    $error = "Gagal memperbarui prodi: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'toggle_prodi') {
        $prodiId = (int)($_POST['prodi_id'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);

        if ($prodiId > 0) {
            $pdo->prepare("UPDATE `prodi` SET `aktif` = :status, `updated_at` = NOW() WHERE `id` = :id")->execute([
                'status' => $status,
                'id' => $prodiId
            ]);

            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                           VALUES ('admin', :uid, 'toggle', 'prodi', :pid, :meta, NOW())")->execute([
                'uid' => $admin['id'],
                'pid' => $prodiId,
                'meta' => json_encode(['aktif' => $status])
            ]);

            $statusText = $status === 1 ? 'diaktifkan' : 'dinonaktifkan';
            $message = "Status Program Studi berhasil {$statusText}.";
        }
    } elseif ($action === 'hapus_prodi') {
        $prodiId = (int)($_POST['prodi_id'] ?? 0);

        if ($prodiId > 0) {
            $prodi = $pdo->prepare("SELECT * FROM `prodi` WHERE `id` = :id");
            $prodi->execute(['id' => $prodiId]);
            $rowProdi = $prodi->fetch();

            if (!$rowProdi) {
                $error = 'Program Studi tidak ditemukan!';
            } else {
                // Cek relasi ke jadwal_kuliah & statistik_mahasiswa
                $cntJadwal = (int)$pdo->prepare("SELECT COUNT(*) FROM `jadwal_kuliah` WHERE `prodi_id` = :id");
                $cntJadwal = (int)$pdo->query("SELECT COUNT(*) FROM `jadwal_kuliah` WHERE `prodi_id` = {$prodiId}")->fetchColumn();
                $cntMhs = (int)$pdo->query("SELECT COUNT(*) FROM `statistik_mahasiswa` WHERE `prodi_id` = {$prodiId}")->fetchColumn();

                if ($cntJadwal > 0 || $cntMhs > 0) {
                    $error = "Program Studi '{$rowProdi['nama']}' ({$rowProdi['kode']}) tidak dapat dihapus karena masih digunakan oleh {$cntJadwal} data jadwal kuliah dan {$cntMhs} data statistik mahasiswa. Silakan nonaktifkan statusnya jika ingin menyembunyikannya dari layar TV.";
                } else {
                    $pdo->prepare("DELETE FROM `prodi` WHERE `id` = :id")->execute(['id' => $prodiId]);

                    $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                                   VALUES ('admin', :uid, 'delete', 'prodi', :pid, :meta, NOW())")->execute([
                        'uid' => $admin['id'],
                        'pid' => $prodiId,
                        'meta' => json_encode(['kode' => $rowProdi['kode'], 'nama' => $rowProdi['nama']])
                    ]);

                    $message = "Program Studi '{$rowProdi['nama']}' ({$rowProdi['kode']}) berhasil dihapus!";
                }
            }
        }
    }
}

// Ambil semua daftar prodi beserta statistik keterkaitannya
$sqlProdi = "SELECT p.*,
                    (SELECT COUNT(*) FROM `jadwal_kuliah` WHERE `prodi_id` = p.id) AS total_jadwal,
                    (SELECT COUNT(*) FROM `statistik_mahasiswa` WHERE `prodi_id` = p.id) AS total_mhs_baris,
                    (SELECT COALESCE(SUM(`jumlah`), 0) FROM `statistik_mahasiswa` sm 
                     JOIN `periode_akademik` pa ON pa.id = sm.periode_id AND pa.aktif = 1 
                     WHERE sm.prodi_id = p.id) AS total_populasi
             FROM `prodi` p
             ORDER BY p.urutan ASC, p.id ASC";
$prodiList = $pdo->query($sqlProdi)->fetchAll();

$nextUrutan = (int)$pdo->query("SELECT COALESCE(MAX(`urutan`), 0) FROM `prodi`")->fetchColumn() + 1;
$totalProdiAktif = count(array_filter($prodiList, fn($p) => (int)$p['aktif'] === 1));
?>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <!-- Kolom Kiri: Tabel Daftar Program Studi -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
            <div>
                <h3>🎓 Master Program Studi Kampus</h3>
                <small style="color:var(--muted);font-weight:600">
                    Total: <?= count($prodiList) ?> Program Studi (<?= $totalProdiAktif ?> Aktif di Layar TV)
                </small>
            </div>
            <a href="import.php" class="btn btn-secondary" style="font-size:12px;padding:6px 12px">
                📥 Impor Jadwal / Data Mahasiswa &rarr;
            </a>
        </div>

        <?php if (empty($prodiList)): ?>
            <div style="text-align:center;padding:40px 20px;color:var(--muted)">
                <div style="font-size:40px;margin-bottom:12px">🎓</div>
                <b>Belum ada data Program Studi.</b>
                <p style="font-size:13px;margin-top:4px">Gunakan formulir di sebelah kanan untuk menambahkan program studi yang ada di kampus Anda.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th style="width:60px">Urutan</th>
                            <th style="width:100px">Kode</th>
                            <th>Nama Program Studi</th>
                            <th style="width:140px">Data Terkait</th>
                            <th style="width:90px">Status</th>
                            <th style="width:160px;text-align:center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prodiList as $p): ?>
                            <tr>
                                <td>
                                    <span style="font-weight:800;color:var(--muted);font-size:13px">#<?= (int)$p['urutan'] ?></span>
                                </td>
                                <td>
                                    <span class="badge ok" style="font-size:12px;font-family:monospace;letter-spacing:1px;font-weight:800">
                                        <?= htmlspecialchars($p['kode']) ?>
                                    </span>
                                </td>
                                <td>
                                    <b><?= htmlspecialchars($p['nama']) ?></b>
                                </td>
                                <td>
                                    <div style="font-size:12px;color:var(--muted);line-height:1.4">
                                        <div>📅 <b><?= (int)$p['total_jadwal'] ?></b> kelas kuliah</div>
                                        <div>👥 <b><?= number_format((int)$p['total_populasi'], 0, ',', '.') ?></b> mhs aktif</div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ((int)$p['aktif'] === 1): ?>
                                        <span class="badge ok">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge off">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center">
                                    <div style="display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap">
                                        <button type="button" 
                                                class="btn btn-secondary" 
                                                style="padding:5px 10px;font-size:12px" 
                                                onclick='openEditProdi(<?= json_encode($p) ?>)'>
                                            ✏️ Edit
                                        </button>

                                        <form method="POST" style="display:inline" onsubmit="return confirm('Apakah Anda yakin ingin mengubah status aktif/nonaktif prodi <?= htmlspecialchars($p['kode']) ?>?')">
                                            <input type="hidden" name="action" value="toggle_prodi">
                                            <input type="hidden" name="prodi_id" value="<?= $p['id'] ?>">
                                            <input type="hidden" name="status" value="<?= (int)$p['aktif'] === 1 ? 0 : 1 ?>">
                                            <button type="submit" 
                                                    class="btn <?= (int)$p['aktif'] === 1 ? 'btn-secondary' : '' ?>" 
                                                    style="padding:5px 8px;font-size:12px" 
                                                    title="<?= (int)$p['aktif'] === 1 ? 'Nonaktifkan dari Layar' : 'Aktifkan di Layar' ?>">
                                                <?= (int)$p['aktif'] === 1 ? 'Matikan' : 'Aktifkan' ?>
                                            </button>
                                        </form>

                                        <form method="POST" style="display:inline" onsubmit="return confirm('Yakin ingin menghapus prodi <?= htmlspecialchars($p['kode']) ?>? Tindakan ini tidak dapat dibatalkan.')">
                                            <input type="hidden" name="action" value="hapus_prodi">
                                            <input type="hidden" name="prodi_id" value="<?= $p['id'] ?>">
                                            <button type="submit" 
                                                    class="btn btn-danger" 
                                                    style="padding:5px 8px;font-size:12px" 
                                                    title="Hapus Prodi"
                                                    <?= ((int)$p['total_jadwal'] > 0 || (int)$p['total_mhs_baris'] > 0) ? 'onclick="return confirm(\'Perhatian: Prodi ini memiliki keterkaitan data. Penghapusan akan ditolak jika masih ada jadwal kuliah atau data mahasiswa. Lanjutkan pengecekan?\')"' : '' ?>>
                                                🗑️
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

    <!-- Kolom Kanan: Form Tambah / Edit Prodi -->
    <div>
        <!-- Form Tambah Prodi Baru -->
        <div class="card" id="boxTambahProdi">
            <h3>➕ Tambah Program Studi</h3>
            <p style="font-size:12px;color:var(--muted);margin-bottom:16px">
                Daftarkan program studi sesuai fakultas dan prodi yang berlaku di kampus Anda.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="tambah_prodi">

                <div class="form-group">
                    <label>Kode Prodi (Singkatan)</label>
                    <input type="text" 
                           name="kode" 
                           required 
                           maxlength="20" 
                           placeholder="Contoh: PAI, HES, TI, PGMI, ES"
                           style="text-transform:uppercase"
                           oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '')">
                    <small style="font-size:11px;color:var(--muted)">Kode ini digunakan pada kolom <code>kode_prodi</code> file CSV impor.</small>
                </div>

                <div class="form-group">
                    <label>Nama Lengkap Program Studi</label>
                    <input type="text" 
                           name="nama" 
                           required 
                           maxlength="100" 
                           placeholder="Contoh: Pendidikan Agama Islam">
                </div>

                <div class="form-group">
                    <label>Urutan Tampil di Layar TV</label>
                    <input type="number" 
                           name="urutan" 
                           value="<?= $nextUrutan ?>" 
                           min="1" 
                           required>
                    <small style="font-size:11px;color:var(--muted)">Angka lebih kecil tampil lebih awal pada tabel dan grafik.</small>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="aktif" value="1" checked style="width:18px;height:18px">
                        <span>Aktifkan & Tampilkan di Layar TV</span>
                    </label>
                </div>

                <button type="submit" class="btn" style="width:100%;padding:10px;font-size:14px">
                    💾 Simpan Program Studi
                </button>
            </form>
        </div>

        <!-- Form Edit Prodi (Muncul saat tombol Edit diklik) -->
        <div class="card" id="boxEditProdi" style="display:none;border-color:var(--g)">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <h3>✏️ Edit Program Studi</h3>
                <button type="button" 
                        class="btn btn-secondary" 
                        style="padding:4px 8px;font-size:11px" 
                        onclick="closeEditProdi()">
                    ✕ Batal
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_prodi">
                <input type="hidden" name="prodi_id" id="editProdiId" value="">

                <div class="form-group">
                    <label>Kode Prodi (Singkatan)</label>
                    <input type="text" 
                           name="kode" 
                           id="editProdiKode" 
                           required 
                           maxlength="20" 
                           style="text-transform:uppercase"
                           oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '')">
                    <small style="font-size:11px;color:var(--muted)">Perhatian: Mengubah kode prodi harus disesuaikan dengan kode di file CSV baru.</small>
                </div>

                <div class="form-group">
                    <label>Nama Lengkap Program Studi</label>
                    <input type="text" 
                           name="nama" 
                           id="editProdiNama" 
                           required 
                           maxlength="100">
                </div>

                <div class="form-group">
                    <label>Urutan Tampil di Layar TV</label>
                    <input type="number" 
                           name="urutan" 
                           id="editProdiUrutan" 
                           min="1" 
                           required>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="aktif" id="editProdiAktif" value="1" style="width:18px;height:18px">
                        <span>Aktifkan & Tampilkan di Layar TV</span>
                    </label>
                </div>

                <button type="submit" class="btn" style="width:100%;padding:10px;font-size:14px;margin-bottom:8px">
                    💾 Simpan Perubahan
                </button>
                <button type="button" class="btn btn-secondary" style="width:100%;padding:8px" onclick="closeEditProdi()">
                    Batal
                </button>
            </form>
        </div>

        <!-- Panduan Integrasi -->
        <div class="card" style="margin-top:20px;background:var(--bg)">
            <h4 style="font-size:14px;margin-bottom:8px;color:var(--g-dark)">💡 Tips Pengaturan Prodi</h4>
            <ul style="font-size:12px;color:var(--muted);padding-left:18px;line-height:1.6">
                <li>Anda bebas menyesuaikan nama dan kode prodi sesuai struktur kampus Anda.</li>
                <li>Gunakan <b>Kode Prodi</b> yang sama persis saat mengisi berkas CSV impor jadwal kuliah dan jumlah mahasiswa.</li>
                <li>Jika kampus memiliki prodi lama yang sudah tidak aktif namun datanya ingin diarsipkan, Anda cukup mengubah statusnya menjadi <b>Nonaktif</b>.</li>
                <li>Tabel statistik mahasiswa di layar TV akan secara dinamis menyusun baris tabel sesuai urutan prodi yang aktif.</li>
            </ul>
        </div>
    </div>
</div>

<script>
function openEditProdi(p) {
    document.getElementById('editProdiId').value = p.id;
    document.getElementById('editProdiKode').value = p.kode;
    document.getElementById('editProdiNama').value = p.nama;
    document.getElementById('editProdiUrutan').value = p.urutan;
    document.getElementById('editProdiAktif').checked = (parseInt(p.aktif) === 1);

    document.getElementById('boxTambahProdi').style.display = 'none';
    const boxEdit = document.getElementById('boxEditProdi');
    boxEdit.style.display = 'block';
    boxEdit.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeEditProdi() {
    document.getElementById('boxEditProdi').style.display = 'none';
    document.getElementById('boxTambahProdi').style.display = 'block';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

