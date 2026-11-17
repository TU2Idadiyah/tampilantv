<?php
require_once __DIR__ . '/auth.php';
$admin = requireAdminAuth();
$pdo = getAdminPdo();
date_default_timezone_set('Asia/Jakarta');

// Helper format durasi
function fmtDuration(int $menit): string {
    if ($menit <= 0) return '0 menit';
    $j = intdiv($menit, 60);
    $m = $menit % 60;
    if ($j > 0 && $m > 0) return "{$j} jam {$m} menit";
    if ($j > 0) return "{$j} jam";
    return "{$m} menit";
}

function fmtDurationShort(int $menit): string {
    if ($menit <= 0) return '0m';
    $j = intdiv($menit, 60);
    $m = $menit % 60;
    if ($j > 0 && $m > 0) return "{$j}j {$m}m";
    if ($j > 0) return "{$j}j";
    return "{$m}m";
}

// Handler Ekspor CSV
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $bulan = $_GET['bulan'] ?? date('Y-m');

    if ($action === 'export_rekap') {
        // Ekspor Rekapitulasi per Pengurus
        $sql = "SELECT p.id, p.nip, p.nama, p.jabatan,
                       COUNT(DISTINCT a.tanggal) AS hari_hadir,
                       COUNT(a.id) AS total_sesi,
                       COALESCE(SUM(a.durasi_menit), 0) AS total_durasi_menit
                FROM `pengurus` p
                LEFT JOIN `absensi_pengurus` a ON a.pengurus_id = p.id AND a.tanggal LIKE :bulan
                WHERE p.aktif = 1 AND p.deleted_at IS NULL
                GROUP BY p.id
                ORDER BY p.urutan ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['bulan' => $bulan . '%']);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rekapitulasi_presensi_' . $bulan . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['No', 'NIP', 'Nama Pengurus', 'Jabatan', 'Hari Hadir', 'Total Sesi', 'Total Durasi Kerja', 'Total Menit', 'Rata-rata Durasi/Hari'], ';');

        foreach ($rows as $idx => $r) {
            $menit = (int)$r['total_durasi_menit'];
            $hari = (int)$r['hari_hadir'];
            $rataMenit = $hari > 0 ? (int)round($menit / $hari) : 0;
            fputcsv($fp, [
                $idx + 1,
                $r['nip'] ?? '-',
                $r['nama'],
                $r['jabatan'],
                $hari,
                $r['total_sesi'],
                fmtDuration($menit),
                $menit,
                fmtDuration($rataMenit)
            ], ';');
        }
        fclose($fp);
        exit;

    } elseif ($action === 'export_detail') {
        // Ekspor Seluruh Baris Sesi Presensi
        $sql = "SELECT a.tanggal, a.sesi_ke, p.nip, p.nama, p.jabatan,
                       a.waktu_masuk, a.waktu_pulang, a.status, a.durasi_menit,
                       a.metode_masuk, a.metode_pulang, a.ip_address, a.catatan
                FROM `absensi_pengurus` a
                JOIN `pengurus` p ON p.id = a.pengurus_id
                WHERE a.tanggal LIKE :bulan
                ORDER BY a.tanggal ASC, p.urutan ASC, a.sesi_ke ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['bulan' => $bulan . '%']);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="log_sesi_presensi_' . $bulan . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Tanggal', 'Sesi', 'NIP', 'Nama Pengurus', 'Jabatan', 'Jam Masuk', 'Jam Pulang', 'Status', 'Durasi Kerja', 'Durasi Menit', 'Metode Masuk', 'Metode Pulang', 'Catatan'], ';');

        foreach ($rows as $r) {
            $dm = (int)($r['durasi_menit'] ?? 0);
            fputcsv($fp, [
                $r['tanggal'],
                'Sesi ' . $r['sesi_ke'],
                $r['nip'] ?? '-',
                $r['nama'],
                $r['jabatan'],
                substr($r['waktu_masuk'] ?: '—', 0, 5),
                $r['waktu_pulang'] ? substr($r['waktu_pulang'], 0, 5) : '—',
                strtoupper($r['status']),
                $r['status'] === 'pulang' ? fmtDuration($dm) : 'Sedang Masuk',
                $dm,
                $r['metode_masuk'],
                $r['metode_pulang'] ?? '-',
                $r['catatan'] ?? ''
            ], ';');
        }
        fclose($fp);
        exit;
    }
}

require_once __DIR__ . '/header.php';

$activeTab = $_GET['tab'] ?? 'harian';
$selectedDate = $_GET['tanggal'] ?? date('Y-m-d');
$selectedMonth = $_GET['bulan'] ?? date('Y-m');
$filterPengurus = (int)($_GET['pengurus_id'] ?? 0);

$message = '';
$error = '';

// Handler Form Tindakan Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'simpan_sesi') {
        $pId = (int)($_POST['pengurus_id'] ?? 0);
        $tgl = trim($_POST['tanggal'] ?? $selectedDate);
        $wMasuk = trim($_POST['waktu_masuk'] ?? '');
        $wPulang = trim($_POST['waktu_pulang'] ?? '');
        $catatan = trim($_POST['catatan'] ?? '');

        if ($pId <= 0 || empty($tgl) || empty($wMasuk)) {
            $error = 'Pengurus, tanggal, dan waktu masuk wajib diisi!';
        } else {
            if (strlen($wMasuk) === 5) $wMasuk .= ':00';
            if (!empty($wPulang) && strlen($wPulang) === 5) $wPulang .= ':00';

            // Hitung sesi_ke baru
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `absensi_pengurus` WHERE `pengurus_id` = :pid AND `tanggal` = :tgl");
            $countStmt->execute(['pid' => $pId, 'tgl' => $tgl]);
            $sesiKe = ((int) $countStmt->fetchColumn()) + 1;

            $status = 'masuk';
            $durasiMenit = null;

            if (!empty($wPulang)) {
                $status = 'pulang';
                $masukTs = strtotime($tgl . ' ' . $wMasuk);
                $pulangTs = strtotime($tgl . ' ' . $wPulang);
                $durasiMenit = max(1, (int) round(($pulangTs - $masukTs) / 60));
            }

            $sqlInsert = "INSERT INTO `absensi_pengurus`
                (`pengurus_id`, `tanggal`, `sesi_ke`, `waktu_absen`, `waktu_masuk`, `waktu_pulang`, `status`, `durasi_menit`, `metode`, `metode_masuk`, `metode_pulang`, `ip_address`, `dibuat_oleh`, `catatan`, `created_at`, `updated_at`)
                VALUES (:pid, :tgl, :sesi, :wmasuk, :wmasuk, :wpulang, :status, :durasi, 'manual', 'manual', :mpulang, :ip, :uid, :catatan, NOW(), NOW())";
            $stmt = $pdo->prepare($sqlInsert);
            $stmt->execute([
                'pid' => $pId,
                'tgl' => $tgl,
                'sesi' => $sesiKe,
                'wmasuk' => $wMasuk,
                'wpulang' => !empty($wPulang) ? $wPulang : null,
                'status' => $status,
                'durasi' => $durasiMenit,
                'mpulang' => !empty($wPulang) ? 'manual' : null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'uid' => $admin['id'],
                'catatan' => $catatan
            ]);

            // Audit Log
            $sqlAudit = "INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `ip_address`, `created_at`)
                         VALUES ('admin', :uid, 'koreksi_tambah_sesi', 'absensi_pengurus', :pid, :meta, :ip, NOW())";
            $pdo->prepare($sqlAudit)->execute([
                'uid' => $admin['id'],
                'pid' => $pId,
                'meta' => json_encode(['tanggal' => $tgl, 'sesi_ke' => $sesiKe, 'masuk' => $wMasuk, 'pulang' => $wPulang, 'alasan' => $catatan]),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $message = "Sesi ke-{$sesiKe} berhasil dicatat untuk pengurus terkait.";
        }

    } elseif ($action === 'tandai_pulang') {
        $absensiId = (int)($_POST['absensi_id'] ?? 0);
        $wPulang = trim($_POST['waktu_pulang'] ?? date('H:i'));
        if (strlen($wPulang) === 5) $wPulang .= ':00';

        $stmtS = $pdo->prepare("SELECT * FROM `absensi_pengurus` WHERE `id` = :id");
        $stmtS->execute(['id' => $absensiId]);
        $rowSesi = $stmtS->fetch();

        if ($rowSesi) {
            $masukTs = strtotime($rowSesi['tanggal'] . ' ' . $rowSesi['waktu_masuk']);
            $pulangTs = strtotime($rowSesi['tanggal'] . ' ' . $wPulang);
            $durasiMenit = max(1, (int) round(($pulangTs - $masukTs) / 60));

            $stmtUp = $pdo->prepare("UPDATE `absensi_pengurus` SET `waktu_pulang` = :wp, `status` = 'pulang', `durasi_menit` = :durasi, `metode_pulang` = 'manual', `updated_at` = NOW() WHERE `id` = :id");
            $stmtUp->execute([
                'wp' => $wPulang,
                'durasi' => $durasiMenit,
                'id' => $absensiId
            ]);

            $message = "Pengurus berhasil ditandai PULANG pada pukul " . substr($wPulang, 0, 5) . " WIB (Durasi: " . fmtDuration($durasiMenit) . ").";
        }

    } elseif ($action === 'batalkan_sesi') {
        $absensiId = (int)($_POST['absensi_id'] ?? 0);
        if ($absensiId > 0) {
            $pdo->prepare("DELETE FROM `absensi_pengurus` WHERE `id` = :id")->execute(['id' => $absensiId]);
            $message = 'Sesi presensi telah dihapus/dibatalkan.';
        }
    }
}

// Data Pengurus Aktif
$allPengurus = $pdo->query("SELECT id, nip, nama, jabatan FROM `pengurus` WHERE `aktif` = 1 AND `deleted_at` IS NULL ORDER BY `urutan` ASC")->fetchAll();

// DATA TAB 1: RIWAYAT HARIAN
$sqlHarian = "SELECT p.id, p.nip, p.nama, p.jabatan
              FROM `pengurus` p
              WHERE p.aktif = 1 AND p.deleted_at IS NULL " .
              ($filterPengurus > 0 ? "AND p.id = {$filterPengurus} " : "") .
              "ORDER BY p.urutan ASC";
$harianPengurus = $pdo->query($sqlHarian)->fetchAll();

// Ambil semua sesi pada tanggal terpilih
$stmtAllSesiTgl = $pdo->prepare("SELECT a.*, u.name AS admin_koreksi
                                 FROM `absensi_pengurus` a
                                 LEFT JOIN `users` u ON u.id = a.dibuat_oleh
                                 WHERE a.tanggal = :tgl
                                 ORDER BY a.sesi_ke ASC, a.id ASC");
$stmtAllSesiTgl->execute(['tgl' => $selectedDate]);
$rowsAllSesiTgl = $stmtAllSesiTgl->fetchAll();

$sesiMap = [];
$harianTotalDurasiSemua = 0;
$harianCountMasuk = 0;
$harianCountPulang = 0;

foreach ($rowsAllSesiTgl as $s) {
    $sesiMap[$s['pengurus_id']][] = $s;
    if ($s['status'] === 'pulang') {
        $harianTotalDurasiSemua += (int)($s['durasi_menit'] ?? 0);
    }
}

// Hitung status pengurus harian
foreach ($harianPengurus as &$p) {
    $uSesi = $sesiMap[$p['id']] ?? [];
    $p['sesi_list'] = $uSesi;
    $p['total_sesi'] = count($uSesi);
    $p['total_durasi_menit'] = 0;

    foreach ($uSesi as $us) {
        if ($us['status'] === 'pulang') {
            $p['total_durasi_menit'] += (int)($us['durasi_menit'] ?? 0);
        }
    }

    if (empty($uSesi)) {
        $p['status'] = 'belum';
    } else {
        $last = end($uSesi);
        if ($last['status'] === 'masuk' || empty($last['waktu_pulang'])) {
            $p['status'] = 'masuk';
            $p['sesi_aktif'] = $last;
            $harianCountMasuk++;
        } else {
            $p['status'] = 'pulang';
            $p['sesi_terakhir'] = $last;
            $harianCountPulang++;
        }
    }
}
unset($p);

// DATA TAB 2: REKAPITULASI BULANAN
$sqlRekap = "SELECT p.id, p.nip, p.nama, p.jabatan,
                    COUNT(DISTINCT a.tanggal) AS hari_hadir,
                    COUNT(a.id) AS total_sesi,
                    COALESCE(SUM(a.durasi_menit), 0) AS total_durasi_menit
             FROM `pengurus` p
             LEFT JOIN `absensi_pengurus` a ON a.pengurus_id = p.id AND a.tanggal LIKE :bulan
             WHERE p.aktif = 1 AND p.deleted_at IS NULL
             GROUP BY p.id
             ORDER BY p.urutan ASC";
$stmtRekap = $pdo->prepare($sqlRekap);
$stmtRekap->execute(['bulan' => $selectedMonth . '%']);
$rekapPengurus = $stmtRekap->fetchAll();

$rekapTotalJamBulan = 0;
$rekapTotalSesiBulan = 0;
$rekapTotalHariHadir = 0;
foreach ($rekapPengurus as $rp) {
    $rekapTotalJamBulan += (int)$rp['total_durasi_menit'];
    $rekapTotalSesiBulan += (int)$rp['total_sesi'];
    $rekapTotalHariHadir += (int)$rp['hari_hadir'];
}
?>

<style>
/* Styling Tab Navigasi & Bento Badges */
.nav-tabs-container {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 2px solid var(--line);
    padding-bottom: 8px;
}
.tab-btn {
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 700;
    border-radius: 12px;
    text-decoration: none;
    color: var(--muted);
    background: transparent;
    transition: all .2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.tab-btn:hover { background: rgba(0, 155, 76, 0.08); color: var(--g-dark); }
.tab-btn.active {
    background: var(--g);
    color: #fff;
    box-shadow: 0 4px 14px rgba(0, 155, 76, 0.28);
}
.badge-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
}
.badge-status.masuk { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.badge-status.pulang { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-status.belum { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

.sesi-chip {
    display: inline-block;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 3px 8px;
    font-size: 12px;
    margin: 2px 4px 2px 0;
    line-height: 1.4;
}
.sesi-chip.active {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #065f46;
}

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.kpi-card {
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 18px 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.03);
}
.kpi-card small { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; display: block; }
.kpi-card b { font-size: 24px; font-weight: 800; color: var(--ink); display: block; margin: 4px 0 2px; }
.kpi-card span { font-size: 12px; color: var(--muted); }
</style>

<!-- Switcher Pengurus vs Dosen -->
<div style="display:flex;gap:8px;margin-bottom:16px">
    <a href="absensi.php" class="btn" style="background:var(--g);color:#fff;font-weight:700">
        👥 Presensi Pengurus &amp; Tendik
    </a>
    <a href="absensi_dosen.php" class="btn btn-secondary" style="font-weight:700">
        🎓 Rekap &amp; Presensi Dosen &rarr;
    </a>
</div>

<!-- Navigasi Tab -->
<div class="nav-tabs-container">
    <a href="?tab=harian&tanggal=<?= htmlspecialchars($selectedDate) ?>" class="tab-btn <?= $activeTab === 'harian' ? 'active' : '' ?>">
        <span>📋 Riwayat Harian & Sesi</span>
    </a>
    <a href="?tab=rekapitulasi&bulan=<?= htmlspecialchars($selectedMonth) ?>" class="tab-btn <?= $activeTab === 'rekapitulasi' ? 'active' : '' ?>">
        <span>📊 Rekapitulasi & Total Durasi</span>
    </a>
</div>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($activeTab === 'harian'): ?>
    <!-- ========================================== -->
    <!-- TAB 1: RIWAYAT PRESENSI HARIAN DENGAN SESI -->
    <!-- ========================================== -->

    <!-- Ringkasan KPI Harian -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <small>Total Pengurus</small>
            <b><?= count($allPengurus) ?></b>
            <span>Master Pengurus Aktif</span>
        </div>
        <div class="kpi-card">
            <small>Sedang Hadir (Masuk)</small>
            <b style="color:var(--g)"><?= $harianCountMasuk ?></b>
            <span>Sesi Masuk Berjalan</span>
        </div>
        <div class="kpi-card">
            <small>Tercatat Pulang</small>
            <b style="color:#d97706"><?= $harianCountPulang ?></b>
            <span>Telah Menyelesaikan Sesi</span>
        </div>
        <div class="kpi-card">
            <small>Total Jam Kerja Tercatat</small>
            <b style="color:#2563eb"><?= fmtDuration($harianTotalDurasiSemua) ?></b>
            <span>Akumulasi Durasi Hari Ini</span>
        </div>
    </div>

    <!-- Filter Bar Harian -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:14px">
        <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="tab" value="harian">
            <div>
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:3px">Tanggal:</label>
                <input type="date" name="tanggal" value="<?= htmlspecialchars($selectedDate) ?>" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px">
            </div>
            <div>
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:3px">Filter Pengurus:</label>
                <select name="pengurus_id" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px">
                    <option value="0">-- Semua Pengurus --</option>
                    <?php foreach ($allPengurus as $ap): ?>
                        <option value="<?= $ap['id'] ?>" <?= $filterPengurus === (int)$ap['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ap['nama']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn" style="margin-top:18px">Tampilkan</button>
        </form>

        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button type="button" class="btn" onclick="openTambahSesiModal()">+ Tambah Sesi Manual</button>
            <a href="?action=export_detail&bulan=<?= substr($selectedDate, 0, 7) ?>" class="btn btn-secondary">
                📥 Ekspor Log Sesi (CSV)
            </a>
        </div>
    </div>

    <!-- Tabel Riwayat Presensi Harian -->
    <div class="card">
        <h3>📋 Riwayat Presensi Tanggal: <?= date('d F Y', strtotime($selectedDate)) ?></h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Dalam sehari pengurus dapat mencatat beberapa sesi masuk dan keluar (<b>pulang</b>). Total durasi harian dihitung otomatis dari akumulasi sesi.</p>

        <table>
            <thead>
                <tr>
                    <th>Pengurus & Jabatan</th>
                    <th>Status Terkini</th>
                    <th>Sesi & Jam Presensi</th>
                    <th>Total Durasi Hari Ini</th>
                    <th>Aksi Admin</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($harianPengurus as $p): ?>
                <tr>
                    <td>
                        <b><?= htmlspecialchars($p['nama']) ?></b><br>
                        <small style="color:var(--muted)"><?= htmlspecialchars($p['jabatan']) ?> · NIP: <?= htmlspecialchars($p['nip'] ?? '-') ?></small>
                    </td>
                    <td>
                        <?php if ($p['status'] === 'masuk'): ?>
                            <span class="badge-status masuk">✓ Sedang Hadir</span>
                            <small style="display:block;color:var(--muted);margin-top:3px">Masuk: <?= substr($p['sesi_aktif']['waktu_masuk'], 0, 5) ?> WIB</small>
                        <?php elseif ($p['status'] === 'pulang'): ?>
                            <span class="badge-status pulang">🚪 Pulang</span>
                            <small style="display:block;color:var(--muted);margin-top:3px">Keluar: <?= substr($p['sesi_terakhir']['waktu_pulang'], 0, 5) ?> WIB</small>
                        <?php else: ?>
                            <span class="badge-status belum">Belum Hadir</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($p['sesi_list'])): ?>
                            <span style="color:var(--muted);font-size:13px">— Belum ada sesi —</span>
                        <?php else: ?>
                            <?php foreach ($p['sesi_list'] as $s): ?>
                                <div class="sesi-chip <?= $s['status'] === 'masuk' ? 'active' : '' ?>">
                                    <b>Sesi <?= $s['sesi_ke'] ?>:</b>
                                    <?= substr($s['waktu_masuk'] ?: '—', 0, 5) ?> &rarr;
                                    <?= $s['waktu_pulang'] ? substr($s['waktu_pulang'], 0, 5) : '<b style="color:var(--g)">Aktif</b>' ?>
                                    <?php if ($s['status'] === 'pulang'): ?>
                                        <small style="color:var(--muted)">(<?= fmtDurationShort((int)$s['durasi_menit']) ?>)</small>
                                    <?php endif; ?>
                                    <?php if ($s['catatan']): ?>
                                        <span title="<?= htmlspecialchars($s['catatan']) ?>" style="cursor:help">📝</span>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;margin-left:4px" onsubmit="return confirm('Hapus sesi ke-<?= $s['sesi_ke'] ?> ini?')">
                                        <input type="hidden" name="action" value="batalkan_sesi">
                                        <input type="hidden" name="absensi_id" value="<?= $s['id'] ?>">
                                        <button type="submit" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:11px;padding:0" title="Hapus sesi ini">&times;</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['total_durasi_menit'] > 0): ?>
                            <b style="color:var(--ink);font-size:14px"><?= fmtDuration($p['total_durasi_menit']) ?></b><br>
                            <small style="color:var(--muted)"><?= $p['total_sesi'] ?> sesi selesai</small>
                        <?php elseif ($p['status'] === 'masuk'): ?>
                            <span style="color:var(--g);font-weight:600;font-size:13px">Sedang Berjalan</span>
                        <?php else: ?>
                            <span style="color:var(--muted)">0 menit</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                            <?php if ($p['status'] === 'masuk'): ?>
                                <button type="button" class="btn" style="background:#d97706;padding:4px 10px;font-size:11px" onclick="openPulangkanModal(<?= $p['sesi_aktif']['id'] ?>, '<?= addslashes($p['nama']) ?>', '<?= substr($p['sesi_aktif']['waktu_masuk'], 0, 5) ?>')">
                                    🚪 Tandai Pulang
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary" style="padding:4px 10px;font-size:11px" onclick="openTambahSesiModal(<?= $p['id'] ?>, '<?= addslashes($p['nama']) ?>')">
                                + Tambah Sesi
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <!-- ============================================== -->
    <!-- TAB 2: REKAPITULASI PRESENSI & TOTAL DURASI    -->
    <!-- ============================================== -->

    <!-- Ringkasan KPI Bulanan -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <small>Bulan Rekapitulasi</small>
            <b><?= date('F Y', strtotime($selectedMonth . '-01')) ?></b>
            <span>Periode Aktif</span>
        </div>
        <div class="kpi-card">
            <small>Total Kehadiran Pengurus</small>
            <b style="color:var(--g)"><?= $rekapTotalHariHadir ?> Kali</b>
            <span>Total Hari Masuk</span>
        </div>
        <div class="kpi-card">
            <small>Total Sesi Terlaksana</small>
            <b style="color:#d97706"><?= $rekapTotalSesiBulan ?> Sesi</b>
            <span>Multi-Sesi Harian</span>
        </div>
        <div class="kpi-card">
            <small>Akumulasi Total Jam Kerja</small>
            <b style="color:#2563eb"><?= fmtDuration($rekapTotalJamBulan) ?></b>
            <span>Seluruh Pengurus Bulan Ini</span>
        </div>
    </div>

    <!-- Filter Bar Bulanan -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:14px">
        <form method="GET" style="display:flex;gap:10px;align-items:center">
            <input type="hidden" name="tab" value="rekapitulasi">
            <div>
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:3px">Pilih Bulan:</label>
                <input type="month" name="bulan" value="<?= htmlspecialchars($selectedMonth) ?>" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px">
            </div>
            <button type="submit" class="btn" style="margin-top:18px">Tampilkan Rekap</button>
        </form>

        <div style="display:flex;gap:10px">
            <a href="?action=export_rekap&bulan=<?= htmlspecialchars($selectedMonth) ?>" class="btn">
                📥 Ekspor Rekap Bulanan (CSV)
            </a>
            <a href="?action=export_detail&bulan=<?= htmlspecialchars($selectedMonth) ?>" class="btn btn-secondary">
                📥 Ekspor Detail Log Sesi (CSV)
            </a>
        </div>
    </div>

    <!-- Tabel Rekapitulasi Bulanan -->
    <div class="card">
        <h3>📊 Rekapitulasi Presensi & Akumulasi Durasi Kerja Bulan: <?= date('F Y', strtotime($selectedMonth . '-01')) ?></h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Tabel di bawah merangkum total hari kehadiran, total sesi, akumulasi jam kerja, dan rata-rata durasi kerja per hari bagi setiap pengurus.</p>

        <table>
            <thead>
                <tr>
                    <th style="width:40px">No</th>
                    <th>Nama Pengurus & NIP</th>
                    <th>Jabatan</th>
                    <th>Hari Hadir</th>
                    <th>Total Sesi</th>
                    <th>Total Akumulasi Durasi</th>
                    <th>Rata-rata Durasi / Hari</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rekapPengurus as $idx => $r):
                    $tMenit = (int)$r['total_durasi_menit'];
                    $hHadir = (int)$r['hari_hadir'];
                    $rataMenit = $hHadir > 0 ? (int)round($tMenit / $hHadir) : 0;
                ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td>
                        <b><?= htmlspecialchars($r['nama']) ?></b><br>
                        <small style="color:var(--muted)">NIP: <?= htmlspecialchars($r['nip'] ?? '-') ?></small>
                    </td>
                    <td><?= htmlspecialchars($r['jabatan']) ?></td>
                    <td>
                        <span class="badge-status <?= $hHadir > 0 ? 'masuk' : 'belum' ?>">
                            <?= $hHadir ?> Hari Hadir
                        </span>
                    </td>
                    <td><b><?= $r['total_sesi'] ?></b> sesi</td>
                    <td>
                        <b style="color:var(--g-dark);font-size:14px"><?= fmtDuration($tMenit) ?></b>
                        <small style="display:block;color:var(--muted)">(<?= $tMenit ?> menit)</small>
                    </td>
                    <td>
                        <b><?= fmtDuration($rataMenit) ?></b> / hari
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<!-- MODAL / FORM TAMBAH SESI MANUAL -->
<div id="modalTambahSesi" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:20px;padding:26px;width:100%;max-width:520px;box-shadow:0 10px 40px rgba(0,0,0,0.2)">
        <h3 style="margin-bottom:8px">✍️ Form Presensi / Tambah Sesi Manual</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px">Gunakan form ini untuk mencatat sesi presensi pengurus secara manual jika terdapat kendala scan. Durasi sesi akan dihitung secara otomatis.</p>

        <form method="POST">
            <input type="hidden" name="action" value="simpan_sesi">
            <div class="form-group" style="margin-bottom:14px">
                <label style="font-size:13px;font-weight:700">Pengurus</label>
                <select name="pengurus_id" id="modalPengurusSelect" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;font-size:14px">
                    <option value="">-- Pilih Pengurus --</option>
                    <?php foreach ($allPengurus as $ap): ?>
                        <option value="<?= $ap['id'] ?>"><?= htmlspecialchars($ap['nama']) ?> (<?= htmlspecialchars($ap['jabatan']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:14px">
                <label style="font-size:13px;font-weight:700">Tanggal</label>
                <input type="date" name="tanggal" value="<?= htmlspecialchars($selectedDate) ?>" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;font-size:14px">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                <div>
                    <label style="font-size:13px;font-weight:700">Jam Masuk (WIB)</label>
                    <input type="time" name="waktu_masuk" value="<?= date('H:i') ?>" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;font-size:14px">
                </div>
                <div>
                    <label style="font-size:13px;font-weight:700">Jam Pulang (Opsional)</label>
                    <input type="time" name="waktu_pulang" placeholder="Kosongkan bila masih masuk" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;font-size:14px">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:18px">
                <label style="font-size:13px;font-weight:700">Alasan / Catatan Koreksi</label>
                <textarea name="catatan" rows="2" placeholder="Contoh: Koreksi dinas luar kota / rapat akademik" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;font-size:14px"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn btn-secondary" onclick="closeTambahSesiModal()">Batal</button>
                <button type="submit" class="btn">Simpan Sesi Presensi</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL TANDAI PULANG -->
<div id="modalPulang" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:20px;padding:26px;width:100%;max-width:440px;box-shadow:0 10px 40px rgba(0,0,0,0.2)">
        <h3 style="margin-bottom:8px">🚪 Tandai Pengurus PULANG</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px">Tandai waktu pulang untuk: <b id="pulangNama" style="color:var(--ink)">-</b> (Jam Masuk: <span id="pulangJamMasuk">-</span> WIB).</p>

        <form method="POST">
            <input type="hidden" name="action" value="tandai_pulang">
            <input type="hidden" name="absensi_id" id="pulangAbsensiId">
            <div class="form-group" style="margin-bottom:18px">
                <label style="font-size:13px;font-weight:700">Jam Pulang (WIB)</label>
                <input type="time" name="waktu_pulang" value="<?= date('H:i') ?>" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;font-size:14px">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn btn-secondary" onclick="closePulangkanModal()">Batal</button>
                <button type="submit" class="btn" style="background:#d97706">Simpan Presensi Pulang</button>
            </div>
        </form>
    </div>
</div>

<script>
function openTambahSesiModal(pId, pName) {
    const modal = document.getElementById('modalTambahSesi');
    modal.style.display = 'flex';
    if (pId) {
        document.getElementById('modalPengurusSelect').value = pId;
    }
}

function closeTambahSesiModal() {
    document.getElementById('modalTambahSesi').style.display = 'none';
}

function openPulangkanModal(absensiId, pName, jamMasuk) {
    document.getElementById('pulangAbsensiId').value = absensiId;
    document.getElementById('pulangNama').textContent = pName;
    document.getElementById('pulangJamMasuk').textContent = jamMasuk;
    document.getElementById('modalPulang').style.display = 'flex';
}

function closePulangkanModal() {
    document.getElementById('modalPulang').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
