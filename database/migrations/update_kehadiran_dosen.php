<?php
/**
 * Migration: Enhance kehadiran_dosen & dosen for Portal Dosen
 */

$pdo = new PDO('mysql:host=127.0.0.1;dbname=stai_display;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "Starting migration for Portal Dosen...\n";

// 1. Update table dosen
$dosenCols = $pdo->query("SHOW COLUMNS FROM `dosen`")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('no_hp', $dosenCols, true)) {
    $pdo->exec("ALTER TABLE `dosen` ADD COLUMN `no_hp` VARCHAR(30) NULL AFTER `foto_path`");
    echo "Added `no_hp` column to `dosen`.\n";
}

if (!in_array('pin', $dosenCols, true)) {
    $pdo->exec("ALTER TABLE `dosen` ADD COLUMN `pin` VARCHAR(100) NULL AFTER `no_hp`");
    echo "Added `pin` column to `dosen`.\n";
}

// 2. Update table kehadiran_dosen
$kehadiranCols = $pdo->query("SHOW COLUMNS FROM `kehadiran_dosen`")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('jadwal_id', $kehadiranCols, true)) {
    $pdo->exec("ALTER TABLE `kehadiran_dosen` ADD COLUMN `jadwal_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `dosen_id`");
    echo "Added `jadwal_id` column to `kehadiran_dosen`.\n";
}

// Modify status to VARCHAR(50) to allow 'hadir', 'tidak_hadir', 'tukar_jam', 'izin', etc.
$pdo->exec("ALTER TABLE `kehadiran_dosen` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'hadir'");
echo "Modified `status` in `kehadiran_dosen` to VARCHAR(50).\n";

if (!in_array('mode', $kehadiranCols, true)) {
    $pdo->exec("ALTER TABLE `kehadiran_dosen` ADD COLUMN `mode` ENUM('offline', 'online') NULL AFTER `status`");
    echo "Added `mode` column to `kehadiran_dosen`.\n";
}

if (!in_array('jam_ganti', $kehadiranCols, true)) {
    $pdo->exec("ALTER TABLE `kehadiran_dosen` ADD COLUMN `jam_ganti` VARCHAR(100) NULL AFTER `mode`");
    echo "Added `jam_ganti` column to `kehadiran_dosen`.\n";
}

if (!in_array('keterangan', $kehadiranCols, true)) {
    $pdo->exec("ALTER TABLE `kehadiran_dosen` ADD COLUMN `keterangan` TEXT NULL AFTER `jam_ganti`");
    echo "Added `keterangan` column to `kehadiran_dosen`.\n";
}

if (!in_array('ip_address', $kehadiranCols, true)) {
    $pdo->exec("ALTER TABLE `kehadiran_dosen` ADD COLUMN `ip_address` VARCHAR(45) NULL AFTER `metode`");
    echo "Added `ip_address` column to `kehadiran_dosen`.\n";
}

// 3. Update Indexes on kehadiran_dosen
$indices = $pdo->query("SHOW INDEX FROM `kehadiran_dosen`")->fetchAll();
$indexNames = array_unique(array_column($indices, 'Key_name'));

if (!in_array('idx_kehadiran_dosen_id', $indexNames, true)) {
    $pdo->exec("ALTER TABLE `kehadiran_dosen` ADD INDEX `idx_kehadiran_dosen_id` (`dosen_id`)");
    echo "Added index `idx_kehadiran_dosen_id`.\n";
}

if (in_array('uk_kehadiran_dosen_tanggal', $indexNames, true)) {
    $pdo->exec("ALTER TABLE `kehadiran_dosen` DROP INDEX `uk_kehadiran_dosen_tanggal`");
    echo "Dropped index `uk_kehadiran_dosen_tanggal`.\n";
}

if (!in_array('uk_kehadiran_dosen_jadwal', $indexNames, true)) {
    $pdo->exec("ALTER TABLE `kehadiran_dosen` ADD UNIQUE KEY `uk_kehadiran_dosen_jadwal` (`dosen_id`, `tanggal`, `jadwal_id`)");
    echo "Added unique index `uk_kehadiran_dosen_jadwal`.\n";
}

echo "Migration completed successfully!\n";
