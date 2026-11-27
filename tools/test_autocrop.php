<?php
/**
 * Uji unit autoCropScannedDocument & compress per kategori.
 * Jalankan: php tools/test_autocrop.php
 */
require_once __DIR__ . '/../includes/BiovisaProcessor.php';

// --- 1. Gambar sintetis: latar putih, dokumen di kiri-atas ---
$W = 800; $H = 600;
$im = imagecreatetruecolor($W, $H);
$white = imagecolorallocate($im, 255, 255, 255);
imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, $white);

// Dokumen: kotak hijau-kebiruan di posisi (80,60)-(360,240)
$docColor = imagecolorallocate($im, 40, 120, 90);
$paper = imagecolorallocate($im, 230, 225, 200);
imagefilledrectangle($im, 80, 60, 360, 240, $paper);
imagefilledrectangle($im, 90, 70, 350, 230, $docColor);

$cropped = BiovisaProcessor::autoCropScannedDocument($im);
$cw = imagesx($cropped);
$ch = imagesy($cropped);
echo "Uji latar putih: asli {$W}x{$H} -> crop {$cw}x{$ch}\n";
if ($cw < $W && $ch < $H) {
    echo "  HASIL: ter-crop (lebih kecil dari gambar asli)\n";
} else {
    echo "  HASIL: TIDAK ter-crop\n";
}
imagedestroy($cropped);

// --- 2. Gambar latar gelap: dokumen terang di tengah ---
$im2 = imagecreatetruecolor($W, $H);
$black = imagecolorallocate($im2, 15, 15, 15);
imagefilledrectangle($im2, 0, 0, $W - 1, $H - 1, $black);
$pale = imagecolorallocate($im2, 245, 245, 235);
imagefilledrectangle($im2, 150, 100, 650, 500, $pale);
$cropped2 = BiovisaProcessor::autoCropScannedDocument($im2);
$cw2 = imagesx($cropped2);
$ch2 = imagesy($cropped2);
echo "Uji latar gelap: asli {$W}x{$H} -> crop {$cw2}x{$ch2}\n";
if ($cw2 < $W && $ch2 < $H) {
    echo "  HASIL: ter-crop\n";
} else {
    echo "  HASIL: TIDAK ter-crop\n";
}
imagedestroy($cropped2);

// --- 2b. Border gelap scanner + alas putih + paspor kecil ---
$im3 = imagecreatetruecolor($W, $H);
$black = imagecolorallocate($im3, 12, 12, 12);
imagefilledrectangle($im3, 0, 0, $W - 1, $H - 1, $black);
$white = imagecolorallocate($im3, 252, 252, 252);
imagefilledrectangle($im3, 55, 45, $W - 55, $H - 45, $white);
$passport = imagecolorallocate($im3, 55, 95, 130);
imagefilledrectangle($im3, 120, 110, 480, 310, $passport);
$cropped3 = BiovisaProcessor::autoCropScannedDocument($im3);
$cw3 = imagesx($cropped3);
$ch3 = imagesy($cropped3);
echo "Uji border gelap+putih: asli {$W}x{$H} -> crop {$cw3}x{$ch3}\n";
if ($cw3 < $W && $ch3 < $H && $cw3 < 520 && $ch3 < 350) {
    echo "  HASIL: ter-crop ke area dokumen\n";
} else {
    echo "  HASIL: TIDAK ter-crop optimal\n";
}
imagedestroy($cropped3);

// --- 3. Batas ukuran per kategori ---
foreach (array_keys(getSiskohatDocCategories()) as $cat) {
    $lim = getSiskohatCategoryCompressLimits($cat);
    echo sprintf("  %-12s min=%.1f KB max=%.1f KB pad=%s\n", $cat, $lim['min_kb'], $lim['max_kb'], $lim['pad_if_under_min'] ? 'ya' : 'tidak');
}

// --- 4. Kompres dengan batas 200 KB (paspor) ---
$big = imagecreatetruecolor(2400, 1600);
for ($y = 0; $y < 1600; $y += 4) {
    for ($x = 0; $x < 2400; $x += 4) {
        $c = imagecolorallocate($big, ($x * 3) % 256, ($y * 5) % 256, ($x + $y) % 256);
        imagefilledrectangle($big, $x, $y, $x + 3, $y + 3, $c);
    }
}
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_paspor.jpg';
$lim = getSiskohatCategoryCompressLimits('paspor');
$res = BiovisaProcessor::saveGdAs50KbJpg($big, $tmp, $lim['min_kb'], $lim['max_kb'], $lim['pad_if_under_min']);
imagedestroy($big);
echo sprintf("Uji kompres paspor: %.2f KB (batas %.0f KB) -> %s\n", $res['size_kb'], $lim['max_kb'], $res['size_kb'] <= $lim['max_kb'] ? 'OK' : 'GAGAL');
@unlink($tmp);

$imRect = imagecreatetruecolor(400, 300);
$wC = imagecolorallocate($imRect, 255, 255, 255);
imagefilledrectangle($imRect, 0, 0, 399, 299, $wC);
$croppedR = BiovisaProcessor::cropGdRect($imRect, 50, 40, 260, 200);
$rw = imagesx($croppedR);
$rh = imagesy($croppedR);
echo "Uji cropGdRect: asli 400x300 -> {$rw}x{$rh}\n";
echo ($rw === 260 && $rh === 200) ? "  HASIL: OK\n" : "  HASIL: GAGAL\n";
imagedestroy($croppedR);

echo "Selesai.\n";
