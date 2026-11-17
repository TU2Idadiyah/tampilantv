<?php
/**
 * Endpoint: GET /api/v1/absen/saya
 * Status Kehadiran Pengurus Hari Ini di HP
 */

require_once __DIR__ . '/helper.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store');

$auth = getActivePengurusFromCookie();
if (!$auth) {
    sendJsonResponse(401, [
        "kode" => "BELUM_LOGIN",
        "pesan" => "Perangkat belum terdaftar atau sesi telah berakhir."
    ]);
}

$pengurus = $auth['pengurus'];
$perangkat = $auth['perangkat'];

sendJsonResponse(200, [
    "status" => "ok",
    "pengurus" => [
        "id" => $pengurus['id'],
        "nip" => $pengurus['nip'] ?? '-',
        "nama" => $pengurus['nama'],
        "jabatan" => $pengurus['jabatan'],
        "status" => $pengurus['status'] ?? 'belum',
        "hadir_hari_ini" => !empty($pengurus['hadir']),
        "waktu_absen" => $pengurus['waktu_absen'] ?? null,
        "waktu_masuk" => $pengurus['waktu_masuk'] ?? null,
        "waktu_pulang" => $pengurus['waktu_pulang'] ?? null,
        "sesi_ke" => $pengurus['sesi_ke'] ?? null,
        "total_durasi_hari_ini_menit" => $pengurus['total_durasi_hari_ini_menit'] ?? 0,
        "total_durasi_hari_ini_formatted" => $pengurus['total_durasi_hari_ini_formatted'] ?? '0 menit',
        "total_durasi_hari_ini_short" => $pengurus['total_durasi_hari_ini_short'] ?? '0m',
        "sesi_hari_ini" => $pengurus['sesi_hari_ini'] ?? [],
        "rekap_bulan_ini" => $pengurus['rekap_bulan_ini'] ?? null,
        "riwayat_terakhir" => $pengurus['riwayat_terakhir'] ?? []
    ],
    "perangkat" => [
        "label" => $perangkat['label'],
        "kedaluwarsa" => $perangkat['kedaluwarsa']
    ]
]);

