<?php

require_once __DIR__ . '/database.php';

return [
    /*
    |--------------------------------------------------------------------------
    | Konfigurasi Layar Display STAI Sidogiri
    |--------------------------------------------------------------------------
    | Mengacu pada PRD Bab 5: Seluruh angka konfigurasi kampus dipusatkan
    | di file konfigurasi ini dan tabel `pengaturan`.
    */

    'nama_kampus' => env('CAMPUS_NAME', 'STAI SIDOGIRI'),
    'kota' => env('CAMPUS_CITY', 'Pasuruan'),
    'koordinat' => [
        'latitude' => env('CAMPUS_LAT', -7.6453),
        'longitude' => env('CAMPUS_LON', 112.9075),
    ],

    // Durasi Tampilan (detik)
    'durasi' => [
        'slide' => (int) env('DISPLAY_SLIDE_SEC', 10),
        'halaman_pengurus' => (int) env('DISPLAY_PAGE_SEC', 10),
        'rotasi_qr' => (int) env('DISPLAY_QR_ROTATE_SEC', 30),
        'spot_penyorotan_ms' => 1300,
    ],

    // Keamanan QR
    'qr' => [
        'secret' => env('QR_SECRET', 'stai_sidogiri_secret_key_2026'),
        'base_url' => env('QR_BASE_URL', 'http://localhost/tampilantv/absen/scan'),
        'toleransi_jendela' => 1, // Menerima jendela saat ini dan 1 jendela sebelumnya
    ],

    // Batasan Perangkat & Jaringan
    'perangkat' => [
        'maksimal_aktif_per_pengurus' => 2,
        'masa_berlaku_hari' => 90,
    ],

    'jaringan' => [
        'allowed_subnets' => explode(',', env('ALLOWED_SUBNETS', '127.0.0.1,::1,192.168.0.0/16,10.0.0.0/8,172.16.0.0/12')),
    ],

    // Kunci Display Layar Kiosk
    'display_key' => env('DISPLAY_KEY', 'KUNCI_DEMO_2026'),
];

