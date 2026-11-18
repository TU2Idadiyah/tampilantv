<?php
/**
 * auth.php - Pengelola Sesi & Autentikasi Panel Admin
 * Selaras dengan PRD Bab 3 & 13
 */

require_once dirname(__DIR__) . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Jakarta');

function getAdminPdo(): PDO
{
    return getDatabasePdo();
}

function getCurrentAdmin(): ?array
{
    if (empty($_SESSION['stai_admin_id'])) {
        return null;
    }

    // Periksa Sesi Berakhir setelah 120 menit tidak aktif (PRD Bab 13)
    $lastActivity = $_SESSION['stai_admin_last_activity'] ?? 0;
    if (time() - $lastActivity > 7200) {
        adminLogout();
        return null;
    }

    $_SESSION['stai_admin_last_activity'] = time();

    return [
        'id' => (int) $_SESSION['stai_admin_id'],
        'name' => $_SESSION['stai_admin_name'],
        'email' => $_SESSION['stai_admin_email'],
        'role' => $_SESSION['stai_admin_role']
    ];
}

function requireAdminAuth(): array
{
    $admin = getCurrentAdmin();
    if (!$admin) {
        header("Location: login.php");
        exit;
    }
    return $admin;
}

function requireSuperAdmin(): array
{
    $admin = requireAdminAuth();
    if ($admin['role'] !== 'super_admin') {
        http_response_code(403);
        die("<h2 style='font-family:sans-serif;color:#dc2626'>Akses Ditolak: Anda memerlukan hak akses Super Admin.</h2>");
    }
    return $admin;
}

function adminLogin(string $email, string $password): array
{
    try {
        $pdo = getAdminPdo();
    } catch (Throwable $t) {
        return ['sukses' => false, 'pesan' => 'Koneksi ke database gagal. Silakan buka halaman <a href="../setup.php" style="color:var(--g-dark);font-weight:700">Setup Database</a> untuk menghubungkan database.'];
    }
    $ident = strtolower(trim($email));
    $stmt = $pdo->prepare("SELECT * FROM `users` WHERE LOWER(`email`) = :ident OR LOWER(`name`) = :ident LIMIT 1");
    $stmt->execute(['ident' => $ident]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['sukses' => false, 'pesan' => 'Username/email atau kata sandi tidak cocok.'];
    }

    $_SESSION['stai_admin_id'] = $user['id'];
    $_SESSION['stai_admin_name'] = $user['name'];
    $_SESSION['stai_admin_email'] = $user['email'];
    $_SESSION['stai_admin_role'] = $user['role'];
    $_SESSION['stai_admin_last_activity'] = time();

    // Catat log
    $sqlAudit = "INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `meta`, `ip_address`, `created_at`)
                 VALUES ('admin', :uid, 'login', 'users', :meta, :ip, NOW())";
    $pdo->prepare($sqlAudit)->execute([
        'uid' => $user['id'],
        'meta' => json_encode(['role' => $user['role']]),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);

    return ['sukses' => true, 'user' => $user];
}

function adminLogout(): void
{
    if (isset($_SESSION['stai_admin_id'])) {
        try {
            $pdo = getAdminPdo();
            $sqlAudit = "INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `created_at`)
                         VALUES ('admin', :uid, 'logout', 'users', NOW())";
            $pdo->prepare($sqlAudit)->execute(['uid' => $_SESSION['stai_admin_id']]);
        } catch (Exception $e) {}
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

