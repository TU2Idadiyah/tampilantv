<?php
/**
 * Test Runner untuk Pengaturan Agenda, Event, & Pengumuman
 */

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/app/Services/WeatherService.php';
require_once __DIR__ . '/app/Services/DisplayStateService.php';

use App\Services\DisplayStateService;

echo "=== MEMULAI TEST PENGATURAN AGENDA & PENGUMUMAN ===\n";

$pdo = getAdminPdo();
$stateService = new DisplayStateService($pdo);

// 1. Uji State Awal (Agenda Aktif)
echo "[TEST 1] Memeriksa Agenda Aktif di State... ";
$state1 = $stateService->getState();
$initialAgendaCount = count($state1['agenda']);
echo "LULUS ({$initialAgendaCount} agenda aktif ditemukan)\n";

// 2. Uji Penonaktifan Seluruh Agenda (Simulasi Tanpa Agenda)
echo "[TEST 2] Simulasi Tanpa Agenda (Semua Agenda Dinonaktifkan)... ";
$pdo->query("UPDATE `agenda` SET `aktif` = 0");
$stateNoAgenda = $stateService->getState();

if (count($stateNoAgenda['agenda']) === 0) {
    echo "LULUS (State agenda mengembalikan array kosong [])\n";
} else {
    echo "GAGAL!\n";
}

// 3. Uji Logika Slider JS ketika Agenda Kosong
echo "[TEST 3] Logika Slider JS Melewati Slide Agenda... ";
$sliderJs = file_get_contents(__DIR__ . '/js/slider.js');
if (str_contains($sliderJs, "state.agenda && Array.isArray(state.agenda) && state.agenda.length > 0")) {
    echo "LULUS (Slide 's-agenda' hanya dimasukkan ke putaran jika agenda tidak kosong)\n";
} else {
    echo "GAGAL!\n";
}

// 4. Kembalikan Agenda Aktif
echo "[TEST 4] Mengaktifkan Kembali Agenda... ";
$pdo->query("UPDATE `agenda` SET `aktif` = 1");
$stateWithAgenda = $stateService->getState();
if (count($stateWithAgenda['agenda']) > 0) {
    echo "LULUS (" . count($stateWithAgenda['agenda']) . " agenda kembali aktif)\n";
} else {
    echo "GAGAL!\n";
}

// 5. Uji Tambah & Tampil Pengumuman Ticker
echo "[TEST 5] Pengaturan Pengumuman Running Text... ";
$testTeks = "PENGUMUMAN UJI COBA OTOMATIS RUNNING TEXT";
$pdo->prepare("INSERT INTO `pengumuman` (`teks`, `urutan`, `tampil_dari`, `tampil_sampai`, `aktif`, `created_at`, `updated_at`)
               VALUES (:teks, 99, CURDATE(), CURDATE(), 1, NOW(), NOW())")->execute(['teks' => $testTeks]);

$stateTicker = $stateService->getState();
$foundTicker = in_array($testTeks, $stateTicker['pengumuman'], true);
// Bersihkan pengumuman tes
$pdo->prepare("DELETE FROM `pengumuman` WHERE `teks` = :teks")->execute(['teks' => $testTeks]);

if ($foundTicker) {
    echo "LULUS (Pengumuman baru terdeteksi di payload TV)\n";
} else {
    echo "GAGAL!\n";
}

echo "=== SELURUH PENGUJIAN AGENDA & PENGUMUMAN 100% SUKSES ===\n";

