/**
 * slider.js - Controller Rotasi Slide & Video Player HTML5
 * Selaras dengan PRD Backend Layar Kampus STAI Sidogiri v1
 */

const Slider = (function() {
  let order = [];
  let currentIndex = -1;
  let slideTimer = null;
  let spotInterval = null;
  let spotTimeout = null;
  let currentVideoUrl = "";

  function setupSlides(state) {
    const jadwalList = (state && state.jadwal_hari_ini) || [];
    const totalJadwalPages = Components.getJadwalTotalPages(jadwalList);

    const newOrder = [];
    for (let p = 0; p < totalJadwalPages; p++) {
      newOrder.push({ id: 's-jadwal', page: p });
    }
    newOrder.push({ id: 's-mhs', page: 0 });

    // Tampilkan slide agenda & event HANYA jika terdapat agenda aktif
    if (state.agenda && Array.isArray(state.agenda) && state.agenda.length > 0) {
      newOrder.push({ id: 's-agenda', page: 0 });
    }

    // Tampilkan slide video profil HANYA jika aktif dan memiliki URL video
    if (state.video && state.video.aktif && state.video.url) {
      newOrder.push({ id: 's-video', page: 0 });
    }

    // Hanya rebuild bila urutan / jumlah halaman berubah
    const oldKey = JSON.stringify(order);
    const newKey = JSON.stringify(newOrder);

    if (oldKey !== newKey) {
      order = newOrder;
      const dots = document.getElementById('dots');
      if (dots) {
        dots.innerHTML = order.map((_, i) => `<span data-index="${i}" title="Langkah ${i + 1}"></span>`).join('');
        dots.querySelectorAll('span').forEach(sp => {
          sp.onclick = (e) => {
            const idx = Number(e.currentTarget.dataset.index);
            goTo(idx, Api.getState());
          };
        });
      }

      // Jika slide yang sedang aktif tidak lagi ada di urutan baru, kembali ke langkah awal
      if (currentIndex === -1 || currentIndex >= order.length) {
        goTo(0, state);
      } else {
        updateDotsProgress(state);
      }
    }
  }

  function updateDotsProgress(state) {
    if (currentIndex < 0 || currentIndex >= order.length) return;
    const currentStep = order[currentIndex];
    const isVideo = currentStep.id === 's-video';
    const slideDuration = isVideo
      ? ((state.video && state.video.durasi_detik) || 180)
      : ((state.pengaturan && state.pengaturan.slide_detik) || 10);

    document.querySelectorAll('#dots span').forEach((dot, i) => {
      dot.classList.remove('on');
      void dot.offsetWidth;
      if (i === currentIndex) {
        dot.style.setProperty('--dur', slideDuration + 's');
        dot.classList.add('on');
      }
    });
  }

  function startSpotlight(el, spotMs = 1300) {
    clearInterval(spotInterval);
    clearTimeout(spotTimeout);
    const items = [...el.querySelectorAll('.spot')];
    items.forEach(i => i.classList.remove('lit'));
    if (!items.length) return;

    let k = 0;
    const step = () => {
      items.forEach(i => i.classList.remove('lit'));
      items[k % items.length].classList.add('lit');
      k++;
    };
    spotTimeout = setTimeout(() => {
      step();
      spotInterval = setInterval(step, spotMs);
    }, 1100);
  }

  function goTo(targetIndex, state) {
    if (!order.length) return;
    clearTimeout(slideTimer);

    const oldStep = currentIndex >= 0 ? order[currentIndex] : null;
    currentIndex = ((targetIndex % order.length) + order.length) % order.length;
    const currentStep = order[currentIndex];

    const oldId = oldStep ? oldStep.id : null;
    const currentId = currentStep.id;
    const currentPage = currentStep.page || 0;

    const oldEl = oldId ? document.getElementById(oldId) : null;
    const currentEl = document.getElementById(currentId);
    if (!currentEl) return;

    // Transisi slide keluar jika berpindah ke elemen kartu slide yang berbeda
    if (oldId && oldId !== currentId) {
      oldEl.classList.remove('on');
      oldEl.classList.add('out');
      setTimeout(() => oldEl.classList.remove('out'), 900);
      currentEl.classList.add('on');
    } else if (!currentEl.classList.contains('on')) {
      currentEl.classList.add('on');
    }

    const isVideo = currentId === 's-video';
    const slideDuration = isVideo
      ? ((state.video && state.video.durasi_detik) || 180)
      : ((state.pengaturan && state.pengaturan.slide_detik) || 10);

    // Animasi Indikator Dots Bawah
    document.querySelectorAll('#dots span').forEach((dot, i) => {
      dot.classList.remove('on');
      void dot.offsetWidth;
      if (i === currentIndex) {
        dot.style.setProperty('--dur', slideDuration + 's');
        dot.classList.add('on');
      }
    });

    if (currentId === 's-jadwal') {
      Components.renderJadwal(state.jadwal_hari_ini, currentPage, true);
    } else if (currentId === 's-mhs') {
      Components.animateCountUp(currentEl);
    }

    startSpotlight(currentEl, (state.pengaturan && state.pengaturan.spot_ms) || 1300);

    if (isVideo) {
      playVideo(state);
    } else {
      stopVideo();
      slideTimer = setTimeout(() => goTo(currentIndex + 1, Api.getState()), slideDuration * 1000);
    }
  }

  function playVideo(state) {
    const videoEl = document.getElementById('player');
    const progEl = document.getElementById('vprog');
    if (!videoEl) return;

    if (state.video && state.video.url && currentVideoUrl !== state.video.url) {
      currentVideoUrl = state.video.url;
      videoEl.src = state.video.url;
    }

    if (progEl) {
      progEl.style.transition = 'none';
      progEl.style.width = '0';
    }

    videoEl.currentTime = 0;

    const playPromise = videoEl.play();
    if (playPromise !== undefined) {
      playPromise.then(() => {
        videoEl.ontimeupdate = () => {
          if (videoEl.duration && progEl) {
            progEl.style.width = (videoEl.currentTime / videoEl.duration * 100) + '%';
          }
        };
        // Transisi saat video selesai diputar
        videoEl.onended = () => {
          goTo(currentIndex + 1, Api.getState());
        };
      }).catch(err => {
        console.warn("Autoplay ditolak atau berkas video gagal dimuat:", err);
        // Fallback otomatis jika video gagal diputar
        slideTimer = setTimeout(() => goTo(currentIndex + 1, Api.getState()), 10000);
      });
    }
  }

  function stopVideo() {
    const videoEl = document.getElementById('player');
    if (videoEl) {
      videoEl.pause();
      videoEl.ontimeupdate = null;
      videoEl.onended = null;
    }
  }

  function next(state) {
    goTo(currentIndex + 1, state || Api.getState());
  }

  function prev(state) {
    goTo(currentIndex - 1, state || Api.getState());
  }

  function goToJadwalPage(page) {
    const targetIdx = order.findIndex(step => step.id === 's-jadwal' && step.page === page);
    if (targetIdx !== -1) {
      goTo(targetIdx, Api.getState());
    } else {
      Components.setJadwalPage(page);
    }
  }

  function getCurrentStep() {
    return order[currentIndex] || null;
  }

  function getCurrentJadwalPage() {
    const step = getCurrentStep();
    return (step && step.id === 's-jadwal') ? (step.page || 0) : 0;
  }

  return {
    setupSlides: setupSlides,
    goTo: goTo,
    next: next,
    prev: prev,
    goToJadwalPage: goToJadwalPage,
    getCurrentStep: getCurrentStep,
    getCurrentJadwalPage: getCurrentJadwalPage
  };
})();
