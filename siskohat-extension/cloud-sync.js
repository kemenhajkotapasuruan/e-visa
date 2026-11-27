/**
 * cloud-sync.js - Berjalan otomatis di halaman https://kemenhajkotapasuruan.github.io/e-visa/
 * Menyinkronkan data Biovisa yang diproses di halaman GitHub Pages langsung ke Ekstensi Chrome Siskohat
 */
(function () {
  function syncPageToExtension() {
    try {
      const rawRecs = localStorage.getItem('gh_evisa_records');
      const rawImgs = localStorage.getItem('gh_evisa_images');
      const payload = {};
      if (rawRecs) payload.gh_cloud_records = JSON.parse(rawRecs);
      if (rawImgs) payload.gh_cloud_images = JSON.parse(rawImgs);

      if (Object.keys(payload).length > 0) {
        chrome.storage.local.set(payload);
      }

      // Sinkronkan juga status "Sudah di Siskohat" dari ekstensi kembali ke halaman GitHub Pages
      chrome.storage.local.get(['gh_siskohat_done_ids'], res => {
        const doneIds = res.gh_siskohat_done_ids || {};
        if (rawRecs && Object.keys(doneIds).length > 0) {
          const recs = JSON.parse(rawRecs);
          let changed = false;
          recs.forEach(r => {
            if (doneIds[r.id] || doneIds[r.paspor]) {
              if (!r.siskohat_uploaded) {
                r.siskohat_uploaded = true;
                changed = true;
              }
            }
          });
          if (changed) {
            localStorage.setItem('gh_evisa_records', JSON.stringify(recs));
          }
        }
      });
    } catch (e) {}
  }

  // Tandai bahwa jembatan ekstensi aktif di halaman GitHub Pages
  try {
    const s = document.createElement('script');
    s.textContent = 'window.__silahutExtBridgeActive = true;';
    (document.head || document.documentElement).appendChild(s);
    s.remove();
  } catch (e) {}

  // Jembatan komunikasi Hardware Scanner WIA/TWAIN (GitHub Pages HTTPS <-> Ekstensi <-> Laragon Localhost)
  window.addEventListener('message', ev => {
    if (!ev || !ev.data || ev.data.type !== 'SILAHUT_EXT_SCANNER_REQ') return;
    const { reqId, scannerAction, payload } = ev.data;
    chrome.runtime.sendMessage(
      {
        type: 'LOCAL_SCANNER_BRIDGE',
        scannerAction: scannerAction || 'list_scanners',
        payload: payload || {}
      },
      res => {
        window.postMessage(
          {
            type: 'SILAHUT_EXT_SCANNER_RES',
            reqId: reqId,
            data: (res && res.data) ? res.data : null
          },
          '*'
        );
      }
    );
  });

  // Terima kiriman gambar dokumen baru langsung dari halaman GitHub Pages (kebal batas 5MB localStorage)
  window.addEventListener('message', ev => {
    if (!ev || !ev.data || ev.data.type !== 'SILAHUT_CLOUD_IMAGE_UPDATE') return;
    const { key, dataUrl, remove } = ev.data;
    if (!key) return;
    chrome.storage.local.get(['gh_cloud_images'], res => {
      const imgs = res.gh_cloud_images || {};
      if (remove) {
        delete imgs[key];
      } else if (dataUrl) {
        imgs[key] = dataUrl;
      }
      chrome.storage.local.set({ gh_cloud_images: imgs });
    });
  });

  syncPageToExtension();
  setInterval(syncPageToExtension, 2500);
})();
