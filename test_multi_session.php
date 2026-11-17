<?php
/**
 * Test Suite Multi-Sesi Presensi, Otomatisasi Durasi, dan Status Pulang
 */

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/api/v1/absen/helper.php';
require_once __DIR__ . '/app/Services/QrTokenService.php';
require_once __DIR__ . '/app/Services/WeatherService.php';
require_once __DIR__ . '/app/Actions/Absensi/CatatAbsensiAction.php';
require_once __DIR__ . '/app/Services/DisplayStateService.php';

use App\Actions\Absensi\CatatAbsensiAction;
use App\Services\QrTokenService;
use App\Services\DisplayStateService;

$pdo = getPdo();
$today = date('Y-m-d');
$secret = getQrSecret();
$qrService = new QrTokenService();

echo "=== MEMULAI TEST SUITE MULTI-SESI & DURASI OTOMATIS ===\n";

// Target Pengurus ID 2 (Ust. Muhammad Rizqi, M.H)
$testPengurusId = 2;

// 1. Bersihkan absensi pengurus ID 2 hari ini untuk test murni
$pdo->prepare("DELETE FROM `absensi_pengurus` WHERE `pengurus_id` = :pid AND `tanggal` = :today")
    ->execute(['pid' => $testPengurusId, 'today' => $today]);

// 2. Pastikan ada token perangkat terdaftar untuk pengurus ID 2
$rawToken = "test_device_token_pengurus_2_" . time();
$tokenHash = hash('sha256', $rawToken);

$pdo->prepare("INSERT INTO `perangkat_pengurus`
    (`pengurus_id`, `token_hash`, `label`, `terakhir_dipakai`, `kedaluwarsa`, `created_at`, `updated_at`)
    VALUES (:pid, :hash, 'HP Test Pengurus 2', NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY), NOW(), NOW())")
    ->execute(['pid' => $testPengurusId, 'hash' => $tokenHash]);

$devId = $pdo->lastInsertId();
$ipClient = '127.0.0.1';

$action = new CatatAbsensiAction($pdo, $qrService);

// TEST 1: Scan Masuk Sesi 1
echo "[TEST 1] Scan Masuk Sesi 1... ";
$qrCode1 = $qrService->generateCode($testPengurusId);
$res1 = $action->execute($qrCode1, $rawToken, $ipClient);

if ($res1['sukses'] && $res1['status'] === 'masuk' && $res1['sesi_ke'] === 1) {
    echo "LULUS! (Sesi 1 Masuk pukul {$res1['waktu_masuk']})\n";
} else {
    echo "GAGAL: " . json_encode($res1) . "\n";
    exit(1);
}

// Verifikasi database: ada 1 row status 'masuk', waktu_pulang NULL
$rowDb1 = $pdo->query("SELECT * FROM `absensi_pengurus` WHERE `pengurus_id` = {$testPengurusId} AND `tanggal` = '{$today}'")->fetchAll();
assert(count($rowDb1) === 1);
assert($rowDb1[0]['status'] === 'masuk');
assert($rowDb1[0]['waktu_pulang'] === null);

// Simulasikan waktu berlalu (misal jam masuk dibuat 2 jam yang lalu: 08:00:00)
$pdo->prepare("UPDATE `absensi_pengurus` SET `waktu_masuk` = '08:00:00', `waktu_absen` = '08:00:00' WHERE `id` = :id")
    ->execute(['id' => $rowDb1[0]['id']]);

// TEST 2: Scan Keluar / Pulang Sesi 1
echo "[TEST 2] Scan Keluar -> Harus mencatat 'PULANG' & hitung durasi otomatis... ";
$qrCode2 = $qrService->generateCode($testPengurusId);
$res2 = $action->execute($qrCode2, $rawToken, $ipClient);

if ($res2['sukses'] && $res2['status'] === 'pulang' && $res2['sesi_ke'] === 1 && !empty($res2['durasi_formatted'])) {
    echo "LULUS! (Status: PULANG, Durasi: {$res2['durasi_formatted']}, Total Hari Ini: {$res2['total_durasi_hari_ini_formatted']})\n";
} else {
    echo "GAGAL: " . json_encode($res2) . "\n";
    exit(1);
}

// Verifikasi database: row 1 status 'pulang', waktu_pulang terisi, durasi_menit terisi (> 0)
$rowDb2 = $pdo->query("SELECT * FROM `absensi_pengurus` WHERE `id` = {$rowDb1[0]['id']}")->fetch();
assert($rowDb2['status'] === 'pulang');
assert(!empty($rowDb2['waktu_pulang']));
assert((int)$rowDb2['durasi_menit'] > 0);

// Set waktu pulang Sesi 1 tetap: 11:30:00 (Durasi 210 menit = 3 jam 30 menit)
$pdo->prepare("UPDATE `absensi_pengurus` SET `waktu_pulang` = '11:30:00', `durasi_menit` = 210 WHERE `id` = :id")
    ->execute(['id' => $rowDb1[0]['id']]);

// TEST 3: Scan Masuk Kembali (Sesi 2 pada hari yang sama)
echo "[TEST 3] Scan Masuk Sesi 2 (Multi-Sesi dalam sehari)... ";
$qrCode3 = $qrService->generateCode($testPengurusId);
$res3 = $action->execute($qrCode3, $rawToken, $ipClient);

if ($res3['sukses'] && $res3['status'] === 'masuk' && $res3['sesi_ke'] === 2) {
    echo "LULUS! (Sesi 2 Masuk pukul {$res3['waktu_masuk']})\n";
} else {
    echo "GAGAL: " . json_encode($res3) . "\n";
    exit(1);
}

// Verifikasi database: sekarang ada 2 row presensi pada tanggal yang sama!
$allSessions = $pdo->query("SELECT * FROM `absensi_pengurus` WHERE `pengurus_id` = {$testPengurusId} AND `tanggal` = '{$today}' ORDER BY `sesi_ke` ASC")->fetchAll();
assert(count($allSessions) === 2);
assert($allSessions[0]['sesi_ke'] == 1 && $allSessions[0]['status'] === 'pulang');
assert($allSessions[1]['sesi_ke'] == 2 && $allSessions[1]['status'] === 'masuk');

// Set waktu masuk Sesi 2 ke 13:00:00
$pdo->prepare("UPDATE `absensi_pengurus` SET `waktu_masuk` = '13:00:00', `waktu_absen` = '13:00:00' WHERE `id` = :id")
    ->execute(['id' => $allSessions[1]['id']]);

// TEST 4: Scan Keluar / Pulang Sesi 2
echo "[TEST 4] Scan Keluar Sesi 2 -> Mencatat PULANG & Akumulasi Durasi Hari Ini... ";
$qrCode4 = $qrService->generateCode($testPengurusId);
$res4 = $action->execute($qrCode4, $rawToken, $ipClient);

if ($res4['sukses'] && $res4['status'] === 'pulang' && $res4['sesi_ke'] === 2) {
    echo "LULUS! (Sesi 2 Pulang pukul {$res4['waktu_pulang']}, Durasi Sesi 2: {$res4['durasi_formatted']})\n";
} else {
    echo "GAGAL: " . json_encode($res4) . "\n";
    exit(1);
}

// Set waktu pulang Sesi 2 ke 17:00:00 (Durasi 240 menit = 4 jam 00 menit)
// Total durasi hari ini: 210 menit + 240 menit = 450 menit (7 jam 30 menit)
$pdo->prepare("UPDATE `absensi_pengurus` SET `waktu_pulang` = '17:00:00', `durasi_menit` = 240 WHERE `id` = :id")
    ->execute(['id' => $allSessions[1]['id']]);

// TEST 5: Verifikasi DisplayStateService untuk Layar TV
echo "[TEST 5] Verifikasi State Layar TV (Status 'pulang' & Total Durasi)... ";
$displayService = new DisplayStateService($pdo);
$state = $displayService->getState();

$targetTvPengurus = null;
foreach ($state['pengurus'] as $tp) {
    if ($tp['id'] === $testPengurusId) {
        $targetTvPengurus = $tp;
        break;
    }
}

assert($targetTvPengurus !== null);
assert($targetTvPengurus['status'] === 'pulang');
assert($targetTvPengurus['total_sesi'] === 2);
assert($targetTvPengurus['total_durasi_menit'] === 450);
assert($targetTvPengurus['total_durasi_formatted'] === '7j 30m');
echo "LULUS! (TV State: Status 'pulang', Total Sesi: 2, Total Durasi: {$targetTvPengurus['total_durasi_formatted']})\n";

// TEST 6: Verifikasi Cookie / HP Profile Endpoint getActivePengurusFromCookie
echo "[TEST 6] Verifikasi Data Profil HP Pengurus (Multi-Sesi & Riwayat)... ";
$_COOKIE['stai_device_token'] = $rawToken;
$authPengurus = getActivePengurusFromCookie();

assert($authPengurus !== null);
assert($authPengurus['pengurus']['status'] === 'pulang');
assert(count($authPengurus['pengurus']['sesi_hari_ini']) === 2);
assert($authPengurus['pengurus']['total_durasi_hari_ini_menit'] === 450);
assert($authPengurus['pengurus']['total_durasi_hari_ini_formatted'] === '7 jam 30 menit');
echo "LULUS! (HP Profile: Status 'pulang', 2 Sesi Hari Ini, Total Durasi: {$authPengurus['pengurus']['total_durasi_hari_ini_formatted']})\n";

// Bersihkan perangkat test
$pdo->prepare("DELETE FROM `perangkat_pengurus` WHERE `id` = :id")->execute(['id' => $devId]);

echo "=== SEMUA TEST MULTI-SESI & DURASI OTOMATIS 100% LULUS! ===\n";
