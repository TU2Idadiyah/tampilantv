@echo off
REM =====================================================================
REM Skrip Pencadangan Otomatis Basis Data MySQL STAI Sidogiri
REM Mengacu pada PRD Bab 13 & 15: Backup Harian & Retensi 14 Hari
REM =====================================================================

set MYSQLDUMP_PATH=C:\xampp\mysql\bin\mysqldump.exe
set DB_NAME=stai_display
set DB_USER=root
set DB_PASS=
set BACKUP_DIR=C:\xampp\htdocs\tampilantv\storage\backups

if not exist "%BACKUP_DIR%" (
    mkdir "%BACKUP_DIR%"
)

for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set datetime=%%I
set CURR_DATE=%datetime:~0,4%-%datetime:~4,2%-%datetime:~6,2%
set BACKUP_FILE=%BACKUP_DIR%\stai_display_%CURR_DATE%.sql

echo Menjalankan backup database %DB_NAME% ke %BACKUP_FILE%...

if exist "%MYSQLDUMP_PATH%" (
    "%MYSQLDUMP_PATH%" -u%DB_USER% %DB_NAME% > "%BACKUP_FILE%"
    echo Backup berhasil diselesaikan pada %CURR_DATE%!
) else (
    echo mysqldump.exe tidak ditemukan di C:\xampp\mysql\bin\
)

REM Rotasi: Hapus backup yang berusia lebih dari 14 hari (PRD Bab 13)
forfiles /p "%BACKUP_DIR%" /s /m *.sql /d -14 /c "cmd /c del @path" 2>nul
echo Rotasi backup 14 hari selesai.

