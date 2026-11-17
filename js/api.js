/**
 * api.js - Network & State Synchronization Service
 * Selaras dengan PRD Backend Layar Kampus STAI Sidogiri v1
 */

const Api = (function() {
  // 1. Inisialisasi Kunci Layar & Identitas Perangkat Layar
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('k')) {
    localStorage.setItem('stai_display_key', urlParams.get('k'));
    window.history.replaceState({}, document.title, window.location.pathname);
  }

  const displayKey = localStorage.getItem('stai_display_key') || '';
  let displayId = localStorage.getItem('stai_display_id');
  if (!displayId) {
    displayId = 'tv-' + Math.random().toString(36).substring(2, 9);
    localStorage.setItem('stai_display_id', displayId);
  }

  // 2. Default State & Inisialisasi dari Cache Lokal
  let state = {
    versi: "",
    waktu_server: new Date().toISOString(),
    pengaturan: {
      slide_detik: 10,
      halaman_detik: 10,
      video_aktif: true,
      hijriah_offset: 0,
      qr_rotasi_detik: 30
    },
    cuaca: { suhu_c: 0, kode: 2, usang: false },
    pengurus: [],
    dosen_hari_ini: [],
    jadwal_hari_ini: [],
    mahasiswa: { angkatan: [], prodi: [], total: 0 },
    agenda: [],
    pengumuman: [],
    video: { aktif: false, url: "", durasi_detik: 180 }
  };

  const cached = localStorage.getItem('stai_display_cache');
  if (cached) {
    try {
      state = Object.assign({}, state, JSON.parse(cached));
    } catch (e) {
      console.warn("Gagal membaca cache lokal:", e);
    }
  }

  let qrTokens = {};
  let qrValidSec = 30;
  let lastEtag = null;
  let serverDeltaMs = 0;
  let failedCount = 0;
  let stateChangeListeners = [];
  let qrChangeListeners = [];

  // Hitung waktu sinkron server
  function getServerDate() {
    return new Date(Date.now() + serverDeltaMs);
  }

  function getServerMinutes() {
    const d = getServerDate();
    return d.getHours() * 60 + d.getMinutes();
  }

  // Update indikator status koneksi
  function updateNetStatus(isConnected, message = "") {
    const el = document.getElementById('net-status');
    if (!el) return;
    if (isConnected) {
      el.classList.remove('active');
    } else {
      el.textContent = message || "⚠️ Jaringan Terputus · Menampilkan Data Lokal";
      el.classList.add('active');
    }
  }

  // Polling State Layar (GET /api/v1/display/state)
  async function pollState() {
    try {
      const headers = {
        'X-Display-Key': displayKey,
        'X-Display-Id': displayId
      };
      if (lastEtag) {
        headers['If-None-Match'] = lastEtag;
      }

      const res = await fetch('api/v1/display/state.php', {
        method: 'GET',
        headers: headers
      });

      // 304 Not Modified: Data sama, animasi tidak terganggu
      if (res.status === 304) {
        failedCount = 0;
        updateNetStatus(true);
        return;
      }

      if (res.status === 200) {
        lastEtag = res.headers.get('ETag');
        const data = await res.json();

        // Koreksi selisih jam server
        if (data.waktu_server) {
          serverDeltaMs = Date.parse(data.waktu_server) - Date.now();
        }

        // Auto-reload jika versi backend berubah (deploy baru)
        if (state.versi && data.versi && state.versi !== data.versi) {
          console.log("Versi baru terdeteksi:", data.versi, ". Memuat ulang layar...");
          window.location.reload();
          return;
        }

        state = data;
        localStorage.setItem('stai_display_cache', JSON.stringify(data));
        failedCount = 0;
        updateNetStatus(true);

        stateChangeListeners.forEach(fn => fn(state));
        return;
      }

      if (res.status === 401 || res.status === 403) {
        updateNetStatus(false, "⚠️ Akses Ditolak: Display Key Tidak Valid");
        return;
      }

      throw new Error(`HTTP Error: ${res.status}`);
    } catch (err) {
      failedCount++;
      if (failedCount >= 3) {
        updateNetStatus(false);
      }
    }
  }

  // Polling Token QR Resmi (GET /api/v1/display/qr)
  async function pollQr() {
    try {
      const res = await fetch('api/v1/display/qr.php', {
        method: 'GET',
        headers: {
          'X-Display-Key': displayKey,
          'X-Display-Id': displayId
        }
      });
      if (res.ok) {
        const data = await res.json();
        qrTokens = data.qr || {};
        qrValidSec = data.waktu_berlaku_detik || 30;
        qrChangeListeners.forEach(fn => fn(qrTokens, qrValidSec));
      }
    } catch (e) {
      // Diamkan bila jaringan lokal sedang down, token terakhir masih bisa dicoba
    }
  }

  // Kirim Heartbeat opsional
  async function sendHeartbeat() {
    try {
      await fetch('api/v1/display/heartbeat.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Display-Key': displayKey,
          'X-Display-Id': displayId
        },
        body: JSON.stringify({
          terakhir_aktif: new Date().toISOString()
        })
      });
    } catch (e) {}
  }

  return {
    init: function(onState, onQr) {
      if (onState) stateChangeListeners.push(onState);
      if (onQr) qrChangeListeners.push(onQr);

      // Panggil langsung pertama kali
      pollState();
      pollQr();

      // Loop polling sesuai PRD (State tiap 3 dtk, Heartbeat tiap 60 dtk)
      setInterval(pollState, 3000);
      setInterval(sendHeartbeat, 60000);
    },
    getState: function() { return state; },
    getQrTokens: function() { return qrTokens; },
    getServerDate: getServerDate,
    getServerMinutes: getServerMinutes,
    refreshQr: pollQr,
    onStateChange: function(fn) { stateChangeListeners.push(fn); },
    onQrChange: function(fn) { qrChangeListeners.push(fn); }
  };
})();

