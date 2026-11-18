<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

$isSuperAdmin = ($admin['role'] === 'super_admin');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profil_sendiri') {
        $nama = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $passLama = $_POST['password_lama'] ?? '';
        $passBaru = $_POST['password_baru'] ?? '';
        $passKonf = $_POST['password_konfirmasi'] ?? '';

        if (empty($nama) || empty($email)) {
            $error = 'Nama dan username/email wajib diisi!';
        } else {
            // Cek duplikat email/username
            $stmtDup = $pdo->prepare("SELECT `id` FROM `users` WHERE LOWER(`email`) = :em AND `id` != :id LIMIT 1");
            $stmtDup->execute(['em' => $email, 'id' => $admin['id']]);
            if ($stmtDup->fetchColumn()) {
                $error = 'Username atau email tersebut sudah digunakan oleh akun lain.';
            } else {
                $stmtMe = $pdo->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
                $stmtMe->execute(['id' => $admin['id']]);
                $me = $stmtMe->fetch();

                if (!empty($passBaru)) {
                    if (!$me || !password_verify($passLama, $me['password'])) {
                        $error = 'Kata sandi lama tidak cocok.';
                    } elseif (strlen($passBaru) < 6) {
                        $error = 'Kata sandi baru minimal harus 6 karakter.';
                    } elseif ($passBaru !== $passKonf) {
                        $error = 'Konfirmasi kata sandi baru tidak sama.';
                    } else {
                        $hash = password_hash($passBaru, PASSWORD_BCRYPT);
                        $pdo->prepare("UPDATE `users` SET `name` = :n, `email` = :e, `password` = :p, `updated_at` = NOW() WHERE `id` = :id")
                            ->execute(['n' => $nama, 'e' => $email, 'p' => $hash, 'id' => $admin['id']]);
                        $_SESSION['stai_admin_name'] = $nama;
                        $_SESSION['stai_admin_email'] = $email;
                        $admin['name'] = $nama;
                        $admin['email'] = $email;

                        $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `ip_address`, `created_at`)
                                       VALUES ('admin', :uid, 'ganti_password_sendiri', 'users', :uid, :ip, NOW())")
                            ->execute(['uid' => $admin['id'], 'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

                        $message = 'Profil dan kata sandi akun Anda berhasil diperbarui!';
                    }
                } else {
                    $pdo->prepare("UPDATE `users` SET `name` = :n, `email` = :e, `updated_at` = NOW() WHERE `id` = :id")
                        ->execute(['n' => $nama, 'e' => $email, 'id' => $admin['id']]);
                    $_SESSION['stai_admin_name'] = $nama;
                    $_SESSION['stai_admin_email'] = $email;
                    $admin['name'] = $nama;
                    $admin['email'] = $email;
                    $message = 'Nama dan username/email akun Anda berhasil diperbarui!';
                }
            }
        }

    } elseif ($action === 'tambah_user') {
        if (!$isSuperAdmin) {
            $error = 'Akses ditolak. Hanya Super Admin yang dapat menambah akun baru.';
        } else {
            $nama = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $role = ($_POST['role'] ?? 'admin') === 'super_admin' ? 'super_admin' : 'admin';
            $password = $_POST['password'] ?? '';
            $passwordKonf = $_POST['password_konfirmasi'] ?? '';

            if (empty($nama) || empty($email) || empty($password)) {
                $error = 'Nama, username/email, dan kata sandi wajib diisi!';
            } elseif (strlen($password) < 6) {
                $error = 'Kata sandi minimal harus 6 karakter.';
            } elseif ($password !== $passwordKonf) {
                $error = 'Konfirmasi kata sandi tidak cocok.';
            } else {
                $stmtDup = $pdo->prepare("SELECT `id` FROM `users` WHERE LOWER(`email`) = :em LIMIT 1");
                $stmtDup->execute(['em' => $email]);
                if ($stmtDup->fetchColumn()) {
                    $error = 'Username atau email tersebut sudah terdaftar.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $pdo->prepare("INSERT INTO `users` (`name`, `email`, `password`, `role`, `created_at`, `updated_at`)
                                   VALUES (:n, :e, :p, :r, NOW(), NOW())")
                        ->execute(['n' => $nama, 'e' => $email, 'p' => $hash, 'r' => $role]);

                    $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `meta`, `ip_address`, `created_at`)
                                   VALUES ('admin', :uid, 'tambah_user', 'users', :meta, :ip, NOW())")
                        ->execute([
                            'uid' => $admin['id'],
                            'meta' => json_encode(['name' => $nama, 'email' => $email, 'role' => $role]),
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                        ]);

                    $message = "Akun baru \"{$nama}\" ({$email}) dengan role " . ($role === 'super_admin' ? 'Super Admin' : 'Admin / Operator') . " berhasil dibuat!";
                }
            }
        }

    } elseif ($action === 'edit_user') {
        if (!$isSuperAdmin) {
            $error = 'Akses ditolak. Hanya Super Admin yang dapat mengubah akun pengguna lain.';
        } else {
            $uId = (int)($_POST['user_id'] ?? 0);
            $nama = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $role = ($_POST['role'] ?? 'admin') === 'super_admin' ? 'super_admin' : 'admin';
            $passBaru = $_POST['password_baru'] ?? '';

            if ($uId <= 0 || empty($nama) || empty($email)) {
                $error = 'Data akun tidak lengkap.';
            } elseif (!empty($passBaru) && strlen($passBaru) < 6) {
                $error = 'Kata sandi baru minimal harus 6 karakter.';
            } else {
                $stmtDup = $pdo->prepare("SELECT `id` FROM `users` WHERE LOWER(`email`) = :em AND `id` != :id LIMIT 1");
                $stmtDup->execute(['em' => $email, 'id' => $uId]);
                if ($stmtDup->fetchColumn()) {
                    $error = 'Username atau email tersebut sudah digunakan oleh akun lain.';
                } else {
                    // Cegah penurunan role Super Admin terakhir
                    $stmtCur = $pdo->prepare("SELECT `role` FROM `users` WHERE `id` = :id LIMIT 1");
                    $stmtCur->execute(['id' => $uId]);
                    $curRole = $stmtCur->fetchColumn();

                    $totalSuper = (int) $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'super_admin'")->fetchColumn();
                    if ($curRole === 'super_admin' && $role !== 'super_admin' && $totalSuper <= 1) {
                        $error = 'Tidak dapat menurunkan role Super Admin terakhir. Minimal harus ada 1 akun Super Admin.';
                    } else {
                        if (!empty($passBaru)) {
                            $hash = password_hash($passBaru, PASSWORD_BCRYPT);
                            $pdo->prepare("UPDATE `users` SET `name` = :n, `email` = :e, `role` = :r, `password` = :p, `updated_at` = NOW() WHERE `id` = :id")
                                ->execute(['n' => $nama, 'e' => $email, 'r' => $role, 'p' => $hash, 'id' => $uId]);
                        } else {
                            $pdo->prepare("UPDATE `users` SET `name` = :n, `email` = :e, `role` = :r, `updated_at` = NOW() WHERE `id` = :id")
                                ->execute(['n' => $nama, 'e' => $email, 'r' => $role, 'id' => $uId]);
                        }

                        if ($uId === (int)$admin['id']) {
                            $_SESSION['stai_admin_name'] = $nama;
                            $_SESSION['stai_admin_email'] = $email;
                            $_SESSION['stai_admin_role'] = $role;
                            $admin['name'] = $nama;
                            $admin['email'] = $email;
                            $admin['role'] = $role;
                        }

                        $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `ip_address`, `created_at`)
                                       VALUES ('admin', :uid, 'edit_user', 'users', :tid, :meta, :ip, NOW())")
                            ->execute([
                                'uid' => $admin['id'],
                                'tid' => $uId,
                                'meta' => json_encode(['name' => $nama, 'email' => $email, 'role' => $role, 'password_diubah' => !empty($passBaru)]),
                                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                            ]);

                        $message = "Akun \"{$nama}\" berhasil diperbarui" . (!empty($passBaru) ? " (termasuk kata sandi baru)" : "") . "!";
                    }
                }
            }
        }

    } elseif ($action === 'hapus_user') {
        if (!$isSuperAdmin) {
            $error = 'Akses ditolak. Hanya Super Admin yang dapat menghapus akun.';
        } else {
            $uId = (int)($_POST['user_id'] ?? 0);
            if ($uId === (int)$admin['id']) {
                $error = 'Anda tidak dapat menghapus akun yang sedang Anda gunakan untuk login.';
            } else {
                $stmtCur = $pdo->prepare("SELECT `name`, `email`, `role` FROM `users` WHERE `id` = :id LIMIT 1");
                $stmtCur->execute(['id' => $uId]);
                $targetUser = $stmtCur->fetch();

                if ($targetUser) {
                    $totalSuper = (int) $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'super_admin'")->fetchColumn();
                    if ($targetUser['role'] === 'super_admin' && $totalSuper <= 1) {
                        $error = 'Tidak dapat menghapus Super Admin terakhir.';
                    } else {
                        $pdo->prepare("DELETE FROM `users` WHERE `id` = :id")->execute(['id' => $uId]);
                        $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `ip_address`, `created_at`)
                                       VALUES ('admin', :uid, 'hapus_user', 'users', :tid, :meta, :ip, NOW())")
                            ->execute([
                                'uid' => $admin['id'],
                                'tid' => $uId,
                                'meta' => json_encode($targetUser),
                                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                            ]);
                        $message = "Akun \"{$targetUser['name']}\" berhasil dihapus.";
                    }
                }
            }
        }
    }
}

$allUsers = $pdo->query("SELECT `id`, `name`, `email`, `role`, `created_at`, `updated_at` FROM `users` ORDER BY CASE WHEN `role` = 'super_admin' THEN 0 ELSE 1 END, `id` ASC")->fetchAll();
?>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:24px">
    <div class="stat-card">
        <small>👑 Super Admin</small>
        <p style="font-size:12.5px;color:var(--muted);margin-top:6px;line-height:1.45">Akses penuh seluruh panel, kelola akun &amp; password semua role, serta hapus/reset data.</p>
    </div>
    <div class="stat-card">
        <small>🛠️ Admin / Operator</small>
        <p style="font-size:12.5px;color:var(--muted);margin-top:6px;line-height:1.45">Kelola presensi, master dosen/pengurus, impor CSV, agenda, pengumuman, dan media layar.</p>
    </div>
    <div class="stat-card">
        <small>👥 PIN Pengurus &amp; Tendik</small>
        <p style="font-size:12.5px;color:var(--muted);margin-top:6px;line-height:1.45">Login presensi HP memakai NIP &amp; PIN. <a href="pengurus.php" style="color:var(--g-dark);font-weight:700">Atur di Kelola Pengurus &rarr;</a></p>
    </div>
    <div class="stat-card">
        <small>👨‍🏫 PIN Portal Dosen</small>
        <p style="font-size:12.5px;color:var(--muted);margin-top:6px;line-height:1.45">Login Portal Dosen memakai NIDN &amp; PIN. <a href="dosen.php" style="color:var(--g-dark);font-weight:700">Atur di Kelola Dosen &rarr;</a></p>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<!-- DAFTAR AKUN & ROLE -->
<div class="card" style="margin-bottom:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
        <div>
            <h3>🔐 Daftar User &amp; Hak Akses Role (<?= count($allUsers) ?> Akun)</h3>
            <p style="font-size:13px;color:var(--muted)">Atur username/email, kata sandi, dan role (Super Admin / Operator) yang diizinkan masuk ke Panel Admin.</p>
        </div>
        <button type="button" class="btn" onclick="openTambahUserModal()">+ Tambah User Baru</button>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:45px">No</th>
                <th>Nama Lengkap</th>
                <th>Username / Email Login</th>
                <th>Role Hak Akses</th>
                <th>Terakhir Diperbarui</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allUsers as $idx => $u): ?>
            <tr>
                <td><?= $idx + 1 ?></td>
                <td>
                    <b><?= htmlspecialchars($u['name']) ?></b>
                    <?php if ((int)$u['id'] === (int)$admin['id']): ?>
                        <span class="badge ok" style="margin-left:6px;font-size:10px">Akun Anda</span>
                    <?php endif; ?>
                </td>
                <td><code><?= htmlspecialchars($u['email']) ?></code></td>
                <td>
                    <?php if ($u['role'] === 'super_admin'): ?>
                        <span class="badge ok">👑 Super Admin</span>
                    <?php else: ?>
                        <span class="badge" style="background:#e0f2fe;color:#0369a1">🛠️ Admin / Operator</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12.5px;color:var(--muted)"><?= htmlspecialchars($u['updated_at'] ?: $u['created_at'] ?: '—') ?></td>
                <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <button type="button" class="btn btn-secondary" style="padding:5px 10px;font-size:12px" onclick='openEditUserModal(<?= json_encode([
                            "id" => (int)$u["id"],
                            "name" => $u["name"],
                            "email" => $u["email"],
                            "role" => $u["role"]
                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                            ✏️ Edit / Ganti Pass
                        </button>
                        <?php if ((int)$u['id'] !== (int)$admin['id']): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Hapus akun <?= addslashes($u['name']) ?> (<?= addslashes($u['email']) ?>)?')">
                            <input type="hidden" name="action" value="hapus_user">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding:5px 10px;font-size:12px">🗑️ Hapus</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- GANTI PROFIL & PASSWORD AKUN PRIBADI -->
<div class="card" style="max-width:620px">
    <h3>🔑 Ubah Username &amp; Kata Sandi Akun Saya</h3>
    <p style="font-size:13px;color:var(--muted);margin-bottom:18px">Gunakan formulir ini untuk memperbarui nama, username/email login, atau kata sandi akun Anda sendiri.</p>

    <form method="POST">
        <input type="hidden" name="action" value="update_profil_sendiri">
        <div class="form-group">
            <label>Nama Lengkap</label>
            <input type="text" name="name" value="<?= htmlspecialchars($admin['name']) ?>" required>
        </div>
        <div class="form-group">
            <label>Username / Email Login</label>
            <input type="text" name="email" value="<?= htmlspecialchars($admin['email']) ?>" required>
        </div>
        <hr style="border:0;border-top:1px dashed var(--line);margin:18px 0">
        <p style="font-size:12.5px;color:var(--muted);margin-bottom:12px">Kosongkan kolom kata sandi di bawah jika hanya ingin mengganti nama/username.</p>
        <div class="form-group">
            <label>Kata Sandi Lama</label>
            <input type="password" name="password_lama" placeholder="Wajib diisi jika ingin mengubah kata sandi">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
                <label>Kata Sandi Baru (Min. 6 karakter)</label>
                <input type="password" name="password_baru" placeholder="Kata sandi baru">
            </div>
            <div class="form-group">
                <label>Konfirmasi Kata Sandi Baru</label>
                <input type="password" name="password_konfirmasi" placeholder="Ulangi kata sandi baru">
            </div>
        </div>
        <button type="submit" class="btn">💾 Simpan Perubahan Akun Saya</button>
    </form>
</div>

<?php if ($isSuperAdmin): ?>
<!-- MODAL TAMBAH USER -->
<div id="modalTambahUser" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:20px;padding:26px;width:100%;max-width:480px;box-shadow:0 10px 40px rgba(0,0,0,0.2)">
        <h3 style="margin-bottom:8px">➕ Tambah Akun Admin / Operator Baru</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px">Buat akun baru lengkap dengan role hak akses dan kata sandinya.</p>

        <form method="POST">
            <input type="hidden" name="action" value="tambah_user">
            <div class="form-group">
                <label>Nama Lengkap</label>
                <input type="text" name="name" placeholder="Contoh: Operator Akademik" required>
            </div>
            <div class="form-group">
                <label>Username / Email Login</label>
                <input type="text" name="email" placeholder="Contoh: operator2 atau op@stai-sidogiri.ac.id" required>
            </div>
            <div class="form-group">
                <label>Role Hak Akses</label>
                <select name="role" required>
                    <option value="admin">🛠️ Admin / Operator</option>
                    <option value="super_admin">👑 Super Admin</option>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label>Kata Sandi (Min. 6 karakter)</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Ulangi Kata Sandi</label>
                    <input type="password" name="password_konfirmasi" required minlength="6">
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
                <button type="button" class="btn btn-secondary" onclick="closeTambahUserModal()">Batal</button>
                <button type="submit" class="btn">💾 Simpan User Baru</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT USER & GANTI PASSWORD -->
<div id="modalEditUser" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:20px;padding:26px;width:100%;max-width:480px;box-shadow:0 10px 40px rgba(0,0,0,0.2)">
        <h3 style="margin-bottom:8px">✏️ Edit User, Role &amp; Reset Kata Sandi</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px">Perbarui informasi akun atau atur kata sandi baru untuk pengguna ini.</p>

        <form method="POST">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="form-group">
                <label>Nama Lengkap</label>
                <input type="text" name="name" id="editUserName" required>
            </div>
            <div class="form-group">
                <label>Username / Email Login</label>
                <input type="text" name="email" id="editUserEmail" required>
            </div>
            <div class="form-group">
                <label>Role Hak Akses</label>
                <select name="role" id="editUserRole" required>
                    <option value="admin">🛠️ Admin / Operator</option>
                    <option value="super_admin">👑 Super Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label>Kata Sandi Baru (Opsional — kosongkan jika tidak diubah)</label>
                <input type="password" name="password_baru" id="editUserPass" placeholder="Minimal 6 karakter jika ingin ganti password">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
                <button type="button" class="btn btn-secondary" onclick="closeEditUserModal()">Batal</button>
                <button type="submit" class="btn">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openTambahUserModal() {
    document.getElementById('modalTambahUser').style.display = 'flex';
}
function closeTambahUserModal() {
    document.getElementById('modalTambahUser').style.display = 'none';
}
function openEditUserModal(u) {
    document.getElementById('editUserId').value = u.id;
    document.getElementById('editUserName').value = u.name;
    document.getElementById('editUserEmail').value = u.email;
    document.getElementById('editUserRole').value = u.role;
    document.getElementById('editUserPass').value = '';
    document.getElementById('modalEditUser').style.display = 'flex';
}
function closeEditUserModal() {
    document.getElementById('modalEditUser').style.display = 'none';
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
