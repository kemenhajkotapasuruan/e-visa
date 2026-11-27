<?php
/**
 * API Endpoint Utama Aplikasi Auto-BioVisa PHU Pasuruan
 * Menangani:
 * - Sinkronisasi otomatis email Mofa Enrollment Confirmation dari phujtmkopasuruan@gmail.com
 * - Webhook real-time dari Google Apps Script saat ada email baru masuk
 * - Konversi & kompresi otomatis ke JPG <= 50KB dengan format Nama_Nomor paspor.jpg
 * - Upload otomatis ke Google Drive muchammad.ali.sanjaya@gmail.com
 * - Simulasi email masuk, upload manual, edit nama/paspor, import master jamaah, & download ZIP
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/BiovisaProcessor.php';
require_once __DIR__ . '/includes/GmailImapClient.php';
require_once __DIR__ . '/includes/GDriveSync.php';
require_once __DIR__ . '/includes/ScannerBridge.php';

// Izinkan akses CORS untuk Ekstensi Chrome Siskohat Gen.2
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Evisa-Token, Authorization, X-Webhook-Token');
header('Access-Control-Allow-Private-Network: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

require_once __DIR__ . '/includes/Auth.php';
Auth::init();

$lembagaSlug = trim((string)($_GET['lembaga'] ?? $_POST['lembaga'] ?? ''));
$tokenActions = [
    'ping_server',
    'siskohat_queue',
    'siskohat_get_file',
    'siskohat_get_all_docs',
    'mark_siskohat',
    'siskohat_dom_debug',
];
$webhookActions = ['webhook_incoming'];

if (in_array($action, $webhookActions, true)) {
    $wh = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''));
    if (!Auth::verifyWebhookToken($wh)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Webhook token tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($lembagaSlug !== '') {
        Auth::bootstrapTenantBySlug($lembagaSlug);
    }
} elseif (in_array($action, $tokenActions, true)) {
    if (!Auth::verifyApiToken(Auth::extractBearerOrHeaderToken())) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'API token tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($lembagaSlug !== '') {
        Auth::bootstrapTenantBySlug($lembagaSlug);
    }
} elseif ($action !== '') {
    Auth::requireLogin();
    if ($lembagaSlug !== '' && (Auth::user()['role'] ?? '') === Auth::ROLE_SUPER) {
        Auth::setActiveLembagaSlug($lembagaSlug);
    }
}

if ($action === 'ping_server') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'service' => 'SILAHUT_EVISA_PASURUAN',
        'hostname' => gethostname() ?: 'KEMENHAJ_KOPAS',
        'time' => date('Y-m-d H:i:s')
    ]);
    exit;
}

if ($action === 'download_siskohat_ext') {
    $extDir = BASE_DIR . DIRECTORY_SEPARATOR . 'siskohat-extension';
    $zipFilename = 'SILAHUT_Auto_Siskohat_Extension.zip';
    $zipPath = getTempDir() . DIRECTORY_SEPARATOR . $zipFilename;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo "Gagal membuat paket ekstensi ZIP.";
        exit;
    }

    // Deteksi URL server saat ini agar komputer lain yang mengunduh ZIP langsung terarah ke IP server ini
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/e-visa/api.php')), '/');
    $detectedApiUrl = "{$scheme}://{$host}{$scriptDir}/api.php";

    foreach (glob($extDir . DIRECTORY_SEPARATOR . '*') as $file) {
        if (is_file($file)) {
            $bname = basename($file);
            if ($bname === 'background.js') {
                $bgContent = file_get_contents($file);
                $bgContent = str_replace(
                    "const DEFAULT_API_BASE = 'http://localhost/e-visa/api.php';",
                    "const DEFAULT_API_BASE = '{$detectedApiUrl}';",
                    $bgContent
                );
                $zip->addFromString($bname, $bgContent);
            } else {
                $zip->addFile($file, $bname);
            }
        }
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($zipPath));
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// Endpoint khusus download file JPG atau ZIP (bukan JSON)
if ($action === 'download_file') {
    $cat  = preg_replace('/[^a-z_]/', '', strtolower(trim((string)($_GET['cat'] ?? 'biovisa'))));
    $file = basename($_GET['file'] ?? '');
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($_GET['lembaga'] ?? ''))));
    $path = '';

    if ($slug !== '' && !Auth::isSuperAdmin()) {
        // Admin hanya boleh mengunduh file milik lembaganya sendiri.
        $own = Auth::tenantSlug();
        if ($slug !== $own) {
            http_response_code(403);
            echo "Akses ditolak.";
            exit;
        }
    }

    if ($slug === '') {
        $slug = Auth::tenantSlug();
    }
    $tenantRoot = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga' . DIRECTORY_SEPARATOR . $slug;
    $tenantBiovisa = $tenantRoot . DIRECTORY_SEPARATOR . 'biovisa';
    $catFolder = getSiskohatDocCategories()[$cat]['folder'] ?? $cat;
    $tenantCatDir = $tenantRoot . DIRECTORY_SEPARATOR . 'dokumen' . DIRECTORY_SEPARATOR . $catFolder;

    if ($cat !== '' && $cat !== 'biovisa') {
        $path = $tenantCatDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) && $slug === 'phu-pasuruan') {
            $path = DOKUMEN_DIR . DIRECTORY_SEPARATOR . $catFolder . DIRECTORY_SEPARATOR . $file;
        }
        if (!is_file($path) && $file !== '') {
            $pasporGuess = '';
            if (preg_match('/_([A-Z0-9]{6,12})\.(?:jpe?g|png|webp)$/i', $file, $m)) {
                $pasporGuess = $m[1];
            } elseif (preg_match('/^([A-Z0-9]{6,12})\.(?:jpe?g|png|webp)$/i', $file, $m)) {
                $pasporGuess = $m[1];
            }
            $foundDoc = findJamaahDocFile($cat, $pasporGuess, '', $file, $slug);
            if ($foundDoc && !empty($foundDoc['path']) && is_file($foundDoc['path'])) {
                $path = $foundDoc['path'];
            }
        }
    } else {
        $path = $tenantBiovisa . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            $path = $tenantCatDir . DIRECTORY_SEPARATOR . $file;
        }
        if (!is_file($path) && $slug === 'phu-pasuruan') {
            $path = BIOVISA_DIR . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                $path = DOKUMEN_DIR . DIRECTORY_SEPARATOR . 'biovisa' . DIRECTORY_SEPARATOR . $file;
            }
        }
        if (!is_file($path) && $file !== '') {
            // Fallback ke dokumen paspor / kategori lain jika jamaah belum punya file biovisa
            $pasporGuess = '';
            if (preg_match('/_([A-Z0-9]{6,12})\.(?:jpe?g|png|webp)$/i', $file, $m)) {
                $pasporGuess = $m[1];
            }
            foreach (array_keys(getSiskohatDocCategories()) as $fallbackCat) {
                $foundDoc = findJamaahDocFile($fallbackCat, $pasporGuess, '', $file, $slug);
                if ($foundDoc && !empty($foundDoc['path']) && is_file($foundDoc['path'])) {
                    $path = $foundDoc['path'];
                    break;
                }
            }
        }
    }

    if ($file === '' || !is_file($path)) {
        http_response_code(404);
        echo "File tidak ditemukan.";
        exit;
    }
    $viewInline = !empty($_GET['inline']);
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($viewInline ? 'inline' : 'attachment') . '; filename="' . $file . '"');
    header('Cache-Control: no-cache, must-revalidate');
    readfile($path);
    exit;
}

/**
 * Helper: Ekstrak tanggal YYYY-MM-DD dari record Biovisa
 */
function getRecordDateYmd(array $rec, string $basis = 'enrollment'): string
{
    if ($basis === 'processed') {
        $raw = trim((string)($rec['processed_at'] ?? ''));
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            return $m[1];
        }
    }

    $enroll = trim((string)($rec['enrollment_time'] ?? ''));
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $enroll, $m)) {
        return $m[1];
    }

    $emailDate = trim((string)($rec['email_date'] ?? ''));
    if ($emailDate !== '') {
        $cleanDate = trim(str_ireplace('WIB', '', $emailDate));
        $ts = strtotime($cleanDate);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }

    $proc = trim((string)($rec['processed_at'] ?? ''));
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $proc, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * Helper: Bersihkan format email sumber dari record
 */
function getRecordSourceEmail(array $rec): string
{
    $raw = trim((string)($rec['source_account_email'] ?? ($rec['to_email'] ?? '')));
    if (preg_match('/<([^>]+)>/', $raw, $m)) {
        $raw = $m[1];
    }
    return strtolower(trim(str_replace(['<', '>'], '', $raw)));
}

/**
 * Helper: Filter records berdasarkan parameter GET date_from, date_to, date_basis, gdrive_filter, source_email, q
 */
function filterRecordsByQueryParams(array $records): array
{
    $dateFrom     = trim((string)($_GET['date_from'] ?? ''));
    $dateTo       = trim((string)($_GET['date_to'] ?? ''));
    $dateBasis    = trim((string)($_GET['date_basis'] ?? 'enrollment'));
    $gdriveFilter = trim((string)($_GET['gdrive_filter'] ?? 'all'));
    $sourceFilter = strtolower(trim((string)($_GET['source_email'] ?? '')));
    $q            = mb_strtolower(trim((string)($_GET['q'] ?? '')));

    if ($dateFrom === '' && $dateTo === '' && $gdriveFilter === 'all' && $sourceFilter === '' && $q === '') {
        return $records;
    }

    $filtered = [];
    foreach ($records as $r) {
        if ($gdriveFilter === 'gdrive_ok' && empty($r['gdrive_uploaded'])) {
            continue;
        }
        if ($gdriveFilter === 'gdrive_pending' && !empty($r['gdrive_uploaded'])) {
            continue;
        }
        if ($sourceFilter !== '' && getRecordSourceEmail($r) !== $sourceFilter) {
            continue;
        }

        if ($dateFrom !== '' || $dateTo !== '') {
            $recDate = getRecordDateYmd($r, $dateBasis);
            if ($recDate === '') {
                continue;
            }
            if ($dateFrom !== '' && strcmp($recDate, $dateFrom) < 0) {
                continue;
            }
            if ($dateTo !== '' && strcmp($recDate, $dateTo) > 0) {
                continue;
            }
        }

        if ($q !== '') {
            $haystack = mb_strtolower(
                ($r['nama'] ?? '') . ' ' .
                ($r['paspor'] ?? '') . ' ' .
                ($r['filename'] ?? '') . ' ' .
                ($r['enrollment_id'] ?? '')
            );
            if (mb_strpos($haystack, $q) === false) {
                continue;
            }
        }

        $filtered[] = $r;
    }

    return $filtered;
}

if ($action === 'download_zip') {
    $records = filterRecordsByQueryParams(getRecords());
    if (empty($records)) {
        http_response_code(404);
        echo "Tidak ada file Biovisa pada rentang tanggal/filter yang dipilih.";
        exit;
    }

    $dateSuffix = '';
    if (!empty($_GET['date_from']) || !empty($_GET['date_to'])) {
        $df = preg_replace('/[^0-9\-]/', '', $_GET['date_from'] ?? '');
        $dt = preg_replace('/[^0-9\-]/', '', $_GET['date_to'] ?? '');
        $dateSuffix = '_' . ($df === $dt ? $df : trim($df . '_sd_' . $dt, '_'));
    }

    $zipFilename = 'Biovisa_PHU_Pasuruan' . $dateSuffix . '_' . date('Ymd_His') . '.zip';
    $zipPath = getTempDir() . DIRECTORY_SEPARATOR . $zipFilename;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo "Gagal membuat arsip ZIP.";
        exit;
    }

    $added = 0;
    foreach ($records as $rec) {
        $fn = $rec['filename'] ?? '';
        $fp = getBiovisaDir() . DIRECTORY_SEPARATOR . $fn;
        if ($fn !== '' && file_exists($fp)) {
            $zip->addFile($fp, $fn);
            $added++;
        }
    }
    $zip->close();

    if ($added === 0 || !file_exists($zipPath)) {
        http_response_code(404);
        echo "Tidak ada file JPG yang ditemukan.";
        exit;
    }

    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($zipPath));
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

if ($action === 'export_csv') {
    $records = filterRecordsByQueryParams(getRecords());
    $dateSuffix = '';
    if (!empty($_GET['date_from']) || !empty($_GET['date_to'])) {
        $df = preg_replace('/[^0-9\-]/', '', $_GET['date_from'] ?? '');
        $dt = preg_replace('/[^0-9\-]/', '', $_GET['date_to'] ?? '');
        $dateSuffix = '_' . ($df === $dt ? $df : trim($df . '_sd_' . $dt, '_'));
    }
    $csvFilename = 'Rekap_Biovisa_PHU_Pasuruan' . $dateSuffix . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $csvFilename . '"');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM agar Microsoft Excel langsung membaca kolom dengan rapi
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'No',
        'Nama Jamaah',
        'Nomor Paspor',
        'MOFA Enrollment ID',
        'Asal Sumber Email',
        'Waktu Enrollment (MOFA)',
        'Nama File (JPG <= 50KB)',
        'Ukuran File (KB)',
        'Status Google Drive',
        'Link Google Drive',
        'Waktu Diproses'
    ]);

    foreach ($records as $idx => $rec) {
        $srcEmail = getRecordSourceEmail($rec) ?: 'phujtmkopasuruan@gmail.com';
        $srcLabel = trim((string)($rec['source_account_label'] ?? ''));
        $srcDisplay = ($srcLabel !== '' && strcasecmp($srcLabel, $srcEmail) !== 0)
            ? "{$srcLabel} ({$srcEmail})"
            : $srcEmail;

        fputcsv($out, [
            $idx + 1,
            $rec['nama'] ?? '',
            $rec['paspor'] ?? '',
            $rec['enrollment_id'] ?? '',
            $srcDisplay,
            $rec['enrollment_time'] ?? ($rec['email_date'] ?? ''),
            $rec['filename'] ?? '',
            $rec['size_kb'] ?? '',
            !empty($rec['gdrive_uploaded']) ? 'Tersimpan di GDrive' : 'Lokal (Belum Upload)',
            $rec['gdrive_url'] ?? '',
            $rec['processed_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Semua respons lainnya berupa JSON
header('Content-Type: application/json; charset=utf-8');

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Helper: Simpan / perbarui record hasil proses email & upload ke GDrive bila aktif
 */
function upsertAndSyncRecord(array $processedRecord, array $settings): array
{
    $records = getRecords();

    // Upload ke Google Drive muchammad.ali.sanjaya@gmail.com jika auto_upload_gdrive aktif dan URL tersedia
    if (!empty($settings['auto_upload_gdrive']) && !empty($settings['gas_gdrive_webapp_url'])) {
        $driveRes = GDriveSync::uploadFileToDrive($processedRecord, $settings);
        if ($driveRes['ok']) {
            $processedRecord['gdrive_uploaded'] = true;
            $processedRecord['gdrive_file_id'] = $driveRes['file_id'];
            $processedRecord['gdrive_url'] = $driveRes['file_url'];
            $processedRecord['gdrive_error'] = '';
            addSyncLog('success', "File {$processedRecord['filename']} ({$processedRecord['size_kb']} KB) berhasil diunggah ke GDrive {$settings['target_gdrive_email']}.");
        } else {
            $processedRecord['gdrive_uploaded'] = false;
            $processedRecord['gdrive_error'] = $driveRes['error'];
            addSyncLog('warning', "File {$processedRecord['filename']} tersimpan lokal, namun gagal upload ke GDrive: {$driveRes['error']}");
        }
    }

    // Cek apakah paspor atau message_id sudah ada, jika ada update dan gabungkan all_message_ids
    $updated = false;
    foreach ($records as $idx => $existing) {
        if (
            ($existing['paspor'] !== 'TANPAPASPOR' && $existing['paspor'] === $processedRecord['paspor']) ||
            ($existing['message_id'] === $processedRecord['message_id'])
        ) {
            if (!empty($existing['filename']) && $existing['filename'] !== $processedRecord['filename']) {
                $oldPath = getBiovisaDir() . DIRECTORY_SEPARATOR . $existing['filename'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $allMsgIds = $existing['all_message_ids'] ?? [];
            if (!empty($existing['message_id'])) {
                $allMsgIds[] = $existing['message_id'];
            }
            if (!empty($processedRecord['message_id'])) {
                $allMsgIds[] = $processedRecord['message_id'];
            }
            $processedRecord['all_message_ids'] = array_values(array_unique($allMsgIds));
            if (!empty($existing['siskohat_uploaded'])) {
                $processedRecord['siskohat_uploaded'] = true;
                $processedRecord['siskohat_uploaded_at'] = $existing['siskohat_uploaded_at'] ?? '';
            }
            $records[$idx] = $processedRecord;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $processedRecord['all_message_ids'] = !empty($processedRecord['message_id']) ? [$processedRecord['message_id']] : [];
        array_unshift($records, $processedRecord);
    }

    saveRecords($records);
    return $processedRecord;
}

// Routing Action
switch ($action) {
    case 'status':
        $settings = getSettings();
        $isSuper = Auth::isSuperAdmin();
        $records  = $isSuper ? getAllTenantsRecords() : getRecords();
        $jamaah   = getJamaahMaster();
        $logs     = loadJsonFile(getLogsFile(), []);

        // Untuk Super Admin: hitung kelengkapan dokumen per tenant aktif (konteks landing)
        $currentSlug = Auth::tenantSlug();

        $totalRecords = count($records);
        $uploadedGdrive = 0;
        $under50KbCount = 0;
        $totalKb = 0;

        foreach ($records as &$r) {
            $slug = (string)($r['lembaga_slug'] ?? $currentSlug);
            $r['lembaga_slug'] = $slug;
            $biovisaDir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga' . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'biovisa';
            $fp = $biovisaDir . DIRECTORY_SEPARATOR . ($r['filename'] ?? '');
            $r['file_exists'] = ($r['filename'] ?? '') !== '' && file_exists($fp);
            if ($r['file_exists']) {
                $r['size_bytes'] = filesize($fp);
                $r['size_kb'] = round($r['size_bytes'] / 1024, 2);
            }
            $r['lembaga_nama'] = getLembagaNameBySlug($slug);
            if (!empty($r['gdrive_uploaded'])) {
                $uploadedGdrive++;
            }
            $biovisaMaxKb = floatval(getSiskohatDocCategories()['biovisa']['max_kb'] ?? 100.0);
            if (($r['size_kb'] ?? 0) <= $biovisaMaxKb) {
                $under50KbCount++;
            }
            $totalKb += ($r['size_kb'] ?? 0);
            $r['seven_docs'] = getJamaahSevenDocsStatus($r, $slug);
        }
        unset($r);

        // Hitung progres terhadap Master Jamaah jika ada
        $processedPassports = [];
        foreach ($records as $rec) {
            $p = strtoupper(trim($rec['paspor'] ?? ''));
            if ($p !== '') {
                $processedPassports[$p] = $rec;
            }
        }

        $jamaahWithStatus = [];
        $jamaahDoneCount = 0;
        foreach ($jamaah as $j) {
            $p = strtoupper(trim($j['paspor'] ?? ''));
            $done = isset($processedPassports[$p]);
            if ($done) {
                $jamaahDoneCount++;
            }
            $jamaahWithStatus[] = [
                'nama' => $j['nama'] ?? '',
                'paspor' => $p,
                'kloter' => $j['kloter'] ?? '',
                'sudah_biovisa' => $done,
                'filename' => $done ? $processedPassports[$p]['filename'] : '',
                'processed_at' => $done ? $processedPassports[$p]['processed_at'] : ''
            ];
        }

        jsonResponse([
            'ok' => true,
            'settings' => $settings,
            'records' => $records,
            'is_super_admin' => $isSuper,
            'active_lembaga' => Auth::getLembagaBySlug($currentSlug),
            'lembaga_list' => Auth::getLembagaList(),
            'doc_categories' => array_values(getSiskohatDocCategories()),
            'docs_root_path' => getDokumenDir(),
            'jamaah' => $jamaahWithStatus,
            'logs' => array_slice($logs, 0, 40),
            'stats' => [
                'total_biovisa' => $totalRecords,
                'uploaded_gdrive' => $uploadedGdrive,
                'pending_gdrive' => max(0, $totalRecords - $uploadedGdrive),
                'valid_50kb_count' => $under50KbCount,
                'avg_size_kb' => $totalRecords > 0 ? round($totalKb / $totalRecords, 2) : 0,
                'total_master_jamaah' => count($jamaah),
                'master_done' => $jamaahDoneCount,
                'master_pending' => max(0, count($jamaah) - $jamaahDoneCount)
            ]
        ]);
        break;

    case 'sync_emails':
        $settings = getSettings();
        $records = getRecords();
        $jamaah = getJamaahMaster();

        $existingMsgIds = [];
        $existingPassportIdx = [];
        foreach ($records as $rIdx => $recItem) {
            $recNama = strtoupper(trim((string)($recItem['nama'] ?? '')));
            $needsNameRepair = ($recNama === '' || $recNama === 'JAMAAH');
            if (!$needsNameRepair) {
                if (!empty($recItem['message_id'])) {
                    $existingMsgIds[] = $recItem['message_id'];
                }
                if (!empty($recItem['all_message_ids']) && is_array($recItem['all_message_ids'])) {
                    foreach ($recItem['all_message_ids'] as $mId) {
                        $existingMsgIds[] = $mId;
                    }
                }
            }
            $pKey = strtoupper(trim((string)($recItem['paspor'] ?? '')));
            if ($pKey !== '' && $pKey !== 'TANPAPASPOR') {
                $existingPassportIdx[$pKey] = $rIdx;
            }
        }
        $existingMsgIds = array_values(array_unique($existingMsgIds));

        $fetchRes = GmailFetcher::fetchEmails($settings, $existingMsgIds);

        if (!$fetchRes['ok']) {
            saveSettings([
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_sync_status' => 'Gagal: ' . $fetchRes['error']
            ]);
            addSyncLog('error', $fetchRes['error']);
            jsonResponse([
                'ok' => false,
                'error' => $fetchRes['error'],
                'new_records' => []
            ]);
        }

        $newRecords = [];
        $recordsModified = false;
        foreach ($fetchRes['emails'] as $emailItem) {
            // Cek dulu apakah nomor paspor dari email ini sudah ada di daftar dashboard
            $preInfo = BiovisaProcessor::extractEmailInfo(
                (string)($emailItem['subject'] ?? ''),
                (string)($emailItem['body_text'] ?? ''),
                (string)($emailItem['body_html'] ?? ''),
                $jamaah
            );
            $prePaspor = strtoupper(trim((string)($preInfo['paspor'] ?? 'TANPAPASPOR')));
            if ($prePaspor === '' || $prePaspor === 'TANPAPASPOR') {
                continue;
            }

            // Jika paspor sudah ada di dashboard, file JPG-nya sudah ada, dan namanya BUKAN 'JAMAAH', cukup catat message_id-nya
            if (isset($existingPassportIdx[$prePaspor])) {
                $eIdx = $existingPassportIdx[$prePaspor];
                $eNama = strtoupper(trim((string)($records[$eIdx]['nama'] ?? '')));
                $eFile = getBiovisaDir() . DIRECTORY_SEPARATOR . ($records[$eIdx]['filename'] ?? '');
                if ($eNama !== '' && $eNama !== 'JAMAAH' && is_file($eFile)) {
                    $msgId = (string)($emailItem['message_id'] ?? '');
                    if ($msgId !== '') {
                        $allIds = $records[$eIdx]['all_message_ids'] ?? [];
                        if (!empty($records[$eIdx]['message_id'])) {
                            $allIds[] = $records[$eIdx]['message_id'];
                        }
                        $allIds[] = $msgId;
                        $records[$eIdx]['all_message_ids'] = array_values(array_unique($allIds));
                        $recordsModified = true;
                    }
                    continue;
                }
            }

            $processed = BiovisaProcessor::processIncomingEmail($emailItem, $settings, $jamaah);
            if (($processed['paspor'] ?? 'TANPAPASPOR') === 'TANPAPASPOR') {
                $badFile = getBiovisaDir() . DIRECTORY_SEPARATOR . ($processed['filename'] ?? '');
                if (!empty($processed['filename']) && file_exists($badFile)) {
                    @unlink($badFile);
                }
                continue;
            }
            if ($recordsModified) {
                saveRecords($records);
                $recordsModified = false;
            }
            $saved = upsertAndSyncRecord($processed, $settings);
            $records = getRecords();
            foreach ($records as $rIdx => $recItem) {
                $pKey = strtoupper(trim((string)($recItem['paspor'] ?? '')));
                if ($pKey !== '' && $pKey !== 'TANPAPASPOR') {
                    $existingPassportIdx[$pKey] = $rIdx;
                }
            }
            $newRecords[] = $saved;
            addSyncLog(
                'success',
                "Email Enrollment Confirmation diproses -> {$saved['filename']} ({$saved['size_kb']} KB)"
            );
        }
        // Perbaiki otomatis record lama yang masih bernama 'JAMAAH' menggunakan body_excerpt yang tersimpan
        $records = getRecords();
        foreach ($records as $rIdx => $recItem) {
            $curNama = strtoupper(trim((string)($recItem['nama'] ?? '')));
            $curPaspor = strtoupper(trim((string)($recItem['paspor'] ?? '')));
            if (($curNama === '' || $curNama === 'JAMAAH') && $curPaspor !== '' && $curPaspor !== 'TANPAPASPOR') {
                $reExtracted = BiovisaProcessor::extractEmailInfo(
                    (string)($recItem['subject'] ?? ''),
                    (string)($recItem['body_excerpt'] ?? ''),
                    '',
                    $jamaah
                );
                $fixedNama = strtoupper(trim((string)($reExtracted['nama'] ?? '')));
                if ($fixedNama !== '' && $fixedNama !== 'JAMAAH') {
                    $oldFn = (string)($recItem['filename'] ?? '');
                    $newFn = BiovisaProcessor::buildFilename($fixedNama, $curPaspor, $settings);
                    $newPath = getBiovisaDir() . DIRECTORY_SEPARATOR . $newFn;
                    $gd = BiovisaProcessor::renderEmailConfirmationToGd([
                        'nama' => $fixedNama,
                        'paspor' => $curPaspor,
                        'enrollment_id' => $recItem['enrollment_id'] ?? $reExtracted['enrollment_id'],
                        'enrollment_time' => $recItem['enrollment_time'] ?? $reExtracted['enrollment_time'],
                        'body_text' => $recItem['body_excerpt'] ?? ''
                    ]);
                    $minKB = floatval($settings['target_min_kb'] ?? 42.0);
                    $maxKB = min(49.5, floatval($settings['target_max_kb'] ?? 49.5));
                    $savedJpg = BiovisaProcessor::saveGdAs50KbJpg($gd, $newPath, $minKB, $maxKB, !empty($settings['strict_50kb_padding']));
                    imagedestroy($gd);

                    if ($oldFn !== '' && $oldFn !== $newFn) {
                        $oldPath = getBiovisaDir() . DIRECTORY_SEPARATOR . $oldFn;
                        if (is_file($oldPath)) {
                            @unlink($oldPath);
                        }
                        foreach (array_keys(getSiskohatDocCategories()) as $cKey) {
                            if ($cKey === 'biovisa') continue;
                            $cDir = getDocCategoryDir($cKey);
                            $oldDocPath = $cDir . DIRECTORY_SEPARATOR . $oldFn;
                            $newDocPath = $cDir . DIRECTORY_SEPARATOR . $newFn;
                            if (is_file($oldDocPath) && !is_file($newDocPath)) {
                                @rename($oldDocPath, $newDocPath);
                            }
                        }
                    }

                    $records[$rIdx]['nama'] = $fixedNama;
                    $records[$rIdx]['filename'] = $newFn;
                    $records[$rIdx]['size_bytes'] = $savedJpg['size_bytes'];
                    $records[$rIdx]['size_kb'] = $savedJpg['size_kb'];
                    $recordsModified = true;
                    $newRecords[] = $records[$rIdx];
                }
            }
        }
        if ($recordsModified) {
            saveRecords($records);
        }
        if (count($newRecords) > 0) {
            triggerBackgroundGitHubPush('Auto-sync ' . count($newRecords) . ' Biovisa baru ke GitHub Pages');
        } else {
            writeCloudSyncBundle();
        }

        $statusMsg = count($newRecords) > 0
            ? 'Berhasil memproses ' . count($newRecords) . ' Biovisa baru'
            : 'Sinkronisasi berhasil (Tidak ada email baru)';

        saveSettings([
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_sync_status' => $statusMsg
        ]);

        jsonResponse([
            'ok' => true,
            'message' => $statusMsg,
            'new_count' => count($newRecords),
            'new_records' => $newRecords
        ]);
        break;

    case 'webhook_incoming':
        // Endpoint penerima push otomatis dari Google Apps Script di phujtmkopasuruan@gmail.com
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $settings = getSettings();
        $jamaah = getJamaahMaster();

        $emailsToProcess = [];
        if (isset($payload['emails']) && is_array($payload['emails'])) {
            $emailsToProcess = $payload['emails'];
        } elseif (!empty($payload['subject']) || !empty($payload['body_text']) || !empty($payload['body_html'])) {
            $emailsToProcess = [$payload];
        }

        if (empty($emailsToProcess)) {
            jsonResponse(['ok' => false, 'error' => 'Payload email kosong.'], 400);
        }

        $created = [];
        foreach ($emailsToProcess as $em) {
            $processed = BiovisaProcessor::processIncomingEmail($em, $settings, $jamaah);
            $saved = upsertAndSyncRecord($processed, $settings);
            $created[] = $saved;
            addSyncLog('success', "[Webhook Auto] Email baru dikonversi -> {$saved['filename']} ({$saved['size_kb']} KB)");
        }

        saveSettings([
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_sync_status' => 'Webhook menerima ' . count($created) . ' Biovisa baru'
        ]);

        jsonResponse([
            'ok' => true,
            'processed_count' => count($created),
            'records' => $created
        ]);
        break;

    case 'manual_create':
        // Proses dari form input / paste isi email / upload file gambar biovisa
        $settings = getSettings();
        $jamaah = getJamaahMaster();

        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $subject = trim($payload['subject'] ?? 'Mofa Enrollment Confirmation');
        $bodyText = trim($payload['body_text'] ?? '');
        $overrideNama = trim($payload['nama'] ?? '');
        $overridePaspor = trim($payload['paspor'] ?? '');
        $overrideEnrollment = trim($payload['enrollment_id'] ?? '');

        $attachments = [];

        // Cek jika ada upload file lewat multipart/form-data
        if (!empty($_FILES['attachment']['tmp_name']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
            $rawBytes = file_get_contents($_FILES['attachment']['tmp_name']);
            $attachments[] = [
                'filename' => $_FILES['attachment']['name'] ?? 'upload.jpg',
                'raw' => $rawBytes
            ];
        } elseif (!empty($payload['attachment_base64'])) {
            $b64 = $payload['attachment_base64'];
            if (strpos($b64, ',') !== false) {
                $b64 = explode(',', $b64, 2)[1];
            }
            $attachments[] = [
                'filename' => $payload['attachment_name'] ?? 'upload.jpg',
                'base64' => $b64
            ];
        }

        $emailData = [
            'message_id' => 'manual_' . uniqid(),
            'subject' => $subject,
            'body_text' => $bodyText,
            'from' => 'MofaBioApp@mofa.gov.sa',
            'to' => $settings['source_email'],
            'date' => date('d M Y, H:i:s') . ' WIB',
            'override_nama' => $overrideNama,
            'override_paspor' => $overridePaspor,
            'override_enrollment_id' => $overrideEnrollment,
            'attachments' => $attachments
        ];

        $processed = BiovisaProcessor::processIncomingEmail($emailData, $settings, $jamaah);
        $saved = upsertAndSyncRecord($processed, $settings);
        addSyncLog('success', "Konversi Biovisa berhasil dibuat: {$saved['filename']} ({$saved['size_kb']} KB)");

        jsonResponse([
            'ok' => true,
            'message' => "File {$saved['filename']} ({$saved['size_kb']} KB) berhasil dibuat!",
            'record' => $saved
        ]);
        break;

    case 'simulate_email':
        // Simulasi email masuk dari Mofa Enrollment Confirmation untuk uji coba instan
        $settings = getSettings();
        $jamaah = getJamaahMaster();

        $samples = [
            ['nama' => 'MUCHAMMAD ALI SANJAYA', 'paspor' => 'E8492015', 'enroll' => '94827163'],
            ['nama' => 'SITI FATIMAH AZZAHRA', 'paspor' => 'C7381924', 'enroll' => '83716254'],
            ['nama' => 'ACHMAD FAUZI RAHMAN', 'paspor' => 'E5192837', 'enroll' => '72918365'],
            ['nama' => 'NURUL HIDAYATI PASURUAN', 'paspor' => 'X6283910', 'enroll' => '61829374'],
            ['nama' => 'ABDUL GHOFUR MANSYUR', 'paspor' => 'E9301827', 'enroll' => '54918273']
        ];

        // Pilih sampel yang belum ada di daftar, atau buat variasi baru
        $records = getRecords();
        $existingPassports = array_column($records, 'paspor');
        $chosen = null;
        foreach ($samples as $s) {
            if (!in_array($s['paspor'], $existingPassports, true)) {
                $chosen = $s;
                break;
            }
        }
        if ($chosen === null) {
            $randNum = rand(1000000, 9999999);
            $chosen = [
                'nama' => 'H. ABDULLAH PASURUAN ' . rand(1, 99),
                'paspor' => 'E' . $randNum,
                'enroll' => (string) rand(10000000, 99999999)
            ];
        }

        $simBody = "Application Submitted Successfully\n\n"
            . "Dear {$chosen['nama']}،\n\n"
            . "Thank you for enrolling your biometric information for your passport number: {$chosen['paspor']} .\n\n"
            . "on " . date('Y-m-d H:i:s') . " as part of your visa application process to the Kingdom of Saudi Arabia. and Your Enrollment Id is : {$chosen['enroll']} .\n"
            . "For any questions on submission of your biometric data or the status of your visa application, please visit www.mofa.gov.sa\n\n"
            . "*This is an automated message, please do not reply to this email directly*\n"
            . "© All Rights Reserved - Ministry of Foreign Affairs - Kingdom of Saudi Arabia";

        $emailData = [
            'message_id' => 'sim_' . uniqid(),
            'subject' => 'Enrollment confirmation',
            'from' => 'Mofa <MofaBioApp@mofa.gov.sa>',
            'to' => $settings['source_email'] ?? 'phujtmkopasuruan@gmail.com',
            'date' => date('d M Y, H:i:s') . ' WIB',
            'body_text' => $simBody
        ];

        $processed = BiovisaProcessor::processIncomingEmail($emailData, $settings, $jamaah);
        $saved = upsertAndSyncRecord($processed, $settings);
        addSyncLog('info', "[Simulasi Email Masuk] {$saved['filename']} ({$saved['size_kb']} KB) berhasil dibuat & diproses.");

        jsonResponse([
            'ok' => true,
            'message' => "Simulasi email 'Mofa Enrollment Confirmation' masuk berhasil diproses menjadi {$saved['filename']} ({$saved['size_kb']} KB)!",
            'record' => $saved
        ]);
        break;

    case 'update_record':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;

        $id = $payload['id'] ?? '';
        $newNama = strtoupper(trim($payload['nama'] ?? ''));
        $newPaspor = strtoupper(trim($payload['paspor'] ?? ''));
        $newEnroll = strtoupper(trim($payload['enrollment_id'] ?? ''));

        if ($id === '' || $newNama === '' || $newPaspor === '') {
            jsonResponse(['ok' => false, 'error' => 'ID, Nama, dan Nomor Paspor wajib diisi.'], 400);
        }

        $hintSlug = trim((string)($payload['lembaga'] ?? $payload['lembaga_slug'] ?? ''));
        bindTenantFromRecordPayload($payload);
        $located = locateRecordInTenantStores($id, $hintSlug);
        if (!$located) {
            jsonResponse(['ok' => false, 'error' => 'Data Biovisa tidak ditemukan di instansi ini.'], 404);
        }
        tenantBindPaths($located['slug']);

        $settings = getSettings();
        $records = getRecords();
        $foundIdx = $located['index'];
        if ($foundIdx < 0 || $foundIdx >= count($records) || ($records[$foundIdx]['id'] ?? '') !== $id) {
            foreach ($records as $idx => $r) {
                if (($r['id'] ?? '') === $id) {
                    $foundIdx = $idx;
                    break;
                }
            }
        }
        if ($foundIdx < 0 || !isset($records[$foundIdx])) {
            jsonResponse(['ok' => false, 'error' => 'Data Biovisa tidak ditemukan.'], 404);
        }

        $rec = $records[$foundIdx];
        $oldFilename = $rec['filename'] ?? '';

        // Render ulang gambar dengan Nama & Nomor Paspor baru
        $newFilename = BiovisaProcessor::buildFilename($newNama, $newPaspor, $settings);
        $newPath = getBiovisaDir() . DIRECTORY_SEPARATOR . $newFilename;

        $renderData = [
            'nama' => $newNama,
            'paspor' => $newPaspor,
            'enrollment_id' => $newEnroll ?: ($rec['enrollment_id'] ?? '-'),
            'enrollment_time' => $rec['enrollment_time'] ?? date('Y-m-d H:i:s'),
            'subject' => $rec['subject'] ?? 'Enrollment confirmation',
            'from' => $rec['from_email'] ?? 'Mofa <MofaBioApp@mofa.gov.sa>',
            'to' => $rec['to_email'] ?? $settings['source_email'],
            'email_date' => $rec['email_date'] ?? date('d M Y, H:i:s'),
            'body_text' => $rec['body_excerpt'] ?? ''
        ];

        try {
            $gd = BiovisaProcessor::renderEmailConfirmationToGd($renderData);
            if ($gd === false || $gd === null) {
                jsonResponse(['ok' => false, 'error' => 'Gagal merender gambar Biovisa (GD library).'], 500);
            }
            $bvLimits = getSiskohatCategoryCompressLimits('biovisa');
            $savedImg = BiovisaProcessor::saveGdAs50KbJpg(
                $gd,
                $newPath,
                floatval($bvLimits['min_kb']),
                floatval($bvLimits['max_kb']),
                !empty($bvLimits['pad_if_under_min'])
            );
            imagedestroy($gd);
        } catch (Throwable $e) {
            jsonResponse(['ok' => false, 'error' => 'Gagal menyimpan file JPG: ' . $e->getMessage()], 500);
        }

        if (!is_file($newPath)) {
            jsonResponse(['ok' => false, 'error' => 'File JPG tidak tersimpan. Periksa izin folder biovisa.'], 500);
        }

        if ($oldFilename !== '' && $oldFilename !== $newFilename) {
            $oldPath = getBiovisaDir() . DIRECTORY_SEPARATOR . $oldFilename;
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        $rec['nama'] = $newNama;
        $rec['paspor'] = $newPaspor;
        $rec['enrollment_id'] = $renderData['enrollment_id'];
        $rec['filename'] = $newFilename;
        $rec['size_bytes'] = $savedImg['size_bytes'];
        $rec['size_kb'] = $savedImg['size_kb'];
        $rec['processed_at'] = date('Y-m-d H:i:s');

        // Re-upload ke GDrive jika sudah terkonfigurasi
        if (!empty($settings['auto_upload_gdrive']) && !empty($settings['gas_gdrive_webapp_url'])) {
            $driveRes = GDriveSync::uploadFileToDrive($rec, $settings);
            if ($driveRes['ok']) {
                $rec['gdrive_uploaded'] = true;
                $rec['gdrive_file_id'] = $driveRes['file_id'];
                $rec['gdrive_url'] = $driveRes['file_url'];
                $rec['gdrive_error'] = '';
            }
        }

        $records[$foundIdx] = $rec;
        if (!saveRecords($records)) {
            jsonResponse(['ok' => false, 'error' => 'Gagal menulis records.json. Periksa izin folder data.'], 500);
        }
        addSyncLog('info', "Data diperbarui menjadi {$newFilename} ({$rec['size_kb']} KB)");

        jsonResponse([
            'ok' => true,
            'message' => "Berhasil mengubah nama file menjadi {$newFilename} ({$rec['size_kb']} KB)",
            'record' => $rec
        ]);
        break;

    case 'delete_record':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;
        $id = $payload['id'] ?? '';
        $ids = $payload['ids'] ?? ($id !== '' ? [$id] : []);
        $hintSlug = trim((string)($payload['lembaga'] ?? $payload['lembaga_slug'] ?? ''));

        if ($id !== '' && count($ids) === 1) {
            bindTenantFromRecordPayload($payload);
            $located = locateRecordInTenantStores($id, $hintSlug);
            if ($located) {
                tenantBindPaths($located['slug']);
            }
        } else {
            bindTenantFromRecordPayload($payload);
        }

        $records = getRecords();
        $remaining = [];
        $deletedCount = 0;
        foreach ($records as $r) {
            if (in_array(($r['id'] ?? ''), $ids, true)) {
                $fp = getBiovisaDir() . DIRECTORY_SEPARATOR . ($r['filename'] ?? '');
                if (file_exists($fp)) {
                    @unlink($fp);
                }
                $deletedCount++;
                continue;
            }
            $remaining[] = $r;
        }
        saveRecords($remaining);
        addSyncLog('info', "Menghapus {$deletedCount} data Biovisa dari daftar lokal.");
        jsonResponse(['ok' => true, 'deleted_count' => $deletedCount, 'message' => "{$deletedCount} data Biovisa berhasil dihapus."]);
        break;

    case 'retry_gdrive':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;
        $targetId = $payload['id'] ?? ''; // Kosong = upload semua yang belum terupload

        $settings = getSettings();
        if (empty($settings['gas_gdrive_webapp_url'])) {
            jsonResponse([
                'ok' => false,
                'error' => 'URL Web App Google Drive (' . $settings['target_gdrive_email'] . ') belum diisi. Silakan buka tab "Integrasi Gmail & GDrive" untuk menyalin script dan mengisi URL-nya.'
            ]);
        }

        $records = getRecords();
        $successCount = 0;
        $lastError = '';

        foreach ($records as $idx => $rec) {
            if ($targetId !== '' && ($rec['id'] ?? '') !== $targetId) {
                continue;
            }
            if ($targetId === '' && !empty($rec['gdrive_uploaded'])) {
                continue;
            }

            $res = GDriveSync::uploadFileToDrive($rec, $settings);
            if ($res['ok']) {
                $records[$idx]['gdrive_uploaded'] = true;
                $records[$idx]['gdrive_file_id'] = $res['file_id'];
                $records[$idx]['gdrive_url'] = $res['file_url'];
                $records[$idx]['gdrive_error'] = '';
                $successCount++;
            } else {
                $records[$idx]['gdrive_uploaded'] = false;
                $records[$idx]['gdrive_error'] = $res['error'];
                $lastError = $res['error'];
            }
        }

        saveRecords($records);

        if ($successCount === 0 && $lastError !== '') {
            jsonResponse(['ok' => false, 'error' => $lastError]);
        }

        jsonResponse([
            'ok' => true,
            'uploaded_count' => $successCount,
            'message' => "Berhasil mengunggah {$successCount} file ke Google Drive ({$settings['target_gdrive_email']})."
        ]);
        break;

    case 'save_settings':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;

        $allowedKeys = [
            'source_email', 'target_gdrive_email', 'email_subject_filter',
            'connection_mode', 'imap_app_password', 'gas_gmail_webapp_url',
            'source_accounts',
            'gas_gdrive_webapp_url', 'gdrive_folder_name', 'gdrive_folder_id',
            'auto_sync_enabled', 'auto_sync_interval_sec', 'auto_download_browser',
            'auto_upload_gdrive', 'process_mode', 'target_min_kb', 'target_max_kb',
            'strict_50kb_padding', 'filename_pattern', 'uppercase_filename'
        ];

        $toSave = [];
        foreach ($allowedKeys as $k) {
            if (array_key_exists($k, $payload)) {
                $toSave[$k] = $payload[$k];
            }
        }

        // Sanitasi & validasi daftar source_accounts (Multi-Email Pembaca)
        if (isset($toSave['source_accounts']) && is_array($toSave['source_accounts'])) {
            $cleanAccounts = [];
            foreach ($toSave['source_accounts'] as $idx => $acc) {
                if (!is_array($acc)) {
                    continue;
                }
                $emailAddr = trim((string)($acc['email'] ?? ''));
                $gasUrl    = trim((string)($acc['gas_url'] ?? ''));
                $imapPass  = trim((string)($acc['imap_password'] ?? ''));
                if ($emailAddr === '' && $gasUrl === '' && $imapPass === '') {
                    continue;
                }
                $cleanAccounts[] = [
                    'id' => !empty($acc['id']) ? (string)$acc['id'] : ('acc_' . ($idx + 1) . '_' . substr(md5($emailAddr . $idx), 0, 6)),
                    'label' => trim((string)($acc['label'] ?? ('Email Pembaca #' . ($idx + 1)))),
                    'email' => $emailAddr ?: ('email' . ($idx + 1) . '@gmail.com'),
                    'mode' => in_array(($acc['mode'] ?? 'gas'), ['gas', 'imap'], true) ? $acc['mode'] : 'gas',
                    'gas_url' => $gasUrl,
                    'imap_password' => $imapPass,
                    'enabled' => !isset($acc['enabled']) || !empty($acc['enabled'])
                ];
            }
            $toSave['source_accounts'] = $cleanAccounts;

            // Sinkronkan akun aktif pertama ke field legacy
            foreach ($cleanAccounts as $ca) {
                if (!empty($ca['enabled'])) {
                    $toSave['source_email'] = $ca['email'];
                    $toSave['connection_mode'] = $ca['mode'];
                    $toSave['gas_gmail_webapp_url'] = $ca['gas_url'];
                    $toSave['imap_app_password'] = $ca['imap_password'];
                    break;
                }
            }
        }

        // Validasi batas KB agar tidak pernah melebihi 50 KB
        if (isset($toSave['target_max_kb'])) {
            $toSave['target_max_kb'] = min(49.8, max(25.0, floatval($toSave['target_max_kb'])));
        }
        if (isset($toSave['target_min_kb'])) {
            $toSave['target_min_kb'] = min(48.0, max(15.0, floatval($toSave['target_min_kb'])));
        }

        $updated = saveSettings($toSave);
        $lem = Auth::getLembagaBySlug(Auth::tenantSlug());
        if ($lem) {
            Auth::saveLembaga(array_merge($lem, [
                'source_email' => $updated['source_email'] ?? $lem['source_email'] ?? '',
                'target_gdrive_email' => $updated['target_gdrive_email'] ?? $lem['target_gdrive_email'] ?? '',
                'gdrive_folder_name' => $updated['gdrive_folder_name'] ?? $lem['gdrive_folder_name'] ?? '',
                'gas_gdrive_webapp_url' => $updated['gas_gdrive_webapp_url'] ?? $lem['gas_gdrive_webapp_url'] ?? '',
                'gas_gmail_webapp_url' => $updated['gas_gmail_webapp_url'] ?? $lem['gas_gmail_webapp_url'] ?? '',
                'source_accounts' => $updated['source_accounts'] ?? $lem['source_accounts'] ?? [],
            ]));
        }
        addSyncLog('info', 'Pengaturan sistem & integrasi berhasil disimpan.');
        if (empty(Auth::getSecrets()['block_public_cloud_sync'] ?? false)) {
            triggerBackgroundGitHubPush('Auto-sync daftar akun email pembaca & pengaturan ke GitHub Pages');
        }

        jsonResponse([
            'ok' => true,
            'message' => 'Pengaturan berhasil disimpan & disinkronkan ke Cloud.',
            'settings' => $updated
        ]);
        break;

    case 'test_connection':
        $settings = getSettings();
        $diag = GDriveSync::diagnoseConnections($settings);
        $bothOk = !empty($diag['gmail']['ok']) && !empty($diag['gdrive']['ok']);
        if ($bothOk) {
            addSyncLog('success', 'Tes Koneksi Berhasil: ' . $diag['gmail']['message'] . ' | ' . $diag['gdrive']['message']);
        } else {
            if (empty($diag['gmail']['ok'])) {
                addSyncLog('warning', 'Diagnosa Gmail: ' . $diag['gmail']['message']);
            }
            if (empty($diag['gdrive']['ok'])) {
                addSyncLog('warning', 'Diagnosa GDrive: ' . $diag['gdrive']['message']);
            }
        }
        jsonResponse([
            'ok' => true,
            'both_ok' => $bothOk,
            'diagnosis' => $diag
        ]);
        break;

    case 'clear_logs':
        saveJsonFile(getLogsFile(), []);
        addSyncLog('info', 'Riwayat log aktivitas telah dibersihkan.');
        jsonResponse(['ok' => true, 'message' => 'Log berhasil dibersihkan.']);
        break;

    case 'import_jamaah':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;
        $rawText = trim($payload['raw_text'] ?? '');
        $replaceAll = !empty($payload['replace_all']);

        if ($rawText === '') {
            jsonResponse(['ok' => false, 'error' => 'Data teks Nama & Nomor Paspor kosong.'], 400);
        }

        $current = $replaceAll ? [] : getJamaahMaster();
        $indexed = [];
        foreach ($current as $item) {
            $indexed[strtoupper($item['paspor'])] = $item;
        }

        $lines = preg_split('/\r\n|\r|\n/', $rawText);
        $added = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Dukung pemisah Tab (copy dari Excel), titik koma (;), koma (,), atau underscore (_)
            $parts = preg_split('/[\t;|,]+/', $line);
            if (count($parts) < 2 && strpos($line, '_') !== false) {
                $parts = explode('_', $line, 2);
            }

            if (count($parts) >= 2) {
                $col1 = strtoupper(trim($parts[0]));
                $col2 = strtoupper(trim($parts[1]));
                $col3 = isset($parts[2]) ? trim($parts[2]) : '';

                // Skip baris header
                if (in_array($col1, ['NAMA', 'NAMA JAMAAH', 'NO', 'NOMOR PASPOR']) && in_array($col2, ['PASPOR', 'NO PASPOR', 'NOMOR PASPOR', 'NAMA'])) {
                    continue;
                }

                // Deteksi mana kolom Nama dan mana kolom Nomor Paspor
                if (preg_match('/^[A-Z]{1,3}\d{5,8}$/', preg_replace('/[^A-Z0-9]/', '', $col1))) {
                    $paspor = preg_replace('/[^A-Z0-9]/', '', $col1);
                    $nama = $col2;
                } else {
                    $nama = $col1;
                    $paspor = preg_replace('/[^A-Z0-9]/', '', $col2);
                }

                if ($nama !== '' && $paspor !== '') {
                    $indexed[$paspor] = [
                        'nama' => $nama,
                        'paspor' => $paspor,
                        'kloter' => $col3
                    ];
                    $added++;
                }
            }
        }

        $finalList = array_values($indexed);
        saveJamaahMaster($finalList);
        addSyncLog('info', "Berhasil mengimpor {$added} data Master Jamaah (Total: " . count($finalList) . " jamaah).");

        jsonResponse([
            'ok' => true,
            'message' => "Berhasil menyimpan {$added} data Jamaah (Total: " . count($finalList) . " Jamaah).",
            'total' => count($finalList)
        ]);
        break;

    case 'siskohat_queue':
        $records = getRecords();
        $queue = [];
        foreach ($records as $r) {
            $fn = $r['filename'] ?? '';
            $fp = getBiovisaDir() . DIRECTORY_SEPARATOR . $fn;
            $sevenDocs = getJamaahSevenDocsStatus($r);
            if ($sevenDocs['available_count'] === 0 && ($fn === '' || !file_exists($fp))) {
                continue;
            }
            $queue[] = [
                'id' => $r['id'] ?? '',
                'nama' => $r['nama'] ?? '',
                'paspor' => $r['paspor'] ?? '',
                'enrollment_id' => $r['enrollment_id'] ?? '',
                'filename' => $fn,
                'size_kb' => $r['size_kb'] ?? (file_exists($fp) ? round(filesize($fp) / 1024, 2) : 0),
                'siskohat_uploaded' => !empty($r['siskohat_uploaded']),
                'siskohat_uploaded_at' => $r['siskohat_uploaded_at'] ?? '',
                'siskohat_uploaded_docs' => $r['siskohat_uploaded_docs'] ?? [],
                'seven_docs' => $sevenDocs
            ];
        }
        jsonResponse([
            'ok' => true,
            'count' => count($queue),
            'doc_categories' => array_values(getSiskohatDocCategories()),
            'records' => $queue
        ]);
        break;

    case 'siskohat_get_all_docs':
    case 'siskohat_get_file':
        $id = trim((string)($_GET['id'] ?? ''));
        $paspor = strtoupper(trim((string)($_GET['paspor'] ?? '')));
        $nama = strtoupper(trim((string)($_GET['nama'] ?? '')));
        $requestedCat = strtolower(trim((string)($_GET['cat'] ?? '')));

        $records = getRecords();
        $matched = null;
        foreach ($records as $r) {
            if ($id !== '' && ($r['id'] ?? '') === $id) {
                $matched = $r;
                break;
            }
            if ($paspor !== '' && strtoupper($r['paspor'] ?? '') === $paspor) {
                $matched = $r;
                break;
            }
            if ($nama !== '' && strtoupper($r['nama'] ?? '') === $nama) {
                $matched = $r;
                break;
            }
        }

        if (!$matched) {
            jsonResponse(['ok' => false, 'error' => 'Data jamaah tidak ditemukan di server E-Visa.'], 404);
        }

        $cats = getSiskohatDocCategories();
        $recPaspor = (string)($matched['paspor'] ?? '');
        $recNama   = (string)($matched['nama'] ?? '');
        $bvFile    = (string)($matched['filename'] ?? '');

        $docsPayload = [];
        $availableCount = 0;

        foreach ($cats as $catKey => $meta) {
            if ($requestedCat !== '' && $requestedCat !== 'all' && $requestedCat !== $catKey) {
                continue;
            }
            $found = findJamaahDocFile($catKey, $recPaspor, $recNama, $catKey === 'biovisa' ? $bvFile : '');
            if ($found && is_file($found['path'])) {
                $bytes = file_get_contents($found['path']);
                $availableCount++;
                $docsPayload[$catKey] = [
                    'exists' => true,
                    'blocked' => false,
                    'num' => $meta['num'],
                    'key' => $catKey,
                    'short' => $meta['short'],
                    'label' => $meta['label'],
                    'siskohat_label' => $meta['siskohat_label'],
                    'filename' => $found['filename'],
                    'size_kb' => round(strlen($bytes) / 1024, 2),
                    'base64' => base64_encode($bytes)
                ];
            } else {
                $docsPayload[$catKey] = [
                    'exists' => false,
                    'blocked' => true,
                    'num' => $meta['num'],
                    'key' => $catKey,
                    'short' => $meta['short'],
                    'label' => $meta['label'],
                    'siskohat_label' => $meta['siskohat_label'],
                    'filename' => '',
                    'size_kb' => 0,
                    'base64' => '',
                    'reason' => "File {$meta['label']} tidak ada -> diblokir & lanjut ke dokumen berikutnya"
                ];
            }
        }

        // Kompatibilitas backward untuk field single 'base64' & 'filename' (biovisa)
        $bvDoc = $docsPayload['biovisa'] ?? null;

        jsonResponse([
            'ok' => true,
            'record' => [
                'id' => $matched['id'] ?? '',
                'nama' => $recNama,
                'paspor' => $recPaspor,
                'enrollment_id' => $matched['enrollment_id'] ?? '',
                'filename' => ($bvDoc && $bvDoc['exists']) ? $bvDoc['filename'] : $bvFile,
                'size_kb' => ($bvDoc && $bvDoc['exists']) ? $bvDoc['size_kb'] : 0,
                'base64' => ($bvDoc && $bvDoc['exists']) ? $bvDoc['base64'] : '',
                'available_count' => $availableCount,
                'total_slots' => count($cats),
                'docs' => $docsPayload
            ]
        ]);
        break;

    case 'list_scanners':
        $res = ScannerBridge::listActiveScanners();
        jsonResponse($res);
        break;

    case 'scan_jamaah_doc':
        $rawInput = file_get_contents('php://input');
        $payload  = json_decode($rawInput, true) ?: $_POST;

        $settings = getSettings();
        $records  = getRecords();
        $cats     = getSiskohatDocCategories();

        $catKey = strtolower(trim((string)($payload['category'] ?? 'paspor')));
        if (!isset($cats[$catKey])) {
            jsonResponse(['ok' => false, 'error' => 'Kategori dokumen tidak valid: ' . $catKey], 400);
        }
        $catMeta   = $cats[$catKey];
        $targetId  = trim((string)($payload['id'] ?? ''));
        $targetDir = ($catKey === 'biovisa') ? getBiovisaDir() : getDocCategoryDir($catKey);

        $matchedRec = null;
        foreach ($records as $r) {
            if (($r['id'] ?? '') === $targetId) {
                $matchedRec = $r;
                break;
            }
        }
        if (!$matchedRec && !empty($payload['paspor'])) {
            $reqP = strtoupper(trim((string)$payload['paspor']));
            foreach ($records as $r) {
                if (strtoupper(trim((string)($r['paspor'] ?? ''))) === $reqP) {
                    $matchedRec = $r;
                    break;
                }
            }
        }

        $scanRes = ScannerBridge::scanFromActiveDriver([
            'device_id'   => $payload['device_id'] ?? '',
            'use_dialog'  => !empty($payload['use_dialog']),
            'dpi'         => intval($payload['dpi'] ?? 150),
            'color_mode'  => $payload['color_mode'] ?? 'color',
            'scan_profile'=> $payload['scan_profile'] ?? 'photo',
        ]);

        if (empty($scanRes['ok'])) {
            jsonResponse([
                'ok' => false,
                'error' => $scanRes['error'] ?? 'Gagal melakukan scan dari driver scanner.'
            ]);
        }

        $tempScanPath = $scanRes['temp_path'];
        $rawBytes = @file_get_contents($tempScanPath);
        @unlink($tempScanPath);

        if ($rawBytes === false || $rawBytes === '') {
            jsonResponse(['ok' => false, 'error' => 'Data gambar hasil scan kosong.']);
        }

        if ($matchedRec) {
            $finalFilename = BiovisaProcessor::buildFilename($matchedRec['nama'], $matchedRec['paspor'], $settings);
        } else {
            $reqNama   = strtoupper(trim((string)($payload['nama'] ?? 'JAMAAH')));
            $reqPaspor = strtoupper(trim((string)($payload['paspor'] ?? ('SCAN' . date('His')))));
            $finalFilename = BiovisaProcessor::buildFilename($reqNama, $reqPaspor, $settings);
        }

        $destPath = $targetDir . DIRECTORY_SEPARATOR . $finalFilename;
        $limits = getSiskohatCategoryCompressLimits($catKey);
        $catMaxKb = floatval($limits['max_kb']);
        $gd = @imagecreatefromstring($rawBytes);
        if ($gd !== false) {
            $gd = BiovisaProcessor::autoCropScannedDocument($gd);
            $gd = BiovisaProcessor::refineScannedDocument($gd);
            BiovisaProcessor::saveGdAs50KbJpg(
                $gd,
                $destPath,
                floatval($limits['min_kb']),
                $catMaxKb,
                !empty($limits['pad_if_under_min'])
            );
            imagedestroy($gd);
        } else {
            file_put_contents($destPath, $rawBytes);
        }

        $finalBytes = is_file($destPath) ? file_get_contents($destPath) : $rawBytes;
        $finalSizeKb = round(strlen($finalBytes) / 1024, 2);

        $gdriveSynced = false;
        if (!empty($settings['auto_upload_gdrive']) && !empty($settings['gas_gdrive_webapp_url']) && is_file($destPath)) {
            $docRec = [
                'filename' => $finalFilename,
                'nama' => $matchedRec['nama'] ?? pathinfo($finalFilename, PATHINFO_FILENAME),
                'paspor' => $matchedRec['paspor'] ?? '-',
                'enrollment_id' => $catMeta['label'],
                'size_kb' => $finalSizeKb,
                'custom_path' => $destPath,
                'subfolder' => $catMeta['gdrive_subfolder']
            ];
            $gdRes = GDriveSync::uploadCustomDocToDrive($docRec, $settings);
            $gdriveSynced = !empty($gdRes['ok']);
        }

        addSyncLog('success', "Berhasil scan dari [{$scanRes['scanner_name']}] -> {$finalFilename} ({$finalSizeKb} KB) pada kategori {$catMeta['label']}.");
        triggerBackgroundGitHubPush("Auto-sync hasil scan dokumen {$catMeta['short']} ({$finalFilename}) ke GitHub Pages & Cloud DB");
        jsonResponse([
            'ok' => true,
            'scanner_name' => $scanRes['scanner_name'],
            'category' => $catKey,
            'category_label' => $catMeta['label'],
            'filename' => $finalFilename,
            'size_kb' => $finalSizeKb,
            'size_bytes' => strlen($finalBytes),
            'data_url' => 'data:image/jpeg;base64,' . base64_encode($finalBytes),
            'gdrive_synced' => $gdriveSynced,
            'message' => "Berhasil scan dari [{$scanRes['scanner_name']}] -> {$finalFilename} ({$finalSizeKb} KB <= {$catMaxKb} KB)!"
        ]);
        break;

    case 'upload_jamaah_doc':
        $settings = getSettings();
        $records  = getRecords();
        $cats     = getSiskohatDocCategories();

        $catKey = strtolower(trim((string)($_POST['category'] ?? $_GET['category'] ?? 'paspor')));
        if (!isset($cats[$catKey])) {
            jsonResponse(['ok' => false, 'error' => 'Kategori dokumen tidak valid: ' . $catKey], 400);
        }
        $catMeta = $cats[$catKey];
        $targetId = trim((string)($_POST['id'] ?? ''));
        $targetDir = ($catKey === 'biovisa') ? getBiovisaDir() : getDocCategoryDir($catKey);

        // Kumpulkan semua file yang diupload (bisa single 'file' atau multi 'files[]')
        $uploadedList = [];
        if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
            $count = count($_FILES['files']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK && is_uploaded_file($_FILES['files']['tmp_name'][$i])) {
                    $uploadedList[] = [
                        'orig_name' => $_FILES['files']['name'][$i],
                        'tmp_name' => $_FILES['files']['tmp_name'][$i]
                    ];
                }
            }
        } elseif (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $uploadedList[] = [
                'orig_name' => $_FILES['file']['name'] ?? 'dokumen.jpg',
                'tmp_name' => $_FILES['file']['tmp_name']
            ];
        }

        if (empty($uploadedList)) {
            jsonResponse(['ok' => false, 'error' => 'Tidak ada file gambar yang diterima.'], 400);
        }

        $savedFiles = [];
        foreach ($uploadedList as $uItem) {
            $origName = $uItem['orig_name'];
            $rawBytes = file_get_contents($uItem['tmp_name']);
            if ($rawBytes === false || $rawBytes === '') continue;

            // Tentukan jamaah pemilik file ini (dari targetId, atau ekstrak Nomor Paspor / Nama dari nama file)
            $matchedRec = null;
            if ($targetId !== '' && count($uploadedList) === 1) {
                foreach ($records as $r) {
                    if (($r['id'] ?? '') === $targetId) {
                        $matchedRec = $r;
                        break;
                    }
                }
            }
            if (!$matchedRec) {
                $baseUpper = strtoupper(pathinfo($origName, PATHINFO_FILENAME));
                foreach ($records as $r) {
                    $p = strtoupper(trim((string)($r['paspor'] ?? '')));
                    $n = strtoupper(trim((string)($r['nama'] ?? '')));
                    if ($p !== '' && $p !== 'TANPAPASPOR' && ($baseUpper === $p || strpos($baseUpper, $p) !== false)) {
                        $matchedRec = $r;
                        break;
                    }
                    if ($n !== '' && ($baseUpper === $n || strpos($baseUpper, $n . '_') === 0)) {
                        $matchedRec = $r;
                        break;
                    }
                }
            }

            if ($matchedRec) {
                $finalFilename = BiovisaProcessor::buildFilename($matchedRec['nama'], $matchedRec['paspor'], $settings);
            } else {
                $cleanBase = preg_replace('/[^A-Za-z0-9_\- ]/', '', pathinfo($origName, PATHINFO_FILENAME));
                $finalFilename = strtoupper(trim($cleanBase) ?: ('DOK_' . time())) . '.jpg';
            }

            $destPath = $targetDir . DIRECTORY_SEPARATOR . $finalFilename;

            $limits = getSiskohatCategoryCompressLimits($catKey);
            $gd = @imagecreatefromstring($rawBytes);
            if ($gd !== false) {
                $gd = BiovisaProcessor::autoCropScannedDocument($gd);
                $gd = BiovisaProcessor::refineScannedDocument($gd);
                BiovisaProcessor::saveGdAs50KbJpg(
                    $gd,
                    $destPath,
                    floatval($limits['min_kb']),
                    floatval($limits['max_kb']),
                    !empty($limits['pad_if_under_min'])
                );
                imagedestroy($gd);
            } else {
                file_put_contents($destPath, $rawBytes);
            }

            $finalSizeKb = is_file($destPath) ? round(filesize($destPath) / 1024, 2) : 0;

            // Sinkronkan juga ke Sub-Folder Google Drive jika URL Web App GDrive sudah aktif
            $gdriveSynced = false;
            if (!empty($settings['auto_upload_gdrive']) && !empty($settings['gas_gdrive_webapp_url']) && is_file($destPath)) {
                $docRec = [
                    'filename' => $finalFilename,
                    'nama' => $matchedRec['nama'] ?? pathinfo($finalFilename, PATHINFO_FILENAME),
                    'paspor' => $matchedRec['paspor'] ?? '-',
                    'enrollment_id' => $matchedRec['enrollment_id'] ?? $catMeta['label'],
                    'size_kb' => $finalSizeKb,
                    'custom_path' => $destPath,
                    'subfolder' => $catMeta['gdrive_subfolder']
                ];
                $gdRes = GDriveSync::uploadCustomDocToDrive($docRec, $settings);
                $gdriveSynced = !empty($gdRes['ok']);
            }

            $savedFiles[] = [
                'category' => $catKey,
                'category_label' => $catMeta['label'],
                'filename' => $finalFilename,
                'size_kb' => $finalSizeKb,
                'matched_paspor' => $matchedRec['paspor'] ?? '',
                'matched_nama' => $matchedRec['nama'] ?? '',
                'gdrive_synced' => $gdriveSynced
            ];
        }

        addSyncLog('success', "Berhasil menyimpan " . count($savedFiles) . " file pada kategori {$catMeta['label']}.");
        triggerBackgroundGitHubPush("Auto-sync upload " . count($savedFiles) . " dokumen {$catMeta['short']} ke GitHub Pages & Cloud DB");
        jsonResponse([
            'ok' => true,
            'count' => count($savedFiles),
            'saved' => $savedFiles,
            'message' => "Berhasil menyimpan " . count($savedFiles) . " file dokumen pada kategori {$catMeta['label']}!"
        ]);
        break;

    case 'crop_jamaah_doc':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;

        $id = trim((string)($payload['id'] ?? ''));
        $catKey = strtolower(trim((string)($payload['category'] ?? 'biovisa')));
        $autoOnly = !empty($payload['auto']);

        $settings = getSettings();
        $cats = getSiskohatDocCategories();
        if (!isset($cats[$catKey])) {
            jsonResponse(['ok' => false, 'error' => 'Kategori dokumen tidak valid.'], 400);
        }
        $catMeta = $cats[$catKey];

        if ($id === '') {
            jsonResponse(['ok' => false, 'error' => 'ID jamaah wajib diisi.'], 400);
        }

        $hintSlug = trim((string)($payload['lembaga'] ?? $payload['lembaga_slug'] ?? ''));
        bindTenantFromRecordPayload($payload);
        $located = locateRecordInTenantStores($id, $hintSlug);
        if (!$located) {
            jsonResponse(['ok' => false, 'error' => 'Data jamaah tidak ditemukan di instansi ini.'], 404);
        }
        $tenantSlug = $located['slug'];
        tenantBindPaths($tenantSlug);
        $records = getRecords();
        $matchedIdx = $located['index'];
        $matched = $records[$matchedIdx] ?? $located['record'];
        if (!$matched || ($matched['id'] ?? '') !== $id) {
            jsonResponse(['ok' => false, 'error' => 'Data jamaah tidak ditemukan.'], 404);
        }

        $found = findJamaahDocFile(
            $catKey,
            (string)($matched['paspor'] ?? ''),
            (string)($matched['nama'] ?? ''),
            $catKey === 'biovisa' ? (string)($matched['filename'] ?? '') : '',
            $tenantSlug
        );
        if (!$found || empty($found['path']) || !is_file($found['path'])) {
            jsonResponse(['ok' => false, 'error' => 'File gambar tidak ditemukan di server.'], 404);
        }

        $destPath = $found['path'];
        $limits = getSiskohatCategoryCompressLimits($catKey);
        $rawBytes = file_get_contents($destPath);
        if ($rawBytes === false || $rawBytes === '') {
            jsonResponse(['ok' => false, 'error' => 'Gagal membaca file gambar.'], 400);
        }

        $gd = @imagecreatefromstring($rawBytes);
        if ($gd === false) {
            jsonResponse(['ok' => false, 'error' => 'Format gambar tidak didukung.'], 400);
        }

        $imgW = imagesx($gd);
        $imgH = imagesy($gd);
        $beforeW = $imgW;
        $beforeH = $imgH;

        if ($autoOnly) {
            $gd = BiovisaProcessor::autoCropScannedDocument($gd);
        } else {
            $x = intval($payload['x'] ?? 0);
            $y = intval($payload['y'] ?? 0);
            $cw = intval($payload['width'] ?? 0);
            $ch = intval($payload['height'] ?? 0);
            if ($cw < 40 || $ch < 40) {
                imagedestroy($gd);
                jsonResponse(['ok' => false, 'error' => 'Area crop terlalu kecil. Pilih area yang lebih besar.'], 400);
            }
            $gd = BiovisaProcessor::cropGdRect($gd, $x, $y, $cw, $ch);
        }

        $afterW = imagesx($gd);
        $afterH = imagesy($gd);
        if ($autoOnly && $afterW === $beforeW && $afterH === $beforeH) {
            imagedestroy($gd);
            jsonResponse([
                'ok' => false,
                'error' => 'Auto-crop tidak mendeteksi tepi dokumen. Gunakan Crop Manual atau perkecil area crop.'
            ], 400);
        }

        $gd = BiovisaProcessor::refineScannedDocument($gd);
        $savedImg = BiovisaProcessor::saveGdAs50KbJpg(
            $gd,
            $destPath,
            floatval($limits['min_kb']),
            floatval($limits['max_kb']),
            !empty($limits['pad_if_under_min'])
        );
        imagedestroy($gd);

        $finalFilename = basename($destPath);
        $gdriveSynced = false;
        if (!empty($settings['auto_upload_gdrive']) && !empty($settings['gas_gdrive_webapp_url']) && is_file($destPath)) {
            $docRec = [
                'filename' => $finalFilename,
                'nama' => $matched['nama'] ?? pathinfo($finalFilename, PATHINFO_FILENAME),
                'paspor' => $matched['paspor'] ?? '-',
                'enrollment_id' => $matched['enrollment_id'] ?? $catMeta['label'],
                'size_kb' => $savedImg['size_kb'],
                'custom_path' => $destPath,
                'subfolder' => $catMeta['gdrive_subfolder'] ?? ''
            ];
            if ($catKey === 'biovisa') {
                $matched['size_kb'] = $savedImg['size_kb'];
                $matched['size_bytes'] = $savedImg['size_bytes'];
                $gdRes = GDriveSync::uploadFileToDrive($matched, $settings);
            } else {
                $gdRes = GDriveSync::uploadCustomDocToDrive($docRec, $settings);
            }
            $gdriveSynced = !empty($gdRes['ok']);
            if ($catKey === 'biovisa' && $gdriveSynced) {
                $matched['gdrive_uploaded'] = true;
                $matched['gdrive_file_id'] = $gdRes['file_id'] ?? ($matched['gdrive_file_id'] ?? '');
                $matched['gdrive_url'] = $gdRes['file_url'] ?? ($matched['gdrive_url'] ?? '');
                $matched['gdrive_error'] = '';
            }
        }

        $updatedRecord = null;
        if ($catKey === 'biovisa' && $matchedIdx >= 0) {
            $matched['size_bytes'] = $savedImg['size_bytes'];
            $matched['size_kb'] = $savedImg['size_kb'];
            $matched['processed_at'] = date('Y-m-d H:i:s');
            $records[$matchedIdx] = $matched;
            saveRecords($records);
            $updatedRecord = $matched;
        }

        $modeLabel = $autoOnly ? 'Auto-crop' : 'Crop manual';
        addSyncLog('success', "{$modeLabel} {$catMeta['short']} {$matched['nama']} -> {$finalFilename} ({$savedImg['size_kb']} KB)");
        triggerBackgroundGitHubPush("Auto-sync hasil crop dokumen {$catMeta['short']} ({$finalFilename})");

        jsonResponse([
            'ok' => true,
            'category' => $catKey,
            'filename' => $finalFilename,
            'size_kb' => $savedImg['size_kb'],
            'size_bytes' => $savedImg['size_bytes'],
            'gdrive_synced' => $gdriveSynced,
            'record' => $updatedRecord,
            'message' => "Berhasil crop & kompres ({$savedImg['size_kb']} KB <= {$limits['max_kb']} KB) untuk {$catMeta['label']}."
        ]);
        break;

    case 'delete_jamaah_doc':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;
        $id = trim((string)($payload['id'] ?? ''));
        $catKey = strtolower(trim((string)($payload['category'] ?? '')));

        $cats = getSiskohatDocCategories();
        if (!isset($cats[$catKey])) {
            jsonResponse(['ok' => false, 'error' => 'Kategori tidak valid.'], 400);
        }

        $records = getRecords();
        $matched = null;
        foreach ($records as $r) {
            if (($r['id'] ?? '') === $id) {
                $matched = $r;
                break;
            }
        }
        if (!$matched) {
            jsonResponse(['ok' => false, 'error' => 'Data jamaah tidak ditemukan.'], 404);
        }

        $found = findJamaahDocFile($catKey, (string)$matched['paspor'], (string)$matched['nama'], $catKey === 'biovisa' ? (string)$matched['filename'] : '');
        if ($found && is_file($found['path'])) {
            @unlink($found['path']);
        }
        $docsBackupPath = BASE_DIR . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dokumen' . DIRECTORY_SEPARATOR . ($cats[$catKey]['folder'] ?? $catKey) . DIRECTORY_SEPARATOR . ($found['filename'] ?? '');
        if (!empty($found['filename']) && is_file($docsBackupPath)) {
            @unlink($docsBackupPath);
        }
        triggerBackgroundGitHubPush("Auto-sync hapus dokumen {$cats[$catKey]['short']} ({$matched['nama']})");
        jsonResponse([
            'ok' => true,
            'message' => "File {$cats[$catKey]['label']} untuk {$matched['nama']} berhasil dihapus."
        ]);
        break;

    case 'sync_gdrive_7docs':
        $settings = getSettings();
        $gasUrl   = trim((string)($settings['gas_gdrive_webapp_url'] ?? ''));
        if ($gasUrl === '') {
            jsonResponse(['ok' => false, 'error' => 'URL Web App Google Drive belum diisi di Konfigurasi Integrasi.'], 400);
        }

        $pulledCount = pullMissingSevenDocsFromGDrive($settings);

        addSyncLog('success', "Sinkronisasi 7 Folder Google Drive selesai: {$pulledCount} file dokumen baru diunduh ke server.");
        if ($pulledCount > 0) {
            triggerBackgroundGitHubPush("Auto-sync {$pulledCount} dokumen dari 7 Folder GDrive ke GitHub Pages");
        } else {
            writeCloudSyncBundle();
        }
        jsonResponse([
            'ok' => true,
            'pulled_count' => $pulledCount,
            'message' => $pulledCount > 0
                ? "Berhasil menarik {$pulledCount} file dokumen baru dari 7 Sub-Folder Google Drive!"
                : "7 Sub-Folder Google Drive sudah sinkron (1_Paspor s/d 7_Foto siap digunakan)."
        ]);
        break;

    case 'mark_siskohat':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;
        $id = trim((string)($payload['id'] ?? ''));
        $status = !isset($payload['status']) || !empty($payload['status']);

        $records = getRecords();
        $found = false;
        $targetName = '';
        foreach ($records as $idx => $r) {
            if (($r['id'] ?? '') === $id) {
                $records[$idx]['siskohat_uploaded'] = $status;
                $records[$idx]['siskohat_uploaded_at'] = $status ? date('Y-m-d H:i:s') : '';
                $targetName = $r['filename'] ?? $id;
                $found = true;
                break;
            }
        }

        if (!$found) {
            jsonResponse(['ok' => false, 'error' => 'ID dokumen tidak ditemukan.'], 404);
        }

        saveRecords($records);
        if ($status) {
            addSyncLog('success', "File {$targetName} telah diunggah ke halaman SISKOHAT Gen.2.");
        }
        triggerBackgroundGitHubPush("Auto-sync status Siskohat {$targetName}");
        jsonResponse([
            'ok' => true,
            'message' => $status
                ? "File {$targetName} ditandai sudah terupload di Siskohat."
                : "Status Siskohat {$targetName} direset."
        ]);
        break;

    case 'siskohat_dom_debug':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: [];
        $payload['time'] = date('Y-m-d H:i:s');
        saveJsonFile(getDataDir() . DIRECTORY_SEPARATOR . 'siskohat_dom_debug.json', $payload);
        jsonResponse(['ok' => true]);
        break;

    case 'mirror_cloud_to_local':
        // Jembatan sinkronisasi 2 arah antara halaman GitHub Pages (saat dibuka di Komputer Server) dan Server Laragon
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: [];

        $localSettings = getSettings();
        $localRecords  = getRecords();
        $changedAnything = false;

        // 1. Gabungkan daftar akun email pembaca (source_accounts) dari Cloud Tab ke Server Lokal
        if (!empty($payload['settings']) && is_array($payload['settings'])) {
            $incSet = $payload['settings'];
            $replaceAccounts = !empty($payload['replace_accounts']);

            if (!empty($incSet['source_accounts']) && is_array($incSet['source_accounts'])) {
                if ($replaceAccounts) {
                    // Pertahankan imap_password lokal jika di cloud kosong
                    $oldPassByEmail = [];
                    foreach ($localSettings['source_accounts'] ?? [] as $oa) {
                        $emKey = strtolower(trim((string)($oa['email'] ?? '')));
                        if ($emKey !== '' && !empty($oa['imap_password'])) {
                            $oldPassByEmail[$emKey] = $oa['imap_password'];
                        }
                    }
                    $newAccList = [];
                    foreach ($incSet['source_accounts'] as $idx => $ca) {
                        $emAddr = trim((string)($ca['email'] ?? ''));
                        $gUrl   = trim((string)($ca['gas_url'] ?? ''));
                        if ($emAddr === '' && $gUrl === '') continue;
                        $emKey = strtolower($emAddr);
                        $newAccList[] = [
                            'id' => !empty($ca['id']) ? (string)$ca['id'] : ('acc_' . ($idx + 1) . '_' . substr(md5($emAddr . $idx), 0, 6)),
                            'label' => trim((string)($ca['label'] ?? ('Email Pembaca #' . ($idx + 1)))),
                            'email' => $emAddr,
                            'mode' => in_array(($ca['mode'] ?? 'gas'), ['gas', 'imap'], true) ? $ca['mode'] : 'gas',
                            'gas_url' => $gUrl,
                            'imap_password' => !empty($ca['imap_password']) ? $ca['imap_password'] : ($oldPassByEmail[$emKey] ?? ''),
                            'enabled' => !isset($ca['enabled']) || !empty($ca['enabled'])
                        ];
                    }
                    if (!empty($newAccList)) {
                        $localSettings['source_accounts'] = $newAccList;
                        $changedAnything = true;
                    }
                } else {
                    // Mode union/merge: tambahkan akun email yang ada di browser Komputer Server tapi belum ada di settings.json
                    $existingEmails = [];
                    foreach ($localSettings['source_accounts'] ?? [] as $laIdx => $la) {
                        $existingEmails[strtolower(trim((string)($la['email'] ?? '')))] = $laIdx;
                    }
                    foreach ($incSet['source_accounts'] as $ca) {
                        $emAddr = trim((string)($ca['email'] ?? ''));
                        $gUrl   = trim((string)($ca['gas_url'] ?? ''));
                        if ($emAddr === '' && $gUrl === '') continue;
                        $emKey = strtolower($emAddr);
                        if (!isset($existingEmails[$emKey])) {
                            $localSettings['source_accounts'][] = [
                                'id' => !empty($ca['id']) ? (string)$ca['id'] : ('acc_' . uniqid()),
                                'label' => trim((string)($ca['label'] ?? $emAddr)),
                                'email' => $emAddr,
                                'mode' => in_array(($ca['mode'] ?? 'gas'), ['gas', 'imap'], true) ? $ca['mode'] : 'gas',
                                'gas_url' => $gUrl,
                                'imap_password' => $ca['imap_password'] ?? '',
                                'enabled' => !isset($ca['enabled']) || !empty($ca['enabled'])
                            ];
                            $changedAnything = true;
                        } elseif ($gUrl !== '' && empty($localSettings['source_accounts'][$existingEmails[$emKey]]['gas_url'])) {
                            $localSettings['source_accounts'][$existingEmails[$emKey]]['gas_url'] = $gUrl;
                            $changedAnything = true;
                        }
                    }
                }
            }

            foreach (['target_gdrive_email', 'email_subject_filter', 'gas_gdrive_webapp_url', 'gdrive_folder_name'] as $k) {
                if (!empty($incSet[$k]) && ($localSettings[$k] ?? '') !== $incSet[$k]) {
                    $localSettings[$k] = $incSet[$k];
                    $changedAnything = true;
                }
            }

            if ($changedAnything) {
                $localSettings = saveSettings($localSettings);
            }
        }

        // 2. Simpan file gambar baru dari ghImages browser ke folder storage/biovisa/ atau storage/dokumen/<cat>/
        if (!empty($payload['images']) && is_array($payload['images'])) {
            foreach ($payload['images'] as $imgKey => $dataUrl) {
                if (!is_string($dataUrl) || $dataUrl === '') continue;
                $b64 = (strpos($dataUrl, ',') !== false) ? explode(',', $dataUrl, 2)[1] : $dataUrl;
                $rawBytes = base64_decode($b64);
                if ($rawBytes === false || strlen($rawBytes) < 100) continue;

                if (strpos($imgKey, ':') !== false) {
                    [$catPart, $fnPart] = explode(':', $imgKey, 2);
                    $catPart = preg_replace('/[^a-z_]/', '', strtolower($catPart));
                    $fnPart  = basename($fnPart);
                    $dirPath = ($catPart === 'biovisa') ? getBiovisaDir() : getDocCategoryDir($catPart);
                } else {
                    $fnPart  = basename($imgKey);
                    $dirPath = getBiovisaDir();
                }
                if ($fnPart !== '' && is_dir($dirPath)) {
                    $destFile = $dirPath . DIRECTORY_SEPARATOR . $fnPart;
                    if (!is_file($destFile) || filesize($destFile) !== strlen($rawBytes)) {
                        file_put_contents($destFile, $rawBytes);
                        $changedAnything = true;
                    }
                }
            }
        }

        // 3. Gabungkan daftar records Biovisa dari Cloud Tab ke Server Lokal berdasarkan Nomor Paspor
        $needPullGDriveDocs = false;
        if (!empty($payload['records']) && is_array($payload['records'])) {
            $localByPaspor = [];
            foreach ($localRecords as $idx => $lr) {
                $p = strtoupper(trim((string)($lr['paspor'] ?? '')));
                if ($p !== '' && $p !== 'TANPAPASPOR') {
                    $localByPaspor[$p] = $idx;
                }
            }

            foreach ($payload['records'] as $cr) {
                if (!is_array($cr)) continue;
                $cp = strtoupper(trim((string)($cr['paspor'] ?? '')));
                if ($cp === '' || $cp === 'TANPAPASPOR') continue;

                if (!isset($localByPaspor[$cp])) {
                    // Jika file JPG belum ada di disk, render otomatis menggunakan template asli SaudiVisaBio <= 50KB
                    $fn = basename((string)($cr['filename'] ?? "{$cr['nama']}_{$cp}.jpg"));
                    $fp = getBiovisaDir() . DIRECTORY_SEPARATOR . $fn;
                    if (!is_file($fp)) {
                        $gd = BiovisaProcessor::renderEmailConfirmationToGd([
                            'nama' => $cr['nama'] ?? 'JAMAAH',
                            'paspor' => $cp,
                            'enrollment_id' => $cr['enrollment_id'] ?? '-',
                            'enrollment_time' => $cr['enrollment_time'] ?? date('Y-m-d H:i:s')
                        ]);
                        $bvLimits = getSiskohatCategoryCompressLimits('biovisa');
                        BiovisaProcessor::saveGdAs50KbJpg($gd, $fp, floatval($bvLimits['min_kb']), floatval($bvLimits['max_kb']), !empty($bvLimits['pad_if_under_min']));
                        imagedestroy($gd);
                    }
                    $cr['filename'] = $fn;
                    $cr['size_bytes'] = is_file($fp) ? filesize($fp) : ($cr['size_bytes'] ?? 49800);
                    $cr['size_kb'] = round($cr['size_bytes'] / 1024, 2);
                    array_unshift($localRecords, $cr);
                    $localByPaspor[$cp] = 0;
                    $changedAnything = true;
                } else {
                    $lIdx = $localByPaspor[$cp];
                    if (!empty($cr['siskohat_uploaded']) && empty($localRecords[$lIdx]['siskohat_uploaded'])) {
                        $localRecords[$lIdx]['siskohat_uploaded'] = true;
                        $localRecords[$lIdx]['siskohat_uploaded_at'] = $cr['siskohat_uploaded_at'] ?? date('Y-m-d H:i:s');
                        $changedAnything = true;
                    }
                    if (!empty($cr['gdrive_uploaded']) && empty($localRecords[$lIdx]['gdrive_uploaded'])) {
                        $localRecords[$lIdx]['gdrive_uploaded'] = true;
                        if (!empty($cr['gdrive_url'])) {
                            $localRecords[$lIdx]['gdrive_url'] = $cr['gdrive_url'];
                        }
                        $changedAnything = true;
                    }
                    if (!empty($cr['seven_docs']['docs']) && is_array($cr['seven_docs']['docs'])) {
                        foreach ($cr['seven_docs']['docs'] as $catKey => $cDoc) {
                            if ($catKey === 'biovisa' || empty($cDoc['exists'])) continue;
                            $foundLocal = findJamaahDocFile($catKey, $cp, (string)($localRecords[$lIdx]['nama'] ?? ''), '');
                            if (!$foundLocal) {
                                $needPullGDriveDocs = true;
                            }
                        }
                    }
                }
            }
        }

        if ($needPullGDriveDocs || !empty($payload['force_publish'])) {
            $pulled = pullMissingSevenDocsFromGDrive($localSettings);
            if ($pulled > 0) {
                $changedAnything = true;
            }
        }

        if ($changedAnything || !empty($payload['force_publish'])) {
            saveRecords($localRecords);
            triggerBackgroundGitHubPush('Auto-sync data Biovisa & 7 Dokumen dari Komputer Server ke GitHub Pages');
        } else {
            writeCloudSyncBundle();
        }

        $bundle = loadJsonFile(getDataDir() . DIRECTORY_SEPARATOR . 'cloud_sync_bundle.json', []);
        jsonResponse([
            'ok' => true,
            'changed' => $changedAnything,
            'bundle' => $bundle
        ]);
        break;

    case 'auth_me':
        $u = Auth::user();
        $lem = Auth::getLembagaBySlug(Auth::tenantSlug());
        jsonResponse([
            'ok' => true,
            'user' => $u,
            'lembaga' => $lem,
            'csrf_token' => Auth::csrfToken(),
        ]);
        break;

    case 'list_lembaga':
        Auth::requireSuperAdmin();
        jsonResponse(['ok' => true, 'lembaga' => Auth::getLembagaList()]);
        break;

    case 'save_lembaga':
        Auth::requireSuperAdmin();
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;
        jsonResponse(Auth::saveLembaga($payload));
        break;

    case 'list_users':
        Auth::requireSuperAdmin();
        $users = loadJsonFile(STORAGE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'users.json', []);
        foreach ($users as &$u) {
            unset($u['password_hash']);
        }
        unset($u);
        jsonResponse(['ok' => true, 'users' => $users, 'lembaga' => Auth::getLembagaList()]);
        break;

    case 'save_user':
        Auth::requireSuperAdmin();
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;
        jsonResponse(Auth::saveUser($payload));
        break;

    case 'switch_lembaga':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;
        $slug = trim((string)($payload['slug'] ?? ''));
        if ($slug === '' || !Auth::setActiveLembagaSlug($slug)) {
            jsonResponse(['ok' => false, 'error' => 'Gagal mengganti lembaga aktif.'], 400);
        }
        jsonResponse(['ok' => true, 'slug' => Auth::tenantSlug(), 'lembaga' => Auth::getLembagaBySlug(Auth::tenantSlug())]);
        break;

    case 'change_my_password':
        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true) ?: $_POST;
        jsonResponse(Auth::changeOwnPassword(
            (string)($payload['current_password'] ?? ''),
            (string)($payload['new_password'] ?? '')
        ));
        break;

    case 'auth_security':
        Auth::requireSuperAdmin();
        $sec = Auth::getSecrets();
        jsonResponse([
            'ok' => true,
            'api_token_preview' => substr($sec['api_token'] ?? '', 0, 8) . '…',
            'webhook_token_preview' => substr($sec['webhook_token'] ?? '', 0, 8) . '…',
            'block_public_cloud_sync' => !empty($sec['block_public_cloud_sync']),
        ]);
        break;

    default:
        jsonResponse(['ok' => false, 'error' => 'Action tidak dikenali: ' . $action], 400);
}


