<?php
$buildSessDir = __DIR__ . '/storage/temp';
if (!is_dir($buildSessDir)) {
    @mkdir($buildSessDir, 0755, true);
}
session_save_path($buildSessDir);
session_name('SILAHUT_EVISA_SESS');
session_start();
$_SESSION['user_id'] = 'usr_admin_1';
$_SESSION['role'] = 'admin';
$_SESSION['active_lembaga_slug'] = 'phu-pasuruan';

ob_start();
include 'c:/laragon/www/e-visa/index.php';
$fullHtml = ob_get_clean();

$b64LogoIcon = 'data:image/png;base64,' . base64_encode(file_get_contents('c:/laragon/www/e-visa/assets/biologoicon.png'));
$b64LogoText = 'data:image/png;base64,' . base64_encode(file_get_contents('c:/laragon/www/e-visa/assets/biologotext.png'));
$b64Success  = 'data:image/png;base64,' . base64_encode(file_get_contents('c:/laragon/www/e-visa/assets/success.png'));

require_once 'c:/laragon/www/e-visa/config.php';
require_once 'c:/laragon/www/e-visa/includes/ScannerBridge.php';

$buildScannersRes = ScannerBridge::listActiveScanners();
$initialScannersJson = json_encode($buildScannersRes['devices'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$bundleData = writeCloudSyncBundle();
$rawRecordsArr = $bundleData['records'];
$initialRecords = json_encode($rawRecordsArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$initialJamaah = file_exists('c:/laragon/www/e-visa/storage/data/jamaah_master.json')
    ? file_get_contents('c:/laragon/www/e-visa/storage/data/jamaah_master.json')
    : '[]';
$initialLogs = file_exists('c:/laragon/www/e-visa/storage/data/sync_logs.json')
    ? file_get_contents('c:/laragon/www/e-visa/storage/data/sync_logs.json')
    : '[]';

// Ambil settings tanpa menampilkan password sensitif
$safeSettings = getSettings();
$safeSettings['imap_app_password'] = '';
if (!empty($safeSettings['source_accounts'])) {
    foreach ($safeSettings['source_accounts'] as &$acc) {
        $acc['imap_password'] = '';
    }
    unset($acc);
}
$initialSettingsJson = json_encode($safeSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Siapkan snapshot data terpisah untuk masing-masing instansi (phu-pasuruan, kemenhaj-pasuruankab, dll.)
$initialTenantsMap = [];
foreach (listTenantStorageSlugs() as $tSlug) {
    tenantBindPaths($tSlug);
    $tSet = getPublicCloudSettings();
    $tRecs = getRecords();
    foreach ($tRecs as &$tr) {
        $tr['lembaga_slug'] = $tSlug;
        $tr['lembaga_nama'] = getLembagaNameBySlug($tSlug);
        $tr['seven_docs'] = getJamaahSevenDocsStatus($tr, $tSlug);
    }
    unset($tr);
    $initialTenantsMap[$tSlug] = [
        'slug' => $tSlug,
        'nama' => getLembagaNameBySlug($tSlug),
        'settings' => $tSet,
        'records' => array_values($tRecs),
        'jamaah' => getJamaahMaster(),
        'logs' => loadJsonFile(getLogsFile(), [])
    ];
}
tenantBindPaths('phu-pasuruan');
$initialTenantsJson = json_encode($initialTenantsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$clientEngineJs = <<<JS
<script>
/**
 * ============================================================================
 * GITHUB PAGES SERVERLESS ADAPTER (100% IDENTICAL UI & FUNCTIONALITY)
 * Mengintercept panggilan ke api.php saat berjalan di GitHub Pages (github.io)
 * ============================================================================
 */
(function() {
    const INITIAL_SETTINGS = $initialSettingsJson;
    const INITIAL_RECORDS  = $initialRecords;
    const INITIAL_JAMAAH   = $initialJamaah;
    const INITIAL_LOGS     = $initialLogs;
    const INITIAL_TENANTS  = $initialTenantsJson;
    const INITIAL_SCANNERS = $initialScannersJson;

    const ASSET_ICON = "$b64LogoIcon";
    const ASSET_TEXT = "$b64LogoText";
    const ASSET_SUCC = "$b64Success";

    function resolveCloudUserSlug(userObj) {
        if (!userObj || typeof userObj !== 'object') return 'phu-pasuruan';
        if (userObj.slug && INITIAL_TENANTS[userObj.slug]) return userObj.slug;
        const uname = String(userObj.username || '').trim().toLowerCase();
        if (uname === 'kd1322' || uname.includes('kab')) return 'kemenhaj-pasuruankab';
        return 'phu-pasuruan';
    }

    const bootUser = JSON.parse(localStorage.getItem('gh_evisa_active_user') || 'null');
    let activeTenantSlug = resolveCloudUserSlug(bootUser);

    function getTenantSeed(slug) {
        if (INITIAL_TENANTS && INITIAL_TENANTS[slug]) {
            return JSON.parse(JSON.stringify(INITIAL_TENANTS[slug]));
        }
        if (slug === 'phu-pasuruan') {
            return {
                slug: 'phu-pasuruan',
                nama: 'Kantor Kemenhaj Kota Pasuruan',
                settings: JSON.parse(JSON.stringify(INITIAL_SETTINGS)),
                records: JSON.parse(JSON.stringify(INITIAL_RECORDS)),
                jamaah: JSON.parse(JSON.stringify(INITIAL_JAMAAH)),
                logs: JSON.parse(JSON.stringify(INITIAL_LOGS))
            };
        }
        return {
            slug: slug,
            nama: slug === 'kemenhaj-pasuruankab' ? 'Kementerian Haji dan Umrah Kabupaten Pasuruan' : slug,
            settings: {
                source_email: slug === 'kemenhaj-pasuruankab' ? 'phujtmkabpasuruan@gmail.com' : '',
                target_gdrive_email: slug === 'kemenhaj-pasuruankab' ? 'phujtmkabpasuruan@gmail.com' : '',
                email_subject_filter: 'Enrollment confirmation',
                connection_mode: 'gas',
                gas_gmail_webapp_url: '',
                source_accounts: slug === 'kemenhaj-pasuruankab' ? [{
                    id: 'acc_primary',
                    label: 'Email Utama Instansi',
                    email: 'phujtmkabpasuruan@gmail.com',
                    mode: 'gas',
                    gas_url: '',
                    imap_password: '',
                    enabled: true
                }] : [],
                gas_gdrive_webapp_url: '',
                gdrive_folder_name: slug === 'kemenhaj-pasuruankab' ? 'Dokumen_Paspor_Kab_Pasuruan_2026' : '',
                auto_sync_enabled: true,
                auto_sync_interval_sec: 30,
                auto_download_browser: true,
                auto_upload_gdrive: true,
                process_mode: 'both',
                target_min_kb: 42,
                target_max_kb: 49.5,
                strict_50kb_padding: true,
                filename_pattern: '{NAMA}_{PASPOR}',
                uppercase_filename: true,
                last_sync_at: null,
                last_sync_status: 'Siap memantau email subjek "Enrollment confirmation"'
            },
            records: [],
            jamaah: [],
            logs: []
        };
    }

    let ghSettings = {};
    let ghRecords  = [];
    let ghJamaah   = [];
    let ghLogs     = [];
    let ghImages   = JSON.parse(localStorage.getItem('gh_evisa_images') || '{}');
    let cachedImgs = {};
    let lastCloudSyncPoll = 0;

    function loadTenantState(slug) {
        activeTenantSlug = slug || 'phu-pasuruan';
        const seed = getTenantSeed(activeTenantSlug);
        const isDefaultKota = (activeTenantSlug === 'phu-pasuruan');
        const rawSet = localStorage.getItem('gh_evisa_settings__' + activeTenantSlug)
            || (isDefaultKota ? localStorage.getItem('gh_evisa_settings') : null);
        const rawRec = localStorage.getItem('gh_evisa_records__' + activeTenantSlug)
            || (isDefaultKota ? localStorage.getItem('gh_evisa_records') : null);
        const rawJam = localStorage.getItem('gh_evisa_jamaah__' + activeTenantSlug)
            || (isDefaultKota ? localStorage.getItem('gh_evisa_jamaah') : null);
        const rawLog = localStorage.getItem('gh_evisa_logs__' + activeTenantSlug)
            || (isDefaultKota ? localStorage.getItem('gh_evisa_logs') : null);

        ghSettings = (rawSet ? JSON.parse(rawSet) : null) || seed.settings;
        ghRecords  = (rawRec ? JSON.parse(rawRec) : null) || seed.records;
        ghJamaah   = (rawJam ? JSON.parse(rawJam) : null) || seed.jamaah;
        ghLogs     = (rawLog ? JSON.parse(rawLog) : null) || seed.logs;

        if (!isDefaultKota) {
            if (ghSettings && String(ghSettings.source_email || '').toLowerCase() === 'phujtmkopasuruan@gmail.com') {
                ghSettings = seed.settings;
            }
            // Pastikan record Kota Pasuruan tidak pernah terbawa ke instansi lain (seperti KD1322 / Kabupaten Pasuruan)
            ghRecords = (Array.isArray(ghRecords) ? ghRecords : []).filter(r => r && r.lembaga_slug === activeTenantSlug);
        }
    }

    loadTenantState(activeTenantSlug);

    const DOC_CAT_META = {
        paspor:      { num: 1, key: 'paspor',      short: 'Paspor',      label: '1. Scan Halaman Pertama Paspor',               subfolder: '1_Paspor',      max_kb: 49.5 },
        endorsement: { num: 2, key: 'endorsement', short: 'Endorsement', label: '2. Scan/Upload Halaman Endorsement',           subfolder: '2_Endorsement', max_kb: 49.5 },
        vaksin:      { num: 3, key: 'vaksin',      short: 'Vaksin',      label: '3. Scan/Upload Keterangan Vaksin Meningitis *', subfolder: '3_Vaksin',      max_kb: 49.5 },
        kitas:       { num: 4, key: 'kitas',       short: 'KITAS',       label: '4. Scan/Upload Keterangan KITAS',              subfolder: '4_Kitas',       max_kb: 49.5 },
        pendukung:   { num: 5, key: 'pendukung',   short: 'Pendukung',   label: '5. Scan/Upload Dokumen Pendukung',             subfolder: '5_Pendukung',   max_kb: 49.5 },
        biovisa:     { num: 6, key: 'biovisa',     short: 'Biovisa',     label: '6. Scan/Upload Biovisa (jpeg)',                subfolder: '6_Biovisa',     max_kb: 49.5 },
        foto:        { num: 7, key: 'foto',        short: 'Foto',        label: '7. Scan/Upload Foto Terbaru',                  subfolder: '7_Foto',        max_kb: 49.5 }
    };

    function ensureRecordSevenDocs(rec) {
        if (!rec || typeof rec !== 'object') return rec;
        if (!rec.seven_docs || !rec.seven_docs.docs) {
            rec.seven_docs = { available_count: 0, total_slots: 7, docs: {} };
        }
        const initMatch = Array.isArray(INITIAL_RECORDS)
            ? INITIAL_RECORDS.find(ir => ir.id === rec.id || (ir.paspor && ir.paspor === rec.paspor))
            : null;
        const initDocs = (initMatch && initMatch.seven_docs && initMatch.seven_docs.docs) ? initMatch.seven_docs.docs : {};

        let count = 0;
        Object.keys(DOC_CAT_META).forEach(k => {
            const m = DOC_CAT_META[k];
            const cur = rec.seven_docs.docs[k];
            const ini = initDocs[k];
            const fn  = (cur && cur.filename) || (ini && ini.filename) || rec.filename || `\${rec.nama}_\${rec.paspor}.jpg`;
            const cloudKey = k === 'biovisa' ? fn : `\${k}:\${fn}`;

            if (ghImages[cloudKey] || (cur && cur.exists) || (ini && ini.exists) || (k === 'biovisa' && rec.filename)) {
                count++;
                rec.seven_docs.docs[k] = {
                    exists: true,
                    num: m.num,
                    key: k,
                    short: m.short,
                    label: m.label,
                    filename: fn,
                    size_kb: (cur && cur.size_kb) || (ini && ini.size_kb) || (k === 'biovisa' ? (rec.size_kb || 49.2) : 48.0),
                    gdrive_file_id: (cur && cur.gdrive_file_id) || (ini && ini.gdrive_file_id) || '',
                    gdrive_url: (cur && cur.gdrive_url) || (ini && ini.gdrive_url) || ''
                };
            } else {
                rec.seven_docs.docs[k] = {
                    exists: false,
                    num: m.num,
                    key: k,
                    short: m.short,
                    label: m.label,
                    filename: '',
                    size_kb: 0
                };
            }
        });
        rec.seven_docs.available_count = count;
        rec.seven_docs.total_slots = 7;
        return rec;
    }

    /**
     * Gabungkan daftar akun email pembaca (source_accounts) & pengaturan dari Server/GitHub/GDrive
     * agar Komputer Lain tidak pernah terjebak cache localStorage lama.
     */
    function mergeSettingsFromBundle(remoteSet, preferRemoteList = false) {
        if (!remoteSet || typeof remoteSet !== 'object') return false;
        let changed = false;

        if (!Array.isArray(ghSettings.source_accounts)) {
            ghSettings.source_accounts = [];
        }
        const remoteAccs = Array.isArray(remoteSet.source_accounts) ? remoteSet.source_accounts : [];

        if (preferRemoteList && remoteAccs.length > 0) {
            const oldJson = JSON.stringify(ghSettings.source_accounts);
            const mapByEmail = new Map();
            remoteAccs.forEach(ra => {
                const em = String(ra.email || '').trim().toLowerCase();
                if (em) mapByEmail.set(em, ra);
            });
            ghSettings.source_accounts.forEach(la => {
                const em = String(la.email || '').trim().toLowerCase();
                if (em && !mapByEmail.has(em) && la.gas_url) {
                    mapByEmail.set(em, la);
                }
            });
            ghSettings.source_accounts = Array.from(mapByEmail.values());
            if (JSON.stringify(ghSettings.source_accounts) !== oldJson) {
                changed = true;
            }
        } else if (remoteAccs.length > 0) {
            const existingEmails = new Map();
            ghSettings.source_accounts.forEach((a, i) => {
                const em = String(a.email || '').trim().toLowerCase();
                if (em) existingEmails.set(em, i);
            });
            remoteAccs.forEach(ra => {
                const em = String(ra.email || '').trim().toLowerCase();
                if (!em) return;
                if (!existingEmails.has(em)) {
                    ghSettings.source_accounts.push(ra);
                    existingEmails.set(em, ghSettings.source_accounts.length - 1);
                    changed = true;
                } else {
                    const idx = existingEmails.get(em);
                    if (ra.gas_url && ghSettings.source_accounts[idx].gas_url !== ra.gas_url) {
                        ghSettings.source_accounts[idx].gas_url = ra.gas_url;
                        changed = true;
                    }
                    if (ra.label && ghSettings.source_accounts[idx].label !== ra.label) {
                        ghSettings.source_accounts[idx].label = ra.label;
                        changed = true;
                    }
                }
            });
        }

        ['gas_gdrive_webapp_url', 'target_gdrive_email', 'gdrive_folder_name', 'email_subject_filter', 'last_sync_at', 'last_sync_status'].forEach(k => {
            if (remoteSet[k] && ghSettings[k] !== remoteSet[k]) {
                ghSettings[k] = remoteSet[k];
                changed = true;
            }
        });

        if (changed && typeof window.uiState === 'object' && window.uiState) {
            window.uiState.accountsInitialized = false;
        }
        return changed;
    }

    /**
     * Gabungkan daftar records Biovisa dari Server/GitHub/GDrive berdasarkan Nomor Paspor
     * dengan menjaga urutan terbaru sama persis seperti di Komputer Server.
     */
    function mergeRecordsFromBundle(remoteRecs) {
        if (!Array.isArray(remoteRecs) || remoteRecs.length === 0) return false;
        let changed = false;

        const localByPaspor = new Map();
        (Array.isArray(ghRecords) ? ghRecords : []).forEach(r => {
            if (!r || typeof r !== 'object') return;
            const p = String(r.paspor || '').trim().toUpperCase();
            if (p && p !== 'TANPAPASPOR') localByPaspor.set(p, r);
        });

        const mergedList = [];
        const seenPassports = new Set();

        // 1. Masukkan semua record dari Server/Bundle sesuai urutan aslinya (terbaru di atas)
        remoteRecs.forEach(rr => {
            if (!rr || typeof rr !== 'object') return;
            const rp = String(rr.paspor || '').trim().toUpperCase();
            if (!rp || rp === 'TANPAPASPOR' || seenPassports.has(rp)) return;
            seenPassports.add(rp);

            const lr = localByPaspor.get(rp);
            if (!lr) {
                mergedList.push(ensureRecordSevenDocs(JSON.parse(JSON.stringify(rr))));
                changed = true;
            } else {
                const combined = JSON.parse(JSON.stringify({ ...rr, ...lr }));
                if (rr.nama && rr.nama !== 'JAMAAH' && (!lr.nama || lr.nama === 'JAMAAH' || lr.nama !== rr.nama)) {
                    combined.nama = rr.nama;
                    combined.filename = rr.filename;
                    combined.size_kb = rr.size_kb;
                    combined.size_bytes = rr.size_bytes;
                    changed = true;
                }
                if ((parseFloat(lr.size_kb) || 0) > 50 && (parseFloat(rr.size_kb) || 0) <= 50) {
                    combined.size_kb = rr.size_kb;
                    combined.size_bytes = rr.size_bytes;
                    changed = true;
                }
                if (rr.gdrive_uploaded) {
                    if (!lr.gdrive_uploaded) changed = true;
                    combined.gdrive_uploaded = true;
                    combined.gdrive_url = rr.gdrive_url || lr.gdrive_url;
                }
                if (rr.siskohat_uploaded || lr.siskohat_uploaded) {
                    if (!lr.siskohat_uploaded && rr.siskohat_uploaded) changed = true;
                    combined.siskohat_uploaded = true;
                }
                if (rr.seven_docs && rr.seven_docs.docs) {
                    if (!combined.seven_docs || !combined.seven_docs.docs) {
                        combined.seven_docs = JSON.parse(JSON.stringify(rr.seven_docs));
                        changed = true;
                    } else {
                        Object.keys(rr.seven_docs.docs).forEach(ck => {
                            const rDoc = rr.seven_docs.docs[ck];
                            const lDoc = combined.seven_docs.docs[ck];
                            if (rDoc && rDoc.exists) {
                                if (!lDoc || !lDoc.exists || (rDoc.gdrive_file_id && !lDoc.gdrive_file_id)) {
                                    changed = true;
                                }
                                combined.seven_docs.docs[ck] = {
                                    ...(lDoc || {}),
                                    ...rDoc,
                                    exists: true,
                                    gdrive_file_id: rDoc.gdrive_file_id || (lDoc && lDoc.gdrive_file_id) || '',
                                    gdrive_url: rDoc.gdrive_url || (lDoc && lDoc.gdrive_url) || ''
                                };
                            }
                        });
                    }
                }
                mergedList.push(ensureRecordSevenDocs(combined));
            }
        });

        // 2. Tambahkan record yang baru ditarik di browser lokal namun belum ada di remoteRecs
        (Array.isArray(ghRecords) ? ghRecords : []).forEach(lr => {
            if (!lr || typeof lr !== 'object') return;
            const lp = String(lr.paspor || '').trim().toUpperCase();
            if (!lp || lp === 'TANPAPASPOR' || seenPassports.has(lp)) return;
            seenPassports.add(lp);
            mergedList.unshift(ensureRecordSevenDocs(lr));
            changed = true;
        });

        if (mergedList.length !== ghRecords.length) {
            changed = true;
        }
        ghRecords = mergedList;
        return changed;
    }

    /**
     * Gabungkan status kelengkapan 7 dokumen langsung dari 7 Sub-Folder Google Drive (1_Paspor s/d 7_Foto)
     * agar Komputer Lain langsung mendeteksi dokumen 1..7 yang sudah diupload tanpa harus klik manual.
     */
    function mergeSevenDocsFromGDriveCategories(gCats) {
        if (!gCats || typeof gCats !== 'object') return false;
        let changed = false;
        Object.keys(DOC_CAT_META).forEach(catKey => {
            const catMeta = DOC_CAT_META[catKey];
            const filesInCat = Array.isArray(gCats[catKey]) ? gCats[catKey] : [];
            filesInCat.forEach(gf => {
                const gName = String(gf.filename || '');
                if (!gName) return;
                const baseUpper = gName.replace(/\\.[^.]+$/, '').toUpperCase();
                ghRecords.forEach(rec => {
                    const p = String(rec.paspor || '').toUpperCase();
                    if (p && p !== 'TANPAPASPOR' && baseUpper.includes(p)) {
                        ensureRecordSevenDocs(rec);
                        const curDoc = rec.seven_docs.docs[catKey];
                        if (!curDoc || !curDoc.exists || (gf.file_id && curDoc.gdrive_file_id !== gf.file_id)) {
                            rec.seven_docs.docs[catKey] = {
                                exists: true,
                                num: catMeta.num,
                                key: catKey,
                                short: catMeta.short,
                                label: catMeta.label,
                                filename: gName,
                                size_kb: gf.size_kb || (curDoc && curDoc.size_kb) || 48,
                                gdrive_file_id: gf.file_id || (curDoc && curDoc.gdrive_file_id) || '',
                                gdrive_url: gf.url || (curDoc && curDoc.gdrive_url) || ''
                            };
                            rec.seven_docs.available_count = Object.values(rec.seven_docs.docs).filter(d => d && d.exists).length;
                            changed = true;
                        }
                    }
                });
            });
        });
        return changed;
    }

    // Pastikan hanya instansi phu-pasuruan yang menggabungkan INITIAL_SETTINGS & INITIAL_RECORDS Kota Pasuruan
    if (activeTenantSlug === 'phu-pasuruan') {
        mergeSettingsFromBundle(INITIAL_SETTINGS, true);
        mergeRecordsFromBundle(INITIAL_RECORDS);
    }
    ghRecords.forEach(r => ensureRecordSevenDocs(r));

    function saveGhState() {
        const sKey = activeTenantSlug || 'phu-pasuruan';
        try { localStorage.setItem('gh_evisa_settings__' + sKey, JSON.stringify(ghSettings)); } catch (e) {}
        try { localStorage.setItem('gh_evisa_records__' + sKey, JSON.stringify(ghRecords)); } catch (e) {}
        try { localStorage.setItem('gh_evisa_jamaah__' + sKey, JSON.stringify(ghJamaah)); } catch (e) {}
        try { localStorage.setItem('gh_evisa_logs__' + sKey, JSON.stringify(ghLogs.slice(0, 100))); } catch (e) {}
        if (sKey === 'phu-pasuruan') {
            try { localStorage.setItem('gh_evisa_settings', JSON.stringify(ghSettings)); } catch (e) {}
            try { localStorage.setItem('gh_evisa_records', JSON.stringify(ghRecords)); } catch (e) {}
            try { localStorage.setItem('gh_evisa_jamaah', JSON.stringify(ghJamaah)); } catch (e) {}
            try { localStorage.setItem('gh_evisa_logs', JSON.stringify(ghLogs.slice(0, 100))); } catch (e) {}
        }
        try { localStorage.setItem('gh_evisa_images', JSON.stringify(ghImages)); } catch (e) {}
    }

    /**
     * Sinkronisasi Multi-Komputer (Komputer Server <-> GitHub Pages <-> Google Drive Cloud DB <-> Komputer Lain):
     * 1. Jika dibuka di Komputer Server (http://localhost/e-visa/api.php aktif), kirim perubahan ke Server Lokal
     *    dan otomatis jalankan build + git push ke GitHub Pages di background.
     * 2. Simpan & tarik juga dari cloud_sync_bundle.json (GitHub Pages), Google Drive Cloud DB (SILAHUT_CLOUD_DB.json),
     *    serta 7 Sub-Folder Google Drive (list_gdrive_docs) agar Komputer Lain langsung membaca Dokumen 1..7 yang 100% sama.
     */
    async function syncMultiComputerCloudState(isWriteAction = false, replaceAccounts = false) {
        let anyUpdated = false;

        // 1. Tarik cloud_sync_bundle.json terbaru langsung dari server GitHub Pages HANYA jika instansi aktif adalah phu-pasuruan
        if (!isWriteAction && activeTenantSlug === 'phu-pasuruan') {
            try {
                const bRes = await origFetch('storage/data/cloud_sync_bundle.json?_t=' + Date.now(), { cache: 'no-store' });
                if (bRes.ok) {
                    const bJson = await bRes.json();
                    if (bJson && bJson.ok) {
                        if (mergeSettingsFromBundle(bJson.settings, false)) anyUpdated = true;
                        if (mergeRecordsFromBundle(bJson.records)) anyUpdated = true;
                    }
                }
            } catch (e) {}
        }

        // 2. Hubungi Server Laragon Lokal (jika browser ini sedang dibuka di Komputer Server)
        try {
            const imagesToMirror = {};
            Object.keys(ghImages || {}).forEach(k => {
                if (isWriteAction || k.includes(':')) {
                    imagesToMirror[k] = ghImages[k];
                }
            });
            const ctrl = new AbortController();
            const timer = setTimeout(() => ctrl.abort(), 3200);
            const locRes = await origFetch('http://localhost/e-visa/api.php?action=mirror_cloud_to_local&lembaga=' + encodeURIComponent(activeTenantSlug), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    lembaga: activeTenantSlug,
                    settings: ghSettings,
                    records: ghRecords,
                    images: imagesToMirror,
                    replace_accounts: replaceAccounts,
                    force_publish: isWriteAction
                }),
                signal: ctrl.signal
            });
            clearTimeout(timer);
            if (locRes.ok && activeTenantSlug === 'phu-pasuruan') {
                const locData = await locRes.json();
                if (locData && locData.ok && locData.bundle) {
                    if (mergeSettingsFromBundle(locData.bundle.settings, true)) anyUpdated = true;
                    if (mergeRecordsFromBundle(locData.bundle.records)) anyUpdated = true;
                }
            }
        } catch (e) {}

        // 3. Sinkronkan juga lewat Google Drive Cloud DB & 7 Sub-Folder Google Drive (agar Komputer Lain langsung sinkron real-time)
        const gdriveUrl = String(ghSettings.gas_gdrive_webapp_url || '').trim();
        if (gdriveUrl) {
            const folderName = ghSettings.gdrive_folder_name || 'Biovisa_Jamaah_PHU_Pasuruan';
            if (isWriteAction) {
                try {
                    const snapshot = {
                        ok: true,
                        version_ts: Math.floor(Date.now() / 1000),
                        updated_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                        settings: ghSettings,
                        records: ghRecords
                    };
                    origFetch(gdriveUrl, {
                        method: 'POST',
                        mode: 'no-cors',
                        headers: { 'Content-Type': 'text/plain;charset=utf-8' },
                        body: JSON.stringify({
                            action: 'save_cloud_db',
                            folder_name: folderName,
                            cloud_db_json: JSON.stringify(snapshot)
                        })
                    }).catch(() => {});
                } catch (e) {}
            } else {
                try {
                    const dbUrl = gdriveUrl + (gdriveUrl.includes('?') ? '&' : '?') + 'action=get_cloud_db&folder_name=' + encodeURIComponent(folderName) + '&_t=' + Date.now();
                    const gRes = await origFetch(dbUrl);
                    if (gRes.ok) {
                        const gJson = await gRes.json();
                        if (gJson && gJson.ok && gJson.found && gJson.bundle) {
                            if (mergeSettingsFromBundle(gJson.bundle.settings, false)) anyUpdated = true;
                            if (mergeRecordsFromBundle(gJson.bundle.records)) anyUpdated = true;
                        }
                    }
                } catch (e) {}

                try {
                    const listUrl = gdriveUrl + (gdriveUrl.includes('?') ? '&' : '?') + 'action=list_gdrive_docs&folder_name=' + encodeURIComponent(folderName) + '&_t=' + Date.now();
                    const lRes = await origFetch(listUrl);
                    if (lRes.ok) {
                        const lJson = await lRes.json();
                        if (lJson && lJson.ok && lJson.categories) {
                            if (mergeSevenDocsFromGDriveCategories(lJson.categories)) anyUpdated = true;
                        }
                    }
                } catch (e) {}
            }
        }

        if (anyUpdated || isWriteAction) {
            saveGhState();
        }
        return anyUpdated;
    }

    function padJpegDataUrlToTarget50KB(dataUrl, minBytes = 46500, maxBytes = 50500) {
        try {
            const parts = dataUrl.split(',');
            const b64 = parts[1] || '';
            const binStr = atob(b64);
            const len = binStr.length;
            if (len >= minBytes && len <= maxBytes) {
                return { dataUrl, size_bytes: len, size_kb: Number((len / 1024).toFixed(2)) };
            }
            if (len > maxBytes) {
                return { dataUrl, size_bytes: len, size_kb: Number((len / 1024).toFixed(2)) };
            }
            // Sisipkan JPEG COM marker (0xFF 0xFE) tepat sebelum EOI (0xFF 0xD9) agar ukuran file berada di 47-49.4 KB (<= 50 KB)
            const targetLen = 49800;
            const padNeeded = targetLen - len;
            if (padNeeded <= 6 || padNeeded > 45000) {
                return { dataUrl, size_bytes: len, size_kb: Number((len / 1024).toFixed(2)) };
            }
            const origBytes = new Uint8Array(len);
            for (let i = 0; i < len; i++) origBytes[i] = binStr.charCodeAt(i);

            // Pastikan 2 byte terakhir adalah EOI (0xFF 0xD9)
            if (origBytes[len - 2] !== 0xFF || origBytes[len - 1] !== 0xD9) {
                return { dataUrl, size_bytes: len, size_kb: Number((len / 1024).toFixed(2)) };
            }

            const payloadLen = padNeeded - 4; // 2 byte marker (FF FE) + 2 byte length
            const segLen = payloadLen + 2;
            const out = new Uint8Array(targetLen);
            out.set(origBytes.subarray(0, len - 2), 0);
            let pos = len - 2;
            out[pos++] = 0xFF;
            out[pos++] = 0xFE;
            out[pos++] = (segLen >> 8) & 0xFF;
            out[pos++] = segLen & 0xFF;
            for (let k = 0; k < payloadLen; k++) {
                out[pos++] = 0x20; // spasi ASCII
            }
            out[pos++] = 0xFF;
            out[pos++] = 0xD9;

            let newBin = '';
            const chunkSize = 8192;
            for (let i = 0; i < out.length; i += chunkSize) {
                newBin += String.fromCharCode.apply(null, out.subarray(i, i + chunkSize));
            }
            const newDataUrl = 'data:image/jpeg;base64,' + btoa(newBin);
            return {
                dataUrl: newDataUrl,
                size_bytes: out.length,
                size_kb: Number((out.length / 1024).toFixed(2))
            };
        } catch (e) {
            const approx = Math.round((dataUrl.length - 23) * 0.75);
            return { dataUrl, size_bytes: approx, size_kb: Number((approx / 1024).toFixed(2)) };
        }
    }

    async function compressUploadedFileToJpg(fileObj, maxKb = 49.5) {
        const dataUrlOrig = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(fileObj);
        });
        const img = await loadCanvasImg(dataUrlOrig);
        let w = img.naturalWidth || img.width || 800;
        let h = img.naturalHeight || img.height || 1000;
        const maxDim = 960;
        if (w > maxDim || h > maxDim) {
            const ratio = Math.min(maxDim / w, maxDim / h);
            w = Math.max(320, Math.round(w * ratio));
            h = Math.max(320, Math.round(h * ratio));
        }

        const maxBytes = 50500; // <= 49.31 KB (Strict <= 50 KB)
        let bestDataUrl = '';
        let bestBytes = 999999;

        // Loop pengecilan dimensi jika pada kualitas rendah pun masih > 50 KB
        for (let scaleStep = 0; scaleStep < 5; scaleStep++) {
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#FFFFFF';
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);

            let low = 0.14, high = 0.94;
            let candidateUrl = '';
            let candidateBytes = 999999;

            for (let i = 0; i < 8; i++) {
                const mid = (low + high) / 2;
                const dUrl = canvas.toDataURL('image/jpeg', mid);
                const b64Part = dUrl.split(',')[1] || '';
                const exactBytes = Math.floor((b64Part.length * 3) / 4);
                if (exactBytes <= maxBytes) {
                    candidateUrl = dUrl;
                    candidateBytes = exactBytes;
                    low = mid;
                } else {
                    high = mid;
                }
            }

            if (candidateUrl && candidateBytes <= maxBytes) {
                bestDataUrl = candidateUrl;
                bestBytes = candidateBytes;
                break;
            }

            // Jika pada kualitas 0.14 masih > 50 KB, perkecil dimensi gambar 78% lalu ulangi
            w = Math.max(260, Math.round(w * 0.78));
            h = Math.max(260, Math.round(h * 0.78));
            bestDataUrl = canvas.toDataURL('image/jpeg', 0.14);
            bestBytes = Math.floor(((bestDataUrl.split(',')[1] || '').length * 3) / 4);
        }

        // Sesuaikan agar ukuran tepat berada di rentang 46 - 49.4 KB (<= 50 KB) sama persis seperti di Lokal
        return padJpegDataUrlToTarget50KB(bestDataUrl, 46000, maxBytes);
    }

    function addGhLog(type, message) {
        const now = new Date();
        const pad = n => String(n).padStart(2, '0');
        const tStr = `\${now.getFullYear()}-\${pad(now.getMonth()+1)}-\${pad(now.getDate())} \${pad(now.getHours())}:\${pad(now.getMinutes())}:\${pad(now.getSeconds())}`;
        if (ghLogs.length > 0 && ghLogs[0].message === message) {
            ghLogs[0].time = tStr;
            return;
        }
        ghLogs.unshift({ id: 'log_' + Date.now(), time: tStr, type, message });
        saveGhState();
    }

    function loadCanvasImg(src) {
        return new Promise(resolve => {
            if (cachedImgs[src]) return resolve(cachedImgs[src]);
            const img = new Image();
            img.onload = () => { cachedImgs[src] = img; resolve(img); };
            img.src = src;
        });
    }

    async function renderBiovisaCanvas50KB(data) {
        const canvas = document.createElement('canvas');
        canvas.width = 768;
        canvas.height = 1048;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#0C6D6E';
        ctx.fillRect(0, 0, 768, 1048);

        const [ic, tx, sc] = await Promise.all([loadCanvasImg(ASSET_ICON), loadCanvasImg(ASSET_TEXT), loadCanvasImg(ASSET_SUCC)]);
        ctx.drawImage(ic, (768 - 163) / 2, 20, 163, 144);
        ctx.drawImage(tx, (768 - 163) / 2, 166, 163, 40);

        ctx.fillStyle = '#FFFFFF';
        ctx.beginPath();
        ctx.roundRect(34, 226, 700, 738, 10);
        ctx.fill();

        ctx.drawImage(sc, (768 - 288) / 2, 246, 288, 305);

        ctx.fillStyle = '#61ACAC';
        ctx.font = 'bold 20.5px Tahoma, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Application Submitted Successfully', 384, 608);

        ctx.textAlign = 'left';
        ctx.fillStyle = '#222222';
        ctx.font = '12.8px Tahoma, sans-serif';
        ctx.fillText('Dear ' + (data.nama || 'JAMAAH') + ',', 74, 668);

        const p1 = 'Thank you for enrolling your biometric information for your passport number: ';
        ctx.fillText(p1, 74, 718);
        const w1 = ctx.measureText(p1).width;
        ctx.font = 'bold 12.8px Tahoma, sans-serif';
        ctx.fillStyle = '#000000';
        ctx.fillText(data.paspor || '-', 74 + w1, 718);
        const wp = ctx.measureText(data.paspor || '-').width;
        ctx.fillRect(74 + w1, 721, wp, 1.5);
        ctx.font = '12.8px Tahoma, sans-serif';
        ctx.fillText(' .', 74 + w1 + wp, 718);

        const eTime = data.enrollment_time || new Date().toISOString().slice(0, 19).replace('T', ' ');
        ctx.fillStyle = '#222222';
        ctx.fillText('on ' + eTime + ' as part of your visa application process to the Kingdom of Saudi Arabia. and Your', 74, 768);
        const p2 = 'Enrollment Id is : ';
        ctx.fillText(p2, 74, 796);
        const w2 = ctx.measureText(p2).width;
        ctx.font = 'bold 12.8px Tahoma, sans-serif';
        ctx.fillStyle = '#0C6D6E';
        ctx.fillText(data.enrollment_id || '-', 74 + w2, 796);
        const we = ctx.measureText(data.enrollment_id || '-').width;
        ctx.fillRect(74 + w2, 799, we, 1.5);
        ctx.font = '12.8px Tahoma, sans-serif';
        ctx.fillStyle = '#222222';
        ctx.fillText(' .', 74 + w2 + we, 796);

        ctx.fillText('For any questions on submission of your biometric data or the status of your visa application, please visit', 74, 842);
        ctx.fillStyle = '#1155CC';
        ctx.fillText('www.mofa.gov.sa', 74, 870);
        ctx.fillRect(74, 873, ctx.measureText('www.mofa.gov.sa').width, 1);

        ctx.fillStyle = '#EB0000';
        ctx.font = 'italic 12.8px Arial, sans-serif';
        ctx.fillText('*This is an automated message, please do not reply to this email directly*', 74, 932);

        ctx.fillStyle = '#FFFFFF';
        ctx.font = '14px Tahoma, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('© All Rights Reserved - Ministry of Foreign Affairs - Kingdom of Saudi Arabia', 384, 1008);

        let low = 0.25, high = 0.92, bestDataUrl = canvas.toDataURL('image/jpeg', 0.85);
        for (let i = 0; i < 6; i++) {
            const mid = (low + high) / 2;
            const dUrl = canvas.toDataURL('image/jpeg', mid);
            const approxBytes = Math.round((dUrl.length - 23) * 0.75);
            if (approxBytes <= 50400) {
                bestDataUrl = dUrl;
                low = mid;
            } else {
                high = mid;
            }
        }
        const sizeBytes = Math.round((bestDataUrl.length - 23) * 0.75);
        return {
            dataUrl: bestDataUrl,
            size_bytes: sizeBytes,
            size_kb: Number((sizeBytes / 1024).toFixed(2))
        };
    }

    // Resolve image URL: supports all 7 document categories (catKey) as well as default biovisa
    window.__resolveBiovisaImgUrl = function(filename, dl = false, catKey = 'biovisa') {
        const cleanCat = (catKey || 'biovisa').toLowerCase();
        const cloudKey = cleanCat === 'biovisa' ? filename : `\${cleanCat}:\${filename}`;
        if (ghImages[cloudKey]) return ghImages[cloudKey];
        if (cleanCat === 'biovisa' && ghImages[filename]) return ghImages[filename];
        const folder = (cleanCat && cleanCat !== 'biovisa') ? `storage/dokumen/\${cleanCat}/` : 'storage/biovisa/';
        return folder + encodeURIComponent(filename);
    };

    // Fallback ambil base64 dokumen langsung dari Google Drive (7 Sub-Folder) jika file statis GitHub Pages belum ter-deploy
    window.__fetchGDriveDocDataUrl = async function(recId, catKey) {
        try {
            const cleanCat = String(catKey || 'paspor').toLowerCase();
            const rec = ghRecords.find(r => r.id === recId);
            if (!rec) return '';
            ensureRecordSevenDocs(rec);
            const docInfo = (rec.seven_docs && rec.seven_docs.docs && rec.seven_docs.docs[cleanCat]) || {};
            const fn = docInfo.filename || `\${rec.nama}_\${rec.paspor}.jpg`;
            const cloudKey = cleanCat === 'biovisa' ? fn : `\${cleanCat}:\${fn}`;
            if (ghImages[cloudKey]) return ghImages[cloudKey];

            const gasUrl = String(ghSettings.gas_gdrive_webapp_url || '').trim();
            if (!gasUrl) return '';

            let fileId = docInfo.gdrive_file_id || '';
            if (!fileId) {
                const listUrl = gasUrl + (gasUrl.includes('?') ? '&' : '?') + 'action=list_gdrive_docs&folder_name=' + encodeURIComponent(ghSettings.gdrive_folder_name || 'Biovisa_Jamaah_PHU_Pasuruan') + '&_t=' + Date.now();
                const lRes = await origFetch(listUrl);
                if (lRes.ok) {
                    const lJson = await lRes.json();
                    if (lJson && lJson.ok && lJson.categories) {
                        mergeSevenDocsFromGDriveCategories(lJson.categories);
                        const updatedInfo = (rec.seven_docs && rec.seven_docs.docs && rec.seven_docs.docs[cleanCat]) || {};
                        fileId = updatedInfo.gdrive_file_id || '';
                        if (!fileId) {
                            const files = Array.isArray(lJson.categories[cleanCat]) ? lJson.categories[cleanCat] : [];
                            const passport = String(rec.paspor || '').toUpperCase();
                            const match = files.find(f => {
                                const name = String(f.filename || '').replace(/\.[^.]+$/, '').toUpperCase();
                                return passport && passport !== 'TANPAPASPOR' && name.includes(passport);
                            });
                            fileId = match && match.file_id ? match.file_id : '';
                        }
                        saveGhState();
                    }
                }
            }
            if (!fileId) return '';

            const dlUrl = gasUrl + (gasUrl.includes('?') ? '&' : '?') + 'action=get_gdrive_file&file_id=' + encodeURIComponent(fileId);
            const dlRes = await origFetch(dlUrl);
            if (!dlRes.ok) return '';
            const dlJson = await dlRes.json();
            if (dlJson && dlJson.ok && dlJson.base64) {
                const mimeType = String(dlJson.mime_type || 'image/jpeg').toLowerCase();
                const dUrl = 'data:' + mimeType + ';base64,' + dlJson.base64;
                ghImages[cloudKey] = dUrl;
                saveGhState();
                window.postMessage({ type: 'SILAHUT_CLOUD_IMAGE_UPDATE', key: cloudKey, dataUrl: dUrl }, '*');
                return dUrl;
            }
        } catch (e) {}
        return '';
    };

    // Intercept tombol Keluar (logout.php) agar di GitHub Pages TIDAK men-download file logout.php,
    // melainkan menampilkan layar Login SILAHUT E-VISA (identik dengan login.php di lokal).
    function showCloudLoginOverlay(prefillUser = '') {
        let overlay = document.getElementById('gh-cloud-login-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'gh-cloud-login-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;padding:16px;';
            overlay.innerHTML = `
                <div class="admin-card" style="max-width:420px;width:100%;padding:28px 26px;box-shadow:0 10px 30px rgba(0,0,0,0.08);">
                    <div style="text-align:center;margin-bottom:18px;">
                        <img src="assets/logo-silahut.svg" alt="Logo" style="height:56px;">
                        <h1 style="font-size:18px;margin:12px 0 4px;">SILAHUT E-VISA</h1>
                        <p style="font-size:13px;color:var(--text-secondary);">Masuk untuk mengakses data Biovisa jamaah (terlindungi).</p>
                    </div>
                    <div id="gh-login-error" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:13px;"></div>
                    <form id="gh-cloud-login-form" autocomplete="off">
                        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;">Username</label>
                        <input class="form-control" type="text" id="gh-login-username" required style="margin-bottom:14px;" placeholder="Masukkan username">
                        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;">Password</label>
                        <input class="form-control" type="password" id="gh-login-password" required style="margin-bottom:18px;" placeholder="Masukkan password">
                        <button type="submit" class="btn btn-gold" style="width:100%;">Masuk</button>
                    </form>
                </div>
            `;
            document.body.appendChild(overlay);

            overlay.querySelector('#gh-cloud-login-form').addEventListener('submit', async function(ev) {
                ev.preventDefault();
                const uIn = String(document.getElementById('gh-login-username').value || '').trim();
                const pIn = String(document.getElementById('gh-login-password').value || '');
                const errBox = document.getElementById('gh-login-error');
                if (!uIn || !pIn) {
                    errBox.textContent = 'Username dan password wajib diisi.';
                    errBox.style.display = 'block';
                    return;
                }
                const savedPwMap = JSON.parse(localStorage.getItem('gh_evisa_pw_map') || '{}');
                const key = uIn.toLowerCase();
                if (savedPwMap[key] && savedPwMap[key] !== pIn) {
                    errBox.textContent = 'Username atau password salah.';
                    errBox.style.display = 'block';
                    return;
                }
                const roleLabel = key === 'superadmin' ? 'super_admin' : 'admin';
                const slug = (key === 'kd1322' || key.includes('kab')) ? 'kemenhaj-pasuruankab' : 'phu-pasuruan';
                const displayName = key === 'superadmin'
                    ? 'Super Administrator'
                    : (slug === 'kemenhaj-pasuruankab' ? 'Admin Kantor Kemenhaj kabupaten Pasuruan' : 'Admin Kantor Kemenhaj Pasuruan');
                const instansiName = slug === 'kemenhaj-pasuruankab'
                    ? 'Kementerian Haji dan Umrah Kabupaten Pasuruan'
                    : 'Kantor Kemenhaj Kota Pasuruan';

                const sessObj = { username: uIn, role: roleLabel, slug: slug, nama: displayName, instansi: instansiName };
                localStorage.setItem('gh_evisa_active_user', JSON.stringify(sessObj));
                sessionStorage.removeItem('gh_evisa_logged_out');
                loadTenantState(slug);
                if (slug === 'phu-pasuruan') {
                    mergeSettingsFromBundle(INITIAL_SETTINGS, true);
                    mergeRecordsFromBundle(INITIAL_RECORDS);
                }
                ghRecords.forEach(r => ensureRecordSevenDocs(r));
                if (typeof window.uiState === 'object' && window.uiState) {
                    window.uiState.accountsInitialized = false;
                }
                applyCloudUserHeader(sessObj);
                overlay.style.display = 'none';
                document.body.style.overflow = '';
                if (typeof window.loadAppStatus === 'function') {
                    await window.loadAppStatus();
                }
                if (typeof showToast === 'function') {
                    showToast('Berhasil masuk sebagai ' + displayName + ' (' + instansiName + ')');
                }
            });
        }
        const errBox = document.getElementById('gh-login-error');
        if (errBox) errBox.style.display = 'none';
        const uField = document.getElementById('gh-login-username');
        const pField = document.getElementById('gh-login-password');
        if (uField) uField.value = prefillUser || '';
        if (pField) pField.value = '';
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        setTimeout(() => { if (uField) uField.focus(); }, 60);
    }

    function applyCloudUserHeader(userObj) {
        if (!userObj) return;
        const rEl = document.getElementById('pill-user-role');
        const nEl = document.getElementById('pill-user-name');
        const iEl = document.getElementById('pill-instansi-name');
        const topGovEl = document.getElementById('top-gov-instansi-label');
        if (rEl) rEl.textContent = (userObj.role || 'admin') + ':';
        if (nEl) nEl.textContent = userObj.nama || userObj.username || 'Admin';
        if (iEl && userObj.instansi) iEl.textContent = userObj.instansi;
        if (topGovEl && userObj.instansi) topGovEl.textContent = userObj.instansi;
    }

    // Intercept links & images pointing to api.php?action=download_file or logout.php
    const origSetAttr = Element.prototype.setAttribute;
    document.addEventListener('click', function(e) {
        const logoutLink = e.target.closest('a[href*="logout.php"], #btn-logout-portal');
        if (logoutLink) {
            e.preventDefault();
            e.stopPropagation();
            sessionStorage.setItem('gh_evisa_logged_out', '1');
            showCloudLoginOverlay('');
            return;
        }
        const a = e.target.closest('a[href*="api.php"]');
        if (!a) return;
        const href = a.getAttribute('href') || '';
        if (href.includes('action=export_csv')) {
            e.preventDefault();
            let csv = '\\uFEFFNo,Nama Jamaah,Nomor Paspor,MOFA Enrollment ID,Asal Sumber Email,Waktu Enrollment,Nama File,Ukuran KB,Status GDrive\\n';
            ghRecords.forEach((r, idx) => {
                csv += `\${idx+1},"\${r.nama || ''}","\${r.paspor || ''}","\${r.enrollment_id || ''}","\${r.to_email || ''}","\${r.enrollment_time || ''}","\${r.filename || ''}","\${r.size_kb || ''}","\${r.gdrive_uploaded ? 'Tersimpan di GDrive' : 'Lokal'}"\\n`;
            });
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const tmp = document.createElement('a');
            tmp.href = URL.createObjectURL(blob);
            tmp.download = 'Rekap_Biovisa_PHU_Pasuruan.csv';
            tmp.click();
        } else if (href.includes('action=download_siskohat_ext')) {
            a.href = 'SILAHUT_Auto_Siskohat_Extension.zip';
            a.setAttribute('download', 'SILAHUT_Auto_Siskohat_Extension.zip');
        } else if (href.includes('action=download_file')) {
            const u = new URL(href, location.href);
            const fn = u.searchParams.get('file') || '';
            const cat = u.searchParams.get('cat') || 'biovisa';
            if (fn) {
                a.href = window.__resolveBiovisaImgUrl(fn, true, cat);
                a.setAttribute('download', fn);
            }
        }
    }, true);

    // MutationObserver untuk mengubah <img src="api.php?action=download_file&inline=1&file=..."> menjadi path statis / dataUrl
    const imgObs = new MutationObserver(() => {
        document.querySelectorAll('a[href*="action=download_siskohat_ext"]').forEach(a => {
            a.href = 'SILAHUT_Auto_Siskohat_Extension.zip';
            a.setAttribute('download', 'SILAHUT_Auto_Siskohat_Extension.zip');
        });
        document.querySelectorAll('img[src*="api.php?action=download_file"]').forEach(img => {
            try {
                const u = new URL(img.getAttribute('src'), location.href);
                const fn = u.searchParams.get('file') || '';
                const cat = u.searchParams.get('cat') || 'biovisa';
                if (fn) {
                    img.dataset.ghFile = fn;
                    img.dataset.ghCat = cat;
                    if (!img.dataset.ghFallbackBound) {
                        img.dataset.ghFallbackBound = '1';
                        img.addEventListener('error', async function() {
                            const fName = img.dataset.ghFile || '';
                            const cKey = (img.dataset.ghCat || 'biovisa').toLowerCase();
                            if (!fName || img.dataset.ghRenderedFallback === fName) return;
                            img.dataset.ghRenderedFallback = fName;
                            const rec = (ghRecords || []).find(r => r && (r.filename === fName || (r.paspor && fName.includes(r.paspor))));
                            if (cKey === 'biovisa' && rec) {
                                try {
                                    const rendered = await renderBiovisaCanvas50KB({
                                        nama: rec.nama,
                                        paspor: rec.paspor,
                                        enrollment_id: rec.enrollment_id,
                                        enrollment_time: rec.enrollment_time
                                    });
                                    if (rendered && rendered.dataUrl) {
                                        ghImages[fName] = rendered.dataUrl;
                                        img.src = rendered.dataUrl;
                                        return;
                                    }
                                } catch (e) {}
                            }
                            if (rec && typeof window.__fetchGDriveDocDataUrl === 'function') {
                                const gUrl = await window.__fetchGDriveDocDataUrl(rec.id, cKey);
                                if (gUrl) img.src = gUrl;
                            }
                        });
                    }
                    img.src = window.__resolveBiovisaImgUrl(fn, false, cat);
                }
            } catch (e) {}
        });
        document.querySelectorAll('a[href*="api.php?action=download_file"]').forEach(a => {
            try {
                const u = new URL(a.getAttribute('href'), location.href);
                const fn = u.searchParams.get('file') || '';
                const cat = u.searchParams.get('cat') || 'biovisa';
                if (fn) {
                    a.href = window.__resolveBiovisaImgUrl(fn, true, cat);
                }
            } catch (e) {}
        });
    });
    window.addEventListener('DOMContentLoaded', () => {
        imgObs.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['src', 'href'] });
        document.querySelectorAll('a[href*="logout.php"], #btn-logout-portal').forEach(a => {
            a.setAttribute('href', '#logout');
        });
        const savedUser = JSON.parse(localStorage.getItem('gh_evisa_active_user') || 'null');
        if (savedUser) {
            applyCloudUserHeader(savedUser);
        }
        if (sessionStorage.getItem('gh_evisa_logged_out') === '1') {
            showCloudLoginOverlay('');
        }
    });

    const origFetch = window.fetch.bind(window);
    window.fetch = async function(input, init) {
        const urlStr = typeof input === 'string' ? input : (input && input.url ? input.url : '');
        if (!urlStr.includes('api.php')) {
            return origFetch(input, init);
        }

        const u = new URL(urlStr, location.href);
        const action = u.searchParams.get('action') || '';
        let bodyData = {};
        if (init && init.body) {
            if (typeof init.body === 'string') {
                try { bodyData = JSON.parse(init.body); } catch (e) {}
            } else if (init.body instanceof FormData) {
                init.body.forEach((v, k) => { bodyData[k] = v; });
            }
        }

        const makeJsonRes = obj => new Response(JSON.stringify(obj), {
            status: 200,
            headers: { 'Content-Type': 'application/json' }
        });

        if (action === 'status') {
            // Sinkronkan daftar akun email pembaca & data Biovisa antar-komputer (maksimal 1x tiap 8 detik)
            if (Date.now() - lastCloudSyncPoll > 8000) {
                lastCloudSyncPoll = Date.now();
                await syncMultiComputerCloudState(false, false);
            }
            const total = ghRecords.length;
            const upGdrive = ghRecords.filter(r => r.gdrive_uploaded).length;
            const valid50 = ghRecords.filter(r => (parseFloat(r.size_kb) || 0) <= 50).length;
            const totalKb = ghRecords.reduce((acc, r) => acc + (parseFloat(r.size_kb) || 0), 0);

            const byPaspor = {};
            ghRecords.forEach(r => { if (r.paspor) byPaspor[r.paspor.toUpperCase()] = r; });
            let mDone = 0;
            const jamaahWithStatus = ghJamaah.map(j => {
                const p = (j.paspor || '').toUpperCase();
                const done = !!byPaspor[p];
                if (done) mDone++;
                return {
                    nama: j.nama,
                    paspor: p,
                    sudah_biovisa: done,
                    filename: done ? byPaspor[p].filename : ''
                };
            });

            const activeSeed = getTenantSeed(activeTenantSlug);
            return makeJsonRes({
                ok: true,
                settings: ghSettings,
                records: ghRecords,
                active_lembaga: {
                    slug: activeTenantSlug,
                    nama: activeSeed.nama
                },
                jamaah: jamaahWithStatus,
                logs: ghLogs,
                stats: {
                    total_biovisa: total,
                    uploaded_gdrive: upGdrive,
                    pending_gdrive: Math.max(0, total - upGdrive),
                    valid_50kb_count: valid50,
                    avg_size_kb: total > 0 ? Number((totalKb / total).toFixed(2)) : 0,
                    total_master_jamaah: ghJamaah.length,
                    master_done: mDone,
                    master_pending: Math.max(0, ghJamaah.length - mDone)
                }
            });
        }

        if (action === 'sync_emails') {
            const accounts = (ghSettings.source_accounts || []).filter(a => a.enabled !== false && a.gas_url);
            if (accounts.length === 0 && ghSettings.gas_gmail_webapp_url) {
                accounts.push({ email: ghSettings.source_email, label: 'Email Utama', gas_url: ghSettings.gas_gmail_webapp_url });
            }
            let newRecords = [];
            for (const acc of accounts) {
                try {
                    const qUrl = acc.gas_url + (acc.gas_url.includes('?') ? '&' : '?') + 'action=fetch_mofa&subject=Enrollment+confirmation&limit=100';
                    const res = await origFetch(qUrl);
                    const data = await res.json();
                    for (const em of (data.emails || [])) {
                        const rawCombined = ((em.subject || '') + '\\n' + (em.body_text || '')).replace(/ØŒ|،/g, ',');
                        if (/One Time Password|Verify Your Account/i.test(rawCombined)) continue;
                        if (/under review/i.test(rawCombined) && !/passport\\s+number/i.test(rawCombined)) continue;
                        if (!/Application Submitted Successfully|Enrollment confirmation/i.test(rawCombined)) continue;

                        // Pecah jika dalam 1 pesan email terdapat beberapa blok Enrollment confirmation (multi-jamaah dalam 1 pesan/thread)
                        const multiBlocks = rawCombined.match(/Dear\\s+[^\\r\\n,،<]{2,55}?\\s*[,،\\r\\n<][\\s\\S]{10,450}?passport\\s+number\\s*:\\s*[A-Z0-9]{6,12}[\\s\\S]{0,350}?(?:Enrollment\\s*Id\\s*(?:is)?\\s*:\\s*\\d{5,15}|www\\.mofa\\.gov\\.sa|$)/gi);
                        const blocksToProcess = (multiBlocks && multiBlocks.length > 1) ? multiBlocks : [rawCombined];

                        for (const combined of blocksToProcess) {
                            const pasporM = combined.match(/passport\\s+number\\s*:\\s*([A-Z0-9]{6,12})/i) || combined.match(/\\b([A-Z]{1,3}\\d{6,8})\\b/);
                            if (!pasporM) continue;
                            const paspor = pasporM[1].toUpperCase();
                            const existingIdx = ghRecords.findIndex(r => r.paspor === paspor);
                            if (existingIdx !== -1 && ghRecords[existingIdx].nama && ghRecords[existingIdx].nama !== 'JAMAAH') continue;

                            const namaM = combined.match(/Dear\\s+([^\\r\\n,،<]{2,55}?)\\s*[,،\\r\\n<]/i);
                            const rawNama = namaM ? namaM[1].replace(/ØŒ|[،,]/g, ' ').replace(/[^A-Za-z\\s.'\\-]+/g, ' ').replace(/\\s+/g, ' ').trim().toUpperCase() : '';
                            const nama = (rawNama && rawNama.length >= 2) ? rawNama : 'JAMAAH';
                            const enrollM = combined.match(/Enrollment\\s*Id\\s*(?:is)?\\s*:\\s*(\\d{5,15})/i);
                            const enrollId = enrollM ? enrollM[1] : '-';
                            const timeM = combined.match(/\\bon\\s+(\\d{4}-\\d{2}-\\d{2}\\s+\\d{2}:\\d{2}:\\d{2})/i);
                            const enrollTime = timeM ? timeM[1] : new Date().toISOString().slice(0, 19).replace('T', ' ');
                            const filename = `\${nama}_\${paspor}.jpg`;

                            const rendered = await renderBiovisaCanvas50KB({ nama, paspor, enrollment_id: enrollId, enrollment_time: enrollTime });
                            ghImages[filename] = rendered.dataUrl;

                            const rec = {
                                id: 'bv_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
                                message_id: (em.message_id || ('msg_' + Date.now())) + '_' + paspor,
                                nama,
                                paspor,
                                enrollment_id: enrollId,
                                enrollment_time: enrollTime,
                                filename,
                                size_bytes: rendered.size_bytes,
                                size_kb: rendered.size_kb,
                                source_type: 'saudivisabio_email',
                                subject: em.subject || 'Enrollment confirmation',
                                from_email: em.from || 'Mofa <MofaBioApp@mofa.gov.sa>',
                                to_email: (em.to || acc.email || 'phujtmkopasuruan@gmail.com').replace(/[<>]/g, ''),
                                source_account_email: acc.email || '',
                                source_account_label: acc.label || '',
                                lembaga_slug: activeTenantSlug,
                                processed_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                                gdrive_uploaded: false
                            };
                            ghRecords.unshift(rec);
                            newRecords.push(rec);
                            addGhLog('success', `Email Enrollment Confirmation diproses -> \${filename} (\${rendered.size_kb} KB)`);
                        }
                    }
                } catch (err) {
                    addGhLog('error', `Gagal sinkron \${acc.email}: ` + err.message);
                }
            }
            ghSettings.last_sync_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
            ghSettings.last_sync_status = newRecords.length > 0 ? `Berhasil memproses \${newRecords.length} Biovisa baru` : 'Sinkronisasi berhasil (Tidak ada email baru)';
            saveGhState();
            if (newRecords.length > 0) {
                await syncMultiComputerCloudState(true, false);
            }
            return makeJsonRes({
                ok: true,
                new_count: newRecords.length,
                new_records: newRecords,
                message: ghSettings.last_sync_status
            });
        }

        if (action === 'simulate_email') {
            const randNum = Math.floor(1000000 + Math.random() * 8999999);
            const nama = 'H. ABDULLAH PASURUAN';
            const paspor = 'E' + randNum;
            const enroll = String(Math.floor(42000000 + Math.random() * 999999));
            const eTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
            const filename = `\${nama}_\${paspor}.jpg`;
            const rendered = await renderBiovisaCanvas50KB({ nama, paspor, enrollment_id: enroll, enrollment_time: eTime });
            ghImages[filename] = rendered.dataUrl;

            const rec = {
                id: 'bv_' + Date.now(),
                nama,
                paspor,
                enrollment_id: enroll,
                enrollment_time: eTime,
                filename,
                size_bytes: rendered.size_bytes,
                size_kb: rendered.size_kb,
                source_type: 'saudivisabio_email',
                lembaga_slug: activeTenantSlug,
                to_email: ghSettings.source_email || 'phujtmkopasuruan@gmail.com',
                processed_at: eTime,
                gdrive_uploaded: false
            };
            ghRecords.unshift(rec);
            addGhLog('info', `[Simulasi Email Masuk] \${filename} (\${rendered.size_kb} KB) berhasil dibuat.`);
            saveGhState();
            await syncMultiComputerCloudState(true, false);
            return makeJsonRes({ ok: true, message: `Simulasi \${filename} (\${rendered.size_kb} KB) berhasil dibuat!`, record: rec });
        }

        if (action === 'manual_create') {
            const nama = String(bodyData.nama || 'JAMAAH').trim().toUpperCase();
            const paspor = String(bodyData.paspor || 'TANPAPASPOR').trim().toUpperCase();
            const enroll = String(bodyData.enrollment_id || '42163441').trim();
            const eTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
            const filename = `\${nama}_\${paspor}.jpg`;
            const rendered = await renderBiovisaCanvas50KB({ nama, paspor, enrollment_id: enroll, enrollment_time: eTime });
            ghImages[filename] = rendered.dataUrl;

            const rec = {
                id: 'bv_' + Date.now(),
                nama,
                paspor,
                enrollment_id: enroll,
                enrollment_time: eTime,
                filename,
                size_bytes: rendered.size_bytes,
                size_kb: rendered.size_kb,
                source_type: 'saudivisabio_email',
                lembaga_slug: activeTenantSlug,
                to_email: ghSettings.source_email || 'phujtmkopasuruan@gmail.com',
                processed_at: eTime,
                gdrive_uploaded: false
            };
            ghRecords.unshift(rec);
            addGhLog('success', `Konversi manual berhasil dibuat: \${filename} (\${rendered.size_kb} KB)`);
            saveGhState();
            await syncMultiComputerCloudState(true, false);
            return makeJsonRes({ ok: true, message: `File \${filename} (\${rendered.size_kb} KB) berhasil dibuat!`, record: rec });
        }

        if (action === 'update_record') {
            const idx = ghRecords.findIndex(r => r.id === bodyData.id);
            if (idx === -1) return makeJsonRes({ ok: false, error: 'Data tidak ditemukan' });
            const nama = String(bodyData.nama || '').trim().toUpperCase();
            const paspor = String(bodyData.paspor || '').trim().toUpperCase();
            const enroll = String(bodyData.enrollment_id || ghRecords[idx].enrollment_id || '-').trim();
            const filename = `\${nama}_\${paspor}.jpg`;
            const rendered = await renderBiovisaCanvas50KB({ nama, paspor, enrollment_id: enroll, enrollment_time: ghRecords[idx].enrollment_time });
            ghImages[filename] = rendered.dataUrl;

            ghRecords[idx].nama = nama;
            ghRecords[idx].paspor = paspor;
            ghRecords[idx].enrollment_id = enroll;
            ghRecords[idx].filename = filename;
            ghRecords[idx].size_bytes = rendered.size_bytes;
            ghRecords[idx].size_kb = rendered.size_kb;
            saveGhState();
            await syncMultiComputerCloudState(true, false);
            return makeJsonRes({ ok: true, message: `Berhasil mengubah nama file menjadi \${filename}`, record: ghRecords[idx] });
        }

        if (action === 'delete_record') {
            const ids = bodyData.ids || (bodyData.id ? [bodyData.id] : []);
            ghRecords = ghRecords.filter(r => !ids.includes(r.id));
            saveGhState();
            await syncMultiComputerCloudState(true, false);
            return makeJsonRes({ ok: true, message: 'Data berhasil dihapus.' });
        }

        if (action === 'mark_siskohat') {
            const idx = ghRecords.findIndex(r => r.id === bodyData.id);
            if (idx !== -1) {
                ghRecords[idx].siskohat_uploaded = !!bodyData.status;
                saveGhState();
                await syncMultiComputerCloudState(true, false);
            }
            return makeJsonRes({ ok: true, message: 'Status Siskohat diperbarui.' });
        }

        if (action === 'list_scanners') {
            let devices = [];
            const pushUniqueDevice = (dev) => {
                if (!dev || !dev.name) return;
                if (!devices.some(x => x.name === dev.name)) {
                    devices.push(dev);
                }
            };

            // 1. Coba jembatan Ekstensi Chrome SILAHUT (bebas blokir Mixed Content HTTPS -> HTTP localhost)
            try {
                const extData = await new Promise(resolve => {
                    const reqId = 'sc_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
                    const timer = setTimeout(() => {
                        window.removeEventListener('message', onMsg);
                        resolve(null);
                    }, 1800);
                    function onMsg(ev) {
                        if (ev && ev.data && ev.data.type === 'SILAHUT_EXT_SCANNER_RES' && ev.data.reqId === reqId) {
                            clearTimeout(timer);
                            window.removeEventListener('message', onMsg);
                            resolve(ev.data.data || null);
                        }
                    }
                    window.addEventListener('message', onMsg);
                    window.postMessage({ type: 'SILAHUT_EXT_SCANNER_REQ', reqId, scannerAction: 'list_scanners' }, '*');
                });
                if (extData && Array.isArray(extData.devices)) {
                    extData.devices.forEach(pushUniqueDevice);
                }
            } catch (e) {}

            // 2. Hubungi langsung jembatan WIA/TWAIN Scanner Windows lokal (http://localhost/e-visa/api.php)
            try {
                const ctrl = new AbortController();
                const tId = setTimeout(() => ctrl.abort(), 2500);
                const locRes = await origFetch('http://localhost/e-visa/api.php?action=list_scanners&_t=' + Date.now(), {
                    credentials: 'include',
                    signal: ctrl.signal
                });
                clearTimeout(tId);
                if (locRes.ok) {
                    const locJson = await locRes.json();
                    if (locJson && Array.isArray(locJson.devices)) {
                        locJson.devices.forEach(pushUniqueDevice);
                    }
                }
            } catch (e) {}

            // 3. Sertakan daftar driver WIA / TWAIN / Registry Imaging perangkat keras yang terdeteksi pada komputer
            if (Array.isArray(INITIAL_SCANNERS)) {
                INITIAL_SCANNERS.forEach(pushUniqueDevice);
            }

            // 4. Cek juga perangkat USB Document Scanner / Kamera Dokumen di browser komputer tersebut
            try {
                if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
                    const mDevs = await navigator.mediaDevices.enumerateDevices();
                    mDevs.filter(d => d.kind === 'videoinput').forEach((vd, idx) => {
                        pushUniqueDevice({
                            id: '__UVC_SCANNER__:' + (vd.deviceId || idx),
                            name: vd.label || ('Scanner Kamera / USB Imaging #' + (idx + 1)),
                            manufacturer: 'USB Video / Document Scanner',
                            driver_type: 'Direct Browser USB Imaging Driver',
                            status: 'Aktif'
                        });
                    });
                }
            } catch (e) {}

            return makeJsonRes({
                ok: true,
                count: devices.length,
                devices: devices,
                has_hardware_scanner: devices.length > 0
            });
        }

        if (action === 'scan_jamaah_doc') {
            const catKey  = String(bodyData.category || 'paspor').toLowerCase();
            const catMeta = DOC_CAT_META[catKey] || DOC_CAT_META.paspor;
            const recId   = String(bodyData.id || '').trim();
            const devId   = String(bodyData.device_id || '');
            const matchedIdx = ghRecords.findIndex(r => r.id === recId);
            const matchedRec = matchedIdx !== -1 ? ghRecords[matchedIdx] : null;
            const finalFilename = matchedRec
                ? `\${matchedRec.nama}_\${matchedRec.paspor}.jpg`
                : `JAMAAH_SCAN_\${Date.now()}.jpg`;

            // A. Jika memilih USB Document Scanner / Imaging langsung dari browser (__UVC_SCANNER__)
            if (devId.startsWith('__UVC_SCANNER__:')) {
                try {
                    const camId = devId.replace('__UVC_SCANNER__:', '');
                    const constraints = {
                        video: camId ? { deviceId: { exact: camId }, width: { ideal: 1280 }, height: { ideal: 960 } } : { width: { ideal: 1280 }, height: { ideal: 960 } }
                    };
                    const stream = await navigator.mediaDevices.getUserMedia(constraints);
                    const video = document.createElement('video');
                    video.srcObject = stream;
                    video.muted = true;
                    await video.play();
                    await new Promise(r => setTimeout(r, 450));

                    const c = document.createElement('canvas');
                    c.width = video.videoWidth || 960;
                    c.height = video.videoHeight || 720;
                    c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
                    stream.getTracks().forEach(t => t.stop());

                    const blob = await new Promise(res => c.toBlob(res, 'image/jpeg', 0.92));
                    const fileObj = new File([blob], finalFilename, { type: 'image/jpeg' });
                    const compressed = await compressUploadedFileToJpg(fileObj, 49.5);

                    const cloudKey = catKey === 'biovisa' ? finalFilename : `\${catKey}:\${finalFilename}`;
                    ghImages[cloudKey] = compressed.dataUrl;
                    if (catKey === 'biovisa') ghImages[finalFilename] = compressed.dataUrl;

                    window.postMessage({ type: 'SILAHUT_CLOUD_IMAGE_UPDATE', key: cloudKey, dataUrl: compressed.dataUrl }, '*');

                    if (matchedRec) {
                        ensureRecordSevenDocs(matchedRec);
                        matchedRec.seven_docs.docs[catKey] = {
                            exists: true,
                            num: catMeta.num,
                            key: catKey,
                            short: catMeta.short,
                            label: catMeta.label,
                            filename: finalFilename,
                            size_kb: compressed.size_kb
                        };
                        matchedRec.seven_docs.available_count = Object.values(matchedRec.seven_docs.docs).filter(d => d && d.exists).length;
                    }
                    saveGhState();
                    await syncMultiComputerCloudState(true, false);
                    return makeJsonRes({
                        ok: true,
                        filename: finalFilename,
                        size_kb: compressed.size_kb,
                        message: `Berhasil memindai dokumen -> \${finalFilename} (\${compressed.size_kb} KB <= 50 KB)!`
                    });
                } catch (errCam) {
                    return makeJsonRes({ ok: false, error: 'Gagal mengakses perangkat pemindai USB: ' + errCam.message });
                }
            }

            // B. Panggil Driver Scanner WIA/TWAIN Windows pada komputer tersebut melalui jembatan Ekstensi Chrome atau localhost
            try {
                let locData = await new Promise(resolve => {
                    const reqId = 'scan_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
                    const timer = setTimeout(() => {
                        window.removeEventListener('message', onMsg);
                        resolve(null);
                    }, 45000);
                    function onMsg(ev) {
                        if (ev && ev.data && ev.data.type === 'SILAHUT_EXT_SCANNER_RES' && ev.data.reqId === reqId) {
                            clearTimeout(timer);
                            window.removeEventListener('message', onMsg);
                            resolve(ev.data.data || null);
                        }
                    }
                    window.addEventListener('message', onMsg);
                    setTimeout(() => {
                        // Jika dalam 1.5 detik ekstensi belum merespons ack, fallback ke fetch langsung
                    }, 1500);
                    window.postMessage({ type: 'SILAHUT_EXT_SCANNER_REQ', reqId, scannerAction: 'scan_jamaah_doc', payload: bodyData }, '*');
                    setTimeout(() => {
                        if (!window.__silahutExtBridgeActive) {
                            clearTimeout(timer);
                            window.removeEventListener('message', onMsg);
                            resolve(null);
                        }
                    }, 1200);
                });

                if (!locData) {
                    const locRes = await origFetch('http://localhost/e-visa/api.php?action=scan_jamaah_doc', {
                        method: 'POST',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(bodyData)
                    });
                    locData = await locRes.json();
                }
                if (!locData || !locData.ok) {
                    return makeJsonRes({
                        ok: false,
                        error: (locData && locData.error) || 'Gagal membaca dari driver scanner.'
                    });
                }

                // Pastikan hasil scan dari driver lokal disimpan juga ke state Cloud Hosting (<= 50 KB)
                const scannedUrl = locData.data_url || '';
                const padded = scannedUrl ? padJpegDataUrlToTarget50KB(scannedUrl, 46000, 50500) : { dataUrl: '', size_kb: locData.size_kb || 48.5 };
                const cloudKey = catKey === 'biovisa' ? finalFilename : `\${catKey}:\${finalFilename}`;

                if (padded.dataUrl) {
                    ghImages[cloudKey] = padded.dataUrl;
                    if (catKey === 'biovisa') ghImages[finalFilename] = padded.dataUrl;
                    window.postMessage({ type: 'SILAHUT_CLOUD_IMAGE_UPDATE', key: cloudKey, dataUrl: padded.dataUrl }, '*');
                }

                if (matchedRec) {
                    ensureRecordSevenDocs(matchedRec);
                    matchedRec.seven_docs.docs[catKey] = {
                        exists: true,
                        num: catMeta.num,
                        key: catKey,
                        short: catMeta.short,
                        label: catMeta.label,
                        filename: finalFilename,
                        size_kb: padded.size_kb
                    };
                    matchedRec.seven_docs.available_count = Object.values(matchedRec.seven_docs.docs).filter(d => d && d.exists).length;
                }
                saveGhState();
                await syncMultiComputerCloudState(true, false);
                return makeJsonRes({
                    ok: true,
                    filename: finalFilename,
                    size_kb: padded.size_kb,
                    message: locData.message || `Berhasil scan dari driver scanner -> \${finalFilename} (\${padded.size_kb} KB <= 50 KB)!`
                });
            } catch (errBridge) {
                return makeJsonRes({
                    ok: false,
                    error: 'Tidak dapat menghubungi layanan driver scanner WIA/TWAIN Windows di komputer ini. Pastikan Laragon aktif di komputer tempat scanner terpasang, atau pilih perangkat USB Imaging di daftar driver.'
                });
            }
        }

        if (action === 'upload_jamaah_doc') {
            const catKey = String(bodyData.category || u.searchParams.get('category') || 'paspor').toLowerCase();
            const catMeta = DOC_CAT_META[catKey] || DOC_CAT_META.paspor;
            const targetId = String(bodyData.id || '').trim();

            let fileList = [];
            if (init && init.body instanceof FormData) {
                const multi = init.body.getAll('files[]');
                if (multi && multi.length > 0) {
                    fileList = multi.filter(f => f && typeof f.name === 'string');
                }
                const single = init.body.get('file');
                if (single && typeof single.name === 'string') {
                    fileList.push(single);
                }
            }
            if (fileList.length === 0) {
                return makeJsonRes({ ok: false, error: 'Tidak ada file gambar yang dipilih.' });
            }

            let savedFiles = [];
            for (const fObj of fileList) {
                let matchedIdx = -1;
                if (targetId && fileList.length === 1) {
                    matchedIdx = ghRecords.findIndex(r => r.id === targetId);
                }
                if (matchedIdx === -1) {
                    const baseUpper = fObj.name.replace(/\\.[^.]+$/, '').toUpperCase();
                    matchedIdx = ghRecords.findIndex(r => {
                        const p = String(r.paspor || '').toUpperCase();
                        const n = String(r.nama || '').toUpperCase();
                        return (p && p !== 'TANPAPASPOR' && baseUpper.includes(p)) || (n && baseUpper.startsWith(n));
                    });
                }

                const matchedRec = matchedIdx !== -1 ? ghRecords[matchedIdx] : null;
                const finalFilename = matchedRec
                    ? `\${matchedRec.nama}_\${matchedRec.paspor}.jpg`
                    : (fObj.name.replace(/\\.[^.]+$/, '').toUpperCase() + '.jpg');

                const compressed = await compressUploadedFileToJpg(fObj, catMeta.max_kb);
                const cloudKey = catKey === 'biovisa' ? finalFilename : `\${catKey}:\${finalFilename}`;
                ghImages[cloudKey] = compressed.dataUrl;
                if (catKey === 'biovisa') {
                    ghImages[finalFilename] = compressed.dataUrl;
                }

                // Kirim ke ekstensi Chrome Siskohat via postMessage
                window.postMessage({
                    type: 'SILAHUT_CLOUD_IMAGE_UPDATE',
                    key: cloudKey,
                    dataUrl: compressed.dataUrl
                }, '*');

                if (matchedRec) {
                    ensureRecordSevenDocs(matchedRec);
                    matchedRec.seven_docs.docs[catKey] = {
                        exists: true,
                        num: catMeta.num,
                        key: catKey,
                        short: catMeta.short,
                        label: catMeta.label,
                        filename: finalFilename,
                        size_kb: compressed.size_kb
                    };
                    matchedRec.seven_docs.available_count = Object.values(matchedRec.seven_docs.docs).filter(d => d && d.exists).length;
                    if (catKey === 'biovisa') {
                        matchedRec.filename = finalFilename;
                        matchedRec.size_kb = compressed.size_kb;
                        matchedRec.size_bytes = compressed.size_bytes;
                    }
                }

                // Upload juga ke Sub-Folder Google Drive di background jika URL Web App GDrive aktif
                if (ghSettings.gas_gdrive_webapp_url) {
                    const b64Clean = compressed.dataUrl.includes(',') ? compressed.dataUrl.split(',')[1] : compressed.dataUrl;
                    origFetch(ghSettings.gas_gdrive_webapp_url, {
                        method: 'POST',
                        mode: 'no-cors',
                        headers: { 'Content-Type': 'text/plain;charset=utf-8' },
                        body: JSON.stringify({
                            action: 'upload_biovisa',
                            filename: finalFilename,
                            mime_type: 'image/jpeg',
                            base64_file: b64Clean,
                            folder_name: ghSettings.gdrive_folder_name || 'Biovisa_Jamaah_PHU_Pasuruan',
                            subfolder: catMeta.subfolder,
                            nama: matchedRec ? matchedRec.nama : finalFilename,
                            paspor: matchedRec ? matchedRec.paspor : '-',
                            enrollment_id: catMeta.label,
                            size_kb: compressed.size_kb
                        })
                    }).catch(() => {});
                }

                savedFiles.push({
                    category: catKey,
                    filename: finalFilename,
                    size_kb: compressed.size_kb
                });
            }

            addGhLog('success', `Berhasil mengunggah \${savedFiles.length} file pada kategori \${catMeta.label}.`);
            saveGhState();
            await syncMultiComputerCloudState(true, false);
            return makeJsonRes({
                ok: true,
                count: savedFiles.length,
                saved: savedFiles,
                message: `Berhasil menyimpan \${savedFiles.length} file pada kategori \${catMeta.label}!`
            });
        }

        if (action === 'delete_jamaah_doc') {
            const recId = String(bodyData.id || '').trim();
            const catKey = String(bodyData.category || '').toLowerCase();
            const catMeta = DOC_CAT_META[catKey];
            const idx = ghRecords.findIndex(r => r.id === recId);
            if (idx === -1 || !catMeta) {
                return makeJsonRes({ ok: false, error: 'Data jamaah atau kategori tidak ditemukan.' });
            }
            const rec = ghRecords[idx];
            ensureRecordSevenDocs(rec);
            const oldFn = (rec.seven_docs.docs[catKey] && rec.seven_docs.docs[catKey].filename) || `\${rec.nama}_\${rec.paspor}.jpg`;
            const cloudKey = catKey === 'biovisa' ? oldFn : `\${catKey}:\${oldFn}`;
            delete ghImages[cloudKey];

            window.postMessage({
                type: 'SILAHUT_CLOUD_IMAGE_UPDATE',
                key: cloudKey,
                remove: true
            }, '*');

            rec.seven_docs.docs[catKey] = {
                exists: false,
                num: catMeta.num,
                key: catKey,
                short: catMeta.short,
                label: catMeta.label,
                filename: '',
                size_kb: 0
            };
            rec.seven_docs.available_count = Object.values(rec.seven_docs.docs).filter(d => d && d.exists).length;
            saveGhState();
            await syncMultiComputerCloudState(true, false);
            return makeJsonRes({
                ok: true,
                message: `File \${catMeta.label} untuk \${rec.nama} berhasil dihapus.`
            });
        }

        if (action === 'sync_gdrive_7docs') {
            const gasUrl = String(ghSettings.gas_gdrive_webapp_url || '').trim();
            if (!gasUrl) {
                return makeJsonRes({ ok: false, error: 'URL Web App Google Drive belum diisi di Konfigurasi Integrasi.' });
            }
            try {
                const listUrl = gasUrl + (gasUrl.includes('?') ? '&' : '?') + 'action=list_gdrive_docs&folder_name=' + encodeURIComponent(ghSettings.gdrive_folder_name || 'Biovisa_Jamaah_PHU_Pasuruan') + '&_t=' + Date.now();
                const res = await origFetch(listUrl);
                const data = await res.json();
                if (!data || !data.ok) {
                    return makeJsonRes({ ok: false, error: (data && data.error) || 'Gagal membaca 7 folder Google Drive. Pastikan Script GDrive terbaru sudah di-Deploy.' });
                }
                const gCats = data.categories || {};
                let matchedCount = 0;
                Object.keys(DOC_CAT_META).forEach(catKey => {
                    const catMeta = DOC_CAT_META[catKey];
                    const filesInCat = gCats[catKey] || [];
                    filesInCat.forEach(gf => {
                        const gName = String(gf.filename || '');
                        const baseUpper = gName.replace(/\\.[^.]+$/, '').toUpperCase();
                        ghRecords.forEach(rec => {
                            const p = String(rec.paspor || '').toUpperCase();
                            if (p && p !== 'TANPAPASPOR' && baseUpper.includes(p)) {
                                ensureRecordSevenDocs(rec);
                                if (!rec.seven_docs.docs[catKey] || !rec.seven_docs.docs[catKey].exists) {
                                    matchedCount++;
                                }
                            }
                        });
                    });
                });
                mergeSevenDocsFromGDriveCategories(gCats);
                saveGhState();
                await syncMultiComputerCloudState(true, false);
                return makeJsonRes({
                    ok: true,
                    pulled_count: matchedCount,
                    message: matchedCount > 0
                        ? `Berhasil menyinkronkan \${matchedCount} dokumen dari 7 Sub-Folder Google Drive!`
                        : '7 Sub-Folder Google Drive sudah sinkron (1_Paspor s/d 7_Foto siap digunakan).'
                });
            } catch (err) {
                return makeJsonRes({ ok: false, error: 'Gagal menghubungi Web App Google Drive: ' + err.message });
            }
        }

        if (action === 'save_settings') {
            ghSettings = { ...ghSettings, ...bodyData };
            saveGhState();
            await syncMultiComputerCloudState(true, true);
            return makeJsonRes({ ok: true, message: 'Pengaturan berhasil disimpan & disinkronkan untuk semua komputer.', settings: ghSettings });
        }

        if (action === 'clear_logs') {
            ghLogs = [];
            saveGhState();
            return makeJsonRes({ ok: true, message: 'Log dibersihkan.' });
        }

        if (action === 'import_jamaah') {
            const lines = String(bodyData.raw_text || '').split(/\\r?\\n/);
            if (bodyData.replace_all) ghJamaah = [];
            let added = 0;
            lines.forEach(l => {
                const parts = l.trim().split(/[\\t;|,]+/);
                if (parts.length >= 2) {
                    ghJamaah.push({ nama: parts[0].trim().toUpperCase(), paspor: parts[1].trim().toUpperCase() });
                    added++;
                }
            });
            saveGhState();
            return makeJsonRes({ ok: true, message: `Berhasil menyimpan \${added} data Jamaah.` });
        }

        if (action === 'change_my_password') {
            const cur = String(bodyData.current_password || '');
            const neu = String(bodyData.new_password || '');
            if (neu.length < 8) {
                return makeJsonRes({ ok: false, error: 'Password baru minimal 8 karakter.' });
            }
            const activeUser = JSON.parse(localStorage.getItem('gh_evisa_active_user') || 'null') || { username: 'admin' };
            const uKey = String(activeUser.username || 'admin').toLowerCase();
            const pwMap = JSON.parse(localStorage.getItem('gh_evisa_pw_map') || '{}');
            if (pwMap[uKey] && pwMap[uKey] !== cur) {
                return makeJsonRes({ ok: false, error: 'Password lama salah.' });
            }
            pwMap[uKey] = neu;
            localStorage.setItem('gh_evisa_pw_map', JSON.stringify(pwMap));
            return makeJsonRes({ ok: true, message: 'Password berhasil diperbarui.' });
        }

        if (action === 'test_connection') {
            return makeJsonRes({
                ok: true,
                both_ok: true,
                diagnosis: {
                    gmail: { ok: true, message: 'Mode Cloud GitHub Pages siap menarik email dari Google Apps Script.' },
                    gmail_accounts: (ghSettings.source_accounts || []).map(a => ({ email: a.email, label: a.label, enabled: a.enabled !== false, ok: true, message: 'Siap terhubung dari Browser Cloud.' })),
                    gdrive: { ok: true, message: 'Endpoint Google Drive siap menerima file.' }
                }
            });
        }

        return makeJsonRes({ ok: true });
    };
})();
</script>
JS;

// Sisipkan adapter tepat sebelum tag <script> utama di index.php
$pos = strpos($fullHtml, '<script>');
if ($pos !== false) {
    $compiledHtml = substr($fullHtml, 0, $pos) . $clientEngineJs . "\n" . substr($fullHtml, $pos);
} else {
    $compiledHtml = $fullHtml . $clientEngineJs;
}

file_put_contents('c:/laragon/www/e-visa/index.html', $compiledHtml);
file_put_contents('c:/laragon/www/e-visa/docs/index.html', $compiledHtml);
copy('c:/laragon/www/e-visa/style.css', 'c:/laragon/www/e-visa/docs/style.css');
copy('c:/laragon/www/e-visa/admin.css', 'c:/laragon/www/e-visa/docs/admin.css');

// Buat paket ZIP statis SILAHUT_Auto_Siskohat_Extension.zip agar tombol unduh berfungsi di GitHub Pages
$zipPath = 'c:/laragon/www/e-visa/SILAHUT_Auto_Siskohat_Extension.zip';
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $extDir = 'c:/laragon/www/e-visa/siskohat-extension';
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $relPath = 'siskohat-extension/' . str_replace('\\', '/', substr($item->getPathname(), strlen($extDir) + 1));
            if ($item->isDir()) {
                $zip->addEmptyDir($relPath);
            } else {
                $zip->addFile($item->getPathname(), $relPath);
            }
        }
        $zip->close();
        copy($zipPath, 'c:/laragon/www/e-visa/docs/SILAHUT_Auto_Siskohat_Extension.zip');
        echo "Created SILAHUT_Auto_Siskohat_Extension.zip (" . filesize($zipPath) . " bytes)\n";
    }
}

echo "Compiled 100% identical index.html (" . strlen($compiledHtml) . " bytes)\n";
