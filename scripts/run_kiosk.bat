@echo off
REM =====================================================================
REM Skrip Peluncur Layar TV Kiosk STAI Sidogiri (Windows)
REM Mengacu pada PRD Backend Bab 15 (Deployment & Kiosk TV)
REM =====================================================================

set TARGET_URL=http://localhost/tampilantv/?k=KUNCI_DEMO_2026
set CHROME_PATH1="%ProgramFiles%\Google\Chrome\Application\chrome.exe"
set CHROME_PATH2="%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
set EDGE_PATH1="%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
set EDGE_PATH2="%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"

echo Menyiapkan Layar Kiosk STAI Sidogiri...

if exist %CHROME_PATH1% (
    echo Menjalankan Google Chrome (64-bit) dalam Mode Kiosk...
    start "" %CHROME_PATH1% --kiosk --autoplay-policy=no-user-gesture-required "%TARGET_URL%"
    goto :done
)

if exist %CHROME_PATH2% (
    echo Menjalankan Google Chrome (32-bit) dalam Mode Kiosk...
    start "" %CHROME_PATH2% --kiosk --autoplay-policy=no-user-gesture-required "%TARGET_URL%"
    goto :done
)

if exist %EDGE_PATH1% (
    echo Menjalankan Microsoft Edge dalam Mode Kiosk...
    start "" %EDGE_PATH1% --kiosk --autoplay-policy=no-user-gesture-required "%TARGET_URL%"
    goto :done
)

if exist %EDGE_PATH2% (
    echo Menjalankan Microsoft Edge (64-bit) dalam Mode Kiosk...
    start "" %EDGE_PATH2% --kiosk --autoplay-policy=no-user-gesture-required "%TARGET_URL%"
    goto :done
)

echo Browser Chrome/Edge tidak ditemukan di folder standar. Membuka di browser default...
start "" "%TARGET_URL%"

:done
echo Layar Kiosk telah diluncurkan.

