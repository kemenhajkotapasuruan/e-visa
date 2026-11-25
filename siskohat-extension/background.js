/**
 * background.js - Service Worker Jembatan antara Siskohat Gen.2 (HTTPS 10.100.88.48)
 * dan Server Lokal E-Visa Laragon (Localhost / Hostname Windows / Auto-Discovery IP LAN Dinamis).
 * Jika IP Server berubah karena DHCP dinamis, ekstensi OTOMATIS mencari IP baru tanpa perlu pasang ulang!
 */

const DEFAULT_API_BASE = 'http://localhost/e-visa/api.php';
const FALLBACK_CANDIDATES = [
  'http://localhost/e-visa/api.php',
  'http://127.0.0.1/e-visa/api.php',
  'http://kemenhaj_kopas/e-visa/api.php',
  'http://kemenhaj_kopas.local/e-visa/api.php'
];

async function getStoredConfig() {
  return new Promise(resolve => {
    chrome.storage.local.get(['evisa_api_url', 'evisa_last_subnet'], res => {
      resolve({
        apiUrl: res.evisa_api_url || DEFAULT_API_BASE,
        lastSubnet: res.evisa_last_subnet || ''
      });
    });
  });
}

function extractSubnetPrefix(urlStr) {
  const m = String(urlStr || '').match(/https?:\/\/(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}/);
  return m ? m[1] : '';
}

async function saveWorkingApiUrl(apiUrl) {
  const subnet = extractSubnetPrefix(apiUrl);
  const toSave = { evisa_api_url: apiUrl };
  if (subnet) toSave.evisa_last_subnet = subnet;
  return new Promise(resolve => {
    chrome.storage.local.set(toSave, resolve);
  });
}

/**
 * Uji apakah sebuah URL api.php adalah server SILAHUT E-Visa yang aktif
 */
async function probeApiUrl(apiUrl, timeoutMs = 1400) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(`${apiUrl}?action=ping_server&_t=${Date.now()}`, {
      signal: controller.signal
    });
    clearTimeout(timer);
    if (!res.ok) return null;
    const data = await res.json();
    if (data && data.ok && data.service === 'SILAHUT_EVISA_PASURUAN') {
      return apiUrl;
    }
  } catch (e) {
    clearTimeout(timer);
  }
  return null;
}

/**
 * Auto-Discovery: Jika IP Server berubah karena DHCP, cari otomatis lewat:
 * 1. Hostname Windows (http://kemenhaj_kopas/e-visa/api.php)
 * 2. Pemindaian cepat subnet LAN (/24) tempat server terakhir berada
 */
async function autoDiscoverServerUrl() {
  const { apiUrl, lastSubnet } = await getStoredConfig();

  // 1. Coba URL yang tersimpan saat ini lebih dulu
  if (await probeApiUrl(apiUrl, 1600)) {
    return apiUrl;
  }

  // 2. Coba daftar kandidat Hostname Windows & Localhost
  const candidates = [...new Set([DEFAULT_API_BASE, ...FALLBACK_CANDIDATES])];
  for (const cand of candidates) {
    if (await probeApiUrl(cand, 1500)) {
      await saveWorkingApiUrl(cand);
      return cand;
    }
  }

  // 3. Jika gagal, pindai subnet LAN (/24) secara paralel dalam batch cepat
  const subnetsToScan = [];
  const curSubnet = extractSubnetPrefix(apiUrl) || extractSubnetPrefix(DEFAULT_API_BASE);
  if (curSubnet) subnetsToScan.push(curSubnet);
  if (lastSubnet && !subnetsToScan.includes(lastSubnet)) subnetsToScan.push(lastSubnet);
  ['192.168.1', '192.168.0', '192.168.100', '10.100.88'].forEach(s => {
    if (!subnetsToScan.includes(s)) subnetsToScan.push(s);
  });

  for (const subnet of subnetsToScan.slice(0, 3)) {
    const batchSize = 32;
    for (let start = 1; start <= 254; start += batchSize) {
      const promises = [];
      for (let i = start; i < start + batchSize && i <= 254; i++) {
        const testUrl = `http://${subnet}.${i}/e-visa/api.php`;
        promises.push(probeApiUrl(testUrl, 1100));
      }
      const results = await Promise.all(promises);
      const found = results.find(r => r !== null);
      if (found) {
        await saveWorkingApiUrl(found);
        return found;
      }
    }
  }

  return null;
}

/**
 * Fetch dengan Auto-Healing: jika koneksi ke IP lama gagal karena IP berubah,
 * otomatis jalankan autoDiscoverServerUrl() lalu ulangi request tanpa gagal!
 */
async function fetchWithAutoDiscovery(buildUrlFn, fetchOptions = {}) {
  const { apiUrl } = await getStoredConfig();
  try {
    const res = await fetch(buildUrlFn(apiUrl), fetchOptions);
    if (res.ok) {
      await saveWorkingApiUrl(apiUrl);
      return { res, activeUrl: apiUrl };
    }
  } catch (firstErr) {
    // IP mungkin berubah! Jalankan Auto-Discovery
    const newUrl = await autoDiscoverServerUrl();
    if (newUrl) {
      const res2 = await fetch(buildUrlFn(newUrl), fetchOptions);
      return { res: res2, activeUrl: newUrl };
    }
    throw firstErr;
  }
  throw new Error('Server E-Visa tidak merespons.');
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  (async () => {
    try {
      if (msg.type === 'AUTO_DISCOVER_SERVER') {
        const foundUrl = await autoDiscoverServerUrl();
        if (foundUrl) {
          sendResponse({ ok: true, apiUrl: foundUrl });
        } else {
          sendResponse({
            ok: false,
            error: 'Server E-Visa tidak ditemukan di jaringan LAN. Pastikan Laragon di komputer server utama sedang aktif.'
          });
        }
        return;
      }

      if (msg.type === 'FETCH_QUEUE') {
        const { res, activeUrl } = await fetchWithAutoDiscovery(
          base => `${base}?action=siskohat_queue&_t=${Date.now()}`
        );
        const data = await res.json();
        sendResponse({ ok: true, data, activeUrl });
        return;
      }

      if (msg.type === 'FETCH_BIOVISA_FILE') {
        const params = new URLSearchParams({
          action: 'siskohat_get_file',
          id: msg.id || '',
          paspor: msg.paspor || '',
          nama: msg.nama || ''
        });
        const { res, activeUrl } = await fetchWithAutoDiscovery(
          base => `${base}?${params.toString()}`
        );
        const data = await res.json();
        sendResponse({ ok: true, data, activeUrl });
        return;
      }

      if (msg.type === 'MARK_SISKOHAT_UPLOADED') {
        const { res } = await fetchWithAutoDiscovery(
          base => `${base}?action=mark_siskohat`,
          {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              id: msg.id,
              status: msg.status !== false
            })
          }
        );
        const data = await res.json();
        sendResponse({ ok: true, data });
        return;
      }

      if (msg.type === 'LOG_DOM_DEBUG') {
        const { apiUrl } = await getStoredConfig();
        await fetch(`${apiUrl}?action=siskohat_dom_debug`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(msg.debug || {})
        });
        sendResponse({ ok: true });
        return;
      }

      sendResponse({ ok: false, error: 'Unknown message type' });
    } catch (err) {
      sendResponse({
        ok: false,
        error: 'Gagal terhubung ke server E-Visa Laragon. Klik tombol ⚙ -> "Cari Server Otomatis" atau pastikan Laragon aktif.'
      });
    }
  })();

  return true;
});
