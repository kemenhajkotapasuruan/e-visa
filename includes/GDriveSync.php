<?php
/**
 * GDriveSync
 * Mengunggah file hasil Biovisa (Nama_Nomor paspor.jpg <= 50KB) secara otomatis
 * ke Google Drive milik muchammad.ali.sanjaya@gmail.com melalui jembatan
 * Google Apps Script Web App.
 */

require_once __DIR__ . '/../config.php';

class GDriveSync
{
    /**
     * Kirim JSON POST ke Google Apps Script Web App dengan penanganan Redirect 302 manual
     * (Google Apps Script selalu merespons POST dengan 302 Redirect ke script.googleusercontent.com via GET)
     */
    public static function postToGasWebApp(string $webappUrl, array $data): array
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($webappUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'PHU-Pasuruan-AutoBiovisa/1.1'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false || $err !== '') {
            return [
                'ok' => false,
                'error' => 'Koneksi ke Google Drive Web App gagal: ' . $err
            ];
        }

        // Ikuti 302/303/307 Redirect dari Google Apps Script menggunakan GET murni
        if (in_array($httpCode, [301, 302, 303, 307, 308], true) && !empty($redirectUrl)) {
            $chGet = curl_init($redirectUrl);
            curl_setopt_array($chGet, [
                CURLOPT_HTTPGET => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 35,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'PHU-Pasuruan-AutoBiovisa/1.1'
            ]);
            $response = curl_exec($chGet);
            $httpCode = curl_getinfo($chGet, CURLINFO_HTTP_CODE);
            $err = curl_error($chGet);
            curl_close($chGet);

            if ($response === false || $err !== '') {
                return [
                    'ok' => false,
                    'error' => 'Gagal membaca hasil redirect Google Drive Web App: ' . $err
                ];
            }
        }

        $decoded = json_decode((string)$response, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Jika bukan JSON (biasanya HTML error dari Google Apps Script)
        $cleanHtml = trim(strip_tags((string)$response));
        $cleanHtml = preg_replace('/\s+/', ' ', $cleanHtml);

        // Cek apakah URL tersebut ternyata menjalankan Script 2 lama (tanpa fungsi doPost)
        if (
            stripos($cleanHtml, 'doPost') !== false ||
            stripos((string)$response, 'Script function not found: doPost') !== false ||
            self::isOldGmailOnlyScript($webappUrl)
        ) {
            return [
                'ok' => false,
                'error' => 'Web App Google Drive Anda saat ini masih berisi kode Script Gmail lama (belum memiliki fungsi doPost untuk simpan GDrive). Silakan buka tab "Integrasi Gmail & Google Drive", klik "Salin Script All-in-One", tempel ke script.google.com (akun muchammad.ali.sanjaya@gmail.com), jalankan fungsi "cekIzinAkses" 1x, lalu klik Deploy -> New deployment.'
            ];
        }

        if (stripos($cleanHtml, 'DriveApp') !== false || stripos($cleanHtml, 'permission') !== false || stripos($cleanHtml, 'Authorization') !== false) {
            return [
                'ok' => false,
                'error' => 'Izin Google Drive (DriveApp) belum diaktifkan di Google Apps Script. Buka script.google.com pada akun muchammad.ali.sanjaya@gmail.com, pilih fungsi "cekIzinAkses" di atas, klik tombol Run (Jalankan) -> Review Permissions -> Allow (Izinkan), lalu Deploy -> New deployment.'
            ];
        }

        if (stripos((string)$response, 'accounts.google.com') !== false || stripos($cleanHtml, 'Sign in') !== false) {
            return [
                'ok' => false,
                'error' => 'Akses Web App Google Drive masih terkunci (meminta Login). Saat Deploy Web App di script.google.com, pastikan "Who has access / Siapa yang memiliki akses" diatur ke "Anyone / Siapa saja".'
            ];
        }

        return [
            'ok' => false,
            'error' => 'Respons Web App Google Drive bukan JSON (HTTP ' . $httpCode . '). Pastikan Anda sudah menyalin Script All-in-One terbaru di tab Integrasi dan melakukan "Deploy -> New deployment" pada akun muchammad.ali.sanjaya@gmail.com.'
        ];
    }

    /**
     * Cek apakah URL Web App GDrive merespons GET dengan format script Gmail lama (tanpa doPost)
     */
    private static function isOldGmailOnlyScript(string $webappUrl): bool
    {
        $ch = curl_init($webappUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res === false) {
            return false;
        }
        $json = json_decode((string)$res, true);
        if (is_array($json) && isset($json['emails']) && empty($json['supports_post'])) {
            return true;
        }
        return false;
    }

    /**
     * Upload file JPG Biovisa ke Google Drive (muchammad.ali.sanjaya@gmail.com)
     */
    public static function uploadFileToDrive(array $record, array $settings = []): array
    {
        if (empty($settings)) {
            $settings = getSettings();
        }

        $webappUrl = trim($settings['gas_gdrive_webapp_url'] ?? '');
        if ($webappUrl === '') {
            return [
                'ok' => false,
                'error' => 'URL Web App Google Drive (' . ($settings['target_gdrive_email'] ?? 'muchammad.ali.sanjaya@gmail.com') . ') belum diisi di Pengaturan.'
            ];
        }

        $filename = $record['filename'] ?? '';
        $filePath = BIOVISA_DIR . DIRECTORY_SEPARATOR . $filename;

        if ($filename === '' || !file_exists($filePath)) {
            return [
                'ok' => false,
                'error' => 'File lokal tidak ditemukan: ' . $filename
            ];
        }

        $fileBytes = file_get_contents($filePath);
        $base64Data = base64_encode($fileBytes);

        $decoded = self::postToGasWebApp($webappUrl, [
            'action' => 'upload_biovisa',
            'filename' => $filename,
            'mime_type' => 'image/jpeg',
            'base64_file' => $base64Data,
            'folder_name' => $settings['gdrive_folder_name'] ?? 'Biovisa_Jamaah_PHU_Pasuruan',
            'folder_id' => $settings['gdrive_folder_id'] ?? '',
            'nama' => $record['nama'] ?? '',
            'paspor' => $record['paspor'] ?? '',
            'enrollment_id' => $record['enrollment_id'] ?? '',
            'size_kb' => $record['size_kb'] ?? round(strlen($fileBytes) / 1024, 2),
            'target_email' => $settings['target_gdrive_email'] ?? 'muchammad.ali.sanjaya@gmail.com'
        ]);

        if (empty($decoded['ok'])) {
            return [
                'ok' => false,
                'error' => $decoded['error'] ?? 'Gagal mengunggah ke Google Drive.'
            ];
        }

        return [
            'ok' => true,
            'file_id' => $decoded['file_id'] ?? '',
            'file_url' => $decoded['file_url'] ?? '',
            'folder_url' => $decoded['folder_url'] ?? '',
            'folder_id' => $decoded['folder_id'] ?? '',
            'account_email' => $decoded['account_email'] ?? ''
        ];
    }

    /**
     * Upload file kategori dokumen pemvisaan (1_Paspor s/d 7_Foto) ke Sub-Folder Google Drive
     */
    public static function uploadCustomDocToDrive(array $docRecord, array $settings = []): array
    {
        if (empty($settings)) {
            $settings = getSettings();
        }
        $webappUrl = trim($settings['gas_gdrive_webapp_url'] ?? '');
        if ($webappUrl === '') {
            return ['ok' => false, 'error' => 'URL Web App GDrive belum diisi.'];
        }
        $filePath = $docRecord['custom_path'] ?? '';
        if ($filePath === '' || !is_file($filePath)) {
            return ['ok' => false, 'error' => 'File tidak ditemukan.'];
        }
        $fileBytes = file_get_contents($filePath);
        return self::postToGasWebApp($webappUrl, [
            'action' => 'upload_biovisa',
            'filename' => $docRecord['filename'] ?? basename($filePath),
            'mime_type' => 'image/jpeg',
            'base64_file' => base64_encode($fileBytes),
            'folder_name' => $settings['gdrive_folder_name'] ?? 'Biovisa_Jamaah_PHU_Pasuruan',
            'folder_id' => $settings['gdrive_folder_id'] ?? '',
            'subfolder' => $docRecord['subfolder'] ?? '',
            'nama' => $docRecord['nama'] ?? '',
            'paspor' => $docRecord['paspor'] ?? '',
            'enrollment_id' => $docRecord['enrollment_id'] ?? '',
            'size_kb' => $docRecord['size_kb'] ?? round(strlen($fileBytes) / 1024, 2)
        ]);
    }

    /**
     * Helper HTTP GET JSON untuk memanggil doGet pada Google Apps Script
     */
    public static function httpGetJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'PHU-Pasuruan-AutoBiovisa/1.2'
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false || $err !== '') {
            return ['ok' => false, 'error' => $err];
        }
        $decoded = json_decode((string)$res, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Respons bukan JSON yang valid.'];
        }
        return ['ok' => true, 'data' => $decoded];
    }

    /**
     * Tes koneksi ke seluruh akun Web App Gmail (Multi-Email) & Web App Google Drive
     */
    public static function diagnoseConnections(array $settings): array
    {
        $gdriveUrl = trim($settings['gas_gdrive_webapp_url'] ?? '');
        $accounts  = $settings['source_accounts'] ?? [];

        if (empty($accounts)) {
            $accounts = [
                [
                    'id' => 'acc_primary',
                    'label' => 'Email Utama',
                    'email' => $settings['source_email'] ?? 'phujtmkopasuruan@gmail.com',
                    'mode' => $settings['connection_mode'] ?? 'gas',
                    'gas_url' => $settings['gas_gmail_webapp_url'] ?? '',
                    'imap_password' => $settings['imap_app_password'] ?? '',
                    'enabled' => true
                ]
            ];
        }

        $report = [
            'gmail' => ['ok' => false, 'message' => 'Belum ada akun Gmail aktif.'],
            'gmail_accounts' => [],
            'gdrive' => ['ok' => false, 'message' => 'URL Web App Google Drive belum diisi.']
        ];

        $subjFilter = $settings['email_subject_filter'] ?? 'Enrollment confirmation';
        if (stripos($subjFilter, 'Mofa Enrollment Confirmation') !== false) {
            $subjFilter = 'Enrollment confirmation';
        }

        $allGmailOk = true;
        $anyGmailEnabled = false;
        $summaryParts = [];

        foreach ($accounts as $idx => $acc) {
            if (isset($acc['enabled']) && !$acc['enabled']) {
                $report['gmail_accounts'][] = [
                    'email' => $acc['email'] ?? ('Akun #' . ($idx + 1)),
                    'label' => $acc['label'] ?? '',
                    'enabled' => false,
                    'ok' => true,
                    'message' => 'Dinonaktifkan (Tidak dicek)'
                ];
                continue;
            }

            $anyGmailEnabled = true;
            $accEmail = trim($acc['email'] ?? ('Akun #' . ($idx + 1)));
            $accLabel = trim($acc['label'] ?? $accEmail);
            $accMode  = $acc['mode'] ?? 'gas';

            if ($accMode === 'gas') {
                $gUrl = trim($acc['gas_url'] ?? '');
                if ($gUrl === '') {
                    $allGmailOk = false;
                    $msg = "[$accEmail] URL Web App Google Apps Script belum diisi.";
                    $summaryParts[] = $msg;
                    $report['gmail_accounts'][] = [
                        'email' => $accEmail,
                        'label' => $accLabel,
                        'enabled' => true,
                        'ok' => false,
                        'message' => $msg
                    ];
                    continue;
                }

                $qUrl = $gUrl . (strpos($gUrl, '?') === false ? '?' : '&')
                    . http_build_query([
                        'action' => 'fetch_mofa',
                        'subject' => $subjFilter,
                        'limit' => 15
                    ]);
                $ch = curl_init($qUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                $res = curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);

                if ($res === false || $err !== '') {
                    $allGmailOk = false;
                    $msg = "[$accEmail] Gagal menghubungi Web App Gmail: $err";
                    $summaryParts[] = $msg;
                    $report['gmail_accounts'][] = [
                        'email' => $accEmail,
                        'label' => $accLabel,
                        'enabled' => true,
                        'ok' => false,
                        'message' => $msg
                    ];
                } else {
                    $json = json_decode((string)$res, true);
                    if (is_array($json) && !empty($json['ok'])) {
                        $cnt = intval($json['count'] ?? count($json['emails'] ?? []));
                        $msg = "[$accEmail] Terhubung ke Web App Gmail — Ditemukan {$cnt} pesan.";
                        $summaryParts[] = $msg;
                        $report['gmail_accounts'][] = [
                            'email' => $accEmail,
                            'label' => $accLabel,
                            'enabled' => true,
                            'ok' => true,
                            'count' => $cnt,
                            'message' => $msg
                        ];
                    } else {
                        $allGmailOk = false;
                        $msg = "[$accEmail] Respons Web App tidak valid: " . ($json['error'] ?? 'Pastikan akses Web App diatur ke Anyone / Siapa saja.');
                        $summaryParts[] = $msg;
                        $report['gmail_accounts'][] = [
                            'email' => $accEmail,
                            'label' => $accLabel,
                            'enabled' => true,
                            'ok' => false,
                            'message' => $msg
                        ];
                    }
                }
            } else {
                // Mode IMAP
                $pass = preg_replace('/\s+/', '', trim($acc['imap_password'] ?? ''));
                if ($pass === '') {
                    $allGmailOk = false;
                    $msg = "[$accEmail] App Password IMAP belum diisi.";
                    $summaryParts[] = $msg;
                    $report['gmail_accounts'][] = [
                        'email' => $accEmail,
                        'label' => $accLabel,
                        'enabled' => true,
                        'ok' => false,
                        'message' => $msg
                    ];
                } else {
                    $msg = "[$accEmail] Mode IMAP siap digunakan.";
                    $summaryParts[] = $msg;
                    $report['gmail_accounts'][] = [
                        'email' => $accEmail,
                        'label' => $accLabel,
                        'enabled' => true,
                        'ok' => true,
                        'message' => $msg
                    ];
                }
            }
        }

        $report['gmail'] = [
            'ok' => $anyGmailEnabled && $allGmailOk,
            'message' => !empty($summaryParts) ? implode(' | ', $summaryParts) : 'Belum ada akun Gmail yang diaktifkan.'
        ];

        // 2. Cek Google Drive Web App (tes doPost ping_post)
        if ($gdriveUrl !== '') {
            $postRes = self::postToGasWebApp($gdriveUrl, [
                'action' => 'ping_post',
                'folder_name' => $settings['gdrive_folder_name'] ?? 'Biovisa_Jamaah_PHU_Pasuruan',
                'folder_id' => $settings['gdrive_folder_id'] ?? ''
            ]);
            if (!empty($postRes['ok'])) {
                $acc = !empty($postRes['account_email']) ? " ({$postRes['account_email']})" : '';
                $fUrl = $postRes['folder_url'] ?? '';
                $report['gdrive'] = [
                    'ok' => true,
                    'folder_url' => $fUrl,
                    'account_email' => $postRes['account_email'] ?? '',
                    'message' => "Terhubung ke Google Drive{$acc}! Folder '{$settings['gdrive_folder_name']}' siap menerima file JPG."
                ];
            } else {
                $report['gdrive'] = [
                    'ok' => false,
                    'message' => $postRes['error'] ?? 'Gagal menguji koneksi tulis Google Drive.'
                ];
            }
        }

        return $report;
    }
}
