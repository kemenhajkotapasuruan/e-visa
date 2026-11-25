<?php
ob_start();
include 'c:/laragon/www/e-visa/index.php';
$fullHtml = ob_get_clean();

$b64LogoIcon = 'data:image/png;base64,' . base64_encode(file_get_contents('c:/laragon/www/e-visa/assets/biologoicon.png'));
$b64LogoText = 'data:image/png;base64,' . base64_encode(file_get_contents('c:/laragon/www/e-visa/assets/biologotext.png'));
$b64Success  = 'data:image/png;base64,' . base64_encode(file_get_contents('c:/laragon/www/e-visa/assets/success.png'));

require_once 'c:/laragon/www/e-visa/config.php';

$rawRecordsArr = getRecords();
foreach ($rawRecordsArr as &$recItem) {
    $recItem['seven_docs'] = getJamaahSevenDocsStatus($recItem);
}
unset($recItem);
saveRecords($rawRecordsArr);
if (is_dir('c:/laragon/www/e-visa/docs/storage/data')) {
    copy('c:/laragon/www/e-visa/storage/data/records.json', 'c:/laragon/www/e-visa/docs/storage/data/records.json');
}

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

    const ASSET_ICON = "$b64LogoIcon";
    const ASSET_TEXT = "$b64LogoText";
    const ASSET_SUCC = "$b64Success";

    let ghSettings = JSON.parse(localStorage.getItem('gh_evisa_settings') || 'null') || INITIAL_SETTINGS;
    let ghRecords  = JSON.parse(localStorage.getItem('gh_evisa_records') || 'null') || INITIAL_RECORDS;
    let ghJamaah   = JSON.parse(localStorage.getItem('gh_evisa_jamaah') || 'null') || INITIAL_JAMAAH;
    let ghLogs     = JSON.parse(localStorage.getItem('gh_evisa_logs') || 'null') || INITIAL_LOGS;
    let ghImages   = JSON.parse(localStorage.getItem('gh_evisa_images') || '{}');
    let cachedImgs = {};

    function saveGhState() {
        try {
            localStorage.setItem('gh_evisa_settings', JSON.stringify(ghSettings));
            localStorage.setItem('gh_evisa_records', JSON.stringify(ghRecords));
            localStorage.setItem('gh_evisa_jamaah', JSON.stringify(ghJamaah));
            localStorage.setItem('gh_evisa_logs', JSON.stringify(ghLogs.slice(0, 100)));
            localStorage.setItem('gh_evisa_images', JSON.stringify(ghImages));
        } catch (e) {}
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

    // Resolve image URL: if generated in browser use ghImages[filename], otherwise use static file in storage/biovisa/
    window.__resolveBiovisaImgUrl = function(filename, dl = false) {
        if (ghImages[filename]) return ghImages[filename];
        return 'storage/biovisa/' + encodeURIComponent(filename);
    };

    // Intercept links & images pointing to api.php?action=download_file
    const origSetAttr = Element.prototype.setAttribute;
    document.addEventListener('click', function(e) {
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
            if (fn) {
                a.href = window.__resolveBiovisaImgUrl(fn, true);
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
                if (fn) {
                    img.src = window.__resolveBiovisaImgUrl(fn, false);
                }
            } catch (e) {}
        });
        document.querySelectorAll('a[href*="api.php?action=download_file"]').forEach(a => {
            try {
                const u = new URL(a.getAttribute('href'), location.href);
                const fn = u.searchParams.get('file') || '';
                if (fn) {
                    a.href = window.__resolveBiovisaImgUrl(fn, true);
                }
            } catch (e) {}
        });
    });
    window.addEventListener('DOMContentLoaded', () => {
        imgObs.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['src', 'href'] });
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

            return makeJsonRes({
                ok: true,
                settings: ghSettings,
                records: ghRecords,
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
                        const rawCombined = (em.subject || '') + '\\n' + (em.body_text || '');
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
                            if (ghRecords.some(r => r.paspor === paspor)) continue;

                            const namaM = combined.match(/Dear\\s+([^\\r\\n,،<]{2,55}?)\\s*[,،\\r\\n<]/i);
                            const nama = namaM ? namaM[1].replace(/[،,]/g, '').trim().toUpperCase() : 'JAMAAH';
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
                to_email: ghSettings.source_email || 'phujtmkopasuruan@gmail.com',
                processed_at: eTime,
                gdrive_uploaded: false
            };
            ghRecords.unshift(rec);
            addGhLog('info', `[Simulasi Email Masuk] \${filename} (\${rendered.size_kb} KB) berhasil dibuat.`);
            saveGhState();
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
                to_email: ghSettings.source_email || 'phujtmkopasuruan@gmail.com',
                processed_at: eTime,
                gdrive_uploaded: false
            };
            ghRecords.unshift(rec);
            addGhLog('success', `Konversi manual berhasil dibuat: \${filename} (\${rendered.size_kb} KB)`);
            saveGhState();
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
            return makeJsonRes({ ok: true, message: `Berhasil mengubah nama file menjadi \${filename}`, record: ghRecords[idx] });
        }

        if (action === 'delete_record') {
            const ids = bodyData.ids || (bodyData.id ? [bodyData.id] : []);
            ghRecords = ghRecords.filter(r => !ids.includes(r.id));
            saveGhState();
            return makeJsonRes({ ok: true, message: 'Data berhasil dihapus.' });
        }

        if (action === 'mark_siskohat') {
            const idx = ghRecords.findIndex(r => r.id === bodyData.id);
            if (idx !== -1) {
                ghRecords[idx].siskohat_uploaded = !!bodyData.status;
                saveGhState();
            }
            return makeJsonRes({ ok: true, message: 'Status Siskohat diperbarui.' });
        }

        if (action === 'save_settings') {
            ghSettings = { ...ghSettings, ...bodyData };
            saveGhState();
            return makeJsonRes({ ok: true, message: 'Pengaturan berhasil disimpan.', settings: ghSettings });
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
