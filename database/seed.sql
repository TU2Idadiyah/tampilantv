-- =====================================================================
-- DATA AWAL (SEEDER) SISTEM INFORMASI LAYAR KAMPUS STAI SIDOGIRI
-- Sesuai Dokumen PRD Backend v1 & Data Prototipe TV
-- =====================================================================

USE `stai_display`;

-- 1. Pengguna Admin
INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `created_at`, `updated_at`) VALUES
(1, 'Super Administrator', 'superadmin@stai-sidogiri.ac.id', '$2y$10$CrH98/ClWeNgGDVTWiz8M.I8C4c405bOQw1cWf4/hymQyQRjYmudK', 'super_admin', NOW(), NOW()),
(2, 'Operator Layar Kampus', 'operator@stai-sidogiri.ac.id', '$2y$10$CrH98/ClWeNgGDVTWiz8M.I8C4c405bOQw1cWf4/hymQyQRjYmudK', 'admin', NOW(), NOW())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 2. Pengaturan Sistem
INSERT INTO `pengaturan` (`kunci`, `nilai`, `tipe`, `created_at`, `updated_at`) VALUES
('nama_kampus', '"STAI SIDOGIRI"', 'string', NOW(), NOW()),
('subjudul_kampus', '"Sekolah Tinggi Agama Islam Sidogiri · Pasuruan, Jawa Timur"', 'string', NOW(), NOW()),
('kota', '"Pasuruan"', 'string', NOW(), NOW()),
('latitude', '-7.6453', 'float', NOW(), NOW()),
('longitude', '112.9075', 'float', NOW(), NOW()),
('slide_detik', '10', 'integer', NOW(), NOW()),
('halaman_detik', '10', 'integer', NOW(), NOW()),
('video_aktif', 'true', 'boolean', NOW(), NOW()),
('hijriah_offset', '0', 'integer', NOW(), NOW()),
('qr_rotasi_detik', '30', 'integer', NOW(), NOW()),
('qr_base_url', '"http://localhost/tampilantv/absen/scan"', 'string', NOW(), NOW()),
('subnet_diizinkan', '["127.0.0.1", "::1", "192.168.0.0/16", "10.0.0.0/8", "172.16.0.0/12"]', 'json', NOW(), NOW()),
('hash_kunci_layar', '"e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"', 'string', NOW(), NOW())
ON DUPLICATE KEY UPDATE `nilai`=VALUES(`nilai`);

-- 3. Periode Akademik
INSERT INTO `periode_akademik` (`id`, `kode`, `mulai`, `selesai`, `aktif`, `created_at`, `updated_at`) VALUES
(1, '2026/2027-Ganjil', '2026-09-01', '2027-02-28', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `aktif`=VALUES(`aktif`);

-- 4. Program Studi
INSERT INTO `prodi` (`id`, `kode`, `nama`, `urutan`, `aktif`, `created_at`, `updated_at`) VALUES
(1, 'PAI', 'Pendidikan Agama Islam', 1, 1, NOW(), NOW()),
(2, 'HES', 'Hukum Ekonomi Syariah', 2, 1, NOW(), NOW()),
(3, 'PBA', 'Pendidikan Bahasa Arab', 3, 1, NOW(), NOW()),
(4, 'ES', 'Ekonomi Syariah', 4, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

-- 5. Data Pengurus Kampus (PIN: 123456)
INSERT INTO `pengurus` (`id`, `nip`, `nama`, `jabatan`, `urutan`, `foto_path`, `pin_hash`, `aktif`, `created_at`, `updated_at`) VALUES
(1, '1985010101', 'Ust. Ahmad Fauzi, M.Pd', 'Ketua', 1, NULL, '$2y$10$AWugJYkq54U.QD1wh0kZk.Vw4gcPCLzesSeG3.yeixCVtIrwOGHjC', 1, NOW(), NOW()),
(2, '1985010102', 'Ust. Muhammad Rizqi, M.H', 'Wakil Ketua', 2, NULL, '$2y$10$AWugJYkq54U.QD1wh0kZk.Vw4gcPCLzesSeG3.yeixCVtIrwOGHjC', 1, NOW(), NOW()),
(3, '1985010103', 'Ust. Abdul Karim, M.Ag', 'Sekretaris', 3, NULL, '$2y$10$AWugJYkq54U.QD1wh0kZk.Vw4gcPCLzesSeG3.yeixCVtIrwOGHjC', 1, NOW(), NOW()),
(4, '1985010104', 'Ust. Hasan Basri, S.E', 'Bendahara', 4, NULL, '$2y$10$AWugJYkq54U.QD1wh0kZk.Vw4gcPCLzesSeG3.yeixCVtIrwOGHjC', 1, NOW(), NOW()),
(5, '1985010105', 'Ust. Ilham Maulana, S.Pd', 'Akademik', 5, NULL, '$2y$10$AWugJYkq54U.QD1wh0kZk.Vw4gcPCLzesSeG3.yeixCVtIrwOGHjC', 1, NOW(), NOW()),
(6, '1985010106', 'Ust. Zainal Arifin, S.Kom', 'Humas & IT', 6, NULL, '$2y$10$AWugJYkq54U.QD1wh0kZk.Vw4gcPCLzesSeG3.yeixCVtIrwOGHjC', 1, NOW(), NOW()),
(7, '1985010107', 'Ust. Fathur Rahman, S.Sos', 'Kemahasiswaan', 7, NULL, '$2y$10$AWugJYkq54U.QD1wh0kZk.Vw4gcPCLzesSeG3.yeixCVtIrwOGHjC', 1, NOW(), NOW()),
(8, '1985010108', 'Ust. Yusuf Hidayat, S.Pd', 'Perpustakaan', 8, NULL, '$2y$10$AWugJYkq54U.QD1wh0kZk.Vw4gcPCLzesSeG3.yeixCVtIrwOGHjC', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

-- 6. Data Dosen Pengajar
INSERT INTO `dosen` (`id`, `kode_nidn`, `nama`, `foto_path`, `aktif`, `created_at`, `updated_at`) VALUES
(1, 'D001', 'Dr. K.H. Abdullah, M.A', NULL, 1, NOW(), NOW()),
(2, 'D002', 'Ust. Hasan Basri, M.H', NULL, 1, NOW(), NOW()),
(3, 'D003', 'Dr. Mahmud Yusuf, Lc., M.A', NULL, 1, NOW(), NOW()),
(4, 'D004', 'Ahmad Syarif, M.Pd', NULL, 1, NOW(), NOW()),
(5, 'D005', 'Siti Aminah, M.Ak', NULL, 1, NOW(), NOW()),
(6, 'D006', 'Ust. Fadlil Munawwar, M.Th.I', NULL, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`);

-- 7. Statistik Mahasiswa (Matriks Prodi & Angkatan)
INSERT INTO `statistik_mahasiswa` (`periode_id`, `prodi_id`, `tahun_angkatan`, `jumlah`, `created_at`, `updated_at`) VALUES
(1, 1, 2022, 88, NOW(), NOW()), (1, 1, 2023, 112, NOW(), NOW()), (1, 1, 2024, 134, NOW(), NOW()), (1, 1, 2025, 150, NOW(), NOW()), (1, 1, 2026, 166, NOW(), NOW()),
(1, 2, 2022, 64, NOW(), NOW()), (1, 2, 2023, 79, NOW(), NOW()), (1, 2, 2024, 96, NOW(), NOW()), (1, 2, 2025, 110, NOW(), NOW()), (1, 2, 2026, 121, NOW(), NOW()),
(1, 3, 2022, 41, NOW(), NOW()), (1, 3, 2023, 52, NOW(), NOW()), (1, 3, 2024, 60, NOW(), NOW()), (1, 3, 2025, 73, NOW(), NOW()), (1, 3, 2026, 80, NOW(), NOW()),
(1, 4, 2022, 37, NOW(), NOW()), (1, 4, 2023, 45, NOW(), NOW()), (1, 4, 2024, 58, NOW(), NOW()), (1, 4, 2025, 66, NOW(), NOW()), (1, 4, 2026, 72, NOW(), NOW())
ON DUPLICATE KEY UPDATE `jumlah`=VALUES(`jumlah`);

-- 8. Jadwal Kuliah (Senin s/d Sabtu)
INSERT INTO `jadwal_kuliah` (`periode_id`, `prodi_id`, `hari`, `jam_mulai`, `jam_selesai`, `mata_kuliah`, `kelas`, `dosen_id`, `ruang`, `aktif`, `created_at`, `updated_at`) VALUES
-- Hari Ini (Dinamis sesuai hari berjalan)
(1, 1, (DAYOFWEEK(CURDATE()) + 5) % 7 + 1, '07:30:00', '09:10:00', 'Ushul Fiqh', 'PAI-3', 1, 'R. 101', 1, NOW(), NOW()),
(1, 2, (DAYOFWEEK(CURDATE()) + 5) % 7 + 1, '09:20:00', '11:00:00', 'Fiqh Muamalah', 'HES-5', 2, 'R. 203', 1, NOW(), NOW()),
(1, 1, (DAYOFWEEK(CURDATE()) + 5) % 7 + 1, '11:10:00', '12:50:00', 'Tafsir Ahkam', 'PAI-5', 3, 'R. 102', 1, NOW(), NOW()),
(1, 3, (DAYOFWEEK(CURDATE()) + 5) % 7 + 1, '13:00:00', '14:40:00', 'Metodologi Penelitian', 'PBA-7', 4, 'Lab. Bahasa', 1, NOW(), NOW()),
(1, 4, (DAYOFWEEK(CURDATE()) + 5) % 7 + 1, '15:00:00', '16:40:00', 'Akuntansi Syariah', 'ES-3', 5, 'R. 204', 1, NOW(), NOW()),
(1, 1, (DAYOFWEEK(CURDATE()) + 5) % 7 + 1, '16:50:00', '18:00:00', 'Hadis Tarbawi', 'PAI-1', 6, 'R. 101', 1, NOW(), NOW());

-- 9. Agenda Kampus
INSERT INTO `agenda` (`judul`, `lokasi`, `mulai_at`, `selesai_at`, `tampil_dari`, `tampil_sampai`, `aktif`, `created_at`, `updated_at`) VALUES
('Seminar Nasional Ekonomi Syariah', 'Aula Utama STAI Sidogiri', '2026-11-07 08:00:00', '2026-11-07 12:00:00', '2026-11-01', '2026-11-07', 1, NOW(), NOW()),
('Rapat Senat Akademik', 'Ruang Rapat Lt. 2', '2026-11-12 13:00:00', '2026-11-12 16:00:00', '2026-11-05', '2026-11-12', 1, NOW(), NOW()),
('Ujian Tengah Semester Ganjil', 'Seluruh Program Studi', '2026-11-20 07:30:00', '2026-11-27 15:00:00', '2026-11-10', '2026-11-27', 1, NOW(), NOW()),
('Wisuda & Haflah Akhirussanah', 'Aula Utama STAI Sidogiri', '2026-11-28 07:00:00', '2026-11-28 13:00:00', '2026-11-15', '2026-11-28', 1, NOW(), NOW());

-- 10. Pengumuman Ticker
INSERT INTO `pengumuman` (`teks`, `urutan`, `tampil_dari`, `tampil_sampai`, `aktif`, `created_at`, `updated_at`) VALUES
('Selamat datang di STAI Sidogiri Pasuruan', 1, '2026-01-01', '2026-12-31', 1, NOW(), NOW()),
('Pengurus wajib melakukan presensi kehadiran melalui scan QR pada layar sebelum memulai aktivitas', 2, '2026-01-01', '2026-12-31', 1, NOW(), NOW()),
('Pengisian KRS semester genap dibuka mulai 1 Desember 2026', 3, '2026-11-01', '2026-12-15', 1, NOW(), NOW()),
('Mahasiswa diharapkan menjaga kebersihan dan ketertiban lingkungan kampus', 4, '2026-01-01', '2026-12-31', 1, NOW(), NOW());
