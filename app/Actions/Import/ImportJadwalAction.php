<?php

declare(strict_types=1);

namespace App\Actions\Import;

require_once dirname(__DIR__, 2) . '/Enums/Hari.php';
require_once dirname(__DIR__, 3) . '/config/database.php';

use App\Enums\Hari;
use App\Services\CsvReader;
use Exception;
use PDO;

class ImportJadwalAction
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
        $dosenMap = $this->getDosenMap();
        $prodiMap = $this->getProdiMap();

        $validRows = [];
        $errors = [];
        $warnings = [];

        foreach ($parsed['rows'] as $r) {
            $line = $r['line_number'];
            $data = $r['data'];

            // 1. Validasi Hari
            $hariRaw = $data['hari'] ?? '';
            $hariVal = null;
            if (is_numeric($hariRaw)) {
                $num = (int)$hariRaw;
                if ($num >= 1 && $num <= 7) $hariVal = $num;
            } else {
                $enumHari = Hari::fromString($hariRaw);
                if ($enumHari) $hariVal = $enumHari->value;
            }

            if (!$hariVal) {
                $errors[] = ['baris' => $line, 'kolom' => 'hari', 'pesan' => "Hari tidak valid: '{$hariRaw}'. Gunakan Senin-Minggu atau angka 1-7."];
                continue;
            }

            // 2. Validasi Jam
            $jamMulai = $data['jam_mulai'] ?? '';
            $jamSelesai = $data['jam_selesai'] ?? '';
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jamMulai) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jamSelesai)) {
                $errors[] = ['baris' => $line, 'kolom' => 'jam', 'pesan' => "Format jam harus HH:MM (Contoh: 07:30)."];
                continue;
            }

            if (strtotime($jamSelesai) <= strtotime($jamMulai)) {
                $errors[] = ['baris' => $line, 'kolom' => 'jam_selesai', 'pesan' => "Jam selesai ({$jamSelesai}) harus lebih besar dari jam mulai ({$jamMulai})."];
                continue;
            }

            // 3. Validasi Dosen
            $kodeDosen = strtoupper($data['kode_dosen'] ?? '');
            if (!isset($dosenMap[$kodeDosen])) {
                $errors[] = ['baris' => $line, 'kolom' => 'kode_dosen', 'pesan' => "Dosen dengan kode '{$kodeDosen}' tidak ditemukan di database."];
                continue;
            }

            // 4. Validasi Prodi
            $kodeProdi = strtoupper($data['kode_prodi'] ?? '');
            if (!isset($prodiMap[$kodeProdi])) {
                $errors[] = ['baris' => $line, 'kolom' => 'kode_prodi', 'pesan' => "Program studi '{$kodeProdi}' tidak ditemukan."];
                continue;
            }

            // 5. Cek Bentrok Ruang (Warning, bukan error)
            $ruang = $data['ruang'] ?? '';
            $sqlBentrok = "SELECT `mata_kuliah`, `jam_mulai`, `jam_selesai` FROM `jadwal_kuliah`
                           WHERE `periode_id` = :pid AND `hari` = :hari AND `ruang` = :ruang AND `aktif` = 1
                           AND NOT (`jam_selesai` <= :mulai OR `jam_mulai` >= :selesai)";
            $stmtBentrok = $this->pdo->prepare($sqlBentrok);
            $stmtBentrok->execute([
                'pid' => $periodeId,
                'hari' => $hariVal,
                'ruang' => $ruang,
                'mulai' => $jamMulai . ':00',
                'selesai' => $jamSelesai . ':00'
            ]);
            $bentrok = $stmtBentrok->fetch();
            if ($bentrok) {
                $warnings[] = ['baris' => $line, 'pesan' => "Bentrok ruang {$ruang} dengan mata kuliah '{$bentrok['mata_kuliah']}' ({$bentrok['jam_mulai']}-{$bentrok['jam_selesai']})."];
            }

            $validRows[] = [
                'periode_id' => $periodeId,
                'prodi_id' => $prodiMap[$kodeProdi],
                'hari' => $hariVal,
                'jam_mulai' => $jamMulai . ':00',
                'jam_selesai' => $jamSelesai . ':00',
                'mata_kuliah' => $data['mata_kuliah'] ?? '',
                'kelas' => $data['kelas'] ?? '',
                'dosen_id' => $dosenMap[$kodeDosen],
                'ruang' => $ruang,
                'aktif' => 1
            ];
        }

        return [
            'total_baris' => $parsed['total_baris'],
            'total_valid' => count($validRows),
            'total_error' => count($errors),
            'total_warning' => count($warnings),
            'errors' => $errors,
            'warnings' => $warnings,
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
                $stmtDel = $this->pdo->prepare("DELETE FROM `jadwal_kuliah` WHERE `periode_id` = :pid");
                $stmtDel->execute(['pid' => $periodeId]);
            }

            $sqlInsert = "INSERT INTO `jadwal_kuliah`
                (`periode_id`, `prodi_id`, `hari`, `jam_mulai`, `jam_selesai`, `mata_kuliah`, `kelas`, `dosen_id`, `ruang`, `aktif`, `created_at`, `updated_at`)
                VALUES (:pid, :prodi_id, :hari, :jam_mulai, :jam_selesai, :mk, :kelas, :dosen_id, :ruang, 1, NOW(), NOW())";
            $stmtInsert = $this->pdo->prepare($sqlInsert);

            foreach ($preview['valid_rows'] as $row) {
                $stmtInsert->execute([
                    'pid' => $row['periode_id'],
                    'prodi_id' => $row['prodi_id'],
                    'hari' => $row['hari'],
                    'jam_mulai' => $row['jam_mulai'],
                    'jam_selesai' => $row['jam_selesai'],
                    'mk' => $row['mata_kuliah'],
                    'kelas' => $row['kelas'],
                    'dosen_id' => $row['dosen_id'],
                    'ruang' => $row['ruang']
                ]);
            }

            // Catat ke import_log
            $sqlLog = "INSERT INTO `import_log`
                (`jenis`, `nama_file`, `hash_file`, `total_baris`, `berhasil`, `gagal`, `status`, `user_id`, `created_at`, `updated_at`)
                VALUES ('jadwal_kuliah', :nama, :hash, :total, :sukses, 0, 'selesai', :uid, NOW(), NOW())";
            $this->pdo->prepare($sqlLog)->execute([
                'nama' => $fileName,
                'hash' => $hashFile,
                'total' => $preview['total_baris'],
                'sukses' => count($preview['valid_rows']),
                'uid' => $userId
            ]);

            // Catat ke audit_log
            $sqlAudit = "INSERT INTO `audit_log` (`aktor_tipe`, `aktor_id`, `aksi`, `entitas`, `entitas_id`, `meta`, `created_at`)
                         VALUES ('admin', :uid, 'import_csv', 'jadwal_kuliah', :pid, :meta, NOW())";
            $this->pdo->prepare($sqlAudit)->execute([
                'uid' => $userId,
                'pid' => $periodeId,
                'meta' => json_encode(['file' => $fileName, 'mode' => $mode, 'total_insert' => count($preview['valid_rows'])])
            ]);

            $this->pdo->commit();

            return [
                'sukses' => true,
                'total_diimpor' => count($preview['valid_rows']),
                'pesan' => "Berhasil mengimpor " . count($preview['valid_rows']) . " baris jadwal kuliah ke database."
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    protected function getDosenMap(): array
    {
        $rows = $this->pdo->query("SELECT `id`, `kode_nidn` FROM `dosen` WHERE `aktif` = 1")->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[strtoupper($r['kode_nidn'])] = (int)$r['id'];
        }
        return $map;
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
