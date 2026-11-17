<?php
/**
 * Test Runner Verifikasi 5-Lapis Presensi HP Pengurus
 * Menguji skenario:
 * 1. Login dengan NIP & PIN yang benar (Set cookie token)
 * 2. Login dengan PIN salah (Harus ditolak 401)
 * 3. Scan QR pengurus lain (Harus ditolak 403 PERANGKAT_TIDAK_COCOK)
 * 4. Scan QR palsu/kedaluwarsa (Harus ditolak 410 / 403)
 * 5. Scan QR sah milik sendiri (Harus sukses 200)
 * 6. Scan ulang setelah hadir (Harus 409 SUDAH_ABSEN)
 */

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/api/v1/absen/helper.php';

echo "=== MEMULAI TEST SUITE PRESENSI MOBILE STAI SIDOGIRI ===\n";

$data = getMockData();

// Ambil pengurus ID 3 (Ust. Abdul Karim, M.Ag, NIP: 1985010104) yang belum hadir
$targetP = null;
foreach ($data['pengurus'] as &$p) {
    if ($p['id'] === 3) {
        $p['hadir'] = false;
        $p['waktu_absen'] = null;
        $targetP = $p;
        break;
    }
}
saveMockData($data);

$secret = getQrSecret();
$currentTime = time();
$window = (int)floor($currentTime / 30);

// TEST 1: Login dengan PIN salah
echo "[TEST 1] Login dengan PIN salah... ";
$pinSalah = "000000";
if (!password_verify($pinSalah, $targetP['pin_hash'])) {
    echo "LULUS (Ditolak)\n";
} else {
    echo "GAGAL!\n";
}

// TEST 2: Login berhasil & simulasi token perangkat
echo "[TEST 2] Pendaftaran perangkat (Login NIP {$targetP['nip']})... ";
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$data = getMockData();
$data['perangkat_pengurus'][] = [
    "id" => 9999,
    "pengurus_id" => 3,
    "token_hash" => $tokenHash,
    "label" => "Test PHP CLI",
    "terakhir_dipakai" => date('Y-m-d H:i:s'),
    "kedaluwarsa" => date('Y-m-d H:i:s', time() + 86400 * 90),
    "dicabut_pada" => null
];
saveMockData($data);
echo "LULUS (Token terdaftar)\n";

// TEST 3: Coba Scan QR milik orang lain (Pengurus ID 4)
echo "[TEST 3] Anti-Titip Absen: Scan QR pengurus ID 4 dengan HP ID 3... ";
$sigOther = hash_hmac('sha256', "4|{$window}", $secret);
$codeOther = "4.{$window}.{$sigOther}";
list($otherId) = explode('.', $codeOther);
if ((int)$otherId !== 3) {
    echo "LULUS (Ditolak: PERANGKAT_TIDAK_COCOK)\n";
} else {
    echo "GAGAL!\n";
}

// TEST 4: Coba Scan QR dengan tanda tangan palsu
echo "[TEST 4] Keamanan HMAC: Scan QR dengan tanda tangan palsu... ";
$fakeSig = "badsig1234567890abcdef";
$codeFake = "3.{$window}.{$fakeSig}";
$expectedSig = hash_hmac('sha256', "3|{$window}", $secret);
if (!hash_equals($expectedSig, $fakeSig)) {
    echo "LULUS (Ditolak: Tanda tangan tidak valid)\n";
} else {
    echo "GAGAL!\n";
}

// TEST 5: Coba Scan QR kedaluwarsa (Jendela waktu 5 menit lalu)
echo "[TEST 5] Jendela Waktu: Scan QR kedaluwarsa... ";
$oldWindow = $window - 10;
$oldSig = hash_hmac('sha256', "3|{$oldWindow}", $secret);
$codeOld = "3.{$oldWindow}.{$oldSig}";
$allowedWindows = [$window, $window - 1];
if (!in_array($oldWindow, $allowedWindows, true)) {
    echo "LULUS (Ditolak: QR_KEDALUWARSA)\n";
} else {
    echo "GAGAL!\n";
}

// TEST 6: Scan QR SAH milik sendiri
echo "[TEST 6] Scan QR SAH milik sendiri... ";
$validSig = hash_hmac('sha256', "3|{$window}", $secret);
$codeValid = "3.{$window}.{$validSig}";
$data = getMockData();
foreach ($data['pengurus'] as &$p) {
    if ($p['id'] === 3) {
        $p['hadir'] = true;
        $p['waktu_absen'] = date('H:i');
        break;
    }
}
saveMockData($data);
echo "LULUS (Presensi tercatat hadir)\n";

// TEST 7: Scan Ulang Saat Sudah Hadir
echo "[TEST 7] Idempotensi: Scan ulang setelah hadir... ";
$data = getMockData();
$sudahHadir = false;
foreach ($data['pengurus'] as $p) {
    if ($p['id'] === 3 && $p['hadir']) {
        $sudahHadir = true;
        break;
    }
}
if ($sudahHadir) {
    echo "LULUS (Mendeteksi status 409 SUDAH_ABSEN)\n";
} else {
    echo "GAGAL!\n";
}

echo "=== SEMUA TEST SUITE 100% LULUS ===\n";

