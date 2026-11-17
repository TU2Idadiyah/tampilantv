/**
 * app.js - Main Application Bootstrap & Kiosk Orchestrator
 * Selaras dengan PRD Backend Layar Kampus STAI Sidogiri v1
 */

(function() {
  // 1. Skala Penuh 1080p (Auto Fit & Centering)
  const stage = document.getElementById('stage');
  function fit() {
    if (!stage) return;
    const s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
    stage.style.transform = `scale(${s})`;
    stage.style.left = ((window.innerWidth - 1920 * s) / 2) + 'px';
    stage.style.top = ((window.innerHeight - 1080 * s) / 2) + 'px';
  }
  window.addEventListener('resize', fit);
  fit();

  // 2. Handler Perubahan State dari Polling Server
  function handleStateChange(state) {
    Components.renderBranding(state.kampus);
    Components.renderWeather(state.cuaca);
    Components.renderPengurusList(state.pengurus, 6, false);
    Components.renderPengurusCards(state.pengurus, 4);
    Components.renderJadwal(state.jadwal_hari_ini, typeof Slider !== 'undefined' ? Slider.getCurrentJadwalPage() : undefined, false);
    Components.renderMahasiswa(state.mahasiswa);
    Components.renderAgenda(state.agenda);
    Components.renderDosen(state.dosen_hari_ini, typeof Components !== 'undefined' ? Components.getCurDosenPage() : 0, false);
    Components.renderTicker(state.pengumuman);
    Slider.setupSlides(state);
  }

  // 3. Handler Perubahan Token QR Resmi
  function handleQrChange(tokens, validSec) {
    QrEngine.setTokens(tokens, validSec);
  }

  // 4. Timer Rotasi Pagination Pengurus (Kiri)
  let pageIntervalTimer = null;
  function resetPaginationTimers(sec) {
    if (pageIntervalTimer) clearInterval(pageIntervalTimer);
    const ms = (sec || 10) * 1000;

    pageIntervalTimer = setInterval(() => {
      const state = Api.getState();
      Components.nextPengurusPage(state.pengurus, 4);
      Components.nextPengurusListPage(state.pengurus, 6);
      Components.nextDosenPage(state.dosen_hari_ini);
    }, ms);
  }

  // 5. Inisialisasi Service
  const initialState = Api.getState();
  handleStateChange(initialState);
  resetPaginationTimers(initialState.pengaturan.halaman_detik);

  // Timer Jam & Header (tiap detik)
  setInterval(() => {
    const s = Api.getState();
    Components.updateClockAndHeader(s);
    Components.markJadwal();
    Components.markDosen();
  }, 1000);

  // Timer Countdown QR
  QrEngine.initCountdown(() => {
    Api.refreshQr();
  });

  // Mulai Service Jaringan & Polling
  Api.init(
    (newState) => {
      handleStateChange(newState);
      if (newState.pengaturan && newState.pengaturan.halaman_detik !== initialState.pengaturan.halaman_detik) {
        resetPaginationTimers(newState.pengaturan.halaman_detik);
      }
    },
    (newTokens, validSec) => {
      handleQrChange(newTokens, validSec);
    }
  );

  // Listener Tombol Keyboard untuk Kontrol Manual
  window.addEventListener('keydown', e => {
    if (e.key === 'ArrowRight' || e.key === 'PageDown') {
      Slider.next();
    } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
      Slider.prev();
    }
  });

  console.log("Layar Informasi STAI Sidogiri v1 Siap.");
})();

