<?php
/**
 * Konfigurasi Utama Aplikasi Auto-BioVisa PHU Pasuruan
 * Email Sumber : phujtmkopasuruan@gmail.com
 * GDrive Tujuan: muchammad.ali.sanjaya@gmail.com
 */

date_default_timezone_set('Asia/Jakarta');

define('BASE_DIR', __DIR__);
define('STORAGE_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'storage');
define('DATA_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'data');
define('BIOVISA_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'biovisa');
define('TEMP_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'temp');

foreach ([STORAGE_DIR, DATA_DIR, BIOVISA_DIR, TEMP_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

define('SETTINGS_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'settings.json');
define('RECORDS_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'records.json');
define('JAMAAH_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'jamaah_master.json');
define('LOGS_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'sync_logs.json');

function getDefaultSettings(): array {
    return [
        'source_email' => 'phujtmkopasuruan@gmail.com',
        'target_gdrive_email' => 'muchammad.ali.sanjaya@gmail.com',
        'email_subject_filter' => 'Enrollment confirmation',
        'connection_mode' => 'gas', // 'gas' (Google Apps Script) atau 'imap' (Gmail App Password)
        'imap_app_password' => '',
        'gas_gmail_webapp_url' => '',
        'source_accounts' => [], // Daftar multi-email pembaca Enrollment confirmation
        'gas_gdrive_webapp_url' => '',
        'gdrive_folder_name' => 'Biovisa_Jamaah_PHU_Pasuruan',
        'gdrive_folder_id' => '',
        'auto_sync_enabled' => true,
        'auto_sync_interval_sec' => 30,
        'auto_download_browser' => true,
        'auto_upload_gdrive' => true,
        'process_mode' => 'both', // 'both', 'render_only', 'attachment_only'
        'target_min_kb' => 42,
        'target_max_kb' => 49.5,
        'strict_50kb_padding' => true, // Jaga ukuran file di kisaran ~45-49.5 KB (<= 50 KB)
        'filename_pattern' => '{NAMA}_{PASPOR}', // Format: Nama_Nomor paspor.jpg
        'uppercase_filename' => true,
        'last_sync_at' => null,
        'last_sync_status' => 'Belum pernah sinkronisasi'
    ];
}

function loadJsonFile(string $path, array $default = []): array {
    if (!file_exists($path)) {
        return $default;
    }
    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return $default;
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : $default;
}

function saveJsonFile(string $path, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function getSettings(): array {
    $saved = loadJsonFile(SETTINGS_FILE, []);
    $merged = array_merge(getDefaultSettings(), $saved);

    // Migrasi otomatis: jika source_accounts masih kosong, bentuk Akun #1 dari pengaturan tunggal
    if (empty($merged['source_accounts']) || !is_array($merged['source_accounts'])) {
        $merged['source_accounts'] = [
            [
                'id' => 'acc_primary',
                'label' => 'Email Utama PHU Pasuruan',
                'email' => $merged['source_email'] ?: 'phujtmkopasuruan@gmail.com',
                'mode' => $merged['connection_mode'] ?: 'gas',
                'gas_url' => $merged['gas_gmail_webapp_url'] ?? '',
                'imap_password' => $merged['imap_app_password'] ?? '',
                'enabled' => true
            ]
        ];
    } else {
        // Sinkronkan akun aktif pertama ke field legacy agar kompatibel
        foreach ($merged['source_accounts'] as $acc) {
            if (!empty($acc['enabled'])) {
                $merged['source_email'] = $acc['email'] ?? $merged['source_email'];
                $merged['connection_mode'] = $acc['mode'] ?? $merged['connection_mode'];
                $merged['gas_gmail_webapp_url'] = $acc['gas_url'] ?? $merged['gas_gmail_webapp_url'];
                $merged['imap_app_password'] = $acc['imap_password'] ?? $merged['imap_app_password'];
                break;
            }
        }
    }

    return $merged;
}

function saveSettings(array $newSettings): array {
    $current = getSettings();
    $merged = array_merge($current, $newSettings);
    saveJsonFile(SETTINGS_FILE, $merged);
    return $merged;
}

function getRecords(): array {
    return loadJsonFile(RECORDS_FILE, []);
}

function saveRecords(array $records): bool {
    return saveJsonFile(RECORDS_FILE, array_values($records));
}

function getJamaahMaster(): array {
    return loadJsonFile(JAMAAH_FILE, []);
}

function saveJamaahMaster(array $list): bool {
    return saveJsonFile(JAMAAH_FILE, $list);
}

function addSyncLog(string $type, string $message, array $context = []): void {
    $logs = loadJsonFile(LOGS_FILE, []);
    // Hindari spam log error/info berulang yang identik dengan entri paling atas
    if (!empty($logs) && ($logs[0]['message'] ?? '') === $message && ($logs[0]['type'] ?? '') === $type) {
        $logs[0]['time'] = date('Y-m-d H:i:s');
        saveJsonFile(LOGS_FILE, $logs);
        return;
    }
    array_unshift($logs, [
        'id' => uniqid('log_'),
        'time' => date('Y-m-d H:i:s'),
        'type' => $type, // 'info', 'success', 'warning', 'error'
        'message' => $message,
        'context' => $context
    ]);
    if (count($logs) > 150) {
        $logs = array_slice($logs, 0, 150);
    }
    saveJsonFile(LOGS_FILE, $logs);
}
