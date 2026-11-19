<?php

declare(strict_types=1);

namespace App\Actions\Import;

require_once dirname(__DIR__, 3) . '/config/database.php';
require_once dirname(__DIR__, 3) . '/api/v1/dosen/helper.php';

use App\Services\CsvReader;
use Exception;
use PDO;

class ImportMahasiswaAction
{
    protected PDO $pdo;
    protected CsvReader $csvReader;

    public function __construct(?PDO $pdo = null, ?CsvReader $csvReader = null)
    {
        $this->pdo = $pdo ?? getDatabasePdo();
        $this->csvReader = $csvReader ?? new CsvReader();
    }

    public function preview(string $filePath, int $periodeId): array
    {
        $parsed = $this->csvReader->readFile($filePath);
        $prodiMap = $this->getProdiMap();

        $headers = $parsed['headers'];
        
        // Deteksi format: detail (ada nama/kelas/nim) vs agregat (jumlah)
        $isDetail = false;
        foreach ($headers as $h) {
            if (in_array($h, ['nama', 'nama_mahasiswa', 'nama_lengkap', 'kelas', 'rombel', 'nama_kelas'])) {
                $isDetail = true;
                break;
            }
        }
        if (!$isDetail && !in_array('jumlah', $headers) && count($headers) >= 4) {
            $isDetail = true;
        }

        if ($isDetail) {
            return $this->previewDetail($parsed, $prodiMap, $periodeId);
        } else {
            return $this->previewAgregat($parsed, $prodiMap, $periodeId);
        }
    }

    protected function previewDetail(array $parsed, array $prodiMap, int $periodeId): array
    {
        $headers = $parsed['headers'];
        $colNama = $this->findHeader($headers, ['nama', 'nama_mahasiswa', 'nama_lengkap', 'name']);
        $colNim = $this->findHeader($headers, ['nim', 'nomor_induk', 'no_induk', 'npm']);
        $colProdi = $this->findHeader($headers, ['kode_prodi', 'prodi', 'program_studi', 'jurusan']);
        $colKelas = $this->findHeader($headers, ['kelas', 'rombel', 'nama_kelas', 'group']);
        $colAngkatan = $this->findHeader($headers, ['tahun_angkatan', 'angkatan', 'tahun', 'angkatan_tahun']);

        $validRows = [];
        $errors = [];
        $rekapProdi = [];
        $rekapKelas = [];
        $rekapAngkatan = [];

        foreach ($parsed['rows'] as $r) {
            $line = $r['line_number'];
            $data = $r['data'];

            $nama = trim($data[$colNama] ?? '');
            if ($nama === '') {
                $errors[] = ['baris' => $line, 'kolom' => 'nama', 'pesan' => "Nama mahasiswa wajib diisi."];
                continue;
            }

            $rawProdi = strtoupper(trim($data[$colProdi] ?? ''));
            if ($rawProdi === '' || !isset($prodiMap[$rawProdi])) {
                $errors[] = ['baris' => $line, 'kolom' => 'kode_prodi', 'pesan' => "Program studi '{$rawProdi}' tidak terdaftar di sistem."];
                continue;
            }
            $prodiId = $prodiMap[$rawProdi];

            $kelas = trim($data[$colKelas] ?? '');
            if ($kelas === '') {
                $errors[] = ['baris' => $line, 'kolom' => 'kelas', 'pesan' => "Kelas perkuliahan wajib diisi (contoh: MBS-01, PAI-01)."];
                continue;
            }

            $rawAngkatan = trim($data[$colAngkatan] ?? '');
            $angkatan = is_numeric($rawAngkatan) ? (int)$rawAngkatan : (int)date('Y');
            if ($angkatan < 2000 || $angkatan > 2050) {
                $errors[] = ['baris' => $line, 'kolom' => 'tahun_angkatan', 'pesan' => "Tahun angkatan harus 4 digit antara 2000 s/d 2050."];
                continue;
            }

            $nim = trim($data[$colNim] ?? '');

            $validRows[] = [
                'periode_id' => $periodeId,
                'prodi_id' => $prodiId,
                'kode_prodi' => $rawProdi,
                'nim' => $nim,
                'nama' => $nama,
                'kelas' => $kelas,
                'tahun_angkatan' => $angkatan
            ];

            $rekapProdi[$rawProdi] = ($rekapProdi[$rawProdi] ?? 0) + 1;
            $rekapKelas[$kelas] = ($rekapKelas[$kelas] ?? 0) + 1;
            $rekapAngkatan[$angkatan] = ($rekapAngkatan[$angkatan] ?? 0) + 1;
        }

        return [
            'format' => 'detail',
            'total_baris' => $parsed['total_baris'],
            'total_valid' => count($validRows),
            'total_error' => count($errors),
            'errors' => $errors,
            'valid_rows' => $validRows,
            'rekap_prodi' => $rekapProdi,
            'rekap_kelas' => $rekapKelas,
            'rekap_angkatan' => $rekapAngkatan
        ];
    }

    protected function previewAgregat(array $parsed, array $prodiMap, int $periodeId): array
    {
        $validRows = [];
        $errors = [];
        $seenPairs = [];

        foreach ($parsed['rows'] as $r) {
            $line = $r['line_number'];
            $data = $r['data'];

            $kodeProdi = strtoupper(trim($data['kode_prodi'] ?? ''));
            if (!isset($prodiMap[$kodeProdi])) {
                $errors[] = ['baris' => $line, 'kolom' => 'kode_prodi', 'pesan' => "Program studi '{$kodeProdi}' tidak ditemukan."];
                continue;
            }

            $angkatan = (int)($data['tahun_angkatan'] ?? 0);
            if ($angkatan < 2000 || $angkatan > 2050) {
                $errors[] = ['baris' => $line, 'kolom' => 'tahun_angkatan', 'pesan' => "Tahun angkatan harus 4 digit antara 2000 s/d 2050."];
                continue;
            }

            $pairKey = "{$kodeProdi}_{$angkatan}";
            if (isset($seenPairs[$pairKey])) {
                $errors[] = ['baris' => $line, 'kolom' => 'prodi_angkatan', 'pesan' => "Duplikat data prodi {$kodeProdi} angkatan {$angkatan} dalam satu berkas (sebelumnya di baris {$seenPairs[$pairKey]})."];
                continue;
            }
            $seenPairs[$pairKey] = $line;

            $jumlahRaw = $data['jumlah'] ?? '';
            if (!is_numeric($jumlahRaw) || (int)$jumlahRaw < 0) {
                $errors[] = ['baris' => $line, 'kolom' => 'jumlah', 'pesan' => "Jumlah mahasiswa harus berupa angka bulat >= 0."];
                continue;
            }

            $validRows[] = [
                'periode_id' => $periodeId,
                'prodi_id' => $prodiMap[$kodeProdi],
                'kode_prodi' => $kodeProdi,
                'tahun_angkatan' => $angkatan,
                'jumlah' => (int)$jumlahRaw
            ];
        }

        return [
            'format' => 'agregat',
            'total_baris' => $parsed['total_baris'],
            'total_valid' => count($validRows),
            'total_error' => count($errors),
            'errors' => $errors,
            'valid_rows' => $validRows
        ];
    }

    public function apply(string $filePath, int $periodeId, string $mode = 'ganti_semua', ?int $userId = null): array
    {
        $preview = $this->preview($filePath, $periodeId);
        if ($preview['total_error'] > 0) {
            throw new Exception("Impor dibatalkan karena terdapat {$preview['total_error']} kesalahan pada berkas CSV.");
        }

        $hashFile = hash_file('sha256', $filePath);
        $fileName = basename($filePath);

        ensurePresensiMahasiswaTables($this->pdo);

        try {
            $this->pdo->beginTransaction();

            if ($preview['format'] === 'detail') {
                $res = $this->applyDetail($preview, $periodeId, $mode, $fileName, $hashFile, $userId);
            } else {
                $res = $this->applyAgregat($preview, $periodeId, $mode, $fileName, $hashFile, $userId);
            }

            $this->pdo->commit();
            return $res;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    protected function applyDetail(array $preview, int $periodeId, string $mode, string $fileName, string $hashFile, ?int $userId): array
    {
        if ($mode === 'ganti_semua') {
            $this->pdo->prepare("DELETE FROM `mahasiswa` WHERE `periode_id` = :pid")->execute(['pid' => $periodeId]);
            $this->pdo->prepare("DELETE FROM `statistik_mahasiswa` WHERE `periode_id` = :pid")->execute(['pid' => $periodeId]);
        }

        $stmtCheckNim = $this->pdo->prepare(
            "SELECT id FROM `mahasiswa` WHERE nim = :nim AND periode_id = :pid LIMIT 1"
        );
        $stmtCheckNama = $this->pdo->prepare(
            "SELECT id FROM `mahasiswa` WHERE nama = :nama AND prodi_id = :prodi_id AND kelas = :kelas AND periode_id = :pid LIMIT 1"
        );
        $stmtUpdateMhs = $this->pdo->prepare(
            "UPDATE `mahasiswa` 
             SET nim = :nim, nama = :nama, prodi_id = :prodi_id, kelas = :kelas, tahun_angkatan = :angkatan, aktif = 1, updated_at = CURRENT_TIMESTAMP 
             WHERE id = :id"
        );
        $stmtInsertMhs = $this->pdo->prepare(
            "INSERT INTO `mahasiswa` 
             (nim, nama, prodi_id, kelas, tahun_angkatan, periode_id, aktif, created_at, updated_at) 
             VALUES (:nim, :nama, :prodi_id, :kelas, :angkatan, :pid, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );

        // Grouping untuk sinkronisasi otomatis ke kelas perkuliahan dosen
        $studentsByClass = [];

        foreach ($preview['valid_rows'] as $row) {
            $existingId = null;
            if ($row['nim'] !== '') {
                $stmtCheckNim->execute([
                    'nim' => $row['nim'],
                    'pid' => $row['periode_id']
                ]);
                $existingId = $stmtCheckNim->fetchColumn();
            }
            if (!$existingId) {
                $stmtCheckNama->execute([
                    'nama' => $row['nama'],
                    'prodi_id' => $row['prodi_id'],
                    'kelas' => $row['kelas'],
                    'pid' => $row['periode_id']
                ]);
                $existingId = $stmtCheckNama->fetchColumn();
            }

            if ($existingId && $mode === 'tambah_perbarui') {
                $stmtUpdateMhs->execute([
                    'nim' => $row['nim'],
                    'nama' => $row['nama'],
                    'prodi_id' => $row['prodi_id'],
                    'kelas' => $row['kelas'],
                    'angkatan' => $row['tahun_angkatan'],
                    'id' => $existingId
                ]);
            } else if (!$existingId) {
                $stmtInsertMhs->execute([
                    'nim' => $row['nim'],
                    'nama' => $row['nama'],
                    'prodi_id' => $row['prodi_id'],
                    'kelas' => $row['kelas'],
                    'angkatan' => $row['tahun_angkatan'],
                    'pid' => $row['periode_id']
                ]);
            }

            $studentsByClass[$row['kelas']][] = $row;
        }

        // 1. SINKRONISASI OTOMATIS KE KELAS MAHASISWA DOSEN
        $stmtFindJadwal = $this->pdo->prepare("SELECT id FROM `jadwal_kuliah` WHERE TRIM(LOWER(kelas)) = TRIM(LOWER(:kelas)) AND aktif = 1");
        $stmtCheckKm = $this->pdo->prepare("SELECT id FROM `kelas_mahasiswa` WHERE jadwal_id = :jid AND nama = :nama LIMIT 1");
        $stmtInsKm = $this->pdo->prepare("INSERT INTO `kelas_mahasiswa` (jadwal_id, nim, nama, created_at) VALUES (:jid, :nim, :nama, CURRENT_TIMESTAMP)");

        foreach ($studentsByClass as $kls => $mhsList) {
            $stmtFindJadwal->execute(['kelas' => $kls]);
            $matchingJadwals = $stmtFindJadwal->fetchAll(PDO::FETCH_COLUMN);

            foreach ($matchingJadwals as $jid) {
                foreach ($mhsList as $m) {
                    $stmtCheckKm->execute(['jid' => (int)$jid, 'nama' => $m['nama']]);
                    if (!$stmtCheckKm->fetch()) {
                        $stmtInsKm->execute(['jid' => (int)$jid, 'nim' => $m['nim'], 'nama' => $m['nama']]);
                    }
                }
            }
        }

        // 2. SINKRONISASI OTOMATIS KE STATISTIK MAHASISWA UNTUK LAYAR UTAMA (TV KIOSK)
        $sqlCounts = "SELECT prodi_id, tahun_angkatan, COUNT(*) as jml 
                      FROM `mahasiswa` 
                      WHERE periode_id = :pid AND aktif = 1 
                      GROUP BY prodi_id, tahun_angkatan";
        $stmtCounts = $this->pdo->prepare($sqlCounts);
        $stmtCounts->execute(['pid' => $periodeId]);
        $statRows = $stmtCounts->fetchAll(PDO::FETCH_ASSOC);

        $stmtCheckSm = $this->pdo->prepare(
            "SELECT id FROM `statistik_mahasiswa` WHERE periode_id = :pid AND prodi_id = :prodi_id AND tahun_angkatan = :angkatan LIMIT 1"
        );
        $stmtUpdateSm = $this->pdo->prepare(
            "UPDATE `statistik_mahasiswa` SET jumlah = :jml, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $stmtInsertSm = $this->pdo->prepare(
            "INSERT INTO `statistik_mahasiswa` (periode_id, prodi_id, tahun_angkatan, jumlah, created_at, updated_at) 
             VALUES (:pid, :prodi_id, :angkatan, :jml, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );

        foreach ($statRows as $sr) {
            $stmtCheckSm->execute([
                'pid' => $periodeId,
                'prodi_id' => $sr['prodi_id'],
                'angkatan' => $sr['tahun_angkatan']
            ]);
            $smId = $stmtCheckSm->fetchColumn();
            if ($smId) {
                $stmtUpdateSm->execute(['jml' => $sr['jml'], 'id' => $smId]);
            } else {
                $stmtInsertSm->execute([
                    'pid' => $periodeId,
                    'prodi_id' => $sr['prodi_id'],
                    'angkatan' => $sr['tahun_angkatan'],
                    'jml' => $sr['jml']
                ]);
            }
        }

        // Catat ke import_log
        $sqlLog = "INSERT INTO `import_log`
            (`jenis`, `nama_file`, `hash_file`, `total_baris`, `berhasil`, `gagal`, `status`, `user_id`, `created_at`, `updated_at`)
            VALUES ('mahasiswa_detail', :nama, :hash, :total, :sukses, 0, 'selesai', :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $this->pdo->prepare($sqlLog)->execute([
            'nama' => $fileName,
            'hash' => $hashFile,
            'total' => $preview['total_baris'],
            'sukses' => count($preview['valid_rows']),
            'uid' => $userId
        ]);

        // Catat ke audit_log
        $sqlAudit = "INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                     VALUES ('admin', :uid, 'import_csv', 'mahasiswa', :pid, :meta, CURRENT_TIMESTAMP)";
        $this->pdo->prepare($sqlAudit)->execute([
            'uid' => $userId,
            'pid' => $periodeId,
            'meta' => json_encode(['file' => $fileName, 'mode' => $mode, 'total_insert' => count($preview['valid_rows']), 'kelas_count' => count($studentsByClass)])
        ]);

        return [
            'sukses' => true,
            'total_diimpor' => count($preview['valid_rows']),
            'pesan' => "Berhasil mengimpor " . count($preview['valid_rows']) . " data mahasiswa ke " . count($studentsByClass) . " kelas. Data presensi dosen dan statistik layar TV otomatis diperbarui!"
        ];
    }

    protected function applyAgregat(array $preview, int $periodeId, string $mode, string $fileName, string $hashFile, ?int $userId): array
    {
        if ($mode === 'ganti_semua') {
            $stmtDel = $this->pdo->prepare("DELETE FROM `statistik_mahasiswa` WHERE `periode_id` = :pid");
            $stmtDel->execute(['pid' => $periodeId]);
        }

        $stmtCheck = $this->pdo->prepare(
            "SELECT `id` FROM `statistik_mahasiswa` WHERE `periode_id` = :pid AND `prodi_id` = :prodi_id AND `tahun_angkatan` = :angkatan LIMIT 1"
        );
        $stmtUpdate = $this->pdo->prepare(
            "UPDATE `statistik_mahasiswa` SET `jumlah` = :jumlah, `updated_at` = CURRENT_TIMESTAMP WHERE `id` = :id"
        );
        $stmtInsert = $this->pdo->prepare(
            "INSERT INTO `statistik_mahasiswa`
                (`periode_id`, `prodi_id`, `tahun_angkatan`, `jumlah`, `created_at`, `updated_at`)
             VALUES (:pid, :prodi_id, :angkatan, :jumlah, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );

        foreach ($preview['valid_rows'] as $row) {
            $stmtCheck->execute([
                'pid' => $row['periode_id'],
                'prodi_id' => $row['prodi_id'],
                'angkatan' => $row['tahun_angkatan']
            ]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUpdate->execute([
                    'jumlah' => $row['jumlah'],
                    'id' => $existingId
                ]);
            } else {
                $stmtInsert->execute([
                    'pid' => $row['periode_id'],
                    'prodi_id' => $row['prodi_id'],
                    'angkatan' => $row['tahun_angkatan'],
                    'jumlah' => $row['jumlah']
                ]);
            }
        }

        // Catat ke import_log
        $sqlLog = "INSERT INTO `import_log`
            (`jenis`, `nama_file`, `hash_file`, `total_baris`, `berhasil`, `gagal`, `status`, `user_id`, `created_at`, `updated_at`)
            VALUES ('statistik_mahasiswa', :nama, :hash, :total, :sukses, 0, 'selesai', :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $this->pdo->prepare($sqlLog)->execute([
            'nama' => $fileName,
            'hash' => $hashFile,
            'total' => $preview['total_baris'],
            'sukses' => count($preview['valid_rows']),
            'uid' => $userId
        ]);

        // Catat ke audit_log
        $sqlAudit = "INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                     VALUES ('admin', :uid, 'import_csv', 'statistik_mahasiswa', :pid, :meta, CURRENT_TIMESTAMP)";
        $this->pdo->prepare($sqlAudit)->execute([
            'uid' => $userId,
            'pid' => $periodeId,
            'meta' => json_encode(['file' => $fileName, 'mode' => $mode, 'total_insert' => count($preview['valid_rows'])])
        ]);

        return [
            'sukses' => true,
            'total_diimpor' => count($preview['valid_rows']),
            'pesan' => "Berhasil mengimpor data statistik mahasiswa sebanyak " . count($preview['valid_rows']) . " baris."
        ];
    }

    protected function findHeader(array $headers, array $candidates): string
    {
        foreach ($candidates as $cand) {
            if (in_array(strtolower($cand), $headers, true)) {
                return strtolower($cand);
            }
        }
        return $candidates[0];
    }

    protected function getProdiMap(): array
    {
        $rows = $this->pdo->query("SELECT `id`, `kode`, `nama` FROM `prodi` WHERE `aktif` = 1")->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[strtoupper(trim($r['kode']))] = (int)$r['id'];
            $map[strtoupper(trim($r['nama']))] = (int)$r['id'];
        }
        return $map;
    }
}
