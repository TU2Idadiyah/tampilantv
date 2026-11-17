<?php
/**
 * GET /api/v1/display/qr
 * Terintegrasi langsung dengan Database MySQL stai_display & QrTokenService
 * Selaras dengan PRD Backend Layar Kampus STAI Sidogiri v1
 */

require_once dirname(__DIR__, 3) . '/config/database.php';
require_once dirname(__DIR__, 3) . '/app/Services/QrTokenService.php';

use App\Services\QrTokenService;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Display-Key, X-Display-Id, Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = getDatabasePdo();
    $qrSecret = env('QR_SECRET', 'stai_sidogiri_secret_key_2026');
    $baseUrl = getBaseUrl() . '/absen/scan';

    // Cek jika ada custom qr_base_url di pengaturan (jika bukan default localhost saat online)
    try {
        $stmtUrl = $pdo->prepare("SELECT `nilai` FROM `pengaturan` WHERE `kunci` = 'qr_base_url' LIMIT 1");
        $stmtUrl->execute();
        $customBaseUrl = $stmtUrl->fetchColumn();
        if ($customBaseUrl) {
            $decoded = json_decode($customBaseUrl, true);
            if (!empty($decoded) && (!str_contains($decoded, 'localhost') || !empty($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'localhost'))) {
                $baseUrl = rtrim($decoded, '/');
            }
        }
    } catch (Throwable $t) {}

    $qrService = new QrTokenService($qrSecret, $baseUrl);

    date_default_timezone_set('Asia/Jakarta');
    $today = date('Y-m-d');

    // Ambil seluruh pengurus yang aktif (Mendukung Multi-Sesi Masuk & Pulang)
    $sql = "SELECT p.id
            FROM `pengurus` p
            WHERE p.aktif = 1 AND p.deleted_at IS NULL
            ORDER BY p.urutan ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $activePengurus = $stmt->fetchAll();

    $qrMap = [];
    foreach ($activePengurus as $p) {
        $pId = (int) $p['id'];
        $qrMap[(string) $pId] = $qrService->generateSignedUrl($pId);
    }

    echo json_encode([
        'jendela' => $qrService->getCurrentWindow(),
        'waktu_berlaku_detik' => $qrService->getRemainingWindowSeconds(),
        'qr' => $qrMap
    ], JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'kode' => 'DATABASE_ERROR',
        'pesan' => 'Gagal menghasilkan token QR: ' . $e->getMessage()
    ]);
}
