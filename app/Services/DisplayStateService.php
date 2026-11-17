<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 2) . '/config/database.php';

use PDO;

class DisplayStateService
{
    protected PDO $pdo;
    protected WeatherService $weatherService;

    public function __construct(?PDO $pdo = null, ?WeatherService $weatherService = null)
    {
        $this->pdo = $pdo ?? getDatabasePdo();
        $this->weatherService = $weatherService ?? new WeatherService();
    }

    public function getState(): array
    {
        date_default_timezone_set('Asia/Jakarta');
        $today = date('Y-m-d');
        $currentIsoDay = (int) date('N'); // 1 = Senin .. 7 = Minggu

        // 1. Pengaturan
        $settingsRows = $this->pdo->query("SELECT `kunci`, `nilai` FROM `pengaturan`")->fetchAll();
        $pengaturan = [];
        foreach ($settingsRows as $row) {
            $pengaturan[$row['kunci']] = json_decode($row['nilai'], true);
        }

        // 1.1 Identitas Kampus & Logo
        $logoMedia = $this->pdo->query("SELECT `path` FROM `media` WHERE `koleksi` = 'logo' AND `aktif` = 1 ORDER BY `id` DESC LIMIT 1")->fetch();
        $logoUrl = null;
        if ($logoMedia && !empty($logoMedia['path'])) {
            $logoUrl = str_starts_with($logoMedia['path'], 'http') ? $logoMedia['path'] : 'storage/' . ltrim($logoMedia['path'], '/');
        } elseif (!empty($pengaturan['logo_url'])) {
            $logoUrl = $pengaturan['logo_url'];
        }

        $kampusData = [
            'nama' => $pengaturan['nama_kampus'] ?? 'STAI SIDOGIRI',
            'subjudul' => $pengaturan['subjudul_kampus'] ?? 'Sekolah Tinggi Agama Islam Sidogiri · Pasuruan, Jawa Timur',
            'kota' => $pengaturan['kota'] ?? 'Pasuruan',
            'logo_url' => $logoUrl
        ];

        // 2. Pengurus + Multi-Sesi Absensi Hari Ini
        $sqlPengurus = "SELECT p.id, p.nama, p.jabatan, p.foto_path
                        FROM `pengurus` p
                        WHERE p.aktif = 1 AND p.deleted_at IS NULL
                        ORDER BY p.urutan ASC";
        $pengurusRows = $this->pdo->query($sqlPengurus)->fetchAll();

        // Ambil seluruh sesi absensi hari ini sekaligus
        $sqlSesi = "SELECT * FROM `absensi_pengurus` WHERE `tanggal` = :today ORDER BY `sesi_ke` ASC, `id` ASC";
        $stmtSesi = $this->pdo->prepare($sqlSesi);
        $stmtSesi->execute(['today' => $today]);
        $allSessions = $stmtSesi->fetchAll();

        $sessionMap = [];
        foreach ($allSessions as $s) {
            $sessionMap[$s['pengurus_id']][] = $s;
        }

        $pengurusList = [];
        $totalMasuk = 0;
        $totalPulang = 0;

        foreach ($pengurusRows as $p) {
            $pId = (int) $p['id'];
            $pSessions = $sessionMap[$pId] ?? [];
            $fotoUrl = !empty($p['foto_path']) ? (str_starts_with($p['foto_path'], 'http') ? $p['foto_path'] : 'storage/' . ltrim($p['foto_path'], '/')) : null;

            $status = 'belum';
            $hadir = false;
            $waktuAbsen = null;
            $waktuMasuk = null;
            $waktuPulang = null;
            $sesiKe = null;
            $totalMenit = 0;

            if (!empty($pSessions)) {
                $lastS = end($pSessions);
                $sesiKe = (int) $lastS['sesi_ke'];
                $waktuMasuk = substr($lastS['waktu_masuk'] ?: $lastS['waktu_absen'], 0, 5);
                $waktuPulang = $lastS['waktu_pulang'] ? substr($lastS['waktu_pulang'], 0, 5) : null;

                foreach ($pSessions as $sRow) {
                    if ($sRow['status'] === 'pulang') {
                        $totalMenit += (int) ($sRow['durasi_menit'] ?? 0);
                    }
                }

                if ($lastS['status'] === 'masuk' || empty($lastS['waktu_pulang'])) {
                    $status = 'masuk';
                    $hadir = true;
                    $waktuAbsen = $waktuMasuk;
                    $totalMasuk++;
                } else {
                    $status = 'pulang';
                    $hadir = false;
                    $waktuAbsen = $waktuPulang;
                    $totalPulang++;
                }
            }

            $pengurusList[] = [
                'id' => $pId,
                'nama' => $p['nama'],
                'jabatan' => $p['jabatan'],
                'foto_url' => $fotoUrl,
                'status' => $status, // 'belum' | 'masuk' | 'pulang'
                'hadir' => $hadir,   // true hanya jika sedang hadir di kampus
                'waktu_absen' => $waktuAbsen,
                'waktu_masuk' => $waktuMasuk,
                'waktu_pulang' => $waktuPulang,
                'sesi_ke' => $sesiKe,
                'total_sesi' => count($pSessions),
                'total_durasi_menit' => $totalMenit,
                'total_durasi_formatted' => $totalMenit > 0 ? self::formatDurasiShort($totalMenit) : null
            ];
        }

        // 3. Jadwal Hari Ini & Status Kehadiran Dosen
        // 3.1 Ambil data konfirmasi kehadiran dosen hari ini
        $sqlKehadiranDosen = "SELECT kd.*, d.nama AS dosen_nama, d.foto_path AS dosen_foto
                              FROM `kehadiran_dosen` kd
                              JOIN `dosen` d ON d.id = kd.dosen_id
                              WHERE kd.tanggal = :today";
        $stmtKD = $this->pdo->prepare($sqlKehadiranDosen);
        $stmtKD->execute(['today' => $today]);
        $allKehadiranDosen = $stmtKD->fetchAll();

        $kehadiranByJadwal = [];
        $kehadiranByDosen = [];
        foreach ($allKehadiranDosen as $kd) {
            $dId = (int) $kd['dosen_id'];
            $jId = (int) ($kd['jadwal_id'] ?? 0);
            if ($jId > 0) {
                $kehadiranByJadwal[$jId] = $kd;
            }
            $kehadiranByDosen[$dId] = $kd;
        }

        $sqlJadwal = "SELECT j.id AS jadwal_id, j.jam_mulai, j.jam_selesai, j.mata_kuliah, j.kelas, j.ruang, d.nama AS dosen, j.dosen_id, d.foto_path AS dosen_foto
                      FROM `jadwal_kuliah` j
                      JOIN `dosen` d ON d.id = j.dosen_id
                      JOIN `prodi` pr ON pr.id = j.prodi_id AND pr.aktif = 1
                      JOIN `periode_akademik` pa ON pa.id = j.periode_id AND pa.aktif = 1
                      WHERE j.hari = :hari AND j.aktif = 1
                      ORDER BY j.jam_mulai ASC";
        $stmtJadwal = $this->pdo->prepare($sqlJadwal);
        $stmtJadwal->execute(['hari' => $currentIsoDay]);
        $jadwalHariIni = [];
        $dosenMap = [];
        while ($row = $stmtJadwal->fetch()) {
            $jId = (int) $row['jadwal_id'];
            $dId = (int) $row['dosen_id'];
            $jamMulai = substr($row['jam_mulai'], 0, 5);
            $jamSelesai = substr($row['jam_selesai'], 0, 5);

            $att = $kehadiranByJadwal[$jId] ?? $kehadiranByDosen[$dId] ?? null;

            $statusKehadiran = 'menunggu';
            $modeKuliah = null;
            $statusLabel = 'Belum Konfirmasi';
            $jamGanti = null;
            $catatan = null;

            if ($att) {
                $rawStatus = $att['status'];
                $modeKuliah = $att['mode'];
                $jamGanti = $att['jam_ganti'];
                $catatan = $att['keterangan'];

                if ($rawStatus === 'hadir') {
                    if ($modeKuliah === 'online') {
                        $statusKehadiran = 'hadir_online';
                        $statusLabel = 'Hadir Online';
                    } elseif ($modeKuliah === 'offline') {
                        $statusKehadiran = 'hadir_offline';
                        $statusLabel = 'Hadir Offline';
                    } else {
                        $statusKehadiran = 'hadir';
                        $statusLabel = 'Hadir';
                    }
                } elseif ($rawStatus === 'tukar_jam') {
                    $statusKehadiran = 'tukar_jam';
                    $statusLabel = 'Tukar Jam' . ($jamGanti ? ' (' . $jamGanti . ')' : '');
                } elseif (in_array($rawStatus, ['tidak_hadir', 'tidak', 'izin', 'alpa'], true)) {
                    $statusKehadiran = 'tidak_hadir';
                    $statusLabel = 'Tidak Hadir' . ($catatan ? ' (' . $catatan . ')' : '');
                }
            }

            $jadwalHariIni[] = [
                'id' => $jId,
                'jam_mulai' => $jamMulai,
                'jam_selesai' => $jamSelesai,
                'mata_kuliah' => $row['mata_kuliah'],
                'dosen' => $row['dosen'],
                'ruang' => $row['ruang'],
                'kelas' => $row['kelas'],
                'status_kehadiran' => $statusKehadiran,
                'mode_kuliah' => $modeKuliah,
                'status_label' => $statusLabel,
                'jam_ganti' => $jamGanti,
                'catatan' => $catatan
            ];

            if (!isset($dosenMap[$dId])) {
                $dosenFoto = !empty($row['dosen_foto']) ? (str_starts_with($row['dosen_foto'], 'http') ? $row['dosen_foto'] : 'storage/' . ltrim($row['dosen_foto'], '/')) : null;
                $dosenMap[$dId] = [
                    'id' => $dId,
                    'nama' => $row['dosen'],
                    'foto_url' => $dosenFoto,
                    'mata_kuliah' => $row['mata_kuliah'],
                    'ruang' => $row['ruang'],
                    'jam_mulai' => $jamMulai,
                    'jam_selesai' => $jamSelesai,
                    'status_kehadiran' => $statusKehadiran,
                    'mode_kuliah' => $modeKuliah,
                    'status_label' => $statusLabel,
                    'jam_ganti' => $jamGanti,
                    'catatan' => $catatan
                ];
            }
        }

        // Jika ada dosen yang konfirmasi hadir hari ini tapi tidak ada di jadwal reguler (tukar jadwal ke hari ini)
        foreach ($allKehadiranDosen as $kd) {
            $dId = (int) $kd['dosen_id'];
            if (!isset($dosenMap[$dId])) {
                $dosenFoto = !empty($kd['dosen_foto']) ? (str_starts_with($kd['dosen_foto'], 'http') ? $kd['dosen_foto'] : 'storage/' . ltrim($kd['dosen_foto'], '/')) : null;
                $rawStatus = $kd['status'];
                $modeKuliah = $kd['mode'];
                $statusKehadiran = 'hadir';
                $statusLabel = 'Hadir';
                if ($rawStatus === 'hadir') {
                    if ($modeKuliah === 'online') {
                        $statusKehadiran = 'hadir_online';
                        $statusLabel = 'Hadir Online';
                    } else {
                        $statusKehadiran = 'hadir_offline';
                        $statusLabel = 'Hadir Offline';
                    }
                } elseif ($rawStatus === 'tukar_jam') {
                    $statusKehadiran = 'tukar_jam';
                    $statusLabel = 'Tukar Jam' . ($kd['jam_ganti'] ? ' (' . $kd['jam_ganti'] . ')' : '');
                } else {
                    $statusKehadiran = 'tidak_hadir';
                    $statusLabel = 'Tidak Hadir';
                }

                $dosenMap[$dId] = [
                    'id' => $dId,
                    'nama' => $kd['dosen_nama'],
                    'foto_url' => $dosenFoto,
                    'mata_kuliah' => $kd['keterangan'] ?: 'Jadwal Pengganti',
                    'ruang' => 'Kampus',
                    'jam_mulai' => $kd['jam_ganti'] ?: ($kd['waktu_absen'] ? substr($kd['waktu_absen'], 0, 5) : '08:00'),
                    'jam_selesai' => 'Selesai',
                    'status_kehadiran' => $statusKehadiran,
                    'mode_kuliah' => $modeKuliah,
                    'status_label' => $statusLabel,
                    'jam_ganti' => $kd['jam_ganti'],
                    'catatan' => $kd['keterangan']
                ];
            }
        }
        $dosenHariIni = array_values($dosenMap);

        // 4. Statistik Mahasiswa
        $sqlMhs = "SELECT pr.nama AS prodi_nama, sm.tahun_angkatan, sm.jumlah
                   FROM `statistik_mahasiswa` sm
                   JOIN `prodi` pr ON pr.id = sm.prodi_id AND pr.aktif = 1
                   JOIN `periode_akademik` pa ON pa.id = sm.periode_id AND pa.aktif = 1
                   ORDER BY pr.urutan ASC, sm.tahun_angkatan ASC";
        $mhsRows = $this->pdo->query($sqlMhs)->fetchAll();
        $angkatanSet = [];
        $prodiGroup = [];
        $grandTotal = 0;

        foreach ($mhsRows as $r) {
            $angkatan = (int) $r['tahun_angkatan'];
            $angkatanSet[$angkatan] = true;
            $prodiName = $r['prodi_nama'];
            if (!isset($prodiGroup[$prodiName])) {
                $prodiGroup[$prodiName] = [];
            }
            $prodiGroup[$prodiName][$angkatan] = (int) $r['jumlah'];
            $grandTotal += (int) $r['jumlah'];
        }

        ksort($angkatanSet);
        $angkatanList = array_keys($angkatanSet);
        $prodiList = [];
        foreach ($prodiGroup as $pName => $counts) {
            $jumlahArray = [];
            foreach ($angkatanList as $ang) {
                $jumlahArray[] = $counts[$ang] ?? 0;
            }
            $prodiList[] = [
                'nama' => $pName,
                'jumlah' => $jumlahArray
            ];
        }

        // 5. Agenda Kampus
        $sqlAgenda = "SELECT `judul`, `lokasi`, `mulai_at`
                      FROM `agenda`
                      WHERE `aktif` = 1 AND :today BETWEEN `tampil_dari` AND `tampil_sampai`
                      ORDER BY `mulai_at` ASC LIMIT 5";
        $stmtAgenda = $this->pdo->prepare($sqlAgenda);
        $stmtAgenda->execute(['today' => $today]);
        $agendaList = [];
        while ($row = $stmtAgenda->fetch()) {
            $agendaList[] = [
                'judul' => $row['judul'],
                'lokasi' => $row['lokasi'],
                'mulai' => date('c', strtotime($row['mulai_at']))
            ];
        }

        // 6. Pengumuman Ticker
        $sqlPengumuman = "SELECT `teks` FROM `pengumuman`
                          WHERE `aktif` = 1 AND :today BETWEEN `tampil_dari` AND `tampil_sampai`
                          ORDER BY `urutan` ASC";
        $stmtPengumuman = $this->pdo->prepare($sqlPengumuman);
        $stmtPengumuman->execute(['today' => $today]);
        $pengumumanList = $stmtPengumuman->fetchAll(PDO::FETCH_COLUMN);

        // 7. Video Profil Aktif
        $videoRow = $this->pdo->query("SELECT `path`, `durasi_detik`, `aktif` FROM `media` WHERE `koleksi` = 'video_profil' AND `aktif` = 1 LIMIT 1")->fetch();
        $videoUrl = '';
        if ($videoRow && !empty($videoRow['path'])) {
            $videoUrl = str_starts_with($videoRow['path'], 'http') ? $videoRow['path'] : 'storage/' . ltrim($videoRow['path'], '/');
        }
        $videoData = [
            'aktif' => $videoRow ? (bool) $videoRow['aktif'] : false,
            'url' => $videoUrl,
            'durasi_detik' => $videoRow ? (int) $videoRow['durasi_detik'] : 180
        ];

        return [
            'versi' => '1.0.0-' . str_replace('-', '', $today),
            'waktu_server' => date('c'),
            'kampus' => $kampusData,
            'pengaturan' => [
                'slide_detik' => $pengaturan['slide_detik'] ?? 10,
                'halaman_detik' => $pengaturan['halaman_detik'] ?? 10,
                'video_aktif' => $pengaturan['video_aktif'] ?? true,
                'hijriah_offset' => $pengaturan['hijriah_offset'] ?? 0,
                'qr_rotasi_detik' => $pengaturan['qr_rotasi_detik'] ?? 30,
                'spot_ms' => 1300
            ],
            'cuaca' => $this->weatherService->getWeather(),
            'pengurus' => $pengurusList,
            'dosen_hari_ini' => $dosenHariIni,
            'jadwal_hari_ini' => $jadwalHariIni,
            'mahasiswa' => [
                'angkatan' => $angkatanList,
                'prodi' => $prodiList,
                'total' => $grandTotal
            ],
            'agenda' => $agendaList,
            'pengumuman' => $pengumumanList,
            'video' => $videoData
        ];
    }

    public static function formatDurasiShort(int $menit): string
    {
        if ($menit <= 0) return '0m';
        $jam = intdiv($menit, 60);
        $sisa = $menit % 60;
        if ($jam > 0 && $sisa > 0) {
            return "{$jam}j {$sisa}m";
        } elseif ($jam > 0) {
            return "{$jam}j";
        } else {
            return "{$sisa}m";
        }
    }

    public function getEtag(array $state): string
    {
        // ETag dihitung dari seluruh konten state selain waktu_server dinamis
        $copy = $state;
        unset($copy['waktu_server']);
        return '"' . md5(json_encode($copy)) . '"';
    }
}

