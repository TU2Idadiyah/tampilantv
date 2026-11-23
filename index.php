<?php
/**
 * Root index.php - STAI Sidogiri
 * Mengarahkan akses browser ke pusat portal masuk (login.php)
 * Mencegah error 404 jika ada perangkat/dosen yang mengakses URL root /index.php
 */

header("Location: login.php");
exit;
