<?php
/**
 * POST /api/v1/dosen/login.php - Login / Identifikasi Dosen
 * GET  /api/v1/dosen/login.php?action=list - List Dosen untuk Pilihan Masuk Cepat
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/helper.php';

$pdo = getDosenPdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'me';

    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id, kode_nidn, nama, foto_path FROM `dosen` WHERE aktif = 1 AND deleted_at IS NULL ORDER BY nama ASC");
        $list = [];
        while ($row = $stmt->fetch()) {
            $fotoUrl = !empty($row['foto_path']) ? (str_starts_with($row['foto_path'], 'http') ? $row['foto_path'] : '../storage/' . ltrim($row['foto_path'], '/')) : null;
            $list[] = [
                'id' => (int) $row['id'],
                'kode_nidn' => $row['kode_nidn'],
                'nama' => $row['nama'],
                'foto_url' => $fotoUrl
            ];
        }

        echo json_encode(['success' => true, 'dosen_list' => $list], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'me') {
        $dosen = getActiveDosen();
        if ($dosen) {
            echo json_encode(['success' => true, 'authenticated' => true, 'dosen' => $dosen], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo json_encode(['success' => true, 'authenticated' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $nidn = trim($input['nidn'] ?? '');
    $dosenId = (int) ($input['dosen_id'] ?? 0);
    $pin = trim($input['pin'] ?? '');

    if ($dosenId > 0) {
        $stmt = $pdo->prepare("SELECT id, kode_nidn, nama, foto_path, pin FROM `dosen` WHERE id = :id AND aktif = 1 AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(['id' => $dosenId]);
        $dosen = $stmt->fetch();
    } elseif (!empty($nidn)) {
        $stmt = $pdo->prepare("SELECT id, kode_nidn, nama, foto_path, pin FROM `dosen` WHERE LOWER(kode_nidn) = LOWER(:nidn) AND aktif = 1 AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(['nidn' => $nidn]);
        $dosen = $stmt->fetch();
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'pesan' => 'NIDN/Kode Dosen atau Akun Dosen wajib dipilih.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$dosen) {
        http_response_code(404);
        echo json_encode(['success' => false, 'pesan' => 'Data Dosen tidak ditemukan atau status tidak aktif.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Periksa PIN jika telah diatur di profil dosen
    if (!empty($dosen['pin'])) {
        if (empty($pin)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'need_pin' => true, 'pesan' => 'PIN keamanan diperlukan untuk akun ini.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($pin !== $dosen['pin'] && !password_verify($pin, $dosen['pin'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'pesan' => 'PIN yang Anda masukkan salah.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Set cookie session dosen
    setDosenCookie((int) $dosen['id']);

    // Log ke audit
    try {
        $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `created_at`)
                       VALUES ('dosen', :did, 'login_portal', 'dosen', :did, NOW())")
            ->execute(['did' => $dosen['id']]);
    } catch (Exception $e) {}

    $fotoUrl = !empty($dosen['foto_path']) ? (str_starts_with($dosen['foto_path'], 'http') ? $dosen['foto_path'] : '../storage/' . ltrim($dosen['foto_path'], '/')) : null;

    echo json_encode([
        'success' => true,
        'pesan' => 'Selamat datang, ' . $dosen['nama'],
        'dosen' => [
            'id' => (int) $dosen['id'],
            'kode_nidn' => $dosen['kode_nidn'],
            'nama' => $dosen['nama'],
            'foto_url' => $fotoUrl
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
