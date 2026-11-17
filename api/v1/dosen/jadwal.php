<?php
/**
 * GET /api/v1/dosen/jadwal.php - Data Jadwal & Notifikasi Dosen
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

$pdo = getDosenPdo();
$today = date('Y-m-d');
$dosenId = (int) $dosen['id'];

$namaHariIndo = [
    1 => 'Senin',
    2 => 'Selasa',
    3 => 'Rabu',
    4 => 'Kamis',
    5 => 'Jumat',
    6 => 'Sabtu',
    7 => 'Minggu'
];
$dayOfWeek = (int) date('N');
$namaHari = $namaHariIndo[$dayOfWeek] ?? 'Hari Ini';

$jadwalHariIni = getDosenJadwalHariIni($pdo, $dosenId, $today);
$jadwalMingguan = getDosenWeeklyJadwal($pdo, $dosenId);

$totalJadwalHariIni = count($jadwalHariIni);
$sudahKonfirmasi = 0;
$belumKonfirmasi = 0;

foreach ($jadwalHariIni as $j) {
    if (!empty($j['konfirmasi'])) {
        $sudahKonfirmasi++;
    } else {
        $belumKonfirmasi++;
    }
}

// Notifikasi Jadwal Hari Ini
$hasJadwalToday = $totalJadwalHariIni > 0;
$notifPayload = null;

if ($hasJadwalToday) {
    $mataKuliahList = array_map(fn($item) => $item['mata_kuliah'] . ' (' . $item['jam_mulai'] . ' WIB)', $jadwalHariIni);
    $notifTitle = "🔔 Jadwal Mengajar Hari Ini ({$namaHari})";
    $notifBody = "Anda memiliki {$totalJadwalHariIni} perkuliahan hari ini: " . implode(', ', $mataKuliahList) . ". Silakan tentukan status kehadiran Anda (Hadir Offline, Online, atau Tukar Jam).";

    $notifPayload = [
        'has_jadwal' => true,
        'title' => $notifTitle,
        'body' => $notifBody,
        'count' => $totalJadwalHariIni,
        'belum_konfirmasi' => $belumKonfirmasi,
        'perlu_tindakan' => ($belumKonfirmasi > 0)
    ];
} else {
    $notifPayload = [
        'has_jadwal' => false,
        'title' => "Tidak Ada Jadwal Mengajar",
        'body' => "Hari ini ({$namaHari}) tidak ada perkuliahan aktif untuk Anda.",
        'count' => 0,
        'belum_konfirmasi' => 0,
        'perlu_tindakan' => false
    ];
}

// Rekap & Riwayat Presensi Dosen
$bulanIni = date('Y-m');
$rekapBulanan = getDosenRekapBulanan($pdo, $dosenId, $bulanIni);
$riwayatPresensi = getDosenRiwayatPresensi($pdo, $dosenId, 30);

echo json_encode([
    'success' => true,
    'dosen' => [
        'id' => $dosen['id'],
        'kode_nidn' => $dosen['kode_nidn'],
        'nama' => $dosen['nama'],
        'foto_url' => $dosen['foto_url']
    ],
    'tanggal' => $today,
    'bulan' => $bulanIni,
    'hari_nama' => $namaHari,
    'jam_sekarang' => date('H:i'),
    'has_jadwal_today' => $hasJadwalToday,
    'notifikasi' => $notifPayload,
    'jadwal_hari_ini' => $jadwalHariIni,
    'jadwal_mingguan' => $jadwalMingguan,
    'rekap_bulanan' => $rekapBulanan,
    'riwayat_presensi' => $riwayatPresensi,
    'ringkasan' => [
        'total' => $totalJadwalHariIni,
        'sudah_konfirmasi' => $sudahKonfirmasi,
        'belum_konfirmasi' => $belumKonfirmasi
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
