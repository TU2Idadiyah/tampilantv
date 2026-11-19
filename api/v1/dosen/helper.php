<?php
/**
 * helper.php - Helper & Layanan Portal Dosen STAI Sidogiri
 */

require_once dirname(__DIR__, 3) . '/config/database.php';

date_default_timezone_set('Asia/Jakarta');

function getDosenPdo(): PDO {
    return getDatabasePdo();
}

function getActiveDosen(): ?array {
    try {
        $pdo = getDosenPdo();
    } catch (Throwable $t) {
        return null;
    }
    $dosenId = null;

    if (!empty($_COOKIE['stai_dosen_id'])) {
        $dosenId = (int) $_COOKIE['stai_dosen_id'];
    } elseif (!empty($_SESSION['stai_dosen_id'])) {
        $dosenId = (int) $_SESSION['stai_dosen_id'];
    }

    if (!$dosenId) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, kode_nidn, nama, foto_path, no_hp, pin, aktif FROM `dosen` WHERE id = :id AND aktif = 1 AND deleted_at IS NULL LIMIT 1");
    $stmt->execute(['id' => $dosenId]);
    $dosen = $stmt->fetch();

    if ($dosen) {
        $dosen['foto_url'] = !empty($dosen['foto_path']) ? (str_starts_with($dosen['foto_path'], 'http') ? $dosen['foto_path'] : '../storage/' . ltrim($dosen['foto_path'], '/')) : null;
        return $dosen;
    }

    return null;
}

function setDosenCookie(int $dosenId): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['stai_dosen_id'] = $dosenId;
    setcookie('stai_dosen_id', (string) $dosenId, [
        'expires' => time() + (86400 * 30), // 30 hari
        'path' => '/',
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}

function clearDosenCookie(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    unset($_SESSION['stai_dosen_id']);
    setcookie('stai_dosen_id', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
}

function getDosenJadwalHariIni(PDO $pdo, int $dosenId, ?string $date = null): array {
    $today = $date ?: date('Y-m-d');
    $dayOfWeek = (int) date('N', strtotime($today)); // 1 = Senin .. 7 = Minggu

    // Ambil jadwal hari ini
    $sql = "SELECT j.id, j.jam_mulai, j.jam_selesai, j.mata_kuliah, j.kelas, j.ruang,
                   pr.nama AS prodi_nama, pa.kode AS periode_kode
            FROM `jadwal_kuliah` j
            JOIN `prodi` pr ON pr.id = j.prodi_id AND pr.aktif = 1
            JOIN `periode_akademik` pa ON pa.id = j.periode_id AND pa.aktif = 1
            WHERE j.dosen_id = :dosen_id AND j.hari = :hari AND j.aktif = 1
            ORDER BY j.jam_mulai ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['dosen_id' => $dosenId, 'hari' => $dayOfWeek]);
    $jadwalRows = $stmt->fetchAll();

    // Ambil kehadiran / konfirmasi hari ini
    $sqlKD = "SELECT * FROM `kehadiran_dosen` WHERE dosen_id = :dosen_id AND tanggal = :today";
    $stmtKD = $pdo->prepare($sqlKD);
    $stmtKD->execute(['dosen_id' => $dosenId, 'today' => $today]);
    $kdRows = $stmtKD->fetchAll();

    $kdMap = [];
    $fallbackKd = null;
    foreach ($kdRows as $kd) {
        $jId = (int) $kd['jadwal_id'];
        if ($jId > 0) {
            $kdMap[$jId] = $kd;
        } else {
            $fallbackKd = $kd;
        }
    }

    $result = [];
    foreach ($jadwalRows as $j) {
        $jId = (int) $j['id'];
        $conf = $kdMap[$jId] ?? $fallbackKd ?? null;

        $jamMulai = substr($j['jam_mulai'], 0, 5);
        $jamSelesai = substr($j['jam_selesai'], 0, 5);

        $result[] = [
            'id' => $jId,
            'mata_kuliah' => $j['mata_kuliah'],
            'kelas' => $j['kelas'],
            'ruang' => $j['ruang'],
            'prodi_nama' => $j['prodi_nama'],
            'periode_kode' => $j['periode_kode'],
            'jam_mulai' => $jamMulai,
            'jam_selesai' => $jamSelesai,
            'konfirmasi' => $conf ? [
                'status' => $conf['status'],
                'mode' => $conf['mode'],
                'jam_ganti' => $conf['jam_ganti'],
                'keterangan' => $conf['keterangan'],
                'waktu_absen' => $conf['waktu_absen'] ? substr($conf['waktu_absen'], 0, 5) : null,
                'updated_at' => $conf['updated_at']
            ] : null
        ];
    }

    return $result;
}

function getDosenWeeklyJadwal(PDO $pdo, int $dosenId): array {
    $sql = "SELECT j.id, j.hari, j.jam_mulai, j.jam_selesai, j.mata_kuliah, j.kelas, j.ruang,
                   pr.nama AS prodi_nama
            FROM `jadwal_kuliah` j
            JOIN `prodi` pr ON pr.id = j.prodi_id AND pr.aktif = 1
            JOIN `periode_akademik` pa ON pa.id = j.periode_id AND pa.aktif = 1
            WHERE j.dosen_id = :dosen_id AND j.aktif = 1
            ORDER BY j.hari ASC, j.jam_mulai ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['dosen_id' => $dosenId]);
    $rows = $stmt->fetchAll();

    $namaHari = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu'
    ];

    $grouped = [];
    foreach ($rows as $r) {
        $h = (int) $r['hari'];
        $hName = $namaHari[$h] ?? 'Hari ' . $h;
        if (!isset($grouped[$h])) {
            $grouped[$h] = [
                'hari' => $h,
                'hari_nama' => $hName,
                'jadwal' => []
            ];
        }
        $grouped[$h]['jadwal'][] = [
            'id' => (int) $r['id'],
            'mata_kuliah' => $r['mata_kuliah'],
            'kelas' => $r['kelas'],
            'ruang' => $r['ruang'],
            'prodi_nama' => $r['prodi_nama'],
            'jam_mulai' => substr($r['jam_mulai'], 0, 5),
            'jam_selesai' => substr($r['jam_selesai'], 0, 5)
        ];
    }

    return array_values($grouped);
}

function getDosenRiwayatPresensi(PDO $pdo, int $dosenId, int $limit = 50): array {
    $sql = "SELECT kd.*, j.mata_kuliah, j.kelas, j.ruang, pr.nama AS prodi_nama
            FROM `kehadiran_dosen` kd
            LEFT JOIN `jadwal_kuliah` j ON j.id = kd.jadwal_id
            LEFT JOIN `prodi` pr ON pr.id = j.prodi_id
            WHERE kd.dosen_id = :dosen_id 
              AND (kd.jadwal_id > 0 OR NOT EXISTS (
                  SELECT 1 FROM `kehadiran_dosen` kd2 
                  WHERE kd2.dosen_id = kd.dosen_id 
                    AND kd2.tanggal = kd.tanggal 
                    AND kd2.jadwal_id > 0
              ))
            ORDER BY kd.tanggal DESC, kd.waktu_absen DESC, kd.id DESC
            LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['dosen_id' => $dosenId]);
    return $stmt->fetchAll();
}

function getDosenRekapBulanan(PDO $pdo, int $dosenId, string $bulan): array {
    $sql = "SELECT 
                COUNT(*) AS total_sesi,
                SUM(CASE WHEN status = 'hadir' AND mode = 'offline' THEN 1 ELSE 0 END) AS hadir_offline,
                SUM(CASE WHEN status = 'hadir' AND mode = 'online' THEN 1 ELSE 0 END) AS hadir_online,
                SUM(CASE WHEN status = 'tukar_jam' THEN 1 ELSE 0 END) AS tukar_jam,
                SUM(CASE WHEN status IN ('tidak_hadir', 'tidak', 'izin', 'alpa') THEN 1 ELSE 0 END) AS tidak_hadir
            FROM `kehadiran_dosen` kd
            WHERE kd.dosen_id = :dosen_id
              AND kd.tanggal LIKE :bulan
              AND (kd.jadwal_id > 0 OR NOT EXISTS (
                  SELECT 1 FROM `kehadiran_dosen` kd2 
                  WHERE kd2.dosen_id = kd.dosen_id 
                    AND kd2.tanggal = kd.tanggal 
                    AND kd2.jadwal_id > 0
              ))";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['dosen_id' => $dosenId, 'bulan' => $bulan . '%']);
    $res = $stmt->fetch();
    return [
        'total_sesi' => (int)($res['total_sesi'] ?? 0),
        'hadir_offline' => (int)($res['hadir_offline'] ?? 0),
        'hadir_online' => (int)($res['hadir_online'] ?? 0),
        'tukar_jam' => (int)($res['tukar_jam'] ?? 0),
        'tidak_hadir' => (int)($res['tidak_hadir'] ?? 0)
    ];
}

/**
 * Pastikan tabel jurnal_perkuliahan, presensi_mahasiswa, dan kelas_mahasiswa tersedia
 * Kompatibel 100% dengan SQLite dan MySQL
 */
function ensurePresensiMahasiswaTables(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) return;
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `jurnal_perkuliahan` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `jadwal_id` INTEGER NOT NULL,
                `dosen_id` INTEGER NOT NULL,
                `pertemuan_ke` INTEGER NOT NULL,
                `tanggal` TEXT NOT NULL,
                `jam_mulai` TEXT NULL,
                `jam_selesai` TEXT NULL,
                `materi` TEXT NOT NULL,
                `capaian_catatan` TEXT NULL,
                `metode` TEXT DEFAULT 'offline',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `presensi_mahasiswa` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `jurnal_id` INTEGER NOT NULL,
                `jadwal_id` INTEGER NOT NULL,
                `nim` TEXT NULL,
                `nama` TEXT NOT NULL,
                `status` TEXT NOT NULL DEFAULT 'hadir',
                `catatan` TEXT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `kelas_mahasiswa` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `jadwal_id` INTEGER NOT NULL,
                `nim` TEXT NULL,
                `nama` TEXT NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `jurnal_perkuliahan` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `jadwal_id` BIGINT UNSIGNED NOT NULL,
                `dosen_id` BIGINT UNSIGNED NOT NULL,
                `pertemuan_ke` INT NOT NULL,
                `tanggal` DATE NOT NULL,
                `jam_mulai` VARCHAR(10) NULL,
                `jam_selesai` VARCHAR(10) NULL,
                `materi` TEXT NOT NULL,
                `capaian_catatan` TEXT NULL,
                `metode` VARCHAR(20) DEFAULT 'offline',
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_jp_jadwal` (`jadwal_id`),
                INDEX `idx_jp_dosen` (`dosen_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `presensi_mahasiswa` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `jurnal_id` BIGINT UNSIGNED NOT NULL,
                `jadwal_id` BIGINT UNSIGNED NOT NULL,
                `nim` VARCHAR(50) NULL,
                `nama` VARCHAR(150) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'hadir',
                `catatan` VARCHAR(255) NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_pm_jurnal` (`jurnal_id`),
                INDEX `idx_pm_jadwal` (`jadwal_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `kelas_mahasiswa` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `jadwal_id` BIGINT UNSIGNED NOT NULL,
                `nim` VARCHAR(50) NULL,
                `nama` VARCHAR(150) NOT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_km_jadwal` (`jadwal_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        $ensured = true;
    } catch (Throwable $t) {
        error_log("ensurePresensiMahasiswaTables error: " . $t->getMessage());
    }
}

/**
 * Ambil seluruh kelas/jadwal yang diampu oleh seorang dosen
 */
function getDosenClasses(PDO $pdo, int $dosenId): array {
    $sql = "SELECT j.id, j.mata_kuliah, j.kelas, j.ruang, j.hari, j.jam_mulai, j.jam_selesai,
                   pr.nama AS prodi_nama, pa.kode AS periode_kode
            FROM `jadwal_kuliah` j
            JOIN `prodi` pr ON pr.id = j.prodi_id
            JOIN `periode_akademik` pa ON pa.id = j.periode_id
            WHERE j.dosen_id = :dosen_id AND j.aktif = 1
            ORDER BY j.hari ASC, j.jam_mulai ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['dosen_id' => $dosenId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ambil daftar master mahasiswa yang terdaftar di suatu kelas/jadwal
 */
function getKelasMahasiswa(PDO $pdo, int $jadwalId): array {
    ensurePresensiMahasiswaTables($pdo);
    $stmt = $pdo->prepare("SELECT * FROM `kelas_mahasiswa` WHERE jadwal_id = :jid ORDER BY nama ASC");
    $stmt->execute(['jid' => $jadwalId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ambil riwayat jurnal perkuliahan dan rekap presensi untuk suatu kelas
 */
function getJurnalList(PDO $pdo, int $jadwalId): array {
    ensurePresensiMahasiswaTables($pdo);
    $stmt = $pdo->prepare("SELECT * FROM `jurnal_perkuliahan` WHERE jadwal_id = :jid ORDER BY pertemuan_ke ASC, tanggal ASC");
    $stmt->execute(['jid' => $jadwalId]);
    $jurnals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($jurnals as &$j) {
        $stmtStat = $pdo->prepare("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
            SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) as izin,
            SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) as sakit,
            SUM(CASE WHEN status = 'alpa' THEN 1 ELSE 0 END) as alpa
            FROM `presensi_mahasiswa` WHERE jurnal_id = :jid");
        $stmtStat->execute(['jid' => $j['id']]);
        $stat = $stmtStat->fetch(PDO::FETCH_ASSOC);
        $j['stat'] = [
            'total' => (int)($stat['total'] ?? 0),
            'hadir' => (int)($stat['hadir'] ?? 0),
            'izin' => (int)($stat['izin'] ?? 0),
            'sakit' => (int)($stat['sakit'] ?? 0),
            'alpa' => (int)($stat['alpa'] ?? 0)
        ];
    }
    return $jurnals;
}

/**
 * Ambil detail satu pertemuan jurnal perkuliahan dan presensi mahasiswa
 */
function getJurnalDetail(PDO $pdo, int $jurnalId): ?array {
    ensurePresensiMahasiswaTables($pdo);
    $stmt = $pdo->prepare("SELECT * FROM `jurnal_perkuliahan` WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $jurnalId]);
    $jurnal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$jurnal) return null;

    $stmtPresensi = $pdo->prepare("SELECT * FROM `presensi_mahasiswa` WHERE jurnal_id = :jid ORDER BY nama ASC");
    $stmtPresensi->execute(['jid' => $jurnalId]);
    $jurnal['presensi'] = $stmtPresensi->fetchAll(PDO::FETCH_ASSOC);
    return $jurnal;
}

