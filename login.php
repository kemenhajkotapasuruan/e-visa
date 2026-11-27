<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Auth.php';
Auth::init();

$error = '';
$next = trim((string)($_GET['next'] ?? 'index.php'));
if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $next)) {
    $next = 'index.php';
}

if (Auth::isLoggedIn()) {
    header('Location: ' . $next);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!Auth::verifyCsrf($csrf)) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman.';
    } else {
        $res = Auth::login(trim($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($res['ok']) {
            header('Location: ' . $next);
            exit;
        }
        $error = $res['error'] ?? 'Login gagal.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login SILAHUT E-VISA</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
</head>
<body class="admin-body" style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;">
    <div class="admin-card" style="max-width:420px;width:100%;padding:28px 26px;">
        <div style="text-align:center;margin-bottom:18px;">
            <img src="assets/logo-silahut.svg" alt="Logo" style="height:56px;">
            <h1 style="font-size:18px;margin:12px 0 4px;">SILAHUT E-VISA</h1>
            <p style="font-size:13px;color:var(--text-secondary);">Masuk untuk mengakses data Biovisa jamaah (terlindungi).</p>
        </div>
        <?php if ($error !== ''): ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:13px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
            <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;">Username</label>
            <input class="form-control" type="text" name="username" required style="margin-bottom:14px;">
            <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px;">Password</label>
            <input class="form-control" type="password" name="password" required style="margin-bottom:18px;">
            <button type="submit" class="btn btn-gold" style="width:100%;">Masuk</button>
        </form>
        <p style="font-size:11.5px;color:var(--text-secondary);margin-top:14px;">Password awal (jika baru dipasang): lihat file <code>storage/data/auth/INITIAL_PASSWORD.txt</code> di server — wajib diganti via Super Admin.</p>
    </div>
</body>
</html>
