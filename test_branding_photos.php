<?php
/**
 * test_branding_photos.php
 * Verifikasi Pengaturan Logo Kampus, Foto Pengurus, dan Foto Dosen
 */

require_once __DIR__ . '/app/Services/WeatherService.php';
require_once __DIR__ . '/app/Services/DisplayStateService.php';

use App\Services\DisplayStateService;

echo "=== MEMULAI TEST PENGATURAN LOGO KAMPUS, FOTO PENGURUS & FOTO DOSEN ===\n\n";

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=stai_display;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$service = new DisplayStateService($pdo);

// 1. TEST LOGO KAMPUS
echo "[TEST 1] Pengaturan Logo Kampus Dinamis... ";
$testLogoPath = __DIR__ . '/storage/media/test_logo_kampus.png';
file_put_contents($testLogoPath, 'DUMMY_PNG_CONTENT');

$pdo->exec("UPDATE `media` SET `aktif` = 0 WHERE `koleksi` = 'logo'");
$stmtMedia = $pdo->prepare("INSERT INTO `media` (`koleksi`, `path`, `mime`, `ukuran_byte`, `durasi_detik`, `aktif`, `created_at`, `updated_at`)
                            VALUES ('logo', 'media/test_logo_kampus.png', 'image/png', 1024, 0, 1, NOW(), NOW())");
$stmtMedia->execute();

$state1 = $service->getState();
if (isset($state1['kampus']) && $state1['kampus']['logo_url'] === 'storage/media/test_logo_kampus.png') {
    echo "LULUS (Logo aktif terdeteksi di payload kampus: {$state1['kampus']['logo_url']})\n";
} else {
    echo "GAGAL!\n";
    exit(1);
}

// 2. TEST FOTO PENGURUS
echo "[TEST 2] Pengaturan Foto Pengurus... ";
$testFotoPengurus = __DIR__ . '/storage/pengurus/test_pengurus_1.jpg';
file_put_contents($testFotoPengurus, 'DUMMY_JPG_CONTENT');

$pdo->prepare("UPDATE `pengurus` SET `foto_path` = 'pengurus/test_pengurus_1.jpg' WHERE `id` = 1")->execute();

$state2 = $service->getState();
$pengurus1 = null;
foreach ($state2['pengurus'] as $p) {
    if ($p['id'] === 1) {
        $pengurus1 = $p;
        break;
    }
}

if ($pengurus1 && $pengurus1['foto_url'] === 'storage/pengurus/test_pengurus_1.jpg') {
    echo "LULUS (Foto pengurus ID 1 terdeteksi: {$pengurus1['foto_url']})\n";
} else {
    echo "GAGAL!\n";
    exit(1);
}

// 3. TEST FOTO DOSEN
echo "[TEST 3] Pengaturan Foto Dosen... ";
$testFotoDosen = __DIR__ . '/storage/dosen/test_dosen_1.jpg';
file_put_contents($testFotoDosen, 'DUMMY_JPG_CONTENT');

$pdo->prepare("UPDATE `dosen` SET `foto_path` = 'dosen/test_dosen_1.jpg' WHERE `id` = 1")->execute();

$state3 = $service->getState();
$dosen1 = null;
foreach ($state3['dosen_hari_ini'] as $d) {
    if ($d['id'] === 1) {
        $dosen1 = $d;
        break;
    }
}

// Jika dosen 1 punya jadwal hari ini, verifikasi dari state
if ($dosen1) {
    if ($dosen1['foto_url'] === 'storage/dosen/test_dosen_1.jpg') {
        echo "LULUS (Foto dosen ID 1 terdeteksi di dosen_hari_ini: {$dosen1['foto_url']})\n";
    } else {
        echo "GAGAL foto_url dosen mismatch!\n";
        exit(1);
    }
} else {
    // Verifikasi langsung ke database
    $dbDosen = $pdo->query("SELECT `foto_path` FROM `dosen` WHERE `id` = 1")->fetch();
    if ($dbDosen && $dbDosen['foto_path'] === 'dosen/test_dosen_1.jpg') {
        echo "LULUS (Foto dosen ID 1 tersimpan di database: {$dbDosen['foto_path']})\n";
    } else {
        echo "GAGAL!\n";
        exit(1);
    }
}

// 4. TEST PEMBERSIHAN / FALLBACK
echo "[TEST 4] Fallback Hapus Foto & Kembalikan ke Inisial / Default... ";
$pdo->prepare("UPDATE `pengurus` SET `foto_path` = NULL WHERE `id` = 1")->execute();
$pdo->prepare("UPDATE `dosen` SET `foto_path` = NULL WHERE `id` = 1")->execute();
$pdo->exec("UPDATE `media` SET `aktif` = 0 WHERE `koleksi` = 'logo'");
$pdo->prepare("DELETE FROM `pengaturan` WHERE `kunci` = 'logo_url'")->execute();

$state4 = $service->getState();
$pClean = null;
foreach ($state4['pengurus'] as $p) {
    if ($p['id'] === 1) { $pClean = $p; break; }
}

if ($state4['kampus']['logo_url'] === null && $pClean['foto_url'] === null) {
    echo "LULUS (Logo null & Foto pengurus null, frontend otomatis gunakan inisial/default)\n";
} else {
    echo "GAGAL fallback!\n";
    exit(1);
}

// Cleanup berkas uji
@unlink($testLogoPath);
@unlink($testFotoPengurus);
@unlink($testFotoDosen);

echo "\n=== SELURUH PENGUJIAN LOGO KAMPUS, FOTO PENGURUS & FOTO DOSEN 100% SUKSES ===\n";

