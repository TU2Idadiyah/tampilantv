<?php

declare(strict_types=1);

namespace App\Actions\Import;

require_once dirname(__DIR__, 3) . '/config/database.php';

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

        $validRows = [];
        $errors = [];
        $seenPairs = [];

        foreach ($parsed['rows'] as $r) {
            $line = $r['line_number'];
            $data = $r['data'];

            // 1. Validasi Kode Prodi
            $kodeProdi = strtoupper($data['kode_prodi'] ?? '');
            if (!isset($prodiMap[$kodeProdi])) {
                $errors[] = ['baris' => $line, 'kolom' => 'kode_prodi', 'pesan' => "Program studi '{$kodeProdi}' tidak ditemukan."];
                continue;
            }

            // 2. Validasi Tahun Angkatan
            $angkatan = (int)($data['tahun_angkatan'] ?? 0);
            if ($angkatan < 2000 || $angkatan > 2050) {
                $errors[] = ['baris' => $line, 'kolom' => 'tahun_angkatan', 'pesan' => "Tahun angkatan harus 4 digit antara 2000 s/d 2050."];
                continue;
            }

            // 3. Validasi Pasangan Duplikat dalam Berkas
            $pairKey = "{$kodeProdi}_{$angkatan}";
            if (isset($seenPairs[$pairKey])) {
                $errors[] = ['baris' => $line, 'kolom' => 'prodi_angkatan', 'pesan' => "Duplikat data prodi {$kodeProdi} angkatan {$angkatan} dalam satu berkas (sebelumnya di baris {$seenPairs[$pairKey]})."];
                continue;
            }
            $seenPairs[$pairKey] = $line;

            // 4. Validasi Jumlah Mahasiswa
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

        try {
            $this->pdo->beginTransaction();

            if ($mode === 'ganti_semua') {
                $stmtDel = $this->pdo->prepare("DELETE FROM `statistik_mahasiswa` WHERE `periode_id` = :pid");
                $stmtDel->execute(['pid' => $periodeId]);
            }

            $sqlUpsert = "INSERT INTO `statistik_mahasiswa`
                (`periode_id`, `prodi_id`, `tahun_angkatan`, `jumlah`, `created_at`, `updated_at`)
                VALUES (:pid, :prodi_id, :angkatan, :jumlah, NOW(), NOW())
                ON DUPLICATE KEY UPDATE `jumlah` = VALUES(`jumlah`), `updated_at` = NOW()";
            $stmtUpsert = $this->pdo->prepare($sqlUpsert);

            foreach ($preview['valid_rows'] as $row) {
                $stmtUpsert->execute([
                    'pid' => $row['periode_id'],
                    'prodi_id' => $row['prodi_id'],
                    'angkatan' => $row['tahun_angkatan'],
                    'jumlah' => $row['jumlah']
                ]);
            }

            // Catat ke import_log
            $sqlLog = "INSERT INTO `import_log`
                (`jenis`, `nama_file`, `hash_file`, `total_baris`, `berhasil`, `gagal`, `status`, `user_id`, `created_at`, `updated_at`)
                VALUES ('statistik_mahasiswa', :nama, :hash, :total, :sukses, 0, 'selesai', :uid, NOW(), NOW())";
            $this->pdo->prepare($sqlLog)->execute([
                'nama' => $fileName,
                'hash' => $hashFile,
                'total' => $preview['total_baris'],
                'sukses' => count($preview['valid_rows']),
                'uid' => $userId
            ]);

            // Catat ke audit_log
            $sqlAudit = "INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                         VALUES ('admin', :uid, 'import_csv', 'statistik_mahasiswa', :pid, :meta, NOW())";
            $this->pdo->prepare($sqlAudit)->execute([
                'uid' => $userId,
                'pid' => $periodeId,
                'meta' => json_encode(['file' => $fileName, 'mode' => $mode, 'total_insert' => count($preview['valid_rows'])])
            ]);

            $this->pdo->commit();

            return [
                'sukses' => true,
                'total_diimpor' => count($preview['valid_rows']),
                'pesan' => "Berhasil mengimpor data statistik mahasiswa sebanyak " . count($preview['valid_rows']) . " baris."
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    protected function getProdiMap(): array
    {
        $rows = $this->pdo->query("SELECT `id`, `kode` FROM `prodi` WHERE `aktif` = 1")->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[strtoupper($r['kode'])] = (int)$r['id'];
        }
        return $map;
    }
}

