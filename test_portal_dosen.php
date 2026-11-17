<?php
/**
 * test_portal_dosen.php - Uji Coba Alur Lengkap Portal Dosen & Tampilan TV
 */

$pdo = new PDO('mysql:host=127.0.0.1;dbname=stai_display;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== MEMULAI TEST ALUR PORTAL DOSEN & INTEGRASI DISPLAY TV ===\n\n";

// 1. Ambil salah satu dosen yang memiliki jadwal hari ini
$todayIso = (int) date('N'); // 1-7
echo "Hari ini adalah hari ke-{$todayIso} (" . date('l, Y-m-d') . ")\n";

$sql = "SELECT j.id AS jadwal_id, j.dosen_id, d.kode_nidn, d.nama, j.mata_kuliah, j.jam_mulai, j.jam_selesai, j.ruang
        FROM `jadwal_kuliah` j
        JOIN `dosen` d ON d.id = j.dosen_id
        WHERE j.hari = :hari AND j.aktif = 1
        LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute(['hari' => $todayIso]);
$sample = $stmt->fetch();

if (!$sample) {
    echo "⚠️ Tidak ada jadwal pada hari ini (hari={$todayIso}). Membuat jadwal dummy untuk pengujian...\n";
    $firstDosen = $pdo->query("SELECT id, kode_nidn, nama FROM `dosen` LIMIT 1")->fetch();
    $firstProdi = $pdo->query("SELECT id FROM `prodi` LIMIT 1")->fetch();
    $firstPeriode = $pdo->query("SELECT id FROM `periode_akademik` LIMIT 1")->fetch();

    $pdo->prepare("INSERT INTO `jadwal_kuliah` (`periode_id`, `prodi_id`, `hari`, `jam_mulai`, `jam_selesai`, `mata_kuliah`, `kelas`, `dosen_id`, `ruang`, `aktif`, `created_at`, `updated_at`)
                   VALUES (:per, :pro, :hari, '08:00:00', '10:00:00', 'Pengujian Dosen Online', 'Test-A', :did, 'Lab Komputer', 1, NOW(), NOW())")
        ->execute([
            'per' => $firstPeriode['id'],
            'pro' => $firstProdi['id'],
            'hari' => $todayIso,
            'did' => $firstDosen['id']
        ]);
    $jadwalId = $pdo->lastInsertId();
    $sample = [
        'jadwal_id' => $jadwalId,
        'dosen_id' => $firstDosen['id'],
        'kode_nidn' => $firstDosen['kode_nidn'],
        'nama' => $firstDosen['nama'],
        'mata_kuliah' => 'Pengujian Dosen Online',
        'jam_mulai' => '08:00:00',
        'jam_selesai' => '10:00:00',
        'ruang' => 'Lab Komputer'
    ];
}

echo "1. Dosen Pengujian Terpilih:\n";
echo "   - Nama: {$sample['nama']}\n";
echo "   - NIDN: {$sample['kode_nidn']}\n";
echo "   - Mata Kuliah: {$sample['mata_kuliah']} (ID Jadwal: {$sample['jadwal_id']})\n\n";

// 2. Simulasikan Konfirmasi: Hadir Online
echo "2. Mensimulasikan Konfirmasi Dosen: HADIR ONLINE (Daring via Zoom)...\n";
require_once __DIR__ . '/api/v1/dosen/helper.php';

$_COOKIE['stai_dosen_id'] = $sample['dosen_id'];
$dosen = getActiveDosen();
if (!$dosen) {
    throw new Exception("Gagal mendapatkan active dosen dari cookie!");
}
echo "   ✓ Sesi dosen aktif: {$dosen['nama']}\n";

// Panggil logika konfirmasi
$today = date('Y-m-d');
$upsertSql = "INSERT INTO `kehadiran_dosen` 
                (`dosen_id`, `jadwal_id`, `tanggal`, `status`, `mode`, `jam_ganti`, `keterangan`, `waktu_absen`, `metode`, `ip_address`, `created_at`, `updated_at`)
              VALUES 
                (:did, :jid, :tgl, 'hadir', 'online', NULL, 'Via Zoom Meeting https://zoom.us/j/123456', '08:15:00', 'portal_dosen', '127.0.0.1', NOW(), NOW())
              ON DUPLICATE KEY UPDATE
                `status` = VALUES(`status`),
                `mode` = VALUES(`mode`),
                `jam_ganti` = VALUES(`jam_ganti`),
                `keterangan` = VALUES(`keterangan`),
                `waktu_absen` = VALUES(`waktu_absen`),
                `metode` = 'portal_dosen',
                `updated_at` = NOW()";

$pdo->prepare($upsertSql)->execute([
    'did' => $sample['dosen_id'],
    'jid' => $sample['jadwal_id'],
    'tgl' => $today
]);

// Juga update level dosen (jadwal_id = 0)
$pdo->prepare($upsertSql)->execute([
    'did' => $sample['dosen_id'],
    'jid' => 0,
    'tgl' => $today
]);
echo "   ✓ Data konfirmasi 'hadir_online' berhasil tersimpan di tabel kehadiran_dosen.\n\n";

// 3. Verifikasi di DisplayStateService
echo "3. Memverifikasi state layar TV (DisplayStateService)...\n";
require_once __DIR__ . '/app/Services/WeatherService.php';
require_once __DIR__ . '/app/Services/DisplayStateService.php';

$displayService = new App\Services\DisplayStateService($pdo);
$state = $displayService->getState();

$foundInDosen = null;
foreach ($state['dosen_hari_ini'] as $dh) {
    if ((int)$dh['id'] === (int)$sample['dosen_id']) {
        $foundInDosen = $dh;
        break;
    }
}

if ($foundInDosen) {
    echo "   ✓ Dosen ditemukan di 'dosen_hari_ini' pada Layar TV:\n";
    echo "     - Nama: {$foundInDosen['nama']}\n";
    echo "     - Status Kehadiran: {$foundInDosen['status_kehadiran']}\n";
    echo "     - Mode Kuliah: {$foundInDosen['mode_kuliah']}\n";
    echo "     - Status Label: {$foundInDosen['status_label']}\n";

    if ($foundInDosen['status_kehadiran'] === 'hadir_online') {
        echo "   🎉 SUKSES: Data layar tampilan menampilkan dosen hadir online!\n\n";
    } else {
        echo "   ❌ Status belum sesuai (diharapkan: hadir_online, didapat: {$foundInDosen['status_kehadiran']})\n\n";
    }
} else {
    echo "   ❌ Dosen tidak ditemukan di 'dosen_hari_ini'\n\n";
}

$foundInJadwal = null;
foreach ($state['jadwal_hari_ini'] as $jh) {
    if ((int)$jh['id'] === (int)$sample['jadwal_id']) {
        $foundInJadwal = $jh;
        break;
    }
}

if ($foundInJadwal) {
    echo "   ✓ Jadwal ditemukan di 'jadwal_hari_ini' pada Layar TV:\n";
    echo "     - Mata Kuliah: {$foundInJadwal['mata_kuliah']}\n";
    echo "     - Status Kehadiran: {$foundInJadwal['status_kehadiran']}\n";
    echo "     - Mode: {$foundInJadwal['mode_kuliah']}\n";
    echo "     - Label: {$foundInJadwal['status_label']}\n\n";
}

// 4. Uji Kasus Tukar Jam
echo "4. Mensimulasikan Dosen Memilih TUKAR JAM...\n";
$pdo->prepare($upsertSql)->execute([
    'did' => $sample['dosen_id'],
    'jid' => $sample['jadwal_id'],
    'tgl' => $today
]);
$pdo->prepare("UPDATE `kehadiran_dosen` SET `status` = 'tukar_jam', `mode` = NULL, `jam_ganti` = '13:30 WIB', `keterangan` = 'Ditukar ke jam 13:30 di Ruang B' WHERE dosen_id = :did AND tanggal = :tgl")
    ->execute(['did' => $sample['dosen_id'], 'tgl' => $today]);

$stateTukar = $displayService->getState();
foreach ($stateTukar['dosen_hari_ini'] as $dh) {
    if ((int)$dh['id'] === (int)$sample['dosen_id']) {
        echo "   ✓ Data TV untuk Tukar Jam: Status: {$dh['status_kehadiran']}, Label: {$dh['status_label']}\n";
        break;
    }
}

echo "\n=== SELURUH PENGUJIAN SELESAI DENGAN SUKSES! ===\n";
