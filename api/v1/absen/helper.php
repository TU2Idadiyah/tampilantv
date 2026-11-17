<?php
/**
 * helper.php - Helper & Layanan Validasi Presensi Mobile
 * Terkoneksi ke MySQL stai_display
 */

require_once dirname(__DIR__, 3) . '/config/database.php';

date_default_timezone_set('Asia/Jakarta');

function getPdo(): PDO {
    return getDatabasePdo();
}

function getAbsenPdo(): PDO {
    return getDatabasePdo();
}

function formatDurationText(int $menit): string {
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

function formatDurationTextShort(int $menit): string {
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

function getQrSecret(): string {
    return env('QR_SECRET', 'stai_sidogiri_secret_key_2026');
}

function isAllowedSubnet(string $ip): bool {
    return isNetworkAllowed($ip, getPdo());
}

function getActivePengurusFromCookie(): ?array {
    if (empty($_COOKIE['stai_device_token'])) {
        return null;
    }

    $rawToken = $_COOKIE['stai_device_token'];
    $tokenHash = hash('sha256', $rawToken);

    try {
        $pdo = getPdo();
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');

        // 1. Ambil Perangkat dan Pengurus
        $sql = "SELECT dev.id AS perangkat_id, dev.label, dev.kedaluwarsa,
                       p.id AS pengurus_id, p.nip, p.nama, p.jabatan, p.foto_path
                FROM `perangkat_pengurus` dev
                JOIN `pengurus` p ON p.id = dev.pengurus_id AND p.aktif = 1 AND p.deleted_at IS NULL
                WHERE dev.token_hash = :hash AND dev.dicabut_pada IS NULL
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();

        if ($row && strtotime($row['kedaluwarsa']) >= time()) {
            $pId = (int) $row['pengurus_id'];

            // 2. Ambil Semua Sesi Hari Ini
            $stmtSessions = $pdo->prepare("SELECT * FROM `absensi_pengurus` WHERE `pengurus_id` = :pid AND `tanggal` = :today ORDER BY `sesi_ke` ASC, `id` ASC");
            $stmtSessions->execute(['pid' => $pId, 'today' => $today]);
            $todaySessions = $stmtSessions->fetchAll();

            $status = 'belum';
            $waktuAbsen = null;
            $waktuMasuk = null;
            $waktuPulang = null;
            $sesiAktifKe = null;
            $totalDurasiHariIni = 0;

            $sessionsFormatted = [];
            foreach ($todaySessions as $s) {
                $masuk = substr($s['waktu_masuk'] ?: $s['waktu_absen'], 0, 5);
                $pulang = $s['waktu_pulang'] ? substr($s['waktu_pulang'], 0, 5) : null;
                $durasi = (int) ($s['durasi_menit'] ?? 0);
                if ($s['status'] === 'pulang') {
                    $totalDurasiHariIni += $durasi;
                }

                $sessionsFormatted[] = [
                    'id' => (int) $s['id'],
                    'sesi_ke' => (int) $s['sesi_ke'],
                    'waktu_masuk' => $masuk,
                    'waktu_pulang' => $pulang,
                    'status' => $s['status'],
                    'durasi_menit' => $durasi,
                    'durasi_formatted' => formatDurationText($durasi),
                    'durasi_short' => formatDurationTextShort($durasi),
                    'metode' => $s['metode']
                ];
            }

            if (!empty($todaySessions)) {
                $lastS = end($todaySessions);
                $sesiAktifKe = (int) $lastS['sesi_ke'];
                $waktuMasuk = substr($lastS['waktu_masuk'] ?: $lastS['waktu_absen'], 0, 5);
                $waktuPulang = $lastS['waktu_pulang'] ? substr($lastS['waktu_pulang'], 0, 5) : null;

                if ($lastS['status'] === 'masuk' || empty($lastS['waktu_pulang'])) {
                    $status = 'masuk';
                    $waktuAbsen = $waktuMasuk;
                } else {
                    $status = 'pulang';
                    $waktuAbsen = $waktuPulang;
                }
            }

            // 3. Rekapitulasi Bulan Berjalan
            $stmtRekap = $pdo->prepare("SELECT COUNT(DISTINCT `tanggal`) AS total_hari, COUNT(*) AS total_sesi, COALESCE(SUM(`durasi_menit`), 0) AS total_menit
                                       FROM `absensi_pengurus`
                                       WHERE `pengurus_id` = :pid AND `tanggal` LIKE :bulan");
            $stmtRekap->execute(['pid' => $pId, 'bulan' => $thisMonth . '%']);
            $rekapRow = $stmtRekap->fetch();
            $blnMenit = (int) ($rekapRow['total_menit'] ?? 0);

            // 4. Riwayat 7 Hari Terakhir
            $stmtHist = $pdo->prepare("SELECT `tanggal`, COUNT(*) AS sesi_count, COALESCE(SUM(`durasi_menit`), 0) AS durasi_hari_menit
                                       FROM `absensi_pengurus`
                                       WHERE `pengurus_id` = :pid
                                       GROUP BY `tanggal`
                                       ORDER BY `tanggal` DESC
                                       LIMIT 7");
            $stmtHist->execute(['pid' => $pId]);
            $recentHistory = [];
            while ($h = $stmtHist->fetch()) {
                $dm = (int) $h['durasi_hari_menit'];
                $recentHistory[] = [
                    'tanggal' => $h['tanggal'],
                    'sesi_count' => (int) $h['sesi_count'],
                    'durasi_menit' => $dm,
                    'durasi_formatted' => formatDurationText($dm)
                ];
            }

            return [
                'pengurus' => [
                    'id' => $pId,
                    'nip' => $row['nip'],
                    'nama' => $row['nama'],
                    'jabatan' => $row['jabatan'],
                    'foto_path' => $row['foto_path'],
                    'status' => $status, // 'belum' | 'masuk' | 'pulang'
                    'hadir' => ($status === 'masuk'), // Hadir sekarang di kampus
                    'waktu_absen' => $waktuAbsen,
                    'waktu_masuk' => $waktuMasuk,
                    'waktu_pulang' => $waktuPulang,
                    'sesi_ke' => $sesiAktifKe,
                    'total_durasi_hari_ini_menit' => $totalDurasiHariIni,
                    'total_durasi_hari_ini_formatted' => formatDurationText($totalDurasiHariIni),
                    'total_durasi_hari_ini_short' => formatDurationTextShort($totalDurasiHariIni),
                    'sesi_hari_ini' => $sessionsFormatted,
                    'rekap_bulan_ini' => [
                        'bulan' => $thisMonth,
                        'total_hari' => (int) ($rekapRow['total_hari'] ?? 0),
                        'total_sesi' => (int) ($rekapRow['total_sesi'] ?? 0),
                        'total_durasi_menit' => $blnMenit,
                        'total_durasi_formatted' => formatDurationText($blnMenit),
                        'total_durasi_short' => formatDurationTextShort($blnMenit)
                    ],
                    'riwayat_terakhir' => $recentHistory
                ],
                'perangkat' => [
                    'id' => (int) $row['perangkat_id'],
                    'label' => $row['label'],
                    'kedaluwarsa' => $row['kedaluwarsa']
                ]
            ];
        }
    } catch (Exception $e) {
        return null;
    }

    return null;
}

function sendJsonResponse(int $httpCode, array $payload): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
