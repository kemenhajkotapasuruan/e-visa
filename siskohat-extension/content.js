/**
 * content.js - Otomatisasi Upload File Biovisa (Nama_Nomor paspor.jpg <= 50KB)
 * langsung ke komponen "Scan/Upload Biovisa (jpeg) *" pada SISKOHAT Gen.2 (WndMenu.zul)
 */

(function () {
  // Jangan jalankan di frame kecil/kosong kecuali memiliki teks SISKOHAT atau Scan/Upload Biovisa
  if (window.innerWidth < 300 && window.innerHeight < 200) return;

  let biovisaQueue = [];
  let autoDetectEnabled = true;
  let autoClickSimpan = false;
  let lastAutoUploadedPassport = '';
  let isUploadingNow = false;
  let wasEntryPasporMenuOpen = false;

  /**
   * Periksa apakah menu "ENTRY DATA PASPOR DAN DOKUMEN PEMVISAAN" sedang dibuka/aktif di layar utama.
   * Mengabaikan teks pada daftar menu tree di sidebar kiri (rect.left < 195).
   */
  function isEntryPasporBiovisaMenuOpen() {
    const candidates = document.querySelectorAll('span, label, div, td, th, caption');
    for (let i = 0; i < candidates.length; i++) {
      const el = candidates[i];
      if (el.children.length > 2) continue;
      const txt = (el.textContent || '').trim();
      if (!txt) continue;

      const isBiovisaField = /Scan\s*\/\s*Upload\s*Biovisa/i.test(txt);
      const isEntryPasporSection = /Entry\s+Data\s+Paspor\s+Jemaah\s+Haji|Scan\s+Paspor\s+dengan\s+MRTD/i.test(txt);
      const isHeaderTitle = /^ENTRY\s+DATA\s+PASPOR\s+DAN\s+DOKUMEN\s+PEMVISAAN$/i.test(txt);

      if (isBiovisaField || isEntryPasporSection || isHeaderTitle) {
        const rect = el.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0 && rect.left >= 195) {
          return true;
        }
      }
    }
    return false;
  }

  /**
   * Tampilkan panel HANYA saat menu "ENTRY DATA PASPOR DAN DOKUMEN PEMVISAAN" sedang aktif.
   * Sembunyikan otomatis saat berada di halaman awal, menu lain, atau setelah klik tombol Tutup.
   */
  function updatePanelVisibilityByMenu() {
    const isOpen = isEntryPasporBiovisaMenuOpen();
    let panel = document.getElementById('silahut-siskohat-panel');

    if (isOpen) {
      if (!panel) {
        initPanel();
        panel = document.getElementById('silahut-siskohat-panel');
      }
      if (panel && panel.style.display === 'none') {
        panel.style.display = 'flex';
      }
      if (!wasEntryPasporMenuOpen) {
        wasEntryPasporMenuOpen = true;
        loadQueueFromLaragon();
      }
    } else {
      if (panel && panel.style.display !== 'none') {
        panel.style.display = 'none';
      }
      if (wasEntryPasporMenuOpen) {
        wasEntryPasporMenuOpen = false;
        lastAutoUploadedPassport = '';
      }
    }
  }

  function initPanel() {
    if (document.getElementById('silahut-siskohat-panel')) return;

    const panel = document.createElement('div');
    panel.id = 'silahut-siskohat-panel';
    panel.innerHTML = `
      <div class="silahut-header" id="silahut-header-bar" title="Tahan & geser untuk memindahkan posisi panel">
        <div class="silahut-header-title">
          <span class="silahut-drag-grip">⠿</span>
          <span>✦ AUTO-BIOVISA SISKOHAT</span>
        </div>
        <button type="button" class="silahut-header-btn" id="silahut-min-btn">Minimize</button>
      </div>
      <div class="silahut-body">
        <div class="silahut-status-bar" id="silahut-status-bar">
          Menghubungkan ke server E-Visa Laragon...
        </div>
        <div class="silahut-controls">
          <label class="silahut-toggle-row" title="Otomatis mendeteksi Nomor Paspor / Nama Jamaah yang sedang dibuka di Siskohat lalu langsung memasukkan file JPG Biovisa">
            <span>Auto-Isi saat Paspor Terdeteksi</span>
            <input type="checkbox" id="silahut-chk-autodetect" checked>
          </label>
          <label class="silahut-toggle-row" title="Otomatis menekan tombol Simpan di Siskohat 1.5 detik setelah file Biovisa berhasil masuk">
            <span>Auto-Klik Tombol "Simpan"</span>
            <input type="checkbox" id="silahut-chk-autosave">
          </label>
          <div class="silahut-search-row">
            <input type="text" class="silahut-input" id="silahut-search" placeholder="Cari Nama / No. Paspor...">
            <button type="button" class="silahut-btn silahut-btn-dark" id="silahut-cfg-btn" title="Atur Alamat IP Server E-Visa (Jika diakses dari Komputer Lain)">⚙</button>
            <button type="button" class="silahut-btn silahut-btn-dark" id="silahut-refresh-btn" title="Muat Ulang Data dari Laragon">↻</button>
          </div>
          <div id="silahut-server-box" style="display:none; padding-top:4px; border-top:1px dashed #CBD5E1;">
            <div style="font-size:10.5px; font-weight:700; color:#475569; margin-bottom:3px;">Sumber Data Biovisa:</div>
            <select class="silahut-input" id="silahut-source-mode" style="width:100%; margin-bottom:5px;">
              <option value="auto_github">☁️ Otomatis (Cloud GitHub + Laragon)</option>
              <option value="github_only">🌐 Cloud GitHub (kemenhajkotapasuruan.github.io)</option>
              <option value="local_only">💻 Laragon Lokal / LAN</option>
            </select>
            <div class="silahut-search-row" style="margin-bottom:4px;">
              <input type="text" class="silahut-input" id="silahut-api-url" placeholder="https://kemenhajkotapasuruan.github.io/e-visa">
              <button type="button" class="silahut-btn silahut-btn-gold" id="silahut-save-url-btn">Simpan</button>
            </div>
          </div>
        </div>
        <div class="silahut-list" id="silahut-list">
          <div style="text-align:center; padding:18px; color:#64748B;">Memuat daftar Biovisa...</div>
        </div>
        <div class="silahut-toast" id="silahut-toast">
          Tips: Geser header panel ini untuk memindahkan posisi.
        </div>
      </div>
    `;

    document.body.appendChild(panel);

    const minBtn = document.getElementById('silahut-min-btn');

    // Pulihkan posisi & status minimize terakhir dari localStorage
    try {
      const savedPos = JSON.parse(localStorage.getItem('silahut_panel_pos') || 'null');
      if (savedPos && typeof savedPos.left === 'number' && typeof savedPos.top === 'number') {
        const maxLeft = Math.max(10, window.innerWidth - 290);
        const maxTop  = Math.max(6, window.innerHeight - 50);
        panel.style.right = 'auto';
        panel.style.left  = Math.min(Math.max(6, savedPos.left), maxLeft) + 'px';
        panel.style.top   = Math.min(Math.max(6, savedPos.top), maxTop) + 'px';
      }
      if (savedPos && savedPos.minimized) {
        panel.classList.add('minimized');
        minBtn.textContent = 'Buka';
      }
    } catch (e) {}

    function savePanelState() {
      try {
        const rect = panel.getBoundingClientRect();
        localStorage.setItem('silahut_panel_pos', JSON.stringify({
          left: Math.round(rect.left),
          top: Math.round(rect.top),
          minimized: panel.classList.contains('minimized')
        }));
      } catch (e) {}
    }

    minBtn.addEventListener('click', e => {
      e.stopPropagation();
      panel.classList.toggle('minimized');
      minBtn.textContent = panel.classList.contains('minimized') ? 'Buka' : 'Minimize';
      clampPanelToViewport();
      savePanelState();
    });

    // Fitur Drag & Drop Dinamis (Memindahkan Posisi Panel Mengambang)
    const headerBar = document.getElementById('silahut-header-bar');
    let isDragging = false;
    let dragStartX = 0;
    let dragStartY = 0;
    let initialLeft = 0;
    let initialTop = 0;

    function clampPanelToViewport() {
      const rect = panel.getBoundingClientRect();
      const maxLeft = Math.max(6, window.innerWidth - rect.width - 6);
      const maxTop  = Math.max(6, window.innerHeight - 44);
      if (panel.style.left) {
        const curL = parseFloat(panel.style.left) || rect.left;
        const curT = parseFloat(panel.style.top) || rect.top;
        panel.style.left = Math.min(Math.max(6, curL), maxLeft) + 'px';
        panel.style.top  = Math.min(Math.max(6, curT), maxTop) + 'px';
      }
    }

    headerBar.addEventListener('mousedown', e => {
      if (e.target === minBtn || minBtn.contains(e.target)) return;
      isDragging = true;
      const rect = panel.getBoundingClientRect();
      dragStartX = e.clientX;
      dragStartY = e.clientY;
      initialLeft = rect.left;
      initialTop = rect.top;

      panel.style.right = 'auto';
      panel.style.left = initialLeft + 'px';
      panel.style.top = initialTop + 'px';
      panel.classList.add('dragging');
      e.preventDefault();
    });

    window.addEventListener('mousemove', e => {
      if (!isDragging) return;
      const dx = e.clientX - dragStartX;
      const dy = e.clientY - dragStartY;
      const maxLeft = Math.max(6, window.innerWidth - panel.offsetWidth - 6);
      const maxTop  = Math.max(6, window.innerHeight - 44);

      const newLeft = Math.min(Math.max(6, initialLeft + dx), maxLeft);
      const newTop  = Math.min(Math.max(6, initialTop + dy), maxTop);

      panel.style.left = newLeft + 'px';
      panel.style.top  = newTop + 'px';
    });

    window.addEventListener('mouseup', () => {
      if (!isDragging) return;
      isDragging = false;
      panel.classList.remove('dragging');
      savePanelState();
    });

    window.addEventListener('resize', clampPanelToViewport);

    document.getElementById('silahut-chk-autodetect').addEventListener('change', e => {
      autoDetectEnabled = e.target.checked;
    });

    document.getElementById('silahut-chk-autosave').addEventListener('change', e => {
      autoClickSimpan = e.target.checked;
    });

    document.getElementById('silahut-search').addEventListener('input', () => {
      renderQueueList();
    });

    document.getElementById('silahut-refresh-btn').addEventListener('click', () => {
      loadQueueFromLaragon();
    });

    // Pengaturan URL Server E-Visa (untuk akses dari komputer lain di jaringan LAN)
    const cfgBtn = document.getElementById('silahut-cfg-btn');
    const srvBox = document.getElementById('silahut-server-box');
    const modeSel = document.getElementById('silahut-source-mode');
    const urlInp = document.getElementById('silahut-api-url');
    const saveUrlBtn = document.getElementById('silahut-save-url-btn');

    chrome.storage.local.get(['evisa_source_mode', 'evisa_api_url'], res => {
      if (modeSel) modeSel.value = res.evisa_source_mode || 'auto_github';
      urlInp.value = res.evisa_api_url || 'http://localhost/e-visa/api.php';
    });

    cfgBtn.addEventListener('click', () => {
      srvBox.style.display = srvBox.style.display === 'none' ? 'block' : 'none';
    });

    saveUrlBtn.addEventListener('click', () => {
      let val = (urlInp.value || '').trim();
      if (!val) val = 'http://localhost/e-visa/api.php';
      if (!val.includes('github.io') && !val.endsWith('api.php')) {
        val = val.replace(/\/+$/, '') + '/api.php';
      }
      urlInp.value = val;
      const chosenMode = modeSel ? modeSel.value : 'auto_github';
      chrome.storage.local.set({ evisa_source_mode: chosenMode, evisa_api_url: val }, () => {
        srvBox.style.display = 'none';
        setToast('✅ Sumber data disimpan (' + chosenMode + ')');
        loadQueueFromLaragon();
      });
    });

    loadQueueFromLaragon();
  }

  function setToast(text, isError = false) {
    const el = document.getElementById('silahut-toast');
    if (!el) return;
    el.textContent = text;
    el.style.borderTopColor = isError ? '#DC2626' : '#D4AF37';
    el.style.color = isError ? '#FCA5A5' : '#FFFFFF';
  }

  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function loadQueueFromLaragon() {
    const statusBar = document.getElementById('silahut-status-bar');
    if (statusBar) statusBar.textContent = 'Mengambil daftar Biovisa dari Laragon...';

    chrome.runtime.sendMessage({ type: 'FETCH_QUEUE' }, res => {
      if (chrome.runtime.lastError || !res || !res.ok || !res.data || !res.data.ok) {
        const err = (res && res.error) || 'Pastikan Laragon aktif (http://localhost/e-visa)';
        if (statusBar) statusBar.innerHTML = `<span style="color:#B91C1C; font-weight:700;">❌ ${err}</span>`;
        return;
      }

      biovisaQueue = res.data.records || [];
      const doneCount = biovisaQueue.filter(r => r.siskohat_uploaded).length;
      const srcLabel = res.data.source_label || (res.activeSource === 'github' ? 'GitHub Cloud' : 'Laragon');
      if (statusBar) {
        statusBar.innerHTML = `Sumber: <strong>${srcLabel}</strong> &bull; <strong>${biovisaQueue.length} JPG</strong> (${doneCount} Selesai)`;
      }
      renderQueueList();
    });
  }

  function renderQueueList() {
    const listEl = document.getElementById('silahut-list');
    if (!listEl) return;

    const q = (document.getElementById('silahut-search').value || '').trim().toLowerCase();
    const filtered = biovisaQueue.filter(r => {
      if (!q) return true;
      return (
        (r.nama || '').toLowerCase().includes(q) ||
        (r.paspor || '').toLowerCase().includes(q) ||
        (r.filename || '').toLowerCase().includes(q)
      );
    });

    if (filtered.length === 0) {
      listEl.innerHTML = `<div style="text-align:center; padding:18px; color:#64748B;">Tidak ada data yang cocok.</div>`;
      return;
    }

    listEl.innerHTML = filtered.map(r => {
      const isDone = !!r.siskohat_uploaded;
      const docsObj = (r.seven_docs && r.seven_docs.docs) ? r.seven_docs.docs : null;
      const availCount = (r.seven_docs && typeof r.seven_docs.available_count === 'number')
        ? r.seven_docs.available_count
        : 1;

      const miniBadges = SISKOHAT_SEVEN_SLOTS.map(s => {
        const hasFile = docsObj
          ? !!(docsObj[s.key] && docsObj[s.key].exists)
          : (s.key === 'biovisa');
        const bg = hasFile ? '#111827' : '#F1F5F9';
        const fg = hasFile ? '#D4AF37' : '#94A3B8';
        const bd = hasFile ? '#D4AF37' : '#CBD5E1';
        const icon = hasFile ? '✓' : '⊘';
        const tip = hasFile
          ? `${s.label}: Tersedia (Akan di-upload otomatis)`
          : `${s.label}: Tidak ada file (Diblokir & lanjut ke file berikutnya)`;
        return `<span title="${escapeHtml(tip)}" style="display:inline-block; font-size:9px; font-weight:800; padding:1px 4px; border-radius:3px; background:${bg}; color:${fg}; border:1px solid ${bd};">${icon}${s.num}</span>`;
      }).join(' ');

      return `
        <div class="silahut-item ${isDone ? 'done-siskohat' : ''}">
          <div class="silahut-item-info">
            <div class="silahut-item-name" title="${escapeHtml(r.filename)}">
              ${isDone ? '✅ ' : ''}${escapeHtml(r.nama)}
            </div>
            <div class="silahut-item-sub">
              Paspor: <strong>${escapeHtml(r.paspor)}</strong> &bull; <strong>${availCount}/7 File</strong>
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:2px; margin-top:3px;">
              ${miniBadges}
            </div>
          </div>
          <button type="button" class="silahut-btn silahut-btn-gold" data-upload-id="${escapeHtml(r.id)}">
            ${isDone ? 'Upload Ulang' : 'Masukkan'}
          </button>
        </div>
      `;
    }).join('');

    listEl.querySelectorAll('[data-upload-id]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-upload-id');
        uploadRecordToSiskohatById(id);
      });
    });
  }

  /**
   * DEFINISI 7 KOLOM FILE UPLOAD PADA MENU "ENTRY DATA PASPOR DAN DOKUMEN PEMVISAAN":
   * 1. Scan Halaman Pertama Paspor
   * 2. Scan/Upload Halaman Endorsement
   * 3. Scan/Upload Keterangan Vaksin Meningitis *
   * 4. Scan/Upload Keterangan KITAS
   * 5. Scan/Upload Dokumen Pendukung
   * 6. Scan/Upload Biovisa (jpeg)
   * 7. Scan/Upload Foto Terbaru
   *
   * ATURAN BLOKIR & LANJUT OTOMATIS:
   * - Setiap slot (1..7) diisolasi secara ketat dari 6 slot lainnya.
   * - Jika file untuk salah satu slot TIDAK ADA, maka slot tersebut DIBLOKIR MUTLAK
   *   (tidak disentuh sama sekali) dan sistem langsung melanjutkan ke file upload berikutnya.
   */
  const SISKOHAT_SEVEN_SLOTS = [
    {
      num: 1,
      key: 'paspor',
      short: '1.Paspor',
      label: '1. Scan Halaman Pertama Paspor',
      regex: /Scan\s+Halaman\s+Pertama\s+Paspor/i
    },
    {
      num: 2,
      key: 'endorsement',
      short: '2.Endorse',
      label: '2. Scan/Upload Halaman Endorsement',
      regex: /Scan\s*\/\s*Upload\s+Halaman\s+Endorsement|Halaman\s+Endorsement/i
    },
    {
      num: 3,
      key: 'vaksin',
      short: '3.Vaksin',
      label: '3. Scan/Upload Keterangan Vaksin Meningitis *',
      regex: /Scan\s*\/\s*Upload\s+Keterangan\s+Vaksin\s+Meningitis|Keterangan\s+Vaksin\s+Meningitis/i
    },
    {
      num: 4,
      key: 'kitas',
      short: '4.KITAS',
      label: '4. Scan/Upload Keterangan KITAS',
      regex: /Scan\s*\/\s*Upload\s+Keterangan\s+KITAS|Keterangan\s+KITAS/i
    },
    {
      num: 5,
      key: 'pendukung',
      short: '5.Pendukung',
      label: '5. Scan/Upload Dokumen Pendukung',
      regex: /Scan\s*\/\s*Upload\s+Dokumen\s+Pendukung|Dokumen\s+Pendukung/i
    },
    {
      num: 6,
      key: 'biovisa',
      short: '6.Biovisa',
      label: '6. Scan/Upload Biovisa (jpeg)',
      regex: /Scan\s*\/\s*Upload\s*Biovisa/i
    },
    {
      num: 7,
      key: 'foto',
      short: '7.Foto',
      label: '7. Scan/Upload Foto Terbaru',
      regex: /Scan\s*\/\s*Upload\s+Foto\s+Terbaru|Foto\s+Terbaru/i
    }
  ];

  const MRTD_FORBIDDEN_REGEX = /Scan\s+Paspor\s+dengan\s+MRTD/i;

  /**
   * Cek apakah teks sebuah elemen DOM mengandung salah satu dari 6 slot LAIN selain targetKey
   */
  function containsOtherSlotText(txt, targetKey) {
    if (!txt) return false;
    if (MRTD_FORBIDDEN_REGEX.test(txt)) return true;
    for (const s of SISKOHAT_SEVEN_SLOTS) {
      if (s.key !== targetKey && s.regex.test(txt)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Klasifikasikan sebuah <input type="file"> milik slot mana (1..7) dengan menelusuri parentElement ke atas.
   * Hanya mengembalikan ownerKey = slot.key jika container terdekat HANYA cocok dengan 1 slot tersebut
   * dan sama sekali TIDAK mengandung 6 slot lainnya.
   */
  function classifySlotFileInputOwner(inputEl) {
    let p = inputEl.parentElement;
    for (let depth = 1; depth <= 18 && p && p !== document.body; depth++) {
      const txt = p.textContent || '';
      if (MRTD_FORBIDDEN_REGEX.test(txt)) {
        return { ownerKey: 'FORBIDDEN_MRTD', depth };
      }

      const matchedSlots = SISKOHAT_SEVEN_SLOTS.filter(s => s.regex.test(txt));
      if (matchedSlots.length === 1) {
        return {
          ownerKey: matchedSlots[0].key,
          depth,
          containerTag: p.tagName,
          containerId: p.id || ''
        };
      }
      if (matchedSlots.length > 1) {
        // Sudah mencapai container induk gabungan beberapa slot
        break;
      }
      p = p.parentElement;
    }
    return { ownerKey: 'UNKNOWN', depth: 99 };
  }

  /**
   * Temukan container DOM terisolasi yang HANYA membungkus slot target (misal slot #1..#7)
   * dan SAMA SEKALI TIDAK mengandung 6 slot lainnya.
   */
  function findIsolatedSlotContainer(slot) {
    const allEls = Array.from(document.querySelectorAll('span, label, td, div, th'));
    const labelEl = allEls.find(el => {
      if (el.children.length > 1) return false;
      const txt = (el.textContent || '').trim();
      if (!slot.regex.test(txt)) return false;
      const rect = el.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0 && rect.left >= 195;
    });

    if (!labelEl) {
      return { labelEl: null, slotContainer: null, pilihBtn: null };
    }

    let bestContainer = labelEl.parentElement;
    let cur = labelEl.parentElement;

    for (let depth = 1; depth <= 15 && cur && cur !== document.body; depth++) {
      const txt = cur.textContent || '';
      if (containsOtherSlotText(txt, slot.key)) {
        // Berhenti! Jangan naik lagi karena level ini sudah bercampur dengan slot lain
        break;
      }
      bestContainer = cur;
      cur = cur.parentElement;
    }

    let pilihBtn = null;
    if (bestContainer) {
      const innerEls = Array.from(bestContainer.querySelectorAll('td, span, button, a, div'));
      pilihBtn = innerEls.find(
        el => el.children.length === 0 && /Pilih\s*file/i.test((el.textContent || '').trim())
      );
      if (!pilihBtn) {
        pilihBtn = innerEls.find(
          el => el.children.length <= 2 && /Pilih\s*file/i.test((el.textContent || '').trim())
        );
      }
    }

    return { labelEl, slotContainer: bestContainer, pilihBtn };
  }

  /**
   * Cari elemen <input type="file"> KHUSUS untuk 1 slot tertentu (dan blokir mutlak 6 slot lainnya)
   */
  async function findSiskohatFileInputForSlot(slot) {
    let { labelEl, slotContainer, pilihBtn } = findIsolatedSlotContainer(slot);

    if (!labelEl || !slotContainer) {
      return null;
    }

    // Scroll ke bagian slot tersebut
    labelEl.scrollIntoView({ behavior: 'instant', block: 'center' });
    await new Promise(r => setTimeout(r, 110));

    // Trigger mouseover/mouseenter pada tombol "Pilih file" DI DALAM slotContainer agar ZK membuat input[type=file]
    if (pilihBtn) {
      let target = pilihBtn;
      for (let i = 0; i < 6 && target && target !== document.body; i++) {
        if (containsOtherSlotText(target.textContent || '', slot.key)) break;
        const rect = target.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;
        ['mouseover', 'mouseenter', 'mousemove'].forEach(evName => {
          target.dispatchEvent(new MouseEvent(evName, {
            bubbles: true,
            cancelable: true,
            view: window,
            clientX: cx,
            clientY: cy
          }));
        });
        target = target.parentElement;
      }
      await new Promise(r => setTimeout(r, 200));
    }

    // Segarkan referensi container setelah event mouseover ZK
    ({ labelEl, slotContainer, pilihBtn } = findIsolatedSlotContainer(slot));

    // 1. Cari <input type="file"> di dalam slotContainer yang ownerKey === slot.key
    if (slotContainer) {
      const inputsInSlot = Array.from(slotContainer.querySelectorAll('input[type="file"]')).filter(
        inp => classifySlotFileInputOwner(inp).ownerKey === slot.key
      );
      if (inputsInSlot.length >= 1) {
        return {
          fileInput: inputsInSlot[inputsInSlot.length - 1],
          source: `isolated_${slot.key}`
        };
      }
    }

    // 2. Periksa seluruh <input type="file"> di halaman, HANYA terima yang ownerKey === slot.key
    const allFileInputs = Array.from(document.querySelectorAll('input[type="file"]'));
    const strictlyMatched = allFileInputs.filter(inp => classifySlotFileInputOwner(inp).ownerKey === slot.key);
    if (strictlyMatched.length >= 1) {
      return {
        fileInput: strictlyMatched[strictlyMatched.length - 1],
        source: `strict_${slot.key}`
      };
    }

    return null;
  }

  function base64ToJpegFile(base64Str, filename) {
    const byteCharacters = atob(base64Str);
    const byteNumbers = new Array(byteCharacters.length);
    for (let i = 0; i < byteCharacters.length; i++) {
      byteNumbers[i] = byteCharacters.charCodeAt(i);
    }
    const byteArray = new Uint8Array(byteNumbers);
    const blob = new Blob([byteArray], { type: 'image/jpeg' });
    return new File([blob], filename, {
      type: 'image/jpeg',
      lastModified: Date.now()
    });
  }

  /**
   * Proses Otomatisasi 7 File Upload secara Berurutan (1..7):
   * - Jika file untuk slot ke-k TIDAK ADA -> Slot ke-k DIBLOKIR MUTLAK & langsung lanjut ke slot ke-(k+1)
   * - Jika file untuk slot ke-k ADA -> Suntikkan khusus ke <input type="file"> slot ke-k, lalu lanjut ke slot ke-(k+1)
   */
  async function injectFileIntoSiskohat(recordData) {
    if (isUploadingNow) return;
    isUploadingNow = true;

    try {
      // Bentuk peta 7 dokumen dari respons API
      const docsMap = (recordData && recordData.docs) ? recordData.docs : {
        biovisa: {
          exists: !!(recordData && recordData.base64),
          filename: (recordData && recordData.filename) || 'BIOVISA.jpg',
          size_kb: (recordData && recordData.size_kb) || 48,
          base64: (recordData && recordData.base64) || ''
        }
      };

      const uploadedSlots = [];
      const blockedEmptySlots = [];
      const notReadyDomSlots = [];

      for (const slot of SISKOHAT_SEVEN_SLOTS) {
        const docItem = docsMap[slot.key];

        // 1. CEK KETERSEDIAAN FILE: Jika file tidak ada -> BLOKIR slot ini & LANJUT ke file upload berikutnya!
        if (!docItem || !docItem.exists || !docItem.base64) {
          blockedEmptySlots.push(slot.short);
          continue;
        }

        setToast(`⏳ [${slot.num}/7] Memasukkan ${docItem.filename} (${docItem.size_kb} KB) ke ${slot.label}...`);

        // 2. Cari <input type="file"> yang terisolasi khusus untuk slot ini (6 slot lainnya diblokir)
        const target = await findSiskohatFileInputForSlot(slot);
        if (!target || !target.fileInput) {
          notReadyDomSlots.push(slot.short);
          continue;
        }

        // 3. Suntikkan file ke <input type="file"> milik slot ini
        const fileObj = base64ToJpegFile(docItem.base64, docItem.filename || `${recordData.nama}_${recordData.paspor}.jpg`);
        const dt = new DataTransfer();
        dt.items.add(fileObj);

        const inputEl = target.fileInput;
        inputEl.files = dt.files;
        inputEl.dispatchEvent(new Event('input', { bubbles: true }));
        inputEl.dispatchEvent(new Event('change', { bubbles: true }));

        uploadedSlots.push(`${slot.short} (${docItem.size_kb}KB)`);

        // Jeda singkat agar ZK Framework Siskohat memproses upload file ini sebelum pindah ke slot berikutnya
        await new Promise(r => setTimeout(r, 420));
      }

      if (uploadedSlots.length === 0) {
        if (notReadyDomSlots.length > 0) {
          setToast(
            `⚠️ Kolom upload [${notReadyDomSlots.join(', ')}] belum aktif di layar Siskohat. Arahkan mouse ke tombol "Pilih file" lalu klik Masukkan lagi.`,
            true
          );
        } else {
          setToast('⚠️ Tidak ada file dokumen yang tersedia untuk jamaah ini.', true);
        }
        isUploadingNow = false;
        return;
      }

      const blockedInfo = blockedEmptySlots.length > 0
        ? ` | ⊘ Diblokir & Dilewati (File Tidak Ada): ${blockedEmptySlots.join(', ')}`
        : '';

      setToast(`✅ Berhasil Auto-Upload ${uploadedSlots.length} File [${uploadedSlots.join(', ')}]${blockedInfo}`);

      // Tandai sudah diupload ke Siskohat
      chrome.runtime.sendMessage(
        { type: 'MARK_SISKOHAT_UPLOADED', id: recordData.id, status: true },
        () => loadQueueFromLaragon()
      );

      // Jika opsi Auto-Klik Simpan diaktifkan, klik tombol "Simpan" di Siskohat setelah seluruh dokumen selesai masuk
      if (autoClickSimpan) {
        setTimeout(() => {
          const allBtns = Array.from(document.querySelectorAll('button, input[type="button"], a.z-button'));
          const simpanBtn = allBtns.find(b => /^\s*Simpan\s*$/i.test(b.textContent || b.value || ''));
          if (simpanBtn) {
            simpanBtn.click();
            setToast(`💾 ${uploadedSlots.length} Dokumen masuk & tombol "Simpan" telah diklik otomatis!`);
          }
        }, 1800);
      }
    } catch (err) {
      setToast('❌ Gagal memasukkan file ke Siskohat: ' + err.message, true);
    } finally {
      isUploadingNow = false;
    }
  }

  function uploadRecordToSiskohatById(id) {
    setToast('⏳ Mengambil file JPG <= 50KB dari Laragon...');
    chrome.runtime.sendMessage({ type: 'FETCH_BIOVISA_FILE', id }, res => {
      if (!res || !res.ok || !res.data || !res.data.ok) {
        setToast('❌ Gagal mengambil file dari Laragon: ' + ((res && res.data && res.data.error) || 'Error'), true);
        return;
      }
      injectFileIntoSiskohat(res.data.record);
    });
  }

  /**
   * Deteksi Otomatis: Pantau jika di halaman Siskohat muncul Nomor Paspor yang cocok dengan antrean Biovisa
   */
  function scanSiskohatPageForActivePassport() {
    if (!autoDetectEnabled || isUploadingNow || biovisaQueue.length === 0) return;

    // Pastikan sedang berada di form yang memiliki "Scan/Upload Biovisa"
    const hasBiovisaSection = /Scan\s*\/\s*Upload\s*Biovisa/i.test(document.body.innerText || '');
    if (!hasBiovisaSection) return;

    // Kumpulkan nilai dari semua input textbox dan label di form Siskohat
    const inputs = Array.from(document.querySelectorAll('input[type="text"], span.z-label, td'));
    const pageTokens = new Set();

    for (const el of inputs) {
      const val = (el.value || el.textContent || '').trim().toUpperCase();
      if (/^[A-Z]{1,3}\d{6,8}$/.test(val)) {
        pageTokens.add(val);
      }
    }

    if (pageTokens.size === 0) return;

    for (const rec of biovisaQueue) {
      const paspor = (rec.paspor || '').toUpperCase();
      if (paspor && pageTokens.has(paspor)) {
        if (lastAutoUploadedPassport !== paspor) {
          lastAutoUploadedPassport = paspor;
          setToast(`🔍 Nomor Paspor ${paspor} (${rec.nama}) terdeteksi di form Siskohat! Mengisi Biovisa otomatis...`);
          uploadRecordToSiskohatById(rec.id);
        }
        break;
      }
    }
  }

  // Pantau perubahan menu di Siskohat Gen.2 (ZK AJAX DOM changes & klik menu)
  updatePanelVisibilityByMenu();

  const menuObserver = new MutationObserver(() => {
    updatePanelVisibilityByMenu();
  });
  if (document.body) {
    menuObserver.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
  }

  document.addEventListener('click', () => {
    setTimeout(updatePanelVisibilityByMenu, 150);
    setTimeout(updatePanelVisibilityByMenu, 500);
    setTimeout(updatePanelVisibilityByMenu, 1100);
  }, true);

  setInterval(() => {
    updatePanelVisibilityByMenu();
    if (wasEntryPasporMenuOpen) {
      scanSiskohatPageForActivePassport();
    }
  }, 1500);
})();
