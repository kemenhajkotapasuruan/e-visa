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

// Izinkan akses CORS untuk Ekstensi Chrome Siskohat Gen.2
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Allow-Private-Network: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

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
    $zipPath = TEMP_DIR . DIRECTORY_SEPARATOR . $zipFilename;

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
    $file = basename($_GET['file'] ?? '');
    $path = BIOVISA_DIR . DIRECTORY_SEPARATOR . $file;
    if ($file === '' || !file_exists($path)) {
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
    $zipPath = TEMP_DIR . DIRECTORY_SEPARATOR . $zipFilename;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo "Gagal membuat arsip ZIP.";
        exit;
    }

    $added = 0;
    foreach ($records as $rec) {
        $fn = $rec['filename'] ?? '';
        $fp = BIOVISA_DIR . DIRECTORY_SEPARATOR . $fn;
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

    // Cek apakah paspor atau message_id sudah ada, jika ada update
    $updated = false;
    foreach ($records as $idx => $existing) {
        if (
            ($existing['paspor'] !== 'TANPAPASPOR' && $existing['paspor'] === $processedRecord['paspor']) ||
            ($existing['message_id'] === $processedRecord['message_id'])
        ) {
            // Hapus file lama jika namanya berubah
            if (!empty($existing['filename']) && $existing['filename'] !== $processedRecord['filename']) {
                $oldPath = BIOVISA_DIR . DIRECTORY_SEPARATOR . $existing['filename'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $records[$idx] = $processedRecord;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        array_unshift($records, $processedRecord);
    }

    saveRecords($records);
    return $processedRecord;
}

// Routing Action
switch ($action) {
    case 'status':
        $settings = getSettings();
        $records  = getRecords();
        $jamaah   = getJamaahMaster();
        $logs     = loadJsonFile(LOGS_FILE, []);

        $totalRecords = count($records);
        $uploadedGdrive = 0;
        $under50KbCount = 0;
        $totalKb = 0;

        foreach ($records as &$r) {
            $fp = BIOVISA_DIR . DIRECTORY_SEPARATOR . ($r['filename'] ?? '');
            $r['file_exists'] = ($r['filename'] ?? '') !== '' && file_exists($fp);
            if ($r['file_exists']) {
                $r['size_bytes'] = filesize($fp);
                $r['size_kb'] = round($r['size_bytes'] / 1024, 2);
            }
            if (!empty($r['gdrive_uploaded'])) {
                $uploadedGdrive++;
            }
            if (($r['size_kb'] ?? 0) <= 50.0) {
                $under50KbCount++;
            }
            $totalKb += ($r['size_kb'] ?? 0);
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

        $existingMsgIds = array_values(array_filter(array_column($records, 'message_id')));
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
        foreach ($fetchRes['emails'] as $emailItem) {
            $processed = BiovisaProcessor::processIncomingEmail($emailItem, $settings, $jamaah);
            // Jangan simpan jika email tidak memiliki nomor paspor (misal email under review / pemberitahuan lain)
            if (($processed['paspor'] ?? 'TANPAPASPOR') === 'TANPAPASPOR') {
                $badFile = BIOVISA_DIR . DIRECTORY_SEPARATOR . ($processed['filename'] ?? '');
                if (!empty($processed['filename']) && file_exists($badFile)) {
                    @unlink($badFile);
                }
                continue;
            }
            $saved = upsertAndSyncRecord($processed, $settings);
            $newRecords[] = $saved;
            addSyncLog(
                'success',
                "Email Enrollment Confirmation diproses -> {$saved['filename']} ({$saved['size_kb']} KB)"
            );
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

        $settings = getSettings();
        $records = getRecords();
        $foundIdx = -1;

        foreach ($records as $idx => $r) {
            if (($r['id'] ?? '') === $id) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx === -1) {
            jsonResponse(['ok' => false, 'error' => 'Data Biovisa tidak ditemukan.'], 404);
        }

        $rec = $records[$foundIdx];
        $oldFilename = $rec['filename'] ?? '';

        // Render ulang gambar dengan Nama & Nomor Paspor baru
        $newFilename = BiovisaProcessor::buildFilename($newNama, $newPaspor, $settings);
        $newPath = BIOVISA_DIR . DIRECTORY_SEPARATOR . $newFilename;

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

        $gd = BiovisaProcessor::renderEmailConfirmationToGd($renderData);
        $savedImg = BiovisaProcessor::saveGdAs50KbJpg(
            $gd,
            $newPath,
            floatval($settings['target_min_kb'] ?? 42),
            floatval($settings['target_max_kb'] ?? 49.5),
            !empty($settings['strict_50kb_padding'])
        );
        imagedestroy($gd);

        if ($oldFilename !== '' && $oldFilename !== $newFilename) {
            $oldPath = BIOVISA_DIR . DIRECTORY_SEPARATOR . $oldFilename;
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
        saveRecords($records);
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

        $records = getRecords();
        $remaining = [];
        $deletedCount = 0;
        foreach ($records as $r) {
            if (in_array(($r['id'] ?? ''), $ids, true)) {
                $fp = BIOVISA_DIR . DIRECTORY_SEPARATOR . ($r['filename'] ?? '');
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
        addSyncLog('info', 'Pengaturan sistem & integrasi berhasil disimpan.');

        jsonResponse([
            'ok' => true,
            'message' => 'Pengaturan berhasil disimpan.',
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
        saveJsonFile(LOGS_FILE, []);
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
            $fp = BIOVISA_DIR . DIRECTORY_SEPARATOR . $fn;
            if ($fn === '' || !file_exists($fp)) {
                continue;
            }
            $queue[] = [
                'id' => $r['id'] ?? '',
                'nama' => $r['nama'] ?? '',
                'paspor' => $r['paspor'] ?? '',
                'enrollment_id' => $r['enrollment_id'] ?? '',
                'filename' => $fn,
                'size_kb' => $r['size_kb'] ?? round(filesize($fp) / 1024, 2),
                'siskohat_uploaded' => !empty($r['siskohat_uploaded']),
                'siskohat_uploaded_at' => $r['siskohat_uploaded_at'] ?? ''
            ];
        }
        jsonResponse([
            'ok' => true,
            'count' => count($queue),
            'records' => $queue
        ]);
        break;

    case 'siskohat_get_file':
        $id = trim((string)($_GET['id'] ?? ''));
        $paspor = strtoupper(trim((string)($_GET['paspor'] ?? '')));
        $nama = strtoupper(trim((string)($_GET['nama'] ?? '')));

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
            jsonResponse(['ok' => false, 'error' => 'Data Biovisa jamaah tidak ditemukan di Laragon.'], 404);
        }

        $fn = $matched['filename'] ?? '';
        $fp = BIOVISA_DIR . DIRECTORY_SEPARATOR . $fn;
        if ($fn === '' || !file_exists($fp)) {
            jsonResponse(['ok' => false, 'error' => 'File JPG tidak ditemukan: ' . $fn], 404);
        }

        $bytes = file_get_contents($fp);
        jsonResponse([
            'ok' => true,
            'record' => [
                'id' => $matched['id'] ?? '',
                'nama' => $matched['nama'] ?? '',
                'paspor' => $matched['paspor'] ?? '',
                'enrollment_id' => $matched['enrollment_id'] ?? '',
                'filename' => $fn,
                'size_kb' => round(strlen($bytes) / 1024, 2),
                'base64' => base64_encode($bytes)
            ]
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
        saveJsonFile(DATA_DIR . DIRECTORY_SEPARATOR . 'siskohat_dom_debug.json', $payload);
        jsonResponse(['ok' => true]);
        break;

    default:
        jsonResponse(['ok' => false, 'error' => 'Action tidak dikenali: ' . $action], 400);
}
