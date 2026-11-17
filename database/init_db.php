<?php
/**
 * Script Inisialisasi & Migrasi Database MySQL (16 Tabel + Seed Awal)
 * Menjalankan schema.sql dan seed.sql ke MariaDB/MySQL XAMPP
 */

require_once dirname(__DIR__) . '/config/database.php';

$host = env('DB_HOST', '127.0.0.1');
$port = env('DB_PORT', 3306);
$user = env('DB_USERNAME', 'root');
$pass = env('DB_PASSWORD', '');

echo "=== MEMULAI INISIALISASI DATABASE STAI_DISPLAY ===\n";

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true
    ]);

    // 1. Eksekusi Skema DDL
    echo "1. Membaca dan mengeksekusi schema.sql (16 Tabel InnoDB)... ";
    $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($schemaSql);
    echo "SUKSES!\n";

    // 2. Eksekusi Seeder Awal
    echo "2. Membaca dan memasukkan seed.sql (Master Data & Pengaturan)... ";
    $seedSql = file_get_contents(__DIR__ . '/seed.sql');
    $pdo->exec($seedSql);
    echo "SUKSES!\n";

    // 3. Verifikasi Tabel
    $pdo->exec("USE `stai_display`");
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "3. Verifikasi Tabel Berhasil! Total " . count($tables) . " tabel terdaftar:\n";
    foreach ($tables as $i => $tbl) {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$tbl}`");
        $rowCount = $countStmt->fetchColumn();
        echo "   " . ($i + 1) . ". {$tbl} ({$rowCount} baris)\n";
    }

    echo "=== DATABASE SIAP DIGUNAKAN DI XAMPP! ===\n";

} catch (Exception $e) {
    echo "\nGAGAL: " . $e->getMessage() . "\n";
    exit(1);
}

