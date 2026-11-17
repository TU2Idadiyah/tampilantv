<?php
/**
 * Endpoint: POST /api/v1/absen/login
 * Pendaftaran Perangkat HP Pengurus (Tukar NIP & PIN dengan Cookie Perangkat)
 * Terintegrasi ke MySQL stai_display
 */

require_once dirname(__DIR__, 3) . '/config/database.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($requestMethod !== 'POST') {
    http_response_code(405);
    echo json_encode(["kode" => "METHOD_NOT_ALLOWED", "pesan" => "Gunakan metode POST"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$nip = trim($input['nip'] ?? '');
$pin = trim($input['pin'] ?? '');

if (empty($nip) || empty($pin)) {
    http_response_code(422);
    echo json_encode([
        "kode" => "VALIDASI_GAGAL",
        "pesan" => "NIP dan PIN 6-digit wajib diisi."
    ]);
    exit;
}

try {
    $pdo = getDatabasePdo();

    // Cari Pengurus berdasarkan NIP
    $stmt = $pdo->prepare("SELECT * FROM `pengurus` WHERE `nip` = :nip AND `aktif` = 1 AND `deleted_at` IS NULL LIMIT 1");
    $stmt->execute(['nip' => $nip]);
    $pengurus = $stmt->fetch();

    if (!$pengurus || !password_verify($pin, $pengurus['pin_hash'])) {
        http_response_code(401);
        echo json_encode([
            "kode" => "AUTENTIKASI_GAGAL",
            "pesan" => "NIP atau PIN yang Anda masukkan salah."
        ]);
        exit;
    }

    $pengurusId = (int) $pengurus['id'];

    // Periksa batas maksimal 2 perangkat aktif per pengurus (PRD Bab 8)
    $stmtDevCount = $pdo->prepare("SELECT COUNT(*) FROM `perangkat_pengurus` WHERE `pengurus_id` = :pid AND `dicabut_pada` IS NULL");
    $stmtDevCount->execute(['pid' => $pengurusId]);
    $activeCount = (int) $stmtDevCount->fetchColumn();

    if ($activeCount >= 2) {
        http_response_code(422);
        echo json_encode([
            "kode" => "BATAS_PERANGKAT_TERCAPAI",
            "pesan" => "Maksimal 2 perangkat aktif telah tercapai. Harap minta admin mencabut perangkat lama Anda."
        ]);
        exit;
    }

    // Buat token acak 256-bit
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiredAt = date('Y-m-d H:i:s', time() + (90 * 86400)); // 90 hari

    $label = $_SERVER['HTTP_USER_AGENT'] ?? 'Smartphone Browser';
    if (strlen($label) > 80) {
        $label = substr($label, 0, 80) . '...';
    }

    $stmtInsertDev = $pdo->prepare("INSERT INTO `perangkat_pengurus`
        (`pengurus_id`, `token_hash`, `label`, `terakhir_dipakai`, `kedaluwarsa`, `created_at`, `updated_at`)
        VALUES (:pid, :hash, :label, NOW(), :exp, NOW(), NOW())");
    $stmtInsertDev->execute([
        'pid' => $pengurusId,
        'hash' => $tokenHash,
        'label' => $label,
        'exp' => $expiredAt
    ]);

    // Pasang Cookie HttpOnly 90 hari
    setcookie('stai_device_token', $rawToken, [
        'expires' => time() + (90 * 86400),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    echo json_encode([
        "status" => "ok",
        "pesan" => "Perangkat berhasil didaftarkan.",
        "pengurus" => [
            "id" => $pengurus['id'],
            "nama" => $pengurus['nama'],
            "jabatan" => $pengurus['jabatan']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "kode" => "DATABASE_ERROR",
        "pesan" => "Terjadi kesalahan database: " . $e->getMessage()
    ]);
}
