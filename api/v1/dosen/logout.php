<?php
/**
 * GET / POST /api/v1/dosen/logout.php - Logout Dosen
 */

require_once __DIR__ . '/helper.php';

clearDosenCookie();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'pesan' => 'Berhasil keluar.'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Location: ../../../dosen/index.php');
exit;
