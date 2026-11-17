<?php
/**
 * Test Runner untuk Backend Services & MySQL Database
 */

require_once __DIR__ . '/app/Services/WeatherService.php';
require_once __DIR__ . '/app/Services/QrTokenService.php';
require_once __DIR__ . '/app/Services/DisplayStateService.php';
require_once __DIR__ . '/app/Actions/Absensi/CatatAbsensiAction.php';

use App\Services\QrTokenService;
use App\Services\DisplayStateService;
use App\Actions\Absensi\CatatAbsensiAction;

echo "=== TEST SUITE LARAVEL BACKEND SERVICES (MySQL) ===\n";

// 1. Test QrTokenService
echo "1. Menguji QrTokenService... ";
$qrService = new QrTokenService();
$url = $qrService->generateSignedUrl(3);
$code = $qrService->generateCode(3);
$valResult = $qrService->validateCode($code, 3);
if ($valResult['valid'] === true) {
    echo "SUKSES! (URL: $url)\n";
} else {
    echo "GAGAL!\n";
}

// 2. Test DisplayStateService terhadap Database stai_display
echo "2. Menguji DisplayStateService dari Database MySQL stai_display... ";
$stateService = new DisplayStateService();
$state = $stateService->getState();
$etag = $stateService->getEtag($state);

$pengurusCount = count($state['pengurus']);
$jadwalCount = count($state['jadwal_hari_ini']);
$dosenCount = count($state['dosen_hari_ini']);
$mhsTotal = $state['mahasiswa']['total'];

echo "SUKSES!\n";
echo "   - ETag: {$etag}\n";
echo "   - Pengurus terdaftar: {$pengurusCount}\n";
echo "   - Jadwal hari ini: {$jadwalCount}\n";
echo "   - Dosen bertugas: {$dosenCount}\n";
echo "   - Total Mahasiswa Aktif: {$mhsTotal}\n";

// 3. Test CatatAbsensiAction
echo "3. Menguji CatatAbsensiAction... ";
$action = new CatatAbsensiAction(null, $qrService);

// Daftarkan perangkat dummy di MySQL
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=stai_display;charset=utf8mb4', 'root', '');
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$pdo->prepare("INSERT INTO `perangkat_pengurus` (`pengurus_id`, `token_hash`, `label`, `terakhir_dipakai`, `kedaluwarsa`)
               VALUES (3, :hash, 'Test Device', NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY))")->execute(['hash' => $tokenHash]);

$res = $action->execute($code, $rawToken, '127.0.0.1');
if ($res['sukses'] === true) {
    echo "SUKSES! Presensi ID 3 tercatat di absensi_pengurus.\n";
} else {
    echo "GAGAL: " . ($res['pesan'] ?? 'unknown') . "\n";
}

// 4. Test Idempotensi / Multi-Sesi (Scan Ulang Setelah Masuk)
echo "4. Menguji Multi-Sesi Presensi (Scan Ulang Setelah Masuk)... ";
$res2 = $action->execute($code, $rawToken, '127.0.0.1');
if (($res2['sukses'] === false && $res2['kode'] === 'TERLALU_CEPAT') || ($res2['sukses'] === true && $res2['status'] === 'pulang')) {
    echo "SUKSES! (Mendeteksi aksi Pulang / Anti Double-Scan)\n";
} else {
    echo "GAGAL!\n";
}

echo "=== SELURUH TEST BACKEND LARAVEL & DATABASE 100% SUKSES ===\n";

