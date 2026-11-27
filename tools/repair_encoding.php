<?php
/**
 * Pemulihan mojibake (UTF-8 yang ter-decode Windows-1252 dua kali) + hapus BOM.
 *
 * Aturan aman:
 *  - Mojibake SELALU diawali karakter lead Latin-1 U+00C2..U+00F6
 *    (hasil decode byte lead UTF-8 C2..F4 sebagai cp1252).
 *  - Karakter Unicode asli (— ✦ ✅ ▾ ، dll) TIDAK tersentuh karena
 *    nilai kodepoint-nya di luar rentang tersebut.
 *
 * Jalankan: php tools/repair_encoding.php [--apply]
 */

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv ?? [], true);

$skipDirs = ['.git', 'node_modules', 'vendor', '.idea'];
$exts = ['php', 'js', 'html', 'json', 'css', 'gs', 'md', 'txt'];

/** Byte cp1252 yang tidak punya karakter definite (0x81,0x8D,0x8F,0x90,0x9D). */
const CP1252_UNDEFINED = [0x81, 0x8D, 0x8F, 0x90, 0x9D];

/**
 * Tabel karakter UTF-8 -> byte cp1252 (kebalikan).
 */
function cp1252ReverseTable(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $special = [
        "\u{20AC}" => 0x80, "\u{201A}" => 0x82, "\u{0192}" => 0x83, "\u{201E}" => 0x84,
        "\u{2026}" => 0x85, "\u{2020}" => 0x86, "\u{2021}" => 0x87, "\u{02C6}" => 0x88,
        "\u{2030}" => 0x89, "\u{0160}" => 0x8A, "\u{2039}" => 0x8B, "\u{0152}" => 0x8C,
        "\u{017D}" => 0x8E, "\u{2018}" => 0x91, "\u{2019}" => 0x92, "\u{201C}" => 0x93,
        "\u{201D}" => 0x94, "\u{2022}" => 0x95, "\u{2013}" => 0x96, "\u{2014}" => 0x97,
        "\u{02DC}" => 0x98, "\u{2122}" => 0x99, "\u{0161}" => 0x9A, "\u{203A}" => 0x9B,
        "\u{0153}" => 0x9C, "\u{017E}" => 0x9E, "\u{0178}" => 0x9F,
    ];
    $t = $special;
    for ($b = 0xA0; $b <= 0xFF; $b++) {
        $t[mb_chr($b, 'UTF-8')] = $b;
    }
    return $t;
}

/**
 * Karakter lead mojibake: U+00C2..U+00F6.
 */
function isMojibakeLead(string $ch): bool
{
    $cp = mb_ord($ch, 'UTF-8');
    if ($cp === false) {
        return false;
    }
    return $cp >= 0xC2 && $cp <= 0xF6;
}

/**
 * Karakter continuation mojibake: Latin-1 U+0080..U+00BF
 * atau salah satu karakter cp1252 khusus.
 */
function isMojibakeContinuation(string $ch): bool
{
    static $rev = null;
    if ($rev === null) {
        $rev = cp1252ReverseTable();
    }
    $cp = mb_ord($ch, 'UTF-8');
    if ($cp === false) {
        return false;
    }
    if ($cp >= 0x80 && $cp <= 0xBF) {
        return true;
    }
    return isset($rev[$ch]) || $ch === "\u{FFFD}";
}

/**
 * Apakah baris byte ini UTF-8 valid tanpa sisa lead mojibake?
 */
function bytesAreCleanUtf8(string $bytes): bool
{
    if ($bytes === '' || !preg_match('//u', $bytes)) {
        return false;
    }
    foreach (preg_split('//u', $bytes, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        if (isMojibakeLead($c)) {
            return false;
        }
    }
    return true;
}

/**
 * Susun baris byte dari daftar slot (single byte atau daftar kandidat).
 */
function buildBytes(array $slots): ?string
{
    $combos = [[]];
    foreach ($slots as $slot) {
        $next = [];
        foreach ($combos as $combo) {
            foreach ($slot as $b) {
                $next[] = array_merge($combo, [$b]);
                if (count($next) > 2048) {
                    break 2;
                }
            }
        }
        $combos = $next;
    }
    foreach ($combos as $combo) {
        $bytes = '';
        foreach ($combo as $b) {
            $bytes .= chr($b);
        }
        if (bytesAreCleanUtf8($bytes)) {
            return $bytes;
        }
    }
    return null;
}

/**
 * Coba pulihkan satu run karakter mojibake.
 * Mengembalikan [teks, jumlah karakter terpakai] atau [null, 0].
 */
function repairRun(string $run): array
{
    static $rev = null;
    if ($rev === null) {
        $rev = cp1252ReverseTable();
    }
    $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($chars);

    $slots = [];
    foreach ($chars as $ch) {
        if ($ch === "\u{FFFD}") {
            $slots[] = CP1252_UNDEFINED;
            continue;
        }
        if (!isset($rev[$ch])) {
            return [null, 0];
        }
        $slots[] = [$rev[$ch]];
    }

    for ($len = count($slots); $len >= 1; $len--) {
        $candidate = buildBytes(array_slice($slots, 0, $len));
        if ($candidate !== null) {
            return [$candidate, $len];
        }
    }
    return [null, 0];
}

function repairMojibake(string $text): array
{
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($chars);
    $out = '';
    $fixed = 0;
    $removed = 0;
    $i = 0;

    while ($i < $n) {
        $ch = $chars[$i];

        if (!isMojibakeLead($ch)) {
            $out .= $ch;
            $i++;
            continue;
        }

        // Kumpulkan run: lead + continuation.
        $run = $ch;
        $j = $i + 1;
        while ($j < $n && isMojibakeContinuation($chars[$j]) && ($j - $i) < 12) {
            $run .= $chars[$j];
            $j++;
        }

        [$fixedText, $used] = repairRun($run);
        if ($used > 0) {
            $out .= $fixedText;
            $fixed += $used;
            $i += $used;
            continue;
        }

        $removed += ($j - $i);
        $i = $j;
    }

    return [$out, $fixed, $removed];
}

// --- Kumpulkan file ---
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

$changed = [];

foreach ($files as $path) {
    $raw = file_get_contents($path);
    if ($raw === false) {
        continue;
    }

    $bomCount = 0;
    $offset = 0;
    while (substr($raw, $offset, 3) === "\xEF\xBB\xBF") {
        $bomCount++;
        $offset += 3;
    }
    $body = substr($raw, $offset);

    if ($body === '' || !mb_check_encoding($body, 'UTF-8')) {
        continue;
    }

    [$fixedText, $fixedCount, $removedCount] = repairMojibake($body);

    if ($bomCount === 0 && $fixedCount === 0 && $removedCount === 0) {
        continue;
    }

    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $changed[] = $rel;

    if ($apply) {
        file_put_contents($path, $fixedText);
    }

    echo sprintf(
        "  %-50s BOM:%d  dipulihkan:%d  dibuang:%d%s\n",
        $rel,
        $bomCount,
        $fixedCount,
        $removedCount,
        $apply ? '  [DITERAPKAN]' : '  [simulasi]'
    );
}

echo "\nFile diperiksa: " . count($files) . " | berubah: " . count($changed) . "\n";
if (!$apply) {
    echo "Jalankan ulang dengan --apply untuk menerapkan.\n";
}
