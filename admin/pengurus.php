<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

$message = '';
$error = '';

// Helper upload foto pengurus
function handleUploadFotoPengurus(?array $file): ?string {
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
    $filename = 'pengurus_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
    $targetDir = dirname(__DIR__) . '/storage/pengurus';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $targetPath = $targetDir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'pengurus/' . $filename;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah_pengurus') {
        $nip = trim($_POST['nip'] ?? '');
        $nama = trim($_POST['nama'] ?? '');
        $jabatan = trim($_POST['jabatan'] ?? '');
        $urutan = (int)($_POST['urutan'] ?? 0);
        $pin = trim($_POST['pin'] ?? '123456');

        if (empty($nama) || empty($jabatan)) {
            $error = 'Nama dan Jabatan wajib diisi!';
        } else {
            $fotoPath = handleUploadFotoPengurus($_FILES['foto'] ?? null);

            $pinHash = password_hash($pin, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO `pengurus` (`nip`, `nama`, `jabatan`, `urutan`, `foto_path`, `pin_hash`, `aktif`, `created_at`, `updated_at`)
                                   VALUES (:nip, :nama, :jabatan, :urutan, :foto, :pin, 1, NOW(), NOW())");
            $stmt->execute([
                'nip' => $nip ?: null,
                'nama' => $nama,
                'jabatan' => $jabatan,
                'urutan' => $urutan,
                'foto' => $fotoPath,
                'pin' => $pinHash
            ]);

            // Audit
            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `created_at`)
                           VALUES ('admin', :uid, 'create', 'pengurus', :pid, NOW())")->execute([
                'uid' => $admin['id'],
                'pid' => $pdo->lastInsertId()
            ]);

            $message = "Pengurus baru '{$nama}' berhasil ditambahkan dengan PIN default: {$pin}.";
        }
    } elseif ($action === 'edit_pengurus') {
        $pId = (int)($_POST['pengurus_id'] ?? 0);
        $nip = trim($_POST['nip'] ?? '');
        $nama = trim($_POST['nama'] ?? '');
        $jabatan = trim($_POST['jabatan'] ?? '');
        $urutan = (int)($_POST['urutan'] ?? 0);
        $hapusFoto = isset($_POST['hapus_foto']);

        if ($pId > 0 && !empty($nama) && !empty($jabatan)) {
            $cur = $pdo->prepare("SELECT * FROM `pengurus` WHERE `id` = :id")->execute(['id' => $pId]);
            $curPengurus = $pdo->query("SELECT * FROM `pengurus` WHERE `id` = {$pId}")->fetch();

            $fotoPath = $curPengurus['foto_path'] ?? null;

            if ($hapusFoto) {
                if ($fotoPath && file_exists(dirname(__DIR__) . '/storage/' . $fotoPath)) {
                    @unlink(dirname(__DIR__) . '/storage/' . $fotoPath);
                }
                $fotoPath = null;
            }

            // Jika ada foto baru diunggah
            $uploadedFoto = handleUploadFotoPengurus($_FILES['foto'] ?? null);
            if ($uploadedFoto) {
                if ($fotoPath && file_exists(dirname(__DIR__) . '/storage/' . $fotoPath)) {
                    @unlink(dirname(__DIR__) . '/storage/' . $fotoPath);
                }
                $fotoPath = $uploadedFoto;
            }

            $stmt = $pdo->prepare("UPDATE `pengurus` SET `nip` = :nip, `nama` = :nama, `jabatan` = :jabatan, `urutan` = :urutan, `foto_path` = :foto, `updated_at` = NOW() WHERE `id` = :id");
            $stmt->execute([
                'nip' => $nip ?: null,
                'nama' => $nama,
                'jabatan' => $jabatan,
                'urutan' => $urutan,
                'foto' => $fotoPath,
                'id' => $pId
            ]);

            // Audit
            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `created_at`)
                           VALUES ('admin', :uid, 'update', 'pengurus', :pid, NOW())")->execute([
                'uid' => $admin['id'],
                'pid' => $pId
            ]);

            $message = "Data pengurus '{$nama}' dan foto berhasil diperbarui!";
        } else {
            $error = "Nama dan Jabatan wajib diisi.";
        }
    } elseif ($action === 'reset_pin') {
        $pId = (int)($_POST['pengurus_id'] ?? 0);
        $newPin = trim($_POST['new_pin'] ?? '');

        if ($pId > 0 && strlen($newPin) === 6 && is_numeric($newPin)) {
            $pinHash = password_hash($newPin, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE `pengurus` SET `pin_hash` = :hash WHERE `id` = :id")->execute([
                'hash' => $pinHash,
                'id' => $pId
            ]);
            $message = "PIN pengurus ID {$pId} berhasil direset menjadi '{$newPin}'.";
        } else {
            $error = "PIN baru harus 6 digit angka.";
        }
    } elseif ($action === 'cabut_perangkat') {
        $devId = (int)($_POST['device_id'] ?? 0);
        if ($devId > 0) {
            $pdo->prepare("UPDATE `perangkat_pengurus` SET `dicabut_pada` = NOW() WHERE `id` = :id")->execute(['id' => $devId]);
            $message = "Perangkat HP telah berhasil dicabut.";
        }
    } elseif ($action === 'hapus_pengurus') {
        $pId = (int)($_POST['pengurus_id'] ?? 0);
        if ($pId > 0) {
            $pdo->prepare("UPDATE `pengurus` SET `deleted_at` = NOW() WHERE `id` = :id")->execute(['id' => $pId]);
            $message = "Pengurus berhasil dihapus (soft delete).";
        }
    }
}

// Ambil pengurus dan perangkatnya
$pengurusList = $pdo->query("SELECT * FROM `pengurus` WHERE `deleted_at` IS NULL ORDER BY `urutan` ASC")->fetchAll();
?>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div class="card">
        <h3>👥 Daftar Pengurus & Foto</h3>
        <table>
            <thead>
                <tr>
                    <th>Urutan</th>
                    <th>Foto & Identitas</th>
                    <th>Jabatan</th>
                    <th>Perangkat Terdaftar</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pengurusList as $p): ?>
                <?php
                    $devices = $pdo->prepare("SELECT * FROM `perangkat_pengurus` WHERE `pengurus_id` = :pid AND `dicabut_pada` IS NULL");
                    $devices->execute(['pid' => $p['id']]);
                    $devList = $devices->fetchAll();
                    $initials = strtoupper(substr($p['nama'], 0, 2));
                    $fotoUrl = !empty($p['foto_path']) ? '../storage/' . ltrim($p['foto_path'], '/') : null;
                ?>
                <tr>
                    <td><b>#<?= $p['urutan'] ?></b></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px">
                            <div style="width:42px;height:42px;border-radius:50%;background:#e6f6ed;color:var(--g-dark);font-weight:800;display:grid;place-items:center;overflow:hidden;flex-shrink:0;border:1px solid var(--line)">
                                <?php if ($fotoUrl): ?>
                                    <img src="<?= htmlspecialchars($fotoUrl) ?>" style="width:100%;height:100%;object-fit:cover" alt="<?= htmlspecialchars($p['nama']) ?>">
                                <?php else: ?>
                                    <?= $initials ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <b><?= htmlspecialchars($p['nama']) ?></b><br>
                                <small style="color:var(--muted)">NIP: <?= htmlspecialchars($p['nip'] ?? '-') ?></small>
                            </div>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($p['jabatan']) ?></td>
                    <td>
                        <?php if (empty($devList)): ?>
                            <span class="badge off">Belum Ada</span>
                        <?php else: ?>
                            <?php foreach ($devList as $dev): ?>
                                <div style="font-size:12px;margin-bottom:4px">
                                    📱 <?= htmlspecialchars(substr($dev['label'], 0, 25)) ?>...
                                    <form method="POST" style="display:inline" onsubmit="return confirm('Cabut perangkat ini?')">
                                        <input type="hidden" name="action" value="cabut_perangkat">
                                        <input type="hidden" name="device_id" value="<?= $dev['id'] ?>">
                                        <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:11px;font-weight:700">[Cabut]</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="btn" style="padding:4px 8px;font-size:11px;margin-bottom:4px" onclick='openEditPengurus(<?= json_encode($p) ?>)'>
                            ✏️ Edit & Foto
                        </button>
                        <button type="button" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;margin-bottom:4px" onclick="openResetPin(<?= $p['id'] ?>, '<?= addslashes($p['nama']) ?>')">
                            🔑 Reset PIN
                        </button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Yakin ingin menghapus pengurus ini?')">
                            <input type="hidden" name="action" value="hapus_pengurus">
                            <input type="hidden" name="pengurus_id" value="<?= $p['id'] ?>">
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
            <h3>➕ Tambah Pengurus Baru</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="tambah_pengurus">
                <div class="form-group">
                    <label>NIP (Opsional)</label>
                    <input type="text" name="nip" placeholder="Contoh: 1985010109">
                </div>
                <div class="form-group">
                    <label>Nama Lengkap & Gelar</label>
                    <input type="text" name="nama" placeholder="Contoh: Ust. Zulfikar, M.Pd" required>
                </div>
                <div class="form-group">
                    <label>Jabatan</label>
                    <input type="text" name="jabatan" placeholder="Contoh: Kepala Lab Komputer" required>
                </div>
                <div class="form-group">
                    <label>Foto Pengurus (Opsional)</label>
                    <input type="file" name="foto" accept="image/png,image/jpeg,image/webp">
                    <small style="color:var(--muted)">Format JPG/PNG/WebP, disarankan rasio 1:1 (persegi).</small>
                </div>
                <div class="form-group">
                    <label>Nomor Urutan di Layar</label>
                    <input type="number" name="urutan" value="<?= count($pengurusList) + 1 ?>" required>
                </div>
                <div class="form-group">
                    <label>PIN 6 Digit (Awal)</label>
                    <input type="text" name="pin" value="123456" maxlength="6" required>
                </div>
                <button type="submit" class="btn" style="width:100%">Simpan Pengurus</button>
            </form>
        </div>

        <!-- Form Edit Pengurus & Foto (Hidden by default) -->
        <div class="card" id="boxEditPengurus" style="display:none;border-color:var(--g)">
            <h3>✏️ Edit Pengurus & Foto</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_pengurus">
                <input type="hidden" name="pengurus_id" id="editPengurusId" value="">

                <div id="editFotoPreviewBox" style="display:flex;align-items:center;gap:12px;margin-bottom:14px;padding:10px;background:var(--bg);border-radius:10px">
                    <div id="editFotoThumb" style="width:50px;height:50px;border-radius:50%;background:#e6f6ed;display:grid;place-items:center;overflow:hidden;border:1px solid var(--line)"></div>
                    <div style="flex:1">
                        <small id="editFotoText" style="color:var(--muted)"></small>
                        <div id="editHapusFotoCheck" style="margin-top:4px">
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
                    <label>NIP</label>
                    <input type="text" name="nip" id="editNip">
                </div>
                <div class="form-group">
                    <label>Nama Lengkap & Gelar</label>
                    <input type="text" name="nama" id="editNama" required>
                </div>
                <div class="form-group">
                    <label>Jabatan</label>
                    <input type="text" name="jabatan" id="editJabatan" required>
                </div>
                <div class="form-group">
                    <label>Nomor Urutan</label>
                    <input type="number" name="urutan" id="editUrutan" required>
                </div>
                <button type="submit" class="btn" style="width:100%;margin-bottom:8px">Simpan Perubahan</button>
                <button type="button" class="btn btn-secondary" style="width:100%" onclick="document.getElementById('boxEditPengurus').style.display='none'">Batal</button>
            </form>
        </div>

        <div class="card" id="boxResetPin" style="display:none">
            <h3>🔑 Reset PIN Pengurus</h3>
            <p id="resetPinName" style="font-size:13px;color:var(--muted);margin-bottom:12px"></p>
            <form method="POST">
                <input type="hidden" name="action" value="reset_pin">
                <input type="hidden" name="pengurus_id" id="resetPinId" value="">
                <div class="form-group">
                    <label>PIN 6 Digit Baru</label>
                    <input type="text" name="new_pin" maxlength="6" placeholder="Contoh: 654321" required>
                </div>
                <button type="submit" class="btn">Simpan PIN Baru</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('boxResetPin').style.display='none'">Batal</button>
            </form>
        </div>
    </div>
</div>

<script>
function openResetPin(id, name) {
    document.getElementById('resetPinId').value = id;
    document.getElementById('resetPinName').textContent = 'Mengubah PIN untuk: ' + name;
    document.getElementById('boxResetPin').style.display = 'block';
    document.getElementById('boxEditPengurus').style.display = 'none';
    document.getElementById('boxResetPin').scrollIntoView({ behavior: 'smooth' });
}

function openEditPengurus(p) {
    document.getElementById('editPengurusId').value = p.id;
    document.getElementById('editNip').value = p.nip || '';
    document.getElementById('editNama').value = p.nama;
    document.getElementById('editJabatan').value = p.jabatan;
    document.getElementById('editUrutan').value = p.urutan;

    const thumb = document.getElementById('editFotoThumb');
    const txt = document.getElementById('editFotoText');
    const chk = document.getElementById('editHapusFotoCheck');

    if (p.foto_path) {
        thumb.innerHTML = `<img src="../storage/${p.foto_path.replace(/^\/+/, '')}" style="width:100%;height:100%;object-fit:cover">`;
        txt.textContent = 'Foto saat ini aktif.';
        chk.style.display = 'block';
    } else {
        thumb.innerHTML = `<b style="color:var(--g)">${(p.nama || '').substr(0,2).toUpperCase()}</b>`;
        txt.textContent = 'Belum ada foto (menggunakan inisial).';
        chk.style.display = 'none';
    }

    document.getElementById('boxEditPengurus').style.display = 'block';
    document.getElementById('boxResetPin').style.display = 'none';
    document.getElementById('boxEditPengurus').scrollIntoView({ behavior: 'smooth' });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
