-- =====================================================================
-- SKEMA BASIS DATA SISTEM INFORMASI LAYAR KAMPUS STAI SIDOGIRI
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Sesuai Dokumen PRD Backend v1 (16 Tabel Inti Berelasi)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `stai_display`
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `stai_display`;

-- Matikan foreign key check sementara agar pembuatan tabel berjalan mulus
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Tabel Users (Super Admin & Admin Operator)
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(191) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('super_admin', 'admin') NOT NULL DEFAULT 'admin',
    `remember_token` VARCHAR(100) NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabel Pengurus (Master Data Pengurus Kampus)
DROP TABLE IF EXISTS `pengurus`;
CREATE TABLE `pengurus` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nip` VARCHAR(50) NULL UNIQUE,
    `nama` VARCHAR(150) NOT NULL,
    `jabatan` VARCHAR(100) NOT NULL,
    `urutan` INT NOT NULL DEFAULT 0,
    `foto_path` VARCHAR(255) NULL,
    `pin_hash` VARCHAR(255) NOT NULL,
    `aktif` TINYINT(1) NOT NULL DEFAULT 1,
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_pengurus_aktif_urutan` (`aktif`, `urutan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel Perangkat Pengurus (Pendaftaran Cookie HP Pengurus, Max 2 per akun)
DROP TABLE IF EXISTS `perangkat_pengurus`;
CREATE TABLE `perangkat_pengurus` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `pengurus_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL UNIQUE,
    `label` VARCHAR(150) NOT NULL,
    `terakhir_dipakai` DATETIME NOT NULL,
    `kedaluwarsa` DATETIME NOT NULL,
    `dicabut_pada` DATETIME NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_perangkat_pengurus` FOREIGN KEY (`pengurus_id`) REFERENCES `pengurus` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel Absensi Pengurus (Pencatatan Kehadiran Harian via QR / Manual - Multi Sesi & Durasi)
DROP TABLE IF EXISTS `absensi_pengurus`;
CREATE TABLE `absensi_pengurus` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `pengurus_id` BIGINT UNSIGNED NOT NULL,
    `tanggal` DATE NOT NULL,
    `sesi_ke` INT UNSIGNED NOT NULL DEFAULT 1,
    `waktu_masuk` TIME NOT NULL,
    `waktu_absen` TIME NOT NULL COMMENT 'Alias waktu masuk untuk kompatibilitas',
    `waktu_pulang` TIME NULL,
    `status` ENUM('masuk', 'pulang') NOT NULL DEFAULT 'masuk',
    `durasi_menit` INT UNSIGNED NULL COMMENT 'Durasi sesi dalam menit',
    `metode` ENUM('qr', 'manual') NOT NULL DEFAULT 'qr',
    `metode_masuk` ENUM('qr', 'manual') NOT NULL DEFAULT 'qr',
    `metode_pulang` ENUM('qr', 'manual') NULL,
    `perangkat_id` BIGINT UNSIGNED NULL,
    `perangkat_id_pulang` BIGINT UNSIGNED NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `ip_pulang` VARCHAR(45) NULL,
    `dibuat_oleh` BIGINT UNSIGNED NULL COMMENT 'Diisi ID User Admin jika koreksi manual',
    `catatan` TEXT NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_absensi_pengurus_tanggal` (`pengurus_id`, `tanggal`),
    INDEX `idx_absensi_tanggal` (`tanggal`),
    CONSTRAINT `fk_absensi_pengurus` FOREIGN KEY (`pengurus_id`) REFERENCES `pengurus` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_absensi_perangkat` FOREIGN KEY (`perangkat_id`) REFERENCES `perangkat_pengurus` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_absensi_perangkat_pulang` FOREIGN KEY (`perangkat_id_pulang`) REFERENCES `perangkat_pengurus` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_absensi_dibuat_oleh` FOREIGN KEY (`dibuat_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabel Dosen (Master Data Dosen Pengajar)
DROP TABLE IF EXISTS `dosen`;
CREATE TABLE `dosen` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `kode_nidn` VARCHAR(50) NOT NULL UNIQUE,
    `nama` VARCHAR(150) NOT NULL,
    `foto_path` VARCHAR(255) NULL,
    `no_hp` VARCHAR(30) NULL,
    `pin` VARCHAR(100) NULL,
    `aktif` TINYINT(1) NOT NULL DEFAULT 1,
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabel Kehadiran Dosen (Status Mengajar Harian & Konfirmasi Mode Daring/Luring/Tukar)
DROP TABLE IF EXISTS `kehadiran_dosen`;
CREATE TABLE `kehadiran_dosen` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `dosen_id` BIGINT UNSIGNED NOT NULL,
    `jadwal_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `tanggal` DATE NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'hadir' COMMENT 'hadir, tidak_hadir, tukar_jam, izin, alpa',
    `mode` ENUM('offline', 'online') NULL COMMENT 'offline (tatap muka) atau online (daring)',
    `jam_ganti` VARCHAR(100) NULL COMMENT 'Jam/waktu pengganti jika tukar jam',
    `keterangan` TEXT NULL COMMENT 'Catatan / Alasan / Link Kuliah Online',
    `waktu_absen` TIME NULL,
    `metode` ENUM('jadwal', 'manual', 'qr', 'portal_dosen') NOT NULL DEFAULT 'portal_dosen',
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_kehadiran_dosen_id` (`dosen_id`),
    UNIQUE KEY `uk_kehadiran_dosen_jadwal` (`dosen_id`, `tanggal`, `jadwal_id`),
    CONSTRAINT `fk_kehadiran_dosen` FOREIGN KEY (`dosen_id`) REFERENCES `dosen` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabel Periode Akademik (Tahun Ajaran / Semester Aktif)
DROP TABLE IF EXISTS `periode_akademik`;
CREATE TABLE `periode_akademik` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `kode` VARCHAR(50) NOT NULL UNIQUE,
    `mulai` DATE NOT NULL,
    `selesai` DATE NOT NULL,
    `aktif` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabel Program Studi
DROP TABLE IF EXISTS `prodi`;
CREATE TABLE `prodi` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `kode` VARCHAR(20) NOT NULL UNIQUE,
    `nama` VARCHAR(100) NOT NULL,
    `urutan` INT NOT NULL DEFAULT 0,
    `aktif` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tabel Statistik Mahasiswa (Matriks Prodi & Angkatan)
DROP TABLE IF EXISTS `statistik_mahasiswa`;
CREATE TABLE `statistik_mahasiswa` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `periode_id` BIGINT UNSIGNED NOT NULL,
    `prodi_id` BIGINT UNSIGNED NOT NULL,
    `tahun_angkatan` INT NOT NULL,
    `jumlah` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_statistik_mhs` (`periode_id`, `prodi_id`, `tahun_angkatan`),
    CONSTRAINT `fk_statistik_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_akademik` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_statistik_prodi` FOREIGN KEY (`prodi_id`) REFERENCES `prodi` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Tabel Jadwal Kuliah (Perkuliahan Harian TV)
DROP TABLE IF EXISTS `jadwal_kuliah`;
CREATE TABLE `jadwal_kuliah` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `periode_id` BIGINT UNSIGNED NOT NULL,
    `prodi_id` BIGINT UNSIGNED NOT NULL,
    `hari` TINYINT UNSIGNED NOT NULL COMMENT '1=Senin, 2=Selasa, 3=Rabu, 4=Kamis, 5=Jumat, 6=Sabtu, 7=Minggu',
    `jam_mulai` TIME NOT NULL,
    `jam_selesai` TIME NOT NULL,
    `mata_kuliah` VARCHAR(150) NOT NULL,
    `kelas` VARCHAR(50) NOT NULL,
    `dosen_id` BIGINT UNSIGNED NOT NULL,
    `ruang` VARCHAR(50) NOT NULL,
    `aktif` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_jadwal_periode_hari` (`periode_id`, `hari`, `jam_mulai`),
    CONSTRAINT `fk_jadwal_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_akademik` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_jadwal_prodi` FOREIGN KEY (`prodi_id`) REFERENCES `prodi` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_jadwal_dosen` FOREIGN KEY (`dosen_id`) REFERENCES `dosen` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Tabel Agenda (Kegiatan & Event Mendatang)
DROP TABLE IF EXISTS `agenda`;
CREATE TABLE `agenda` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `judul` VARCHAR(200) NOT NULL,
    `lokasi` VARCHAR(150) NOT NULL,
    `mulai_at` DATETIME NOT NULL,
    `selesai_at` DATETIME NULL,
    `tampil_dari` DATE NOT NULL,
    `tampil_sampai` DATE NOT NULL,
    `aktif` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_agenda_tampil` (`aktif`, `mulai_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Tabel Pengumuman (Running Text Ticker Bawah)
DROP TABLE IF EXISTS `pengumuman`;
CREATE TABLE `pengumuman` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `teks` TEXT NOT NULL,
    `urutan` INT NOT NULL DEFAULT 0,
    `tampil_dari` DATE NOT NULL,
    `tampil_sampai` DATE NOT NULL,
    `aktif` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_pengumuman_tampil` (`aktif`, `tampil_sampai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Tabel Media (Video Profil & Logo Kampus)
DROP TABLE IF EXISTS `media`;
CREATE TABLE `media` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `koleksi` ENUM('video_profil', 'logo') NOT NULL,
    `path` VARCHAR(255) NOT NULL,
    `mime` VARCHAR(100) NOT NULL,
    `ukuran_byte` BIGINT UNSIGNED NOT NULL,
    `durasi_detik` INT NOT NULL DEFAULT 0,
    `aktif` TINYINT(1) NOT NULL DEFAULT 0,
    `diunggah_oleh` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_media_user` FOREIGN KEY (`diunggah_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Tabel Pengaturan (Key-Value Konfigurasi Sistem)
DROP TABLE IF EXISTS `pengaturan`;
CREATE TABLE `pengaturan` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `kunci` VARCHAR(100) NOT NULL UNIQUE,
    `nilai` JSON NOT NULL,
    `tipe` VARCHAR(50) NOT NULL DEFAULT 'string',
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Tabel Import Log (Riwayat & Audit Impor CSV)
DROP TABLE IF EXISTS `import_log`;
CREATE TABLE `import_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `jenis` VARCHAR(50) NOT NULL,
    `nama_file` VARCHAR(255) NOT NULL,
    `hash_file` VARCHAR(64) NOT NULL,
    `total_baris` INT NOT NULL DEFAULT 0,
    `berhasil` INT NOT NULL DEFAULT 0,
    `gagal` INT NOT NULL DEFAULT 0,
    `laporan_path` VARCHAR(255) NULL,
    `status` ENUM('selesai', 'gagal', 'sebagian') NOT NULL DEFAULT 'selesai',
    `user_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_import_hash` (`hash_file`),
    CONSTRAINT `fk_import_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Tabel Audit Log (Pencatatan Aktivitas Keamanan & Perubahan Data)
DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `aktor_tipe` VARCHAR(50) NOT NULL,
    `aktor_id` BIGINT UNSIGNED NULL,
    `aksi` VARCHAR(100) NOT NULL,
    `entitas` VARCHAR(100) NOT NULL,
    `entitas_id` BIGINT UNSIGNED NULL,
    `meta` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_audit_entitas` (`entitas`, `entitas_id`),
    INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

