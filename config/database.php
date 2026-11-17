<?php
/**
 * config/database.php
 * Konfigurasi Database Terpusat & Helper Lingkungan (Environment)
 * STAI Sidogiri - Sistem Informasi Layar Kampus v1.0
 */

// 1. Loader File .env Tanpa Dependensi Pihak Ketiga
if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $filePath): void {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $val = trim($parts[1]);

            // Bersihkan kutip jika ada
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }

            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// Muat .env jika tersedia di root proyek
loadEnvFile(dirname(__DIR__) . '/.env');

// 2. Helper Pembaca Environment
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null) {
            return $default;
        }

        $lower = strtolower((string)$val);
        if ($lower === 'true' || $lower === '(true)') return true;
        if ($lower === 'false' || $lower === '(false)') return false;
        if ($lower === 'empty' || $lower === '(empty)') return '';
        if ($lower === 'null' || $lower === '(null)') return null;

        return $val;
    }
}

// 3. Helper Koneksi Database PDO Terpusat
if (!function_exists('getDatabasePdo')) {
    function getDatabasePdo(): PDO {
        static $pdo = null;

        if ($pdo !== null) {
            return $pdo;
        }

        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', 3306);
        $dbname = env('DB_DATABASE', 'stai_display');
        $username = env('DB_USERNAME', 'root');
        $password = env('DB_PASSWORD', '');
        $charset = env('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Log kesalahan dan lempar kembali
            error_log("Database Connection Failed: " . $e->getMessage());
            throw new RuntimeException("Koneksi ke database gagal. Pastikan konfigurasi di .env atau database.php sudah benar: " . $e->getMessage());
        }

        return $pdo;
    }
}

// 4. Helper Base URL Otomatis (Mendukung Hosting Domain Root maupun Subfolder)
if (!function_exists('getBaseUrl')) {
    function getBaseUrl(): string {
        $configuredUrl = env('APP_URL');
        if (!empty($configuredUrl)) {
            return rtrim($configuredUrl, '/');
        }

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
        );

        $scheme = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $rootDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';

        $subDir = '';
        if (!empty($docRoot)) {
            if (stripos($rootDir, $docRoot) === 0) {
                $subDir = trim(substr($rootDir, strlen($docRoot)), '/');
            }
        }

        if (!empty($subDir)) {
            return "{$scheme}://{$host}/{$subDir}";
        }

        return "{$scheme}://{$host}";
    }
}

// 5. Helper Validasi Subnet IP (Mendukung Toggle via .env dan Tabel pengaturan)
if (!function_exists('isNetworkAllowed')) {
    function isNetworkAllowed(string $ip, ?PDO $pdo = null): bool {
        // Jika di .env secara eksplisit dinonaktifkan
        if (env('DISABLE_SUBNET_CHECK', false) === true || env('VALIDASI_SUBNET_IP', true) === false) {
            return true;
        }

        // Cek pengaturan di database jika koneksi tersedia
        try {
            $db = $pdo ?? getDatabasePdo();
            $stmt = $db->prepare("SELECT `nilai` FROM `pengaturan` WHERE `kunci` = 'validasi_subnet_ip' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                $decoded = json_decode($val, true);
                // Jika pengaturan bernilai 0 / false, lewati validasi subnet
                if ($decoded === false || $decoded === 0 || $decoded === '0') {
                    return true;
                }
            }
        } catch (Throwable $t) {
            // Abaikan kesalahan DB di helper fallback
        }

        // Localhost selalu diizinkan
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
            return true;
        }

        // Cek subnet IPv4 standar (Private LAN RFC1918)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if ($parts[0] === '192' && $parts[1] === '168') return true;
            if ($parts[0] === '10') return true;
            if ($parts[0] === '172' && (int)$parts[1] >= 16 && (int)$parts[1] <= 31) return true;
        }

        return false;
    }
}
