<?php
require_once __DIR__ . '/auth.php';
$admin = requireAdminAuth();
$pdo = getAdminPdo();

// Handler Unduh Template CSV (dieksekusi sebelum header HTML dikirim)
if (isset($_GET['download_template'])) {
    $t = $_GET['download_template'];
    
    // Ambil prodi aktif dari database untuk contoh template
    $prodiSample = $pdo->query("SELECT `kode`, `nama` FROM `prodi` WHERE `aktif` = 1 ORDER BY `urutan` ASC, `id` ASC LIMIT 4")->fetchAll();
    $p1 = $prodiSample[0]['kode'] ?? 'PAI';
    $p2 = $prodiSample[1]['kode'] ?? ($prodiSample[0]['kode'] ?? 'HES');

    if ($t === 'jadwal') {
        // Ambil dosen aktif dari database
        $dosenSample = $pdo->query("SELECT `kode_nidn`, `nama` FROM `dosen` WHERE `aktif` = 1 ORDER BY `id` ASC LIMIT 2")->fetchAll();
        $d1 = $dosenSample[0]['kode_nidn'] ?? 'D001';
        $d2 = $dosenSample[1]['kode_nidn'] ?? ($dosenSample[0]['kode_nidn'] ?? 'D002');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="template_jadwal_kuliah.csv"');
        echo "hari;jam_mulai;jam_selesai;mata_kuliah;kelas;kode_dosen;ruang;kode_prodi\n";
        echo "Senin;07:30;09:10;Contoh Matkul 1;Kelas A;{$d1};R. 101;{$p1}\n";
        echo "Senin;09:20;11:00;Contoh Matkul 2;Kelas B;{$d2};R. 203;{$p2}\n";
        exit;
    } elseif ($t === 'mahasiswa') {
        $tahunSekarang = (int)date('Y');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="template_data_mahasiswa.csv"');
        echo "nim;nama;kode_prodi;kelas;tahun_angkatan\n";
        
        // Ambil contoh kombinasi prodi & kelas dari jadwal kuliah yang aktif
        $sampleJadwal = $pdo->query("SELECT j.kelas, pr.kode as kode_prodi 
            FROM `jadwal_kuliah` j 
            JOIN `prodi` pr ON pr.id = j.prodi_id 
            WHERE j.aktif = 1 
            GROUP BY j.kelas, pr.kode 
            ORDER BY pr.kode ASC, j.kelas ASC 
            LIMIT 4")->fetchAll();

        if (!empty($sampleJadwal)) {
            $sampleNames = ['Ahmad Fauzi', 'Muhammad Ridwan', 'Siti Nurhaliza', 'Fathur Rahman', 'Zainab Al-Kubro', 'Nur Halimah'];
            $i = 0;
            foreach ($sampleJadwal as $sj) {
                $p = $sj['kode_prodi'];
                $k = $sj['kelas'];
                $nim1 = $tahunSekarang . sprintf('%05d', ($i * 2 + 1));
                $nim2 = $tahunSekarang . sprintf('%05d', ($i * 2 + 2));
                $n1 = $sampleNames[($i * 2) % count($sampleNames)];
                $n2 = $sampleNames[($i * 2 + 1) % count($sampleNames)];
                echo "{$nim1};{$n1};{$p};{$k};{$tahunSekarang}\n";
                echo "{$nim2};{$n2};{$p};{$k};{$tahunSekarang}\n";
                $i++;
            }
        } else {
            echo "202300001;Ahmad Fauzi;{$p1};{$p1}-01;{$tahunSekarang}\n";
            echo "202300002;Muhammad Ridwan;{$p1};{$p1}-01;{$tahunSekarang}\n";
            echo "202300003;Siti Nurhaliza;{$p2};{$p2}-01;{$tahunSekarang}\n";
        }
        exit;
    }
}

require_once __DIR__ . '/header.php';
require_once dirname(__DIR__) . '/app/Services/CsvReader.php';
require_once dirname(__DIR__) . '/app/Actions/Import/ImportJadwalAction.php';
require_once dirname(__DIR__) . '/app/Actions/Import/ImportMahasiswaAction.php';

use App\Actions\Import\ImportJadwalAction;
use App\Actions\Import\ImportMahasiswaAction;

$message = '';
$error = '';
$previewData = null;
$jenisImport = $_POST['jenis'] ?? ($_GET['tab'] ?? 'jadwal');
$uploadedTempFile = $_SESSION['stai_import_temp_file'] ?? null;

// Periode aktif
$activePeriode = $pdo->query("SELECT * FROM `periode_akademik` WHERE `aktif` = 1 LIMIT 1")->fetch();
$periodeId = $activePeriode ? (int)$activePeriode['id'] : 1;

// Referensi Prodi & Dosen aktif untuk panduan admin
$activeProdiList = $pdo->query("SELECT `kode`, `nama` FROM `prodi` WHERE `aktif` = 1 ORDER BY `urutan` ASC, `id` ASC")->fetchAll();
$activeDosenList = $pdo->query("SELECT `kode_nidn`, `nama` FROM `dosen` WHERE `aktif` = 1 ORDER BY `nama` ASC")->fetchAll();

// Proses Form Impor
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'preview_csv') {
        if (!isset($_FILES['file_csv']) || $_FILES['file_csv']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Gagal mengunggah berkas CSV.';
        } else {
            $tempPath = dirname(__DIR__) . '/storage/imports/' . uniqid('import_') . '.csv';
            move_uploaded_file($_FILES['file_csv']['tmp_name'], $tempPath);
            $_SESSION['stai_import_temp_file'] = $tempPath;
            $_SESSION['stai_import_jenis'] = $jenisImport;

            try {
                if ($jenisImport === 'jadwal') {
                    $actionObj = new ImportJadwalAction($pdo);
                    $previewData = $actionObj->preview($tempPath, $periodeId);
                } else {
                    $actionObj = new ImportMahasiswaAction($pdo);
                    $previewData = $actionObj->preview($tempPath, $periodeId);
                }
            } catch (Exception $e) {
                $error = 'Error membaca CSV: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'terapkan_import') {
        $tempPath = $_SESSION['stai_import_temp_file'] ?? null;
        $jenis = $_SESSION['stai_import_jenis'] ?? 'jadwal';
        $mode = $_POST['mode'] ?? 'ganti_semua';

        if ($tempPath && file_exists($tempPath)) {
            try {
                if ($jenis === 'jadwal') {
                    $actionObj = new ImportJadwalAction($pdo);
                    $res = $actionObj->apply($tempPath, $periodeId, $mode, $admin['id']);
                } else {
                    $actionObj = new ImportMahasiswaAction($pdo);
                    $res = $actionObj->apply($tempPath, $periodeId, $mode, $admin['id']);
                }
                $message = $res['pesan'];
                @unlink($tempPath);
                unset($_SESSION['stai_import_temp_file']);
            } catch (Exception $e) {
                $error = 'Gagal menyimpan impor: ' . $e->getMessage();
            }
        } else {
            $error = 'Berkas sementara impor tidak ditemukan. Silakan upload ulang.';
        }
    }
}
?>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:20px">
    <div style="display:flex;gap:12px;border-bottom:1px solid var(--line);padding-bottom:12px">
        <a href="?tab=jadwal" class="btn <?= $jenisImport === 'jadwal' ? '' : 'btn-secondary' ?>">
            📚 Impor Jadwal Kuliah
        </a>
        <a href="?tab=mahasiswa" class="btn <?= $jenisImport === 'mahasiswa' ? '' : 'btn-secondary' ?>">
            🎓 Impor Data Mahasiswa (Presensi &amp; TV)
        </a>
    </div>

    <div style="margin-top:20px">
        <h3>
            <?= $jenisImport === 'jadwal' ? 'Unggah Berkas CSV Jadwal Kuliah' : 'Unggah Berkas CSV Data Mahasiswa (Presensi Dosen &amp; Statistik TV)' ?>
            <small style="font-size:13px;font-weight:600;color:var(--muted)">
                (Periode Aktif: <b><?= htmlspecialchars($activePeriode['kode'] ?? 'N/A') ?></b>)
            </small>
        </h3>

        <form method="POST" enctype="multipart/form-data" style="margin-top:16px">
            <input type="hidden" name="action" value="preview_csv">
            <input type="hidden" name="jenis" value="<?= htmlspecialchars($jenisImport) ?>">

            <div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">
                <div class="form-group" style="flex:1;min-width:280px;margin-bottom:0">
                    <label>Pilih Berkas CSV (UTF-8, pemisah titik koma / koma)</label>
                    <input type="file" name="file_csv" accept=".csv" required style="padding:8px">
                </div>
                <button type="submit" class="btn">🔍 Unggah & Tampilkan Pratinjau (Dry-Run)</button>
                <a href="?download_template=<?= $jenisImport ?>" class="btn btn-secondary">
                    ⬇️ Unduh Template CSV
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-bottom:20px;background:#fbfdfc;border-color:var(--line)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
        <h4 style="font-size:14px;color:var(--g-dark);font-weight:700">
            💡 Kode Referensi yang Terdaftar di Sistem (Untuk Format CSV)
        </h4>
        <div style="display:flex;gap:8px">
            <a href="prodi.php" class="btn btn-secondary" style="font-size:12px;padding:6px 12px">🎓 Sesuaikan / Tambah Prodi &rarr;</a>
            <?php if ($jenisImport === 'jadwal'): ?>
                <a href="dosen.php" class="btn btn-secondary" style="font-size:12px;padding:6px 12px">👨‍🏫 Kelola Dosen &rarr;</a>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:8px">
            KODE PROGRAM STUDI AKTIF (Kolom <code>kode_prodi</code>):
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if (empty($activeProdiList)): ?>
                <span style="font-size:13px;color:var(--danger)">Belum ada prodi aktif. <a href="prodi.php">Klik di sini untuk menambah prodi</a>.</span>
            <?php else: ?>
                <?php foreach ($activeProdiList as $ap): ?>
                    <span class="badge ok" style="font-size:12px;padding:6px 10px;font-family:monospace" title="<?= htmlspecialchars($ap['nama']) ?>">
                        <b><?= htmlspecialchars($ap['kode']) ?></b> &mdash; <span style="font-family:sans-serif"><?= htmlspecialchars($ap['nama']) ?></span>
                    </span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($jenisImport === 'jadwal'): ?>
        <div style="margin-top:16px;border-top:1px dashed var(--line);padding-top:14px">
            <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:8px">
                KODE DOSEN AKTIF (Kolom <code>kode_dosen</code>):
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;max-height:110px;overflow-y:auto;padding:8px;background:#fff;border-radius:8px;border:1px solid var(--line)">
                <?php if (empty($activeDosenList)): ?>
                    <span style="font-size:13px;color:var(--danger)">Belum ada data dosen aktif. <a href="dosen.php">Tambah dosen di sini</a>.</span>
                <?php else: ?>
                    <?php foreach ($activeDosenList as $ad): ?>
                        <span class="badge off" style="font-size:11px;padding:4px 8px" title="<?= htmlspecialchars($ad['nama']) ?>">
                            <b><?= htmlspecialchars($ad['kode_nidn']) ?></b>: <?= htmlspecialchars($ad['nama']) ?>
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <?php
        $activeKelases = $pdo->query("SELECT DISTINCT j.kelas, pr.kode as prodi_kode 
            FROM `jadwal_kuliah` j 
            JOIN `prodi` pr ON pr.id = j.prodi_id 
            WHERE j.aktif = 1 
            ORDER BY pr.kode ASC, j.kelas ASC")->fetchAll();
        ?>
        <div style="margin-top:16px;border-top:1px dashed var(--line);padding-top:14px">
            <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:8px">
                FORMAT KOLOM CSV: <code>nim;nama;kode_prodi;kelas;tahun_angkatan</code>
            </div>
            <p style="font-size:12px;color:var(--muted);margin-bottom:8px">
                Gunakan nama kelas (kolom <code>kelas</code>) yang sama dengan kelas pada jadwal perkuliahan agar mahasiswa otomatis terhubung ke presensi dosen:
            </p>
            <div style="display:flex;gap:6px;flex-wrap:wrap;max-height:90px;overflow-y:auto;padding:8px;background:#fff;border-radius:8px;border:1px solid var(--line)">
                <?php if (empty($activeKelases)): ?>
                    <span style="font-size:12px;color:var(--muted)">Belum ada kelas jadwal terdaftar.</span>
                <?php else: ?>
                    <?php foreach ($activeKelases as $ak): ?>
                        <span class="badge ok" style="font-size:11px;padding:3px 8px">
                            <b><?= htmlspecialchars($ak['kelas']) ?></b> (<?= htmlspecialchars($ak['prodi_kode']) ?>)
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($previewData): ?>
    <div class="card">
        <h3>📋 Hasil Pratinjau (Dry-Run) Impor CSV</h3>
        <div style="display:flex;gap:20px;margin-bottom:20px;flex-wrap:wrap">
            <div>Total Baris: <b><?= $previewData['total_baris'] ?></b></div>
            <div>Valid: <b style="color:var(--g)"><?= $previewData['total_valid'] ?></b></div>
            <div>Error: <b style="color:var(--danger)"><?= $previewData['total_error'] ?></b></div>
            <?php if (isset($previewData['total_warning'])): ?>
                <div>Peringatan: <b style="color:#d97706"><?= $previewData['total_warning'] ?></b></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($previewData['errors'])): ?>
            <div class="alert err">
                <b>Daftar Kesalahan (Impor tidak dapat diterapkan sebelum diperbaiki):</b>
                <ul style="margin-left:20px;margin-top:6px">
                    <?php foreach ($previewData['errors'] as $err): ?>
                        <li>Baris <?= $err['baris'] ?>: [Kolom: <?= htmlspecialchars($err['kolom']) ?>] <?= htmlspecialchars($err['pesan']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($previewData['warnings'])): ?>
            <div style="background:#fef3c7;color:#92400e;padding:12px;border-radius:10px;margin-bottom:16px;font-size:13px">
                <b>Peringatan:</b>
                <ul style="margin-left:20px;margin-top:4px">
                    <?php foreach ($previewData['warnings'] as $w): ?>
                        <li>Baris <?= $w['baris'] ?>: <?= htmlspecialchars($w['pesan']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($previewData['valid_rows']) && ($previewData['format'] ?? '') === 'detail'): ?>
            <div style="margin:16px 0">
                <div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap">
                    <span class="badge ok" style="padding:6px 12px;font-size:12.5px">
                        <b><?= count($previewData['valid_rows']) ?></b> Mahasiswa
                    </span>
                    <span class="badge ok" style="padding:6px 12px;font-size:12.5px;background:#e0f2fe;color:#0284c7">
                        <b><?= count($previewData['rekap_kelas'] ?? []) ?></b> Kelas
                    </span>
                    <span class="badge ok" style="padding:6px 12px;font-size:12.5px;background:#fef3c7;color:#b45309">
                        <b><?= count($previewData['rekap_prodi'] ?? []) ?></b> Prodi
                    </span>
                </div>

                <div style="max-height:260px;overflow-y:auto;border:1px solid var(--line);border-radius:10px">
                    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
                        <thead>
                            <tr style="background:#f8fafc;text-align:left;border-bottom:1px solid var(--line)">
                                <th style="padding:8px 12px;width:35px">#</th>
                                <th style="padding:8px 12px">NIM</th>
                                <th style="padding:8px 12px">Nama Mahasiswa</th>
                                <th style="padding:8px 12px">Prodi</th>
                                <th style="padding:8px 12px">Kelas</th>
                                <th style="padding:8px 12px">Angkatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sampleRows = array_slice($previewData['valid_rows'], 0, 15);
                            foreach ($sampleRows as $sIdx => $sr): 
                            ?>
                                <tr style="border-bottom:1px solid #f1f5f9">
                                    <td style="padding:6px 12px;color:var(--muted)"><?= $sIdx + 1 ?></td>
                                    <td style="padding:6px 12px;font-family:monospace"><?= htmlspecialchars($sr['nim'] ?: '-') ?></td>
                                    <td style="padding:6px 12px;font-weight:600"><?= htmlspecialchars($sr['nama']) ?></td>
                                    <td style="padding:6px 12px"><span class="badge ok" style="font-size:11px;padding:2px 6px"><?= htmlspecialchars($sr['kode_prodi']) ?></span></td>
                                    <td style="padding:6px 12px"><b><?= htmlspecialchars($sr['kelas']) ?></b></td>
                                    <td style="padding:6px 12px"><?= htmlspecialchars($sr['tahun_angkatan']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($previewData['valid_rows']) > 15): ?>
                    <small style="color:var(--muted);display:block;margin-top:6px">... dan <?= count($previewData['valid_rows']) - 15 ?> baris mahasiswa lainnya.</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($previewData['total_error'] === 0 && $previewData['total_valid'] > 0): ?>
            <form method="POST" style="margin-top:20px;padding:20px;background:var(--g-soft);border-radius:12px">
                <input type="hidden" name="action" value="terapkan_import">
                <div class="form-group">
                    <label>Pilih Mode Penyimpanan ke Database:</label>
                    <select name="mode" style="background:#fff;max-width:400px">
                        <option value="ganti_semua">(Direkomendasikan) Ganti Semua Data Periode Ini</option>
                        <option value="tambah_perbarui">Tambah / Perbarui Data yang Sudah Ada</option>
                    </select>
                </div>
                <button type="submit" class="btn" style="font-size:15px;padding:10px 24px">
                    💾 Terapkan <?= $previewData['total_valid'] ?> Data ke Database Sekarang
                </button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>

