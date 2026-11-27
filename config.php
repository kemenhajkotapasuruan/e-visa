<?php
/**
 * Konfigurasi Utama Aplikasi Auto-BioVisa PHU Pasuruan
 * Email Sumber : phujtmkopasuruan@gmail.com
 * GDrive Tujuan: muchammad.ali.sanjaya@gmail.com
 */

date_default_timezone_set('Asia/Jakarta');

define('BASE_DIR', __DIR__);
define('STORAGE_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'storage');

/** @var array<string, string> path aktif per konteks lembaga */
$GLOBALS['TENANT_PATHS'] = [
    'slug' => 'phu-pasuruan',
    'root' => '',
    'data' => '',
    'biovisa' => '',
    'dokumen' => '',
    'temp' => '',
];

function tenantSlugActive(): string
{
    $slug = $GLOBALS['TENANT_PATHS']['slug'] ?? 'phu-pasuruan';
    $slug = strtolower(trim($slug));
    return preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?: 'phu-pasuruan';
}

function tenantEnsureDirs(string $slug): void
{
    $slug = strtolower(preg_replace('/[^a-z0-9\-]+/', '-', trim($slug)));
    $root = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga' . DIRECTORY_SEPARATOR . $slug;
    foreach ([$root, $root . '/data', $root . '/biovisa', $root . '/dokumen', $root . '/temp'] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
    foreach (getSiskohatDocCategories() as $catMeta) {
        $sub = $root . DIRECTORY_SEPARATOR . 'dokumen' . DIRECTORY_SEPARATOR . $catMeta['folder'];
        if (!is_dir($sub)) {
            @mkdir($sub, 0755, true);
        }
    }
    tenantInitEmptyDataFiles($slug);
}

/**
 * Inisialisasi berkas data tenant kosong (tanpa menyalin data instansi lain).
 */
function tenantInitEmptyDataFiles(string $slug): void
{
    $slug = strtolower(preg_replace('/[^a-z0-9\-]+/', '-', trim($slug)));
    $dataDir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga' . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0755, true);
    }
    foreach ([
        'records.json' => [],
        'jamaah_master.json' => [],
        'sync_logs.json' => [],
    ] as $file => $default) {
        $path = $dataDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            $json = json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents($path, $json, LOCK_EX);
        }
    }
}

function tenantBindPaths(string $slug): void
{
    $slug = strtolower(preg_replace('/[^a-z0-9\-]+/', '-', trim($slug)));
    tenantEnsureDirs($slug);
    $root = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga' . DIRECTORY_SEPARATOR . $slug;
    $GLOBALS['TENANT_PATHS'] = [
        'slug' => $slug,
        'root' => $root,
        'data' => $root . DIRECTORY_SEPARATOR . 'data',
        'biovisa' => $root . DIRECTORY_SEPARATOR . 'biovisa',
        'dokumen' => $root . DIRECTORY_SEPARATOR . 'dokumen',
        'temp' => $root . DIRECTORY_SEPARATOR . 'temp',
    ];
}

function getDataDir(): string
{
    if (!empty($GLOBALS['TENANT_PATHS']['data'])) {
        return $GLOBALS['TENANT_PATHS']['data'];
    }
    tenantBindPaths('phu-pasuruan');
    return $GLOBALS['TENANT_PATHS']['data'];
}

function getBiovisaDir(): string
{
    if (!empty($GLOBALS['TENANT_PATHS']['biovisa'])) {
        return $GLOBALS['TENANT_PATHS']['biovisa'];
    }
    tenantBindPaths('phu-pasuruan');
    return $GLOBALS['TENANT_PATHS']['biovisa'];
}

function getDokumenDir(): string
{
    if (!empty($GLOBALS['TENANT_PATHS']['dokumen'])) {
        return $GLOBALS['TENANT_PATHS']['dokumen'];
    }
    tenantBindPaths('phu-pasuruan');
    return $GLOBALS['TENANT_PATHS']['dokumen'];
}

function getTempDir(): string
{
    if (!empty($GLOBALS['TENANT_PATHS']['temp'])) {
        return $GLOBALS['TENANT_PATHS']['temp'];
    }
    tenantBindPaths('phu-pasuruan');
    return $GLOBALS['TENANT_PATHS']['temp'];
}

// Kompatibilitas kode lama
if (!defined('DATA_DIR')) {
    define('DATA_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'data');
}
if (!defined('BIOVISA_DIR')) {
    define('BIOVISA_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'biovisa');
}
if (!defined('DOKUMEN_DIR')) {
    define('DOKUMEN_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'dokumen');
}
if (!defined('TEMP_DIR')) {
    define('TEMP_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'temp');
}

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
            'max_kb' => 200.0,
            'min_kb' => 42.0
        ],
        'endorsement' => [
            'num' => 2,
            'key' => 'endorsement',
            'short' => 'Endorsement',
            'label' => '2. Scan/Upload Halaman Endorsement',
            'siskohat_label' => 'Scan/Upload Halaman Endorsement',
            'folder' => 'endorsement',
            'gdrive_subfolder' => '2_Endorsement',
            'max_kb' => 50.0,
            'min_kb' => 35.0
        ],
        'vaksin' => [
            'num' => 3,
            'key' => 'vaksin',
            'short' => 'Vaksin',
            'label' => '3. Scan/Upload Keterangan Vaksin Meningitis *',
            'siskohat_label' => 'Scan/Upload Keterangan Vaksin Meningitis *',
            'folder' => 'vaksin',
            'gdrive_subfolder' => '3_Vaksin',
            'max_kb' => 50.0,
            'min_kb' => 35.0
        ],
        'kitas' => [
            'num' => 4,
            'key' => 'kitas',
            'short' => 'KITAS',
            'label' => '4. Scan/Upload Keterangan KITAS',
            'siskohat_label' => 'Scan/Upload Keterangan KITAS',
            'folder' => 'kitas',
            'gdrive_subfolder' => '4_Kitas',
            'max_kb' => 50.0,
            'min_kb' => 35.0
        ],
        'pendukung' => [
            'num' => 5,
            'key' => 'pendukung',
            'short' => 'Pendukung',
            'label' => '5. Scan/Upload Dokumen Pendukung',
            'siskohat_label' => 'Scan/Upload Dokumen Pendukung',
            'folder' => 'pendukung',
            'gdrive_subfolder' => '5_Pendukung',
            'max_kb' => 50.0,
            'min_kb' => 35.0
        ],
        'biovisa' => [
            'num' => 6,
            'key' => 'biovisa',
            'short' => 'Biovisa',
            'label' => '6. Scan/Upload Biovisa (jpeg)',
            'siskohat_label' => 'Scan/Upload Biovisa (jpeg) *',
            'folder' => 'biovisa',
            'gdrive_subfolder' => '6_Biovisa',
            'max_kb' => 100.0,
            'min_kb' => 42.0
        ],
        'foto' => [
            'num' => 7,
            'key' => 'foto',
            'short' => 'Foto',
            'label' => '7. Scan/Upload Foto Terbaru',
            'siskohat_label' => 'Scan/Upload Foto Terbaru *',
            'folder' => 'foto',
            'gdrive_subfolder' => '7_Foto',
            'max_kb' => 100.0,
            'min_kb' => 42.0
        ]
    ];
}

/**
 * Batas kompresi JPEG per kategori dokumen Siskohat (upload/scan).
 */
function getSiskohatCategoryCompressLimits(string $catKey): array
{
    $cats = getSiskohatDocCategories();
    if (!isset($cats[$catKey])) {
        return ['min_kb' => 35.0, 'max_kb' => 50.0, 'pad_if_under_min' => true];
    }
    $meta = $cats[$catKey];
    $maxKb = floatval($meta['max_kb'] ?? 50.0);
    $minKb = floatval($meta['min_kb'] ?? ($maxKb <= 55 ? 35.0 : 42.0));
    $settings = getSettings();
    $pad = ($maxKb <= 50.5) && !empty($settings['strict_50kb_padding']);
    return [
        'min_kb' => $minKb,
        'max_kb' => $maxKb,
        'pad_if_under_min' => $pad
    ];
}

foreach ([STORAGE_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

tenantBindPaths('phu-pasuruan');

function getSettingsFile(): string
{
    return getDataDir() . DIRECTORY_SEPARATOR . 'settings.json';
}

function getRecordsFile(): string
{
    return getDataDir() . DIRECTORY_SEPARATOR . 'records.json';
}

function getJamaahFile(): string
{
    return getDataDir() . DIRECTORY_SEPARATOR . 'jamaah_master.json';
}

function getLogsFile(): string
{
    return getDataDir() . DIRECTORY_SEPARATOR . 'sync_logs.json';
}

// Konstanta legacy — gunakan getter di atas untuk path tenant
if (!defined('SETTINGS_FILE')) {
    define('SETTINGS_FILE', getSettingsFile());
}
if (!defined('RECORDS_FILE')) {
    define('RECORDS_FILE', getRecordsFile());
}
if (!defined('JAMAAH_FILE')) {
    define('JAMAAH_FILE', getJamaahFile());
}
if (!defined('LOGS_FILE')) {
    define('LOGS_FILE', getLogsFile());
}

function getDefaultSettings(): array {
    return [
        'source_email' => '',
        'target_gdrive_email' => '',
        'email_subject_filter' => 'Enrollment confirmation',
        'connection_mode' => 'gas', // 'gas' (Google Apps Script) atau 'imap' (Gmail App Password)
        'imap_app_password' => '',
        'gas_gmail_webapp_url' => '',
        'source_accounts' => [], // Daftar multi-email pembaca Enrollment confirmation
        'gas_gdrive_webapp_url' => '',
        'gdrive_folder_name' => '',
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
    if (!is_file($path)) {
        return $default;
    }
    $maxBytes = 48 * 1024 * 1024;
    $size = @filesize($path);
    if ($size === false || $size > $maxBytes) {
        error_log('[e-visa] loadJsonFile: berkas terlalu besar atau tidak terbaca — ' . $path . ' (' . (string)$size . ' byte)');
        return $default;
    }
    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return $default;
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        error_log('[e-visa] loadJsonFile: JSON tidak valid — ' . $path);
        return $default;
    }
    return $decoded;
}

function saveJsonFile(string $path, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function getSettings(): array {
    $saved = loadJsonFile(getSettingsFile(), []);
    $merged = array_merge(getDefaultSettings(), $saved);

    // Migrasi otomatis: bentuk Akun #1 hanya jika email sumber instansi sudah dikonfigurasi
    if (empty($merged['source_accounts']) || !is_array($merged['source_accounts'])) {
        $merged['source_accounts'] = [];
        $primaryEmail = trim((string)($merged['source_email'] ?? ''));
        if ($primaryEmail !== '') {
            $merged['source_accounts'] = [
                [
                    'id' => 'acc_primary',
                    'label' => 'Email Utama Instansi',
                    'email' => $primaryEmail,
                    'mode' => $merged['connection_mode'] ?: 'gas',
                    'gas_url' => $merged['gas_gmail_webapp_url'] ?? '',
                    'imap_password' => $merged['imap_app_password'] ?? '',
                    'enabled' => true
                ]
            ];
        }
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

    $merged = applyLembagaSettingsOverlay($merged);
    return $merged;
}

function applyLembagaSettingsOverlay(array $settings): array
{
    $slug = tenantSlugActive();
    $lemFile = STORAGE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'lembaga.json';
    if (!is_file($lemFile)) {
        return $settings;
    }
    $list = loadJsonFile($lemFile, []);
    foreach ($list as $lem) {
        if (($lem['slug'] ?? '') !== $slug) {
            continue;
        }
        foreach ([
            'source_email', 'target_gdrive_email', 'gdrive_folder_name',
            'gas_gdrive_webapp_url', 'gas_gmail_webapp_url'
        ] as $k) {
            if (!empty($lem[$k])) {
                $settings[$k] = $lem[$k];
            }
        }
        if (!empty($lem['source_accounts']) && is_array($lem['source_accounts'])) {
            $settings['source_accounts'] = $lem['source_accounts'];
        }
        break;
    }
    return $settings;
}

function saveSettings(array $newSettings): array {
    $current = getSettings();
    $merged = array_merge($current, $newSettings);
    saveJsonFile(getSettingsFile(), $merged);
    return $merged;
}

function normalizeLegacySourceAccountLabels(array $records, bool &$changed = false): array
{
    $legacyPrimaryLabel = 'Email Utama PHU Pasuruan';
    $newPrimaryLabel = 'Email Utama Kemenhaj Pasuruan';

    foreach ($records as &$rec) {
        if (!is_array($rec)) {
            continue;
        }
        $lbl = trim((string)($rec['source_account_label'] ?? ''));
        if ($lbl === $legacyPrimaryLabel) {
            $rec['source_account_label'] = $newPrimaryLabel;
            $changed = true;
        }
    }
    unset($rec);

    return $records;
}

function getRecords(): array {
    $file = getRecordsFile();
    $records = loadJsonFile($file, []);
    $changed = false;
    $records = normalizeLegacySourceAccountLabels($records, $changed);
    if ($changed) {
        saveJsonFile($file, array_values($records));
    }
    return $records;
}

/**
 * Kumpulkan seluruh record dari semua_storage/lembaga/{slug}/data/records.json.
 * Dipakai Super Admin untuk melihat agregasi seluruh instansi.
 */
function getAllTenantsRecords(): array {
    $all = [];
    $base = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga';
    if (!is_dir($base)) {
        return $all;
    }
    foreach (glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $slugDir) {
        $slug = basename($slugDir);
        $file = $slugDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'records.json';
        $records = loadJsonFile($file, []);
        $changed = false;
        $records = normalizeLegacySourceAccountLabels($records, $changed);
        if ($changed) {
            saveJsonFile($file, array_values($records));
        }
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $rec['lembaga_slug'] = $slug;
            $all[] = $rec;
        }
    }
    usort($all, static function ($a, $b) {
        $ta = strtotime((string)($a['processed_at'] ?? $a['email_date'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['processed_at'] ?? $b['email_date'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });
    return $all;
}

/**
 * Ambil nama lembaga berdasarkan slug (untuk label agregat).
 */
function getLembagaNameBySlug(string $slug): string {
    static $map = null;
    if ($map === null) {
        $map = [];
        $file = STORAGE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'lembaga.json';
        foreach (loadJsonFile($file, []) as $lem) {
            $map[(string)($lem['slug'] ?? '')] = (string)($lem['nama'] ?? '');
        }
    }
    return $map[$slug] ?? $slug;
}

/**
 * Daftar slug folder storage/lembaga yang ada.
 */
function listTenantStorageSlugs(): array
{
    $base = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga';
    if (!is_dir($base)) {
        return ['phu-pasuruan'];
    }
    $slugs = [];
    foreach (glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if ($slug !== '') {
            $slugs[] = $slug;
        }
    }
    return $slugs === [] ? ['phu-pasuruan'] : $slugs;
}

/**
 * Cari record jamaah/Biovisa di storage tenant (multi-lembaga & Super Admin).
 * Mengembalikan slug tenant, indeks array, serta snapshot records.
 */
function locateRecordInTenantStores(string $recordId, string $hintSlug = ''): ?array
{
    $recordId = trim($recordId);
    if ($recordId === '') {
        return null;
    }

    $slugs = [];
    $hintSlug = strtolower(preg_replace('/[^a-z0-9\-]+/', '-', trim($hintSlug)));
    if ($hintSlug !== '') {
        $slugs[] = $hintSlug;
    }

    if (class_exists('Auth') && method_exists('Auth', 'isLoggedIn') && Auth::isLoggedIn()) {
        if (Auth::isSuperAdmin()) {
            if ($slugs === []) {
                $slugs = Auth::accessibleSlugs();
            }
        } else {
            $slugs = [Auth::tenantSlug()];
        }
    }

    if ($slugs === []) {
        $slugs = [tenantSlugActive()];
    }

    $tried = [];
    foreach ($slugs as $slug) {
        if ($slug === '' || isset($tried[$slug])) {
            continue;
        }
        $tried[$slug] = true;
        tenantBindPaths($slug);
        $records = getRecords();
        foreach ($records as $idx => $r) {
            if (($r['id'] ?? '') === $recordId) {
                return [
                    'slug' => $slug,
                    'index' => $idx,
                    'record' => $r,
                    'records' => $records,
                ];
            }
        }
    }

    if (class_exists('Auth') && method_exists('Auth', 'isLoggedIn') && Auth::isLoggedIn() && Auth::isSuperAdmin()) {
        foreach (listTenantStorageSlugs() as $slug) {
            if (isset($tried[$slug])) {
                continue;
            }
            tenantBindPaths($slug);
            $records = getRecords();
            foreach ($records as $idx => $r) {
                if (($r['id'] ?? '') === $recordId) {
                    return [
                        'slug' => $slug,
                        'index' => $idx,
                        'record' => $r,
                        'records' => $records,
                    ];
                }
            }
        }
    }

    return null;
}

function bindTenantFromRecordPayload(array $payload): void
{
    $hint = strtolower(preg_replace('/[^a-z0-9\-]+/', '-', trim((string)($payload['lembaga'] ?? $payload['lembaga_slug'] ?? ''))));
    if ($hint === '') {
        return;
    }
    if (class_exists('Auth') && method_exists('Auth', 'isLoggedIn') && Auth::isLoggedIn()) {
        if (Auth::isSuperAdmin() || Auth::tenantSlug() === $hint) {
            tenantBindPaths($hint);
        }
        return;
    }
    tenantBindPaths($hint);
}

function saveRecords(array $records): bool {
    return saveJsonFile(getRecordsFile(), array_values($records));
}

function getJamaahMaster(): array {
    return loadJsonFile(getJamaahFile(), []);
}

function saveJamaahMaster(array $list): bool {
    return saveJsonFile(getJamaahFile(), $list);
}

function addSyncLog(string $type, string $message, array $context = []): void {
    $logs = loadJsonFile(getLogsFile(), []);
    // Hindari spam log error/info berulang yang identik dengan entri paling atas
    if (!empty($logs) && ($logs[0]['message'] ?? '') === $message && ($logs[0]['type'] ?? '') === $type) {
        $logs[0]['time'] = date('Y-m-d H:i:s');
        saveJsonFile(getLogsFile(), $logs);
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
    saveJsonFile(getLogsFile(), $logs);
}

/**
 * Dapatkan path folder fisik untuk kategori dokumen tertentu (1..7)
 */
function getDocCategoryDir(string $catKey): string {
    $cats = getSiskohatDocCategories();
    $folder = $cats[$catKey]['folder'] ?? $catKey;
    return getDokumenDir() . DIRECTORY_SEPARATOR . $folder;
}

/**
 * Cari file fisik untuk seorang jamaah pada kategori tertentu (paspor, endorsement, vaksin, kitas, pendukung, biovisa, foto).
 * Mendukung pencocokan fleksibel:
 * - Nama_NomorPaspor.jpg (contoh: HALIMAH MUNTARICIPTO_Y2047436.jpg)
 * - NomorPaspor.jpg (contoh: Y2047436.jpg / Y2047436.jpeg / Y2047436.png)
 * - File apa pun di folder kategori tersebut yang mengandung Nomor Paspor jamaah
 * - Khusus kategori 'biovisa', memeriksa folder storage/biovisa/ dan storage/dokumen/biovisa/
 */
function findJamaahDocFile(string $catKey, string $paspor, string $nama = '', string $defaultBiovisaFile = '', string $slug = ''): ?array {
    $pasporClean = strtoupper(trim($paspor));
    $namaClean   = strtoupper(trim($nama));

    $slug = $slug !== '' ? $slug : tenantSlugActive();
    $tenantRoot = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga' . DIRECTORY_SEPARATOR . $slug;
    $tenantBiovisa = $tenantRoot . DIRECTORY_SEPARATOR . 'biovisa';
    $tenantDoc = $tenantRoot . DIRECTORY_SEPARATOR . 'dokumen';
    $tenantCatDir = $tenantDoc . DIRECTORY_SEPARATOR . (getSiskohatDocCategories()[$catKey]['folder'] ?? $catKey);

    $searchDirs = [];
    if ($catKey === 'biovisa') {
        $searchDirs[] = $tenantBiovisa;
        $searchDirs[] = $tenantCatDir;
        if ($slug === 'phu-pasuruan') {
            $searchDirs[] = BIOVISA_DIR;
            $searchDirs[] = DOKUMEN_DIR . DIRECTORY_SEPARATOR . 'biovisa';
        }
    } else {
        $searchDirs[] = $tenantCatDir;
        if ($slug === 'phu-pasuruan') {
            $searchDirs[] = DOKUMEN_DIR . DIRECTORY_SEPARATOR . (getSiskohatDocCategories()[$catKey]['folder'] ?? $catKey);
        }
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
                $relDir = ($dir === $tenantBiovisa) ? ('storage/lembaga/' . $slug . '/biovisa') : ('storage/lembaga/' . $slug . '/dokumen/' . $catKey);
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
                            $relDir = ($dir === $tenantBiovisa) ? ('storage/lembaga/' . $slug . '/biovisa') : ('storage/lembaga/' . $slug . '/dokumen/' . $catKey);
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
function getJamaahSevenDocsStatus(array $rec, string $slug = ''): array {
    $cats = getSiskohatDocCategories();
    $paspor = (string)($rec['paspor'] ?? '');
    $nama   = (string)($rec['nama'] ?? '');
    $bvFile = (string)($rec['filename'] ?? '');

    $docs = [];
    $availableCount = 0;
    foreach ($cats as $catKey => $meta) {
        $found = findJamaahDocFile($catKey, $paspor, $nama, $catKey === 'biovisa' ? $bvFile : '', $slug);
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

/**
 * Ambil settings yang aman untuk disinkronkan ke Cloud GitHub Pages & antar-komputer
 * (Menyertakan seluruh daftar source_accounts beserta URL Web App GAS-nya, tanpa password IMAP)
 */
function getPublicCloudSettings(?array $settings = null): array {
    if ($settings === null) {
        $settings = getSettings();
    }
    $safe = $settings;
    $safe['imap_app_password'] = '';
    if (!empty($safe['source_accounts']) && is_array($safe['source_accounts'])) {
        foreach ($safe['source_accounts'] as &$acc) {
            $acc['imap_password'] = '';
        }
        unset($acc);
    }
    return $safe;
}

/**
 * Tarik otomatis file dokumen pemvisaan (1_Paspor s/d 7_Foto) dari Google Drive
 * yang belum ada di folder lokal storage/dokumen/<kategori>/
 */
function pullMissingSevenDocsFromGDrive(?array $settings = null): int {
    if ($settings === null) {
        $settings = getSettings();
    }
    $gasUrl = trim((string)($settings['gas_gdrive_webapp_url'] ?? ''));
    if ($gasUrl === '' || !class_exists('GDriveSync')) {
        return 0;
    }

    $listUrl = $gasUrl . (strpos($gasUrl, '?') === false ? '?' : '&')
        . 'action=list_gdrive_docs&folder_name=' . rawurlencode($settings['gdrive_folder_name'] ?? 'Biovisa_Jamaah_PHU_Pasuruan');
    $listRes = GDriveSync::httpGetJson($listUrl);
    if (empty($listRes['ok']) || empty($listRes['data']['ok'])) {
        return 0;
    }

    $cats = getSiskohatDocCategories();
    $gdriveCats = $listRes['data']['categories'] ?? [];
    $pulledCount = 0;

    foreach ($cats as $catKey => $meta) {
        $filesInCat = $gdriveCats[$catKey] ?? [];
        if (!is_array($filesInCat)) continue;
        $targetDir = ($catKey === 'biovisa') ? getBiovisaDir() : getDocCategoryDir($catKey);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        foreach ($filesInCat as $gf) {
            $gName = basename((string)($gf['filename'] ?? ''));
            $gId   = trim((string)($gf['file_id'] ?? ''));
            if ($gName === '' || $gId === '') continue;

            $destPath = $targetDir . DIRECTORY_SEPARATOR . $gName;
            if (is_file($destPath) && filesize($destPath) > 100) {
                continue;
            }

            $dlUrl = $gasUrl . (strpos($gasUrl, '?') === false ? '?' : '&') . 'action=get_gdrive_file&file_id=' . rawurlencode($gId);
            $dlRes = GDriveSync::httpGetJson($dlUrl);
            if (!empty($dlRes['ok']) && !empty($dlRes['data']['ok']) && !empty($dlRes['data']['base64'])) {
                $rawBytes = base64_decode($dlRes['data']['base64']);
                if ($rawBytes !== false && strlen($rawBytes) > 100) {
                    file_put_contents($destPath, $rawBytes);
                    $pulledCount++;
                }
            }
        }
    }

    return $pulledCount;
}

/**
 * Tulis paket sinkronisasi Cloud (cloud_sync_bundle.json) ke storage/data/ dan docs/storage/data/
 * serta salin file JPG baru ke docs/storage/ agar seluruh komputer lain yang membuka GitHub Pages
 * selalu mendapatkan daftar Akun Email Pembaca & Data Biovisa yang 100% sama dengan Komputer Server.
 */
function writeCloudSyncBundle(bool $forceWrite = false): array {
    if (!$forceWrite) {
        $secFile = STORAGE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'secrets.json';
        $sec = loadJsonFile($secFile, []);
        if (!empty($sec['block_public_cloud_sync'])) {
            return ['ok' => true, 'records' => getRecords(), 'skipped' => 'private_mode'];
        }
    }
    $safeSettings = getPublicCloudSettings();
    $records = getRecords();
    foreach ($records as &$r) {
        $r['seven_docs'] = getJamaahSevenDocsStatus($r);
    }
    unset($r);
    saveRecords($records);

    $bundle = [
        'ok' => true,
        'version_ts' => time(),
        'updated_at' => date('Y-m-d H:i:s'),
        'settings' => $safeSettings,
        'records' => $records
    ];

    $bundlePath = getDataDir() . DIRECTORY_SEPARATOR . 'cloud_sync_bundle.json';
    saveJsonFile($bundlePath, $bundle);

    $docsDataDir = BASE_DIR . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($docsDataDir)) {
        @mkdir($docsDataDir, 0777, true);
    }
    saveJsonFile($docsDataDir . DIRECTORY_SEPARATOR . 'cloud_sync_bundle.json', $bundle);
    saveJsonFile($docsDataDir . DIRECTORY_SEPARATOR . 'records.json', $records);

    // Salin juga file JPG Biovisa dan 7 Dokumen yang belum ada di docs/storage/
    $docsBiovisaDir = BASE_DIR . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'biovisa';
    if (!is_dir($docsBiovisaDir)) {
        @mkdir($docsBiovisaDir, 0777, true);
    }
    foreach (glob(getBiovisaDir() . DIRECTORY_SEPARATOR . '*.jpg') ?: [] as $jpgFile) {
        $dest = $docsBiovisaDir . DIRECTORY_SEPARATOR . basename($jpgFile);
        if (!is_file($dest) || filesize($dest) !== filesize($jpgFile)) {
            @copy($jpgFile, $dest);
        }
    }

    foreach (getSiskohatDocCategories() as $catKey => $meta) {
        $srcCatDir = getDocCategoryDir($catKey);
        $dstCatDir = BASE_DIR . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dokumen' . DIRECTORY_SEPARATOR . $meta['folder'];
        if (!is_dir($dstCatDir)) {
            @mkdir($dstCatDir, 0777, true);
        }
        foreach (glob($srcCatDir . DIRECTORY_SEPARATOR . '*.jpg') ?: [] as $docJpg) {
            $dest = $dstCatDir . DIRECTORY_SEPARATOR . basename($docJpg);
            if (!is_file($dest) || filesize($dest) !== filesize($docJpg)) {
                @copy($docJpg, $dest);
            }
        }
    }

    return $bundle;
}

/**
 * Jalankan kompilasi build_github_pages.php & git push otomatis di background (non-blocking)
 * setiap kali ada penambahan email pembaca atau data Biovisa baru di Komputer Server.
 */
function triggerBackgroundGitHubPush(string $commitMsg = 'Auto-sync Biovisa & Email Pembaca dari Komputer Server'): void {
    $secFile = STORAGE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'secrets.json';
    $sec = loadJsonFile($secFile, []);
    if (!empty($sec['block_public_cloud_sync'])) {
        writeCloudSyncBundle();
        return;
    }
    $bundle = writeCloudSyncBundle();

    // Simpan juga snapshot Cloud DB ke Google Drive jika URL Web App GDrive aktif
    $settings = getSettings();
    if (!empty($settings['gas_gdrive_webapp_url']) && class_exists('GDriveSync')) {
        try {
            GDriveSync::postToGasWebApp($settings['gas_gdrive_webapp_url'], [
                'action' => 'save_cloud_db',
                'folder_name' => $settings['gdrive_folder_name'] ?? 'Biovisa_Jamaah_PHU_Pasuruan',
                'cloud_db_json' => json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
        } catch (\Throwable $e) {}
    }

    $phpExe = 'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe';
    $gitExe = 'C:\laragon\bin\git\cmd\git.exe';
    if (!is_file($phpExe) || !is_file($gitExe)) {
        return;
    }

    $safeMsg = str_replace(['"', "'", '&', '|'], '', $commitMsg);
    $batPath = getTempDir() . DIRECTORY_SEPARATOR . 'auto_git_push.bat';
    $batContent = "@echo off\r\n"
        . "cd /d \"" . BASE_DIR . "\"\r\n"
        . "\"{$phpExe}\" build_github_pages.php >nul 2>&1\r\n"
        . "\"{$gitExe}\" add -A >nul 2>&1\r\n"
        . "\"{$gitExe}\" commit -m \"{$safeMsg}\" >nul 2>&1\r\n"
        . "\"{$gitExe}\" push -u origin main >nul 2>&1\r\n";
    @file_put_contents($batPath, $batContent);

    if (function_exists('popen')) {
        @pclose(@popen('start /B "" cmd /c "' . $batPath . '"', 'r'));
    }
}
