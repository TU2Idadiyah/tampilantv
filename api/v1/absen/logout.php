<?php
/**
 * Endpoint: POST /api/v1/absen/logout
 * Mencabut Perangkat Ini di Database MySQL stai_display
 */

require_once __DIR__ . '/helper.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

$auth = getActivePengurusFromCookie();
if ($auth) {
    try {
        $pdo = getPdo();
        $stmt = $pdo->prepare("UPDATE `perangkat_pengurus` SET `dicabut_pada` = NOW() WHERE `id` = :id");
        $stmt->execute(['id' => $auth['perangkat']['id']]);
    } catch (Exception $e) {}
}

setcookie('stai_device_token', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);

echo json_encode([
    "status" => "ok",
    "pesan" => "Perangkat berhasil dicabut/dikeluarkan."
]);
