<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

$today = date('Y-m-d');

// 1. Stats Pengurus Hadir
$totalPengurus = (int) $pdo->query("SELECT COUNT(*) FROM `pengurus` WHERE `aktif` = 1 AND `deleted_at` IS NULL")->fetchColumn();
$hadirPengurus = (int) $pdo->query("SELECT COUNT(DISTINCT `pengurus_id`) FROM `absensi_pengurus` WHERE `tanggal` = '{$today}'")->fetchColumn();

// 2. Stats Perkuliahan & Mahasiswa
$totalJadwalHariIni = (int) $pdo->query("SELECT COUNT(*) FROM `jadwal_kuliah` WHERE `hari` = " . date('N') . " AND `aktif` = 1")->fetchColumn();
$totalMahasiswa = (int) $pdo->query("SELECT SUM(`jumlah`) FROM `statistik_mahasiswa` sm JOIN `periode_akademik` pa ON pa.id = sm.periode_id AND pa.aktif = 1")->fetchColumn();
$totalProdi = (int) $pdo->query("SELECT COUNT(*) FROM `prodi` WHERE `aktif` = 1")->fetchColumn();

// 3. Impor Terakhir
$lastImport = $pdo->query("SELECT * FROM `import_log` ORDER BY `id` DESC LIMIT 1")->fetch();

// 4. Daftar Kehadiran Hari Ini (Ringkas)
$recentAbsensi = $pdo->query("
    SELECT a.*, p.nama, p.jabatan
    FROM `absensi_pengurus` a
    JOIN `pengurus` p ON p.id = a.pengurus_id
    WHERE a.tanggal = '{$today}'
    ORDER BY a.updated_at DESC, a.id DESC LIMIT 5
")->fetchAll();
?>

<div class="stats-grid">
    <div class="stat-card">
        <small>Pengurus Hadir Hari Ini</small>
        <b><?= $hadirPengurus ?> / <?= $totalPengurus ?></b>
        <span class="badge <?= $hadirPengurus > 0 ? 'ok' : 'off' ?>" style="margin-top:8px">
            <?= $totalPengurus > 0 ? round(($hadirPengurus / $totalPengurus) * 100) : 0 ?>% Terisi
        </span>
    </div>
    <div class="stat-card">
        <small>Kuliah Hari Ini (<?= date('l') ?>)</small>
        <b><?= $totalJadwalHariIni ?> Kelas</b>
        <span class="badge ok" style="margin-top:8px">Jadwal Aktif</span>
    </div>
    <div class="stat-card">
        <small>Total Mahasiswa Aktif</small>
        <b><?= number_format($totalMahasiswa, 0, ',', '.') ?></b>
        <a href="prodi.php" style="text-decoration:none"><span class="badge ok" style="margin-top:8px"><?= $totalProdi ?> Program Studi Aktif</span></a>
    </div>
    <div class="stat-card">
        <small>Status Impor Terakhir</small>
        <b style="font-size:18px;margin-top:8px"><?= $lastImport ? htmlspecialchars($lastImport['jenis']) : 'Belum Ada' ?></b>
        <span class="badge <?= ($lastImport && $lastImport['status'] === 'selesai') ? 'ok' : 'off' ?>" style="margin-top:8px">
            <?= $lastImport ? htmlspecialchars($lastImport['nama_file']) : 'N/A' ?>
        </span>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div class="card">
        <h3>📋 Kehadiran Pengurus Hari Ini</h3>
        <?php if (empty($recentAbsensi)): ?>
            <p style="color:var(--muted);font-size:14px;padding:20px 0;text-align:center">Belum ada pengurus yang melakukan presensi hari ini.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Status / Sesi</th>
                        <th>Nama Pengurus</th>
                        <th>Jabatan</th>
                        <th>Metode</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAbsensi as $a): ?>
                    <tr>
                        <td>
                            <b><?= substr($a['status'] === 'pulang' && $a['waktu_pulang'] ? $a['waktu_pulang'] : ($a['waktu_masuk'] ?: $a['waktu_absen']), 0, 5) ?> WIB</b>
                        </td>
                        <td>
                            <?php if ($a['status'] === 'pulang'): ?>
                                <span class="badge" style="background:#fef3c7;color:#b45309">🚪 Pulang (Sesi <?= $a['sesi_ke'] ?>)</span>
                            <?php else: ?>
                                <span class="badge ok">✓ Masuk (Sesi <?= $a['sesi_ke'] ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td><b><?= htmlspecialchars($a['nama']) ?></b></td>
                        <td><small style="color:var(--muted)"><?= htmlspecialchars($a['jabatan']) ?></small></td>
                        <td>
                            <span class="badge <?= ($a['metode'] === 'qr' || $a['metode_masuk'] === 'qr') ? 'ok' : 'off' ?>">
                                <?= ($a['metode'] === 'qr' || $a['metode_masuk'] === 'qr') ? '📱 Scan QR' : '✍️ Manual' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:16px;text-align:right">
                <a href="absensi.php" style="color:var(--g);font-size:13px;font-weight:700;text-decoration:none">Lihat Rekap Lengkap &rarr;</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>⚡ Aksi Cepat Operator</h3>
        <div style="display:flex;flex-direction:column;gap:10px">
            <a href="absensi.php#koreksi" class="btn" style="width:100%;text-align:center">✍️ Koreksi Presensi Manual</a>
            <a href="import.php" class="btn btn-secondary" style="width:100%;text-align:center">📥 Impor Jadwal / Mahasiswa</a>
            <a href="pengaturan.php" class="btn btn-secondary" style="width:100%;text-align:center">⚙️ Pengaturan Durasi Layar</a>
            <a href="../api/v1/display/simulasi_scan.php" target="_blank" class="btn btn-secondary" style="width:100%;text-align:center">🧪 Buka Simulator Presensi</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

