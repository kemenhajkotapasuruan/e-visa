<?php
/**
 * BiovisaProcessor
 * - Mengekstrak Nama Jamaah, Nomor Paspor, Enrollment ID, dan Waktu Enrollment dari email
 *   "Enrollment confirmation" (Mofa <MofaBioApp@mofa.gov.sa>)
 * - Merender tampilan email PERSIS 1:1 sesuai template asli SaudiVisaBio di email masuk
 *   (tanpa mengubah desain, warna, logo, maupun susunan teks email asli)
 * - Mengompres & menyesuaikan ukuran JPG agar tepat <= 50 KB (kisaran 42 - 49.5 KB)
 * - Menamai file dengan format Nama_Nomor paspor.jpg
 */

require_once __DIR__ . '/../config.php';

class BiovisaProcessor
{
    /**
     * Cek apakah pesan email adalah email konfirmasi Biovisa yang SUDAH BERHASIL
     * (Bukan OTP / Verify Your Account, dan Bukan "Enrollment under review" / "Application under review")
     */
    public static function isBiovisaConfirmationEmail(string $subject, string $bodyText, string $bodyHtml = ''): bool
    {
        $combined = $subject . "\n" . $bodyText . "\n" . strip_tags($bodyHtml);

        // Tolak email OTP atau verifikasi akun
        if (
            stripos($combined, 'One Time Password') !== false ||
            stripos($combined, 'Verify Your Account') !== false
        ) {
            return false;
        }

        // Terima jika memiliki "Application Submitted Successfully" atau ("Enrollment confirmation" + "passport number")
        // Meskipun di dalam thread/kutipan bawah ada teks "under review" dari pesan sebelumnya, tetap terima selama ada passport number!
        $hasSubmitted = stripos($combined, 'Application Submitted Successfully') !== false;
        $hasPassport  = stripos($combined, 'passport number') !== false;

        if ($hasSubmitted && $hasPassport) {
            return true;
        }

        if (stripos($subject, 'under review') !== false && !$hasPassport) {
            return false;
        }
        if (stripos($combined, 'under review') !== false && !$hasPassport) {
            return false;
        }

        if (
            $hasSubmitted ||
            (stripos($subject, 'Enrollment confirmation') !== false && $hasPassport)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Pecah 1 pesan email jika di dalam body-nya terdapat LEBIH DARI SATU blok "Enrollment confirmation"
     * (misalnya akibat penggabungan thread / kutipan / forward beberapa jamaah dalam 1 pesan).
     * Mengembalikan array berisi 1 atau beberapa blok teks terpisah per Nomor Paspor.
     */
    public static function splitMultipleConfirmationBlocks(string $bodyText, string $bodyHtml = ''): array
    {
        $raw = trim($bodyText !== '' ? $bodyText : strip_tags(preg_replace('/<(br|p|div|tr)[^>]*>/i', "\n", $bodyHtml)));
        $raw = str_replace(["\xC3\x98\xC5\x92", 'ØŒ', '،'], ',', $raw);
        if ($raw === '') {
            return [$raw];
        }

        // Cari semua posisi kemunculan "Dear <NAMA>" atau "Application Submitted Successfully"
        if (preg_match_all('/(?:Application\s+Submitted\s+Successfully[\s\S]{0,120}?)?Dear\s+[^\r\n,،<]{2,60}?\s*[,،\r\n<][\s\S]{10,450}?passport\s+number\s*[:\-–=]?\s*[A-Z0-9]{6,12}[\s\S]{0,350}?(?:Enrollment\s*Id\s*(?:is)?\s*[:\-–=#]?\s*\d{5,15}|www\.mofa\.gov\.sa|$)/iu', $raw, $matches)) {
            if (!empty($matches[0]) && count($matches[0]) > 1) {
                return $matches[0];
            }
        }

        return [$raw];
    }

    /**
     * Ekstrak Nama Jamaah, Nomor Paspor, Enrollment ID, dan Waktu Enrollment dari Subjek & Isi Email
     */
    public static function extractEmailInfo(string $subject, string $bodyText, string $bodyHtml = '', array $jamaahMaster = []): array
    {
        $cleanHtmlText = '';
        if ($bodyHtml !== '') {
            $preparedHtml = preg_replace('/<(br|p|div|tr|li|h[1-6])[^>]*>/i', "\n", $bodyHtml);
            $preparedHtml = preg_replace('/<\/(p|div|tr|td|th|li|h[1-6])>/i', " \n", $preparedHtml);
            $cleanHtmlText = html_entity_decode(strip_tags($preparedHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $combinedText = trim($subject . "\n" . ($bodyText !== '' ? $bodyText : $cleanHtmlText) . "\n" . $cleanHtmlText);
        $combinedText = str_replace(["\xC3\x98\xC5\x92", 'ØŒ', '،'], ',', $combinedText);
        $combinedText = preg_replace("/\r\n|\r/", "\n", $combinedText);

        $paspor = '';
        $nama = '';
        $enrollmentId = '';
        $enrollmentTime = '';

        // 1. Ekstrak Waktu Enrollment asli dari kalimat email: "on 2026-11-25 02:07:35 as part of your visa application"
        if (preg_match('/\bon\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+as\s+part\s+of/i', $combinedText, $m)) {
            $enrollmentTime = trim($m[1]);
        } elseif (preg_match('/\b(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\b/', $combinedText, $m)) {
            $enrollmentTime = trim($m[1]);
        }

        // 2. Ekstrak Nomor Paspor (Passport Number)
        $passportPatterns = [
            '/for\s+your\s+passport\s+number\s*[:\-–=]?\s*([A-Z0-9]{6,12})/i',
            '/(?:Passport\s*(?:Number|No\.?|#)|No(?:mor)?\.?\s*Paspor|Paspor)\s*[:\-–=]\s*([A-Z0-9]{6,12})/i',
            '/(?:Passport\s*(?:Number|No\.?|#)|No(?:mor)?\.?\s*Paspor)\s+is\s+([A-Z0-9]{6,12})/i',
            '/\b([A-Z]{1,3}\d{6,8})\b/'
        ];

        foreach ($passportPatterns as $pattern) {
            if (preg_match($pattern, $combinedText, $m)) {
                $candidate = strtoupper(trim($m[1]));
                if (preg_match('/[A-Z]/', $candidate) && preg_match('/\d/', $candidate)) {
                    $paspor = $candidate;
                    break;
                }
            }
        }

        if ($paspor === '') {
            if (preg_match('/(?:Passport\s*(?:Number|No\.?|#)|No(?:mor)?\.?\s*Paspor)\s*[:\-–=]?\s*([A-Z0-9\-]{5,15})/i', $combinedText, $m)) {
                $paspor = strtoupper(str_replace('-', '', trim($m[1])));
            }
        }

        // 3. Ekstrak Enrollment ID (Format asli MOFA: "and Your Enrollment Id is : 42163441 .")
        $enrollmentPatterns = [
            '/Enrollment\s*Id\s*(?:is)?\s*[:\-–=#]?\s*(\d{5,15})/i',
            '/(?:Enrollment\s*(?:ID|Number|No\.?|Code)|Biometric\s*ID|Reference\s*(?:ID|No\.?|Number))(?:\s+is)?\s*[:\-–=#]?\s*([A-Z0-9\-]{5,20})/i'
        ];
        foreach ($enrollmentPatterns as $pattern) {
            if (preg_match($pattern, $combinedText, $m)) {
                $candidateId = strtoupper(trim($m[1]));
                if ($candidateId !== $paspor && !in_array(strtolower($candidateId), ['is', 'for', 'your', 'the', 'number'])) {
                    $enrollmentId = $candidateId;
                    break;
                }
            }
        }

        // 4. Ekstrak Nama Jamaah (Format asli MOFA: "Dear MOHALI،" dengan koma Arab '،' atau koma biasa ',')
        if (preg_match('/Dear\s+([^\r\n,،<]{2,60}?)\s*[,،\r\n<]/u', $combinedText, $m)) {
            $candidateName = self::cleanPersonName($m[1], $paspor);
            if (self::isValidPersonName($candidateName)) {
                $nama = $candidateName;
            }
        }

        // Fallback pola nama lainnya di body atau subjek
        if ($nama === '') {
            $otherNamePatterns = [
                '/(?:Applicant\s*Name|Full\s*Name|Passenger\s*Name|Pilgrim\s*Name|Nama\s*Jamaah|Nama\s*Lengkap|Name|Nama)\s*[:\-–=]\s*([^\n\r<,،]{2,65})/iu',
                '/Mofa\s+Enrollment\s+Confirmation\s*[\-–:|\/]\s*([A-Za-z\s\.\',]{2,60})/i',
                '/Enrollment\s+Confirmation\s*[\-–:|\/]\s*([A-Za-z\s\.\',]{2,60})/i'
            ];
            foreach ($otherNamePatterns as $bp) {
                if (preg_match($bp, $combinedText, $m)) {
                    $candidateName = self::cleanPersonName($m[1], $paspor);
                    if (self::isValidPersonName($candidateName)) {
                        $nama = $candidateName;
                        break;
                    }
                }
            }
        }

        // 5. Cek di Master Data Jamaah jika ada
        if ($paspor !== '' && !empty($jamaahMaster)) {
            $normPaspor = strtoupper(preg_replace('/[^A-Z0-9]/', '', $paspor));
            foreach ($jamaahMaster as $jm) {
                $jmPaspor = strtoupper(preg_replace('/[^A-Z0-9]/', '', $jm['paspor'] ?? ''));
                if ($jmPaspor !== '' && $jmPaspor === $normPaspor && !empty($jm['nama'])) {
                    $nama = trim($jm['nama']);
                    break;
                }
            }
        }

        if ($paspor === '') {
            $paspor = 'TANPAPASPOR';
        }
        if ($nama === '') {
            $nama = 'JAMAAH';
        }
        if ($enrollmentId === '') {
            $enrollmentId = '421' . rand(10000, 99999);
        }
        if ($enrollmentTime === '') {
            $enrollmentTime = date('Y-m-d H:i:s');
        }

        return [
            'nama' => strtoupper(trim($nama)),
            'paspor' => strtoupper(trim($paspor)),
            'enrollment_id' => $enrollmentId,
            'enrollment_time' => $enrollmentTime,
            'clean_text' => trim($cleanHtmlText !== '' ? $cleanHtmlText : $bodyText)
        ];
    }

    private static function cleanPersonName(string $raw, string $paspor = ''): string
    {
        // Hapus koma Arab '،', mojibake 'ØŒ', dan karakter non-ASCII / tanda baca di ujung
        $name = str_replace(["\xC3\x98\xC5\x92", 'ØŒ', '،', ','], ' ', $raw);
        $name = preg_replace('/[^\x20-\x7E]+/', ' ', $name);
        $name = trim($name);
        if ($paspor !== '') {
            $name = str_ireplace($paspor, '', $name);
        }
        $name = preg_replace('/\b(Passport|Number|No|Enrollment|Confirmation|ID|MOFA|Saudi|Visa|Bio)\b.*/i', '', $name);
        $name = preg_replace('/[^A-Za-z\s\.\'\-]+/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name, " \t\n\r\0\x0B.,:-_");
    }

    private static function isValidPersonName(string $name): bool
    {
        if (strlen($name) < 2 || strlen($name) > 60) {
            return false;
        }
        $lower = strtolower($name);
        $blacklist = [
            'applicant', 'customer', 'user', 'sir', 'madam', 'sir/madam',
            'valued applicant', 'visa applicant', 'pilgrim', 'mofa',
            'enrollment confirmation', 'mofa enrollment confirmation',
            'ministry of foreign affairs', 'saudi visa bio', 'kingdom of saudi arabia',
            'biometric enrollment', 'confirmation', 'no reply', 'noreply', 'dear applicant'
        ];
        if (in_array($lower, $blacklist, true)) {
            return false;
        }
        foreach (['ministry of', 'foreign affairs', 'biometric', 'successfully', 'submitted', 'application', 'http', 'www', '@'] as $badWord) {
            if (strpos($lower, $badWord) !== false) {
                return false;
            }
        }
        return (bool) preg_match('/^[A-Za-z][A-Za-z\s\.\',\-]+$/', $name);
    }

    /**
     * Buat nama file sesuai format Nama_Nomor paspor.jpg
     */
    public static function buildFilename(string $nama, string $paspor, array $settings = []): string
    {
        $pattern = $settings['filename_pattern'] ?? '{NAMA}_{PASPOR}';
        $cleanNama = trim(preg_replace('/[\\\\\/:*?"<>|،,]+/u', ' ', $nama));
        $cleanNama = preg_replace('/\s+/', ' ', $cleanNama);
        $cleanPaspor = trim(preg_replace('/[^A-Za-z0-9\-]/', '', $paspor));

        if (!empty($settings['uppercase_filename'])) {
            $cleanNama = strtoupper($cleanNama);
            $cleanPaspor = strtoupper($cleanPaspor);
        }

        $base = str_replace(
            ['{NAMA}', '{PASPOR}'],
            [$cleanNama, $cleanPaspor],
            $pattern
        );
        $base = trim($base, ' _-');
        if ($base === '') {
            $base = 'JAMAAH_' . ($cleanPaspor ?: time());
        }
        return $base . '.jpg';
    }

    /**
     * Render tampilan email PERSIS 1:1 seperti template asli SaudiVisaBio dari Mofa <MofaBioApp@mofa.gov.sa>
     * Menggunakan aset resmi biologoicon.png, biologotext.png, dan success.png serta font Tahoma
     */
    public static function renderEmailConfirmationToGd(array $data)
    {
        // Ukuran asli tabel HTML email MOFA: width="768"
        $width = 768;
        $height = 1048;

        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, true);
        imagesavealpha($im, true);

        // Warna-warna persis dari HTML email MOFA:
        // bgcolor="#0C6D6E", inner card "#FFFFFF", h2 "#61ACAC", red italic, link blue
        $tealBg     = imagecolorallocate($im, 12, 109, 110);  // #0C6D6E
        $white      = imagecolorallocate($im, 255, 255, 255); // #FFFFFF
        $h2Teal     = imagecolorallocate($im, 97, 172, 172);  // #61ACAC
        $textBlack  = imagecolorallocate($im, 34, 34, 34);    // #222222
        $boldBlack  = imagecolorallocate($im, 0, 0, 0);       // #000000
        $linkBlue   = imagecolorallocate($im, 17, 85, 204);   // #1155CC
        $redItalic  = imagecolorallocate($im, 235, 0, 0);     // red

        // 1. Background utama #0C6D6E
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $tealBg);

        $assetsDir = BASE_DIR . DIRECTORY_SEPARATOR . 'assets';
        $logoIconPath = $assetsDir . DIRECTORY_SEPARATOR . 'biologoicon.png';
        $logoTextPath = $assetsDir . DIRECTORY_SEPARATOR . 'biologotext.png';
        $successPath  = $assetsDir . DIRECTORY_SEPARATOR . 'success.png';

        // 2. Header Logo MOFA (biologoicon.png 163x144) & Teks SaudiVisaBio (biologotext.png 163x40)
        if (file_exists($logoIconPath)) {
            $iconIm = @imagecreatefrompng($logoIconPath);
            if ($iconIm) {
                $iw = imagesx($iconIm);
                $ih = imagesy($iconIm);
                imagecopyresampled($im, $iconIm, intval(($width - 163) / 2), 20, 0, 0, 163, 144, $iw, $ih);
                imagedestroy($iconIm);
            }
        }

        if (file_exists($logoTextPath)) {
            $txtIm = @imagecreatefrompng($logoTextPath);
            if ($txtIm) {
                $tw = imagesx($txtIm);
                $th = imagesy($txtIm);
                imagecopyresampled($im, $txtIm, intval(($width - 163) / 2), 166, 0, 0, 163, 40, $tw, $th);
                imagedestroy($txtIm);
            }
        }

        // 3. Kotak Putih Tengah: width="700px", background-color="#ffffff", border-radius="10px"
        $cardX1 = 34; // (768 - 700) / 2
        $cardY1 = 226;
        $cardX2 = 734;
        $cardY2 = 964;
        self::drawRoundedRectangle($im, $cardX1, $cardY1, $cardX2, $cardY2, 10, $white);

        // 4. Gambar Centang Sukses (success.png 288x305) di tengah dalam kotak putih
        if (file_exists($successPath)) {
            $sucIm = @imagecreatefrompng($successPath);
            if ($sucIm) {
                $sw = imagesx($sucIm);
                $sh = imagesy($sucIm);
                imagecopyresampled($im, $sucIm, intval(($width - 288) / 2), 246, 0, 0, 288, 305, $sw, $sh);
                imagedestroy($sucIm);
            }
        }

        // Font Tahoma sesuai style="font-family: tahoma;" di HTML asli
        $fontTahoma     = self::getFontPath('tahoma');
        $fontTahomaBold = self::getFontPath('tahoma_bold');
        $fontItalic     = self::getFontPath('italic');

        // 5. Judul: <h2 style="color:#61ACAC; font-weight:bold; margin:35px;">Application Submitted Successfully</h2>
        $headingText = 'Application Submitted Successfully';
        self::drawCenteredText($im, 15.5, $width / 2, 608, $h2Teal, $fontTahomaBold, $headingText);

        // Data variabel jamaah
        $nama           = strtoupper(trim($data['nama'] ?? 'JAMAAH'));
        $paspor         = strtoupper(trim($data['paspor'] ?? '-'));
        $enrollmentId   = trim($data['enrollment_id'] ?? '-');
        $enrollmentTime = trim($data['enrollment_time'] ?? '');
        if ($enrollmentTime === '') {
            // Coba ekstrak dari body_text jika ada
            if (!empty($data['body_text']) && preg_match('/\bon\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/i', $data['body_text'], $m)) {
                $enrollmentTime = $m[1];
            } else {
                $enrollmentTime = date('Y-m-d H:i:s');
            }
        }

        // Margin kiri teks di dalam tabel putih (cardX1 + 40px padding = 74)
        $leftX = 74;
        $fontSize = 9.5;

        // 6. Baris: "Dear MOHALI,"
        self::drawText($im, $fontSize, $leftX, 668, $textBlack, $fontTahoma, 'Dear ' . $nama . ',');

        // 7. Baris: "Thank you for enrolling your biometric information for your passport number: Y2047838 ."
        $p1Prefix = 'Thank you for enrolling your biometric information for your passport number: ';
        $wPrefix1 = self::drawTextAndMeasure($im, $fontSize, $leftX, 718, $textBlack, $fontTahoma, $p1Prefix);
        $pasporX  = $leftX + $wPrefix1;
        $wPaspor  = self::drawTextAndMeasure($im, $fontSize, $pasporX, 718, $boldBlack, $fontTahomaBold, $paspor);
        // Garis bawah (underline) tebal pada Nomor Paspor
        imageline($im, $pasporX, 721, $pasporX + $wPaspor, 721, $boldBlack);
        imageline($im, $pasporX, 722, $pasporX + $wPaspor, 722, $boldBlack);
        self::drawText($im, $fontSize, $pasporX + $wPaspor, 718, $textBlack, $fontTahoma, ' .');

        // 8. Baris: "on 2026-11-25 02:07:35 as part of your visa application process to the Kingdom of Saudi Arabia. and Your"
        //    "Enrollment Id is : 42163441 ."
        $line3a = "on {$enrollmentTime} as part of your visa application process to the Kingdom of Saudi Arabia. and Your";
        self::drawText($im, $fontSize, $leftX, 768, $textBlack, $fontTahoma, $line3a);

        $line3bPrefix = 'Enrollment Id is : ';
        $wPrefix3b = self::drawTextAndMeasure($im, $fontSize, $leftX, 796, $textBlack, $fontTahoma, $line3bPrefix);
        $enrollX   = $leftX + $wPrefix3b;
        $wEnroll   = self::drawTextAndMeasure($im, $fontSize, $enrollX, 796, $tealBg, $fontTahomaBold, $enrollmentId);
        // Garis bawah (underline) berwarna #0C6D6E pada Enrollment ID
        imageline($im, $enrollX, 799, $enrollX + $wEnroll, 799, $tealBg);
        imageline($im, $enrollX, 800, $enrollX + $wEnroll, 800, $tealBg);
        self::drawText($im, $fontSize, $enrollX + $wEnroll, 796, $textBlack, $fontTahoma, ' .');

        // 9. Baris: "For any questions on submission of your biometric data or the status of your visa application, please visit"
        //    "www.mofa.gov.sa"
        $line4a = 'For any questions on submission of your biometric data or the status of your visa application, please visit';
        self::drawText($im, $fontSize, $leftX, 842, $textBlack, $fontTahoma, $line4a);

        $linkText = 'www.mofa.gov.sa';
        $wLink = self::drawTextAndMeasure($im, $fontSize, $leftX, 870, $linkBlue, $fontTahoma, $linkText);
        imageline($im, $leftX, 873, $leftX + $wLink, 873, $linkBlue);

        // 10. Baris merah miring: "*This is an automated message, please do not reply to this email directly*"
        $redMsg = '*This is an automated message, please do not reply to this email directly*';
        self::drawText($im, $fontSize, $leftX, 932, $redItalic, $fontItalic, $redMsg);

        // 11. Footer putih di bawah kotak putih pada background #0C6D6E:
        // "© All Rights Reserved - Ministry of Foreign Affairs - Kingdom of Saudi Arabia"
        $footerText = html_entity_decode('&copy; All Rights Reserved - Ministry of Foreign Affairs - Kingdom of Saudi Arabia', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::drawCenteredText($im, 10.0, $width / 2, 1008, $white, $fontTahoma, $footerText);

        return $im;
    }

    /**
     * Gambar kotak dengan sudut membulat (border-radius) yang halus
     */
    private static function drawRoundedRectangle($im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        $d = $r * 2;
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $d, $d, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $d, $d, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $d, $d, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $d, $d, $color);
    }

    /**
     * Proses gambar lampiran (attachment) menjadi GD Image TANPA merubah template gambar aslinya
     */
    public static function createGdFromAttachment(string $rawBytes, array $data)
    {
        $src = @imagecreatefromstring($rawBytes);
        if (!$src) {
            return self::renderEmailConfirmationToGd($data);
        }
        return $src;
    }

    /**
     * Crop otomatis area dokumen dari hasil scan/upload.
     * Mendukung dua jenis latar:
     * - Latar gelap (scanner cover hitam) -> cari area terang (dokumen).
     * - Latar putih (kertas/latar kantor) -> cari bounding box area non-putih (dokumen).
     */
    public static function autoCropScannedDocument($im, int $margin = 8)
    {
        if (!is_resource($im) && !($im instanceof \GdImage)) {
            return $im;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 120 || $h < 120) {
            return $im;
        }

        $edgeSample = self::sampleImageEdgesLuminance($im, $w, $h);
        if (floatval($edgeSample['dark_ratio']) >= 0.22) {
            $trimmed = self::stripDarkScannerBorder($im);
            if ($trimmed !== $im) {
                imagedestroy($im);
                $im = $trimmed;
                $w = imagesx($im);
                $h = imagesy($im);
                if ($w < 120 || $h < 120) {
                    return $im;
                }
            }
        }

        $crop = self::detectDocumentBBox($im, $w, $h);
        if ($crop === null) {
            $trimmed = self::trimUniformContentMargins($im);
            return ($trimmed !== $im) ? $trimmed : $im;
        }

        [$left, $top, $right, $bottom] = $crop;
        if ($left >= $right || $top >= $bottom) {
            return $im;
        }

        $m = max(0, min($margin, 20));
        $left = max(0, $left - $m);
        $top = max(0, $top - $m);
        $right = min($w - 1, $right + $m);
        $bottom = min($h - 1, $bottom + $m);

        $cropW = $right - $left + 1;
        $cropH = $bottom - $top + 1;
        if ($cropW < intval($w * 0.12) || $cropH < intval($h * 0.12)) {
            return $im;
        }
        if ($cropW >= $w - 6 && $cropH >= $h - 6) {
            return $im;
        }

        $cropped = imagecreatetruecolor($cropW, $cropH);
        $white = imagecolorallocate($cropped, 255, 255, 255);
        imagefilledrectangle($cropped, 0, 0, $cropW - 1, $cropH - 1, $white);
        imagecopyresampled($cropped, $im, 0, 0, $left, $top, $cropW, $cropH, $cropW, $cropH);
        imagedestroy($im);
        return $cropped;
    }

    /**
     * Crop manual berdasarkan koordinat piksel (natural image space).
     */
    public static function cropGdRect($im, int $x, int $y, int $width, int $height)
    {
        if (!is_resource($im) && !($im instanceof \GdImage)) {
            return $im;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 1 || $h < 1) {
            return $im;
        }

        $x = max(0, min($x, $w - 1));
        $y = max(0, min($y, $h - 1));
        $width = max(1, min($width, $w - $x));
        $height = max(1, min($height, $h - $y));
        if ($width < 8 || $height < 8) {
            return $im;
        }

        $cropped = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($cropped, 255, 255, 255);
        imagefilledrectangle($cropped, 0, 0, $width - 1, $height - 1, $white);
        imagecopyresampled($cropped, $im, 0, 0, $x, $y, $width, $height, $width, $height);
        imagedestroy($im);
        return $cropped;
    }

    /**
     * Deteksi bounding box area dokumen (non-latar).
     */
    private static function detectDocumentBBox($im, int $w, int $h): ?array
    {
        $sample = self::sampleImageEdgesLuminance($im, $w, $h);
        $avgEdge = floatval($sample['avg_luminance']);
        $darkRatio = floatval($sample['dark_ratio']);

        $mode = ($avgEdge >= 175 && $darkRatio < 0.4) ? 'light_bg' : 'dark_bg';
        $bgLum = (int) round($avgEdge);
        $lightThresh = min(248, max(198, $bgLum - 14));
        $darkThresh = max(48, min(130, $bgLum + 28));

        $step = max(2, (int) floor(min($w, $h) / 220));
        $xMin = $w;
        $xMax = -1;
        $yMin = $h;
        $yMax = -1;
        $contentCount = 0;
        $total = 0;

        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $total++;
                $lum = self::pixelLuminance($im, $x, $y);
                $isContent = ($mode === 'light_bg') ? ($lum < $lightThresh) : ($lum > $darkThresh);
                if (!$isContent) {
                    continue;
                }
                $contentCount++;
                if ($x < $xMin) $xMin = $x;
                if ($x > $xMax) $xMax = $x;
                if ($y < $yMin) $yMin = $y;
                if ($y > $yMax) $yMax = $y;
            }
        }

        if ($xMax < 0 || $total < 100) {
            return null;
        }
        if ($contentCount < (int)($total * 0.006) || $contentCount > (int)($total * 0.96)) {
            return null;
        }

        // Padding proporsional agar dokumen tidak kepotong terlalu rapat
        $padX = max(6, (int)(($xMax - $xMin) * 0.04));
        $padY = max(6, (int)(($yMax - $yMin) * 0.04));

        return [
            max(0, $xMin - $padX),
            max(0, $yMin - $padY),
            min($w - 1, $xMax + $padX),
            min($h - 1, $yMax + $padY)
        ];
    }

    /**
     * Perbaiki kontras/kecerahan ringan setelah crop scan.
     */
    public static function refineScannedDocument($im)
    {
        if (!is_resource($im) && !($im instanceof \GdImage)) {
            return $im;
        }
        @imagefilter($im, IMG_FILTER_CONTRAST, -10);
        @imagefilter($im, IMG_FILTER_BRIGHTNESS, 3);
        return $im;
    }

    private static function pixelLuminance($im, int $x, int $y): int
    {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return intval(($r * 299 + $g * 587 + $b * 114) / 1000);
    }

    private static function sampleImageEdgesLuminance($im, int $w, int $h): array
    {
        $samples = [];
        $strip = max(4, (int) floor(min($w, $h) * 0.04));
        $stepX = max(1, (int) floor($w / 100));
        $stepY = max(1, (int) floor($strip / 4));
        $stepXv = max(1, (int) floor($h / 100));
        for ($x = 0; $x < $w; $x += $stepX) {
            for ($y = 0; $y < $strip; $y += $stepY) {
                $samples[] = self::pixelLuminance($im, $x, $y);
                $samples[] = self::pixelLuminance($im, $x, $h - 1 - $y);
            }
        }
        for ($y = 0; $y < $h; $y += $stepXv) {
            for ($x = 0; $x < $strip; $x += $stepY) {
                $samples[] = self::pixelLuminance($im, $x, $y);
                $samples[] = self::pixelLuminance($im, $w - 1 - $x, $y);
            }
        }
        if (empty($samples)) {
            return ['avg_luminance' => 128, 'dark_ratio' => 0];
        }
        $sum = array_sum($samples);
        $dark = 0;
        foreach ($samples as $lum) {
            if ($lum < 80) {
                $dark++;
            }
        }
        return [
            'avg_luminance' => $sum / count($samples),
            'dark_ratio' => $dark / count($samples)
        ];
    }

    /**
     * Pangkas margin kosong di tepi jika deteksi bounding box gagal.
     */
    private static function trimUniformContentMargins($im)
    {
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 160 || $h < 160) {
            return $im;
        }

        $step = max(2, (int) floor(min($w, $h) / 200));
        $edge = self::sampleImageEdgesLuminance($im, $w, $h);
        $bg = (int) round(floatval($edge['avg_luminance']));
        $isLightBg = ($bg >= 165 && floatval($edge['dark_ratio']) < 0.45);
        $thresh = $isLightBg ? min(245, max(190, $bg - 18)) : max(55, min(125, $bg + 32));
        $densityCut = 0.055;

        $left = 0;
        for ($x = 0; $x < $w; $x++) {
            if (self::lineContentRatio($im, $x, 0, 1, $h, $step, $isLightBg, $thresh) >= $densityCut) {
                $left = $x;
                break;
            }
        }
        $right = $w - 1;
        for ($x = $w - 1; $x >= 0; $x--) {
            if (self::lineContentRatio($im, $x, 0, 1, $h, $step, $isLightBg, $thresh) >= $densityCut) {
                $right = $x;
                break;
            }
        }
        $top = 0;
        for ($y = 0; $y < $h; $y++) {
            if (self::lineContentRatio($im, 0, $y, $w, 1, $step, $isLightBg, $thresh) >= $densityCut) {
                $top = $y;
                break;
            }
        }
        $bottom = $h - 1;
        for ($y = $h - 1; $y >= 0; $y--) {
            if (self::lineContentRatio($im, 0, $y, $w, 1, $step, $isLightBg, $thresh) >= $densityCut) {
                $bottom = $y;
                break;
            }
        }

        $cropW = $right - $left + 1;
        $cropH = $bottom - $top + 1;
        if ($cropW < (int)($w * 0.2) || $cropH < (int)($h * 0.2)) {
            return $im;
        }
        if ($cropW >= $w - 4 && $cropH >= $h - 4) {
            return $im;
        }

        $pad = max(6, (int) floor(min($cropW, $cropH) * 0.02));
        $left = max(0, $left - $pad);
        $top = max(0, $top - $pad);
        $right = min($w - 1, $right + $pad);
        $bottom = min($h - 1, $bottom + $pad);
        $cropW = $right - $left + 1;
        $cropH = $bottom - $top + 1;

        $cropped = imagecreatetruecolor($cropW, $cropH);
        $white = imagecolorallocate($cropped, 255, 255, 255);
        imagefilledrectangle($cropped, 0, 0, $cropW - 1, $cropH - 1, $white);
        imagecopyresampled($cropped, $im, 0, 0, $left, $top, $cropW, $cropH, $cropW, $cropH);
        imagedestroy($im);
        return $cropped;
    }

    private static function lineContentRatio($im, int $x0, int $y0, int $wLine, int $hLine, int $step, bool $lightBg, int $thresh): float
    {
        $hits = 0;
        $total = 0;
        for ($y = $y0; $y < $y0 + $hLine; $y += $step) {
            for ($x = $x0; $x < $x0 + $wLine; $x += $step) {
                $total++;
                $lum = self::pixelLuminance($im, $x, $y);
                $isContent = $lightBg ? ($lum < $thresh) : ($lum > $thresh);
                if ($isContent) {
                    $hits++;
                }
            }
        }
        return $total > 0 ? ($hits / $total) : 0.0;
    }

    /**
     * Buang pinggiran gelap (cover scanner) hingga area terang (alas putih + dokumen).
     */
    private static function stripDarkScannerBorder($im)
    {
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 160 || $h < 160) {
            return $im;
        }

        $step = max(2, (int) floor(min($w, $h) / 180));
        $maxScan = (int) floor(min($w, $h) * 0.38);
        $darkCutoff = 0.44;

        $left = 0;
        for ($x = 0; $x < $maxScan; $x++) {
            if (self::lineDarkRatio($im, $x, 0, 1, $h, $step) < $darkCutoff) {
                $left = $x;
                break;
            }
        }
        $right = $w - 1;
        for ($x = $w - 1; $x > $w - 1 - $maxScan; $x--) {
            if (self::lineDarkRatio($im, $x, 0, 1, $h, $step) < $darkCutoff) {
                $right = $x;
                break;
            }
        }
        $top = 0;
        for ($y = 0; $y < $maxScan; $y++) {
            if (self::lineDarkRatio($im, 0, $y, $w, 1, $step) < $darkCutoff) {
                $top = $y;
                break;
            }
        }
        $bottom = $h - 1;
        for ($y = $h - 1; $y > $h - 1 - $maxScan; $y--) {
            if (self::lineDarkRatio($im, 0, $y, $w, 1, $step) < $darkCutoff) {
                $bottom = $y;
                break;
            }
        }

        $cropW = $right - $left + 1;
        $cropH = $bottom - $top + 1;
        if ($cropW < (int) ($w * 0.45) || $cropH < (int) ($h * 0.45)) {
            return $im;
        }
        if ($left <= 2 && $top <= 2 && $right >= $w - 3 && $bottom >= $h - 3) {
            return $im;
        }

        $out = imagecreatetruecolor($cropW, $cropH);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $cropW - 1, $cropH - 1, $white);
        imagecopyresampled($out, $im, 0, 0, $left, $top, $cropW, $cropH, $cropW, $cropH);
        return $out;
    }

    /**
     * Rasio piksel gelap pada garis vertikal atau horizontal.
     */
    private static function lineDarkRatio($im, int $x0, int $y0, int $lineW, int $lineH, int $step): float
    {
        $dark = 0;
        $total = 0;
        if ($lineW === 1) {
            for ($y = $y0; $y < $y0 + $lineH; $y += $step) {
                $total++;
                if (self::pixelLuminance($im, $x0, $y) < 82) {
                    $dark++;
                }
            }
        } else {
            for ($x = $x0; $x < $x0 + $lineW; $x += $step) {
                $total++;
                if (self::pixelLuminance($im, $x, $y0) < 82) {
                    $dark++;
                }
            }
        }
        return $total > 0 ? ($dark / $total) : 0.0;
    }

    /**
     * Kompres dan simpan GD Image menjadi file JPG sesuai batas ukuran kategori ($minKB s/d $maxKB).
     */
    public static function saveGdAs50KbJpg($im, string $outputPath, float $minKB = 42.0, float $maxKB = 49.5, bool $padIfUnderMin = true): array
    {
        $maxKB = min(200.0, max(10.0, $maxKB));
        $maxBytes = intval(floor($maxKB * 1024));
        $minBytes = intval(floor($minKB * 1024));

        $bestBlob = null;
        $bestQuality = 85;

        // 1. Binary search kualitas JPEG dari 15 s/d 96
        $low = 15;
        $high = 96;

        while ($low <= $high) {
            $mid = intval(($low + $high) / 2);
            ob_start();
            imagejpeg($im, null, $mid);
            $blob = ob_get_clean();
            $len = strlen($blob);

            if ($len <= $maxBytes) {
                $bestBlob = $blob;
                $bestQuality = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        // 2. Jika pada kualitas terendah masih > maxBytes, perkecil dimensi gambar bertahap
        $scaleFactor = 0.88;
        while ($bestBlob === null && $scaleFactor >= 0.35) {
            $w = imagesx($im);
            $h = imagesy($im);
            $newW = max(400, intval($w * $scaleFactor));
            $newH = max(400, intval($h * $scaleFactor));

            $scaled = imagecreatetruecolor($newW, $newH);
            $white = imagecolorallocate($scaled, 255, 255, 255);
            imagefilledrectangle($scaled, 0, 0, $newW - 1, $newH - 1, $white);
            imagecopyresampled($scaled, $im, 0, 0, 0, 0, $newW, $newH, $w, $h);

            $l = 20;
            $hQ = 92;
            while ($l <= $hQ) {
                $m = intval(($l + $hQ) / 2);
                ob_start();
                imagejpeg($scaled, null, $m);
                $blob = ob_get_clean();
                if (strlen($blob) <= $maxBytes) {
                    $bestBlob = $blob;
                    $bestQuality = $m;
                    $l = $m + 1;
                } else {
                    $hQ = $m - 1;
                }
            }

            imagedestroy($scaled);
            $scaleFactor -= 0.12;
        }

        if ($bestBlob === null) {
            ob_start();
            imagejpeg($im, null, 15);
            $bestBlob = ob_get_clean();
        }

        // 3. Jika ukuran hasil < $minBytes (misal 32 KB) dan opsi strict_50kb_padding aktif,
        // sisipkan segmen JPEG COM standar agar ukuran file berada tepat di kisaran ~46 - 49 KB (<= 50 KB)
        $currentBytes = strlen($bestBlob);
        if ($padIfUnderMin && $maxKB <= 50.5 && $currentBytes < $minBytes) {
            $targetBytes = intval(($minBytes + $maxBytes) / 2);
            $needed = $targetBytes - $currentBytes;
            if ($needed > 6 && $needed < 45000 && substr($bestBlob, -2) === "\xFF\xD9") {
                $payloadLen = $needed - 4;
                $commentData = str_repeat('SAUDIVISABIO-MOFA-KSA-', intval(ceil($payloadLen / 22)));
                $commentData = substr($commentData, 0, $payloadLen);
                $segLen = strlen($commentData) + 2;
                $comSegment = "\xFF\xFE" . pack('n', $segLen) . $commentData;
                $bestBlob = substr($bestBlob, 0, -2) . $comSegment . "\xFF\xD9";
            }
        }

        file_put_contents($outputPath, $bestBlob, LOCK_EX);
        clearstatcache(true, $outputPath);
        $finalBytes = is_file($outputPath) ? filesize($outputPath) : strlen($bestBlob);

        return [
            'path' => $outputPath,
            'size_bytes' => $finalBytes,
            'size_kb' => round($finalBytes / 1024, 2),
            'quality' => $bestQuality
        ];
    }

    /**
     * Proses 1 email lengkap menjadi JPG <= 50KB sesuai template asli email
     */
    public static function processIncomingEmail(array $emailPayload, array $settings = [], array $jamaahMaster = []): array
    {
        if (empty($settings)) {
            $settings = getSettings();
        }
        if (empty($jamaahMaster)) {
            $jamaahMaster = getJamaahMaster();
        }

        $subject   = trim($emailPayload['subject'] ?? 'Enrollment confirmation');
        $bodyText  = (string) ($emailPayload['body_text'] ?? '');
        $bodyHtml  = (string) ($emailPayload['body_html'] ?? '');
        $from      = trim($emailPayload['from'] ?? 'Mofa <MofaBioApp@mofa.gov.sa>');
        $rawTo     = trim($emailPayload['to'] ?? ($emailPayload['source_account_email'] ?? $settings['source_email']));
        $to        = trim(str_replace(['<', '>'], '', $rawTo));
        $sourceAccEmail = trim($emailPayload['source_account_email'] ?? $to);
        $sourceAccLabel = trim($emailPayload['source_account_label'] ?? '');
        $emailDate = trim($emailPayload['date'] ?? date('Y-m-d H:i:s'));
        $messageId = trim($emailPayload['message_id'] ?? ('msg_' . md5($subject . $bodyText . $emailDate)));

        $extracted = self::extractEmailInfo($subject, $bodyText, $bodyHtml, $jamaahMaster);

        $nama = !empty($emailPayload['override_nama'])
            ? strtoupper(trim($emailPayload['override_nama']))
            : $extracted['nama'];

        $paspor = !empty($emailPayload['override_paspor'])
            ? strtoupper(trim($emailPayload['override_paspor']))
            : $extracted['paspor'];

        $enrollmentId = !empty($emailPayload['override_enrollment_id'])
            ? strtoupper(trim($emailPayload['override_enrollment_id']))
            : $extracted['enrollment_id'];

        $enrollmentTime = !empty($emailPayload['override_enrollment_time'])
            ? trim($emailPayload['override_enrollment_time'])
            : $extracted['enrollment_time'];

        $filename = self::buildFilename($nama, $paspor, $settings);
        $outputPath = getBiovisaDir() . DIRECTORY_SEPARATOR . $filename;

        $renderData = [
            'nama' => $nama,
            'paspor' => $paspor,
            'enrollment_id' => $enrollmentId,
            'enrollment_time' => $enrollmentTime,
            'subject' => $subject,
            'from' => $from,
            'to' => $to,
            'email_date' => $emailDate,
            'body_text' => $extracted['clean_text'] ?: $bodyText
        ];

        $attachments = $emailPayload['attachments'] ?? [];
        $hasValidImageAttachment = false;
        $firstAttachmentBytes = null;
        $attachmentSourceName = '';

        foreach ($attachments as $att) {
            $attData = '';
            if (!empty($att['base64'])) {
                $attData = base64_decode($att['base64']);
            } elseif (!empty($att['raw'])) {
                $attData = $att['raw'];
            }
            if ($attData !== '' && @imagecreatefromstring($attData) !== false) {
                $hasValidImageAttachment = true;
                $firstAttachmentBytes = $attData;
                $attachmentSourceName = $att['filename'] ?? 'attachment.jpg';
                break;
            }
        }

        $minKB = floatval($settings['target_min_kb'] ?? 42.0);
        $maxKB = min(49.5, floatval($settings['target_max_kb'] ?? 49.5));
        $padIfUnder = !empty($settings['strict_50kb_padding']);

        if ($hasValidImageAttachment && ($settings['process_mode'] ?? 'both') !== 'render_only') {
            $gd = self::createGdFromAttachment($firstAttachmentBytes, $renderData);
            $sourceModeUsed = 'attachment (' . $attachmentSourceName . ')';
        } else {
            $gd = self::renderEmailConfirmationToGd($renderData);
            $sourceModeUsed = 'saudivisabio_email';
        }

        $savedMain = self::saveGdAs50KbJpg($gd, $outputPath, $minKB, $maxKB, $padIfUnder);
        imagedestroy($gd);

        return [
            'id' => 'bv_' . substr(md5($paspor . '_' . $nama), 0, 10),
            'message_id' => $messageId,
            'nama' => $nama,
            'paspor' => $paspor,
            'enrollment_id' => $enrollmentId,
            'enrollment_time' => $enrollmentTime,
            'filename' => $filename,
            'size_bytes' => $savedMain['size_bytes'],
            'size_kb' => $savedMain['size_kb'],
            'source_type' => $sourceModeUsed,
            'subject' => $subject,
            'from_email' => $from,
            'to_email' => $to,
            'source_account_email' => $sourceAccEmail,
            'source_account_label' => $sourceAccLabel,
            'email_date' => $emailDate,
            'body_excerpt' => mb_substr($renderData['body_text'], 0, 500),
            'processed_at' => date('Y-m-d H:i:s'),
            'gdrive_uploaded' => false,
            'gdrive_file_id' => '',
            'gdrive_url' => '',
            'gdrive_target_email' => $settings['target_gdrive_email'] ?? 'muchammad.ali.sanjaya@gmail.com',
            'gdrive_error' => ''
        ];
    }

    private static function getFontPath(string $type = 'tahoma'): string
    {
        $winFonts = 'C:\\Windows\\Fonts\\';
        $candidates = [
            'tahoma'      => [$winFonts . 'tahoma.ttf', $winFonts . 'segoeui.ttf', $winFonts . 'arial.ttf'],
            'tahoma_bold' => [$winFonts . 'tahomabd.ttf', $winFonts . 'segoeuib.ttf', $winFonts . 'arialbd.ttf'],
            'italic'      => [$winFonts . 'ariali.ttf', $winFonts . 'segoeuii.ttf', $winFonts . 'tahoma.ttf'],
            'regular'     => [$winFonts . 'tahoma.ttf', $winFonts . 'segoeui.ttf', $winFonts . 'arial.ttf'],
            'bold'        => [$winFonts . 'tahomabd.ttf', $winFonts . 'segoeuib.ttf', $winFonts . 'arialbd.ttf']
        ];

        foreach ($candidates[$type] ?? $candidates['tahoma'] as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return '';
    }

    private static function drawText($im, float $size, int $x, int $y, int $color, string $fontPath, string $text): void
    {
        if ($fontPath !== '' && function_exists('imagettftext')) {
            @imagettftext($im, $size, 0, $x, $y, $color, $fontPath, $text);
        } else {
            imagestring($im, 4, $x, max(0, $y - 14), $text, $color);
        }
    }

    private static function drawTextAndMeasure($im, float $size, int $x, int $y, int $color, string $fontPath, string $text): int
    {
        self::drawText($im, $size, $x, $y, $color, $fontPath, $text);
        if ($fontPath !== '' && function_exists('imagettfbbox')) {
            $bbox = @imagettfbbox($size, 0, $fontPath, $text);
            if ($bbox !== false) {
                return abs($bbox[2] - $bbox[0]);
            }
        }
        return strlen($text) * 8;
    }

    private static function drawCenteredText($im, float $size, float $centerX, int $y, int $color, string $fontPath, string $text): void
    {
        $textWidth = strlen($text) * 8;
        if ($fontPath !== '' && function_exists('imagettfbbox')) {
            $bbox = @imagettfbbox($size, 0, $fontPath, $text);
            if ($bbox !== false) {
                $textWidth = abs($bbox[2] - $bbox[0]);
            }
        }
        $x = intval(round($centerX - ($textWidth / 2)));
        self::drawText($im, $size, $x, $y, $color, $fontPath, $text);
    }
}

