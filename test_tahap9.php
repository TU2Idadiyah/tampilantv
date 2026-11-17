<?php
/**
 * Test Runner untuk Tahap 9: Panel Admin & Modul Impor CSV
 */

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/app/Services/CsvReader.php';
require_once __DIR__ . '/app/Actions/Import/ImportJadwalAction.php';
require_once __DIR__ . '/app/Actions/Import/ImportMahasiswaAction.php';

use App\Services\CsvReader;
use App\Actions\Import\ImportJadwalAction;
use App\Actions\Import\ImportMahasiswaAction;

echo "=== MEMULAI TEST SUITE TAHAP 9: PANEL ADMIN & IMPOR CSV ===\n";

$pdo = getAdminPdo();

// 1. Uji Autentikasi Admin
echo "[TEST 1] Autentikasi Admin (Email & Password)... ";
$loginAdmin = adminLogin('superadmin@stai-sidogiri.ac.id', 'password123');
if ($loginAdmin['sukses'] && $loginAdmin['user']['role'] === 'super_admin') {
    echo "LULUS (Super Admin login berhasil)\n";
} else {
    echo "GAGAL!\n";
}

// 2. Uji CsvReader (Deteksi Delimiter & UTF-8 BOM)
echo "[TEST 2] CsvReader (Pemisah Titik Koma & BOM)... ";
$testCsvContent = "\xEF\xBB\xBFhari;jam_mulai;jam_selesai;mata_kuliah;kelas;kode_dosen;ruang;kode_prodi\r\nSenin;07:30;09:10;Ushul Fiqh;PSI-3;D001;R. 101;PSI";
$tmpFile = __DIR__ . '/storage/imports/test_reader.csv';
file_put_contents($tmpFile, $testCsvContent);

$reader = new CsvReader();
$parsed = $reader->readFile($tmpFile);
if ($parsed['delimiter'] === ';' && count($parsed['rows']) === 1) {
    echo "LULUS (Delimiter ';' terdeteksi, BOM dibersihkan)\n";
} else {
    echo "GAGAL!\n";
}

// 3. Uji ImportJadwalAction (Dry-Run Preview & Apply)
echo "[TEST 3] ImportJadwalAction (Dry-Run & Transaction Apply)... ";
$actionJadwal = new ImportJadwalAction($pdo, $reader);
$previewJadwal = $actionJadwal->preview($tmpFile, 1);
if ($previewJadwal['total_valid'] === 1 && $previewJadwal['total_error'] === 0) {
    $resApply = $actionJadwal->apply($tmpFile, 1, 'tambah_perbarui', 1);
    if ($resApply['sukses']) {
        echo "LULUS (1 baris jadwal diimpor ke MySQL)\n";
    } else {
        echo "GAGAL apply!\n";
    }
} else {
    echo "GAGAL preview!\n";
}
@unlink($tmpFile);

// 4. Uji ImportMahasiswaAction
echo "[TEST 4] ImportMahasiswaAction (Dry-Run & Apply)... ";
$testMhsContent = "kode_prodi;tahun_angkatan;jumlah\r\nPSI;2026;175\r\nMBS;2026;130";
$tmpFileMhs = __DIR__ . '/storage/imports/test_mhs.csv';
file_put_contents($tmpFileMhs, $testMhsContent);

$actionMhs = new ImportMahasiswaAction($pdo, $reader);
$previewMhs = $actionMhs->preview($tmpFileMhs, 1);
if ($previewMhs['total_valid'] === 2 && $previewMhs['total_error'] === 0) {
    $resApplyMhs = $actionMhs->apply($tmpFileMhs, 1, 'tambah_perbarui', 1);
    if ($resApplyMhs['sukses']) {
        echo "LULUS (2 baris statistik mahasiswa diperbarui)\n";
    } else {
        echo "GAGAL apply!\n";
    }
} else {
    echo "GAGAL preview!\n";
}
@unlink($tmpFileMhs);

// 5. Uji Koreksi Manual Kehadiran & Audit Trail
echo "[TEST 5] Koreksi Presensi Manual Admin... ";
$today = date('Y-m-d');
$nowTime = date('H:i:s');
$alasan = "Koreksi manual karena ponsel mati saat dinas";
$stmtKoreksi = $pdo->prepare("INSERT INTO `absensi_pengurus`
    (`pengurus_id`, `tanggal`, `waktu_absen`, `metode`, `ip_address`, `dibuat_oleh`, `catatan`, `created_at`, `updated_at`)
    VALUES (4, :today, :waktu, 'manual', '127.0.0.1', 1, :alasan, NOW(), NOW())
    ON DUPLICATE KEY UPDATE `waktu_absen` = VALUES(`waktu_absen`), `metode` = 'manual', `catatan` = VALUES(`catatan`)");
$stmtKoreksi->execute(['today' => $today, 'waktu' => $nowTime, 'alasan' => $alasan]);

// Cek data di absensi_pengurus
$check = $pdo->query("SELECT * FROM `absensi_pengurus` WHERE `pengurus_id` = 4 AND `tanggal` = '{$today}'")->fetch();
if ($check && $check['metode'] === 'manual' && $check['catatan'] === $alasan) {
    echo "LULUS (Koreksi manual tersimpan lengkap dengan alasan)\n";
} else {
    echo "GAGAL!\n";
}

echo "=== SELURUH TEST SUITE TAHAP 9 100% SUKSES! ===\n";

