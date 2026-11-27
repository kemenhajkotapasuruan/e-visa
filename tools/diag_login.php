<?php
/**
 * Diagnosis login: apakah POST login benar-benar berhasil?
 * Jalankan: php tools/diag_login.php
 */

$base = 'http://localhost/e-visa';

$html = @file_get_contents($base . '/login.php');
if ($html === false) {
    echo "GAGAL buka login.php\n";
    exit(1);
}
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$csrf = $m[1] ?? '';
echo "csrf diambil: " . ($csrf !== '' ? 'ya (' . strlen($csrf) . ' karakter)' : 'TIDAK ADA') . "\n";

// --- Cek password langsung terhadap users.json ---
$users = json_decode(file_get_contents('c:/laragon/www/e-visa/storage/data/auth/users.json'), true);
$plain = 'Silahut@09f944ad';
foreach ($users as $u) {
    $ok = password_verify($plain, $u['password_hash'] ?? '');
    echo sprintf(
        "user %-12s verify('%s') = %s | hash=%s\n",
        $u['username'],
        $plain,
        $ok ? 'COCOK' : 'TIDAK COCOK',
        substr($u['password_hash'] ?? '', 0, 7)
    );
}

// --- Cek konfigurasi session ---
echo "session.save_path = '" . ini_get('session.save_path') . "'\n";
echo "session.name default = '" . ini_get('session.name') . "'\n";
echo "session.cookie_secure = " . var_export((bool) ini_get('session.cookie_secure'), true) . "\n";
$sp = session_save_path();
if ($sp === '') {
    $sp = sys_get_temp_dir();
    echo "(kosong -> pakai sys_get_temp_dir: {$sp})\n";
}
echo "session save path exists: " . var_export(is_dir($sp), true) . " | writable: " . var_export(is_writable($sp), true) . "\n";
echo "session files: " . count(glob($sp . '/sess_*') ?: []) . "\n";
