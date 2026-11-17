<?php

declare(strict_types=1);

namespace App\Actions\Absensi;

require_once dirname(__DIR__, 3) . '/config/database.php';

use App\Services\QrTokenService;
use Exception;
use PDO;

class CatatAbsensiAction
{
    protected PDO $pdo;
    protected QrTokenService $qrService;

    public function __construct(?PDO $pdo = null, ?QrTokenService $qrService = null)
    {
        $this->pdo = $pdo ?? getDatabasePdo();
        $this->qrService = $qrService ?? new QrTokenService();
    }

    /**
     * Mengeksekusi verifikasi 5-lapis dan mencatat absensi dalam satu transaksi database atomik
     */
    public function execute(string $qrCode, string $rawDeviceToken, string $ipAddress): array
    {
        date_default_timezone_set('Asia/Jakarta');
        $today = date('Y-m-d');
        $nowTime = date('H:i:s');

        // LAPIS 1: Validasi Subnet LAN
        if (!$this->isAllowedSubnet($ipAddress)) {
            return [
                'sukses' => false,
                'http_code' => 403,
                'kode' => 'DI_LUAR_JARINGAN',
                'pesan' => 'Presensi ditolak. HP Anda harus terhubung ke Wi-Fi kampus STAI Sidogiri.'
            ];
        }

        // LAPIS 2: Validasi Cookie & Perangkat Terdaftar
        $tokenHash = hash('sha256', $rawDeviceToken);
        $sqlDev = "SELECT dev.*, p.id AS pengurus_id, p.nama, p.jabatan, p.aktif
                   FROM `perangkat_pengurus` dev
                   JOIN `pengurus` p ON p.id = dev.pengurus_id
                   WHERE dev.token_hash = :hash AND dev.dicabut_pada IS NULL";
        $stmtDev = $this->pdo->prepare($sqlDev);
        $stmtDev->execute(['hash' => $tokenHash]);
        $device = $stmtDev->fetch();

        if (!$device) {
            return [
                'sukses' => false,
                'http_code' => 401,
                'kode' => 'BELUM_LOGIN',
                'pesan' => 'Perangkat ini belum terdaftar. Silakan login terlebih dahulu.'
            ];
        }

        if (strtotime($device['kedaluwarsa']) < time()) {
            return [
                'sukses' => false,
                'http_code' => 401,
                'kode' => 'PERANGKAT_KEDALUWARSA',
                'pesan' => 'Masa berlaku pendaftaran perangkat telah habis. Silakan login ulang.'
            ];
        }

        $pengurusId = (int) $device['pengurus_id'];

        // LAPIS 3 & 4: Validasi QR, Kepemilikan (Anti-Titip), dan Tanda Tangan HMAC
        $qrResult = $this->qrService->validateCode($qrCode, $pengurusId);
        if (!$qrResult['valid']) {
            $errorCode = $qrResult['error'];
            $pesan = match ($errorCode) {
                'PERANGKAT_TIDAK_COCOK' => 'Presensi ditolak. Kode QR ini milik pengurus lain!',
                'QR_KEDALUWARSA' => 'Kode QR sudah kedaluwarsa. Silakan scan ulang kode terbaru di layar TV.',
                default => 'Tanda tangan kode QR tidak valid atau telah dimanipulasi.'
            };

            return [
                'sukses' => false,
                'http_code' => ($errorCode === 'QR_KEDALUWARSA' ? 410 : 403),
                'kode' => $errorCode,
                'pesan' => $pesan
            ];
        }

        // LAPIS 5: Multi-Sesi Presensi & Durasi Otomatis
        $sqlLast = "SELECT * FROM `absensi_pengurus`
                    WHERE `pengurus_id` = :pid AND `tanggal` = :today
                    ORDER BY `sesi_ke` DESC, `id` DESC
                    LIMIT 1";
        $stmtLast = $this->pdo->prepare($sqlLast);
        $stmtLast->execute(['pid' => $pengurusId, 'today' => $today]);
        $lastSession = $stmtLast->fetch();

        // Tentukan apakah aksi ini MASUK atau PULANG
        $isOpen = ($lastSession && ($lastSession['status'] === 'masuk' || empty($lastSession['waktu_pulang'])));

        // EKSEKUSI TRANSAKSI DATABASE
        try {
            $this->pdo->beginTransaction();

            if ($isOpen) {
                // AKSI: PULANG (Check-Out Sesi Berjalan)
                $masukTs = strtotime($today . ' ' . $lastSession['waktu_masuk']);
                $nowTs = strtotime($today . ' ' . $nowTime);
                $durasiDetik = max(0, $nowTs - $masukTs);
                $durasiMenit = (int) round($durasiDetik / 60);

                // Anti Double-Scan Cepat (< 5 detik)
                if ($durasiDetik < 5) {
                    $this->pdo->rollBack();
                    return [
                        'sukses' => false,
                        'http_code' => 429,
                        'kode' => 'TERLALU_CEPAT',
                        'pesan' => 'Anda baru saja melakukan presensi masuk beberapa detik lalu. Tunggu sebentar sebelum scan pulang.'
                    ];
                }

                $sqlUpdate = "UPDATE `absensi_pengurus` SET
                                `waktu_pulang` = :waktu_pulang,
                                `status` = 'pulang',
                                `durasi_menit` = :durasi,
                                `metode_pulang` = 'qr',
                                `perangkat_id_pulang` = :dev_id,
                                `ip_pulang` = :ip,
                                `updated_at` = NOW()
                              WHERE `id` = :id";
                $stmtUpdate = $this->pdo->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    'waktu_pulang' => $nowTime,
                    'durasi' => $durasiMenit,
                    'dev_id' => $device['id'],
                    'ip' => $ipAddress,
                    'id' => $lastSession['id']
                ]);

                // Update Waktu Terakhir Perangkat Dipakai
                $sqlUpdateDev = "UPDATE `perangkat_pengurus` SET `terakhir_dipakai` = NOW() WHERE `id` = :dev_id";
                $this->pdo->prepare($sqlUpdateDev)->execute(['dev_id' => $device['id']]);

                // Hitung total durasi hari ini
                $sqlTot = "SELECT SUM(`durasi_menit`) AS total_menit, COUNT(*) AS total_sesi
                           FROM `absensi_pengurus`
                           WHERE `pengurus_id` = :pid AND `tanggal` = :today";
                $stmtTot = $this->pdo->prepare($sqlTot);
                $stmtTot->execute(['pid' => $pengurusId, 'today' => $today]);
                $totRow = $stmtTot->fetch();
                $totalMenit = (int)($totRow['total_menit'] ?? $durasiMenit);

                // Catat Audit Log
                $sqlAudit = "INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `ip_address`, `created_at`)
                             VALUES ('pengurus', :pid, 'absen_pulang', 'absensi_pengurus', :absensi_id, :meta, :ip, NOW())";
                $this->pdo->prepare($sqlAudit)->execute([
                    'pid' => $pengurusId,
                    'absensi_id' => $lastSession['id'],
                    'meta' => json_encode([
                        'sesi_ke' => (int)$lastSession['sesi_ke'],
                        'waktu_masuk' => $lastSession['waktu_masuk'],
                        'waktu_pulang' => $nowTime,
                        'durasi_menit' => $durasiMenit
                    ]),
                    'ip' => $ipAddress
                ]);

                $this->pdo->commit();

                return [
                    'sukses' => true,
                    'http_code' => 200,
                    'status' => 'pulang',
                    'tipe' => 'pulang',
                    'pesan' => "Alhamdulillah, presensi PULANG (Sesi ke-{$lastSession['sesi_ke']}) berhasil dicatat!",
                    'sesi_ke' => (int)$lastSession['sesi_ke'],
                    'waktu_masuk' => substr($lastSession['waktu_masuk'], 0, 5),
                    'waktu_pulang' => substr($nowTime, 0, 5),
                    'durasi_menit' => $durasiMenit,
                    'durasi_formatted' => self::formatDuration($durasiMenit),
                    'total_durasi_hari_ini_menit' => $totalMenit,
                    'total_durasi_hari_ini_formatted' => self::formatDuration($totalMenit),
                    'pengurus' => [
                        'id' => $pengurusId,
                        'nama' => $device['nama'],
                        'jabatan' => $device['jabatan'],
                        'status' => 'pulang',
                        'waktu_absen' => substr($nowTime, 0, 5),
                        'waktu_masuk' => substr($lastSession['waktu_masuk'], 0, 5),
                        'waktu_pulang' => substr($nowTime, 0, 5),
                        'durasi_formatted' => self::formatDuration($durasiMenit),
                        'total_durasi_formatted' => self::formatDuration($totalMenit)
                    ]
                ];

            } else {
                // AKSI: MASUK (Check-In Sesi Baru)
                // Cek anti double-scan dari waktu pulang terakhir
                if ($lastSession && !empty($lastSession['waktu_pulang'])) {
                    $pulangTs = strtotime($today . ' ' . $lastSession['waktu_pulang']);
                    $nowTs = strtotime($today . ' ' . $nowTime);
                    if (($nowTs - $pulangTs) < 5) {
                        $this->pdo->rollBack();
                        return [
                            'sukses' => false,
                            'http_code' => 429,
                            'kode' => 'TERLALU_CEPAT',
                            'pesan' => 'Anda baru saja melakukan presensi pulang beberapa detik lalu. Tunggu sebentar sebelum scan masuk sesi baru.'
                        ];
                    }
                }

                // Hitung nomor sesi baru
                $sqlCount = "SELECT COUNT(*) FROM `absensi_pengurus` WHERE `pengurus_id` = :pid AND `tanggal` = :today";
                $stmtCount = $this->pdo->prepare($sqlCount);
                $stmtCount->execute(['pid' => $pengurusId, 'today' => $today]);
                $sesiKe = ((int) $stmtCount->fetchColumn()) + 1;

                // 1. Simpan Absensi Masuk Baru
                $sqlInsert = "INSERT INTO `absensi_pengurus`
                              (`pengurus_id`, `tanggal`, `sesi_ke`, `waktu_absen`, `waktu_masuk`, `waktu_pulang`, `status`, `durasi_menit`, `metode`, `metode_masuk`, `perangkat_id`, `ip_address`, `created_at`, `updated_at`)
                              VALUES (:pid, :tanggal, :sesi, :waktu, :waktu, NULL, 'masuk', NULL, 'qr', 'qr', :dev_id, :ip, NOW(), NOW())";
                $stmtInsert = $this->pdo->prepare($sqlInsert);
                $stmtInsert->execute([
                    'pid' => $pengurusId,
                    'tanggal' => $today,
                    'sesi' => $sesiKe,
                    'waktu' => $nowTime,
                    'dev_id' => $device['id'],
                    'ip' => $ipAddress
                ]);
                $absensiId = (int) $this->pdo->lastInsertId();

                // 2. Update Waktu Terakhir Perangkat Dipakai
                $sqlUpdateDev = "UPDATE `perangkat_pengurus` SET `terakhir_dipakai` = NOW() WHERE `id` = :dev_id";
                $this->pdo->prepare($sqlUpdateDev)->execute(['dev_id' => $device['id']]);

                // 3. Catat Audit Log
                $sqlAudit = "INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `ip_address`, `created_at`)
                             VALUES ('pengurus', :pid, 'absen_masuk', 'absensi_pengurus', :absensi_id, :meta, :ip, NOW())";
                $this->pdo->prepare($sqlAudit)->execute([
                    'pid' => $pengurusId,
                    'absensi_id' => $absensiId,
                    'meta' => json_encode(['sesi_ke' => $sesiKe, 'waktu_masuk' => $nowTime, 'metode' => 'qr']),
                    'ip' => $ipAddress
                ]);

                $this->pdo->commit();

                return [
                    'sukses' => true,
                    'http_code' => 200,
                    'status' => 'masuk',
                    'tipe' => 'masuk',
                    'pesan' => "Alhamdulillah, presensi MASUK (Sesi ke-{$sesiKe}) berhasil dicatat!",
                    'sesi_ke' => $sesiKe,
                    'waktu_masuk' => substr($nowTime, 0, 5),
                    'pengurus' => [
                        'id' => $pengurusId,
                        'nama' => $device['nama'],
                        'jabatan' => $device['jabatan'],
                        'status' => 'masuk',
                        'waktu_absen' => substr($nowTime, 0, 5),
                        'waktu_masuk' => substr($nowTime, 0, 5),
                        'sesi_ke' => $sesiKe
                    ]
                ];
            }

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return [
                'sukses' => false,
                'http_code' => 500,
                'kode' => 'SERVER_ERROR',
                'pesan' => 'Terjadi kesalahan sistem saat menyimpan absensi: ' . $e->getMessage()
            ];
        }
    }

    public static function formatDuration(int $menit): string
    {
        if ($menit <= 0) return '0 menit';
        $jam = intdiv($menit, 60);
        $sisa = $menit % 60;
        if ($jam > 0 && $sisa > 0) {
            return "{$jam} jam {$sisa} menit";
        } elseif ($jam > 0) {
            return "{$jam} jam";
        } else {
            return "{$sisa} menit";
        }
    }

    public static function formatDurationShort(int $menit): string
    {
        if ($menit <= 0) return '0m';
        $jam = intdiv($menit, 60);
        $sisa = $menit % 60;
        if ($jam > 0 && $sisa > 0) {
            return "{$jam}j {$sisa}m";
        } elseif ($jam > 0) {
            return "{$jam}j";
        } else {
            return "{$sisa}m";
        }
    }

    protected function isAllowedSubnet(string $ip): bool
    {
        if (function_exists('isNetworkAllowed')) {
            return isNetworkAllowed($ip, $this->pdo);
        }

        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if ($parts[0] === '192' && $parts[1] === '168') return true;
            if ($parts[0] === '10') return true;
            if ($parts[0] === '172' && (int)$parts[1] >= 16 && (int)$parts[1] <= 31) return true;
        }

        return false;
    }
}

