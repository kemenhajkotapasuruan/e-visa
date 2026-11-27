<?php
/**
 * Autentikasi, sesi, role (super_admin | admin), dan konteks lembaga.
 */

final class Auth
{
    public const ROLE_SUPER = 'super_admin';
    public const ROLE_ADMIN = 'admin';

    private static bool $inited = false;

    public static function init(): void
    {
        if (self::$inited) {
            return;
        }
        self::$inited = true;

        if (session_status() === PHP_SESSION_NONE) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => $secure,
            ]);
            session_name('SILAHUT_EVISA_SESS');
            session_start();
        }

        self::ensureAuthStorage();
        self::repairAuthTenantIntegrity();
        self::migrateLegacyStorageIfNeeded();
        self::bootstrapTenantContext();
    }

    private static function authDir(): string
    {
        $dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auth';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    private static function usersFile(): string
    {
        return self::authDir() . DIRECTORY_SEPARATOR . 'users.json';
    }

    private static function lembagaFile(): string
    {
        return self::authDir() . DIRECTORY_SEPARATOR . 'lembaga.json';
    }

    private static function secretsFile(): string
    {
        return self::authDir() . DIRECTORY_SEPARATOR . 'secrets.json';
    }

    public static function ensureAuthStorage(): void
    {
        if (!file_exists(self::lembagaFile())) {
            $settings = loadJsonFile(
                STORAGE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'settings.json',
                getDefaultSettings()
            );
            $slug = 'phu-pasuruan';
            saveJsonFile(self::lembagaFile(), [[
                'id' => 'lem_' . substr(md5($slug), 0, 10),
                'slug' => $slug,
                'nama' => 'Kantor Kemenhaj Kota Pasuruan',
                'source_email' => $settings['source_email'] ?? 'phujtmkopasuruan@gmail.com',
                'target_gdrive_email' => $settings['target_gdrive_email'] ?? '',
                'gdrive_folder_name' => $settings['gdrive_folder_name'] ?? 'Biovisa_Jamaah_PHU_Pasuruan',
                'gas_gdrive_webapp_url' => $settings['gas_gdrive_webapp_url'] ?? '',
                'gas_gmail_webapp_url' => $settings['gas_gmail_webapp_url'] ?? '',
                'source_accounts' => $settings['source_accounts'] ?? [],
                'aktif' => true,
                'created_at' => date('Y-m-d H:i:s'),
            ]]);
        }

        if (!file_exists(self::usersFile())) {
            $lembaga = self::getLembagaList();
            $lemId = $lembaga[0]['id'] ?? 'lem_default';
            $plain = 'Silahut@' . substr(bin2hex(random_bytes(4)), 0, 8);
            saveJsonFile(self::usersFile(), [
                [
                    'id' => 'usr_super_1',
                    'username' => 'superadmin',
                    'password_hash' => password_hash($plain, PASSWORD_DEFAULT),
                    'nama' => 'Super Administrator',
                    'role' => self::ROLE_SUPER,
                    'lembaga_id' => null,
                    'aktif' => true,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'id' => 'usr_admin_1',
                    'username' => 'admin',
                    'password_hash' => password_hash($plain, PASSWORD_DEFAULT),
                    'nama' => 'Admin Kantor Kemenhaj Pasuruan',
                    'role' => self::ROLE_ADMIN,
                    'lembaga_id' => $lemId,
                    'aktif' => true,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            ]);
            @file_put_contents(
                self::authDir() . DIRECTORY_SEPARATOR . 'INITIAL_PASSWORD.txt',
                "Akun pertama kali (wajib diganti setelah login):\n\nsuperadmin / {$plain}\nadmin / {$plain}\n"
            );
        }

        if (!file_exists(self::secretsFile())) {
            saveJsonFile(self::secretsFile(), [
                'webhook_token' => bin2hex(random_bytes(24)),
                'api_token' => bin2hex(random_bytes(24)),
                'block_public_cloud_sync' => true,
            ]);
        }
    }

    public static function migrateLegacyStorageIfNeeded(): void
    {
        $slug = 'phu-pasuruan';
        $sec = self::getSecrets();
        if (!empty($sec['legacy_storage_merged_phu_v1'])) {
            tenantEnsureDirs($slug);
            return;
        }

        $targetRoot = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga' . DIRECTORY_SEPARATOR . $slug;
        $legacyData = STORAGE_DIR . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($legacyData)) {
            tenantEnsureDirs($slug);
            $sec['legacy_storage_merged_phu_v1'] = true;
            saveJsonFile(self::secretsFile(), $sec);
            return;
        }

        tenantEnsureDirs($slug);
        require_once __DIR__ . '/LegacyStorageMerge.php';
        legacyMergePasuruanTenant($slug);

        $sec['legacy_storage_merged_phu_v1'] = true;
        saveJsonFile(self::secretsFile(), $sec);
    }

    public static function getSecrets(): array
    {
        return loadJsonFile(self::secretsFile(), []);
    }

    public static function getLembagaList(): array
    {
        return loadJsonFile(self::lembagaFile(), []);
    }

    public static function getLembagaById(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        foreach (self::getLembagaList() as $lem) {
            if (($lem['id'] ?? '') === $id) {
                return $lem;
            }
        }
        return null;
    }

    /**
     * Pastikan setiap instansi punya ID unik dan admin terikat ke satu instansi (bukan fallback kota).
     */
    public static function repairAuthTenantIntegrity(): void
    {
        $list = self::getLembagaList();
        $changedLem = false;
        foreach ($list as $idx => $lem) {
            if (trim((string)($lem['id'] ?? '')) === '') {
                $slug = self::sanitizeSlug((string)($lem['slug'] ?? ''));
                $list[$idx]['id'] = 'lem_' . substr(md5($slug . microtime(true)), 0, 12);
                $changedLem = true;
            }
        }
        if ($changedLem) {
            saveJsonFile(self::lembagaFile(), $list);
        }

        $users = self::loadUsersRaw();
        $changedUsers = false;
        foreach ($users as $idx => $u) {
            if (($u['role'] ?? '') !== self::ROLE_ADMIN) {
                continue;
            }
            $lemId = trim((string)($u['lembaga_id'] ?? ''));
            if ($lemId !== '' && self::getLembagaById($lemId)) {
                continue;
            }
            $resolved = self::guessLembagaForOrphanAdmin($u, $list);
            if ($resolved) {
                $users[$idx]['lembaga_id'] = $resolved['id'];
                $changedUsers = true;
            }
        }
        if ($changedUsers) {
            saveJsonFile(self::usersFile(), $users);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $lembagaList
     */
    private static function guessLembagaForOrphanAdmin(array $user, array $lembagaList): ?array
    {
        $needle = mb_strtolower(trim((string)($user['nama'] ?? '')) . ' ' . trim((string)($user['username'] ?? '')));
        $best = null;
        $bestScore = 0;
        foreach ($lembagaList as $lem) {
            $slug = (string)($lem['slug'] ?? '');
            if ($slug === 'phu-pasuruan') {
                continue;
            }
            $hay = mb_strtolower(($lem['nama'] ?? '') . ' ' . $slug);
            $score = 0;
            if ($hay !== '' && mb_strpos($needle, 'kabupaten') !== false && mb_strpos($hay, 'kab') !== false) {
                $score += 4;
            }
            if ($hay !== '' && mb_strpos($needle, 'kota') !== false && mb_strpos($hay, 'kota') !== false) {
                $score += 4;
            }
            if ($hay !== '' && mb_strpos($needle, mb_strtolower($slug)) !== false) {
                $score += 5;
            }
            foreach (preg_split('/\s+/', mb_strtolower((string)($lem['nama'] ?? ''))) ?: [] as $word) {
                if (mb_strlen($word) >= 5 && mb_strpos($needle, $word) !== false) {
                    $score += 1;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $lem;
            }
        }
        return ($bestScore >= 3 && $best) ? $best : null;
    }

    public static function resolveLembagaForUser(?array $user): ?array
    {
        if (!$user) {
            return null;
        }
        if (($user['role'] ?? '') === self::ROLE_SUPER) {
            return null;
        }
        $lemId = trim((string)($user['lembaga_id'] ?? ''));
        if ($lemId !== '') {
            $lem = self::getLembagaById($lemId);
            if ($lem) {
                return $lem;
            }
        }
        return self::guessLembagaForOrphanAdmin($user, self::getLembagaList());
    }

    public static function getLembagaBySlug(string $slug): ?array
    {
        $slug = self::sanitizeSlug($slug);
        foreach (self::getLembagaList() as $lem) {
            if (($lem['slug'] ?? '') === $slug) {
                return $lem;
            }
        }
        return null;
    }

    public static function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug);
        return trim($slug, '-');
    }

    private static function loadUsersRaw(): array
    {
        return loadJsonFile(self::usersFile(), []);
    }

    private static function findActiveUserRow(): ?array
    {
        $uid = $_SESSION['user_id'] ?? '';
        if ($uid === '') {
            return null;
        }
        foreach (self::loadUsersRaw() as $u) {
            if (($u['id'] ?? '') === $uid && !empty($u['aktif'])) {
                unset($u['password_hash']);
                return $u;
            }
        }
        return null;
    }

    private static function tenantSlugForUserRow(?array $u): string
    {
        if ($u && ($u['role'] ?? '') === self::ROLE_ADMIN) {
            $lem = self::resolveLembagaForUser($u);
            if ($lem && trim((string)($lem['slug'] ?? '')) !== '') {
                return self::sanitizeSlug($lem['slug']);
            }
        }
        $sess = self::sanitizeSlug((string)($_SESSION['active_lembaga_slug'] ?? ''));
        return $sess !== '' ? $sess : 'phu-pasuruan';
    }

    public static function user(): ?array
    {
        $u = self::findActiveUserRow();
        if (!$u) {
            return null;
        }
        $u['active_lembaga_slug'] = self::tenantSlugForUserRow($u);
        return $u;
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): void
    {
        if (self::isLoggedIn()) {
            self::bootstrapTenantContext();
            return;
        }
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (str_ends_with($script, 'api.php')) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Sesi berakhir. Silakan login ulang.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
        exit;
    }

    public static function requireSuperAdmin(): void
    {
        $u = self::user();
        if (!$u || ($u['role'] ?? '') !== self::ROLE_SUPER) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Akses ditolak: Super Admin diperlukan.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public static function login(string $username, string $password): array
    {
        $username = trim($username);
        foreach (self::loadUsersRaw() as $u) {
            if (!empty($u['aktif']) && strcasecmp($u['username'] ?? '', $username) === 0) {
                if (!password_verify($password, $u['password_hash'] ?? '')) {
                    return ['ok' => false, 'error' => 'Username atau password salah.'];
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = $u['id'];
                $_SESSION['role'] = $u['role'];
                if (($u['role'] ?? '') === self::ROLE_ADMIN) {
                    $lem = self::resolveLembagaForUser($u);
                    if (!$lem || trim((string)($lem['slug'] ?? '')) === '') {
                        return ['ok' => false, 'error' => 'Akun Admin belum ditautkan ke instansi. Hubungi Super Admin.'];
                    }
                    $_SESSION['active_lembaga_slug'] = $lem['slug'];
                } else {
                    $_SESSION['active_lembaga_slug'] = $_SESSION['active_lembaga_slug'] ?? 'phu-pasuruan';
                }
                self::bootstrapTenantContext();
                addSyncLog('info', 'Login: ' . ($u['username'] ?? '') . ' (' . ($u['role'] ?? '') . ')');
                return ['ok' => true, 'user' => self::user()];
            }
        }
        return ['ok' => false, 'error' => 'Username atau password salah.'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function setActiveLembagaSlug(string $slug): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $lem = self::getLembagaBySlug($slug);
        if (!$lem) {
            return false;
        }
        if (($u['role'] ?? '') === self::ROLE_ADMIN) {
            $own = self::getLembagaById($u['lembaga_id'] ?? '');
            if (!$own || ($own['slug'] ?? '') !== $slug) {
                return false;
            }
        }
        $_SESSION['active_lembaga_slug'] = $slug;
        self::bootstrapTenantContext();
        return true;
    }

    public static function tenantSlug(): string
    {
        return self::tenantSlugForUserRow(self::findActiveUserRow());
    }

    public static function bootstrapTenantBySlug(string $slug): bool
    {
        $lem = self::getLembagaBySlug($slug);
        if (!$lem) {
            return false;
        }
        $_SESSION['active_lembaga_slug'] = $lem['slug'];
        self::bootstrapTenantContext();
        return true;
    }

    public static function bootstrapTenantContext(): void
    {
        tenantBindPaths(self::tenantSlug());
    }

    /**
     * Super Admin melihat agregasi seluruh lembaga, bukan satu lembaga.
     */
    public static function isSuperAdmin(): bool
    {
        $u = self::user();
        return $u !== null && ($u['role'] ?? '') === self::ROLE_SUPER;
    }

    /**
     * Daftar seluruh slug lembaga yang boleh diakses pengguna saat ini.
     */
    public static function accessibleSlugs(): array
    {
        $u = self::user();
        if (!$u) {
            return [];
        }
        if (($u['role'] ?? '') === self::ROLE_SUPER) {
            $slugs = [];
            foreach (self::getLembagaList() as $lem) {
                $slug = (string)($lem['slug'] ?? '');
                if ($slug !== '') {
                    $slugs[] = $slug;
                }
            }
            return $slugs === [] ? [self::tenantSlug()] : $slugs;
        }
        return [self::tenantSlug()];
    }

    /**
     * Ubah password milik pengguna yang sedang login.
     */
    public static function changeOwnPassword(string $current, string $new): array
    {
        $u = self::user();
        if (!$u) {
            return ['ok' => false, 'error' => 'Belum login.'];
        }
        if (strlen($new) < 8) {
            return ['ok' => false, 'error' => 'Password baru minimal 8 karakter.'];
        }

        $users = self::loadUsersRaw();
        foreach ($users as $idx => $row) {
            if (($row['id'] ?? '') !== ($u['id'] ?? '')) {
                continue;
            }
            if (!password_verify($current, $row['password_hash'] ?? '')) {
                return ['ok' => false, 'error' => 'Password lama salah.'];
            }
            $users[$idx]['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            $users[$idx]['password_updated_at'] = date('Y-m-d H:i:s');
            saveJsonFile(self::usersFile(), $users);
            @unlink(self::authDir() . DIRECTORY_SEPARATOR . 'INITIAL_PASSWORD.txt');
            return ['ok' => true, 'message' => 'Password berhasil diperbarui.'];
        }

        return ['ok' => false, 'error' => 'Pengguna tidak ditemukan.'];
    }

    public static function verifyApiToken(?string $token): bool
    {
        $token = trim((string)$token);
        if ($token === '') {
            return false;
        }
        $sec = self::getSecrets();
        return hash_equals((string)($sec['api_token'] ?? ''), $token);
    }

    public static function verifyWebhookToken(?string $token): bool
    {
        $token = trim((string)$token);
        if ($token === '') {
            return false;
        }
        $sec = self::getSecrets();
        return hash_equals((string)($sec['webhook_token'] ?? ''), $token);
    }

    public static function extractBearerOrHeaderToken(): string
    {
        $h = $_SERVER['HTTP_X_EVISA_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($h, 'Bearer ') === 0) {
            return trim(substr($h, 7));
        }
        return trim((string)($_GET['api_token'] ?? $_POST['api_token'] ?? $h));
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return $token !== null && $token !== '' && hash_equals(self::csrfToken(), $token);
    }

    public static function saveUser(array $data): array
    {
        $users = self::loadUsersRaw();
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            $id = 'usr_' . uniqid();
        }
        $username = trim((string)($data['username'] ?? ''));
        $role = in_array(($data['role'] ?? ''), [self::ROLE_SUPER, self::ROLE_ADMIN], true)
            ? $data['role'] : self::ROLE_ADMIN;

        if ($username === '') {
            return ['ok' => false, 'error' => 'Username wajib diisi.'];
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
            return ['ok' => false, 'error' => 'Username hanya boleh huruf, angka, titik, garis bawah, atau strip (3-32 karakter).'];
        }

        foreach ($users as $u) {
            if (($u['id'] ?? '') !== $id && strcasecmp($u['username'] ?? '', $username) === 0) {
                return ['ok' => false, 'error' => 'Username sudah dipakai.'];
            }
        }

        $lembagaId = $role === self::ROLE_SUPER ? null : trim((string)($data['lembaga_id'] ?? ''));
        if ($role === self::ROLE_ADMIN) {
            if ($lembagaId === '') {
                return ['ok' => false, 'error' => 'Instansi wajib dipilih untuk peran Admin.'];
            }
            if (!self::getLembagaById($lembagaId)) {
                return ['ok' => false, 'error' => 'Instansi tidak ditemukan atau belum valid.'];
            }
        }

        $found = false;
        foreach ($users as $idx => $u) {
            if (($u['id'] ?? '') === $id) {
                $found = true;
                $users[$idx]['username'] = $username;
                $users[$idx]['nama'] = trim((string)($data['nama'] ?? $u['nama'] ?? ''));
                $users[$idx]['role'] = $role;
                $users[$idx]['lembaga_id'] = $role === self::ROLE_SUPER ? null : ($lembagaId !== '' ? $lembagaId : trim((string)($u['lembaga_id'] ?? '')));
                $users[$idx]['aktif'] = !isset($data['aktif']) || !empty($data['aktif']);
                if (!empty($data['password'])) {
                    $users[$idx]['password_hash'] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
                    $users[$idx]['password_updated_at'] = date('Y-m-d H:i:s');
                }
                break;
            }
        }
        if (!$found) {
            $pass = (string)($data['password'] ?? '');
            if ($pass === '') {
                return ['ok' => false, 'error' => 'Password wajib untuk pengguna baru.'];
            }
            if (strlen($pass) < 8) {
                return ['ok' => false, 'error' => 'Password minimal 8 karakter.'];
            }
            $users[] = [
                'id' => $id,
                'username' => $username,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'nama' => trim((string)($data['nama'] ?? $username)),
                'role' => $role,
                'lembaga_id' => $role === self::ROLE_SUPER ? null : $lembagaId,
                'aktif' => true,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
        saveJsonFile(self::usersFile(), $users);
        return ['ok' => true, 'message' => 'Pengguna disimpan.'];
    }

    public static function saveLembaga(array $data): array
    {
        $list = self::getLembagaList();
        $slug = self::sanitizeSlug((string)($data['slug'] ?? ''));
        $nama = trim((string)($data['nama'] ?? ''));
        if ($slug === '' || $nama === '') {
            return ['ok' => false, 'error' => 'Nama dan slug lembaga wajib diisi.'];
        }
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            foreach ($list as $lem) {
                if (($lem['slug'] ?? '') === $slug && trim((string)($lem['id'] ?? '')) !== '') {
                    $id = (string)$lem['id'];
                    break;
                }
            }
            if ($id === '') {
                $id = 'lem_' . uniqid();
            }
        }
        foreach ($list as $lem) {
            if (($lem['id'] ?? '') !== $id && ($lem['slug'] ?? '') === $slug) {
                return ['ok' => false, 'error' => 'Slug lembaga sudah dipakai.'];
            }
        }
        $row = [
            'id' => $id,
            'slug' => $slug,
            'nama' => $nama,
            'source_email' => trim((string)($data['source_email'] ?? '')),
            'target_gdrive_email' => trim((string)($data['target_gdrive_email'] ?? '')),
            'gdrive_folder_name' => trim((string)($data['gdrive_folder_name'] ?? ('Biovisa_' . strtoupper($slug)))),
            'gas_gdrive_webapp_url' => trim((string)($data['gas_gdrive_webapp_url'] ?? '')),
            'gas_gmail_webapp_url' => trim((string)($data['gas_gmail_webapp_url'] ?? '')),
            'source_accounts' => is_array($data['source_accounts'] ?? null) ? $data['source_accounts'] : [],
            'aktif' => !isset($data['aktif']) || !empty($data['aktif']),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $found = false;
        $isNewInstansi = true;
        foreach ($list as $idx => $lem) {
            if (($lem['id'] ?? '') === $id || ($lem['slug'] ?? '') === $slug) {
                $isNewInstansi = false;
                $row['created_at'] = $lem['created_at'] ?? date('Y-m-d H:i:s');
                // Pertahankan daftar akun email bila tidak dikirim ulang
                if (empty($row['source_accounts']) && !empty($lem['source_accounts'])) {
                    $row['source_accounts'] = $lem['source_accounts'];
                }
                if (($row['gas_gdrive_webapp_url'] ?? '') === '' && !empty($lem['gas_gdrive_webapp_url'])) {
                    $row['gas_gdrive_webapp_url'] = $lem['gas_gdrive_webapp_url'];
                }
                if (($row['gas_gmail_webapp_url'] ?? '') === '' && !empty($lem['gas_gmail_webapp_url'])) {
                    $row['gas_gmail_webapp_url'] = $lem['gas_gmail_webapp_url'];
                }
                $list[$idx] = $row;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $row['created_at'] = date('Y-m-d H:i:s');
            $list[] = $row;
        }
        saveJsonFile(self::lembagaFile(), $list);
        tenantEnsureDirs($slug);

        // Sinkronkan pengaturan tenant (instansi baru mulai dari pengaturan kosong, tanpa data kota)
        $tenantSettings = self::targetRootSettingsPath($slug);
        $hadSettingsFile = is_file($tenantSettings);
        $cur = loadJsonFile($tenantSettings, []);
        if ($cur === []) {
            $cur = getDefaultSettings();
        }
        if ($isNewInstansi && !$hadSettingsFile) {
            $cur = array_merge(getDefaultSettings(), [
                'source_email' => $row['source_email'],
                'target_gdrive_email' => $row['target_gdrive_email'],
                'gdrive_folder_name' => $row['gdrive_folder_name'],
                'gas_gdrive_webapp_url' => $row['gas_gdrive_webapp_url'],
                'gas_gmail_webapp_url' => $row['gas_gmail_webapp_url'],
                'source_accounts' => $row['source_accounts'],
                'last_sync_status' => 'Belum dikonfigurasi — isi Pengaturan & Integrasi',
            ]);
        } else {
            if (($row['source_email'] ?? '') !== '') {
                $cur['source_email'] = $row['source_email'];
            }
            if (($row['target_gdrive_email'] ?? '') !== '') {
                $cur['target_gdrive_email'] = $row['target_gdrive_email'];
            }
            if (($row['gdrive_folder_name'] ?? '') !== '') {
                $cur['gdrive_folder_name'] = $row['gdrive_folder_name'];
            }
            if (($row['gas_gdrive_webapp_url'] ?? '') !== '') {
                $cur['gas_gdrive_webapp_url'] = $row['gas_gdrive_webapp_url'];
            }
            if (($row['gas_gmail_webapp_url'] ?? '') !== '') {
                $cur['gas_gmail_webapp_url'] = $row['gas_gmail_webapp_url'];
            }
            if (!empty($row['source_accounts'])) {
                $cur['source_accounts'] = $row['source_accounts'];
            }
        }
        saveJsonFile($tenantSettings, $cur);

        return ['ok' => true, 'message' => 'Instansi disimpan.', 'lembaga' => $row];
    }

    private static function targetRootSettingsPath(string $slug): string
    {
        return STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga' . DIRECTORY_SEPARATOR . $slug
            . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'settings.json';
    }
}
