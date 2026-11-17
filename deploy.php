<?php
/**
 * deploy.php - Webhook & Cron Auto-Deployment Helper
 * STAI Sidogiri - Sistem Informasi Layar Kampus
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store');

@ini_set('display_errors', '0');
@set_time_limit(180);

echo "=== MEMULAI SINKRONISASI AUTO-DEPLOY ===\n";
echo "Waktu Server: " . date('Y-m-d H:i:s') . "\n";
echo "Folder Proyek: " . __DIR__ . "\n\n";

$targetDir = __DIR__;

// Fungsi pembantu eksekusi perintah
function executeShell(string $cmd, string $dir): void {
    echo "> {$cmd}\n";
    $output = [];
    $returnVar = 0;
    
    // Prioritaskan exec jika tersedia
    if (function_exists('exec')) {
        @exec("cd " . escapeshellarg($dir) . " && {$cmd} 2>&1", $output, $returnVar);
        echo implode("\n", $output) . "\n";
    } elseif (function_exists('shell_exec')) {
        $res = @shell_exec("cd " . escapeshellarg($dir) . " && {$cmd} 2>&1");
        echo ($res ?: "(tidak ada output)") . "\n";
    } elseif (function_exists('passthru')) {
        @passthru("cd " . escapeshellarg($dir) . " && {$cmd} 2>&1", $returnVar);
    } else {
        echo "PERINGATAN: Fungsi shell_exec / exec dinonaktifkan di konfigurasi PHP hosting ini.\n";
    }
}

// 1. Set safe.directory untuk git
executeShell('/usr/bin/git config --global --add safe.directory ' . escapeshellarg($targetDir), $targetDir);

// 2. Fetch origin main
executeShell('/usr/bin/git fetch origin main', $targetDir);

// 3. Reset hard ke origin main (mengatasi konflik file lokal secara permanen)
executeShell('/usr/bin/git reset --hard origin/main', $targetDir);

echo "\nStatus Git Saat Ini:\n";
executeShell('/usr/bin/git log -n 1 --oneline', $targetDir);

echo "\n=== SINKRONISASI SELESAI ===\n";
