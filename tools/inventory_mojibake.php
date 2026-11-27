<?php
/**
 * Inventaris karakter mojibake yang ditemukan, beserta konteks &Proposed perbaikan.
 * Jalankan: php tools/inventory_mojibake.php
 */

$root = dirname(__DIR__);
$skipDirs = ['.git', 'node_modules', 'vendor', '.idea'];
$exts = ['php', 'js', 'html', 'json', 'css', 'gs', 'md', 'txt'];

/** Petacp1252 byte -> karakter UTF-8 */
function cp1252Table(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $special = [
        "\x80" => "\u{20AC}", "\x82" => "\u{201A}", "\x83" => "\u{0192}", "\x84" => "\u{201E}",
        "\x85" => "\u{2026}", "\x86" => "\u{2020}", "\x87" => "\u{2021}", "\x88" => "\u{02C6}",
        "\x89" => "\u{2030}", "\x8A" => "\u{0160}", "\x8B" => "\u{2039}", "\x8C" => "\u{0152}",
        "\x8E" => "\u{017D}", "\x91" => "\u{2018}", "\x92" => "\u{2019}", "\x93" => "\u{201C}",
        "\x94" => "\u{201D}", "\x95" => "\u{2022}", "\x96" => "\u{2013}", "\x97" => "\u{2014}",
        "\x98" => "\u{02DC}", "\x99" => "\u{2122}", "\x9A" => "\u{0161}", "\x9B" => "\u{203A}",
        "\x9C" => "\u{0153}", "\x9E" => "\u{017E}", "\x9F" => "\u{0178}",
    ];
    $t = $special;
    for ($b = 0xA0; $b <= 0xFF; $b++) {
        $t[chr($b)] = mb_chr($b, 'UTF-8');
    }
    return $t;
}

/**
 * Kembalikan teks UTF-8 dari byte cp1252.
 */
function fromCp1252(string $bytes): string
{
    $t = cp1252Table();
    $out = '';
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $ch = $bytes[$i];
        $out .= ($ch === "\x80" || $ch === "\x81" || $ch === "\x8D" || $ch === "\x8F" || $ch === "\x90" || $ch === "\x9D")
            ? '?'
            : ($t[$ch] ?? '?');
    }
    return $out;
}

/**
 * Kembalikan byte cp1252 dari teks UTF-8 (kebalikan).
 */
function toCp1252(string $text): string
{
    $out = '';
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        $bytes = @mb_convert_encoding($ch, 'Windows-1252', 'UTF-8');
        $out .= ($bytes === false || $bytes === '') ? '?' : $bytes;
    }
    return $out;
}

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
    if (!in_array(strtolower($f->getExtension()), $exts, true)) {
        continue;
    }
    $files[] = $path;
}

$inventory = [];

foreach ($files as $path) {
    $raw = file_get_contents($path);
    if ($raw === false) {
        continue;
    }
    $offset = 0;
    while (substr($raw, $offset, 3) === "\xEF\xBB\xBF") {
        $offset += 3;
    }
    $body = substr($raw, $offset);
    if ($body === '' || !mb_check_encoding($body, 'UTF-8')) {
        continue;
    }

    // Cari semua kemunculan karakter non-ASCII yang "mencurigakan".
    $chars = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($chars);
    for ($i = 0; $i < $n; $i++) {
        $ch = $chars[$i];
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp === false || $cp < 0xA0) {
            continue;
        }
        // Kumpulkan run: karakter non-ASCII berturut-turut (maks 6).
        $run = '';
        $j = $i;
        while ($j < $n && mb_ord($chars[$j], 'UTF-8') >= 0xA0 && mb_strlen($run, 'UTF-8') < 6) {
            $run .= $chars[$j];
            $j++;
        }
        $bytes = toCp1252($run);
        $restored = fromCp1252($bytes);
        $key = $run . '=>' . $restored;
        if (!isset($inventory[$key])) {
            $inventory[$key] = ['run' => $run, 'restored' => $restored, 'count' => 0, 'files' => [], 'sample' => ''];
        }
        $inventory[$key]['count']++;
        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
        if (!in_array($rel, $inventory[$key]['files'], true)) {
            $inventory[$key]['files'][] = $rel;
        }
        if ($inventory[$key]['sample'] === '') {
            $start = max(0, $i - 30);
            $ctx = '';
            for ($k = $start; $k < min($n, $j + 30); $k++) {
                $ctx .= $chars[$k];
            }
            $inventory[$key]['sample'] = $ctx;
        }
        $i = $j - 1;
    }
}

uasort($inventory, static fn($a, $b) => $b['count'] <=> $a['count']);

echo "=== INVENTARIS MOJIBAKE (" . count($inventory) . " pola unik) ===\n\n";
foreach ($inventory as $item) {
    printf("Jumlah : %d\n", $item['count']);
    printf("Asli   : %s\n", $item['run']);
    printf("Pulih  : %s\n", $item['restored']);
    printf("File   : %s\n", implode(', ', array_slice($item['files'], 0, 4)));
    printf("Contoh : %s\n\n", str_replace(["\r", "\n"], ' ', $item['sample']));
}
