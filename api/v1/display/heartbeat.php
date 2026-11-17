<?php
/**
 * Mock & Simulator Endpoint: POST /api/v1/display/heartbeat
 * Sesuai PRD Bab 7
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Display-Key, X-Display-Id, Content-Type');
header('Content-Type: application/json; charset=utf-8');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$displayId = $_SERVER['HTTP_X_DISPLAY_ID'] ?? 'unknown';
$rawInput = file_get_contents('php://input');

// Catat timestamp heartbeat layar
$logFile = __DIR__ . '/heartbeat.log';
$entry = date('c') . " | Display ID: " . $displayId . " | Body: " . trim($rawInput) . PHP_EOL;
file_put_contents($logFile, $entry, FILE_APPEND);

echo json_encode([
    "status" => "ok",
    "waktu_server" => date('c'),
    "display_id" => $displayId
]);
