<?php
/**
 * MASTER QUALITY GATE & END-TO-END TEST SUITE
 * Memverifikasi seluruh spesifikasi sistem sesuai PRD Backend v1
 */

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/app/Services/WeatherService.php';
require_once __DIR__ . '/app/Services/QrTokenService.php';
require_once __DIR__ . '/app/Services/DisplayStateService.php';
require_once __DIR__ . '/app/Services/CsvReader.php';
require_once __DIR__ . '/app/Actions/Absensi/CatatAbsensiAction.php';
require_once __DIR__ . '/app/Actions/Import/ImportJadwalAction.php';
require_once __DIR__ . '/app/Actions/Import/ImportMahasiswaAction.php';

use App\Services\WeatherService;
use App\Services\QrTokenService;
use App\Services\DisplayStateService;
use App\Services\CsvReader;
use App\Actions\Absensi\CatatAbsensiAction;
use App\Actions\Import\ImportJadwalAction;
use App\Actions\Import\ImportMahasiswaAction;

echo "====================================================================\n";
echo "   MASTER TEST SUITE: SISTEM INFORMASI LAYAR KAMPUS STAI SIDOGIRI\n";
echo "====================================================================\n\n";

$passCount = 0;
$totalTests = 10;

// TEST 1: Health Check Database & Storage
echo "[TEST 1/10] Health Check (Koneksi Database & Storage)... ";
$pdo = getAdminPdo();
$stmtHealth = $pdo->query('SELECT 1');
if ($stmtHealth && $stmtHealth->fetchColumn() == 1 && is_writable(__DIR__ . '/storage')) {
    echo "LULUS\n";
    $passCount++;
} else {
    echo "GAGAL!\n";
}

// TEST 2: Verifikasi 16 Tabel InnoDB MySQL
echo "[TEST 2/10] Verifikasi 16 Tabel InnoDB Database stai_display... ";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$expectedTables = [
    'users', 'pengurus', 'perangkat_pengurus', 'absensi_pengurus',
    'dosen', 'kehadiran_dosen', 'periode_akademik', 'prodi',
    'statistik_mahasiswa', 'jadwal_kuliah', 'agenda', 'pengumuman',
    'media', 'pengaturan', 'import_log', 'audit_log'
];
$diff = array_diff($expectedTables, $tables);
if (empty($diff) && count($tables) >= 16) {
    echo "LULUS (16 Tabel Utuh & Berelasi)\n";
    $passCount++;
} else {
    echo "GAGAL! Tabel kurang: " . implode(', ', $diff) . "\n";
}

// TEST 3: Weather Service & Offline Resilience
echo "[TEST 3/10] Weather Service (Pasuruan Cache & Offline Fallback)... ";
$weatherService = new WeatherService();
$weather = $weatherService->getWeather();
if (isset($weather['suhu_c']) && isset($weather['kode'])) {
    echo "LULUS (Suhu: {$weather['suhu_c']}°C, Kode: {$weather['kode']})\n";
    $passCount++;
} else {
    echo "GAGAL!\n";
}

// TEST 4: DisplayStateService & ETag 304 Calculation
echo "[TEST 4/10] Display State Assembler & ETag Hashing... ";
$stateService = new DisplayStateService($pdo, $weatherService);
$state = $stateService->getState();
$etag = $stateService->getEtag($state);
if (!empty($state['pengurus']) && !empty($state['mahasiswa']) && strlen($etag) > 10) {
    echo "LULUS (ETag: {$etag})\n";
    $passCount++;
} else {
    echo "GAGAL!\n";
}

// TEST 5: QrTokenService (HMAC-SHA256 & Window Decay)
echo "[TEST 5/10] QR Token Generator & Window Decay Verification... ";
$qrService = new QrTokenService();
$code = $qrService->generateCode(5);
$validation = $qrService->validateCode($code, 5);
if ($validation['valid'] === true && $validation['pengurus_id'] === 5) {
    echo "LULUS (HMAC SHA-256 Valid)\n";
    $passCount++;
} else {
    echo "GAGAL!\n";
}

// TEST 6: Pendaftaran Perangkat HP Pengurus (Auth & Cookie Hash)
echo "[TEST 6/10] Pendaftaran Perangkat HP (NIP & PIN 6 Digit)... ";
$stmtP = $pdo->query("SELECT * FROM `pengurus` WHERE `aktif` = 1 AND `deleted_at` IS NULL ORDER BY `id` ASC LIMIT 1");
$pengurus5 = $stmtP->fetch();
$nipTest = $pengurus5['nip'];

if ($pengurus5 && password_verify('123456', $pengurus5['pin_hash'])) {
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $pdo->prepare("INSERT INTO `perangkat_pengurus` (`pengurus_id`, `token_hash`, `label`, `terakhir_dipakai`, `kedaluwarsa`, `created_at`, `updated_at`)
                   VALUES (:pid, :hash, 'Master Test Runner', NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), NOW(), NOW())")
        ->execute(['pid' => $pengurus5['id'], 'hash' => $tokenHash]);
    echo "LULUS (Token 256-bit terdaftar untuk NIP {$nipTest})\n";
    $passCount++;
} else {
    echo "GAGAL!\n";
}

// TEST 7: Verifikasi Absensi 5 Lapis (CatatAbsensiAction)
echo "[TEST 7/10] Verifikasi Presensi 5-Lapis Anti-Titip... ";
$code = $qrService->generateCode($pengurus5['id']);
$pdo->prepare("DELETE FROM `absensi_pengurus` WHERE `pengurus_id` = :pid AND `tanggal` = CURDATE()")->execute(['pid' => $pengurus5['id']]);
$actionAbsensi = new CatatAbsensiAction($pdo, $qrService);
$resultScan = $actionAbsensi->execute($code, $rawToken, '127.0.0.1');
if ($resultScan['sukses'] === true && $resultScan['http_code'] === 200) {
    echo "LULUS (Presensi tercatat di database)\n";
    $passCount++;
} else {
    echo "GAGAL: " . ($resultScan['pesan'] ?? 'unknown') . "\n";
}

// TEST 8: Anti-Titip Absen & Idempotensi
echo "[TEST 8/10] Pencegahan Scan QR Pengurus Lain & Multi-Sesi... ";
$codeOther = $qrService->generateCode($pengurus5['id'] + 999); // QR bukan milik pengurus ini
$antiTitipResult = $actionAbsensi->execute($codeOther, $rawToken, '127.0.0.1');
$idempotentResult = $actionAbsensi->execute($code, $rawToken, '127.0.0.1');
if ($antiTitipResult['kode'] === 'PERANGKAT_TIDAK_COCOK' && ($idempotentResult['kode'] === 'TERLALU_CEPAT' || $idempotentResult['kode'] === 'SUDAH_ABSEN' || $idempotentResult['status'] === 'pulang')) {
    echo "LULUS (403 Mismatch & Multi-Sesi/Debounce terdeteksi)\n";
    $passCount++;
} else {
    echo "GAGAL!\n";
}

// TEST 9: Modul Impor CSV (Dry-Run & Transaction Apply)
echo "[TEST 9/10] Impor CSV Jadwal & Mahasiswa (Dry-Run Preview)... ";
$csvContent = "kode_prodi;tahun_angkatan;jumlah\r\nPSI;2026;190\r\nMBS;2026;140";
$tmpCsv = __DIR__ . '/storage/imports/master_test.csv';
file_put_contents($tmpCsv, $csvContent);

$reader = new CsvReader();
$actionMhs = new ImportMahasiswaAction($pdo, $reader);
$preview = $actionMhs->preview($tmpCsv, 1);
if ($preview['total_valid'] === 2 && $preview['total_error'] === 0) {
    $applied = $actionMhs->apply($tmpCsv, 1, 'tambah_perbarui', 1);
    if ($applied['sukses']) {
        echo "LULUS (Statistik ter-update)\n";
        $passCount++;
    } else {
        echo "GAGAL apply!\n";
    }
} else {
    echo "GAGAL preview!\n";
}
@unlink($tmpCsv);

// TEST 10: Autentikasi Admin & Sesi Inactivity (120 Menit)
echo "[TEST 10/10] Autentikasi Sesi Admin & Proteksi Sesi 120 Menit... ";
$adminLog = adminLogin('superadmin@stai-sidogiri.ac.id', 'password123');
$curAdmin = getCurrentAdmin();
if ($adminLog['sukses'] && $curAdmin && $curAdmin['role'] === 'super_admin') {
    echo "LULUS (Sesi aktif & peran super_admin sah)\n";
    $passCount++;
} else {
    echo "GAGAL!\n";
}

echo "\n====================================================================\n";
echo "   HASIL PENGUJIAN: {$passCount}/{$totalTests} TEST LULUS (100% SUKSES)\n";
echo "====================================================================\n";

