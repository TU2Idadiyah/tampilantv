<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

$message = '';
$error = '';

// Helper upload foto dosen
function handleUploadFotoDosen(?array $file): ?string {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMimes)) {
        return null;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'dosen_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
    $targetDir = dirname(__DIR__) . '/storage/dosen';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $targetPath = $targetDir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'dosen/' . $filename;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah_dosen') {
        $kodeNidn = trim($_POST['kode_nidn'] ?? '');
        $nama = trim($_POST['nama'] ?? '');
        $aktif = isset($_POST['aktif']) ? 1 : 0;

        if (empty($kodeNidn) || empty($nama)) {
            $error = 'NIDN/Kode Dosen dan Nama Lengkap wajib diisi!';
        } else {
            $fotoPath = handleUploadFotoDosen($_FILES['foto'] ?? null);

            $stmt = $pdo->prepare("INSERT INTO `dosen` (`kode_nidn`, `nama`, `foto_path`, `aktif`, `created_at`, `updated_at`)
                                   VALUES (:nidn, :nama, :foto, :aktif, NOW(), NOW())");
            try {
                $stmt->execute([
                    'nidn' => $kodeNidn,
                    'nama' => $nama,
                    'foto' => $fotoPath,
                    'aktif' => $aktif
                ]);

                $dosenId = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `created_at`)
                               VALUES ('admin', :uid, 'create', 'dosen', :did, NOW())")->execute([
                    'uid' => $admin['id'],
                    'did' => $dosenId
                ]);

                $message = "Dosen baru '{$nama}' ({$kodeNidn}) berhasil ditambahkan!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "NIDN / Kode Dosen '{$kodeNidn}' sudah terdaftar!";
                } else {
                    $error = "Gagal menyimpan data dosen: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'edit_dosen') {
        $dId = (int)($_POST['dosen_id'] ?? 0);
        $kodeNidn = trim($_POST['kode_nidn'] ?? '');
        $nama = trim($_POST['nama'] ?? '');
        $noHp = trim($_POST['no_hp'] ?? '');
        $pin = trim($_POST['pin'] ?? '');
        $aktif = isset($_POST['aktif']) ? 1 : 0;
        $hapusFoto = isset($_POST['hapus_foto']);

        if ($dId > 0 && !empty($kodeNidn) && !empty($nama)) {
            $curDosen = $pdo->query("SELECT * FROM `dosen` WHERE `id` = {$dId}")->fetch();
            $fotoPath = $curDosen['foto_path'] ?? null;

            if ($hapusFoto) {
                if ($fotoPath && file_exists(dirname(__DIR__) . '/storage/' . $fotoPath)) {
                    @unlink(dirname(__DIR__) . '/storage/' . $fotoPath);
                }
                $fotoPath = null;
            }

            $uploadedFoto = handleUploadFotoDosen($_FILES['foto'] ?? null);
            if ($uploadedFoto) {
                if ($fotoPath && file_exists(dirname(__DIR__) . '/storage/' . $fotoPath)) {
                    @unlink(dirname(__DIR__) . '/storage/' . $fotoPath);
                }
                $fotoPath = $uploadedFoto;
            }

            try {
                $stmt = $pdo->prepare("UPDATE `dosen` SET `kode_nidn` = :nidn, `nama` = :nama, `foto_path` = :foto, `no_hp` = :no_hp, `pin` = :pin, `aktif` = :aktif, `updated_at` = NOW() WHERE `id` = :id");
                $stmt->execute([
                    'nidn' => $kodeNidn,
                    'nama' => $nama,
                    'foto' => $fotoPath,
                    'no_hp' => $noHp ?: null,
                    'pin' => $pin ?: null,
                    'aktif' => $aktif,
                    'id' => $dId
                ]);

                $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `created_at`)
                               VALUES ('admin', :uid, 'update', 'dosen', :did, NOW())")->execute([
                    'uid' => $admin['id'],
                    'did' => $dId
                ]);

                $message = "Data dosen '{$nama}' dan foto berhasil diperbarui!";
            } catch (PDOException $e) {
                $error = "Gagal memperbarui dosen: " . $e->getMessage();
            }
        } else {
            $error = "NIDN dan Nama wajib diisi.";
        }
    } elseif ($action === 'koreksi_kehadiran_dosen') {
        $did = (int)($_POST['dosen_id'] ?? 0);
        $jid = (int)($_POST['jadwal_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'hadir');
        $mode = trim($_POST['mode'] ?? 'offline');
        $jamGanti = trim($_POST['jam_ganti'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');
        $todayDate = date('Y-m-d');
        
        try {
            $stmtCek = $pdo->prepare(
                "SELECT `id` FROM `kehadiran_dosen` WHERE `dosen_id` = :did AND `jadwal_id` = :jid AND `tanggal` = :tgl LIMIT 1"
            );
            $stmtUpd = $pdo->prepare(
                "UPDATE `kehadiran_dosen`
                 SET `status` = :status, `mode` = :mode, `jam_ganti` = :jam_ganti, `keterangan` = :ket,
                     `metode` = 'manual', `updated_at` = NOW()
                 WHERE `id` = :id"
            );
            $stmtIns = $pdo->prepare(
                "INSERT INTO `kehadiran_dosen`
                    (`dosen_id`, `jadwal_id`, `tanggal`, `status`, `mode`, `jam_ganti`, `keterangan`, `waktu_absen`, `metode`, `created_at`, `updated_at`)
                 VALUES
                    (:did, :jid, :tgl, :status, :mode, :jam_ganti, :ket, NOW(), 'manual', NOW(), NOW())"
            );

            // Jika spesifik jadwal, update juga fallback jadwal_id = 0
            $targetJids = array_unique([$jid, 0]);
            $modeVal = $status === 'hadir' ? $mode : null;

            foreach ($targetJids as $tJid) {
                $stmtCek->execute(['did' => $did, 'jid' => $tJid, 'tgl' => $todayDate]);
                $existId = $stmtCek->fetchColumn();

                if ($existId) {
                    $stmtUpd->execute([
                        'status' => $status,
                        'mode' => $modeVal,
                        'jam_ganti' => $jamGanti ?: null,
                        'ket' => $keterangan ?: null,
                        'id' => $existId
                    ]);
                } else {
                    $stmtIns->execute([
                        'did' => $did,
                        'jid' => $tJid,
                        'tgl' => $todayDate,
                        'status' => $status,
                        'mode' => $modeVal,
                        'jam_ganti' => $jamGanti ?: null,
                        'ket' => $keterangan ?: null
                    ]);
                }
            }

            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `created_at`)
                           VALUES ('admin', :uid, 'koreksi_kehadiran_dosen', 'kehadiran_dosen', :did, NOW())")
                ->execute(['uid' => $admin['id'], 'did' => $did]);

            $message = "Status kehadiran dosen hari ini berhasil diperbarui secara manual dan langsung tayang di TV!";
        } catch (Exception $e) {
            $error = "Gagal memperbarui kehadiran: " . $e->getMessage();
        }
    } elseif ($action === 'toggle_status') {
        $dId = (int)($_POST['dosen_id'] ?? 0);
        if ($dId > 0) {
            $pdo->prepare("UPDATE `dosen` SET `aktif` = 1 - `aktif`, `updated_at` = NOW() WHERE `id` = :id")->execute(['id' => $dId]);
            $message = "Status aktif dosen ID {$dId} berhasil diubah.";
        }
    } elseif ($action === 'hapus_dosen') {
        $dId = (int)($_POST['dosen_id'] ?? 0);
        if ($dId > 0) {
            $pdo->prepare("UPDATE `dosen` SET `deleted_at` = NOW() WHERE `id` = :id")->execute(['id' => $dId]);
            $message = "Dosen berhasil dihapus (soft delete).";
        }
    }
}

// Ambil seluruh dosen aktif dan jumlah jadwal mengajarnya
$dosenList = $pdo->query("SELECT d.*, COUNT(j.id) AS total_jadwal
                          FROM `dosen` d
                          LEFT JOIN `jadwal_kuliah` j ON j.dosen_id = d.id AND j.aktif = 1
                          WHERE d.deleted_at IS NULL
                          GROUP BY d.id
                          ORDER BY d.nama ASC")->fetchAll();

// Ambil jadwal & status kehadiran dosen hari ini untuk pemantauan layar TV
$todayIsoDay = (int) date('N');
$todayDate = date('Y-m-d');
$namaHariIndo = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$namaHariIni = $namaHariIndo[$todayIsoDay] ?? 'Hari Ini';

$sqlTodayDosen = "SELECT j.id AS jadwal_id, j.jam_mulai, j.jam_selesai, j.mata_kuliah, j.kelas, j.ruang,
                         d.id AS dosen_id, d.nama AS dosen_nama, d.kode_nidn, d.foto_path,
                         pr.nama AS prodi_nama,
                         kd.status AS status_kehadiran, kd.mode AS mode_kuliah, kd.jam_ganti, kd.keterangan, kd.waktu_absen, kd.metode AS metode_absen, kd.updated_at AS waktu_update
                  FROM `jadwal_kuliah` j
                  JOIN `dosen` d ON d.id = j.dosen_id AND d.aktif = 1 AND d.deleted_at IS NULL
                  JOIN `prodi` pr ON pr.id = j.prodi_id AND pr.aktif = 1
                  LEFT JOIN `kehadiran_dosen` kd ON (kd.dosen_id = d.id AND kd.tanggal = :today AND (kd.jadwal_id = j.id OR kd.jadwal_id = 0))
                  WHERE j.hari = :hari AND j.aktif = 1
                  ORDER BY j.jam_mulai ASC";
$stmtTodayDosen = $pdo->prepare($sqlTodayDosen);
$stmtTodayDosen->execute(['hari' => $todayIsoDay, 'today' => $todayDate]);
$todayDosenList = $stmtTodayDosen->fetchAll();
?>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- KARTU STATUS KEHADIRAN DOSEN HARI INI (LAYAR TV) -->
<div class="card" style="border-color:#bae6fd;background:linear-gradient(180deg, #f0f9ff, #ffffff)">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
            <h3 style="color:#0369a1;margin-bottom:4px;display:flex;align-items:center;gap:8px">
                <span>📺 Kehadiran Dosen Hari Ini di Layar TV</span>
                <span class="badge" style="background:#e0f2fe;color:#0284c7;font-weight:800"><?= $namaHariIni ?>, <?= date('d M Y') ?></span>
            </h3>
            <p style="font-size:13px;color:var(--muted)">
                Status kehadiran yang dikirim dosen lewat Portal Dosen (Hadir Offline, Hadir Online, atau Tukar Jam) langsung tayang secara real-time di TV Kiosk.
            </p>
        </div>
        <div>
            <a href="../dosen/index.php" target="_blank" class="btn" style="background:#0284c7">
                👨‍🏫 Buka Portal Dosen &rarr;
            </a>
        </div>
    </div>

    <?php if (empty($todayDosenList)): ?>
        <p style="font-size:13px;color:var(--muted);padding:14px;background:#fff;border-radius:10px;border:1px solid var(--line)">
            Tidak ada jadwal perkuliahan aktif untuk hari <?= $namaHariIni ?> ini.
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Jam</th>
                    <th>Dosen</th>
                    <th>Mata Kuliah &amp; Kelas</th>
                    <th>Ruang</th>
                    <th>Status di Layar TV</th>
                    <th>Koreksi Manual</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($todayDosenList as $td): ?>
                <?php
                    $rawSt = $td['status_kehadiran'];
                    $rawMd = $td['mode_kuliah'];
                ?>
                <tr>
                    <td><b><?= substr($td['jam_mulai'], 0, 5) ?>–<?= substr($td['jam_selesai'], 0, 5) ?></b></td>
                    <td>
                        <b><?= htmlspecialchars($td['dosen_nama']) ?></b>
                        <small style="display:block;color:var(--muted)"><?= htmlspecialchars($td['kode_nidn']) ?></small>
                    </td>
                    <td>
                        <b><?= htmlspecialchars($td['mata_kuliah']) ?></b>
                        <small style="display:block;color:var(--muted)"><?= htmlspecialchars($td['prodi_nama']) ?> · Kelas <?= htmlspecialchars($td['kelas']) ?></small>
                    </td>
                    <td><?= htmlspecialchars($td['ruang']) ?></td>
                    <td>
                        <?php if ($rawSt === 'hadir' && $rawMd === 'online'): ?>
                            <span class="badge" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd">
                                🌐 Hadir Online
                            </span>
                            <?php if ($td['keterangan']): ?>
                                <small style="display:block;color:#0369a1;margin-top:2px"><?= htmlspecialchars($td['keterangan']) ?></small>
                            <?php endif; ?>
                        <?php elseif ($rawSt === 'hadir'): ?>
                            <span class="badge ok">
                                🏫 Hadir Offline (Tatap Muka)
                            </span>
                        <?php elseif ($rawSt === 'tukar_jam'): ?>
                            <span class="badge" style="background:#fef3c7;color:#b45309;border:1px solid #fde68a">
                                🔄 Tukar Jam <?= $td['jam_ganti'] ? '(' . htmlspecialchars($td['jam_ganti']) . ')' : '' ?>
                            </span>
                        <?php elseif (in_array($rawSt, ['tidak_hadir', 'tidak', 'izin'], true)): ?>
                            <span class="badge err">
                                ❌ Tidak Hadir (Izin) <?= $td['keterangan'] ? '(' . htmlspecialchars($td['keterangan']) . ')' : '' ?>
                            </span>
                        <?php else: ?>
                            <span class="badge off">
                                ⏳ Belum Konfirmasi
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="btn btn-secondary" style="padding:4px 10px;font-size:11px" onclick='openModalKoreksi(<?= json_encode($td) ?>)'>
                            ✏️ Koreksi Status
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div class="card">
        <h3>👨‍🏫 Master Data Dosen & Foto</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
            Dosen yang memiliki jadwal kuliah pada hari ini akan otomatis tayang di slide kanan TV Kiosk bersama foto profil mereka.
        </p>

        <table>
            <thead>
                <tr>
                    <th>Foto & Dosen</th>
                    <th>NIDN / Kode</th>
                    <th>Jadwal Aktif</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dosenList as $d): ?>
                <?php
                    $initials = strtoupper(substr($d['nama'], 0, 2));
                    $fotoUrl = !empty($d['foto_path']) ? '../storage/' . ltrim($d['foto_path'], '/') : null;
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px">
                            <div style="width:42px;height:42px;border-radius:50%;background:#e6f6ed;color:var(--g-dark);font-weight:800;display:grid;place-items:center;overflow:hidden;flex-shrink:0;border:1px solid var(--line)">
                                <?php if ($fotoUrl): ?>
                                    <img src="<?= htmlspecialchars($fotoUrl) ?>" style="width:100%;height:100%;object-fit:cover" alt="<?= htmlspecialchars($d['nama']) ?>">
                                <?php else: ?>
                                    <?= $initials ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <b><?= htmlspecialchars($d['nama']) ?></b>
                            </div>
                        </div>
                    </td>
                    <td><code><?= htmlspecialchars($d['kode_nidn']) ?></code></td>
                    <td>
                        <span class="badge ok"><?= (int)$d['total_jadwal'] ?> Kelas</span>
                    </td>
                    <td>
                        <?php if ($d['aktif']): ?>
                            <span class="badge ok">Aktif</span>
                        <?php else: ?>
                            <span class="badge off">Nonaktif</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="btn" style="padding:4px 8px;font-size:11px;margin-bottom:4px" onclick='openEditDosen(<?= json_encode($d) ?>)'>
                            ✏️ Edit & Foto
                        </button>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="dosen_id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;margin-bottom:4px">
                                <?= $d['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>
                            </button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Hapus dosen ini?')">
                            <input type="hidden" name="action" value="hapus_dosen">
                            <input type="hidden" name="dosen_id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px">Hapus</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div>
        <div class="card">
            <h3>➕ Tambah Dosen Baru</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="tambah_dosen">
                <div class="form-group">
                    <label>NIDN / Kode Dosen</label>
                    <input type="text" name="kode_nidn" placeholder="Contoh: D007 atau 0712345678" required>
                </div>
                <div class="form-group">
                    <label>Nama Lengkap & Gelar</label>
                    <input type="text" name="nama" placeholder="Contoh: Dr. Syamsul Arifin, M.Ag" required>
                </div>
                <div class="form-group">
                    <label>Foto Dosen (Opsional)</label>
                    <input type="file" name="foto" accept="image/png,image/jpeg,image/webp">
                    <small style="color:var(--muted)">Format JPG/PNG/WebP, disarankan rasio 1:1.</small>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="aktif" value="1" checked style="width:18px;height:18px">
                        <span>Status Dosen Aktif Mengajar</span>
                    </label>
                </div>
                <button type="submit" class="btn" style="width:100%">Simpan Dosen Baru</button>
            </form>
        </div>

        <!-- Form Edit Dosen & Foto (Hidden by default) -->
        <div class="card" id="boxEditDosen" style="display:none;border-color:var(--g)">
            <h3>✏️ Edit Dosen & Foto</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_dosen">
                <input type="hidden" name="dosen_id" id="editDosenId" value="">

                <div id="editFotoDosenPreviewBox" style="display:flex;align-items:center;gap:12px;margin-bottom:14px;padding:10px;background:var(--bg);border-radius:10px">
                    <div id="editFotoDosenThumb" style="width:50px;height:50px;border-radius:50%;background:#e6f6ed;display:grid;place-items:center;overflow:hidden;border:1px solid var(--line)"></div>
                    <div style="flex:1">
                        <small id="editFotoDosenText" style="color:var(--muted)"></small>
                        <div id="editHapusFotoDosenCheck" style="margin-top:4px">
                            <label style="font-size:12px;color:var(--danger);cursor:pointer">
                                <input type="checkbox" name="hapus_foto" value="1"> Hapus foto & gunakan inisial
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Ganti Foto (Opsional)</label>
                    <input type="file" name="foto" accept="image/png,image/jpeg,image/webp">
                </div>
                <div class="form-group">
                    <label>NIDN / Kode Dosen</label>
                    <input type="text" name="kode_nidn" id="editKodeNidn" required>
                </div>
                <div class="form-group">
                    <label>Nama Lengkap & Gelar</label>
                    <input type="text" name="nama" id="editNamaDosen" required>
                </div>
                <div class="form-group">
                    <label>No. HP / WhatsApp (Opsional)</label>
                    <input type="text" name="no_hp" id="editNoHp" placeholder="08xxxxxxxxxx">
                </div>
                <div class="form-group">
                    <label>PIN Keamanan Portal Dosen (Opsional)</label>
                    <input type="password" name="pin" id="editPin" placeholder="Kosongkan jika tanpa PIN">
                    <small style="color:var(--muted)">Digunakan dosen saat masuk portal jika diisi.</small>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="aktif" id="editAktifDosen" value="1" style="width:18px;height:18px">
                        <span>Status Dosen Aktif Mengajar</span>
                    </label>
                </div>
                <button type="submit" class="btn" style="width:100%;margin-bottom:8px">Simpan Perubahan</button>
                <button type="button" class="btn btn-secondary" style="width:100%" onclick="document.getElementById('boxEditDosen').style.display='none'">Batal</button>
            </form>
        </div>

        <!-- Form Koreksi Kehadiran Dosen Hari Ini -->
        <div class="card" id="boxKoreksiKehadiran" style="display:none;border-color:#0284c7;background:#f8fafc">
            <h3 style="color:#0369a1">✏️ Koreksi Status Kehadiran Hari Ini</h3>
            <p id="koreksiInfoText" style="font-size:13px;color:var(--muted);margin-bottom:14px"></p>
            <form method="POST">
                <input type="hidden" name="action" value="koreksi_kehadiran_dosen">
                <input type="hidden" name="dosen_id" id="koreksiDosenId">
                <input type="hidden" name="jadwal_id" id="koreksiJadwalId">

                <div class="form-group">
                    <label>Status Kehadiran</label>
                    <select name="status" id="koreksiStatus" class="form-control" onchange="toggleKoreksiMode(this.value)">
                        <option value="hadir">Hadir (Sesuai Jadwal)</option>
                        <option value="tukar_jam">Tukar Jam (Reschedule)</option>
                        <option value="tidak_hadir">Tidak Hadir (Izin/Sakit)</option>
                    </select>
                </div>

                <div class="form-group" id="groupKoreksiMode">
                    <label>Mode Perkuliahan</label>
                    <select name="mode" id="koreksiMode" class="form-control">
                        <option value="offline">🏫 Tatap Muka (Offline di Ruang Kampus)</option>
                        <option value="online">🌐 Daring (Online via Zoom/GMeet/dsb)</option>
                    </select>
                </div>

                <div class="form-group" id="groupKoreksiJamGanti" style="display:none">
                    <label>Jam / Tanggal Pengganti (Tukar Jam)</label>
                    <input type="text" name="jam_ganti" id="koreksiJamGanti" placeholder="Contoh: Jam 13:00 WIB atau Kamis jam 09:00">
                </div>

                <div class="form-group">
                    <label>Catatan / Alasan / Link Online</label>
                    <input type="text" name="keterangan" id="koreksiKeterangan" placeholder="Contoh: Link GMeet atau Alasan Izin">
                </div>

                <button type="submit" class="btn" style="background:#0284c7;width:100%;margin-bottom:8px">
                    💾 Simpan &amp; Tayangkan di TV
                </button>
                <button type="button" class="btn btn-secondary" style="width:100%" onclick="document.getElementById('boxKoreksiKehadiran').style.display='none'">
                    Batal
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function openEditDosen(d) {
    document.getElementById('editDosenId').value = d.id;
    document.getElementById('editKodeNidn').value = d.kode_nidn;
    document.getElementById('editNamaDosen').value = d.nama;
    document.getElementById('editNoHp').value = d.no_hp || '';
    document.getElementById('editPin').value = d.pin || '';
    document.getElementById('editAktifDosen').checked = (d.aktif == 1);

    const thumb = document.getElementById('editFotoDosenThumb');
    const txt = document.getElementById('editFotoDosenText');
    const chk = document.getElementById('editHapusFotoDosenCheck');

    if (d.foto_path) {
        thumb.innerHTML = `<img src="../storage/${d.foto_path.replace(/^\/+/, '')}" style="width:100%;height:100%;object-fit:cover">`;
        txt.textContent = 'Foto saat ini aktif.';
        chk.style.display = 'block';
    } else {
        thumb.innerHTML = `<b style="color:var(--g)">${(d.nama || '').substr(0,2).toUpperCase()}</b>`;
        txt.textContent = 'Belum ada foto (menggunakan inisial).';
        chk.style.display = 'none';
    }

    document.getElementById('boxEditDosen').style.display = 'block';
    document.getElementById('boxEditDosen').scrollIntoView({ behavior: 'smooth' });
}

function openModalKoreksi(td) {
    document.getElementById('koreksiDosenId').value = td.dosen_id;
    document.getElementById('koreksiJadwalId').value = td.jadwal_id;
    document.getElementById('koreksiInfoText').innerHTML = `<b>${td.dosen_nama}</b><br>${td.mata_kuliah} (${td.jam_mulai.substr(0,5)}–${td.jam_selesai.substr(0,5)} di ${td.ruang})`;

    const st = td.status_kehadiran || 'hadir';
    const md = td.mode_kuliah || 'offline';
    document.getElementById('koreksiStatus').value = st;
    document.getElementById('koreksiMode').value = md;
    document.getElementById('koreksiJamGanti').value = td.jam_ganti || '';
    document.getElementById('koreksiKeterangan').value = td.keterangan || '';

    toggleKoreksiMode(st);

    const box = document.getElementById('boxKoreksiKehadiran');
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth' });
}

function toggleKoreksiMode(status) {
    const grpMode = document.getElementById('groupKoreksiMode');
    const grpTukar = document.getElementById('groupKoreksiJamGanti');
    if (status === 'hadir') {
        grpMode.style.display = 'block';
        grpTukar.style.display = 'none';
    } else if (status === 'tukar_jam') {
        grpMode.style.display = 'none';
        grpTukar.style.display = 'block';
    } else {
        grpMode.style.display = 'none';
        grpTukar.style.display = 'none';
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

