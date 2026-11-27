<?php
/**
 * Uji end-to-end fitur Super Admin lewat HTTP (menggunakan cURL + cookie jar).
 * Jalankan: php tools/test_superadmin.php
 */

$base = 'http://localhost/e-visa';
$jar = tempnam(sys_get_temp_dir(), 'evisar') . '.txt';

function req(string $url, string $jar, ?array $payload = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$body, true);
    return [
        'code' => $code,
        'json' => is_array($decoded) ? $decoded : null,
        'raw' => (string)$body,
    ];
}

// --- Login ---
$ch = curl_init($base . '/login.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_TIMEOUT => 30,
]);
$html = (string)curl_exec($ch);
curl_close($ch);
preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
$csrf = $m[1] ?? '';

$ch = curl_init($base . '/login.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => 'superadmin',
        'password' => 'Silahut@09f944ad',
        'csrf_token' => $csrf,
    ]),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => false,
]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "1. Login superadmin: HTTP $code " . ($code === 302 ? 'OK' : 'GAGAL') . "\n";
if ($code !== 302) {
    echo "   Halaman login tidak memproses POST. Periksa CSRF/session.\n";
    exit(1);
}

$slug = 'kota-malang';

// --- Simpan instansi baru ---
$res = req($base . '/api.php?action=save_lembaga', $jar, [
    'slug' => $slug,
    'nama' => 'Kantor Kemenhaj Kota Malang (Uji)',
    'source_email' => 'kemenhaj.malang@example.com',
    'target_gdrive_email' => 'gdrive.malang@example.com',
    'gdrive_folder_name' => 'Biovisa_Jamaah_Kemenhaj_Malang',
]);
echo "2. Simpan instansi baru: ok=" . var_export($res['json']['ok'] ?? false, true)
    . ' ' . ($res['json']['message'] ?? $res['json']['error'] ?? substr($res['raw'], 0, 120)) . "\n";

// --- Daftar instansi ---
$res = req($base . '/api.php?action=list_lembaga', $jar);
$list = $res['json']['lembaga'] ?? [];
$newId = '';
$found = false;
foreach ($list as $l) {
    if (($l['slug'] ?? '') === $slug) {
        $found = true;
        $newId = $l['id'];
    }
}
echo "3. Instansi baru terdaftar: " . ($found ? 'YA' : 'TIDAK') . ' (total ' . count($list) . ")\n";

// --- Daftar pengguna ---
$res = req($base . '/api.php?action=list_users', $jar);
echo "4. Daftar pengguna terbaca: ok=" . var_export($res['json']['ok'] ?? false, true)
    . ' total=' . count($res['json']['users'] ?? []) . "\n";

// --- Simpan pengguna baru ---
$res = req($base . '/api.php?action=save_user', $jar, [
    'username' => 'admin_malang',
    'nama' => 'Admin Malang',
    'password' => 'MalangTest123',
    'role' => 'admin',
    'lembaga_id' => $newId,
]);
echo "5. Simpan pengguna baru: ok=" . var_export($res['json']['ok'] ?? false, true)
    . ' ' . ($res['json']['message'] ?? $res['json']['error'] ?? '') . "\n";

// --- Update password (benar) ---
$res = req($base . '/api.php?action=change_my_password', $jar, [
    'current_password' => 'Silahut@09f944ad',
    'new_password' => 'Silahut@09f944ad',
]);
echo "6. Update password (lama benar): ok=" . var_export($res['json']['ok'] ?? false, true)
    . ' ' . ($res['json']['message'] ?? $res['json']['error'] ?? '') . "\n";

// --- Update password (lama salah) -> harus ditolak ---
$res = req($base . '/api.php?action=change_my_password', $jar, [
    'current_password' => 'SALAH-SEKALI',
    'new_password' => 'BaruSekali123',
]);
echo "7. Update password (lama salah, harus ditolak): ok=" . var_export($res['json']['ok'] ?? true, true)
    . ' ' . ($res['json']['error'] ?? '') . "\n";

// --- Status agregat ---
$res = req($base . '/api.php?action=status', $jar);
$j = $res['json'] ?? [];
echo "8. Status agregat: total=" . ($j['stats']['total_biovisa'] ?? '-')
    . ' super=' . var_export($j['is_super_admin'] ?? false, true)
    . ' instansi=' . count($j['lembaga_list'] ?? []) . "\n";

// --- Update instansi (edit) ---
$res = req($base . '/api.php?action=save_lembaga', $jar, [
    'id' => $newId,
    'slug' => $slug,
    'nama' => 'Kantor Kemenhaj Kota Malang (Uji Edit)',
    'source_email' => 'kemenhaj.malang@example.com',
    'target_gdrive_email' => 'gdrive.malang@example.com',
    'gdrive_folder_name' => 'Biovisa_Jamaah_Kemenhaj_Malang',
]);
echo "9. Edit instansi: ok=" . var_export($res['json']['ok'] ?? false, true)
    . ' ' . ($res['json']['message'] ?? $res['json']['error'] ?? '') . "\n";

@unlink($jar);
