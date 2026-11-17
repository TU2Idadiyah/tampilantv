<?php
/**
 * Migration: Multi-Session Presensi & Durasi Otomatis
 * Menambahkan dukungan multi-sesi harian, waktu pulang, dan durasi otomatis
 */

require_once dirname(__DIR__) . '/api/v1/absen/helper.php';

try {
    $pdo = getPdo();
    echo "=== MEMULAI MIGRASI MULTI-SESI PRESENSI STAI DISPLAY ===\n";

    // 1. Tambah Kolom Baru jika belum ada
    $columns = [
        "ADD COLUMN IF NOT EXISTS `sesi_ke` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `tanggal`",
        "ADD COLUMN IF NOT EXISTS `waktu_masuk` TIME NULL AFTER `sesi_ke`",
        "ADD COLUMN IF NOT EXISTS `waktu_pulang` TIME NULL AFTER `waktu_absen`",
        "ADD COLUMN IF NOT EXISTS `status` ENUM('masuk', 'pulang') NOT NULL DEFAULT 'masuk' AFTER `waktu_pulang`",
        "ADD COLUMN IF NOT EXISTS `durasi_menit` INT UNSIGNED NULL AFTER `status`",
        "ADD COLUMN IF NOT EXISTS `metode_masuk` ENUM('qr', 'manual') NOT NULL DEFAULT 'qr' AFTER `metode`",
        "ADD COLUMN IF NOT EXISTS `metode_pulang` ENUM('qr', 'manual') NULL AFTER `metode_masuk`",
        "ADD COLUMN IF NOT EXISTS `perangkat_id_pulang` BIGINT UNSIGNED NULL AFTER `perangkat_id`",
        "ADD COLUMN IF NOT EXISTS `ip_pulang` VARCHAR(45) NULL AFTER `ip_address`"
    ];

    foreach ($columns as $sqlCol) {
        $pdo->exec("ALTER TABLE `absensi_pengurus` " . $sqlCol);
    }
    echo "✓ Kolom multi-sesi berhasil diperiksa/ditambahkan.\n";

    // 2. Tambah index baru terlebih dahulu agar FK fk_absensi_pengurus tidak bergantung pada uk_absensi_pengurus_tanggal
    $indexes = $pdo->query("SHOW INDEX FROM `absensi_pengurus`")->fetchAll(PDO::FETCH_ASSOC);
    $hasUnique = false;
    $hasIdx = false;
    foreach ($indexes as $idx) {
        if ($idx['Key_name'] === 'uk_absensi_pengurus_tanggal') {
            $hasUnique = true;
        }
        if ($idx['Key_name'] === 'idx_absensi_pengurus_tanggal') {
            $hasIdx = true;
        }
    }

    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE `absensi_pengurus` ADD INDEX `idx_absensi_pengurus_tanggal` (`pengurus_id`, `tanggal`)");
        echo "✓ Index idx_absensi_pengurus_tanggal berhasil ditambahkan.\n";
    }

    if ($hasUnique) {
        $pdo->exec("ALTER TABLE `absensi_pengurus` DROP INDEX `uk_absensi_pengurus_tanggal`");
        echo "✓ Unique constraint uk_absensi_pengurus_tanggal berhasil dilepas (mendukung multi-sesi).\n";
    }

    // 3. Tambah Foreign Key untuk perangkat_id_pulang jika belum ada
    try {
        $pdo->exec("ALTER TABLE `absensi_pengurus` ADD CONSTRAINT `fk_absensi_perangkat_pulang` FOREIGN KEY (`perangkat_id_pulang`) REFERENCES `perangkat_pengurus` (`id`) ON DELETE SET NULL");
        echo "✓ Foreign key fk_absensi_perangkat_pulang ditambahkan.\n";
    } catch (Exception $e) {
        // FK mungkin sudah ada
    }

    // 4. Update data lama agar konsisten
    $pdo->exec("UPDATE `absensi_pengurus` SET `waktu_masuk` = `waktu_absen` WHERE `waktu_masuk` IS NULL");
    $pdo->exec("UPDATE `absensi_pengurus` SET `metode_masuk` = `metode` WHERE `metode_masuk` IS NULL");
    echo "✓ Data lama disinkronkan dengan kolom waktu_masuk & metode_masuk.\n";

    echo "=== MIGRASI BERHASIL! ===\n";

} catch (Exception $e) {
    echo "MIGRASI GAGAL: " . $e->getMessage() . "\n";
    exit(1);
}
