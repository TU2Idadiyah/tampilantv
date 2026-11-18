<?php
/**
 * POST /api/v1/dosen/konfirmasi.php - Simpan Konfirmasi Kehadiran Dosen
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/helper.php';

$dosen = getActiveDosen();
if (!$dosen) {
    http_response_code(401);
    echo json_encode(['success' => false, 'pesan' => 'Sesi dosen belum teridentifikasi. Silakan masuk terlebih dahulu.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'pesan' => 'Metode request harus POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$jadwalId = (int) ($input['jadwal_id'] ?? 0);
$status = trim($input['status'] ?? '');
$mode = trim($input['mode'] ?? '');
$jamGanti = trim($input['jam_ganti'] ?? '');
$keterangan = trim($input['keterangan'] ?? '');

if (!in_array($status, ['hadir', 'tidak_hadir', 'tukar_jam'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'pesan' => 'Pilihan status tidak valid. Pilih: hadir, tidak_hadir, atau tukar_jam.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($status === 'hadir') {
    if (!in_array($mode, ['offline', 'online'], true)) {
        $mode = 'offline';
    }
} else {
    $mode = null;
}

$pdo = getDosenPdo();
$today = date('Y-m-d');
$waktuSekarang = date('H:i:s');
$dosenId = (int) $dosen['id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Daftar jadwal yang akan di-update
$targetJadwalIds = [];
if ($jadwalId > 0) {
    $targetJadwalIds[] = $jadwalId;
} else {
    // Ambil seluruh jadwal dosen hari ini
    $dayOfWeek = (int) date('N');
    $stmtAll = $pdo->prepare("SELECT id FROM `jadwal_kuliah` WHERE dosen_id = :did AND hari = :hari AND aktif = 1");
    $stmtAll->execute(['did' => $dosenId, 'hari' => $dayOfWeek]);
    $targetJadwalIds = $stmtAll->fetchAll(PDO::FETCH_COLUMN);
    if (empty($targetJadwalIds)) {
        $targetJadwalIds = [0];
    }
}

$stmtCek = $pdo->prepare(
    "SELECT `id` FROM `kehadiran_dosen` WHERE `dosen_id` = :did AND `jadwal_id` = :jid AND `tanggal` = :tgl LIMIT 1"
);
$stmtUpd = $pdo->prepare(
    "UPDATE `kehadiran_dosen`
     SET `status` = :status, `mode` = :mode, `jam_ganti` = :jam_ganti, `keterangan` = :ket,
         `waktu_absen` = :waktu, `metode` = 'portal_dosen', `ip_address` = :ip, `updated_at` = NOW()
     WHERE `id` = :id"
);
$stmtIns = $pdo->prepare(
    "INSERT INTO `kehadiran_dosen`
        (`dosen_id`, `jadwal_id`, `tanggal`, `status`, `mode`, `jam_ganti`, `keterangan`, `waktu_absen`, `metode`, `ip_address`, `created_at`, `updated_at`)
     VALUES
        (:did, :jid, :tgl, :status, :mode, :jam_ganti, :ket, :waktu, 'portal_dosen', :ip, NOW(), NOW())"
);

// Rekam juga level dosen (jadwal_id = 0) agar kompatibel
$allTargetJids = array_unique(array_merge(array_map('intval', $targetJadwalIds), [0]));

foreach ($allTargetJids as $tJid) {
    $stmtCek->execute(['did' => $dosenId, 'jid' => $tJid, 'tgl' => $today]);
    $existId = $stmtCek->fetchColumn();

    if ($existId) {
        $stmtUpd->execute([
            'status' => $status,
            'mode' => $mode,
            'jam_ganti' => $jamGanti ?: null,
            'ket' => $keterangan ?: null,
            'waktu' => $waktuSekarang,
            'ip' => $ip,
            'id' => $existId
        ]);
    } else {
        $stmtIns->execute([
            'did' => $dosenId,
            'jid' => $tJid,
            'tgl' => $today,
            'status' => $status,
            'mode' => $mode,
            'jam_ganti' => $jamGanti ?: null,
            'ket' => $keterangan ?: null,
            'waktu' => $waktuSekarang,
            'ip' => $ip
        ]);
    }
}

// Label status untuk pesan
$statusLabel = match($status) {
    'hadir' => ($mode === 'online' ? 'Hadir Online (Daring)' : 'Hadir Offline (Tatap Muka)'),
    'tukar_jam' => 'Tukar Jam' . ($jamGanti ? ' (' . $jamGanti . ')' : ''),
    'tidak_hadir' => 'Tidak Hadir' . ($keterangan ? ' (' . $keterangan . ')' : ''),
    default => $status
};

// Log audit
try {
    $meta = json_encode([
        'status' => $status,
        'mode' => $mode,
        'jam_ganti' => $jamGanti,
        'keterangan' => $keterangan,
        'target_jadwal_ids' => $targetJadwalIds,
        'waktu' => $waktuSekarang
    ]);
    $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `ip_address`, `created_at`)
                   VALUES ('dosen', :did, 'konfirmasi_kehadiran', 'kehadiran_dosen', :did, :meta, :ip, NOW())")
        ->execute([
            'did' => $dosenId,
            'meta' => $meta,
            'ip' => $ip
        ]);
} catch (Exception $e) {}

echo json_encode([
    'success' => true,
    'pesan' => "Status kehadiran berhasil disimpan sebagai '{$statusLabel}'. Data langsung ditampilkan di layar TV kampus.",
    'data' => [
        'dosen_id' => $dosenId,
        'tanggal' => $today,
        'status' => $status,
        'mode' => $mode,
        'status_label' => $statusLabel,
        'jam_ganti' => $jamGanti,
        'keterangan' => $keterangan,
        'waktu_konfirmasi' => substr($waktuSekarang, 0, 5)
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
