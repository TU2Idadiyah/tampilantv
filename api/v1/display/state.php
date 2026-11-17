<?php
/**
 * GET /api/v1/display/state
 * Terintegrasi langsung dengan Database MySQL stai_display & DisplayStateService
 * Selaras dengan PRD Backend Layar Kampus STAI Sidogiri v1
 */

require_once dirname(__DIR__, 3) . '/app/Services/WeatherService.php';
require_once dirname(__DIR__, 3) . '/app/Services/DisplayStateService.php';

use App\Services\DisplayStateService;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Display-Key, X-Display-Id, If-None-Match, Content-Type');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $service = new DisplayStateService();
    $state = $service->getState();
    $etag = $service->getEtag($state);

    // Periksa ETag dari header request (ETag 304 Not Modified sesuai PRD)
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=1');

    echo json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log("state.php error: " . $e->getMessage());
    $cacheFile = dirname(__DIR__, 3) . '/storage/cache_state.json';
    if (file_exists($cacheFile)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=1');
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $cached['waktu_server'] = date('c');
            echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'kode' => 'SERVER_ERROR',
        'pesan' => 'Gagal membaca state tampilan: ' . $e->getMessage()
    ]);
}
