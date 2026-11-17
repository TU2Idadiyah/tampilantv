<?php
/**
 * Simulator Presensi Pengurus
 * Terkoneksi langsung ke Database MySQL stai_display
 */

require_once dirname(__DIR__, 3) . '/config/database.php';

date_default_timezone_set('Asia/Jakarta');

try {
    $pdo = getDatabasePdo();
} catch (Exception $e) {
    die("Koneksi MySQL Gagal: " . $e->getMessage());
}

$today = date('Y-m-d');
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $nowTime = date('H:i:s');

    if ($action === 'masuk' && $id > 0) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `absensi_pengurus` WHERE `pengurus_id` = :pid AND `tanggal` = :today");
        $countStmt->execute(['pid' => $id, 'today' => $today]);
        $sesiKe = ((int) $countStmt->fetchColumn()) + 1;

        $stmt = $pdo->prepare("INSERT INTO `absensi_pengurus`
            (`pengurus_id`, `tanggal`, `sesi_ke`, `waktu_absen`, `waktu_masuk`, `waktu_pulang`, `status`, `metode`, `metode_masuk`, `ip_address`, `created_at`, `updated_at`)
            VALUES (:pid, :today, :sesi, :waktu, :waktu, NULL, 'masuk', 'manual', 'manual', '127.0.0.1', NOW(), NOW())");
        $stmt->execute(['pid' => $id, 'today' => $today, 'sesi' => $sesiKe, 'waktu' => $nowTime]);

        $nameStmt = $pdo->prepare("SELECT `nama` FROM `pengurus` WHERE `id` = :id");
        $nameStmt->execute(['id' => $id]);
        $pName = $nameStmt->fetchColumn() ?: "ID $id";

        $message = "Pengurus <b>{$pName}</b> berhasil presensi <b>MASUK (Sesi {$sesiKe})</b> pada jam " . substr($nowTime, 0, 5) . " WIB!";
    } elseif ($action === 'pulang' && $id > 0) {
        // Ambil sesi terbuka terakhir
        $lastStmt = $pdo->prepare("SELECT * FROM `absensi_pengurus` WHERE `pengurus_id` = :pid AND `tanggal` = :today ORDER BY `sesi_ke` DESC, `id` DESC LIMIT 1");
        $lastStmt->execute(['pid' => $id, 'today' => $today]);
        $last = $lastStmt->fetch();

        if ($last) {
            $masukTs = strtotime($today . ' ' . $last['waktu_masuk']);
            $nowTs = strtotime($today . ' ' . $nowTime);
            $durasiMenit = max(1, (int) round(($nowTs - $masukTs) / 60));

            $upStmt = $pdo->prepare("UPDATE `absensi_pengurus` SET `waktu_pulang` = :waktu, `status` = 'pulang', `durasi_menit` = :durasi, `metode_pulang` = 'manual', `ip_pulang` = '127.0.0.1', `updated_at` = NOW() WHERE `id` = :id");
            $upStmt->execute(['waktu' => $nowTime, 'durasi' => $durasiMenit, 'id' => $last['id']]);

            $nameStmt = $pdo->prepare("SELECT `nama` FROM `pengurus` WHERE `id` = :id");
            $nameStmt->execute(['id' => $id]);
            $pName = $nameStmt->fetchColumn() ?: "ID $id";

            $jam = intdiv($durasiMenit, 60);
            $sisaM = $durasiMenit % 60;
            $durText = ($jam > 0 ? "{$jam} jam " : "") . "{$sisaM} menit";

            $message = "Pengurus <b>{$pName}</b> berhasil presensi <b>PULANG (Sesi {$last['sesi_ke']})</b> pada jam " . substr($nowTime, 0, 5) . " WIB! Durasi sesi: <b>{$durText}</b>.";
        }
    } elseif ($action === 'reset') {
        $pdo->prepare("DELETE FROM `absensi_pengurus` WHERE `tanggal` = :today")->execute(['today' => $today]);
        $message = "Seluruh status kehadiran pengurus hari ini telah di-reset ke <b>Belum Hadir</b>.";
    }
}

// Ambil daftar pengurus beserta status & sesi hari ini
$sql = "SELECT p.id, p.nip, p.nama, p.jabatan
        FROM `pengurus` p
        WHERE p.aktif = 1 AND p.deleted_at IS NULL
        ORDER BY p.urutan ASC";
$pengurusList = $pdo->query($sql)->fetchAll();

// Ambil semua sesi hari ini
$sesiStmt = $pdo->prepare("SELECT * FROM `absensi_pengurus` WHERE `tanggal` = :today ORDER BY `sesi_ke` ASC, `id` ASC");
$sesiStmt->execute(['today' => $today]);
$allSesi = $sesiStmt->fetchAll();

$sesiByPengurus = [];
foreach ($allSesi as $s) {
    $sesiByPengurus[$s['pengurus_id']][] = $s;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Simulator Presensi Pengurus (MySQL Live)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; background: #f0fdf4; color: #16261d; padding: 24px; }
        .box { max-width: 640px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
        h1 { font-size: 20px; color: #006b34; margin-bottom: 8px; }
        p { color: #62756a; font-size: 14px; margin-bottom: 20px; line-height: 1.4; }
        .msg { background: #dcfce7; color: #166534; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
        .badge { padding: 4px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .badge.ok { background: #009B4C; color: #fff; }
        .badge.pulang { background: #d97706; color: #fff; }
        .badge.off { background: #e5e7eb; color: #6b7280; }
        button { background: #009B4C; color: #fff; border: 0; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; }
        button.pulang { background: #d97706; }
        button.reset { background: #dc2626; }
        button:hover { opacity: 0.9; }
        a { color: #009B4C; text-decoration: none; font-weight: 600; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>📱 Simulator Presensi Multi-Sesi & Durasi (MySQL Live)</h1>
        <p>Terkoneksi langsung ke basis data <b>stai_display</b> di MariaDB/MySQL. Mendukung beberapa sesi dalam sehari, pencatatan <b>pulang</b>, dan <b>perhitungan durasi otomatis</b>.</p>

        <?php if ($message): ?>
            <div class="msg"><?= $message ?></div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Pengurus & NIP</th>
                    <th>Status & Sesi Hari Ini</th>
                    <th>Aksi Cepat</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pengurusList as $p):
                    $pId = (int)$p['id'];
                    $userSesi = $sesiByPengurus[$pId] ?? [];
                    $lastS = !empty($userSesi) ? end($userSesi) : null;
                    $isMasuk = $lastS && ($lastS['status'] === 'masuk' || empty($lastS['waktu_pulang']));
                    $isPulang = $lastS && $lastS['status'] === 'pulang';
                    $totalMenit = 0;
                    foreach ($userSesi as $us) {
                        if ($us['status'] === 'pulang') $totalMenit += (int)($us['durasi_menit'] ?? 0);
                    }
                    $tJam = intdiv($totalMenit, 60);
                    $tMin = $totalMenit % 60;
                    $durTotalStr = ($tJam > 0 ? "{$tJam}j " : "") . "{$tMin}m";
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($p['nama']) ?></strong><br>
                        <small style="color:#62756a"><?= htmlspecialchars($p['jabatan']) ?> · NIP: <b><?= htmlspecialchars($p['nip'] ?? '-') ?></b></small>
                    </td>
                    <td>
                        <?php if ($isMasuk): ?>
                            <span class="badge ok">✓ Masuk Sesi <?= $lastS['sesi_ke'] ?> (<?= htmlspecialchars(substr($lastS['waktu_masuk'], 0, 5)) ?>)</span>
                            <?php if ($totalMenit > 0): ?><br><small style="color:#62756a">Durasi sblm: <?= $durTotalStr ?></small><?php endif; ?>
                        <?php elseif ($isPulang): ?>
                            <span class="badge pulang">🚪 Pulang (<?= htmlspecialchars(substr($lastS['waktu_pulang'], 0, 5)) ?>)</span><br>
                            <small style="color:#62756a">Total: <?= count($userSesi) ?> sesi · <b><?= $durTotalStr ?></b></small>
                        <?php else: ?>
                            <span class="badge off">Belum Hadir</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isMasuk): ?>
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="action" value="pulang">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="pulang">Simulasi Pulang</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="action" value="masuk">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit">
                                    <?= empty($userSesi) ? 'Simulasi Masuk (Sesi 1)' : 'Masuk (Sesi ' . (count($userSesi) + 1) . ')' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap">
            <form method="POST" style="margin:0">
                <input type="hidden" name="action" value="reset">
                <button type="submit" class="reset">Reset Semua ke Belum Hadir</button>
            </form>
        </div>

        <div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:20px">
            <a href="../../index.html" target="_blank">📺 Buka Layar TV &rarr;</a>
            <a href="../../absen/index.php" target="_blank">📱 Buka Web Absen HP &rarr;</a>
        </div>
    </div>
</body>
</html>
