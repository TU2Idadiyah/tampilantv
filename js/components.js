/**
 * components.js - Komponen UI Display Layar TV
 * Selaras dengan PRD Backend Layar Kampus STAI Sidogiri v1
 */

const Components = (function() {
  const COLORS = ["#0f3920", "#14532d", "#166534", "#15803d", "#0d9488", "#0369a1"];

  const WX_MAP = {
    0: ["<i class='fa-solid fa-sun'></i>", "Cerah"],
    1: ["<i class='fa-solid fa-cloud-sun'></i>", "Cerah berawan"],
    2: ["<i class='fa-solid fa-cloud-sun'></i>", "Berawan"],
    3: ["<i class='fa-solid fa-cloud'></i>", "Mendung"],
    45: ["<i class='fa-solid fa-smog'></i>", "Berkabut"],
    48: ["<i class='fa-solid fa-smog'></i>", "Berkabut"],
    51: ["<i class='fa-solid fa-cloud-rain'></i>", "Gerimis"],
    53: ["<i class='fa-solid fa-cloud-rain'></i>", "Gerimis"],
    55: ["<i class='fa-solid fa-cloud-rain'></i>", "Gerimis"],
    61: ["<i class='fa-solid fa-cloud-showers-heavy'></i>", "Hujan ringan"],
    63: ["<i class='fa-solid fa-cloud-showers-heavy'></i>", "Hujan"],
    65: ["<i class='fa-solid fa-cloud-showers-water'></i>", "Hujan lebat"],
    80: ["<i class='fa-solid fa-cloud-sun-rain'></i>", "Hujan lokal"],
    81: ["<i class='fa-solid fa-cloud-showers-heavy'></i>", "Hujan lokal"],
    82: ["<i class='fa-solid fa-cloud-bolt'></i>", "Hujan deras"],
    95: ["<i class='fa-solid fa-bolt'></i>", "Badai petir"],
    96: ["<i class='fa-solid fa-bolt'></i>", "Badai petir"],
    99: ["<i class='fa-solid fa-bolt'></i>", "Badai petir"]
  };

  const pad = n => String(n).padStart(2, '0');
  const toMin = t => { if (!t) return 0; const [h, m] = t.split(':').map(Number); return h * 60 + m; };
  const grad = i => `linear-gradient(135deg, ${COLORS[i % 6]}, #15803d)`;
  const ini = n => (n || '').replace(/(Ust\.|Dr\.|K\.H\.|Prof\.)\s*/g, '').split(' ').slice(0, 2).map(x => x[0]).join('').toUpperCase();

  // 0. Identitas Kampus & Logo
  function renderBranding(kampus) {
    if (!kampus) return;
    const logoEl = document.getElementById('logo');
    const titleEl = document.getElementById('brandTitle');
    const subEl = document.getElementById('brandSubtitle');

    if (titleEl && kampus.nama) {
      titleEl.textContent = kampus.nama;
    }
    if (subEl && kampus.subjudul) {
      subEl.textContent = kampus.subjudul;
    }
    if (logoEl) {
      if (kampus.logo_url) {
        logoEl.innerHTML = `<img src="${kampus.logo_url}" alt="Logo" onerror="this.onerror=null;this.parentElement.innerHTML='<span>STAI</span>';">`;
      } else {
        logoEl.innerHTML = `<span>STAI</span>`;
      }
    }
  }

  // 1. Clock, Dates, Hijriah & Weather
  function updateClockAndHeader(state) {
    const d = Api.getServerDate();
    const clk = document.getElementById('clk');
    const dM = document.getElementById('dM');
    const dH = document.getElementById('dH');

    if (clk) {
      clk.innerHTML = `${pad(d.getHours())}<span>:</span>${pad(d.getMinutes())}<small>${pad(d.getSeconds())}</small>`;
    }
    if (dM) {
      dM.textContent = d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    if (dH) {
      try {
        const offset = state.pengaturan ? (state.pengaturan.hijriah_offset || 0) : 0;
        const hd = new Date(d.getTime() + offset * 864e5);
        dH.textContent = new Intl.DateTimeFormat('id-ID-u-ca-islamic-umalqura', { day: 'numeric', month: 'long', year: 'numeric' }).format(hd);
      } catch (e) {
        dH.textContent = '';
      }
    }

    // Auto-reload jam 04:00 subuh (PRD Bab 9)
    if (d.getHours() === 4 && d.getMinutes() === 0 && d.getSeconds() === 0) {
      window.location.reload();
    }
  }

  function renderWeather(cuaca) {
    if (!cuaca || cuaca.suhu_c === undefined) return;
    const wIc = document.getElementById('wIc');
    const wT = document.getElementById('wT');
    const wD = document.getElementById('wD');
    const w = WX_MAP[cuaca.kode] || ["<i class='fa-solid fa-cloud-sun'></i>", "Berawan"];

    if (wIc) wIc.innerHTML = w[0];
    if (wT) wT.textContent = Math.round(cuaca.suhu_c) + "°C";
    if (wD) wD.textContent = "Pasuruan · " + w[1] + (cuaca.usang ? " (Offline)" : "");
  }

  // 2. Pengurus: List Atas & Kartu Bawah
  let listPage = 0;
  let cardPage = 0;

  function getCurCardIds(pengurus, cardsPerPage = 4) {
    return pengurus.slice(cardPage * cardsPerPage, cardPage * cardsPerPage + cardsPerPage).map(p => p.id);
  }

  function renderPengurusList(pengurus, listPerPage = 6, anim = true) {
    const box = document.getElementById('list');
    const cnt = document.getElementById('cnt');
    if (!box || !pengurus) return;

    const total = pengurus.length;
    const hadirCount = pengurus.filter(p => p.status === 'masuk' || (p.hadir && p.status !== 'pulang')).length;
    const pulangCount = pengurus.filter(p => p.status === 'pulang').length;
    if (cnt) cnt.textContent = `${hadirCount} Hadir · ${pulangCount} Pulang / ${total}`;

    if (!total) {
      box.innerHTML = '';
      return;
    }

    const pages = Math.ceil(total / listPerPage);
    listPage %= pages;
    const items = pengurus.slice(listPage * listPerPage, listPage * listPerPage + listPerPage);

    box.innerHTML = items.map((p, i) => {
      let rowClass = '';
      let statusHtml = '';
      let timeText = '—';

      if (p.status === 'masuk' || (p.hadir && p.status !== 'pulang')) {
        rowClass = 'ok';
        statusHtml = '<i class="fa-solid fa-check"></i> Hadir';
        timeText = p.waktu_masuk || p.waktu_absen || '—';
      } else if (p.status === 'pulang') {
        rowClass = 'pulang';
        statusHtml = '<i class="fa-solid fa-person-walking-arrow-right"></i> Pulang';
        timeText = p.waktu_pulang || p.waktu_absen || '—';
      } else {
        statusHtml = 'Belum hadir';
        timeText = '—';
      }

      return `
      <div class="row ${rowClass}" id="r${p.id}" style="--i:${i}">
        <div class="av">
          ${p.foto_url ? `<img src="${p.foto_url}" alt="${p.nama}" onerror="this.onerror=null;this.parentElement.innerHTML='${ini(p.nama)}';">` : ini(p.nama)}
        </div>
        <div class="rn"><b>${p.nama}</b><small>${p.jabatan}</small></div>
        <div class="st">${statusHtml}</div>
        <div class="tm">${timeText}</div>
      </div>`;
    }).join('');

    box.classList.remove('swap');
    if (anim) {
      void box.offsetWidth;
      box.classList.add('swap');
    }

    markCurList(pengurus);
  }

  function markCurList(pengurus) {
    const ids = getCurCardIds(pengurus);
    document.querySelectorAll('#list .row').forEach(r => {
      r.classList.toggle('cur', ids.includes(+r.id.slice(1)));
    });
  }

  function renderPengurusCards(pengurus, cardsPerPage = 4) {
    const container = document.getElementById('cards');
    if (!container || !pengurus) return;

    QrEngine.clearFlips();
    const items = pengurus.slice(cardPage * cardsPerPage, cardPage * cardsPerPage + cardsPerPage);

    container.innerHTML = items.map((p, i) => {
      let pcClass = '';
      let tickHtml = '';
      let pillHtml = '';
      let qrTitle = 'Scan untuk Masuk';
      let qrSub = p.nama;

      if (p.status === 'masuk' || (p.hadir && p.status !== 'pulang')) {
        pcClass = 'present';
        tickHtml = '<div class="tick"><i class="fa-solid fa-check"></i></div>';
        pillHtml = `<span class="pill ok"><i class="fa-solid fa-check"></i> Hadir ${p.waktu_masuk || p.waktu_absen || ''}</span>`;
        qrTitle = 'Scan untuk Pulang';
        qrSub = `Selesai Sesi ${p.sesi_ke || 1}`;
      } else if (p.status === 'pulang') {
        pcClass = 'pulang';
        tickHtml = '<div class="tick tick-pulang"><i class="fa-solid fa-door-open"></i></div>';
        const durText = p.total_durasi_formatted ? ` · ${p.total_durasi_formatted}` : '';
        pillHtml = `<span class="pill pulang"><i class="fa-solid fa-door-open"></i> Pulang ${p.waktu_pulang || ''}${durText}</span>`;
        qrTitle = 'Scan Masuk Lagi';
        qrSub = `Mulai Sesi ${(p.total_sesi || 0) + 1}`;
      } else {
        pillHtml = `<span class="pill"><i class="fa-regular fa-clock"></i> Belum hadir</span>`;
        qrTitle = 'Scan untuk Masuk';
        qrSub = p.nama;
      }

      return `
      <div class="pc ${pcClass}" id="pc${p.id}" style="--i:${i}" onclick="this.classList.toggle('back')">
        <div class="pc-in">
          <div class="pf">
            <div class="phw">
              <div class="photo" style="background:${grad(p.id)}">
                ${p.foto_url ? `<img src="${p.foto_url}" alt="${p.nama}" onerror="this.onerror=null;this.parentElement.innerHTML='${ini(p.nama)}';">` : ini(p.nama)}
              </div>
              ${tickHtml}
            </div>
            <div class="pt">
              <b>${p.nama}</b>
              <small>${p.jabatan}</small>
              ${pillHtml}
            </div>
          </div>
          <div class="pf rev">
            <div class="qrbox" data-id="${p.id}"></div>
            <div class="qt">
              <i><i class="fa-solid fa-mobile-screen-button"></i></i>
              <b>${qrTitle}</b>
              <small>${qrSub}</small>
            </div>
          </div>
        </div>
      </div>`;
    }).join('');

    QrEngine.renderAllQRs('#cards');
    QrEngine.scheduleFlips(items, '#cards');
    markCurList(pengurus);
  }

  function nextPengurusPage(pengurus, cardsPerPage = 4) {
    const pages = Math.ceil(pengurus.length / cardsPerPage);
    if (pages <= 1) {
      QrEngine.clearFlips();
      document.querySelectorAll('.pc.back').forEach(c => c.classList.remove('back'));
      QrEngine.scheduleFlips(pengurus.slice(0, cardsPerPage), '#cards');
      return;
    }
    const container = document.getElementById('cards');
    if (!container) return;
    container.classList.add('leave');
    setTimeout(() => {
      cardPage = (cardPage + 1) % pages;
      renderPengurusCards(pengurus, cardsPerPage);
      container.classList.remove('leave');
    }, 450);
  }

  function nextPengurusListPage(pengurus, listPerPage = 6) {
    if (Math.ceil(pengurus.length / listPerPage) > 1) {
      listPage++;
      renderPengurusList(pengurus, listPerPage, true);
    }
  }

  // 3. Jadwal Kuliah & Pagination
  const JADWAL_PER_PAGE = 7;
  let curJadwalPage = 0;
  let cachedJadwal = [];

  function getJadwalTotalPages(jadwal) {
    const list = jadwal || cachedJadwal || [];
    if (!list.length) return 1;
    return Math.max(1, Math.ceil(list.length / JADWAL_PER_PAGE));
  }

  function getCurJadwalPage() {
    return curJadwalPage;
  }

  function setJadwalPage(page) {
    renderJadwal(cachedJadwal, page, true);
  }

  function renderJadwal(jadwal, page, anim = true) {
    const jDay = document.getElementById('jDay');
    const jBody = document.getElementById('jBody');
    const jCnt = document.getElementById('jCnt');
    const jPagination = document.getElementById('jPagination');
    const jPages = document.getElementById('jPages');
    const jPageInfo = document.getElementById('jPageInfo');
    if (!jBody) return;

    if (jadwal !== undefined) {
      cachedJadwal = Array.isArray(jadwal) ? jadwal : [];
    }
    const list = cachedJadwal;

    if (jDay) {
      jDay.textContent = Api.getServerDate().toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });
    }

    if (!list.length) {
      jBody.innerHTML = '<div style="padding:40px;text-align:center;color:var(--muted);font-size:22px">Tidak ada perkuliahan aktif hari ini</div>';
      if (jCnt) jCnt.textContent = '0 Jadwal';
      if (jPagination) jPagination.style.display = 'none';
      return;
    }

    const totalPages = Math.max(1, Math.ceil(list.length / JADWAL_PER_PAGE));
    if (page !== undefined) {
      curJadwalPage = page;
    }
    curJadwalPage = Math.min(Math.max(0, curJadwalPage), totalPages - 1);

    if (jCnt) {
      jCnt.textContent = totalPages > 1
        ? `${list.length} Jadwal · Hal ${curJadwalPage + 1}/${totalPages}`
        : `${list.length} Jadwal`;
    }

    const startIndex = curJadwalPage * JADWAL_PER_PAGE;
    const items = list.slice(startIndex, startIndex + JADWAL_PER_PAGE);

    jBody.innerHTML = `
      <table class="sch">
        <thead>
          <tr>
            <th style="width:145px">Jam</th>
            <th>Mata Kuliah</th>
            <th style="width:230px">Dosen</th>
            <th style="width:115px">Ruang</th>
          </tr>
        </thead>
        <tbody>` +
        items.map((j, i) => {
          let statusTagHtml = '';
          if (j.status_kehadiran === 'hadir_online' || j.mode_kuliah === 'online') {
            statusTagHtml = '<span class="tag tag-online"><i class="fa-solid fa-globe"></i> Online</span>';
          } else if (j.status_kehadiran === 'hadir_offline' || j.mode_kuliah === 'offline') {
            statusTagHtml = '<span class="tag tag-offline"><i class="fa-solid fa-chalkboard-user"></i> Offline</span>';
          } else if (j.status_kehadiran === 'tukar_jam') {
            statusTagHtml = '<span class="tag tag-tukar"><i class="fa-solid fa-clock-rotate-left"></i> Tukar</span>';
          } else if (j.status_kehadiran === 'tidak_hadir') {
            statusTagHtml = '<span class="tag tag-tidak"><i class="fa-solid fa-user-xmark"></i> Izin</span>';
          }

          let ruangHtml = j.ruang;
          if (j.status_kehadiran === 'hadir_online' || j.mode_kuliah === 'online') {
            ruangHtml = `<span>${j.ruang}</span><span style="display:block;font-size:11px;font-weight:700;color:#0284c7">Daring</span>`;
          } else if (j.status_kehadiran === 'tukar_jam' && j.jam_ganti) {
            ruangHtml = `<span>${j.ruang}</span><span style="display:block;font-size:11px;font-weight:700;color:#b45309">${j.jam_ganti}</span>`;
          }

          return `
          <tr class="spot" style="--i:${i}" data-s="${j.jam_mulai}" data-e="${j.jam_selesai}">
            <td>${j.jam_mulai}–${j.jam_selesai}</td>
            <td><div class="mk" title="${j.mata_kuliah}">${j.mata_kuliah}</div><span class="tag">${j.kelas}</span>${statusTagHtml}</td>
            <td>${j.dosen}</td>
            <td>${ruangHtml}</td>
          </tr>`;
        }).join('') +
        `</tbody>
      </table>`;

    if (jPagination) {
      if (totalPages > 1) {
        jPagination.style.display = 'flex';
        if (jPages) {
          jPages.innerHTML = Array.from({ length: totalPages }, (_, i) => `
            <span class="j-dot ${i === curJadwalPage ? 'on' : ''}" data-page="${i}" title="Halaman ${i + 1}"></span>
          `).join('');
          jPages.querySelectorAll('.j-dot').forEach(el => {
            el.onclick = (e) => {
              const targetPage = Number(e.currentTarget.dataset.page);
              if (typeof Slider !== 'undefined' && Slider.goToJadwalPage) {
                Slider.goToJadwalPage(targetPage);
              } else {
                setJadwalPage(targetPage);
              }
            };
          });
        }
        if (jPageInfo) {
          const startNum = startIndex + 1;
          const endNum = Math.min(startIndex + JADWAL_PER_PAGE, list.length);
          jPageInfo.innerHTML = `Menampilkan <b>${startNum}–${endNum}</b> dari <b>${list.length}</b> jadwal`;
        }
      } else {
        jPagination.style.display = 'flex';
        if (jPages) jPages.innerHTML = '';
        if (jPageInfo) jPageInfo.innerHTML = `Menampilkan seluruh <b>${list.length}</b> jadwal hari ini`;
      }
    }

    const schTable = jBody.querySelector('.sch');
    if (schTable && anim) {
      schTable.classList.remove('j-swap');
      void schTable.offsetWidth;
      schTable.classList.add('j-swap');
    }

    markJadwal();
  }

  function markJadwal() {
    const m = Api.getServerMinutes();
    document.querySelectorAll('.sch tbody tr').forEach(tr => {
      const s = toMin(tr.dataset.s);
      const e = toMin(tr.dataset.e);
      tr.classList.toggle('now', m >= s && m < e);
      tr.classList.toggle('done', m >= e);
    });
  }

  // 4. Data Mahasiswa & Count-up Animation
  function renderMahasiswa(m) {
    const mBody = document.getElementById('mBody');
    if (!mBody || !m || !m.angkatan || !m.angkatan.length) return;

    const tAll = document.getElementById('tAll');
    const tProdi = document.getElementById('tProdi');
    const tAng = document.getElementById('tAng');

    if (tAll) tAll.dataset.to = m.total || 0;
    if (tProdi) tProdi.dataset.to = m.prodi ? m.prodi.length : 0;
    if (tAng) tAng.dataset.to = m.angkatan ? m.angkatan.length : 0;

    const colTotals = m.angkatan.map((_, i) => (m.prodi || []).reduce((acc, p) => acc + (p.jumlah[i] || 0), 0));

    mBody.innerHTML = `
      <table class="mhs">
        <thead>
          <tr><th>Program Studi</th>${m.angkatan.map(a => `<th>${a}</th>`).join('')}<th>Total</th></tr>
        </thead>
        <tbody>` +
          (m.prodi || []).map(p => {
            const rowTotal = (p.jumlah || []).reduce((a, b) => a + b, 0);
            return `<tr class="spot">
              <td>${p.nama}</td>
              ${(p.jumlah || []).map(v => `<td data-count data-to="${v}">0</td>`).join('')}
              <td data-count data-to="${rowTotal}">0</td>
            </tr>`;
          }).join('') +
          `<tr class="sum spot">
            <td>Total</td>
            ${colTotals.map(v => `<td data-count data-to="${v}">0</td>`).join('')}
            <td data-count data-to="${m.total}">0</td>
          </tr>
        </tbody>
      </table>`;
  }

  function animateCountUp(root) {
    if (!root) return;
    root.querySelectorAll('[data-count]').forEach(el => {
      const to = +el.dataset.to || 0;
      const t0 = performance.now();
      const D = 1400;
      (function f(t) {
        const k = Math.min(1, (t - t0) / D);
        const e = 1 - Math.pow(1 - k, 3);
        el.textContent = Math.round(to * e).toLocaleString('id-ID');
        if (k < 1) requestAnimationFrame(f);
      })(t0);
    });
  }

  // 5. Agenda Kampus
  function renderAgenda(agenda) {
    const aBody = document.getElementById('aBody');
    if (!aBody) return;

    if (!agenda || !agenda.length) {
      aBody.innerHTML = '<div style="padding:40px;text-align:center;color:var(--muted);font-size:22px">Belum ada agenda mendatang</div>';
      return;
    }

    aBody.innerHTML = agenda.map((a, i) => {
      const dt = new Date(a.mulai);
      const day = dt.getDate();
      const month = dt.toLocaleDateString('id-ID', { month: 'short' });
      const weekday = dt.toLocaleDateString('id-ID', { weekday: 'long' });
      const time = pad(dt.getHours()) + ':' + pad(dt.getMinutes()) + ' WIB';
      return `
        <div class="agi spot" style="--i:${i}">
          <div class="dt"><b>${day}</b><span>${month}</span></div>
          <div><h3>${a.judul}</h3><p><i class="fa-solid fa-location-dot"></i> ${a.lokasi}</p></div>
          <div class="when">${weekday}<small>${time}</small></div>
        </div>`;
    }).join('');
  }

  // 6. Dosen Hari Ini & Pagination
  const DOSEN_PER_PAGE = 5;
  let curDosenPage = 0;
  let cachedDosen = [];

  function getDosenTotalPages(dosen) {
    const list = dosen || cachedDosen || [];
    if (!list.length) return 1;
    return Math.max(1, Math.ceil(list.length / DOSEN_PER_PAGE));
  }

  function getCurDosenPage() {
    return curDosenPage;
  }

  function setDosenPage(page) {
    renderDosen(cachedDosen, page, true);
  }

  function nextDosenPage(dosen) {
    if (dosen !== undefined) {
      cachedDosen = Array.isArray(dosen) ? dosen : [];
    }
    const list = cachedDosen;
    const totalPages = Math.ceil(list.length / DOSEN_PER_PAGE);
    if (totalPages > 1) {
      curDosenPage = (curDosenPage + 1) % totalPages;
      renderDosen(list, curDosenPage, true);
    }
  }

  function renderDosen(dosen, page, anim = true) {
    const dl = document.getElementById('dl');
    const dcnt = document.getElementById('dcnt');
    const dPagination = document.getElementById('dPagination');
    const dPages = document.getElementById('dPages');
    const dPageInfo = document.getElementById('dPageInfo');
    if (!dl) return;

    if (dosen !== undefined) {
      cachedDosen = Array.isArray(dosen) ? dosen : [];
    }
    const list = cachedDosen;

    if (!list.length) {
      dl.innerHTML = '<div style="padding:30px;text-align:center;color:var(--muted);font-size:18px">Tidak ada dosen bertugas hari ini</div>';
      if (dcnt) dcnt.textContent = '0 Dosen';
      if (dPagination) dPagination.style.display = 'none';
      return;
    }

    const totalPages = Math.max(1, Math.ceil(list.length / DOSEN_PER_PAGE));
    if (page !== undefined) {
      curDosenPage = page;
    }
    curDosenPage = Math.min(Math.max(0, curDosenPage), totalPages - 1);

    if (dcnt) {
      dcnt.textContent = totalPages > 1
        ? `${list.length} Dosen · Hal ${curDosenPage + 1}/${totalPages}`
        : `${list.length} Dosen`;
    }

    const startIndex = curDosenPage * DOSEN_PER_PAGE;
    const items = list.slice(startIndex, startIndex + DOSEN_PER_PAGE);

    dl.innerHTML = items.map((d, i) => `
      <div class="dc" id="dc${startIndex + i}" style="--i:${i}" 
           data-s="${d.jam_mulai}" 
           data-e="${d.jam_selesai}" 
           data-status="${d.status_kehadiran || 'menunggu'}" 
           data-mode="${d.mode_kuliah || ''}" 
           data-label="${d.status_label || ''}" 
           data-jam-ganti="${d.jam_ganti || ''}" 
           data-catatan="${d.catatan || ''}">
        <div class="photo" style="background:${grad((d.id || i) + 2)}">
          ${d.foto_url ? `<img src="${d.foto_url}" alt="${d.nama}" onerror="this.onerror=null;this.parentElement.innerHTML='${ini(d.nama)}';">` : ini(d.nama)}
        </div>
        <div class="dt2">
          <div class="dn" title="${d.nama}">${d.nama}</div>
          <div class="dm" title="${d.mata_kuliah}"><i class="fa-solid fa-book-open"></i> ${d.mata_kuliah}</div>
          <div class="dr"><i class="fa-solid fa-door-open"></i> ${d.ruang} · ${d.jam_mulai}–${d.jam_selesai}</div>
          <span class="bd"></span>
        </div>
      </div>`).join('');

    if (dPagination) {
      if (totalPages > 1) {
        dPagination.style.display = 'flex';
        if (dPages) {
          dPages.innerHTML = Array.from({ length: totalPages }, (_, i) => `
            <span class="d-dot ${i === curDosenPage ? 'on' : ''}" data-page="${i}" title="Halaman ${i + 1}"></span>
          `).join('');
          dPages.querySelectorAll('.d-dot').forEach(el => {
            el.onclick = (e) => {
              const targetPage = Number(e.currentTarget.dataset.page);
              setDosenPage(targetPage);
            };
          });
        }
        if (dPageInfo) {
          const startNum = startIndex + 1;
          const endNum = Math.min(startIndex + DOSEN_PER_PAGE, list.length);
          dPageInfo.innerHTML = `Menampilkan <b>${startNum}–${endNum}</b> dari <b>${list.length}</b> dosen`;
        }
      } else {
        dPagination.style.display = 'flex';
        if (dPages) dPages.innerHTML = '';
        if (dPageInfo) dPageInfo.innerHTML = `Menampilkan seluruh <b>${list.length}</b> dosen hari ini`;
      }
    }

    if (anim) {
      dl.classList.remove('d-swap');
      void dl.offsetWidth;
      dl.classList.add('d-swap');
    }

    markDosen();
  }

  function markDosen() {
    const m = Api.getServerMinutes();
    document.querySelectorAll('#dl .dc').forEach(c => {
      const s = toMin(c.dataset.s);
      const e = toMin(c.dataset.e);
      const bd = c.querySelector('.bd');
      const status = c.dataset.status || 'menunggu';
      const mode = c.dataset.mode || '';
      const jamGanti = c.dataset.jamGanti || '';
      const catatan = c.dataset.catatan || '';
      const now = m >= s && m < e;
      const done = m >= e;

      c.classList.toggle('now', now && status !== 'tidak_hadir');
      c.classList.toggle('done', done && status !== 'tidak_hadir');
      c.classList.toggle('is-online', status === 'hadir_online' || mode === 'online');
      c.classList.toggle('is-offline', status === 'hadir_offline' || mode === 'offline');
      c.classList.toggle('is-tukar', status === 'tukar_jam');
      c.classList.toggle('is-tidak', status === 'tidak_hadir');

      if (bd) {
        if (status === 'hadir_online' || mode === 'online') {
          bd.className = 'bd bd-online';
          bd.innerHTML = now
            ? '<i class="fa-solid fa-globe"></i> Hadir Online · Sedang Mengajar'
            : done
            ? '<i class="fa-solid fa-globe"></i> Hadir Online · Selesai'
            : '<i class="fa-solid fa-globe"></i> Hadir Online';
        } else if (status === 'hadir_offline' || mode === 'offline') {
          bd.className = 'bd bd-offline';
          bd.innerHTML = now
            ? '<i class="fa-solid fa-chalkboard-user"></i> Hadir Tatap Muka · Sedang Mengajar'
            : done
            ? '<i class="fa-solid fa-chalkboard-user"></i> Hadir Tatap Muka · Selesai'
            : '<i class="fa-solid fa-chalkboard-user"></i> Hadir Tatap Muka';
        } else if (status === 'tukar_jam') {
          bd.className = 'bd bd-tukar';
          bd.innerHTML = '<i class="fa-solid fa-clock-rotate-left"></i> Tukar Jam' + (jamGanti ? ' (' + jamGanti + ')' : '');
        } else if (status === 'tidak_hadir') {
          bd.className = 'bd bd-tidak';
          bd.innerHTML = '<i class="fa-solid fa-user-xmark"></i> Tidak Hadir' + (catatan ? ' (' + catatan + ')' : ' (Izin)');
        } else {
          bd.className = 'bd';
          bd.innerHTML = now ? '● Sedang mengajar' : done ? 'Selesai' : 'Mulai ' + c.dataset.s;
        }
      }
    });
  }

  // 7. Running Text Pengumuman
  function renderTicker(pengumuman) {
    const tk = document.getElementById('tk');
    if (!tk) return;
    if (!pengumuman || !pengumuman.length) {
      tk.innerHTML = '<span>Selamat Datang di STAI Sidogiri Pasuruan</span>';
      tk.style.animationDuration = '40s';
      return;
    }
    tk.innerHTML = pengumuman.map(t => `<span>${t}</span>`).join('');
    tk.style.animationDuration = Math.max(40, pengumuman.join('').length / 4) + 's';
  }

  return {
    renderBranding: renderBranding,
    updateClockAndHeader: updateClockAndHeader,
    renderWeather: renderWeather,
    renderPengurusList: renderPengurusList,
    renderPengurusCards: renderPengurusCards,
    nextPengurusPage: nextPengurusPage,
    nextPengurusListPage: nextPengurusListPage,
    renderJadwal: renderJadwal,
    markJadwal: markJadwal,
    getJadwalTotalPages: getJadwalTotalPages,
    getCurJadwalPage: getCurJadwalPage,
    setJadwalPage: setJadwalPage,
    JADWAL_PER_PAGE: JADWAL_PER_PAGE,
    renderMahasiswa: renderMahasiswa,
    animateCountUp: animateCountUp,
    renderAgenda: renderAgenda,
    renderDosen: renderDosen,
    markDosen: markDosen,
    getDosenTotalPages: getDosenTotalPages,
    getCurDosenPage: getCurDosenPage,
    setDosenPage: setDosenPage,
    nextDosenPage: nextDosenPage,
    DOSEN_PER_PAGE: DOSEN_PER_PAGE,
    renderTicker: renderTicker
  };
})();

