<?php
/**
 * Skrip diagnosis: login via HTTP lalu introspeksi respons api.php?action=status
 * Jalankan: php tools/diag_status.php
 */

$base = 'http://localhost/e-visa';

$jar = tempnam(sys_get_temp_dir(), 'evisacookie');
@unlink($jar);
$cookieFile = $jar . '.txt';

// 1. Ambil halaman login + token CSRF
$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Cookie: \r\n",
        'ignore_errors' => true,
    ],
]);
$html = @file_get_contents($base . '/login.php', false, $ctx);
if ($html === false) {
    echo "GAGAL: tidak bisa membuka login.php\n";
    exit(1);
}
if (!preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) {
    echo "GAGAL: token CSRF tidak ditemukan\n";
    exit(1);
}
$csrf = $m[1];

// 2. Login
$payload = http_build_query([
    'username' => 'superadmin',
    'password' => 'Silahut@09f944ad',
    'csrf_token' => $csrf,
]);
$ctx2 = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'follow_location' => 0,
    ],
]);
$respHeaders = $http_response_header ?? [];
$body = @file_get_contents($base . '/login.php', false, $ctx2);
$setCookie = '';
foreach ($respHeaders as $h) {
    if (stripos($h, 'Set-Cookie:') === 0) {
        $setCookie = trim(substr($h, 11));
    }
}
if ($setCookie === '') {
    echo "GAGAL: tidak ada cookie sesi pada login\n";
    exit(1);
}
$sessionCookie = explode(';', $setCookie)[0];
echo "Login OK (cookie sesi diterima)\n";

// 3. Panggil api.php?action=status memakai cookie sesi
$ctx3 = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Cookie: {$sessionCookie}\r\n",
        'ignore_errors' => true,
    ],
]);
$raw = @file_get_contents($base . '/api.php?action=status', false, $ctx3);
if ($raw === false) {
    echo "GAGAL: api.php tidak merespons\n";
    exit(1);
}
echo "Panjang respons: " . strlen($raw) . " byte\n";
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    echo "RESPONS BUKAN JSON. Cuplikan 600 karakter pertama:\n";
    echo substr($raw, 0, 600) . "\n";
    exit(1);
}
echo "ok=" . var_export($decoded['ok'] ?? null, true) . "\n";
echo "error=" . ($decoded['error'] ?? '-') . "\n";
echo "total_biovisa=" . ($decoded['stats']['total_biovisa'] ?? '-') . "\n";
echo "jumlah records=" . count($decoded['records'] ?? []) . "\n";
echo "records_root_path=" . ($decoded['docs_root_path'] ?? '-') . "\n";
