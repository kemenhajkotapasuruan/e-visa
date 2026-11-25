<?php
/**
 * GmailFetcher
 * Mendukung Penarikan Email otomatis dari SATU atau BANYAK Akun Email (Multi-Email):
 * 1. Google Apps Script Gmail Bridge (Tanpa App Password / Tanpa IMAP)
 * 2. Pure-PHP SSL Socket IMAP Client (ssl://imap.gmail.com:993)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/BiovisaProcessor.php';

class GmailFetcher
{
    /**
     * Ambil email dari seluruh akun pembaca yang aktif di $settings['source_accounts']
     */
    public static function fetchEmails(array $settings, array $existingMessageIds = []): array
    {
        $accounts = $settings['source_accounts'] ?? [];
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

        $subjectFilter = $settings['email_subject_filter'] ?? 'Enrollment confirmation';
        $allEmails = [];
        $accountReports = [];
        $anySuccess = false;
        $errors = [];
        $activeCount = 0;

        foreach ($accounts as $idx => $acc) {
            if (isset($acc['enabled']) && !$acc['enabled']) {
                continue;
            }
            $activeCount++;
            $accEmail = trim($acc['email'] ?? ('Akun #' . ($idx + 1)));
            $accLabel = trim($acc['label'] ?? $accEmail);
            $accMode  = $acc['mode'] ?? 'gas';

            if ($accMode === 'gas') {
                $gasUrl = trim($acc['gas_url'] ?? '');
                if ($gasUrl === '') {
                    $errMsg = "[$accEmail] URL Web App Google Apps Script belum diisi.";
                    $errors[] = $errMsg;
                    $accountReports[] = [
                        'email' => $accEmail,
                        'label' => $accLabel,
                        'ok' => false,
                        'error' => $errMsg,
                        'count' => 0
                    ];
                    continue;
                }
                $res = self::fetchViaGasBridge($gasUrl, $subjectFilter, $existingMessageIds, $accEmail);
            } else {
                $appPass = preg_replace('/\s+/', '', trim($acc['imap_password'] ?? ''));
                if ($appPass === '') {
                    $errMsg = "[$accEmail] App Password IMAP belum diisi.";
                    $errors[] = $errMsg;
                    $accountReports[] = [
                        'email' => $accEmail,
                        'label' => $accLabel,
                        'ok' => false,
                        'error' => $errMsg,
                        'count' => 0
                    ];
                    continue;
                }
                $res = self::fetchViaSocketImap($accEmail, $appPass, $subjectFilter, $existingMessageIds);
            }

            if ($res['ok']) {
                $anySuccess = true;
                foreach ($res['emails'] as $em) {
                    $em['source_account_email'] = $accEmail;
                    $em['source_account_label'] = $accLabel;
                    if (empty($em['to'])) {
                        $em['to'] = $accEmail;
                    }
                    $allEmails[] = $em;
                    if (!empty($em['message_id'])) {
                        $existingMessageIds[] = $em['message_id'];
                    }
                }
                $accountReports[] = [
                    'email' => $accEmail,
                    'label' => $accLabel,
                    'ok' => true,
                    'count' => count($res['emails'])
                ];
            } else {
                $errMsg = "[$accEmail] " . ($res['error'] ?? 'Gagal mengambil email.');
                $errors[] = $errMsg;
                $accountReports[] = [
                    'email' => $accEmail,
                    'label' => $accLabel,
                    'ok' => false,
                    'error' => $errMsg,
                    'count' => 0
                ];
            }
        }

        if ($activeCount === 0) {
            return [
                'ok' => false,
                'error' => 'Tidak ada akun email pembaca yang diaktifkan di Pengaturan.',
                'emails' => [],
                'account_reports' => []
            ];
        }

        if (!$anySuccess && !empty($errors)) {
            return [
                'ok' => false,
                'error' => implode(' | ', $errors),
                'emails' => [],
                'account_reports' => $accountReports
            ];
        }

        return [
            'ok' => true,
            'emails' => $allEmails,
            'total_found' => count($allEmails),
            'account_reports' => $accountReports,
            'partial_errors' => $errors
        ];
    }

    /**
     * Metode 1: Ambil email dari Google Apps Script yang terpasang di akun Gmail sumber
     */
    public static function fetchViaGasBridge(string $webappUrl, string $subjectFilter, array $existingMessageIds = [], string $defaultToEmail = 'phujtmkopasuruan@gmail.com'): array
    {
        $effectiveSubject = $subjectFilter;
        if (stripos($subjectFilter, 'Mofa Enrollment Confirmation') !== false) {
            $effectiveSubject = 'Enrollment confirmation';
        }

        $queryUrl = $webappUrl . (strpos($webappUrl, '?') === false ? '?' : '&')
            . http_build_query([
                'action' => 'fetch_mofa',
                'subject' => $effectiveSubject,
                'limit' => 15
            ]);

        $ch = curl_init($queryUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'PHU-Pasuruan-AutoBiovisa/1.4'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false || $err !== '') {
            return [
                'ok' => false,
                'error' => 'Gagal menghubungi Google Apps Script Gmail Bridge: ' . $err,
                'emails' => []
            ];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'Respons dari Google Apps Script Gmail Bridge tidak valid (HTTP ' . $httpCode . '). Pastikan Web App di-deploy dengan akses "Anyone / Siapa saja".',
                'emails' => []
            ];
        }

        if (empty($decoded['ok']) && isset($decoded['error'])) {
            return [
                'ok' => false,
                'error' => 'GAS Gmail Error: ' . $decoded['error'],
                'emails' => []
            ];
        }

        $rawEmails = $decoded['emails'] ?? [];
        $filtered = [];
        foreach ($rawEmails as $em) {
            $subj = $em['subject'] ?? 'Enrollment confirmation';
            $bText = $em['body_text'] ?? '';
            $bHtml = $em['body_html'] ?? '';

            if (!BiovisaProcessor::isBiovisaConfirmationEmail($subj, $bText, $bHtml)) {
                continue;
            }

            $msgId = $em['message_id'] ?? '';
            if ($msgId !== '' && in_array($msgId, $existingMessageIds, true)) {
                continue;
            }

            $filtered[] = [
                'message_id' => $msgId ?: ('gas_' . md5($subj . ($em['date'] ?? '') . $bText)),
                'subject' => $subj,
                'from' => $em['from'] ?? 'Mofa <MofaBioApp@mofa.gov.sa>',
                'to' => $em['to'] ?? $defaultToEmail,
                'date' => $em['date'] ?? date('Y-m-d H:i:s'),
                'body_text' => $bText,
                'body_html' => $bHtml,
                'attachments' => $em['attachments'] ?? []
            ];
        }

        return [
            'ok' => true,
            'emails' => $filtered,
            'total_found' => count($filtered)
        ];
    }

    /**
     * Metode 2: Pure-PHP SSL Socket IMAP Client ke imap.gmail.com:993
     */
    public static function fetchViaSocketImap(string $email, string $password, string $subjectFilter, array $existingMessageIds = []): array
    {
        $effectiveSubject = $subjectFilter;
        if (stripos($subjectFilter, 'Mofa Enrollment Confirmation') !== false) {
            $effectiveSubject = 'Enrollment confirmation';
        }

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $fp = @stream_socket_client('ssl://imap.gmail.com:993', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return [
                'ok' => false,
                'error' => "Tidak dapat terhubung ke server IMAP Gmail ($errstr - $errno).",
                'emails' => []
            ];
        }

        stream_set_timeout($fp, 15);
        fgets($fp, 4096);

        $escapedUser = addslashes($email);
        $escapedPass = addslashes($password);
        $loginRes = self::imapCommand($fp, 'A01', "LOGIN \"$escapedUser\" \"$escapedPass\"");
        if (!$loginRes['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'error' => 'Login IMAP Gmail gagal. Pastikan menggunakan 16-digit Gmail App Password untuk ' . $email . '.',
                'emails' => []
            ];
        }

        $selRes = self::imapCommand($fp, 'A02', 'SELECT INBOX');
        if (!$selRes['ok']) {
            fclose($fp);
            return [
                'ok' => false,
                'error' => 'Gagal membuka INBOX pada akun ' . $email,
                'emails' => []
            ];
        }

        $safeSubj = str_replace('"', '', $effectiveSubject);
        $searchRes = self::imapCommand($fp, 'A03', "SEARCH SUBJECT \"$safeSubj\"");
        $msgNums = [];
        foreach ($searchRes['lines'] as $line) {
            if (preg_match('/^\*\s+SEARCH\s+(.*)$/i', trim($line), $m)) {
                $parts = preg_split('/\s+/', trim($m[1]));
                foreach ($parts as $p) {
                    if (ctype_digit($p)) {
                        $msgNums[] = intval($p);
                    }
                }
            }
        }

        $msgNums = array_slice($msgNums, -25);
        $emails = [];
        $cmdIdx = 10;

        foreach ($msgNums as $num) {
            $imapId = 'imap_' . md5($email) . '_' . $num;
            $cmdIdx++;
            $hdrRes = self::imapCommand($fp, 'A' . $cmdIdx, "FETCH $num (BODY.PEEK[HEADER.FIELDS (MESSAGE-ID SUBJECT FROM TO DATE)])");
            $rawHeader = implode("\n", $hdrRes['lines']);

            $msgId = $imapId;
            if (preg_match('/Message-ID:\s*(<[^>]+>|[^\r\n]+)/i', $rawHeader, $m)) {
                $msgId = trim($m[1]);
            }
            if (in_array($msgId, $existingMessageIds, true)) {
                continue;
            }

            $subject = $effectiveSubject;
            if (preg_match('/Subject:\s*([^\r\n]+)/i', $rawHeader, $m)) {
                $subject = self::decodeMimeStr(trim($m[1]));
            }
            $from = 'Mofa <MofaBioApp@mofa.gov.sa>';
            if (preg_match('/From:\s*([^\r\n]+)/i', $rawHeader, $m)) {
                $from = self::decodeMimeStr(trim($m[1]));
            }
            $date = date('Y-m-d H:i:s');
            if (preg_match('/Date:\s*([^\r\n]+)/i', $rawHeader, $m)) {
                $ts = strtotime(trim($m[1]));
                $date = $ts ? date('Y-m-d H:i:s', $ts) : trim($m[1]);
            }

            $cmdIdx++;
            $bodyRes = self::imapCommand($fp, 'A' . $cmdIdx, "FETCH $num (BODY.PEEK[TEXT])");
            $rawBody = implode("\n", array_slice($bodyRes['lines'], 1, -1));
            $parsedBody = self::parseRawMimeBody($rawBody);

            if (!BiovisaProcessor::isBiovisaConfirmationEmail($subject, $parsedBody['text'], $parsedBody['html'])) {
                continue;
            }

            $emails[] = [
                'message_id' => $msgId,
                'subject' => $subject,
                'from' => $from,
                'to' => $email,
                'date' => $date,
                'body_text' => $parsedBody['text'],
                'body_html' => $parsedBody['html'],
                'attachments' => $parsedBody['attachments']
            ];
        }

        self::imapCommand($fp, 'A99', 'LOGOUT');
        fclose($fp);

        return [
            'ok' => true,
            'emails' => $emails,
            'total_found' => count($emails)
        ];
    }

    private static function imapCommand($fp, string $tag, string $command): array
    {
        fwrite($fp, "$tag $command\r\n");
        $lines = [];
        $ok = false;
        while (!feof($fp)) {
            $line = fgets($fp, 8192);
            if ($line === false) {
                break;
            }
            $lines[] = rtrim($line, "\r\n");
            if (strpos($line, "$tag OK") === 0) {
                $ok = true;
                break;
            }
            if (strpos($line, "$tag NO") === 0 || strpos($line, "$tag BAD") === 0) {
                $ok = false;
                break;
            }
        }
        return ['ok' => $ok, 'lines' => $lines];
    }

    private static function decodeMimeStr(string $str): string
    {
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($str, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $str;
    }

    private static function parseRawMimeBody(string $rawBody): array
    {
        $text = $rawBody;
        $html = '';
        $attachments = [];

        if (strpos($rawBody, '=3D') !== false || strpos($rawBody, "=\n") !== false) {
            $decoded = quoted_printable_decode($rawBody);
            if (stripos($decoded, '<html') !== false || stripos($decoded, '<div') !== false || stripos($decoded, '<table') !== false) {
                $html = $decoded;
                $text = trim(strip_tags(preg_replace('/<(br|p|div|tr)[^>]*>/i', "\n", $decoded)));
            } else {
                $text = $decoded;
            }
        } elseif (stripos($rawBody, '<html') !== false || stripos($rawBody, '<table') !== false) {
            $html = $rawBody;
            $text = trim(strip_tags(preg_replace('/<(br|p|div|tr)[^>]*>/i', "\n", $rawBody)));
        }

        return [
            'text' => $text,
            'html' => $html,
            'attachments' => $attachments
        ];
    }
}
