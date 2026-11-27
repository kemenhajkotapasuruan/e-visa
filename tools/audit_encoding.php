<?php
/**
 * Audit file: cari BOM di awal file & karakter mojibake (UTF-8 yang ter-encode dua kali)
 * Jalankan: php tools/audit_encoding.php
 */

$root = dirname(__DIR__);
$skipDirs = ['.git', 'node_modules', 'vendor', '.idea'];

$exts = ['php', 'js', 'html', 'json', 'css', 'gs', 'md', 'txt'];
$files = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $path = $f->getPathname();
    foreach ($skipDirs as $sd) {
        if (strpos($path, DIRECTORY_SEPARATOR . $sd . DIRECTORY_SEPARATOR) !== false) {
            continue 2;
        }
    }
    $ext = strtolower($f->getExtension());
    if (!in_array($ext, $exts, true)) {
        continue;
    }
    $files[] = $path;
}

$bomFiles = [];
$mojiFiles = [];

foreach ($files as $path) {
    $raw = file_get_contents($path);
    if ($raw === false) {
        continue;
    }

    // Deteksi BOM (EF BB BF) di awal file
    $bomCount = 0;
    $offset = 0;
    while (substr($raw, $offset, 3) === "\xEF\xBB\xBF") {
        $bomCount++;
        $offset += 3;
    }
    if ($bomCount > 0) {
        $bomFiles[] = [$path, $bomCount];
    }

    // Deteksi mojibake: karakter khas hasil decode UTF-8 dua kali
    $text = substr($raw, $offset);
    $moji = [];
    foreach (['', '', '', '', '’', '“', '\x9D', '', ''] as $needle) {
        $c = substr_count($text, $needle);
        if ($c > 0) {
            $moji[$needle] = $c;
        }
    }
    if ($moji) {
        $mojiFiles[] = [$path, $moji];
    }
}

echo "=== FILE DENGAN BOM ===\n";
if (!$bomFiles) {
    echo "(tidak ada)\n";
}
foreach ($bomFiles as [$p, $c]) {
    echo str_replace($root . DIRECTORY_SEPARATOR, '', $p) . "  (BOM x{$c})\n";
}

echo "\n=== FILE DENGAN MOJIBAKE ===\n";
if (!$mojiFiles) {
    echo "(tidak ada)\n";
}
foreach ($mojiFiles as [$p, $moji]) {
    $parts = [];
    foreach ($moji as $n => $c) {
        $parts[] = sprintf('%s=%d', bin2hex($n), $c);
    }
    echo str_replace($root . DIRECTORY_SEPARATOR, '', $p) . '  ' . implode(' ', $parts) . "\n";
}

echo "\nTotal file diperiksa: " . count($files) . "\n";
