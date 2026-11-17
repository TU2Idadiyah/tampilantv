<?php
/**
 * Endpoint: POST /api/v1/absen/scan
 * Terhubung langsung ke Database MySQL stai_display & CatatAbsensiAction
 * Selaras dengan PRD Bab 7 & 8
 */

require_once dirname(__DIR__, 3) . '/app/Services/QrTokenService.php';
require_once dirname(__DIR__, 3) . '/app/Actions/Absensi/CatatAbsensiAction.php';

use App\Actions\Absensi\CatatAbsensiAction;

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
$code = trim($input['c'] ?? '');
$rawDeviceToken = $_COOKIE['stai_device_token'] ?? '';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

if (empty($code)) {
    http_response_code(422);
    echo json_encode(["kode" => "VALIDASI_GAGAL", "pesan" => "Kode QR tidak boleh kosong."]);
    exit;
}

$action = new CatatAbsensiAction();
$result = $action->execute($code, $rawDeviceToken, $clientIp);

http_response_code($result['http_code']);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
