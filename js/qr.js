/**
 * qr.js - QR Code Rendering & Flip Card Scheduler
 * Selaras dengan PRD Backend Layar Kampus STAI Sidogiri v1
 */

const QrEngine = (function() {
  let tokens = {};
  let countdownSec = 30;
  let flipTimers = [];

  function setTokens(newTokens, validSec = 30) {
    tokens = newTokens || {};
    countdownSec = validSec;
    renderAllQRs();
  }

  function renderAllQRs(container = '#cards') {
    const boxes = document.querySelectorAll(container + ' .qrbox');
    boxes.forEach(box => {
      box.innerHTML = '';
      const pengurusId = box.dataset.id;
      const targetUrl = tokens[pengurusId];

      if (!targetUrl || !window.QRCode) {
        box.innerHTML = '<span style="font-size:12px;color:var(--muted)">Memuat QR...</span>';
        return;
      }

      try {
        new QRCode(box, {
          text: targetUrl,
          width: 90,
          height: 90,
          colorDark: "#0f3920",
          colorLight: "#ffffff",
          correctLevel: QRCode.CorrectLevel.M
        });
      } catch (err) {
        console.error("Gagal menggambar QR:", err);
      }
    });
  }

  function scheduleFlips(items, container = '#cards') {
    // Bersihkan timer flip sebelumnya
    flipTimers.forEach(clearTimeout);
    flipTimers = [];

    items.forEach((p, i) => {
      // Jadwalkan flip kartu ke sisi QR
      const timerFlipToQr = setTimeout(() => {
        const card = document.querySelector(`${container} #pc${p.id}`);
        if (card) {
          card.classList.add('back');
        }
      }, 2500 + (i * 350));
      flipTimers.push(timerFlipToQr);

      // Jika pengurus sedang Hadir atau Pulang, kembalikan ke foto depan setelah 8 detik
      if (p.hadir || p.status === 'pulang') {
        const timerFlipToPhoto = setTimeout(() => {
          const card = document.querySelector(`${container} #pc${p.id}`);
          if (card) {
            card.classList.remove('back');
          }
        }, 9000 + (i * 350));
        flipTimers.push(timerFlipToPhoto);
      }
    });
  }

  function clearFlips() {
    flipTimers.forEach(clearTimeout);
    flipTimers = [];
  }

  function initCountdown(onExpired) {
    const qsm = document.getElementById('qsm');
    setInterval(() => {
      countdownSec--;
      if (countdownSec <= 0) {
        if (typeof onExpired === 'function') onExpired();
        countdownSec = 30;
      }
      if (qsm) {
        qsm.textContent = `Kode QR diperbarui ${Math.max(0, countdownSec)} dtk lagi`;
      }
    }, 1000);
  }

  return {
    setTokens: setTokens,
    renderAllQRs: renderAllQRs,
    scheduleFlips: scheduleFlips,
    clearFlips: clearFlips,
    initCountdown: initCountdown,
    getCountdown: function() { return countdownSec; }
  };
})();

