<?php
/**
 * Health Check Endpoint: GET /up
 * Memeriksa kesiapan infrastruktur (Koneksi Database MySQL & Penyimpanan Lokal)
 * Selaras dengan PRD Bab 7
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

date_default_timezone_set('Asia/Jakarta');

$checks = [
    'status' => 'ok',
    'timestamp' => date('c'),
    'database' => 'disconnected',
    'storage' => 'unwritable',
    'php_version' => PHP_VERSION,
    'diagnostik' => []
];

require_once __DIR__ . '/config/database.php';

$checks['diagnostik'] = [
    'env_dimuat_dari' => $activeEnvFile ?? '(tidak ada file .env yang dimuat)',
    'db_host' => env('DB_HOST', '127.0.0.1'),
    'db_database' => env('DB_DATABASE', 'stai_display'),
    'db_user' => env('DB_USERNAME', 'root'),
    'ada_password' => !empty(env('DB_PASSWORD')),
    'cache_state_tersedia' => file_exists(__DIR__ . '/storage/cache_state.json'),
];

$healthy = true;

// 1. Cek Konektivitas Database MySQL
try {
    $pdo = getDatabasePdo();
    $stmt = $pdo->query('SELECT 1');
    if ($stmt && $stmt->fetchColumn() == 1) {
        $checks['database'] = 'connected';
    } else {
        $healthy = false;
    }
} catch (Exception $e) {
    $healthy = false;
    $checks['database'] = 'error: ' . $e->getMessage();
}

// 2. Cek Izin Tulis Folder Storage
$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0777, true);
}

$testFile = $storageDir . '/.health_check_tmp';
if (@file_put_contents($testFile, 'ok') !== false) {
    @unlink($testFile);
    $checks['storage'] = 'writable';
} else {
    $healthy = false;
    $checks['storage'] = 'unwritable';
}

if ($healthy) {
    http_response_code(200);
} else {
    http_response_code(503);
    $checks['status'] = 'unhealthy';
}

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

