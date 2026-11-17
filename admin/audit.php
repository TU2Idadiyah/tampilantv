<?php
require_once __DIR__ . '/header.php';
$pdo = getAdminPdo();

// Ambil Audit Log
$logs = $pdo->query("SELECT * FROM `audit_log` ORDER BY `id` DESC LIMIT 50")->fetchAll();

// Cek Log Heartbeat Layar TV dari file / cache
$heartbeatFile = dirname(__DIR__) . '/api/v1/display/heartbeat.log';
$tvDisplays = [];
if (file_exists($heartbeatFile)) {
    $lines = array_reverse(array_filter(explode(PHP_EOL, file_get_contents($heartbeatFile))));
    $seenIds = [];
    foreach ($lines as $line) {
        // Format: date | Display ID: tv-xxx | Body: ...
        if (preg_match('/^([^|]+)\s*\|\s*Display ID:\s*([^\s|]+)/', $line, $matches)) {
            $ts = trim($matches[1]);
            $dId = trim($matches[2]);
            if (!isset($seenIds[$dId])) {
                $seenIds[$dId] = true;
                $diffSec = time() - strtotime($ts);
                $isOnline = $diffSec < 120;
                $tvDisplays[] = [
                    'id' => $dId,
                    'last_seen' => $ts,
                    'diff_sec' => $diffSec,
                    'online' => $isOnline
                ];
            }
        }
    }
}
?>

<div class="card" style="margin-bottom:24px">
    <h3>📺 Status Konektivitas Layar TV Kiosk</h3>
    <?php if (empty($tvDisplays)): ?>
        <p style="font-size:14px;color:var(--muted)">Belum ada layar TV yang terdeteksi mengirim heartbeat. Buka <a href="../index.html" target="_blank" style="color:var(--g)">Layar TV</a> untuk memulai koneksi.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Display ID</th>
                    <th>Status Koneksi</th>
                    <th>Terakhir Terlihat</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tvDisplays as $tv): ?>
                <tr>
                    <td><b><?= htmlspecialchars($tv['id']) ?></b></td>
                    <td>
                        <span class="badge <?= $tv['online'] ? 'ok' : 'err' ?>">
                            <?= $tv['online'] ? '● Online (Terhubung)' : '○ Terputus (> 2 menit)' ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($tv['last_seen']) ?> (<?= $tv['diff_sec'] ?> detik lalu)</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3>🛡️ Rekam Jejak Audit Sistem (Audit Log)</h3>
    <table>
        <thead>
            <tr>
                <th>Waktu</th>
                <th>Aktor</th>
                <th>Aksi</th>
                <th>Entitas</th>
                <th>IP Address</th>
                <th>Detail / Metadata</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $l): ?>
            <tr>
                <td><small><?= htmlspecialchars($l['created_at']) ?></small></td>
                <td>
                    <span class="badge <?= $l['aktor_tipe'] === 'admin' ? 'ok' : 'off' ?>">
                        <?= htmlspecialchars($l['aktor_tipe']) ?>
                    </span>
                </td>
                <td><b><?= htmlspecialchars($l['aksi']) ?></b></td>
                <td><?= htmlspecialchars($l['entitas']) ?></td>
                <td><small><?= htmlspecialchars($l['ip_address'] ?? '-') ?></small></td>
                <td><small style="font-family:monospace;color:var(--muted)"><?= htmlspecialchars($l['meta'] ?? '-') ?></small></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

