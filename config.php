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
define('DOKUMEN_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'dokumen');
define('TEMP_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'temp');

/**
 * Definisi Resmi 7 Dokumen Pemvisaan Siskohat Gen.2
 * Urutan sesuai form ENTRY DATA PASPOR DAN DOKUMEN PEMVISAAN
 */
function getSiskohatDocCategories(): array {
    return [
        'paspor' => [
            'num' => 1,
            'key' => 'paspor',
            'short' => 'Paspor',
            'label' => '1. Scan Halaman Pertama Paspor',
            'siskohat_label' => 'Scan Halaman Pertama Paspor *',
            'folder' => 'paspor',
            'gdrive_subfolder' => '1_Paspor',
            'max_kb' => 49.5
        ],
        'endorsement' => [
            'num' => 2,
            'key' => 'endorsement',
            'short' => 'Endorsement',
            'label' => '2. Scan/Upload Halaman Endorsement',
            'siskohat_label' => 'Scan/Upload Halaman Endorsement',
            'folder' => 'endorsement',
            'gdrive_subfolder' => '2_Endorsement',
            'max_kb' => 49.5
        ],
        'vaksin' => [
            'num' => 3,
            'key' => 'vaksin',
            'short' => 'Vaksin',
            'label' => '3. Scan/Upload Keterangan Vaksin Meningitis *',
            'siskohat_label' => 'Scan/Upload Keterangan Vaksin Meningitis *',
            'folder' => 'vaksin',
            'gdrive_subfolder' => '3_Vaksin',
            'max_kb' => 49.5
        ],
        'kitas' => [
            'num' => 4,
            'key' => 'kitas',
            'short' => 'KITAS',
            'label' => '4. Scan/Upload Keterangan KITAS',
            'siskohat_label' => 'Scan/Upload Keterangan KITAS',
            'folder' => 'kitas',
            'gdrive_subfolder' => '4_Kitas',
            'max_kb' => 49.5
        ],
        'pendukung' => [
            'num' => 5,
            'key' => 'pendukung',
            'short' => 'Pendukung',
            'label' => '5. Scan/Upload Dokumen Pendukung',
            'siskohat_label' => 'Scan/Upload Dokumen Pendukung',
            'folder' => 'pendukung',
            'gdrive_subfolder' => '5_Pendukung',
            'max_kb' => 49.5
        ],
        'biovisa' => [
            'num' => 6,
            'key' => 'biovisa',
            'short' => 'Biovisa',
            'label' => '6. Scan/Upload Biovisa (jpeg)',
            'siskohat_label' => 'Scan/Upload Biovisa (jpeg) *',
            'folder' => 'biovisa',
            'gdrive_subfolder' => '6_Biovisa',
            'max_kb' => 49.5
        ],
        'foto' => [
            'num' => 7,
            'key' => 'foto',
            'short' => 'Foto',
            'label' => '7. Scan/Upload Foto Terbaru',
            'siskohat_label' => 'Scan/Upload Foto Terbaru *',
            'folder' => 'foto',
            'gdrive_subfolder' => '7_Foto',
            'max_kb' => 49.5
        ]
    ];
}

foreach ([STORAGE_DIR, DATA_DIR, BIOVISA_DIR, DOKUMEN_DIR, TEMP_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

foreach (getSiskohatDocCategories() as $catKey => $catMeta) {
    $subDir = DOKUMEN_DIR . DIRECTORY_SEPARATOR . $catMeta['folder'];
    if (!is_dir($subDir)) {
        mkdir($subDir, 0777, true);
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

/**
 * Dapatkan path folder fisik untuk kategori dokumen tertentu (1..7)
 */
function getDocCategoryDir(string $catKey): string {
    $cats = getSiskohatDocCategories();
    $folder = $cats[$catKey]['folder'] ?? $catKey;
    return DOKUMEN_DIR . DIRECTORY_SEPARATOR . $folder;
}

/**
 * Cari file fisik untuk seorang jamaah pada kategori tertentu (paspor, endorsement, vaksin, kitas, pendukung, biovisa, foto).
 * Mendukung pencocokan fleksibel:
 * - Nama_NomorPaspor.jpg (contoh: HALIMAH MUNTARICIPTO_Y2047436.jpg)
 * - NomorPaspor.jpg (contoh: Y2047436.jpg / Y2047436.jpeg / Y2047436.png)
 * - File apa pun di folder kategori tersebut yang mengandung Nomor Paspor jamaah
 * - Khusus kategori 'biovisa', memeriksa folder storage/biovisa/ dan storage/dokumen/biovisa/
 */
function findJamaahDocFile(string $catKey, string $paspor, string $nama = '', string $defaultBiovisaFile = ''): ?array {
    $pasporClean = strtoupper(trim($paspor));
    $namaClean   = strtoupper(trim($nama));

    $searchDirs = [];
    if ($catKey === 'biovisa') {
        $searchDirs[] = BIOVISA_DIR;
        $searchDirs[] = getDocCategoryDir('biovisa');
    } else {
        $searchDirs[] = getDocCategoryDir($catKey);
    }

    foreach ($searchDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }

        // 1. Jika ada defaultBiovisaFile atau format Nama_Paspor.jpg persis
        $candidates = [];
        if ($defaultBiovisaFile !== '') {
            $candidates[] = $defaultBiovisaFile;
        }
        if ($namaClean !== '' && $pasporClean !== '') {
            $candidates[] = "{$namaClean}_{$pasporClean}.jpg";
            $candidates[] = "{$namaClean}_{$pasporClean}.jpeg";
            $candidates[] = "{$namaClean}_{$pasporClean}.png";
        }
        if ($pasporClean !== '') {
            $candidates[] = "{$pasporClean}.jpg";
            $candidates[] = "{$pasporClean}.jpeg";
            $candidates[] = "{$pasporClean}.png";
        }

        foreach ($candidates as $cand) {
            $fullPath = $dir . DIRECTORY_SEPARATOR . $cand;
            if (is_file($fullPath)) {
                $bytes = filesize($fullPath);
                $relDir = ($dir === BIOVISA_DIR) ? 'storage/biovisa' : ('storage/dokumen/' . $catKey);
                return [
                    'exists' => true,
                    'category' => $catKey,
                    'filename' => basename($fullPath),
                    'path' => $fullPath,
                    'rel_url' => $relDir . '/' . rawurlencode(basename($fullPath)),
                    'size_bytes' => $bytes,
                    'size_kb' => round($bytes / 1024, 2)
                ];
            }
        }

        // 2. Scan isi folder untuk mencari file yang mengandung Nomor Paspor atau Nama Jamaah
        if ($pasporClean !== '' && $pasporClean !== 'TANPAPASPOR') {
            $files = @scandir($dir);
            if (is_array($files)) {
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
                    $fUpper = strtoupper(pathinfo($f, PATHINFO_FILENAME));
                    if (
                        $fUpper === $pasporClean ||
                        strpos($fUpper, '_' . $pasporClean) !== false ||
                        strpos($fUpper, $pasporClean . '_') !== false ||
                        strpos($fUpper, $pasporClean) !== false
                    ) {
                        $fullPath = $dir . DIRECTORY_SEPARATOR . $f;
                        if (is_file($fullPath)) {
                            $bytes = filesize($fullPath);
                            $relDir = ($dir === BIOVISA_DIR) ? 'storage/biovisa' : ('storage/dokumen/' . $catKey);
                            return [
                                'exists' => true,
                                'category' => $catKey,
                                'filename' => $f,
                                'path' => $fullPath,
                                'rel_url' => $relDir . '/' . rawurlencode($f),
                                'size_bytes' => $bytes,
                                'size_kb' => round($bytes / 1024, 2)
                            ];
                        }
                    }
                }
            }
        }
    }

    return null;
}

/**
 * Dapatkan peta kelengkapan 7 dokumen Siskohat untuk 1 record jamaah
 */
function getJamaahSevenDocsStatus(array $rec): array {
    $cats = getSiskohatDocCategories();
    $paspor = (string)($rec['paspor'] ?? '');
    $nama   = (string)($rec['nama'] ?? '');
    $bvFile = (string)($rec['filename'] ?? '');

    $docs = [];
    $availableCount = 0;
    foreach ($cats as $catKey => $meta) {
        $found = findJamaahDocFile($catKey, $paspor, $nama, $catKey === 'biovisa' ? $bvFile : '');
        if ($found) {
            $availableCount++;
            $docs[$catKey] = [
                'exists' => true,
                'num' => $meta['num'],
                'key' => $catKey,
                'short' => $meta['short'],
                'label' => $meta['label'],
                'filename' => $found['filename'],
                'rel_url' => $found['rel_url'],
                'size_kb' => $found['size_kb']
            ];
        } else {
            $docs[$catKey] = [
                'exists' => false,
                'num' => $meta['num'],
                'key' => $catKey,
                'short' => $meta['short'],
                'label' => $meta['label'],
                'filename' => '',
                'rel_url' => '',
                'size_kb' => 0
            ];
        }
    }

    return [
        'available_count' => $availableCount,
        'total_slots' => count($cats),
        'docs' => $docs
    ];
}
