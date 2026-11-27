/**
 * background.js - Service Worker Jembatan antara Siskohat Gen.2 (HTTPS 10.100.88.48)
 * dan Sumber Data Biovisa:
 * 1. Cloud GitHub Pages: https://kemenhajkotapasuruan.github.io/e-visa/ (Berfungsi di semua PC tanpa Laragon & tanpa masalah IP Dinamis!)
 * 2. Server Lokal Laragon: http://localhost/e-visa/api.php (atau IP LAN / Hostname)
 */

const GITHUB_PAGES_BASE = 'https://kemenhajkotapasuruan.github.io/e-visa';
const DEFAULT_LOCAL_API = 'http://localhost/e-visa/api.php';
const DEFAULT_API_TOKEN = '';
const DEFAULT_LEMBAGA_SLUG = '';

async function getStoredConfig() {
  return new Promise(resolve => {
    chrome.storage.local.get(
      ['evisa_source_mode', 'evisa_api_url', 'evisa_api_token', 'evisa_lembaga_slug', 'gh_cloud_records', 'gh_cloud_images', 'gh_siskohat_done_ids'],
      res => {
        resolve({
          sourceMode: res.evisa_source_mode || 'auto_github', // 'auto_github' | 'github_only' | 'local_only'
          apiUrl: res.evisa_api_url || DEFAULT_LOCAL_API,
          apiToken: res.evisa_api_token || DEFAULT_API_TOKEN,
          lembagaSlug: res.evisa_lembaga_slug || DEFAULT_LEMBAGA_SLUG,
          cloudRecords: Array.isArray(res.gh_cloud_records) ? res.gh_cloud_records : [],
          cloudImages: res.gh_cloud_images || {},
          doneIds: res.gh_siskohat_done_ids || {}
        });
      }
    );
  });
}

function arrayBufferToBase64(buffer) {
  let binary = '';
  const bytes = new Uint8Array(buffer);
  const len = bytes.byteLength;
  for (let i = 0; i < len; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary);
}

/**
 * Ambil daftar antrean Biovisa dari GitHub Pages (https://kemenhajkotapasuruan.github.io/e-visa/)
 * digabung dengan hasil sinkronisasi baru di browser (gh_cloud_records)
 */
async function fetchQueueFromGitHubPages() {
  const { cloudRecords, doneIds } = await getStoredConfig();
  let staticRecords = [];

  try {
    const res = await fetch(`${GITHUB_PAGES_BASE}/storage/data/records.json?_t=${Date.now()}`);
    if (res.ok) {
      const json = await res.json();
      if (Array.isArray(json)) {
        staticRecords = json;
      }
    }
  } catch (e) {}

  // Gabungkan cloudRecords (yang baru ditarik dari Gmail di tab github.io) dengan staticRecords dari repo GitHub
  const byPaspor = new Map();
  for (const r of [...staticRecords, ...cloudRecords]) {
    const key = (r.paspor || r.id || r.filename || '').toUpperCase();
    if (!key || key === 'TANPAPASPOR') continue;
    const isDone = !!(r.siskohat_uploaded || doneIds[r.id] || doneIds[r.paspor]);
    if (!byPaspor.has(key)) {
      byPaspor.set(key, {
        id: r.id || ('bv_' + r.paspor),
        nama: r.nama || '',
        paspor: r.paspor || '',
        enrollment_id: r.enrollment_id || '',
        filename: r.filename || `${r.nama}_${r.paspor}.jpg`,
        size_kb: r.size_kb || 49.2,
        siskohat_uploaded: isDone,
        seven_docs: r.seven_docs ? JSON.parse(JSON.stringify(r.seven_docs)) : null
      });
    } else {
      const existing = byPaspor.get(key);
      if (isDone) existing.siskohat_uploaded = true;
      if (r.seven_docs && r.seven_docs.docs) {
        if (!existing.seven_docs || !existing.seven_docs.docs) {
          existing.seven_docs = JSON.parse(JSON.stringify(r.seven_docs));
        } else {
          Object.keys(r.seven_docs.docs).forEach(ck => {
            if (r.seven_docs.docs[ck] && r.seven_docs.docs[ck].exists) {
              existing.seven_docs.docs[ck] = {
                ...(existing.seven_docs.docs[ck] || {}),
                ...r.seven_docs.docs[ck],
                exists: true
              };
            }
          });
          existing.seven_docs.available_count = Object.values(existing.seven_docs.docs).filter(d => d && d.exists).length;
        }
      }
    }
  }

  const list = Array.from(byPaspor.values());
  return {
    ok: true,
    source_label: 'GitHub Cloud (kemenhajkotapasuruan.github.io)',
    count: list.length,
    records: list
  };
}

/**
 * Ambil file JPG <= 50KB (Base64) langsung dari GitHub Pages (https://kemenhajkotapasuruan.github.io/e-visa/)
 */
async function fetchBiovisaFileFromGitHubPages(id, paspor, nama) {
  const { cloudImages } = await getStoredConfig();
  const queueRes = await fetchQueueFromGitHubPages();
  const records = queueRes.records || [];

  const matched = records.find(
    r =>
      (id && r.id === id) ||
      (paspor && (r.paspor || '').toUpperCase() === paspor.toUpperCase()) ||
      (nama && (r.nama || '').toUpperCase() === nama.toUpperCase())
  );

  if (!matched) {
    throw new Error('Data jamaah tidak ditemukan di GitHub Pages.');
  }

  const filename = matched.filename;
  const sevenDocsMeta = (matched.seven_docs && matched.seven_docs.docs) ? matched.seven_docs.docs : {};
  const slotKeys = ['paspor', 'endorsement', 'vaksin', 'kitas', 'pendukung', 'biovisa', 'foto'];
  const docsPayload = {};
  let availableCount = 0;

  for (const catKey of slotKeys) {
    const meta = sevenDocsMeta[catKey];
    const shouldTryFetch = (catKey === 'biovisa') || (meta && meta.exists);
    if (!shouldTryFetch) {
      docsPayload[catKey] = {
        exists: false,
        blocked: true,
        key: catKey,
        filename: '',
        size_kb: 0,
        base64: ''
      };
      continue;
    }

    const targetFn = (meta && meta.filename) ? meta.filename : filename;
    const cloudKey = catKey === 'biovisa' ? targetFn : (`${catKey}:${targetFn}`);

    if (cloudImages[cloudKey] || (catKey === 'biovisa' && cloudImages[targetFn])) {
      let b64 = cloudImages[cloudKey] || cloudImages[targetFn];
      if (b64.includes(',')) b64 = b64.split(',')[1];
      const approxKb = Number(((b64.length * 0.75) / 1024).toFixed(2));
      availableCount++;
      docsPayload[catKey] = {
        exists: true,
        blocked: false,
        key: catKey,
        filename: targetFn,
        size_kb: approxKb,
        base64: b64
      };
      continue;
    }

    const folderPath = catKey === 'biovisa' ? 'storage/biovisa' : `storage/dokumen/${catKey}`;
    const fileUrl = `${GITHUB_PAGES_BASE}/${folderPath}/${encodeURIComponent(targetFn)}?_t=${Date.now()}`;
    try {
      const imgRes = await fetch(fileUrl);
      if (imgRes.ok) {
        const buf = await imgRes.arrayBuffer();
        const b64 = arrayBufferToBase64(buf);
        const sizeKb = Number((buf.byteLength / 1024).toFixed(2));
        availableCount++;
        docsPayload[catKey] = {
          exists: true,
          blocked: false,
          key: catKey,
          filename: targetFn,
          size_kb: sizeKb,
          base64: b64
        };
      } else {
        docsPayload[catKey] = { exists: false, blocked: true, key: catKey, filename: '', size_kb: 0, base64: '' };
      }
    } catch (e) {
      docsPayload[catKey] = { exists: false, blocked: true, key: catKey, filename: '', size_kb: 0, base64: '' };
    }
  }

  const bvDoc = docsPayload.biovisa || {};
  return {
    ok: true,
    record: {
      ...matched,
      size_kb: bvDoc.size_kb || matched.size_kb,
      base64: bvDoc.base64 || '',
      available_count: availableCount,
      docs: docsPayload
    }
  };
}

/**
 * Coba ambil dari Laragon Lokal jika tersedia
 */
async function tryFetchLocalApi(urlWithParams, options = {}, timeoutMs = 1800) {
  const cfg = await getStoredConfig();
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    let finalUrl = urlWithParams;
    if (cfg.lembagaSlug && !finalUrl.includes('lembaga=')) {
      finalUrl += (finalUrl.includes('?') ? '&' : '?') + 'lembaga=' + encodeURIComponent(cfg.lembagaSlug);
    }
    const headers = Object.assign({}, options.headers || {});
    if (cfg.apiToken) {
      headers['X-Evisa-Token'] = cfg.apiToken;
    }
    const res = await fetch(finalUrl, {
      ...options,
      headers,
      credentials: 'include',
      signal: controller.signal
    });
    clearTimeout(timer);
    if (!res.ok) return null;
    return await res.json();
  } catch (e) {
    clearTimeout(timer);
    return null;
  }
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  (async () => {
    try {
      const cfg = await getStoredConfig();

      if (msg.type === 'FETCH_QUEUE') {
        // Jika mode local_only atau auto, cek Laragon lokal dulu (dengan timeout cepat 1.5 detik)
        if (cfg.sourceMode !== 'github_only') {
          const localData = await tryFetchLocalApi(`${cfg.apiUrl}?action=siskohat_queue&_t=${Date.now()}`);
          if (localData && localData.ok) {
            localData.source_label = 'Laragon Lokal';
            sendResponse({ ok: true, data: localData, activeSource: 'local' });
            return;
          }
        }

        // Gunakan GitHub Pages Cloud (https://kemenhajkotapasuruan.github.io/e-visa/)
        const ghData = await fetchQueueFromGitHubPages();
        sendResponse({ ok: true, data: ghData, activeSource: 'github' });
        return;
      }

      if (msg.type === 'FETCH_BIOVISA_FILE') {
        if (cfg.sourceMode !== 'github_only') {
          const params = new URLSearchParams({
            action: 'siskohat_get_all_docs',
            id: msg.id || '',
            paspor: msg.paspor || '',
            nama: msg.nama || ''
          });
          const localFile = await tryFetchLocalApi(`${cfg.apiUrl}?${params.toString()}`, {}, 2500);
          if (localFile && localFile.ok && localFile.record) {
            sendResponse({ ok: true, data: localFile, activeSource: 'local' });
            return;
          }
        }

        // Ambil langsung dari https://kemenhajkotapasuruan.github.io/e-visa/
        const ghFile = await fetchBiovisaFileFromGitHubPages(msg.id, msg.paspor, msg.nama);
        sendResponse({ ok: true, data: ghFile, activeSource: 'github' });
        return;
      }

      if (msg.type === 'MARK_SISKOHAT_UPLOADED') {
        const doneIds = cfg.doneIds || {};
        if (msg.id) {
          if (msg.status !== false) doneIds[msg.id] = true;
          else delete doneIds[msg.id];
        }
        chrome.storage.local.set({ gh_siskohat_done_ids: doneIds });

        // Update juga ke Laragon lokal bila aktif
        await tryFetchLocalApi(
          `${cfg.apiUrl}?action=mark_siskohat`,
          {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: msg.id, status: msg.status !== false })
          },
          1500
        );

        sendResponse({ ok: true, data: { ok: true } });
        return;
      }

      if (msg.type === 'LOG_DOM_DEBUG') {
        await tryFetchLocalApi(
          `${cfg.apiUrl}?action=siskohat_dom_debug`,
          {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(msg.debug || {})
          },
          1200
        );
        sendResponse({ ok: true });
        return;
      }

      sendResponse({ ok: false, error: 'Unknown message type' });
    } catch (err) {
      sendResponse({
        ok: false,
        error: 'Gagal mengambil data Biovisa: ' + err.message
      });
    }
  })();

  return true;
});
