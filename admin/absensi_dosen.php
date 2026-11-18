<?php
/**
 * absensi_dosen.php - Rekapitulasi & Riwayat Presensi Dosen Kampus
 * STAI Sidogiri - Sistem Informasi Layar Kampus
 */

require_once __DIR__ . '/auth.php';
$admin = requireAdminAuth();
$pdo = getAdminPdo();
date_default_timezone_set('Asia/Jakarta');

// Format tanggal lokal Indonesia
function tglIndo(string $tgl): string {
    $parts = explode('-', $tgl);
    if (count($parts) !== 3) return $tgl;
    $bulanIndo = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
        '04' => 'April', '05' => 'Mei', '06' => 'Juni',
        '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
        '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];
    return (int)$parts[2] . ' ' . ($bulanIndo[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
}

// 1. HANDLER EKSPOR CSV REKAPITULASI & DETAIL
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $bulan = $_GET['bulan'] ?? date('Y-m');

    if ($action === 'export_rekap') {
        // Ekspor Rekapitulasi per Dosen
        $sqlRekapExport = "SELECT d.id, d.kode_nidn, d.nama,
                                  COUNT(DISTINCT j.id) AS total_jadwal_mingguan,
                                  COALESCE(SUM(CASE WHEN kd.status = 'hadir' AND kd.mode = 'offline' THEN 1 ELSE 0 END), 0) AS hadir_offline,
                                  COALESCE(SUM(CASE WHEN kd.status = 'hadir' AND kd.mode = 'online' THEN 1 ELSE 0 END), 0) AS hadir_online,
                                  COALESCE(SUM(CASE WHEN kd.status = 'tukar_jam' THEN 1 ELSE 0 END), 0) AS tukar_jam,
                                  COALESCE(SUM(CASE WHEN kd.status IN ('tidak_hadir', 'tidak', 'izin', 'alpa') THEN 1 ELSE 0 END), 0) AS tidak_hadir,
                                  COALESCE(COUNT(kd.id), 0) AS total_konfirmasi
                           FROM `dosen` d
                           LEFT JOIN `jadwal_kuliah` j ON j.dosen_id = d.id AND j.aktif = 1
                           LEFT JOIN `kehadiran_dosen` kd ON kd.dosen_id = d.id 
                                AND kd.tanggal LIKE :bulan 
                                AND (kd.jadwal_id > 0 OR NOT EXISTS (
                                    SELECT 1 FROM `kehadiran_dosen` kd2 
                                    WHERE kd2.dosen_id = kd.dosen_id 
                                      AND kd2.tanggal = kd.tanggal 
                                      AND kd2.jadwal_id > 0
                                ))
                           WHERE d.deleted_at IS NULL AND d.aktif = 1
                           GROUP BY d.id
                           ORDER BY d.nama ASC";
        $stmtExp = $pdo->prepare($sqlRekapExport);
        $stmtExp->execute(['bulan' => $bulan . '%']);
        $rowsExp = $stmtExp->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rekap_presensi_dosen_' . $bulan . '.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM agar Excel menampilkan aksen dengan benar
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['No', 'NIDN/Kode', 'Nama Dosen', 'Total Jadwal Mingguan', 'Hadir Offline (Tatap Muka)', 'Hadir Online (Daring)', 'Tukar Jam', 'Tidak Hadir (Izin/Sakit)', 'Total Sesi Konfirmasi', 'Tingkat Kehadiran (%)'], ';');

        foreach ($rowsExp as $idx => $r) {
            $totalHadir = (int)$r['hadir_offline'] + (int)$r['hadir_online'];
            $totalSesi = (int)$r['total_konfirmasi'];
            $pct = $totalSesi > 0 ? round(($totalHadir / $totalSesi) * 100, 1) : 0;

            fputcsv($fp, [
                $idx + 1,
                $r['kode_nidn'],
                $r['nama'],
                $r['total_jadwal_mingguan'] . ' Kelas',
                $r['hadir_offline'],
                $r['hadir_online'],
                $r['tukar_jam'],
                $r['tidak_hadir'],
                $totalSesi,
                $pct . '%'
            ], ';');
        }
        fclose($fp);
        exit;

    } elseif ($action === 'export_detail') {
        // Ekspor Seluruh Baris Detail Log Presensi
        $sqlDetailExport = "SELECT kd.tanggal, kd.waktu_absen, d.kode_nidn, d.nama AS dosen_nama,
                                   COALESCE(j.mata_kuliah, kd.keterangan, 'Perkuliahan') AS mata_kuliah,
                                   j.kelas, pr.nama AS prodi_nama, j.ruang,
                                   kd.status, kd.mode, kd.jam_ganti, kd.keterangan, kd.metode
                            FROM `kehadiran_dosen` kd
                            JOIN `dosen` d ON d.id = kd.dosen_id
                            LEFT JOIN `jadwal_kuliah` j ON j.id = kd.jadwal_id
                            LEFT JOIN `prodi` pr ON pr.id = j.prodi_id
                            WHERE kd.tanggal LIKE :bulan
                              AND (kd.jadwal_id > 0 OR NOT EXISTS (
                                  SELECT 1 FROM `kehadiran_dosen` kd2 
                                  WHERE kd2.dosen_id = kd.dosen_id 
                                    AND kd2.tanggal = kd.tanggal 
                                    AND kd2.jadwal_id > 0
                              ))
                            ORDER BY kd.tanggal DESC, kd.waktu_absen DESC";
        $stmtExpDet = $pdo->prepare($sqlDetailExport);
        $stmtExpDet->execute(['bulan' => $bulan . '%']);
        $rowsExpDet = $stmtExpDet->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="log_detail_presensi_dosen_' . $bulan . '.csv"');
        echo "\xEF\xBB\xBF";
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Tanggal', 'Jam Konfirmasi', 'NIDN', 'Nama Dosen', 'Mata Kuliah', 'Kelas', 'Prodi', 'Ruang', 'Status', 'Mode Kuliah', 'Jam Pengganti', 'Keterangan/Link', 'Metode'], ';');

        foreach ($rowsExpDet as $r) {
            $statusLabel = match($r['status']) {
                'hadir' => ($r['mode'] === 'online' ? 'Hadir Online' : 'Hadir Offline'),
                'tukar_jam' => 'Tukar Jam',
                'tidak_hadir', 'tidak', 'izin', 'alpa' => 'Tidak Hadir (Izin)',
                default => $r['status']
            };

            fputcsv($fp, [
                $r['tanggal'],
                $r['waktu_absen'] ? substr($r['waktu_absen'], 0, 5) : '-',
                $r['kode_nidn'],
                $r['dosen_nama'],
                $r['mata_kuliah'],
                $r['kelas'] ?? '-',
                $r['prodi_nama'] ?? '-',
                $r['ruang'] ?? '-',
                $statusLabel,
                strtoupper($r['mode'] ?: '-'),
                $r['jam_ganti'] ?: '-',
                $r['keterangan'] ?: '-',
                $r['metode'] ?: 'portal_dosen'
            ], ';');
        }
        fclose($fp);
        exit;
    }
}

// 2. LOGIKA STATE & FILTER
$activeTab = $_GET['tab'] ?? 'rekap';
$selectedMonth = $_GET['bulan'] ?? date('Y-m');
$selectedDate = $_GET['tanggal'] ?? date('Y-m-d');
$filterDosenId = (int)($_GET['dosen_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');

$message = '';
$error = '';

// 3. HANDLER POST ADMIN (SIMPAN / KOREKSI / HAPUS SESI DOSEN)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'simpan_presensi_dosen') {
        $did = (int)($_POST['dosen_id'] ?? 0);
        $jid = (int)($_POST['jadwal_id'] ?? 0);
        $tgl = trim($_POST['tanggal'] ?? date('Y-m-d'));
        $status = trim($_POST['status'] ?? 'hadir');
        $mode = trim($_POST['mode'] ?? 'offline');
        $waktuAbsen = trim($_POST['waktu_absen'] ?? date('H:i'));
        $jamGanti = trim($_POST['jam_ganti'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($did <= 0 || empty($tgl)) {
            $error = 'Dosen dan tanggal perkuliahan wajib dipilih!';
        } else {
            if (strlen($waktuAbsen) === 5) $waktuAbsen .= ':00';

            try {
                $stmtCek = $pdo->prepare(
                    "SELECT `id` FROM `kehadiran_dosen` WHERE `dosen_id` = :did AND `jadwal_id` = :jid AND `tanggal` = :tgl LIMIT 1"
                );
                $stmtUpd = $pdo->prepare(
                    "UPDATE `kehadiran_dosen`
                     SET `status` = :status, `mode` = :mode, `jam_ganti` = :jam_ganti, `keterangan` = :ket,
                         `waktu_absen` = :waktu, `metode` = 'manual', `ip_address` = :ip, `updated_at` = NOW()
                     WHERE `id` = :id"
                );
                $stmtIns = $pdo->prepare(
                    "INSERT INTO `kehadiran_dosen`
                        (`dosen_id`, `jadwal_id`, `tanggal`, `status`, `mode`, `jam_ganti`, `keterangan`, `waktu_absen`, `metode`, `ip_address`, `created_at`, `updated_at`)
                     VALUES
                        (:did, :jid, :tgl, :status, :mode, :jam_ganti, :ket, :waktu, 'manual', :ip, NOW(), NOW())"
                );

                // Simpan untuk jadwal spesifik dan juga fallback jadwal_id = 0 agar tampilan Kiosk selalu tersinkron
                $targetJids = array_unique([$jid, 0]);
                $ipAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $modeVal = $status === 'hadir' ? $mode : null;

                foreach ($targetJids as $tJid) {
                    $stmtCek->execute(['did' => $did, 'jid' => $tJid, 'tgl' => $tgl]);
                    $existId = $stmtCek->fetchColumn();

                    if ($existId) {
                        $stmtUpd->execute([
                            'status' => $status,
                            'mode' => $modeVal,
                            'jam_ganti' => $jamGanti ?: null,
                            'ket' => $keterangan ?: null,
                            'waktu' => $waktuAbsen,
                            'ip' => $ipAddr,
                            'id' => $existId
                        ]);
                    } else {
                        $stmtIns->execute([
                            'did' => $did,
                            'jid' => $tJid,
                            'tgl' => $tgl,
                            'status' => $status,
                            'mode' => $modeVal,
                            'jam_ganti' => $jamGanti ?: null,
                            'ket' => $keterangan ?: null,
                            'waktu' => $waktuAbsen,
                            'ip' => $ipAddr
                        ]);
                    }
                }

                $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `created_at`)
                               VALUES ('admin', :uid, 'koreksi_presensi_dosen', 'kehadiran_dosen', :did, NOW())")
                    ->execute(['uid' => $admin['id'], 'did' => $did]);

                $message = "Data presensi dosen berhasil disimpan dan terekap dalam sistem!";
            } catch (Exception $e) {
                $error = "Gagal menyimpan presensi dosen: " . $e->getMessage();
            }
        }
    } elseif ($formAction === 'edit_sesi_dosen') {
        $kid = (int)($_POST['kehadiran_id'] ?? 0);
        $tgl = trim($_POST['tanggal'] ?? '');
        $status = trim($_POST['status'] ?? 'hadir');
        $mode = trim($_POST['mode'] ?? 'offline');
        $waktuAbsen = trim($_POST['waktu_absen'] ?? '');
        $jamGanti = trim($_POST['jam_ganti'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($kid <= 0 || empty($tgl)) {
            $error = 'ID presensi dan tanggal wajib diisi!';
        } else {
            if (!empty($waktuAbsen) && strlen($waktuAbsen) === 5) $waktuAbsen .= ':00';
            $modeVal = $status === 'hadir' ? $mode : null;

            $stmtOrig = $pdo->prepare("SELECT `dosen_id`, `jadwal_id`, `tanggal` FROM `kehadiran_dosen` WHERE `id` = :id LIMIT 1");
            $stmtOrig->execute(['id' => $kid]);
            $origRow = $stmtOrig->fetch();

            $stmtUpd = $pdo->prepare(
                "UPDATE `kehadiran_dosen`
                 SET `tanggal` = :tgl, `status` = :status, `mode` = :mode, `jam_ganti` = :jam_ganti,
                     `keterangan` = :ket, `waktu_absen` = :waktu, `metode` = 'manual', `updated_at` = NOW()
                 WHERE `id` = :id"
            );
            $stmtUpd->execute([
                'tgl' => $tgl,
                'status' => $status,
                'mode' => $modeVal,
                'jam_ganti' => $jamGanti ?: null,
                'ket' => $keterangan ?: null,
                'waktu' => $waktuAbsen ?: null,
                'id' => $kid
            ]);

            // Sinkronkan juga baris fallback jadwal_id = 0 bila tanggal sama
            if ($origRow && (int)$origRow['jadwal_id'] > 0 && $origRow['tanggal'] === $tgl) {
                $pdo->prepare("UPDATE `kehadiran_dosen` SET `status` = :status, `mode` = :mode, `jam_ganti` = :jam_ganti, `keterangan` = :ket, `waktu_absen` = :waktu, `updated_at` = NOW()
                               WHERE `dosen_id` = :did AND `jadwal_id` = 0 AND `tanggal` = :tgl")
                    ->execute([
                        'status' => $status,
                        'mode' => $modeVal,
                        'jam_ganti' => $jamGanti ?: null,
                        'ket' => $keterangan ?: null,
                        'waktu' => $waktuAbsen ?: null,
                        'did' => $origRow['dosen_id'],
                        'tgl' => $tgl
                    ]);
            }

            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                           VALUES ('admin', :uid, 'edit_presensi_dosen', 'kehadiran_dosen', :kid, :meta, NOW())")
                ->execute(['uid' => $admin['id'], 'kid' => $kid, 'meta' => json_encode(['tanggal' => $tgl, 'status' => $status, 'mode' => $modeVal])]);

            $message = "Data presensi dosen berhasil diperbarui!";
        }
    } elseif ($formAction === 'hapus_sesi_dosen') {
        $kid = (int)($_POST['kehadiran_id'] ?? 0);
        if ($kid > 0) {
            $stmtOrig = $pdo->prepare("SELECT `dosen_id`, `jadwal_id`, `tanggal` FROM `kehadiran_dosen` WHERE `id` = :id LIMIT 1");
            $stmtOrig->execute(['id' => $kid]);
            $origRow = $stmtOrig->fetch();

            $pdo->prepare("DELETE FROM `kehadiran_dosen` WHERE `id` = :id")->execute(['id' => $kid]);

            // Jika baris spesifik jadwal dihapus dan tidak ada jadwal lain di tanggal tsb, bersihkan juga fallback jadwal_id = 0
            if ($origRow && (int)$origRow['jadwal_id'] > 0) {
                $stmtRem = $pdo->prepare("SELECT COUNT(*) FROM `kehadiran_dosen` WHERE `dosen_id` = :did AND `tanggal` = :tgl AND `jadwal_id` > 0");
                $stmtRem->execute(['did' => $origRow['dosen_id'], 'tgl' => $origRow['tanggal']]);
                if ((int)$stmtRem->fetchColumn() === 0) {
                    $pdo->prepare("DELETE FROM `kehadiran_dosen` WHERE `dosen_id` = :did AND `tanggal` = :tgl AND `jadwal_id` = 0")
                        ->execute(['did' => $origRow['dosen_id'], 'tgl' => $origRow['tanggal']]);
                }
            }

            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `created_at`)
                           VALUES ('admin', :uid, 'hapus_presensi_dosen', 'kehadiran_dosen', :kid, NOW())")
                ->execute(['uid' => $admin['id'], 'kid' => $kid]);

            $message = "Sesi presensi dosen berhasil dihapus dari rekap.";
        }
    } elseif ($formAction === 'hapus_dosen_bulan') {
        $did = (int)($_POST['dosen_id'] ?? 0);
        $targetBulan = trim($_POST['bulan'] ?? '');
        if ($did > 0 && preg_match('/^\d{4}-\d{2}$/', $targetBulan)) {
            $stmtDel = $pdo->prepare("DELETE FROM `kehadiran_dosen` WHERE `dosen_id` = :did AND `tanggal` LIKE :bln");
            $stmtDel->execute(['did' => $did, 'bln' => $targetBulan . '%']);
            $deleted = $stmtDel->rowCount();
            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                           VALUES ('admin', :uid, 'hapus_rekap_dosen_bulan', 'kehadiran_dosen', :did, :meta, NOW())")
                ->execute(['uid' => $admin['id'], 'did' => $did, 'meta' => json_encode(['bulan' => $targetBulan, 'jumlah' => $deleted])]);
            $message = "Berhasil menghapus {$deleted} data presensi dosen tersebut pada bulan {$targetBulan}.";
        }
    } elseif ($formAction === 'hapus_bulan_dosen') {
        $targetBulan = trim($_POST['bulan'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $targetBulan)) {
            $stmtDel = $pdo->prepare("DELETE FROM `kehadiran_dosen` WHERE `tanggal` LIKE :bln");
            $stmtDel->execute(['bln' => $targetBulan . '%']);
            $deleted = $stmtDel->rowCount();
            $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `meta`, `created_at`)
                           VALUES ('admin', :uid, 'hapus_rekap_dosen_semua_bulan', 'kehadiran_dosen', :meta, NOW())")
                ->execute(['uid' => $admin['id'], 'meta' => json_encode(['bulan' => $targetBulan, 'jumlah' => $deleted])]);
            $message = "Berhasil menghapus {$deleted} rekaman presensi dosen pada bulan {$targetBulan}.";
        }
    } elseif ($formAction === 'hapus_semua_dosen') {
        $stmtDel = $pdo->prepare("DELETE FROM `kehadiran_dosen`");
        $stmtDel->execute();
        $deleted = $stmtDel->rowCount();
        $pdo->prepare("INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `meta`, `created_at`)
                       VALUES ('admin', :uid, 'kosongkan_semua_presensi_dosen', 'kehadiran_dosen', :meta, NOW())")
            ->execute(['uid' => $admin['id'], 'meta' => json_encode(['jumlah_dihapus' => $deleted])]);
        $message = "Seluruh data presensi dosen ({$deleted} baris) telah dikosongkan. Rekap kini bersih (0).";
    }
}

$totalAllKehadiranDosen = (int) $pdo->query("SELECT COUNT(*) FROM `kehadiran_dosen`")->fetchColumn();

// 4. DATA DOSEN UNTUK FILTER
$allDosenList = $pdo->query("SELECT id, kode_nidn, nama FROM `dosen` WHERE aktif = 1 AND deleted_at IS NULL ORDER BY nama ASC")->fetchAll();

// 5. QUERY AGREGASI KPI BULANAN
$sqlKpi = "SELECT 
               COUNT(*) AS total_sesi,
               SUM(CASE WHEN kd.status = 'hadir' AND kd.mode = 'offline' THEN 1 ELSE 0 END) AS hadir_offline,
               SUM(CASE WHEN kd.status = 'hadir' AND kd.mode = 'online' THEN 1 ELSE 0 END) AS hadir_online,
               SUM(CASE WHEN kd.status = 'tukar_jam' THEN 1 ELSE 0 END) AS tukar_jam,
               SUM(CASE WHEN kd.status IN ('tidak_hadir', 'tidak', 'izin', 'alpa') THEN 1 ELSE 0 END) AS tidak_hadir
           FROM `kehadiran_dosen` kd
           WHERE kd.tanggal LIKE :bulan
             AND (kd.jadwal_id > 0 OR NOT EXISTS (
                 SELECT 1 FROM `kehadiran_dosen` kd2 
                 WHERE kd2.dosen_id = kd.dosen_id 
                   AND kd2.tanggal = kd.tanggal 
                   AND kd2.jadwal_id > 0
             ))";
$stmtKpi = $pdo->prepare($sqlKpi);
$stmtKpi->execute(['bulan' => $selectedMonth . '%']);
$kpi = $stmtKpi->fetch();

$kpiTotalSesi = (int)($kpi['total_sesi'] ?? 0);
$kpiHadirOffline = (int)($kpi['hadir_offline'] ?? 0);
$kpiHadirOnline = (int)($kpi['hadir_online'] ?? 0);
$kpiTukarJam = (int)($kpi['tukar_jam'] ?? 0);
$kpiTidakHadir = (int)($kpi['tidak_hadir'] ?? 0);
$kpiTotalHadir = $kpiHadirOffline + $kpiHadirOnline;
$kpiPersenHadir = $kpiTotalSesi > 0 ? round(($kpiTotalHadir / $kpiTotalSesi) * 100, 1) : 0;

// 6. TAB 1: REKAPITULASI BULANAN PER DOSEN
$sqlRekapDosen = "SELECT d.id, d.kode_nidn, d.nama, d.foto_path,
                         COUNT(DISTINCT j.id) AS total_jadwal,
                         COALESCE(SUM(CASE WHEN kd.status = 'hadir' AND kd.mode = 'offline' THEN 1 ELSE 0 END), 0) AS hadir_offline,
                         COALESCE(SUM(CASE WHEN kd.status = 'hadir' AND kd.mode = 'online' THEN 1 ELSE 0 END), 0) AS hadir_online,
                         COALESCE(SUM(CASE WHEN kd.status = 'tukar_jam' THEN 1 ELSE 0 END), 0) AS tukar_jam,
                         COALESCE(SUM(CASE WHEN kd.status IN ('tidak_hadir', 'tidak', 'izin', 'alpa') THEN 1 ELSE 0 END), 0) AS tidak_hadir,
                         COALESCE(COUNT(kd.id), 0) AS total_sesi_terekap
                  FROM `dosen` d
                  LEFT JOIN `jadwal_kuliah` j ON j.dosen_id = d.id AND j.aktif = 1
                  LEFT JOIN `kehadiran_dosen` kd ON kd.dosen_id = d.id 
                       AND kd.tanggal LIKE :bulan 
                       AND (kd.jadwal_id > 0 OR NOT EXISTS (
                           SELECT 1 FROM `kehadiran_dosen` kd2 
                           WHERE kd2.dosen_id = kd.dosen_id 
                             AND kd2.tanggal = kd.tanggal 
                             AND kd2.jadwal_id > 0
                       ))
                  WHERE d.deleted_at IS NULL AND d.aktif = 1 " .
                  ($filterDosenId > 0 ? "AND d.id = {$filterDosenId} " : "") .
                  "GROUP BY d.id
                  ORDER BY d.nama ASC";
$stmtRekap = $pdo->prepare($sqlRekapDosen);
$stmtRekap->execute(['bulan' => $selectedMonth . '%']);
$dosenRekapList = $stmtRekap->fetchAll();

// 7. TAB 2: LOG & RIWAYAT DETAIL PRESENSI
$whereDetail = ["kd.tanggal LIKE :bulan"];
$paramsDetail = ['bulan' => $selectedMonth . '%'];

if ($filterDosenId > 0) {
    $whereDetail[] = "kd.dosen_id = :did";
    $paramsDetail['did'] = $filterDosenId;
}
if (!empty($filterStatus)) {
    if ($filterStatus === 'hadir_offline') {
        $whereDetail[] = "kd.status = 'hadir' AND kd.mode = 'offline'";
    } elseif ($filterStatus === 'hadir_online') {
        $whereDetail[] = "kd.status = 'hadir' AND kd.mode = 'online'";
    } elseif ($filterStatus === 'tukar_jam') {
        $whereDetail[] = "kd.status = 'tukar_jam'";
    } elseif ($filterStatus === 'tidak_hadir') {
        $whereDetail[] = "kd.status IN ('tidak_hadir', 'tidak', 'izin', 'alpa')";
    }
}

$sqlDetail = "SELECT kd.*, d.nama AS dosen_nama, d.kode_nidn, d.foto_path,
                     j.mata_kuliah, j.kelas, j.ruang, j.jam_mulai, j.jam_selesai,
                     pr.nama AS prodi_nama
              FROM `kehadiran_dosen` kd
              JOIN `dosen` d ON d.id = kd.dosen_id
              LEFT JOIN `jadwal_kuliah` j ON j.id = kd.jadwal_id
              LEFT JOIN `prodi` pr ON pr.id = j.prodi_id
              WHERE " . implode(' AND ', $whereDetail) . "
                AND (kd.jadwal_id > 0 OR NOT EXISTS (
                    SELECT 1 FROM `kehadiran_dosen` kd2 
                    WHERE kd2.dosen_id = kd.dosen_id 
                      AND kd2.tanggal = kd.tanggal 
                      AND kd2.jadwal_id > 0
                ))
              ORDER BY kd.tanggal DESC, kd.waktu_absen DESC, kd.id DESC";
$stmtDetail = $pdo->prepare($sqlDetail);
$stmtDetail->execute($paramsDetail);
$detailList = $stmtDetail->fetchAll();

require_once __DIR__ . '/header.php';
?>

<!-- TOGGLE NAVIGASI: PENGURUS vs DOSEN -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <div style="display:flex;gap:8px">
        <a href="absensi.php" class="btn btn-secondary" style="font-weight:700">
            👥 Presensi Pengurus &amp; Tendik
        </a>
        <a href="absensi_dosen.php" class="btn" style="background:#0284c7;color:#fff;font-weight:800">
            🎓 Rekap &amp; Presensi Dosen
        </a>
    </div>
    <div>
        <a href="../dosen/index.php" target="_blank" class="btn btn-secondary" style="background:#e0f2fe;color:#0284c7;border:1px solid #bae6fd">
            👨‍🏫 Buka Portal Dosen &rarr;
        </a>
    </div>
</div>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- STATISTIK KPI PRESENSI DOSEN BULAN INI -->
<div class="stats-grid">
    <div class="stat-card" style="border-left:4px solid #0284c7">
        <small><i class="fa-solid fa-calendar-check" style="color:#0284c7"></i> Total Sesi Terekap</small>
        <b style="color:#0284c7"><?= $kpiTotalSesi ?> Sesi</b>
        <span style="font-size:12px;color:var(--muted)">Bulan <?= date('M Y', strtotime($selectedMonth . '-01')) ?></span>
    </div>
    <div class="stat-card" style="border-left:4px solid #16a34a">
        <small><i class="fa-solid fa-chalkboard-user" style="color:#16a34a"></i> Hadir Offline (Tatap Muka)</small>
        <b style="color:#16a34a"><?= $kpiHadirOffline ?> Sesi</b>
        <span style="font-size:12px;color:var(--muted)">Di Ruang Kampus</span>
    </div>
    <div class="stat-card" style="border-left:4px solid #0284c7">
        <small><i class="fa-solid fa-globe" style="color:#0284c7"></i> Hadir Online (Daring)</small>
        <b style="color:#0284c7"><?= $kpiHadirOnline ?> Sesi</b>
        <span style="font-size:12px;color:var(--muted)">Via Zoom / Meet</span>
    </div>
    <div class="stat-card" style="border-left:4px solid #d97706">
        <small><i class="fa-solid fa-clock-rotate-left" style="color:#d97706"></i> Tukar Jam (Reschedule)</small>
        <b style="color:#d97706"><?= $kpiTukarJam ?> Sesi</b>
        <span style="font-size:12px;color:var(--muted)">Jadwal Pengganti</span>
    </div>
    <div class="stat-card" style="border-left:4px solid #dc2626">
        <small><i class="fa-solid fa-user-xmark" style="color:#dc2626"></i> Tidak Hadir (Izin/Sakit)</small>
        <b style="color:#dc2626"><?= $kpiTidakHadir ?> Sesi</b>
        <span style="font-size:12px;color:var(--muted)">Izin / Berhalangan</span>
    </div>
    <div class="stat-card" style="border-left:4px solid #4f46e5;background:linear-gradient(135deg, #ffffff, #f5f3ff)">
        <small><i class="fa-solid fa-chart-line" style="color:#4f46e5"></i> Tingkat Kehadiran (%)</small>
        <b style="color:#4f46e5"><?= $kpiPersenHadir ?>%</b>
        <span style="font-size:12px;color:var(--muted)"><?= $kpiTotalHadir ?> dari <?= $kpiTotalSesi ?> Sesi</span>
    </div>
</div>

<!-- BAR FILTER & TABS -->
<div class="card" style="padding:16px 20px;margin-bottom:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <!-- Tab Navigasi -->
        <div style="display:flex;gap:6px">
            <a href="?tab=rekap&bulan=<?= urlencode($selectedMonth) ?>&dosen_id=<?= $filterDosenId ?>" 
               class="btn <?= $activeTab === 'rekap' ? '' : 'btn-secondary' ?>" style="<?= $activeTab === 'rekap' ? 'background:#0284c7' : '' ?>">
                📊 Rekapitulasi Bulanan
            </a>
            <a href="?tab=detail&bulan=<?= urlencode($selectedMonth) ?>&dosen_id=<?= $filterDosenId ?>&status=<?= urlencode($filterStatus) ?>" 
               class="btn <?= $activeTab === 'detail' ? '' : 'btn-secondary' ?>" style="<?= $activeTab === 'detail' ? 'background:#0284c7' : '' ?>">
                📋 Log Riwayat Detail (<?= count($detailList) ?>)
            </a>
            <a href="?tab=input&bulan=<?= urlencode($selectedMonth) ?>" 
               class="btn <?= $activeTab === 'input' ? '' : 'btn-secondary' ?>" style="<?= $activeTab === 'input' ? 'background:#0284c7' : '' ?>">
                ➕ Catat Presensi Manual
            </a>
        </div>

        <!-- Filter Form -->
        <form method="GET" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
            
            <label style="font-size:12px;font-weight:700;color:var(--muted)">Bulan:</label>
            <input type="month" name="bulan" value="<?= htmlspecialchars($selectedMonth) ?>" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px" onchange="this.form.submit()">

            <label style="font-size:12px;font-weight:700;color:var(--muted);margin-left:6px">Dosen:</label>
            <select name="dosen_id" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;max-width:180px" onchange="this.form.submit()">
                <option value="0">-- Semua Dosen --</option>
                <?php foreach ($allDosenList as $ad): ?>
                    <option value="<?= $ad['id'] ?>" <?= $filterDosenId === (int)$ad['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ad['nama']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($activeTab === 'detail'): ?>
                <label style="font-size:12px;font-weight:700;color:var(--muted);margin-left:6px">Status:</label>
                <select name="status" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px" onchange="this.form.submit()">
                    <option value="">-- Semua Status --</option>
                    <option value="hadir_offline" <?= $filterStatus === 'hadir_offline' ? 'selected' : '' ?>>🏫 Hadir Offline</option>
                    <option value="hadir_online" <?= $filterStatus === 'hadir_online' ? 'selected' : '' ?>>🌐 Hadir Online</option>
                    <option value="tukar_jam" <?= $filterStatus === 'tukar_jam' ? 'selected' : '' ?>>🔄 Tukar Jam</option>
                    <option value="tidak_hadir" <?= $filterStatus === 'tidak_hadir' ? 'selected' : '' ?>>❌ Tidak Hadir</option>
                </select>
            <?php endif; ?>

            <noscript><button type="submit" class="btn" style="padding:6px 12px;font-size:12px">Terapkan</button></noscript>
        </form>
    </div>
</div>

<?php if ($activeTab === 'rekap'): ?>
    <!-- TAB 1: TABEL REKAPITULASI BULANAN -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px">
            <div>
                <h3>📊 Rekapitulasi Presensi Dosen — Bulan <?= tglIndo($selectedMonth . '-01') ?></h3>
                <p style="font-size:13px;color:var(--muted)">
                    Akumulasi data konfirmasi kehadiran yang dikirim dosen lewat portal maupun koreksi admin.
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="?action=export_rekap&bulan=<?= urlencode($selectedMonth) ?>" class="btn" style="background:#16a34a">
                    📥 Ekspor CSV Rekap
                </a>
                <?php if ($kpiTotalSesi > 0): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Hapus seluruh data presensi dosen pada bulan <?= htmlspecialchars($selectedMonth) ?>?')">
                    <input type="hidden" name="form_action" value="hapus_bulan_dosen">
                    <input type="hidden" name="bulan" value="<?= htmlspecialchars($selectedMonth) ?>">
                    <button type="submit" class="btn btn-danger">🗑️ Hapus Data Bulan Ini</button>
                </form>
                <?php endif; ?>
                <?php if ($totalAllKehadiranDosen > 0): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('PERHATIAN! Anda akan mengosongkan SELURUH <?= $totalAllKehadiranDosen ?> rekaman presensi dosen dari semua bulan. Lanjutkan?')">
                    <input type="hidden" name="form_action" value="hapus_semua_dosen">
                    <button type="submit" class="btn" style="background:#7f1d1d;color:#fff">🧹 Kosongkan Semua Data</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:50px">No</th>
                    <th>Dosen Pengajar</th>
                    <th>NIDN</th>
                    <th>Jadwal Mingguan</th>
                    <th>Hadir Offline</th>
                    <th>Hadir Online</th>
                    <th>Tukar Jam</th>
                    <th>Tidak Hadir</th>
                    <th>Total Terekap</th>
                    <th>% Kehadiran</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($dosenRekapList)): ?>
                    <tr>
                        <td colspan="11" style="text-align:center;padding:30px;color:var(--muted)">
                            Tidak ada data dosen yang sesuai dengan filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dosenRekapList as $idx => $r): ?>
                    <?php
                        $totHadir = (int)$r['hadir_offline'] + (int)$r['hadir_online'];
                        $totSesi = (int)$r['total_sesi_terekap'];
                        $pct = $totSesi > 0 ? round(($totHadir / $totSesi) * 100, 1) : 0;
                        $fotoUrl = !empty($r['foto_path']) ? '../storage/' . ltrim($r['foto_path'], '/') : null;
                    ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <div style="width:36px;height:36px;border-radius:10px;background:#e0f2fe;color:#0284c7;display:grid;place-items:center;font-weight:800;font-size:13px;overflow:hidden;flex-shrink:0">
                                    <?php if ($fotoUrl): ?>
                                        <img src="<?= htmlspecialchars($fotoUrl) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
                                    <?php else: ?>
                                        <?= strtoupper(substr($r['nama'], 0, 2)) ?>
                                    <?php endif; ?>
                                </div>
                                <b><?= htmlspecialchars($r['nama']) ?></b>
                            </div>
                        </td>
                        <td><code><?= htmlspecialchars($r['kode_nidn']) ?></code></td>
                        <td><span class="badge" style="background:#f1f5f9;color:var(--ink)"><?= $r['total_jadwal'] ?> Kelas</span></td>
                        <td><b><?= $r['hadir_offline'] ?></b></td>
                        <td><b><?= $r['hadir_online'] ?></b></td>
                        <td><b><?= $r['tukar_jam'] ?></b></td>
                        <td><b><?= $r['tidak_hadir'] ?></b></td>
                        <td><b><?= $totSesi ?> Sesi</b></td>
                        <td>
                            <?php if ($pct >= 85): ?>
                                <span class="badge ok"><?= $pct ?>%</span>
                            <?php elseif ($pct >= 70): ?>
                                <span class="badge" style="background:#fef3c7;color:#b45309"><?= $pct ?>%</span>
                            <?php else: ?>
                                <span class="badge err"><?= $pct ?>%</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <a href="?tab=detail&bulan=<?= urlencode($selectedMonth) ?>&dosen_id=<?= $r['id'] ?>" class="btn btn-secondary" style="padding:4px 8px;font-size:11px" title="Lihat riwayat rincian dosen ini">
                                    🔍 Rincian
                                </a>
                                <?php if ($totSesi > 0): ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Hapus seluruh data presensi milik <?= addslashes($r['nama']) ?> pada bulan <?= htmlspecialchars($selectedMonth) ?>?')">
                                    <input type="hidden" name="form_action" value="hapus_dosen_bulan">
                                    <input type="hidden" name="dosen_id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="bulan" value="<?= htmlspecialchars($selectedMonth) ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px">🗑️ Reset</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($activeTab === 'detail'): ?>
    <!-- TAB 2: TABEL LOG DETAIL PRESENSI DOSEN -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px">
            <div>
                <h3>📋 Log Detail Presensi Dosen — Bulan <?= tglIndo($selectedMonth . '-01') ?></h3>
                <p style="font-size:13px;color:var(--muted)">
                    Rincian setiap sesi perkuliahan beserta mode (Offline / Online), tautan meeting, jam ganti, dan catatan. Klik <b>Edit</b> atau <b>Hapus</b> untuk memvalidasi data.
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="?action=export_detail&bulan=<?= urlencode($selectedMonth) ?>" class="btn" style="background:#16a34a">
                    📥 Ekspor CSV Detail
                </a>
                <?php if ($totalAllKehadiranDosen > 0): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('PERHATIAN! Anda akan mengosongkan SELURUH <?= $totalAllKehadiranDosen ?> rekaman presensi dosen dari semua bulan. Lanjutkan?')">
                    <input type="hidden" name="form_action" value="hapus_semua_dosen">
                    <button type="submit" class="btn" style="background:#7f1d1d;color:#fff">🧹 Kosongkan Semua (<?= $totalAllKehadiranDosen ?>)</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Tanggal &amp; Jam</th>
                    <th>Dosen</th>
                    <th>Mata Kuliah &amp; Kelas</th>
                    <th>Ruang</th>
                    <th>Status Presensi</th>
                    <th>Keterangan / Link Online / Jam Ganti</th>
                    <th>Metode</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($detailList)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">
                            Belum ada rekaman presensi dosen untuk filter bulan/kriteria ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($detailList as $d): ?>
                    <?php
                        $st = $d['status'];
                        $md = $d['mode'];
                    ?>
                    <tr>
                        <td>
                            <b><?= tglIndo($d['tanggal']) ?></b>
                            <small style="display:block;color:var(--muted)"><?= $d['waktu_absen'] ? substr($d['waktu_absen'], 0, 5) . ' WIB' : '—' ?></small>
                        </td>
                        <td>
                            <b><?= htmlspecialchars($d['dosen_nama']) ?></b>
                            <small style="display:block;color:var(--muted)"><?= htmlspecialchars($d['kode_nidn']) ?></small>
                        </td>
                        <td>
                            <b><?= htmlspecialchars($d['mata_kuliah'] ?: 'Perkuliahan Dosen') ?></b>
                            <small style="display:block;color:var(--muted)">
                                <?= htmlspecialchars($d['prodi_nama'] ?: 'Kampus') ?> <?= $d['kelas'] ? '· Kelas ' . htmlspecialchars($d['kelas']) : '' ?>
                            </small>
                        </td>
                        <td><?= htmlspecialchars($d['ruang'] ?: '—') ?></td>
                        <td>
                            <?php if ($st === 'hadir' && $md === 'online'): ?>
                                <span class="badge" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd">
                                    🌐 Hadir Online (Daring)
                                </span>
                            <?php elseif ($st === 'hadir'): ?>
                                <span class="badge ok">
                                    🏫 Hadir Offline (Tatap Muka)
                                </span>
                            <?php elseif ($st === 'tukar_jam'): ?>
                                <span class="badge" style="background:#fef3c7;color:#b45309;border:1px solid #fde68a">
                                    🔄 Tukar Jam
                                </span>
                            <?php else: ?>
                                <span class="badge err">
                                    ❌ Tidak Hadir (Izin)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['jam_ganti']): ?>
                                <span style="font-size:12px;font-weight:700;color:#b45309">Jam Baru: <?= htmlspecialchars($d['jam_ganti']) ?></span><br>
                            <?php endif; ?>
                            <?php if ($d['keterangan']): ?>
                                <span style="font-size:12px;color:var(--ink)"><?= htmlspecialchars($d['keterangan']) ?></span>
                            <?php else: ?>
                                <span style="color:var(--muted);font-size:12px">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge" style="background:#f8fafc;color:var(--muted);font-size:11px">
                                <?= $d['metode'] === 'portal_dosen' ? '📱 Portal Dosen' : '⚙️ Admin Manual' ?>
                            </span>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <button type="button" class="btn btn-secondary" style="padding:4px 8px;font-size:11px" onclick='openEditDosenModal(<?= json_encode([
                                    "id" => (int)$d["id"],
                                    "dosen_nama" => $d["dosen_nama"],
                                    "tanggal" => $d["tanggal"],
                                    "status" => $d["status"],
                                    "mode" => $d["mode"] ?: "offline",
                                    "waktu_absen" => $d["waktu_absen"] ? substr($d["waktu_absen"], 0, 5) : "",
                                    "jam_ganti" => $d["jam_ganti"] ?? "",
                                    "keterangan" => $d["keterangan"] ?? ""
                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    ✏️ Edit
                                </button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Hapus baris rekaman presensi dosen ini?')">
                                    <input type="hidden" name="form_action" value="hapus_sesi_dosen">
                                    <input type="hidden" name="kehadiran_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:11px">
                                        🗑️ Hapus
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($activeTab === 'input'): ?>
    <!-- TAB 3: CATAT PRESENSI MANUAL OLEH ADMIN -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
        <div class="card">
            <h3>➕ Catat / Koreksi Presensi Dosen Manual</h3>
            <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
                Gunakan form ini untuk merekap atau mengoreksi presensi dosen untuk tanggal lampau atau jika dosen berhalangan mengakses portal.
            </p>

            <form method="POST">
                <input type="hidden" name="form_action" value="simpan_presensi_dosen">

                <div class="form-group">
                    <label>Pilih Dosen Pengajar</label>
                    <select name="dosen_id" id="inputManualDosen" class="form-control" required onchange="loadJadwalDosen(this.value)">
                        <option value="">-- Pilih Dosen --</option>
                        <?php foreach ($allDosenList as $ad): ?>
                            <option value="<?= $ad['id'] ?>"><?= htmlspecialchars($ad['nama']) ?> (<?= htmlspecialchars($ad['kode_nidn']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Tanggal Perkuliahan</label>
                    <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label>Pilih Jadwal Kuliah (Opsional)</label>
                    <select name="jadwal_id" id="inputManualJadwal" class="form-control">
                        <option value="0">-- Presensi Umum / Semua Jadwal --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Status Kehadiran</label>
                    <select name="status" id="inputManualStatus" class="form-control" onchange="toggleManualMode(this.value)">
                        <option value="hadir">Hadir (Sesuai Jadwal)</option>
                        <option value="tukar_jam">Tukar Jam (Reschedule)</option>
                        <option value="tidak_hadir">Tidak Hadir (Izin/Sakit)</option>
                    </select>
                </div>

                <div class="form-group" id="groupManualMode">
                    <label>Mode Perkuliahan</label>
                    <select name="mode" class="form-control">
                        <option value="offline">🏫 Hadir Offline (Tatap Muka di Ruang)</option>
                        <option value="online">🌐 Hadir Online (Daring via Zoom/Meet)</option>
                    </select>
                </div>

                <div class="form-group" id="groupManualJamGanti" style="display:none">
                    <label>Waktu / Jam Pengganti (Tukar Jam)</label>
                    <input type="text" name="jam_ganti" placeholder="Contoh: Jam 13.00 WIB atau Kamis jam 09.00">
                </div>

                <div class="form-group">
                    <label>Jam Konfirmasi / Jam Absen</label>
                    <input type="time" name="waktu_absen" value="<?= date('H:i') ?>">
                </div>

                <div class="form-group">
                    <label>Catatan / Keterangan (Tautan Daring / Alasan)</label>
                    <input type="text" name="keterangan" placeholder="Contoh: Link Zoom atau Alasan Izin">
                </div>

                <button type="submit" class="btn" style="background:#0284c7;width:100%">
                    💾 Simpan Presensi &amp; Rekap
                </button>
            </form>
        </div>

        <div class="card" style="background:#f8fafc;border-color:var(--line)">
            <h3>💡 Petunjuk Rekapitulasi Presensi Dosen</h3>
            <div style="font-size:13.5px;color:var(--muted);line-height:1.6;display:flex;flex-direction:column;gap:12px;margin-top:12px">
                <p>
                    <b>1. Penyimpanan Otomatis:</b> Setiap kali dosen menekan tombol <i>Hadir Offline</i>, <i>Hadir Online</i>, <i>Tukar Jam</i>, atau <i>Tidak Hadir</i> dari smartphone mereka di <code>/dosen</code>, data langsung tersimpan permanen di database dan otomatis terhitung ke dalam rekapitulasi bulanan.
                </p>
                <p>
                    <b>2. Sinkronisasi Real-Time TV:</b> Data kehadiran yang tersimpan langsung dipublikasikan ke layar Kiosk TV kampus pada panel <i>Dosen Hadir Hari Ini</i> dan jadwal kuliah harian.
                </p>
                <p>
                    <b>3. Laporan Akademik:</b> Hasil rekapitulasi dapat diekspor kapan saja ke format <b>CSV Excel</b> dengan menekan tombol <i>Ekspor CSV</i> di atas untuk keperluan administrasi, honorarium, dan evaluasi pimpinan.
                </p>
            </div>
        </div>
    </div>

    <script>
    function toggleManualMode(val) {
        const grpMode = document.getElementById('groupManualMode');
        const grpTukar = document.getElementById('groupManualJamGanti');
        if (val === 'hadir') {
            grpMode.style.display = 'block';
            grpTukar.style.display = 'none';
        } else if (val === 'tukar_jam') {
            grpMode.style.display = 'none';
            grpTukar.style.display = 'block';
        } else {
            grpMode.style.display = 'none';
            grpTukar.style.display = 'none';
        }
    }

    async function loadJadwalDosen(dosenId) {
        const sel = document.getElementById('inputManualJadwal');
        sel.innerHTML = '<option value="0">-- Presensi Umum / Semua Jadwal --</option>';
        if (!dosenId) return;

        try {
            const res = await fetch('../api/v1/dosen/jadwal.php', {
                headers: { 'Cookie': 'stai_dosen_id=' + dosenId }
            });
            // Fallback: ambil jadwal dari database via query sederhana
        } catch (e) {}
    }
    </script>
<?php endif; ?>

<!-- MODAL EDIT PRESENSI DOSEN -->
<div id="modalEditDosen" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center;padding:16px">
    <div style="background:#fff;border-radius:20px;padding:26px;width:100%;max-width:520px;box-shadow:0 10px 40px rgba(0,0,0,0.2)">
        <h3 style="margin-bottom:6px">✏️ Edit Presensi Dosen</h3>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px">Dosen: <b id="editDosenNama" style="color:var(--ink)">-</b></p>

        <form method="POST">
            <input type="hidden" name="form_action" value="edit_sesi_dosen">
            <input type="hidden" name="kehadiran_id" id="editDosenKid">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                <div>
                    <label style="font-size:13px;font-weight:700;display:block;margin-bottom:4px">Tanggal</label>
                    <input type="date" name="tanggal" id="editDosenTgl" required style="width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;font-size:14px">
                </div>
                <div>
                    <label style="font-size:13px;font-weight:700;display:block;margin-bottom:4px">Jam Konfirmasi (WIB)</label>
                    <input type="time" name="waktu_absen" id="editDosenWaktu" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:10px;font-size:14px">
                </div>
            </div>

            <div class="form-group">
                <label>Status Kehadiran</label>
                <select name="status" id="editDosenStatus" onchange="toggleEditDosenMode(this.value)">
                    <option value="hadir">Hadir (Sesuai Jadwal)</option>
                    <option value="tukar_jam">Tukar Jam (Reschedule)</option>
                    <option value="tidak_hadir">Tidak Hadir (Izin/Sakit)</option>
                </select>
            </div>

            <div class="form-group" id="editDosenModeGroup">
                <label>Mode Perkuliahan</label>
                <select name="mode" id="editDosenMode">
                    <option value="offline">🏫 Hadir Offline (Tatap Muka)</option>
                    <option value="online">🌐 Hadir Online (Daring)</option>
                </select>
            </div>

            <div class="form-group" id="editDosenJamGantiGroup" style="display:none">
                <label>Waktu / Jam Pengganti</label>
                <input type="text" name="jam_ganti" id="editDosenJamGanti" placeholder="Contoh: Kamis 13.00 WIB">
            </div>

            <div class="form-group">
                <label>Catatan / Keterangan</label>
                <input type="text" name="keterangan" id="editDosenKet" placeholder="Opsional: Link Zoom atau Alasan">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
                <button type="button" class="btn btn-secondary" onclick="closeEditDosenModal()">Batal</button>
                <button type="submit" class="btn">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleEditDosenMode(val) {
    document.getElementById('editDosenModeGroup').style.display = (val === 'hadir') ? 'block' : 'none';
    document.getElementById('editDosenJamGantiGroup').style.display = (val === 'tukar_jam') ? 'block' : 'none';
}

function openEditDosenModal(data) {
    document.getElementById('editDosenKid').value = data.id;
    document.getElementById('editDosenNama').textContent = data.dosen_nama;
    document.getElementById('editDosenTgl').value = data.tanggal;
    document.getElementById('editDosenWaktu').value = data.waktu_absen || '';
    const st = (data.status === 'hadir' || data.status === 'tukar_jam') ? data.status : 'tidak_hadir';
    document.getElementById('editDosenStatus').value = st;
    document.getElementById('editDosenMode').value = data.mode || 'offline';
    document.getElementById('editDosenJamGanti').value = data.jam_ganti || '';
    document.getElementById('editDosenKet').value = data.keterangan || '';
    toggleEditDosenMode(st);
    document.getElementById('modalEditDosen').style.display = 'flex';
}

function closeEditDosenModal() {
    document.getElementById('modalEditDosen').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
