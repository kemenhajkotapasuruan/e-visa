<?php
require_once __DIR__ . '/config.php';
$settings = getSettings();
$gasGdriveCode = @file_get_contents(__DIR__ . '/gas/1_GDrive_muchammad_ali_sanjaya.gs') ?: '';
$gasGmailCode  = @file_get_contents(__DIR__ . '/gas/2_Gmail_phujtmkopasuruan.gs') ?: '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SILAHUT E-VISA &bull; Sistem Otomatisasi BioVisa Jamaah Haji | PHU Kemenag Pasuruan</title>
    <link rel="icon" type="image/svg+xml" href="assets/logo-silahut.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="admin.css">
</head>
<body class="admin-body">

    <!-- 1. TOP GOVERNMENT BAR (.top-gov-bar - #000000) -->
    <div class="top-gov-bar">
        <div class="top-gov-inner">
            <div class="gov-badge-left">
                <span class="gold-highlight">✦ KEMENTERIAN HAJI DAN UMRAH REPUBLIK INDONESIA</span>
                <span>&bull;</span>
                <span>Penyelenggaraan Haji dan Umrah Kota Pasuruan</span>
            </div>
            <div class="gov-badge-right">
                <span>Standar Siskohat: <strong class="gold-highlight">JPG &le; 50 KB (Nama_Nomor paspor.jpg)</strong></span>
                <span>&bull;</span>
                <span id="live-clock-wib">--:--:-- WIB</span>
            </div>
        </div>
    </div>

    <!-- 2. APP SHELL: SIDEBAR + MAIN WORKSPACE -->
    <div class="app-shell">

        <!-- MOBILE SIDEBAR BACKDROP -->
        <div class="sidebar-backdrop" id="sidebar-backdrop" onclick="toggleMobileSidebar(false)"></div>

        <!-- ADMIN SIDEBAR (.admin-sidebar - #111827 Charcoal + #D4AF37 Gold, Clean Governance Style) -->
        <aside class="admin-sidebar" id="admin-sidebar">
            <div class="sidebar-inner-wrap">
                <a href="index.php" class="sidebar-brand">
                    <img src="assets/logo-silahut.svg" alt="Logo PHU Kemenag">
                    <div class="sidebar-brand-text">
                        <div class="brand-tag">KEMENHAJ KOTA PASURUAN</div>
                        <div class="brand-name">SILAHUT E-VISA</div>
                        <div class="brand-sub">Sistem Otomatisasi Biovisa</div>
                    </div>
                </a>

                <div class="sidebar-section-label">Menu &amp; Submenu Layanan</div>
                <nav class="sidebar-nav">
                    <!-- MENU 1: LAYANAN DOKUMEN PEMVISAAN -->
                    <div class="sidebar-menu-group open" id="menu-grp-layanan">
                        <button type="button" class="sidebar-parent-btn" onclick="toggleSidebarGroup('menu-grp-layanan')">
                            <span class="sidebar-parent-left">
                                <span class="parent-indicator"></span>
                                <span>Dokumen Pemvisaan</span>
                            </span>
                            <span class="submenu-chevron">▾</span>
                        </button>
                        <div class="sidebar-submenu">
                            <button type="button" class="sidebar-sublink active" id="nav-tab-records" onclick="openRecordsSubmenu('table', 'all', false, this)">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Daftar Biovisa Jamaah</span>
                                </span>
                                <span class="sidebar-badge" id="nav-badge-total">0</span>
                            </button>
                            <button type="button" class="sidebar-sublink" id="nav-sub-7docs" onclick="openRecordsSubmenu('table', 'all', true, this)">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Upload &amp; Scan 7 Dokumen</span>
                                </span>
                            </button>
                            <button type="button" class="sidebar-sublink" id="nav-sub-gallery" onclick="openRecordsSubmenu('grid', 'all', false, this)">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Galeri Visual Biovisa</span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- MENU 2: INTEGRASI SISKOHAT GEN.2 -->
                    <div class="sidebar-menu-group open" id="menu-grp-siskohat">
                        <button type="button" class="sidebar-parent-btn" onclick="toggleSidebarGroup('menu-grp-siskohat')">
                            <span class="sidebar-parent-left">
                                <span class="parent-indicator"></span>
                                <span>Integrasi Siskohat Gen.2</span>
                            </span>
                            <span class="submenu-chevron">▾</span>
                        </button>
                        <div class="sidebar-submenu">
                            <button type="button" class="sidebar-sublink" id="nav-tab-siskohat" onclick="switchTab('tab-siskohat', this)">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Panduan &amp; Ekstensi Chrome</span>
                                </span>
                            </button>
                            <button type="button" class="sidebar-sublink" id="nav-sub-siskohat-pend" onclick="openRecordsSubmenu('table', 'siskohat_pending', false, this)">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Antrean Belum Siskohat</span>
                                </span>
                                <span class="sidebar-badge" id="nav-badge-siskohat-pend">0</span>
                            </button>
                            <a href="api.php?action=download_siskohat_ext" class="sidebar-sublink">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Unduh Ekstensi (.ZIP)</span>
                                </span>
                            </a>
                        </div>
                    </div>

                    <!-- MENU 3: PENGATURAN & INTEGRASI -->
                    <div class="sidebar-menu-group open" id="menu-grp-setup">
                        <button type="button" class="sidebar-parent-btn" onclick="toggleSidebarGroup('menu-grp-setup')">
                            <span class="sidebar-parent-left">
                                <span class="parent-indicator"></span>
                                <span>Pengaturan &amp; Integrasi</span>
                            </span>
                            <span class="submenu-chevron">▾</span>
                        </button>
                        <div class="sidebar-submenu">
                            <button type="button" class="sidebar-sublink" id="nav-tab-setup" onclick="switchTab('tab-setup', this)">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Akun Email &amp; Google Drive</span>
                                </span>
                            </button>
                            <button type="button" class="sidebar-sublink" id="nav-tab-gas" onclick="switchTab('tab-gas-scripts', this)">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Kode Google Apps Script</span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- MENU 4: LAPORAN & AUDIT SISTEM -->
                    <div class="sidebar-menu-group open" id="menu-grp-laporan">
                        <button type="button" class="sidebar-parent-btn" onclick="toggleSidebarGroup('menu-grp-laporan')">
                            <span class="sidebar-parent-left">
                                <span class="parent-indicator"></span>
                                <span>Laporan &amp; Audit Sistem</span>
                            </span>
                            <span class="submenu-chevron">▾</span>
                        </button>
                        <div class="sidebar-submenu">
                            <button type="button" class="sidebar-sublink" id="nav-tab-logs" onclick="switchTab('tab-logs', this)">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Log Aktivitas Sistem</span>
                                </span>
                            </button>
                            <a href="api.php?action=export_csv" class="sidebar-sublink">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Ekspor Rekap Excel (.CSV)</span>
                                </span>
                            </a>
                            <a href="api.php?action=download_zip" class="sidebar-sublink">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Unduh Arsip (.ZIP)</span>
                                </span>
                            </a>
                            <button type="button" class="sidebar-sublink" onclick="printOfficialReport()">
                                <span class="sidebar-link-left">
                                    <span class="nav-bullet"></span>
                                    <span>Cetak Laporan Resmi</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </nav>

                <div class="sidebar-section-label">Kontrol Otomatisasi</div>
                <div class="sidebar-radar-card">
                    <div class="radar-header">
                        <span id="sync-dot" class="pulse-dot"></span>
                        <div>
                            <div style="font-size: 11.8px; font-weight: 700; color: #E2C278;" id="sync-countdown-text">Auto-Sync Aktif</div>
                            <div style="font-size: 10.5px; color: #94A3B8;">Subjek: Enrollment confirmation</div>
                        </div>
                    </div>

                    <label class="sidebar-toggle-row" title="Cek otomatis email Enrollment confirmation yang baru masuk">
                        <span>Auto-Cek Email Masuk</span>
                        <span class="gov-switch">
                            <input type="checkbox" id="toggle-auto-sync" <?= !empty($settings['auto_sync_enabled']) ? 'checked' : '' ?> onchange="toggleQuickSetting()">
                            <span class="gov-slider"></span>
                        </span>
                    </label>

                    <label class="sidebar-toggle-row" title="Otomatis ter-download ke komputer Anda setiap ada email biovisa baru masuk">
                        <span>Auto-Unduh ke Komputer</span>
                        <span class="gov-switch">
                            <input type="checkbox" id="toggle-auto-download" <?= !empty($settings['auto_download_browser']) ? 'checked' : '' ?> onchange="toggleQuickSetting()">
                            <span class="gov-slider"></span>
                        </span>
                    </label>

                    <label class="sidebar-toggle-row" title="Bunyi notifikasi lembut saat ada email Biovisa baru masuk">
                        <span>Notifikasi Suara</span>
                        <span class="gov-switch">
                            <input type="checkbox" id="toggle-sound-notif" checked>
                            <span class="gov-slider"></span>
                        </span>
                    </label>
                </div>
            </div>
        </aside>

        <!-- MAIN WORKSPACE -->
        <div class="admin-main">

            <!-- TOP MAIN NAVBAR (.main-navbar - #FFFFFF + #D4AF37 Gold Border) -->
            <header class="main-navbar">
                <div class="navbar-inner">
                    <div class="navbar-left">
                        <button class="sidebar-toggle-btn" onclick="toggleSidebarVisibility()" title="Sembunyikan / Tampilkan Sidebar Navigasi">
                            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="9" y1="3" x2="9" y2="21"></line>
                                <polyline points="14 9 11 12 14 15"></polyline>
                            </svg>
                        </button>
                        <div>
                            <div class="portal-title-main" style="font-size: 16px;">PORTAL OTOMATISASI BIOVISA JAMAAH HAJI</div>
                            <div style="font-size: 12px; color: var(--text-secondary);" id="last-sync-info">
                                Siap memantau email subjek "Enrollment confirmation" secara real-time
                            </div>
                        </div>
                    </div>

                    <div class="account-pills">
                        <div class="portal-pill-btn" title="Akun Gmail Penerima Email MOFA">
                            <span style="color:var(--text-secondary);">Email Sumber:</span>
                            <strong id="header-source-email"><?= htmlspecialchars($settings['source_email']) ?></strong>
                        </div>
                        <div class="portal-pill-btn" title="Akun Google Drive Penyimpanan Otomatis">
                            <span style="color:var(--text-secondary);">Google Drive:</span>
                            <strong id="header-gdrive-email"><?= htmlspecialchars($settings['target_gdrive_email']) ?></strong>
                        </div>
                    </div>
                </div>
            </header>

            <!-- WORKSPACE CONTAINER -->
            <main class="workspace-container">

                <!-- HERO PORTAL BANNER (.hero-portal - #111827 Charcoal + Gold Divider) -->
                <section class="hero-portal bg-emerald">
                    <div class="hero-inner">
                        <div class="hero-copy">
                            <div class="hero-eyebrow">✦ SISTEM TERINTEGRASI MOFA SAUDIVISABIO &amp; SISKOHAT</div>
                            <h1>Otomatisasi Konversi Biovisa Jamaah ke JPG &le; 50 KB &amp; Google Drive</h1>
                            <div class="portal-title-divider"></div>
                            <p>
                                Setiap email <strong>Enrollment confirmation</strong> yang masuk ke <strong><?= htmlspecialchars($settings['source_email']) ?></strong> otomatis diekstrak sesuai template asli <strong>SaudiVisaBio</strong> menjadi file <code>Nama_Nomor paspor.jpg</code> (&le; 50 KB), diunduh otomatis, dan disimpan ke Google Drive <strong><?= htmlspecialchars($settings['target_gdrive_email']) ?></strong>.
                            </p>
                        </div>

                        <div class="hero-actions">
                            <button class="btn btn-gold portal-action-btn" id="btn-sync-now" onclick="syncEmailsNow(false)">
                                Cek Email Masuk Sekarang
                            </button>
                            <button class="btn btn-charcoal" id="btn-batch-gdrive" onclick="batchSyncPendingGDrive()" style="display:none;">
                                Sinkron GDrive (<span id="hero-pending-count">0</span>)
                            </button>
                            <button class="btn btn-charcoal" id="btn-simulate" onclick="simulateIncomingEmail()">
                                Simulasi Email Masuk
                            </button>
                            <a class="btn btn-outline" href="api.php?action=export_csv">
                                Ekspor Excel (.CSV)
                            </a>
                        </div>
                    </div>
                </section>

                <!-- BATCH UPLOAD LIVE PROGRESS BANNER -->
                <div class="batch-progress-banner" id="batch-progress-banner">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <strong style="color:var(--rich-gold); font-size:13px;" id="batch-progress-title">Mengunggah File Biovisa ke Google Drive...</strong>
                        <span style="font-size:12px; font-weight:700;" id="batch-progress-Label">0 / 0</span>
                    </div>
                    <div class="progress-track" style="background:#1E293B; border-color:#334155; height:9px;">
                        <div class="progress-fill" id="batch-progress-bar" style="width:0%;"></div>
                    </div>
                </div>

                <!-- 4 KARTU STATISTIK ADMIN (.admin-stat-card - #FFFFFF + Gold Top Accent) -->
                <div class="stats-grid">
                    <div class="admin-stat-card">
                        <div class="stat-top">
                            <span class="stat-label">Total Biovisa Jamaah</span>
                            <span class="badge badge-gold">JPG</span>
                        </div>
                        <div class="stat-value">
                            <span id="stat-total">0</span>
                            <span style="font-size: 13px; font-weight: 700; color: var(--ui-gold);">Dokumen</span>
                        </div>
                        <span class="stat-sub">Format: <code>Nama_Nomor paspor.jpg</code></span>
                    </div>

                    <div class="admin-stat-card">
                        <div class="stat-top">
                            <span class="stat-label">Validasi Ukuran &le; 50 KB</span>
                            <span class="badge badge-gold">50 KB</span>
                        </div>
                        <div class="stat-value">
                            <span id="stat-50kb">0</span>
                            <span style="font-size: 13px; font-weight: 700; color: var(--text-secondary);" id="stat-avg-kb">Rata-rata 0 KB</span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" id="progress-50kb" style="width: 100%;"></div>
                        </div>
                        <span class="stat-sub">100% Sesuai Template Asli SaudiVisaBio</span>
                    </div>

                    <div class="admin-stat-card">
                        <div class="stat-top">
                            <span class="stat-label">Tersimpan di Google Drive</span>
                            <span class="badge badge-charcoal">GDRIVE</span>
                        </div>
                        <div class="stat-value">
                            <span id="stat-gdrive">0</span>
                            <button class="btn btn-gold btn-sm" id="btn-retry-all-gdrive" onclick="batchSyncPendingGDrive()" style="margin-left:auto; display:none;">
                                Upload Pending
                            </button>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" id="progress-gdrive" style="width: 0%;"></div>
                        </div>
                        <span class="stat-sub">Tujuan: <strong>muchammad.ali.sanjaya@gmail.com</strong></span>
                    </div>

                    <div class="admin-stat-card">
                        <div class="stat-top">
                            <span class="stat-label">Tersimpan di Siskohat</span>
                            <span class="badge badge-gold">SISKOHAT</span>
                        </div>
                        <div class="stat-value">
                            <span id="stat-siskohat-done">0</span>
                            <span style="font-size: 14px; color: var(--text-secondary); font-weight: 700;">/ <span id="stat-siskohat-total">0</span></span>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" id="progress-siskohat" style="width: 0%;"></div>
                        </div>
                        <span class="stat-sub" id="stat-siskohat-pending">Belum Siskohat: 0 Jamaah</span>
                    </div>
                </div>

                <!-- ================================================================
                     TAB 1: DAFTAR HASIL BIOVISA (TABEL & GALERI VISUAL)
                     ================================================================ -->
                <section id="tab-records" class="tab-pane active">
                    <div class="admin-card feature-card">

                        <!-- KOP LAPORAN KHUSUS CETAK (@media print) -->
                        <div class="print-header-only">
                            <h2 style="font-size:16px; text-transform:uppercase;"></h2>
                            <h3 style="font-size:14px;">KANTOR KEMENTERIAN AGAMA KOTA / KABUPATEN PASURUAN</h3>
                            <p style="font-size:12px;">Rekapitulasi Dokumen Konfirmasi BioVisa Jamaah Haji (MOFA SaudiVisaBio &bull; JPG &le; 50KB)</p>
                            <p style="font-size:11.5px; font-weight:700; margin-top:4px;" id="print-period-label">Periode: Semua Tanggal</p>
                        </div>

                        <div class="panel-header">
                            <div>
                                <h2 class="news-title">Daftar Dokumen Biovisa Jamaah (Template Asli Email &bull; &le; 50 KB)</h2>
                                <p style="font-size: 12.5px; color: var(--text-secondary);">
                                    Filter jamaah berdasarkan tanggal perekaman Biovisa, status Google Drive, atau cari nama &amp; nomor paspor.
                                </p>
                            </div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <button class="btn btn-gold btn-sm" onclick="toggleSevenDocsManagerPanel()">
                                    📂 Kelola &amp; Upload 7 Dokumen Siskohat
                                </button>
                                <button class="btn btn-charcoal btn-sm" id="btn-sync-gdrive-7docs" onclick="syncGDriveSevenDocs()">
                                    ☁️ Tarik 7 Folder GDrive
                                </button>
                                <button class="btn btn-outline btn-sm" onclick="printOfficialReport()">
                                    Cetak Laporan
                                </button>
                                <a class="btn btn-outline btn-sm" id="btn-export-csv-filtered" href="api.php?action=export_csv">
                                    Ekspor CSV/Excel
                                </a>
                                <a class="btn btn-gold btn-sm" id="btn-download-zip-filtered" href="api.php?action=download_zip">
                                    Unduh Arsip (.ZIP)
                                </a>
                            </div>
                        </div>

                        <!-- PANEL KELOLA & UPLOAD MASSAL 7 DOKUMEN PEMVISAAN SISKOHAT -->
                        <div class="seven-docs-manager-box" id="seven-docs-manager-box" style="display:none;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
                                <div>
                                    <div style="font-weight:800; font-size:13.5px; color:var(--pure-black);">
                                        ✦ Upload Massal / Sinkronisasi 7 Dokumen Pemvisaan Siskohat Gen.2 (ENTRY DATA PASPOR DAN DOKUMEN PEMVISAAN)
                                    </div>
                                    <div style="font-size:12px; color:var(--text-secondary); margin-top:2px;">
                                        Anda dapat menaruh file <code>NomorPaspor.jpg</code> atau <code>Nama_NomorPaspor.jpg</code> di 7 folder lokal <code>c:\laragon\www\e-visa\storage\dokumen\{paspor, endorsement, vaksin, kitas, pendukung, biovisa, foto}</code>, di 7 sub-folder Google Drive (<code>1_Paspor</code> s/d <code>7_Foto</code>), atau unggah sekaligus di bawah ini. Jika salah satu dari 7 file tidak ada, Siskohat otomatis <strong>memblokir slot kosong tersebut dan lanjut ke file berikutnya</strong>.
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline btn-sm" onclick="copyTextToClipboard('c:\\laragon\\www\\e-visa\\storage\\dokumen', 'Folder 7 Dokumen Server')">
                                    📋 Salin Path Folder Lokal
                                </button>
                            </div>

                            <div class="seven-docs-upload-bar">
                                <select id="bulk-doc-category" class="form-control" style="max-width:310px; font-weight:700;">
                                    <option value="paspor">1. Scan Halaman Pertama Paspor</option>
                                    <option value="endorsement">2. Scan/Upload Halaman Endorsement</option>
                                    <option value="vaksin">3. Scan/Upload Keterangan Vaksin Meningitis *</option>
                                    <option value="kitas">4. Scan/Upload Keterangan KITAS</option>
                                    <option value="pendukung">5. Scan/Upload Dokumen Pendukung</option>
                                    <option value="biovisa">6. Scan/Upload Biovisa (jpeg)</option>
                                    <option value="foto">7. Scan/Upload Foto Terbaru</option>
                                </select>
                                <input type="file" id="bulk-doc-files" accept="image/*" multiple class="form-control" style="max-width:310px;">
                                <button type="button" class="btn btn-gold" id="btn-bulk-doc-upload" onclick="uploadBulkSevenDocs()">
                                    💻 Dari Komputer (Upload)
                                </button>
                                <button type="button" class="btn btn-charcoal" onclick="openScannerModal('', document.getElementById('bulk-doc-category').value)">
                                    🖨️ Dari Scanner (Driver Aktif)
                                </button>
                                <button type="button" class="btn btn-outline" onclick="syncGDriveSevenDocs()">
                                    ☁️ Sinkron 7 Folder GDrive
                                </button>
                            </div>
                        </div>

                        <!-- BARIS 1: FILTER TANGGAL BIOVISA (.date-filter-bar) -->
                        <div class="date-filter-bar">
                            <div class="date-filter-group">
                                <span class="date-filter-label">Filter Tanggal:</span>

                                <select id="date-basis-select" class="select-compact" onchange="onDateBasisChange()" title="Dasar Tanggal Filter">
                                    <option value="enrollment">Tgl. Biovisa (MOFA)</option>
                                    <option value="processed">Tgl. Diproses Sistem</option>
                                </select>

                                <select id="quick-date-select" class="select-compact" onchange="onQuickDateSelectChange(this.value)" title="Pilih Cepat Tanggal Biovisa yang Tersedia">
                                    <option value="">-- Semua Tanggal Tersedia --</option>
                                </select>

                                <span style="font-size:12px; color:var(--text-secondary); font-weight:600;">Rentang:</span>
                                <input type="date" id="filter-date-from" class="date-input-compact" onchange="onCustomDateInput()" title="Dari Tanggal">
                                <span style="font-size:12px; color:var(--text-secondary);">s/d</span>
                                <input type="date" id="filter-date-to" class="date-input-compact" onchange="onCustomDateInput()" title="Sampai Tanggal">
                            </div>

                            <div class="date-filter-group">
                                <button type="button" class="date-preset-btn active" id="preset-all" onclick="applyDatePreset('all', this)">Semua</button>
                                <button type="button" class="date-preset-btn" id="preset-today" onclick="applyDatePreset('today', this)">Hari Ini</button>
                                <button type="button" class="date-preset-btn" id="preset-yesterday" onclick="applyDatePreset('yesterday', this)">Kemarin</button>
                                <button type="button" class="date-preset-btn" id="preset-7days" onclick="applyDatePreset('7days', this)">7 Hari Terakhir</button>
                                <button type="button" class="date-preset-btn" id="preset-month" onclick="applyDatePreset('month', this)">Bulan Ini</button>
                            </div>
                        </div>

                        <!-- BANNER RINGKASAN FILTER TANGGAL AKTIF -->
                        <div class="date-active-banner" id="date-active-banner">
                            <div>
                                <strong id="date-active-summary-text">Filter Tanggal Aktif</strong>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <button type="button" class="btn btn-outline btn-sm" onclick="applyDatePreset('all', document.getElementById('preset-all'))">
                                    Reset Filter Tanggal
                                </button>
                            </div>
                        </div>

                        <!-- BARIS 2: FILTER STATUS, SORT, SEARCH & VIEW MODE TOOLBAR -->
                        <div class="explorer-toolbar">
                            <div class="filter-pills">
                                <button class="filter-pill active" onclick="setRecordFilter('all', this)">
                                    Semua (<span id="count-filter-all">0</span>)
                                </button>
                                <button class="filter-pill" onclick="setRecordFilter('gdrive_ok', this)">
                                    Sudah di GDrive (<span id="count-filter-ok">0</span>)
                                </button>
                                <button class="filter-pill" onclick="setRecordFilter('gdrive_pending', this)">
                                    Belum GDrive (<span id="count-filter-pending">0</span>)
                                </button>
                                <button class="filter-pill" onclick="setRecordFilter('siskohat_pending', this)">
                                    Belum Siskohat (<span id="count-filter-siskohat-pending">0</span>)
                                </button>
                            </div>

                            <div class="toolbar-right">
                                <input type="text" id="search-records" class="search-input" placeholder="🔍 Cari Nama Jamaah, No. Paspor, atau Email..." oninput="onFilterOrSearchChange()">

                                <select id="filter-source-email" class="select-compact" onchange="onFilterOrSearchChange()" title="Filter Berdasarkan Asal Sumber Email">
                                    <option value="">Semua Sumber Email</option>
                                </select>

                                <select id="sort-records" class="select-compact" onchange="onFilterOrSearchChange()" title="Urutkan Data">
                                    <option value="newest">Urut: Terbaru Diproses</option>
                                    <option value="nama_asc">Urut: Nama Jamaah (A-Z)</option>
                                    <option value="paspor_asc">Urut: Nomor Paspor (A-Z)</option>
                                    <option value="size_asc">Urut: Ukuran KB</option>
                                </select>

                                <select id="page-size-select" class="select-compact" onchange="onFilterOrSearchChange()" title="Jumlah Baris per Halaman">
                                    <option value="25">25 / hal</option>
                                    <option value="50">50 / hal</option>
                                    <option value="100">100 / hal</option>
                                    <option value="9999">Semua</option>
                                </select>

                                <div class="view-toggle-group" title="Pilih Mode Tampilan">
                                    <button class="view-toggle-btn active" id="btn-view-table" onclick="setViewMode('table')">☰ Tabel</button>
                                    <button class="view-toggle-btn" id="btn-view-grid" onclick="setViewMode('grid')">⊞ Galeri</button>
                                </div>
                            </div>
                        </div>

                        <!-- BULK ACTION BAR -->
                        <div class="bulk-action-bar" id="bulk-action-bar">
                            <div style="font-size: 13px; font-weight: 800; color: var(--charcoal);">
                                ✦ <span id="bulk-selected-count">0</span> dokumen Biovisa dipilih
                            </div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <button class="btn btn-charcoal btn-sm" onclick="bulkUploadSelectedToGDrive()">
                                    ☁️ Upload Terpilih ke GDrive
                                </button>
                                <button class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger);" onclick="bulkDeleteSelected()">
                                    🗑️ Hapus Terpilih
                                </button>
                                <button class="btn btn-outline btn-sm" onclick="clearBulkSelection()">
                                    Batal
                                </button>
                            </div>
                        </div>

                        <!-- MODE 1: TAMPILAN TABEL (.table-custom) -->
                        <div class="table-wrap" id="records-table-container">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th class="no-print" style="width: 36px; text-align:center;">
                                            <input type="checkbox" id="check-all-records" onchange="toggleSelectAllRecords(this.checked)">
                                        </th>
                                        <th style="width: 42px;">No</th>
                                        <th class="no-print" style="width: 62px;">Preview</th>
                                        <th>Jamaah &amp; No. Paspor</th>
                                        <th>File Biovisa &amp; Sumber Email</th>
                                        <th>Kelengkapan 7 Dokumen</th>
                                        <th>Status Sinkronisasi</th>
                                        <th>Waktu Biovisa</th>
                                        <th class="no-print" style="text-align: right;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="records-tbody">
                                    <tr>
                                        <td colspan="9" class="empty-state">Memuat data Biovisa...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- MODE 2: TAMPILAN GALERI KARTU (.gallery-grid) -->
                        <div class="gallery-grid" id="records-grid-container" style="display:none;"></div>

                        <!-- PAGINATION FOOTER -->
                        <div class="table-footer">
                            <div id="pagination-info">Menampilkan 0 data</div>
                            <div style="display:flex; gap:6px;" id="pagination-buttons"></div>
                        </div>
                    </div>
                </section>

                <!-- ================================================================
                     TAB 4: INTEGRASI GMAIL & GOOGLE DRIVE
                     ================================================================ -->
                <section id="tab-setup" class="tab-pane">
                    <div class="admin-card service-item-card">
                        <div class="panel-header">
                            <div>
                                <h2 class="news-title">Konfigurasi Integrasi Email &amp; Google Drive</h2>
                                <p style="font-size: 12.5px; color: var(--text-secondary);">
                                    Pengaturan penarikan email otomatis dari <strong>phujtmkopasuruan@gmail.com</strong> dan penyimpanan ke Google Drive <strong>muchammad.ali.sanjaya@gmail.com</strong>.
                                </p>
                            </div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <button class="btn btn-charcoal" id="btn-test-conn" onclick="testConnectionsNow()">
                                    🔍 Tes &amp; Diagnosa Koneksi Gmail + GDrive
                                </button>
                                <button class="btn btn-gold" onclick="saveAppSettings()">
                                    💾 Simpan Semua Pengaturan
                                </button>
                            </div>
                        </div>

                        <div id="connection-diag-box" class="verification-panel" style="display:none;"></div>

                        <div class="form-grid">
                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom: 12px;">
                                    <h3 style="font-size: 14.5px;"><span class="tracking-step">1</span> Daftar Akun Email Pembaca "Enrollment confirmation" (Multi-Email)</h3>
                                    <button type="button" class="btn btn-gold btn-sm" onclick="addNewSourceAccountCard()">
                                        ➕ Tambah Email Pembaca
                                    </button>
                                </div>

                                <div class="form-group">
                                    <label>Filter Judul / Subjek Email (Berlaku untuk Semua Akun Email Pembaca)</label>
                                    <input type="text" id="set-subject-filter" class="form-control" value="<?= htmlspecialchars($settings['email_subject_filter']) ?>">
                                    <div class="help-text">Default resmi MOFA: <code>Enrollment confirmation</code></div>
                                </div>

                                <!-- DAFTAR DINAMIS AKUN EMAIL PEMBACA -->
                                <div id="source-accounts-list" class="source-accounts-container"></div>

                                <button type="button" class="btn btn-outline" style="width:100%; border-style:dashed;" onclick="addNewSourceAccountCard()">
                                    ➕ Tambah Akun Email Pembaca Lainnya
                                </button>
                            </div>

                            <div>
                                <h3 style="font-size: 14.5px; margin-bottom: 12px;"><span class="tracking-step">2</span> Penyimpanan Google Drive &amp; Ukuran File JPG 50KB</h3>
                                <div class="form-group">
                                    <label>Akun Google Drive Tujuan</label>
                                    <input type="email" id="set-target-gdrive-email" class="form-control" value="<?= htmlspecialchars($settings['target_gdrive_email']) ?>">
                                </div>

                                <div class="form-group">
                                    <label>URL Web App Google Apps Script Penyimpan GDrive (Akun: muchammad.ali.sanjaya@gmail.com)</label>
                                    <input type="url" id="set-gas-gdrive-url" class="form-control" placeholder="https://script.google.com/macros/s/YYYY.../exec" value="<?= htmlspecialchars($settings['gas_gdrive_webapp_url']) ?>">
                                </div>

                                <div class="form-group">
                                    <label>Nama Folder di Google Drive (Otomatis Dibuat)</label>
                                    <input type="text" id="set-gdrive-folder" class="form-control" value="<?= htmlspecialchars($settings['gdrive_folder_name']) ?>">
                                </div>

                                <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <div class="form-group">
                                        <label>Batas Minimal Ukuran (KB)</label>
                                        <input type="number" step="0.5" min="20" max="48" id="set-min-kb" class="form-control" value="<?= htmlspecialchars((string)$settings['target_min_kb']) ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Batas Maksimal Ukuran (KB &le; 50)</label>
                                        <input type="number" step="0.1" min="30" max="49.8" id="set-max-kb" class="form-control" value="<?= htmlspecialchars((string)$settings['target_max_kb']) ?>">
                                    </div>
                                </div>

                                <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <div class="form-group">
                                        <label>Interval Cek Email Otomatis (Detik)</label>
                                        <input type="number" min="10" max="600" id="set-sync-interval" class="form-control" value="<?= htmlspecialchars((string)$settings['auto_sync_interval_sec']) ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Mode Konversi Email</label>
                                        <select id="set-process-mode" class="form-control">
                                            <option value="both" <?= ($settings['process_mode'] ?? 'both') === 'both' ? 'selected' : '' ?>>Keduanya (Lampiran &amp; Template Asli Email)</option>
                                            <option value="render_only" <?= ($settings['process_mode'] ?? 'both') === 'render_only' ? 'selected' : '' ?>>Template Asli Email SaudiVisaBio</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ================================================================
                     TAB 4B: KODE GOOGLE APPS SCRIPT (SUBMENU TERPISAH AGAR RAPI)
                     ================================================================ -->
                <section id="tab-gas-scripts" class="tab-pane">
                    <div class="form-grid">
                        <div class="admin-card feature-card">
                            <div class="panel-header">
                                <div>
                                    <h2 class="news-title">☁️ Script 1: Simpan Otomatis ke Google Drive</h2>
                                    <p style="font-size: 12px; color: var(--text-secondary); font-weight: 600;">Pasang di akun: muchammad.ali.sanjaya@gmail.com</p>
                                </div>
                                <button class="btn btn-gold btn-sm" onclick="copyScriptCode('code-gas-gdrive')">
                                    📋 Salin Script GDrive
                                </button>
                            </div>
                            <ol class="step-list">
                                <li>Buka <a href="https://script.google.com" target="_blank" style="color:var(--charcoal); font-weight:700;">script.google.com</a> dengan akun <strong>muchammad.ali.sanjaya@gmail.com</strong>.</li>
                                <li>Tempel (Paste) script di bawah ini, pilih fungsi <code>cekIzinAkses</code> lalu klik <strong>Run</strong> 1x untuk izin Drive.</li>
                                <li>Klik <strong>Deploy &rarr; New deployment</strong> (jenis <strong>Web app</strong>, akses <strong>Anyone / Siapa saja</strong>).</li>
                            </ol>
                            <pre class="code-box" id="code-gas-gdrive"><?= htmlspecialchars($gasGdriveCode) ?></pre>
                        </div>

                        <div class="admin-card feature-card">
                            <div class="panel-header">
                                <div>
                                    <h2 class="news-title">📩 Script 2: Pembaca Email "Enrollment confirmation"</h2>
                                    <p style="font-size: 12px; color: var(--text-secondary); font-weight: 600;">Pasang di akun: phujtmkopasuruan@gmail.com</p>
                                </div>
                                <button class="btn btn-charcoal btn-sm" onclick="copyScriptCode('code-gas-gmail')">
                                    📋 Salin Script Gmail
                                </button>
                            </div>
                            <ol class="step-list">
                                <li>Buka <a href="https://script.google.com" target="_blank" style="color:var(--charcoal); font-weight:700;">script.google.com</a> dengan akun <strong>phujtmkopasuruan@gmail.com</strong>.</li>
                                <li>Tempel (Paste) script di bawah ini, lalu klik <strong>Deploy &rarr; New deployment</strong> (jenis <strong>Web app</strong>, akses <strong>Anyone / Siapa saja</strong>).</li>
                                <li>Salin <strong>Web App URL</strong> ke kolom <em>URL Web App Pembaca Gmail</em> di submenu Akun Email &amp; Google Drive.</li>
                            </ol>
                            <pre class="code-box" id="code-gas-gmail"><?= htmlspecialchars($gasGmailCode) ?></pre>
                        </div>
                    </div>
                </section>

                <!-- ================================================================
                     TAB 5: LOG AKTIVITAS
                     ================================================================ -->
                <section id="tab-logs" class="tab-pane">
                    <div class="admin-card news-card">
                        <div class="panel-header">
                            <div>
                                <h2 class="news-title">Riwayat Aktivitas Otomatisasi</h2>
                                <p style="font-size: 12.5px; color: var(--text-secondary);">Catatan real-time penarikan email MOFA, konversi JPG &le; 50KB, dan sinkronisasi Google Drive.</p>
                            </div>
                            <button class="btn btn-outline btn-sm" onclick="clearSyncLogs()">
                                🗑️ Bersihkan Log
                            </button>
                        </div>
                        <div class="table-wrap">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th style="width: 170px;">Waktu</th>
                                        <th style="width: 110px;">Status</th>
                                        <th>Keterangan Aktivitas</th>
                                    </tr>
                                </thead>
                                <tbody id="logs-tbody">
                                    <tr>
                                        <td colspan="3" class="empty-state">Belum ada log aktivitas.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- ================================================================
                     TAB 6: AUTO-UPLOAD KE WEBSITE SISKOHAT GEN.2
                     ================================================================ -->
                <section id="tab-siskohat" class="tab-pane">
                    <div class="admin-card feature-card">
                        <div class="panel-header">
                            <div>
                                <h2 class="news-title">Otomatisasi Upload Biovisa ke Website SISKOHAT Gen.2 (10.100.88.48)</h2>
                                <p style="font-size: 12.5px; color: var(--text-secondary);">
                                    Memasukkan file <code>Nama_Nomor paspor.jpg</code> (&le; 50 KB) dari aplikasi ini langsung ke bagian <strong>Scan/Upload Biovisa (jpeg) *</strong> pada halaman <code>https://10.100.88.48/SISKOHAT/WndMenu.zul</code> secara otomatis tanpa mencari file manual di folder komputer.
                                </p>
                            </div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <button class="btn btn-gold" onclick="copyTextToClipboard('c:\\laragon\\www\\e-visa\\siskohat-extension', 'Lokasi Folder Ekstensi Siskohat')">
                                    Salin Lokasi Folder Ekstensi
                                </button>
                                <a class="btn btn-charcoal" href="api.php?action=download_siskohat_ext">
                                    Unduh Paket Ekstensi (.ZIP)
                                </a>
                            </div>
                        </div>

                        <div class="verification-panel">
                            <div style="font-weight:800; font-size:14px; color:var(--pure-black); margin-bottom:6px;">
                                Cara Kerja Otomatisasi 7 Dokumen Pemvisaan Siskohat Gen.2 (Dengan Sistem Blokir &amp; Lanjut Otomatis):
                            </div>
                            <div style="font-size:13px; color:var(--charcoal); line-height:1.65;">
                                Saat Anda membuka menu <strong>ENTRY DATA PASPOR DAN DOKUMEN PEMVISAAN</strong> di Siskohat Gen.2 (<code>https://10.100.88.48/SISKOHAT/WndMenu.zul</code>), panel <strong>✦ AUTO-BIOVISA SISKOHAT</strong> otomatis muncul dan memeriksa ke-7 dokumen jamaah secara berurutan:<br>
                                <strong>1. Scan Halaman Pertama Paspor</strong> &bull;
                                <strong>2. Scan/Upload Halaman Endorsement</strong> &bull;
                                <strong>3. Scan/Upload Keterangan Vaksin Meningitis *</strong> &bull;
                                <strong>4. Scan/Upload Keterangan KITAS</strong> &bull;
                                <strong>5. Scan/Upload Dokumen Pendukung</strong> &bull;
                                <strong>6. Scan/Upload Biovisa (jpeg)</strong> &bull;
                                <strong>7. Scan/Upload Foto Terbaru</strong>.<br>
                                ✦ <strong>Blokir &amp; Lanjut Otomatis:</strong> Jika salah satu dari ke-7 file tersebut <strong>tidak ada</strong>, maka slot upload tersebut <strong>otomatis diblokir/dikunci</strong> (tidak akan pernah terisi file yang salah) dan sistem langsung <strong>melanjutkan ke file upload berikutnya</strong> yang tersedia!
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="admin-card service-item-card" style="margin-bottom:0;">
                                <h3 style="font-size:15px; margin-bottom:12px;"><span class="tracking-step">1</span> Cara Pasang di Google Chrome (Hanya 1 Kali)</h3>
                                <ol class="step-list" style="line-height:1.75;">
                                    <li>Salin lokasi folder ekstensi ini (klik tombolnya):<br>
                                        <span class="filename-chip" style="margin:6px 0;" onclick="copyTextToClipboard('c:\\laragon\\www\\e-visa\\siskohat-extension', 'Lokasi Folder Ekstensi')">
                                            c:\laragon\www\e-visa\siskohat-extension
                                        </span>
                                    </li>
                                    <li>Buka tab baru di Google Chrome, ketik <strong><code>chrome://extensions</code></strong> di baris alamat lalu tekan <strong>Enter</strong>.</li>
                                    <li>Aktifkan sakelar <strong>Developer mode (Mode pengembang)</strong> di pojok kanan atas halaman Chrome Extensions.</li>
                                    <li>Klik tombol <strong>Load unpacked (Muat yang belum dibuka)</strong> di pojok kiri atas.</li>
                                    <li>Tempelkan alamat folder <strong><code>c:\laragon\www\e-visa\siskohat-extension</code></strong> lalu klik <strong>Select Folder (Pilih Folder)</strong>.</li>
                                    <li>Buka atau Refresh halaman <strong><code>https://10.100.88.48/SISKOHAT/WndMenu.zul</code></strong> — Panel Auto-Upload Biovisa siap digunakan!</li>
                                </ol>
                            </div>

                            <div class="admin-card service-item-card" style="margin-bottom:0;">
                                <h3 style="font-size:15px; margin-bottom:12px;"><span class="tracking-step">2</span> Rekap Status Upload ke Siskohat Gen.2</h3>
                                <p style="font-size:12.5px; color:var(--text-secondary); margin-bottom:14px;">
                                    Setiap dokumen Biovisa yang berhasil dimasukkan ke form <em>Scan/Upload Biovisa (jpeg) *</em> di Siskohat akan otomatis tercatat statusnya di tabel utama. Anda juga dapat menandai status Siskohat secara manual melalui tombol status di tabel.
                                </p>
                                <div class="verification-panel" style="margin-bottom:0;">
                                    <div style="font-size:12px; color:var(--text-secondary); font-weight:700;">LOKASI FILE EKSTENSI SIAP PAKAI:</div>
                                    <div style="font-family:Consolas,monospace; font-size:13px; font-weight:800; color:var(--pure-black); margin-top:4px;">
                                        c:\laragon\www\e-visa\siskohat-extension\manifest.json
                                    </div>
                                    <div style="font-size:12px; color:var(--success); font-weight:700; margin-top:6px;">
                                        ✦ Mendukung Bypass HTTPS Self-Signed (10.100.88.48) &rarr; Laragon Localhost
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

            </main>

            <!-- FOOTER UTAMA (.main-footer - #111827 Charcoal + Gold Top Border) -->
            <footer class="main-footer">
                <div class="footer-inner">
                    <div>
                        <span class="gold-text">✦ SILAHUT AUTO-BIOVISA PHU PASURUAN</span> &bull; Sistem Otomatisasi Dokumen Biometrik Visa Haji
                    </div>
                    <div>
                        Sumber: <span class="gold-text"><?= htmlspecialchars($settings['source_email']) ?></span> &rarr; GDrive: <span class="gold-text"><?= htmlspecialchars($settings['target_gdrive_email']) ?></span>
                    </div>
                </div>
            </footer>

        </div>
    </div>

    <!-- MODAL PREVIEW, ZOOM, NAVIGASI & EDIT NAMA_NOMOR PASPOR -->
    <div class="modal-backdrop" id="modal-preview" onclick="if(event.target===this) closeModal()">
        <div class="modal-card">
            <div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:10px; margin-bottom:14px; padding-bottom:12px; border-bottom:1px solid var(--border-color);">
                <div>
                    <h3 id="modal-title" style="font-size: 16px;">Preview &amp; Verifikasi Dokumen Biovisa</h3>
                    <div style="font-size: 11.5px; color: var(--text-secondary);">Gunakan tombol panah kiri/kanan pada keyboard untuk berpindah antar-jamaah</div>
                </div>
                <div style="display:flex; gap:6px; align-items:center;">
                    <button class="btn btn-outline btn-sm" onclick="navigateModalRecord(-1)" title="Jamaah Sebelumnya (←)">◀ Sebelumnya</button>
                    <button class="btn btn-outline btn-sm" onclick="navigateModalRecord(1)" title="Jamaah Berikutnya (→)">Berikutnya ▶</button>
                    <button class="btn btn-charcoal btn-sm" onclick="closeModal()">✕ Tutup</button>
                </div>
            </div>

            <div class="form-grid" style="grid-template-columns: 1.2fr 1fr;">
                <div style="background:var(--off-white); padding:12px; border-radius:10px; border:1px solid var(--border-color); text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:center; overflow:auto;">
                    <img id="modal-img" src="" alt="Preview Biovisa" style="max-width:100%; max-height:490px; border-radius:8px; border:1px solid #cbd5e1; transition:transform 0.2s ease; cursor:zoom-in;" onclick="toggleImageZoom(this)">
                    <div style="font-size:11px; color:var(--text-secondary); margin-top:6px;">🔍 Klik gambar untuk memperbesar (Zoom In/Out)</div>
                </div>

                <div>
                    <form id="form-edit-record" onsubmit="submitEditRecord(event)">
                        <input type="hidden" id="edit-id">
                        <div class="form-group">
                            <label>Nama File Output (Klik untuk salin)</label>
                            <div id="edit-current-filename" class="filename-chip" style="font-size: 13px; padding: 6px 10px;" onclick="copyTextToClipboard(this.textContent, 'Nama file')">-</div>
                        </div>
                        <div class="form-group">
                            <label>Asal Sumber Email Penerima</label>
                            <div id="edit-current-source-email" class="source-email-badge" style="font-size: 12px; padding: 5px 10px; margin-top:0;" onclick="copyTextToClipboard(this.dataset.email || this.textContent, 'Email sumber')">-</div>
                        </div>
                        <div class="form-group">
                            <label>Validasi Ukuran File JPG</label>
                            <div id="edit-current-size" style="font-size: 13.5px; font-weight: 800; color: var(--success);">-</div>
                        </div>
                        <div class="form-group">
                            <label>Nama Jamaah</label>
                            <input type="text" id="edit-nama" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Nomor Paspor</label>
                            <input type="text" id="edit-paspor" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>MOFA Enrollment ID</label>
                            <input type="text" id="edit-enrollment" class="form-control">
                        </div>

                        <div style="display:flex; flex-direction:column; gap:8px; margin-top: 16px;">
                            <button type="submit" class="btn btn-gold">
                                💾 Simpan Perubahan &amp; Buat Ulang JPG &le; 50KB
                            </button>
                            <a id="modal-download-btn" href="#" class="btn btn-charcoal" download>
                                ⬇️ Download File JPG
                            </a>
                            <a id="modal-gdrive-btn" href="#" target="_blank" class="btn btn-outline" style="display:none;">
                                ☁️ Buka File di Google Drive ↗
                            </a>
                            <button type="button" class="btn btn-outline" style="color:var(--danger); border-color:var(--danger);" onclick="deleteCurrentRecord()">
                                🗑️ Hapus Dokumen Ini
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- KELENGKAPAN & UPLOAD 7 DOKUMEN PEMVISAAN SISKOHAT PER JAMAAH -->
            <div style="margin-top:16px; padding-top:14px; border-top:2px solid var(--ui-gold);">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                    <div>
                        <strong style="font-size:14px; color:var(--pure-black);">✦ Kelengkapan 7 File Upload Siskohat (Pilih: 💻 Dari Komputer atau 🖨️ Dari Scanner)</strong>
                        <div style="font-size:11.5px; color:var(--text-secondary);">Setiap file yang diunggah dari Komputer maupun dipindai langsung dari Driver Scanner otomatis dikompres menjadi <strong>JPG &le; 50 KB</strong>. File yang belum ada (⊘) otomatis diblokir &amp; dilewati ke file berikutnya.</div>
                    </div>
                </div>
                <div id="modal-seven-docs-grid" class="modal-seven-docs-grid"></div>
            </div>
        </div>
    </div>

    <!-- MODAL PEMINDAI DOKUMEN (MEMBACA DRIVER SCANNER AKTIF PADA KOMPUTER) -->
    <div class="modal-backdrop" id="modal-scanner" onclick="if(event.target===this) closeScannerModal()">
        <div class="modal-card" style="max-width: 680px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:14px; padding-bottom:12px; border-bottom:1px solid var(--border-color);">
                <div>
                    <h3 style="font-size: 16px;">🖨️ Scan Dokumen Langsung dari Driver Scanner Aktif</h3>
                    <div style="font-size: 11.5px; color: var(--text-secondary);">Membaca driver scanner (WIA / TWAIN) yang aktif pada komputer ini &amp; otomatis mengompres hasil scan menjadi JPG &le; 50 KB</div>
                </div>
                <button type="button" class="btn btn-charcoal btn-sm" onclick="closeScannerModal()">✕ Tutup</button>
            </div>

            <div class="verification-panel" id="scanner-driver-status-box" style="margin-bottom:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                        <div style="font-size:11.5px; font-weight:800; color:var(--text-secondary);">STATUS DRIVER SCANNER KOMPUTER:</div>
                        <div id="scanner-driver-status-text" style="font-size:13px; font-weight:800; color:var(--pure-black); margin-top:2px;">
                            ⏳ Memeriksa driver scanner yang aktif di komputer ini...
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="refreshActiveScannerDrivers()">
                        🔄 Deteksi Ulang Driver Scanner
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label>1. Pilih Jamaah Tujuan</label>
                <select id="scanner-target-jamaah" class="form-control" style="font-weight:700;"></select>
            </div>

            <div class="form-group">
                <label>2. Kategori Dokumen Pemvisaan Siskohat (1 s/d 7)</label>
                <select id="scanner-target-category" class="form-control" style="font-weight:700;">
                    <option value="paspor">1. Scan Halaman Pertama Paspor</option>
                    <option value="endorsement">2. Scan/Upload Halaman Endorsement</option>
                    <option value="vaksin">3. Scan/Upload Keterangan Vaksin Meningitis *</option>
                    <option value="kitas">4. Scan/Upload Keterangan KITAS</option>
                    <option value="pendukung">5. Scan/Upload Dokumen Pendukung</option>
                    <option value="biovisa">6. Scan/Upload Biovisa (jpeg)</option>
                    <option value="foto">7. Scan/Upload Foto Terbaru</option>
                </select>
            </div>

            <div class="form-group">
                <label>3. Driver Scanner yang Aktif pada Komputer Ini</label>
                <select id="scanner-device-select" class="form-control" style="font-weight:700;">
                    <option value="">🖨️ Memuat daftar driver scanner aktif...</option>
                </select>
                <div class="help-text">Otomatis membaca driver WIA / TWAIN / USB Scanner yang terpasang dan menyala di komputer ini.</div>
            </div>

            <div class="form-grid" style="grid-template-columns: 1.3fr 0.85fr 0.85fr; gap: 10px;">
                <div class="form-group">
                    <label>Mode Pemindaian Driver</label>
                    <select id="scanner-mode-select" class="form-control">
                        <option value="direct">⚡ Langsung Scan Otomatis (1 Klik)</option>
                        <option value="dialog">🪟 Buka Jendela Bawaan Driver Scanner</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Resolusi (DPI)</label>
                    <select id="scanner-dpi-select" class="form-control">
                        <option value="150" selected>150 DPI (Cepat)</option>
                        <option value="200">200 DPI (Tajam)</option>
                        <option value="300">300 DPI (Detail)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Mode Warna</label>
                    <select id="scanner-color-select" class="form-control">
                        <option value="color" selected>Warna (Color)</option>
                        <option value="grayscale">Grayscale</option>
                    </select>
                </div>
            </div>

            <div style="display:flex; gap:10px; align-items:center; margin-top:6px;">
                <button type="button" class="btn btn-gold portal-action-btn" id="btn-start-hardware-scan" style="flex:1; padding:12px;" onclick="executeHardwareScanNow()">
                    🖨️ Mulai Scan dari Driver Scanner (&le; 50 KB)
                </button>
            </div>
        </div>
    </div>

    <div id="toast-container"></div>

    <script>
        let appState = {
            settings: {},
            records: [],
            jamaah: [],
            logs: [],
            stats: {}
        };

        let uiState = {
            filter: 'all',
            viewMode: 'table', // 'table' | 'grid'
            currentPage: 1,
            pageSize: 25,
            selectedIds: new Set(),
            currentModalId: null,
            filteredList: []
        };

        let countdownSec = 30;
        let timerHandle = null;
        let isSyncing = false;
        let isBatchUploading = false;

        // Jam Real-Time WIB di Top Gov Bar
        function updateLiveClock() {
            const now = new Date();
            const opts = { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false };
            document.getElementById('live-clock-wib').textContent = now.toLocaleDateString('id-ID', opts) + ' WIB';
        }
        setInterval(updateLiveClock, 1000);
        updateLiveClock();

        // Notifikasi Suara Halus (Web Audio API)
        function playNotificationSound() {
            const soundToggle = document.getElementById('toggle-sound-notif');
            if (!soundToggle || !soundToggle.checked) return;
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
                osc.frequency.setValueAtTime(880, ctx.currentTime + 0.12); // A5
                gain.gain.setValueAtTime(0.08, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.35);
            } catch (e) {}
        }

        function showToast(msg, isError = false) {
            const container = document.getElementById('toast-container');
            const el = document.createElement('div');
            el.className = 'toast' + (isError ? ' error' : '');
            el.textContent = msg;
            container.appendChild(el);
            setTimeout(() => {
                el.remove();
            }, 4500);
        }

        function copyTextToClipboard(text, label = 'Teks') {
            navigator.clipboard.writeText(String(text || '').trim()).then(() => {
                showToast(`📋 ${label} "${text}" berhasil disalin!`);
            });
        }

        function toggleMobileSidebar(open) {
            const sb = document.getElementById('admin-sidebar');
            const bd = document.getElementById('sidebar-backdrop');
            if (open) {
                sb.classList.add('open');
                bd.classList.add('open');
            } else {
                sb.classList.remove('open');
                bd.classList.remove('open');
            }
        }

        function toggleSidebarVisibility() {
            if (window.innerWidth <= 1024) {
                const sb = document.getElementById('admin-sidebar');
                toggleMobileSidebar(!sb.classList.contains('open'));
            } else {
                document.body.classList.toggle('sidebar-collapsed');
                try {
                    localStorage.setItem('silahut_sidebar_collapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
                } catch (e) {}
            }
        }

        try {
            if (window.innerWidth > 1024 && localStorage.getItem('silahut_sidebar_collapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {}

        function toggleSidebarGroup(grpId) {
            const grp = document.getElementById(grpId);
            if (grp) {
                grp.classList.toggle('open');
            }
        }

        function switchTab(tabId, btnEl) {
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.sidebar-link, .sidebar-sublink').forEach(b => b.classList.remove('active'));
            const pane = document.getElementById(tabId);
            if (pane) pane.classList.add('active');
            if (btnEl) btnEl.classList.add('active');
            toggleMobileSidebar(false);
        }

        function openRecordsSubmenu(viewMode, filterType, show7DocsPanel, btnEl) {
            switchTab('tab-records', btnEl);
            setViewMode(viewMode || 'table');
            const filterBtns = document.querySelectorAll('.filter-pill');
            let targetPill = filterBtns[0];
            if (filterType === 'siskohat_pending' && filterBtns[3]) targetPill = filterBtns[3];
            setRecordFilter(filterType || 'all', targetPill);

            const box7 = document.getElementById('seven-docs-manager-box');
            if (box7) {
                box7.style.display = show7DocsPanel ? 'block' : 'none';
                if (show7DocsPanel) {
                    setTimeout(() => box7.scrollIntoView({ behavior: 'smooth', block: 'center' }), 80);
                }
            }
        }

        function printOfficialReport() {
            switchTab('tab-records', document.getElementById('nav-tab-records'));
            setTimeout(() => window.print(), 150);
        }

        async function loadAppStatus() {
            try {
                const res = await fetch('api.php?action=status');
                const data = await res.json();
                if (!data.ok) return;

                appState = data;
                updateDashboardUI();
            } catch (e) {
                console.error(e);
            }
        }

        function updateDashboardUI() {
            const s = appState.stats || {};
            const set = appState.settings || {};
            const total = s.total_biovisa ?? 0;
            const valid50 = s.valid_50kb_count ?? 0;
            const upGdrive = s.uploaded_gdrive ?? 0;
            const pendGdrive = s.pending_gdrive ?? 0;
            const siskohatDone = (appState.records || []).filter(r => !!r.siskohat_uploaded).length;
            const siskohatPending = Math.max(0, total - siskohatDone);

            document.getElementById('stat-total').textContent = total;
            document.getElementById('nav-badge-total').textContent = total;
            const navSiskPend = document.getElementById('nav-badge-siskohat-pend');
            if (navSiskPend) navSiskPend.textContent = siskohatPending;

            document.getElementById('stat-50kb').textContent = `${valid50} / ${total}`;
            document.getElementById('stat-avg-kb').textContent = `Rata-rata ${s.avg_size_kb ?? 0} KB`;
            document.getElementById('progress-50kb').style.width = total > 0 ? `${Math.round((valid50 / total) * 100)}%` : '100%';

            document.getElementById('stat-gdrive').textContent = `${upGdrive} / ${total}`;
            document.getElementById('progress-gdrive').style.width = total > 0 ? `${Math.round((upGdrive / total) * 100)}%` : '0%';

            document.getElementById('stat-siskohat-done').textContent = siskohatDone;
            document.getElementById('stat-siskohat-total').textContent = total;
            document.getElementById('stat-siskohat-pending').textContent = `Belum Siskohat: ${siskohatPending} Jamaah`;
            document.getElementById('progress-siskohat').style.width = total > 0 ? `${Math.round((siskohatDone / total) * 100)}%` : '0%';

            // Update Filter Pill Counts
            document.getElementById('count-filter-all').textContent = total;
            document.getElementById('count-filter-ok').textContent = upGdrive;
            document.getElementById('count-filter-pending').textContent = pendGdrive;
            const siskPendEl = document.getElementById('count-filter-siskohat-pending');
            if (siskPendEl) siskPendEl.textContent = siskohatPending;

            const retryBtn = document.getElementById('btn-retry-all-gdrive');
            const heroBatchBtn = document.getElementById('btn-batch-gdrive');
            if (pendGdrive > 0) {
                retryBtn.style.display = 'inline-flex';
                retryBtn.textContent = `☁️ Upload (${pendGdrive})`;
                heroBatchBtn.style.display = 'inline-flex';
                document.getElementById('hero-pending-count').textContent = pendGdrive;
            } else {
                retryBtn.style.display = 'none';
                heroBatchBtn.style.display = 'none';
            }

            const activeAccounts = (set.source_accounts || []).filter(a => a.enabled !== false);
            if (activeAccounts.length > 1) {
                document.getElementById('header-source-email').textContent = `${activeAccounts[0].email} (+${activeAccounts.length - 1} Email)`;
                document.getElementById('header-source-email').parentElement.title = 'Email Sumber Aktif: ' + activeAccounts.map(a => a.email).join(', ');
            } else if (activeAccounts.length === 1) {
                document.getElementById('header-source-email').textContent = activeAccounts[0].email;
            } else {
                document.getElementById('header-source-email').textContent = set.source_email || 'phujtmkopasuruan@gmail.com';
            }
            document.getElementById('header-gdrive-email').textContent = set.target_gdrive_email || 'muchammad.ali.sanjaya@gmail.com';

            const lastInfo = set.last_sync_at
                ? (`Terakhir sinkron: ${set.last_sync_at} — ${set.last_sync_status || ''}`)
                : `Siap memantau email subjek "${set.email_subject_filter || 'Enrollment confirmation'}"`;
            document.getElementById('last-sync-info').textContent = lastInfo;

            if (!uiState.accountsInitialized) {
                initSourceAccountsEditor(set.source_accounts || []);
                uiState.accountsInitialized = true;
            }

            populateQuickDateDropdown();
            populateSourceEmailFilterDropdown();
            renderRecordsExplorer();
            renderLogsTable();
        }

        // ====================================================================
        // HELPER ASAL SUMBER EMAIL (ORIGIN SOURCE EMAIL LABEL)
        // ====================================================================
        function getRecordSourceEmailInfo(rec) {
            let rawEmail = String(rec.source_account_email || rec.to_email || '').trim();
            const m = rawEmail.match(/<([^>]+)>/);
            if (m && m[1]) rawEmail = m[1];
            rawEmail = rawEmail.replace(/[<>]/g, '').trim().toLowerCase();
            if (!rawEmail) {
                rawEmail = ((appState.settings && appState.settings.source_email) || 'phujtmkopasuruan@gmail.com').toLowerCase();
            }

            let label = String(rec.source_account_label || '').trim();
            if (!label && appState.settings && Array.isArray(appState.settings.source_accounts)) {
                const baseEmail = rawEmail.replace(/\+[^@]+@/, '@');
                const matched = appState.settings.source_accounts.find(a => {
                    const ae = String(a.email || '').trim().toLowerCase();
                    return ae === rawEmail || ae === baseEmail;
                });
                if (matched && matched.label) {
                    label = matched.label;
                }
            }

            return {
                email: rawEmail,
                label: label,
                display: (label && label.toLowerCase() !== rawEmail) ? `${label} • ${rawEmail}` : rawEmail
            };
        }

        function populateSourceEmailFilterDropdown() {
            const sel = document.getElementById('filter-source-email');
            if (!sel) return;

            const counts = {};
            const labels = {};
            (appState.records || []).forEach(r => {
                const info = getRecordSourceEmailInfo(r);
                counts[info.email] = (counts[info.email] || 0) + 1;
                if (info.label) labels[info.email] = info.label;
            });

            const emails = Object.keys(counts).sort();
            const curVal = sel.value;

            let html = `<option value="">Semua Sumber Email (${emails.length} Akun)</option>`;
            emails.forEach(em => {
                const lbl = labels[em] ? `${labels[em]} (${em})` : em;
                const selected = em === curVal ? 'selected' : '';
                html += `<option value="${escapeHtml(em)}" ${selected}>${escapeHtml(lbl)} — ${counts[em]} Jamaah</option>`;
            });
            sel.innerHTML = html;
        }

        // ====================================================================
        // FILTER TANGGAL (DATE FILTER) LOGIC
        // ====================================================================
        function extractRecordDateYmd(rec, basis = 'enrollment') {
            if (basis === 'processed') {
                const proc = String(rec.processed_at || '').trim();
                const m = proc.match(/^(\d{4}-\d{2}-\d{2})/);
                if (m) return m[1];
            }

            const enroll = String(rec.enrollment_time || '').trim();
            const m1 = enroll.match(/^(\d{4}-\d{2}-\d{2})/);
            if (m1) return m1[1];

            const emDate = String(rec.email_date || '').replace(/WIB/i, '').trim();
            if (emDate) {
                const d = new Date(emDate);
                if (!isNaN(d.getTime())) {
                    const y = d.getFullYear();
                    const m = String(d.getMonth() + 1).padStart(2, '0');
                    const day = String(d.getDate()).padStart(2, '0');
                    return `${y}-${m}-${day}`;
                }
            }

            const procFallback = String(rec.processed_at || '').trim();
            const m2 = procFallback.match(/^(\d{4}-\d{2}-\d{2})/);
            return m2 ? m2[1] : '';
        }

        function formatDateIndo(ymd) {
            if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return ymd || '-';
            const [y, m, d] = ymd.split('-').map(Number);
            const dt = new Date(y, m - 1, d);
            return dt.toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
        }

        function getTodayYmd(offsetDays = 0) {
            const dt = new Date();
            if (offsetDays !== 0) dt.setDate(dt.getDate() + offsetDays);
            const y = dt.getFullYear();
            const m = String(dt.getMonth() + 1).padStart(2, '0');
            const d = String(dt.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        function populateQuickDateDropdown() {
            const sel = document.getElementById('quick-date-select');
            if (!sel) return;

            const basis = (document.getElementById('date-basis-select') && document.getElementById('date-basis-select').value) || 'enrollment';
            const countsByDate = {};
            (appState.records || []).forEach(r => {
                const ymd = extractRecordDateYmd(r, basis);
                if (ymd) {
                    countsByDate[ymd] = (countsByDate[ymd] || 0) + 1;
                }
            });

            const sortedDates = Object.keys(countsByDate).sort((a, b) => b.localeCompare(a));
            const curVal = sel.value;

            let html = `<option value="">-- Semua Tanggal Tersedia (${sortedDates.length} Hari) --</option>`;
            sortedDates.forEach(ymd => {
                const count = countsByDate[ymd];
                const selected = ymd === curVal ? 'selected' : '';
                html += `<option value="${ymd}" ${selected}>${formatDateIndo(ymd)} (${count} Jamaah)</option>`;
            });
            sel.innerHTML = html;
        }

        function onDateBasisChange() {
            populateQuickDateDropdown();
            onFilterOrSearchChange();
        }

        function onQuickDateSelectChange(ymd) {
            document.querySelectorAll('.date-preset-btn').forEach(b => b.classList.remove('active'));
            const fromEl = document.getElementById('filter-date-from');
            const toEl   = document.getElementById('filter-date-to');
            if (!ymd) {
                fromEl.value = '';
                toEl.value = '';
                document.getElementById('preset-all').classList.add('active');
            } else {
                fromEl.value = ymd;
                toEl.value = ymd;
            }
            onFilterOrSearchChange();
        }

        function onCustomDateInput() {
            document.querySelectorAll('.date-preset-btn').forEach(b => b.classList.remove('active'));
            const fromVal = document.getElementById('filter-date-from').value;
            const toVal   = document.getElementById('filter-date-to').value;
            const quickSel = document.getElementById('quick-date-select');

            if (!fromVal && !toVal) {
                document.getElementById('preset-all').classList.add('active');
                if (quickSel) quickSel.value = '';
            } else if (fromVal && fromVal === toVal && quickSel) {
                quickSel.value = fromVal;
            } else if (quickSel) {
                quickSel.value = '';
            }
            onFilterOrSearchChange();
        }

        function applyDatePreset(preset, btnEl) {
            document.querySelectorAll('.date-preset-btn').forEach(b => b.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');

            const fromEl = document.getElementById('filter-date-from');
            const toEl   = document.getElementById('filter-date-to');
            const quickSel = document.getElementById('quick-date-select');
            if (quickSel) quickSel.value = '';

            if (preset === 'all') {
                fromEl.value = '';
                toEl.value = '';
            } else if (preset === 'today') {
                const today = getTodayYmd(0);
                fromEl.value = today;
                toEl.value = today;
                if (quickSel) quickSel.value = today;
            } else if (preset === 'yesterday') {
                const yest = getTodayYmd(-1);
                fromEl.value = yest;
                toEl.value = yest;
                if (quickSel) quickSel.value = yest;
            } else if (preset === '7days') {
                fromEl.value = getTodayYmd(-6);
                toEl.value = getTodayYmd(0);
            } else if (preset === 'month') {
                const now = new Date();
                const firstDay = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;
                fromEl.value = firstDay;
                toEl.value = getTodayYmd(0);
            }
            onFilterOrSearchChange();
        }

        function updateDateFilterBannerAndExportLinks(filteredCount) {
            const fromVal = (document.getElementById('filter-date-from') && document.getElementById('filter-date-from').value) || '';
            const toVal   = (document.getElementById('filter-date-to') && document.getElementById('filter-date-to').value) || '';
            const basis   = (document.getElementById('date-basis-select') && document.getElementById('date-basis-select').value) || 'enrollment';
            const q       = (document.getElementById('search-records') && document.getElementById('search-records').value || '').trim();

            const banner = document.getElementById('date-active-banner');
            const summaryText = document.getElementById('date-active-summary-text');
            const printLabel = document.getElementById('print-period-label');
            const basisLabel = basis === 'processed' ? 'Tanggal Diproses' : 'Tanggal Biovisa MOFA';

            if (fromVal || toVal) {
                let periodStr = '';
                if (fromVal && toVal && fromVal === toVal) {
                    periodStr = formatDateIndo(fromVal);
                } else {
                    periodStr = `${fromVal ? formatDateIndo(fromVal) : 'Awal'} s/d ${toVal ? formatDateIndo(toVal) : 'Sekarang'}`;
                }
                banner.classList.add('active');
                summaryText.textContent = `✦ Menampilkan ${filteredCount} Jamaah Biovisa berdasarkan ${basisLabel}: ${periodStr}`;
                if (printLabel) printLabel.textContent = `Periode (${basisLabel}): ${periodStr} (${filteredCount} Jamaah)`;
            } else {
                banner.classList.remove('active');
                if (printLabel) printLabel.textContent = `Periode: Semua Tanggal (${filteredCount} Jamaah)`;
            }

            // Update Export CSV & Download ZIP links agar mengikuti filter tanggal & sumber email yang aktif
            const srcEmailFilter = (document.getElementById('filter-source-email') && document.getElementById('filter-source-email').value) || '';
            const params = new URLSearchParams();
            if (fromVal) params.set('date_from', fromVal);
            if (toVal) params.set('date_to', toVal);
            if (basis) params.set('date_basis', basis);
            if (srcEmailFilter) params.set('source_email', srcEmailFilter);
            if (uiState.filter && uiState.filter !== 'all') params.set('gdrive_filter', uiState.filter);
            if (q) params.set('q', q);

            const qs = params.toString() ? ('&' + params.toString()) : '';
            const csvBtn = document.getElementById('btn-export-csv-filtered');
            const zipBtn = document.getElementById('btn-download-zip-filtered');
            if (csvBtn) csvBtn.href = 'api.php?action=export_csv' + qs;
            if (zipBtn) zipBtn.href = 'api.php?action=download_zip' + qs;
        }

        function setRecordFilter(filterType, btnEl) {
            uiState.filter = filterType;
            uiState.currentPage = 1;
            document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');
            renderRecordsExplorer();
        }

        function setViewMode(mode) {
            uiState.viewMode = mode;
            document.getElementById('btn-view-table').classList.toggle('active', mode === 'table');
            document.getElementById('btn-view-grid').classList.toggle('active', mode === 'grid');
            document.getElementById('records-table-container').style.display = mode === 'table' ? 'block' : 'none';
            document.getElementById('records-grid-container').style.display = mode === 'grid' ? 'grid' : 'none';
            renderRecordsExplorer();
        }

        function onFilterOrSearchChange() {
            uiState.currentPage = 1;
            uiState.pageSize = parseInt(document.getElementById('page-size-select').value || '25', 10);
            renderRecordsExplorer();
        }

        function getFilteredAndSortedRecords() {
            const q = (document.getElementById('search-records').value || '').trim().toLowerCase();
            const sortBy = document.getElementById('sort-records').value || 'newest';
            const fromVal = (document.getElementById('filter-date-from') && document.getElementById('filter-date-from').value) || '';
            const toVal   = (document.getElementById('filter-date-to') && document.getElementById('filter-date-to').value) || '';
            const basis   = (document.getElementById('date-basis-select') && document.getElementById('date-basis-select').value) || 'enrollment';
            const srcEmailFilter = (document.getElementById('filter-source-email') && document.getElementById('filter-source-email').value) || '';

            let list = (appState.records || []).filter(r => {
                if (uiState.filter === 'gdrive_ok' && !r.gdrive_uploaded) return false;
                if (uiState.filter === 'gdrive_pending' && r.gdrive_uploaded) return false;
                if (uiState.filter === 'siskohat_pending' && r.siskohat_uploaded) return false;

                const srcInfo = getRecordSourceEmailInfo(r);
                if (srcEmailFilter && srcInfo.email !== srcEmailFilter) {
                    return false;
                }

                if (fromVal || toVal) {
                    const recDate = extractRecordDateYmd(r, basis);
                    if (!recDate) return false;
                    if (fromVal && recDate < fromVal) return false;
                    if (toVal && recDate > toVal) return false;
                }

                if (!q) return true;
                return (
                    (r.nama || '').toLowerCase().includes(q) ||
                    (r.paspor || '').toLowerCase().includes(q) ||
                    (r.filename || '').toLowerCase().includes(q) ||
                    (r.enrollment_id || '').toLowerCase().includes(q) ||
                    srcInfo.email.includes(q) ||
                    srcInfo.label.toLowerCase().includes(q)
                );
            });

            list = [...list];
            if (sortBy === 'nama_asc') {
                list.sort((a, b) => (a.nama || '').localeCompare(b.nama || ''));
            } else if (sortBy === 'paspor_asc') {
                list.sort((a, b) => (a.paspor || '').localeCompare(b.paspor || ''));
            } else if (sortBy === 'size_asc') {
                list.sort((a, b) => (parseFloat(a.size_kb) || 0) - (parseFloat(b.size_kb) || 0));
            }
            return list;
        }

        function renderRecordsExplorer() {
            const filtered = getFilteredAndSortedRecords();
            uiState.filteredList = filtered;
            updateDateFilterBannerAndExportLinks(filtered.length);

            const totalItems = filtered.length;
            const pageSize = uiState.pageSize;
            const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
            if (uiState.currentPage > totalPages) uiState.currentPage = totalPages;

            const startIdx = (uiState.currentPage - 1) * pageSize;
            const pageItems = filtered.slice(startIdx, startIdx + pageSize);

            renderTableRows(pageItems, startIdx);
            renderGalleryCards(pageItems);
            renderPagination(totalItems, startIdx, pageItems.length, totalPages);
            updateBulkActionBar();
        }

        function renderTableRows(items, startIdx) {
            const tbody = document.getElementById('records-tbody');
            if (items.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10" class="empty-state">
                            <h3>Tidak Ada Dokumen Biovisa yang Cocok</h3>
                            <p>Ubah filter pencarian atau klik tombol <strong>"🔄 Cek Email Masuk Sekarang"</strong> di bagian atas.</p>
                        </td>
                    </tr>`;
                return;
            }

            tbody.innerHTML = items.map((r, idx) => {
                const rowNum = startIdx + idx + 1;
                const isChecked = uiState.selectedIds.has(r.id) ? 'checked' : '';
                const imgUrl = 'api.php?action=download_file&inline=1&file=' + encodeURIComponent(r.filename) + '&t=' + encodeURIComponent(r.processed_at || '');
                const dlUrl  = 'api.php?action=download_file&file=' + encodeURIComponent(r.filename);
                const sizeKb = parseFloat(r.size_kb || 0);
                const sizeBadge = sizeKb <= 50
                    ? `<span class="badge badge-gold">✦ ${sizeKb} KB</span>`
                    : `<span class="badge badge-warning">⚠️ ${sizeKb} KB</span>`;

                let gdriveHtml = '';
                if (r.gdrive_uploaded) {
                    gdriveHtml = r.gdrive_url
                        ? `<a href="${escapeHtml(r.gdrive_url)}" target="_blank" class="badge badge-charcoal" style="text-decoration:none;">☁️ Tersimpan di GDrive ↗</a>`
                        : `<span class="badge badge-charcoal">☁️ Tersimpan di GDrive</span>`;
                } else {
                    gdriveHtml = `
                        <button class="btn btn-outline btn-sm" onclick="retryGDriveUpload('${escapeHtml(r.id)}')" title="${escapeHtml(r.gdrive_error || 'Klik untuk upload ke Google Drive')}">
                            ☁️ Upload GDrive
                        </button>`;
                }

                const siskohatHtml = r.siskohat_uploaded
                    ? `<div style="margin-top:4px;"><span class="badge badge-success" style="cursor:pointer;" onclick="toggleSiskohatStatus('${escapeHtml(r.id)}', false)" title="Klik untuk reset status Siskohat">✓ Sudah di Siskohat</span></div>`
                    : `<div style="margin-top:4px;"><span class="badge badge-outline" style="cursor:pointer; border:1px dashed #94A3B8; color:#475569; background:#F8FAFC;" onclick="toggleSiskohatStatus('${escapeHtml(r.id)}', true)" title="Klik untuk menandai sudah diupload ke Siskohat">Belum Siskohat</span></div>`;

                const srcInfo = getRecordSourceEmailInfo(r);
                const srcLabelBadge = srcInfo.label
                    ? `<div><span class="source-email-badge" onclick="copyTextToClipboard('${escapeHtml(srcInfo.email)}', 'Asal Email')" title="Klik untuk salin alamat email sumber">${escapeHtml(srcInfo.label)} &bull; <strong>${escapeHtml(srcInfo.email)}</strong></span></div>`
                    : `<div><span class="source-email-badge" onclick="copyTextToClipboard('${escapeHtml(srcInfo.email)}', 'Asal Email')" title="Klik untuk salin alamat email sumber">Sumber: <strong>${escapeHtml(srcInfo.email)}</strong></span></div>`;

                const sevenBadgesHtml = renderSevenDocsInlineBadges(r);

                return `
                    <tr>
                        <td class="no-print" style="text-align:center;">
                            <input type="checkbox" ${isChecked} onchange="toggleSelectRecord('${escapeHtml(r.id)}', this.checked)">
                        </td>
                        <td style="font-weight:600; color:var(--text-secondary);">${rowNum}</td>
                        <td class="no-print">
                            <div class="thumb-box" onclick="openPreviewModal('${escapeHtml(r.id)}')" title="Klik untuk memperbesar & kelola 7 dokumen">
                                <img src="${imgUrl}" alt="${escapeHtml(r.filename)}" loading="lazy" decoding="async">
                            </div>
                        </td>
                        <td>
                            <div style="font-weight:700; color:var(--text-main); font-size:13px;">${escapeHtml(r.nama)}</div>
                            <div style="font-size:11.8px; color:var(--text-secondary); margin-top:2px;">
                                Paspor: <strong class="copy-pill" style="color:var(--charcoal);" onclick="copyTextToClipboard('${escapeHtml(r.paspor)}', 'Nomor Paspor')" title="Klik untuk salin Nomor Paspor">${escapeHtml(r.paspor)}</strong>
                                &bull; ID: <span class="copy-pill" onclick="copyTextToClipboard('${escapeHtml(r.enrollment_id || '')}', 'Enrollment ID')" title="Klik untuk salin MOFA Enrollment ID">${escapeHtml(r.enrollment_id || '-')}</span>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                <span class="filename-chip" onclick="copyTextToClipboard('${escapeHtml(r.filename)}', 'Nama file')" title="${escapeHtml(r.filename)}">
                                    ${escapeHtml(r.filename)}
                                </span>
                                ${sizeBadge}
                            </div>
                            ${srcLabelBadge}
                        </td>
                        <td>${sevenBadgesHtml}</td>
                        <td>${gdriveHtml}${siskohatHtml}</td>
                        <td style="font-size:11.5px; color:var(--text-secondary); white-space:nowrap;">${escapeHtml(r.enrollment_time || r.processed_at || '')}</td>
                        <td class="no-print" style="text-align:right; white-space:nowrap;">
                            <button class="btn btn-outline btn-sm" onclick="openPreviewModal('${escapeHtml(r.id)}')">
                                Kelola 7 File
                            </button>
                            <a href="${dlUrl}" download="${escapeHtml(r.filename)}" class="btn btn-gold btn-sm">
                                Unduh
                            </a>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function renderGalleryCards(items) {
            const grid = document.getElementById('records-grid-container');
            if (items.length === 0) {
                grid.innerHTML = `<div class="empty-state" style="grid-column: 1 / -1;"><h3>Tidak Ada Dokumen Biovisa</h3></div>`;
                return;
            }

            grid.innerHTML = items.map(r => {
                const imgUrl = 'api.php?action=download_file&inline=1&file=' + encodeURIComponent(r.filename) + '&t=' + encodeURIComponent(r.processed_at || '');
                const dlUrl  = 'api.php?action=download_file&file=' + encodeURIComponent(r.filename);
                const srcInfo = getRecordSourceEmailInfo(r);

                return `
                    <div class="biovisa-gallery-card">
                        <div class="gallery-img-wrap" onclick="openPreviewModal('${escapeHtml(r.id)}')">
                            <img src="${imgUrl}" alt="${escapeHtml(r.filename)}" loading="lazy">
                        </div>
                        <div class="gallery-card-body">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:6px;">
                                <span class="badge badge-gold">✦ ${escapeHtml(r.size_kb)} KB</span>
                                ${r.gdrive_uploaded
                                    ? `<span class="badge badge-charcoal">☁️ GDrive OK</span>`
                                    : `<button class="btn btn-outline btn-sm" style="padding:2px 7px; font-size:11px;" onclick="retryGDriveUpload('${escapeHtml(r.id)}')">☁️ Upload</button>`}
                            </div>
                            <div style="font-weight:800; font-size:13.5px; color:var(--pure-black); margin-top:2px;">${escapeHtml(r.nama)}</div>
                            <div style="font-family:Consolas,monospace; font-size:12px; color:#856404; font-weight:700;">
                                Paspor: ${escapeHtml(r.paspor)} &bull; ID: ${escapeHtml(r.enrollment_id || '-')}
                            </div>
                            <div class="filename-chip" style="font-size:11px; width:100%; justify-content:center;" onclick="copyTextToClipboard('${escapeHtml(r.filename)}', 'Nama file')">
                                ${escapeHtml(r.filename)}
                            </div>
                            <div class="source-email-badge" style="font-size:10.5px; width:100%; justify-content:center;" onclick="copyTextToClipboard('${escapeHtml(srcInfo.email)}', 'Asal Email')">
                                Asal: <strong>${escapeHtml(srcInfo.email)}</strong>
                            </div>
                            <div class="gallery-card-actions">
                                <button class="btn btn-outline btn-sm" style="flex:1;" onclick="openPreviewModal('${escapeHtml(r.id)}')">✏️ Edit</button>
                                <a href="${dlUrl}" download="${escapeHtml(r.filename)}" class="btn btn-gold btn-sm" style="flex:1;">⬇️ JPG</a>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderPagination(totalItems, startIdx, currentCount, totalPages) {
            const info = document.getElementById('pagination-info');
            const btns = document.getElementById('pagination-buttons');

            if (totalItems === 0) {
                info.textContent = 'Menampilkan 0 data';
                btns.innerHTML = '';
                return;
            }

            info.textContent = `Menampilkan ${startIdx + 1} – ${startIdx + currentCount} dari total ${totalItems} dokumen Biovisa`;
            if (totalPages <= 1) {
                btns.innerHTML = '';
                return;
            }

            let html = '';
            for (let p = 1; p <= totalPages; p++) {
                const active = p === uiState.currentPage ? 'btn-charcoal' : 'btn-outline';
                html += `<button class="btn ${active} btn-sm" onclick="goToPage(${p})">${p}</button>`;
            }
            btns.innerHTML = html;
        }

        function goToPage(p) {
            uiState.currentPage = p;
            renderRecordsExplorer();
        }

        // Bulk Selection
        function toggleSelectRecord(id, checked) {
            if (checked) uiState.selectedIds.add(id);
            else uiState.selectedIds.delete(id);
            updateBulkActionBar();
        }

        function toggleSelectAllRecords(checked) {
            uiState.filteredList.forEach(r => {
                if (checked) uiState.selectedIds.add(r.id);
                else uiState.selectedIds.delete(r.id);
            });
            renderRecordsExplorer();
        }

        function clearBulkSelection() {
            uiState.selectedIds.clear();
            const checkAll = document.getElementById('check-all-records');
            if (checkAll) checkAll.checked = false;
            renderRecordsExplorer();
        }

        function updateBulkActionBar() {
            const bar = document.getElementById('bulk-action-bar');
            const count = uiState.selectedIds.size;
            document.getElementById('bulk-selected-count').textContent = count;
            bar.classList.toggle('active', count > 0);
        }

        async function bulkDeleteSelected() {
            const ids = Array.from(uiState.selectedIds);
            if (ids.length === 0) return;
            if (!confirm(`Hapus ${ids.length} dokumen Biovisa terpilih dari daftar lokal?`)) return;

            const res = await fetch('api.php?action=delete_record', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids })
            });
            const data = await res.json();
            clearBulkSelection();
            await loadAppStatus();
            showToast(data.message || 'Data terpilih dihapus.');
        }

        async function bulkUploadSelectedToGDrive() {
            const ids = Array.from(uiState.selectedIds);
            if (ids.length === 0) return;
            await runBatchGDriveUploadByIds(ids);
            clearBulkSelection();
        }

        // Batch Upload ke Google Drive Satu per Satu dengan Progress Bar Real-Time
        async function batchSyncPendingGDrive() {
            const pending = (appState.records || []).filter(r => !r.gdrive_uploaded).map(r => r.id);
            if (pending.length === 0) {
                showToast('Seluruh file sudah tersimpan di Google Drive!');
                return;
            }
            await runBatchGDriveUploadByIds(pending);
        }

        async function runBatchGDriveUploadByIds(ids) {
            if (isBatchUploading) return;
            isBatchUploading = true;

            const banner = document.getElementById('batch-progress-banner');
            const bar = document.getElementById('batch-progress-bar');
            const label = document.getElementById('batch-progress-Label');
            const title = document.getElementById('batch-progress-title');

            banner.classList.add('active');
            let successCount = 0;

            for (let i = 0; i < ids.length; i++) {
                const id = ids[i];
                const rec = (appState.records || []).find(r => r.id === id);
                const nameLabel = rec ? rec.filename : id;
                title.textContent = `☁️ Mengunggah ke Google Drive: ${nameLabel}...`;
                label.textContent = `${i + 1} / ${ids.length}`;
                bar.style.width = `${Math.round(((i + 1) / ids.length) * 100)}%`;

                try {
                    const res = await fetch('api.php?action=retry_gdrive', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id })
                    });
                    const data = await res.json();
                    if (data.ok) {
                        successCount++;
                    } else {
                        showToast(data.error || `Gagal upload ${nameLabel}`, true);
                        break;
                    }
                } catch (e) {
                    showToast(`Kesalahan jaringan saat mengunggah ${nameLabel}`, true);
                    break;
                }
            }

            isBatchUploading = false;
            await loadAppStatus();
            setTimeout(() => {
                banner.classList.remove('active');
                bar.style.width = '0%';
            }, 1200);

            if (successCount > 0) {
                playNotificationSound();
                showToast(`✅ Berhasil mengunggah ${successCount} file Biovisa ke Google Drive!`);
            }
        }

        function renderJamaahMasterTable() {
            const tbody = document.getElementById('jamaah-master-tbody');
            const list = appState.jamaah || [];
            if (list.length === 0) {
                tbody.innerHTML = `<tr><td colspan="3" class="empty-state">Belum ada daftar Master Jamaah.</td></tr>`;
                return;
            }
            tbody.innerHTML = list.map(j => `
                <tr>
                    <td style="font-weight:700;">${escapeHtml(j.nama)}</td>
                    <td><code>${escapeHtml(j.paspor)}</code></td>
                    <td>
                        ${j.sudah_biovisa
                            ? `<span class="badge badge-gold">✦ Sudah (${escapeHtml(j.filename)})</span>`
                            : `<span class="badge badge-warning">⏳ Menunggu Email</span>`}
                    </td>
                </tr>
            `).join('');
        }

        function renderLogsTable() {
            const tbody = document.getElementById('logs-tbody');
            const list = appState.logs || [];
            if (list.length === 0) {
                tbody.innerHTML = `<tr><td colspan="3" class="empty-state">Belum ada log aktivitas.</td></tr>`;
                return;
            }
            tbody.innerHTML = list.map(l => {
                let badgeClass = 'badge-charcoal';
                if (l.type === 'success') badgeClass = 'badge-gold';
                if (l.type === 'warning') badgeClass = 'badge-warning';
                if (l.type === 'error') badgeClass = 'badge-danger';
                return `
                    <tr>
                        <td style="font-size:12px; color:var(--text-secondary);">${escapeHtml(l.time)}</td>
                        <td><span class="badge ${badgeClass}">${escapeHtml((l.type || 'info').toUpperCase())}</span></td>
                        <td>${escapeHtml(l.message)}</td>
                    </tr>
                `;
            }).join('');
        }

        function triggerBrowserDownload(filename) {
            if (!document.getElementById('toggle-auto-download').checked) return;
            const a = document.createElement('a');
            a.href = 'api.php?action=download_file&file=' + encodeURIComponent(filename);
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
        }

        async function syncEmailsNow(isAutoBackground = false) {
            if (isSyncing || isBatchUploading) return;
            isSyncing = true;
            const btn = document.getElementById('btn-sync-now');
            const origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳ Mengecek Email...';

            try {
                const res = await fetch('api.php?action=sync_emails', { method: 'POST' });
                const data = await res.json();
                await loadAppStatus();

                if (!data.ok) {
                    if (!isAutoBackground) {
                        showToast(data.error || 'Gagal mengecek email.', true);
                    }
                } else {
                    if ((data.new_count || 0) > 0) {
                        playNotificationSound();
                        showToast(`🎉 ${data.new_count} Email Biovisa baru berhasil dikonversi ke JPG <= 50KB!`);
                        (data.new_records || []).forEach(rec => {
                            if (rec.filename) triggerBrowserDownload(rec.filename);
                        });
                    } else if (!isAutoBackground) {
                        showToast(data.message || 'Tidak ada email biovisa baru.');
                    }
                }
            } catch (err) {
                if (!isAutoBackground) {
                    showToast('Terjadi kesalahan jaringan saat sinkronisasi.', true);
                }
            } finally {
                isSyncing = false;
                btn.disabled = false;
                btn.innerHTML = origText;
                resetCountdown();
            }
        }

        async function simulateIncomingEmail() {
            const btn = document.getElementById('btn-simulate');
            btn.disabled = true;
            try {
                const res = await fetch('api.php?action=simulate_email', { method: 'POST' });
                const data = await res.json();
                await loadAppStatus();
                if (data.ok && data.record) {
                    playNotificationSound();
                    showToast(data.message);
                    triggerBrowserDownload(data.record.filename);
                } else {
                    showToast(data.error || 'Gagal membuat simulasi.', true);
                }
            } catch (e) {
                showToast('Gagal menjalankan simulasi.', true);
            } finally {
                btn.disabled = false;
            }
        }

        // Drag & Drop File Support
        const dropzone = document.getElementById('manual-dropzone');
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(ev => {
                dropzone.addEventListener(ev, e => {
                    e.preventDefault();
                    dropzone.classList.add('dragover');
                });
            });
            ['dragleave', 'drop'].forEach(ev => {
                dropzone.addEventListener(ev, e => {
                    e.preventDefault();
                    dropzone.classList.remove('dragover');
                });
            });
            dropzone.addEventListener('drop', e => {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                    document.getElementById('manual-file').files = e.dataTransfer.files;
                    updateDropzoneLabel();
                }
            });
        }

        function updateDropzoneLabel() {
            const fi = document.getElementById('manual-file');
            const lbl = document.getElementById('dropzone-label');
            if (fi.files && fi.files[0]) {
                lbl.textContent = `✅ File terpilih: ${fi.files[0].name}`;
            } else {
                lbl.textContent = 'Klik atau tarik file gambar (JPG/PNG/WebP) ke area ini';
            }
        }

        function autoExtractFromPaste() {
            const text = (document.getElementById('manual-subject').value + "\n" + document.getElementById('manual-body').value);

            const pasporMatch = text.match(/(?:Passport\s*(?:Number|No\.?|#)|No(?:mor)?\.?\s*Paspor|Paspor)\s*[:\-–=]?\s*([A-Z0-9]{6,12})/i)
                || text.match(/\b([A-Z]{1,3}\d{6,8})\b/);
            if (pasporMatch && pasporMatch[1]) {
                document.getElementById('manual-paspor').value = pasporMatch[1].toUpperCase();
            }

            const enrollMatch = text.match(/(?:Enrollment\s*(?:ID|Number|No\.?)|Reference\s*(?:ID|No\.?))(?:\s+is)?\s*[:\-–=#]?\s*([A-Z0-9\-]{5,20})/i);
            if (enrollMatch && enrollMatch[1]) {
                document.getElementById('manual-enrollment').value = enrollMatch[1].toUpperCase();
            }

            const nameMatch = text.match(/Dear\s+([^\r\n,،<]{2,55}?)\s*[,،\r\n<]/i)
                || text.match(/(?:Applicant\s*Name|Full\s*Name|Pilgrim\s*Name|Nama\s*Jamaah|Nama\s*Lengkap|Name|Nama)\s*[:\-–=]\s*([^\n\r]{2,55})/i);

            if (nameMatch && nameMatch[1]) {
                const candidate = nameMatch[1].replace(/[،,]/g, '').trim();
                if (!/^(applicant|customer|sir|madam|user)$/i.test(candidate)) {
                    document.getElementById('manual-nama').value = candidate.toUpperCase();
                }
            }

            const curPaspor = document.getElementById('manual-paspor').value.trim().toUpperCase();
            if (curPaspor && !document.getElementById('manual-nama').value.trim()) {
                const found = (appState.jamaah || []).find(j => (j.paspor || '').toUpperCase() === curPaspor);
                if (found && found.nama) {
                    document.getElementById('manual-nama').value = found.nama.toUpperCase();
                }
            }

            updatePreviewFilename();
        }

        function updatePreviewFilename() {
            const nama = (document.getElementById('manual-nama').value || 'NAMA JAMAAH').trim().toUpperCase();
            const paspor = (document.getElementById('manual-paspor').value || 'NOMORPASPOR').trim().toUpperCase();
            document.getElementById('manual-filename-preview').textContent = `${nama}_${paspor}.jpg`;
        }

        async function submitManualCreate(e) {
            e.preventDefault();
            const btn = document.getElementById('btn-submit-manual');
            btn.disabled = true;
            btn.textContent = '⏳ Memproses JPG <= 50KB...';

            try {
                const fd = new FormData();
                fd.append('action', 'manual_create');
                fd.append('subject', document.getElementById('manual-subject').value);
                fd.append('body_text', document.getElementById('manual-body').value);
                fd.append('nama', document.getElementById('manual-nama').value);
                fd.append('paspor', document.getElementById('manual-paspor').value);
                fd.append('enrollment_id', document.getElementById('manual-enrollment').value);

                const fileInput = document.getElementById('manual-file');
                if (fileInput.files && fileInput.files[0]) {
                    fd.append('attachment', fileInput.files[0]);
                }

                const res = await fetch('api.php?action=manual_create', {
                    method: 'POST',
                    body: fd
                });
                const data = await res.json();
                await loadAppStatus();

                if (data.ok && data.record) {
                    playNotificationSound();
                    showToast(data.message);
                    triggerBrowserDownload(data.record.filename);
                    document.getElementById('manual-body').value = '';
                    document.getElementById('manual-nama').value = '';
                    document.getElementById('manual-paspor').value = '';
                    document.getElementById('manual-enrollment').value = '';
                    fileInput.value = '';
                    updateDropzoneLabel();
                    updatePreviewFilename();
                    switchTab('tab-records', document.getElementById('nav-tab-records'));
                } else {
                    showToast(data.error || 'Gagal membuat file Biovisa.', true);
                }
            } catch (err) {
                showToast('Gagal mengirim data.', true);
            } finally {
                btn.disabled = false;
                btn.textContent = '⚡ Proses JPG <= 50KB, Download & Simpan ke GDrive';
            }
        }

        // Modal Preview, Zoom & Keyboard Navigation
        function openPreviewModal(id) {
            const rec = (appState.records || []).find(r => r.id === id);
            if (!rec) return;

            uiState.currentModalId = id;
            const srcInfo = getRecordSourceEmailInfo(rec);
            document.getElementById('edit-id').value = rec.id;
            document.getElementById('edit-current-filename').textContent = rec.filename;
            const srcEl = document.getElementById('edit-current-source-email');
            if (srcEl) {
                srcEl.textContent = srcInfo.display;
                srcEl.dataset.email = srcInfo.email;
            }
            document.getElementById('edit-current-size').textContent = `✦ ${rec.size_kb} KB (${rec.size_bytes} bytes) — Valid Standar Siskohat <= 50 KB`;
            document.getElementById('edit-nama').value = rec.nama || '';
            document.getElementById('edit-paspor').value = rec.paspor || '';
            document.getElementById('edit-enrollment').value = rec.enrollment_id || '';

            const imgEl = document.getElementById('modal-img');
            imgEl.style.transform = 'scale(1)';
            imgEl.dataset.zoomed = '0';
            imgEl.src = 'api.php?action=download_file&inline=1&file=' + encodeURIComponent(rec.filename) + '&t=' + Date.now();

            const dlBtn = document.getElementById('modal-download-btn');
            dlBtn.href = 'api.php?action=download_file&file=' + encodeURIComponent(rec.filename);
            dlBtn.setAttribute('download', rec.filename);

            const gdBtn = document.getElementById('modal-gdrive-btn');
            if (rec.gdrive_uploaded && rec.gdrive_url) {
                gdBtn.style.display = 'inline-flex';
                gdBtn.href = rec.gdrive_url;
            } else {
                gdBtn.style.display = 'none';
            }

            renderModalSevenDocsGrid(rec);
            document.getElementById('modal-preview').classList.add('open');
        }

        const SEVEN_DOC_DEFS = [
            { num: 1, key: 'paspor', short: '1.Paspor', label: '1. Scan Halaman Pertama Paspor' },
            { num: 2, key: 'endorsement', short: '2.Endorse', label: '2. Scan/Upload Halaman Endorsement' },
            { num: 3, key: 'vaksin', short: '3.Vaksin', label: '3. Scan/Upload Keterangan Vaksin Meningitis *' },
            { num: 4, key: 'kitas', short: '4.KITAS', label: '4. Scan/Upload Keterangan KITAS' },
            { num: 5, key: 'pendukung', short: '5.Pendukung', label: '5. Scan/Upload Dokumen Pendukung' },
            { num: 6, key: 'biovisa', short: '6.Biovisa', label: '6. Scan/Upload Biovisa (jpeg)' },
            { num: 7, key: 'foto', short: '7.Foto', label: '7. Scan/Upload Foto Terbaru' }
        ];

        function getRecSevenDocsMap(rec) {
            const raw = (rec && rec.seven_docs && rec.seven_docs.docs) ? rec.seven_docs.docs : {};
            const map = {};
            SEVEN_DOC_DEFS.forEach(d => {
                if (raw[d.key]) {
                    map[d.key] = raw[d.key];
                } else if (d.key === 'biovisa' && rec && rec.filename) {
                    map[d.key] = {
                        exists: true,
                        num: 6,
                        key: 'biovisa',
                        short: 'Biovisa',
                        label: d.label,
                        filename: rec.filename,
                        size_kb: rec.size_kb || 48
                    };
                } else {
                    map[d.key] = { exists: false, num: d.num, key: d.key, short: d.short, label: d.label, filename: '', size_kb: 0 };
                }
            });
            return map;
        }

        function renderSevenDocsInlineBadges(rec) {
            const map = getRecSevenDocsMap(rec);
            let availCount = 0;
            const dots = SEVEN_DOC_DEFS.map(d => {
                const info = map[d.key];
                if (info && info.exists) {
                    availCount++;
                    return `<span class="doc7-dot ready" title="✓ ${escapeHtml(d.label)}: ${escapeHtml(info.filename)} (${escapeHtml(info.size_kb)} KB)">${d.num}</span>`;
                }
                return `<span class="doc7-dot blocked" title="⊘ ${escapeHtml(d.label)}: Belum ada file (Otomatis diblokir & lanjut ke dokumen berikutnya)">${d.num}</span>`;
            }).join('');
            return `
                <div class="doc7-compact-cell" onclick="openPreviewModal('${escapeHtml(rec.id)}')" title="Klik untuk mengelola 7 Dokumen Pemvisaan Siskohat">
                    <span class="doc7-summary-pill">${availCount} / 7 Dokumen Siap</span>
                    <div class="doc7-dots-bar">${dots}</div>
                </div>
            `;
        }

        function renderModalSevenDocsGrid(rec) {
            const container = document.getElementById('modal-seven-docs-grid');
            if (!container || !rec) return;
            const map = getRecSevenDocsMap(rec);

            container.innerHTML = SEVEN_DOC_DEFS.map(d => {
                const info = map[d.key] || { exists: false };
                const isReady = !!info.exists;
                const previewUrl = isReady
                    ? (typeof window.__resolveBiovisaImgUrl === 'function'
                        ? window.__resolveBiovisaImgUrl(info.filename, false, d.key)
                        : `api.php?action=download_file&cat=${encodeURIComponent(d.key)}&inline=1&file=${encodeURIComponent(info.filename)}&t=${Date.now()}`)
                    : '';
                return `
                    <div class="modal-doc7-card ${isReady ? 'ready' : 'blocked'}">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:6px;">
                            <strong style="font-size:12px; color:var(--pure-black);">${escapeHtml(d.label)}</strong>
                            <span class="badge ${isReady ? 'badge-gold' : 'badge-outline'}" style="font-size:10.5px;">
                                ${isReady ? ('✓ Siap (' + escapeHtml(info.size_kb) + ' KB)') : '⊘ Kosong (Diblokir & Lewati)'}
                            </span>
                        </div>
                        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; font-family:Consolas,monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            ${isReady ? escapeHtml(info.filename) : 'Belum ada file — Pilih Dari Komputer atau Scanner'}
                        </div>
                        <div style="display:flex; gap:5px; align-items:center; flex-wrap:wrap;">
                            <label class="btn btn-gold btn-sm" style="cursor:pointer; margin:0;" title="Pilih file gambar dari folder komputer (Otomatis dikompres <= 50 KB)">
                                💻 Dari Komputer
                                <input type="file" accept="image/*" style="display:none;" onchange="uploadSingleJamaahDoc('${escapeHtml(rec.id)}', '${escapeHtml(d.key)}', this)">
                            </label>
                            <button type="button" class="btn btn-charcoal btn-sm" onclick="openScannerModal('${escapeHtml(rec.id)}', '${escapeHtml(d.key)}')" title="Pindai langsung dari Driver Scanner yang aktif di komputer ini (Otomatis <= 50 KB)">
                                🖨️ Dari Scanner
                            </button>
                            ${isReady ? `
                                <button type="button" class="btn btn-outline btn-sm" onclick="previewDocInModal('${escapeHtml(previewUrl)}', '${escapeHtml(d.label)}')">
                                    👁️ Lihat
                                </button>
                                ${d.key !== 'biovisa' ? `
                                    <button type="button" class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger);" onclick="deleteSingleJamaahDoc('${escapeHtml(rec.id)}', '${escapeHtml(d.key)}')">
                                        🗑️
                                    </button>
                                ` : ''}
                            ` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function previewDocInModal(url, label) {
            const imgEl = document.getElementById('modal-img');
            if (imgEl && url) {
                imgEl.src = url;
                showToast(`👁️ Menampilkan preview: ${label}`);
            }
        }

        function toggleSevenDocsManagerPanel() {
            const box = document.getElementById('seven-docs-manager-box');
            if (!box) return;
            box.style.display = box.style.display === 'none' ? 'block' : 'none';
        }

        async function uploadSingleJamaahDoc(recId, catKey, fileInput) {
            if (!fileInput.files || !fileInput.files[0]) return;
            const fd = new FormData();
            fd.append('action', 'upload_jamaah_doc');
            fd.append('id', recId);
            fd.append('category', catKey);
            fd.append('file', fileInput.files[0]);

            showToast('⏳ Mengunggah & menyesuaikan dokumen...');
            try {
                const res = await fetch('api.php?action=upload_jamaah_doc', { method: 'POST', body: fd });
                const data = await res.json();
                await loadAppStatus();
                if (data.ok) {
                    showToast(data.message || 'Dokumen berhasil disimpan!');
                    openPreviewModal(recId);
                } else {
                    showToast(data.error || 'Gagal mengunggah dokumen.', true);
                }
            } catch (e) {
                showToast('Gagal mengunggah dokumen.', true);
            }
        }

        async function deleteSingleJamaahDoc(recId, catKey) {
            if (!confirm('Hapus file dokumen kategori ini untuk jamaah tersebut?')) return;
            const res = await fetch('api.php?action=delete_jamaah_doc', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: recId, category: catKey })
            });
            const data = await res.json();
            await loadAppStatus();
            if (data.ok) {
                showToast(data.message);
                openPreviewModal(recId);
            } else {
                showToast(data.error || 'Gagal menghapus dokumen.', true);
            }
        }

        async function uploadBulkSevenDocs() {
            const cat = document.getElementById('bulk-doc-category').value;
            const fi  = document.getElementById('bulk-doc-files');
            if (!fi.files || fi.files.length === 0) {
                showToast('Pilih 1 atau beberapa file gambar (NomorPaspor.jpg / Nama_NomorPaspor.jpg) terlebih dahulu.', true);
                return;
            }
            const btn = document.getElementById('btn-bulk-doc-upload');
            const origText = btn.textContent;
            btn.disabled = true;
            btn.textContent = `⏳ Mengunggah ${fi.files.length} File...`;

            try {
                const fd = new FormData();
                fd.append('action', 'upload_jamaah_doc');
                fd.append('category', cat);
                for (let i = 0; i < fi.files.length; i++) {
                    fd.append('files[]', fi.files[i]);
                }
                const res = await fetch('api.php?action=upload_jamaah_doc', { method: 'POST', body: fd });
                const data = await res.json();
                await loadAppStatus();
                if (data.ok) {
                    playNotificationSound();
                    showToast(data.message);
                    fi.value = '';
                } else {
                    showToast(data.error || 'Gagal mengunggah file.', true);
                }
            } catch (e) {
                showToast('Terjadi kesalahan saat mengunggah file.', true);
            } finally {
                btn.disabled = false;
                btn.textContent = origText;
            }
        }

        async function syncGDriveSevenDocs() {
            const btn = document.getElementById('btn-sync-gdrive-7docs');
            if (btn) btn.disabled = true;
            showToast('☁️ Memeriksa & menarik 7 Sub-Folder Dokumen dari Google Drive...');
            try {
                const res = await fetch('api.php?action=sync_gdrive_7docs', { method: 'POST' });
                const data = await res.json();
                await loadAppStatus();
                if (data.ok) {
                    if ((data.pulled_count || 0) > 0) playNotificationSound();
                    showToast(data.message);
                } else {
                    showToast(data.error || 'Gagal menarik 7 folder Google Drive.', true);
                }
            } catch (e) {
                showToast('Kesalahan jaringan saat menghubungi Google Drive.', true);
            } finally {
                if (btn) btn.disabled = false;
            }
        }

        // ====================================================================
        // FITUR PEMINDAI (SCANNER) LANGSUNG DARI DRIVER SCANNER AKTIF KOMPUTER
        // ====================================================================
        function openScannerModal(recId = '', catKey = 'paspor') {
            const jamaahSel = document.getElementById('scanner-target-jamaah');
            const catSel    = document.getElementById('scanner-target-category');
            const recs      = appState.records || [];

            jamaahSel.innerHTML = recs.map(r => {
                const sel = (r.id === recId) ? 'selected' : '';
                return `<option value="${escapeHtml(r.id)}" ${sel}>${escapeHtml(r.nama)} — Paspor: ${escapeHtml(r.paspor)}</option>`;
            }).join('');

            if (catKey && catSel) {
                catSel.value = catKey;
            }

            document.getElementById('modal-scanner').classList.add('open');
            refreshActiveScannerDrivers();
        }

        function closeScannerModal() {
            document.getElementById('modal-scanner').classList.remove('open');
        }

        async function refreshActiveScannerDrivers() {
            const statusText = document.getElementById('scanner-driver-status-text');
            const devSelect  = document.getElementById('scanner-device-select');
            statusText.textContent = '⏳ Membaca daftar driver scanner (WIA / TWAIN) yang aktif di komputer ini...';
            statusText.style.color = 'var(--pure-black)';
            devSelect.innerHTML = `<option value="">⏳ Memindai driver scanner aktif...</option>`;

            try {
                const res = await fetch('api.php?action=list_scanners&_t=' + Date.now());
                const data = await res.json();
                const devices = (data && Array.isArray(data.devices)) ? data.devices : [];

                if (devices.length > 0) {
                    statusText.textContent = `✅ Ditemukan ${devices.length} Driver Scanner Aktif pada komputer ini`;
                    statusText.style.color = 'var(--success)';
                    let opts = '';
                    devices.forEach((d, idx) => {
                        const mfg = d.manufacturer ? ` (${d.manufacturer})` : '';
                        opts += `<option value="${escapeHtml(d.id || '')}" ${idx === 0 ? 'selected' : ''}>🖨️ [Driver Aktif] ${escapeHtml(d.name)}${escapeHtml(mfg)} — ${escapeHtml(d.driver_type || 'WIA/TWAIN')}</option>`;
                    });
                    opts += `<option value="__DIALOG__">🪟 Dialog Pemilih Driver Scanner Bawaan Windows (WIA / TWAIN)</option>`;
                    devSelect.innerHTML = opts;
                } else {
                    statusText.textContent = '⚠️ Tidak ada scanner yang terdeteksi menyala otomatis — Gunakan Dialog Driver Scanner Windows atau pastikan kabel USB scanner menyala (ON)';
                    statusText.style.color = 'var(--warning)';
                    devSelect.innerHTML = `<option value="__DIALOG__" selected>🪟 Gunakan Driver Scanner Default Windows (Dialog WIA / TWAIN Aktif)</option>`;
                }
            } catch (e) {
                statusText.textContent = '⚠️ Pastikan layanan lokal aktif untuk mengakses driver hardware scanner Windows.';
                statusText.style.color = 'var(--danger)';
                devSelect.innerHTML = `<option value="__DIALOG__" selected>🪟 Driver Scanner Default Windows (WIA / TWAIN)</option>`;
            }
        }

        async function executeHardwareScanNow() {
            const recId      = document.getElementById('scanner-target-jamaah').value;
            const catKey     = document.getElementById('scanner-target-category').value;
            const devId      = document.getElementById('scanner-device-select').value;
            const scanMode   = document.getElementById('scanner-mode-select').value;
            const dpi        = parseInt(document.getElementById('scanner-dpi-select').value || '150', 10);
            const colorMode  = document.getElementById('scanner-color-select').value || 'color';
            const btn        = document.getElementById('btn-start-hardware-scan');

            const rec = (appState.records || []).find(r => r.id === recId);
            const useDialog = (scanMode === 'dialog' || devId === '__DIALOG__');

            const origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳ Membaca dari Driver Scanner & Mengompres <= 50 KB...';
            showToast('🖨️ Menghubungi driver scanner aktif pada komputer... Pastikan dokumen sudah diletakkan di kaca/ADF scanner.');

            try {
                const res = await fetch('api.php?action=scan_jamaah_doc', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: recId,
                        nama: rec ? rec.nama : 'JAMAAH',
                        paspor: rec ? rec.paspor : '',
                        category: catKey,
                        device_id: devId === '__DIALOG__' ? '' : devId,
                        use_dialog: useDialog,
                        dpi: dpi,
                        color_mode: colorMode
                    })
                });
                const data = await res.json();
                await loadAppStatus();

                if (data && data.ok) {
                    playNotificationSound();
                    showToast(data.message || `✅ Berhasil scan dokumen (${data.size_kb} KB <= 50 KB)!`);
                    closeScannerModal();
                    if (recId) {
                        openPreviewModal(recId);
                    }
                } else {
                    showToast((data && data.error) || 'Gagal memindai dari driver scanner.', true);
                }
            } catch (err) {
                showToast('Gagal berkomunikasi dengan driver scanner: ' + err.message, true);
            } finally {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        }

        function toggleImageZoom(imgEl) {
            if (imgEl.dataset.zoomed === '1') {
                imgEl.style.transform = 'scale(1)';
                imgEl.style.cursor = 'zoom-in';
                imgEl.dataset.zoomed = '0';
            } else {
                imgEl.style.transform = 'scale(1.45)';
                imgEl.style.cursor = 'zoom-out';
                imgEl.dataset.zoomed = '1';
            }
        }

        function navigateModalRecord(dir) {
            const list = uiState.filteredList.length > 0 ? uiState.filteredList : (appState.records || []);
            if (list.length === 0) return;
            const idx = list.findIndex(r => r.id === uiState.currentModalId);
            if (idx === -1) return;
            const nextIdx = (idx + dir + list.length) % list.length;
            openPreviewModal(list[nextIdx].id);
        }

        window.addEventListener('keydown', e => {
            const modalOpen = document.getElementById('modal-preview').classList.contains('open');
            if (!modalOpen) return;
            if (e.key === 'Escape') closeModal();
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            if (e.key === 'ArrowLeft') navigateModalRecord(-1);
            if (e.key === 'ArrowRight') navigateModalRecord(1);
        });

        function closeModal() {
            document.getElementById('modal-preview').classList.remove('open');
        }

        async function submitEditRecord(e) {
            e.preventDefault();
            const payload = {
                id: document.getElementById('edit-id').value,
                nama: document.getElementById('edit-nama').value,
                paspor: document.getElementById('edit-paspor').value,
                enrollment_id: document.getElementById('edit-enrollment').value
            };

            const res = await fetch('api.php?action=update_record', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            await loadAppStatus();

            if (data.ok && data.record) {
                showToast(data.message);
                openPreviewModal(data.record.id);
            } else {
                showToast(data.error || 'Gagal memperbarui data.', true);
            }
        }

        async function deleteCurrentRecord() {
            const id = document.getElementById('edit-id').value;
            if (!id || !confirm('Hapus dokumen Biovisa ini dari daftar lokal?')) return;

            const res = await fetch('api.php?action=delete_record', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const data = await res.json();
            closeModal();
            await loadAppStatus();
            showToast(data.message || 'Data dihapus.');
        }

        async function retryGDriveUpload(id = '') {
            if (!id) {
                await batchSyncPendingGDrive();
                return;
            }
            await runBatchGDriveUploadByIds([id]);
        }

        async function importJamaahMaster(replaceAll = false) {
            const rawText = document.getElementById('jamaah-import-text').value.trim();
            if (!rawText) {
                showToast('Silakan tempel daftar Nama & Nomor Paspor terlebih dahulu.', true);
                return;
            }
            const res = await fetch('api.php?action=import_jamaah', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ raw_text: rawText, replace_all: replaceAll })
            });
            const data = await res.json();
            await loadAppStatus();
            if (data.ok) {
                showToast(data.message);
                document.getElementById('jamaah-import-text').value = '';
            } else {
                showToast(data.error || 'Gagal mengimpor data jamaah.', true);
            }
        }

        // ====================================================================
        // MULTI-EMAIL READER ACCOUNTS EDITOR (Pembaca Email Enrollment confirmation)
        // ====================================================================
        let sourceAccountsDraft = [];

        function initSourceAccountsEditor(accounts) {
            if (!Array.isArray(accounts) || accounts.length === 0) {
                const set = appState.settings || {};
                sourceAccountsDraft = [
                    {
                        id: 'acc_primary',
                        label: 'Email Utama PHU Pasuruan',
                        email: set.source_email || 'phujtmkopasuruan@gmail.com',
                        mode: set.connection_mode || 'gas',
                        gas_url: set.gas_gmail_webapp_url || '',
                        imap_password: set.imap_app_password || '',
                        enabled: true
                    }
                ];
            } else {
                sourceAccountsDraft = JSON.parse(JSON.stringify(accounts));
            }
            renderSourceAccountsList();
        }

        function renderSourceAccountsList() {
            const container = document.getElementById('source-accounts-list');
            if (!container) return;

            container.innerHTML = sourceAccountsDraft.map((acc, idx) => {
                const isEnabled = acc.enabled !== false;
                const mode = acc.mode || 'gas';
                return `
                    <div class="source-account-card ${isEnabled ? '' : 'disabled-acc'}">
                        <div class="source-account-header">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span class="tracking-step">${idx + 1}</span>
                                <strong style="font-size:13.5px; color:var(--pure-black);">
                                    ${escapeHtml(acc.label || ('Akun Email Pembaca #' + (idx + 1)))}
                                </strong>
                                <span class="badge ${isEnabled ? 'badge-gold' : 'badge-warning'}">
                                    ${isEnabled ? '✦ Aktif' : 'Nonaktif'}
                                </span>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <label style="display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; cursor:pointer;">
                                    <input type="checkbox" ${isEnabled ? 'checked' : ''} onchange="updateSourceAccountField(${idx}, 'enabled', this.checked)" style="accent-color:var(--charcoal); width:15px; height:15px;">
                                    <span>Aktifkan</span>
                                </label>
                                ${sourceAccountsDraft.length > 1 ? `
                                    <button type="button" class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger); padding:3px 8px;" onclick="removeSourceAccountCard(${idx})" title="Hapus Akun Ini">
                                        🗑️ Hapus
                                    </button>
                                ` : ''}
                            </div>
                        </div>

                        <div class="form-grid" style="grid-template-columns: 1fr 1.2fr; gap: 10px;">
                            <div class="form-group" style="margin-bottom:8px;">
                                <label>Label / Nama Akun</label>
                                <input type="text" class="form-control" placeholder="Contoh: Email PHU 1 / KBIHU" value="${escapeHtml(acc.label || '')}" oninput="updateSourceAccountField(${idx}, 'label', this.value, false)">
                            </div>
                            <div class="form-group" style="margin-bottom:8px;">
                                <label>Alamat Email Gmail Penerima Biovisa</label>
                                <input type="email" class="form-control" placeholder="contoh@gmail.com" value="${escapeHtml(acc.email || '')}" oninput="updateSourceAccountField(${idx}, 'email', this.value, false)">
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:8px;">
                            <label>Metode Koneksi Pembaca Email</label>
                            <select class="form-control" onchange="updateSourceAccountField(${idx}, 'mode', this.value, true)">
                                <option value="gas" ${mode === 'gas' ? 'selected' : ''}>Google Apps Script Bridge (Rekomendasi - Tanpa App Password)</option>
                                <option value="imap" ${mode === 'imap' ? 'selected' : ''}>Koneksi Langsung IMAP Gmail (16-Digit App Password)</option>
                            </select>
                        </div>

                        ${mode === 'gas' ? `
                            <div class="form-group" style="margin-bottom:2px;">
                                <label>URL Web App Google Apps Script Pembaca Gmail (${escapeHtml(acc.email || ('Akun #' + (idx + 1)))})</label>
                                <input type="url" class="form-control" placeholder="https://script.google.com/macros/s/XXXX.../exec" value="${escapeHtml(acc.gas_url || '')}" oninput="updateSourceAccountField(${idx}, 'gas_url', this.value, false)">
                                <div class="help-text">Pasang Script 2 di bawah pada akun <strong>${escapeHtml(acc.email || 'Gmail ini')}</strong>, klik Deploy &rarr; Web App, lalu tempel URL-nya di sini.</div>
                            </div>
                        ` : `
                            <div class="form-group" style="margin-bottom:2px;">
                                <label>Gmail App Password (16 Karakter untuk ${escapeHtml(acc.email || ('Akun #' + (idx + 1)))})</label>
                                <input type="password" class="form-control" placeholder="xxxx xxxx xxxx xxxx" value="${escapeHtml(acc.imap_password || '')}" oninput="updateSourceAccountField(${idx}, 'imap_password', this.value, false)">
                            </div>
                        `}
                    </div>
                `;
            }).join('');
        }

        function updateSourceAccountField(idx, field, value, shouldRerender = true) {
            if (!sourceAccountsDraft[idx]) return;
            sourceAccountsDraft[idx][field] = value;
            if (shouldRerender) {
                renderSourceAccountsList();
            }
        }

        function addNewSourceAccountCard() {
            const nextNum = sourceAccountsDraft.length + 1;
            sourceAccountsDraft.push({
                id: 'acc_' + Date.now() + '_' + nextNum,
                label: 'Email Pembaca PHU #' + nextNum,
                email: '',
                mode: 'gas',
                gas_url: '',
                imap_password: '',
                enabled: true
            });
            renderSourceAccountsList();
            showToast(`➕ Form Akun Email Pembaca #${nextNum} ditambahkan. Silakan isi alamat email & URL Web App, lalu klik Simpan.`);
        }

        function removeSourceAccountCard(idx) {
            if (sourceAccountsDraft.length <= 1) {
                showToast('Minimal harus ada 1 akun email pembaca.', true);
                return;
            }
            const acc = sourceAccountsDraft[idx];
            if (!confirm(`Hapus akun email pembaca "${acc.email || acc.label || ('#' + (idx + 1))}" dari daftar?`)) return;
            sourceAccountsDraft.splice(idx, 1);
            renderSourceAccountsList();
        }

        async function toggleQuickSetting() {
            const autoSync = document.getElementById('toggle-auto-sync').checked;
            const autoDl   = document.getElementById('toggle-auto-download').checked;
            await fetch('api.php?action=save_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    auto_sync_enabled: autoSync,
                    auto_download_browser: autoDl
                })
            });
            resetCountdown();
        }

        async function saveAppSettings() {
            const cleanedAccounts = sourceAccountsDraft.map((a, i) => ({
                id: a.id || ('acc_' + (i + 1)),
                label: (a.label || ('Email Pembaca #' + (i + 1))).trim(),
                email: (a.email || '').trim(),
                mode: a.mode || 'gas',
                gas_url: (a.gas_url || '').trim(),
                imap_password: (a.imap_password || '').trim(),
                enabled: a.enabled !== false
            }));

            const firstActive = cleanedAccounts.find(a => a.enabled) || cleanedAccounts[0] || {};

            const payload = {
                source_accounts: cleanedAccounts,
                source_email: firstActive.email || 'phujtmkopasuruan@gmail.com',
                connection_mode: firstActive.mode || 'gas',
                gas_gmail_webapp_url: firstActive.gas_url || '',
                imap_app_password: firstActive.imap_password || '',
                target_gdrive_email: document.getElementById('set-target-gdrive-email').value.trim(),
                email_subject_filter: document.getElementById('set-subject-filter').value.trim(),
                gas_gdrive_webapp_url: document.getElementById('set-gas-gdrive-url').value.trim(),
                gdrive_folder_name: document.getElementById('set-gdrive-folder').value.trim(),
                target_min_kb: parseFloat(document.getElementById('set-min-kb').value || '42'),
                target_max_kb: parseFloat(document.getElementById('set-max-kb').value || '49.5'),
                auto_sync_interval_sec: parseInt(document.getElementById('set-sync-interval').value || '30', 10),
                process_mode: document.getElementById('set-process-mode').value,
                auto_sync_enabled: document.getElementById('toggle-auto-sync').checked,
                auto_download_browser: document.getElementById('toggle-auto-download').checked
            };

            const res = await fetch('api.php?action=save_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            await loadAppStatus();
            if (data.ok) {
                initSourceAccountsEditor((data.settings && data.settings.source_accounts) || cleanedAccounts);
                showToast(`✅ Pengaturan disimpan (${cleanedAccounts.length} Akun Email Pembaca)!`);
                resetCountdown();
            } else {
                showToast('Gagal menyimpan pengaturan.', true);
            }
        }

        function copyScriptCode(elementId) {
            const text = document.getElementById(elementId).innerText;
            navigator.clipboard.writeText(text).then(() => {
                showToast('📋 Kode Google Apps Script All-in-One berhasil disalin ke Clipboard!');
            });
        }

        async function testConnectionsNow() {
            const btn = document.getElementById('btn-test-conn');
            const box = document.getElementById('connection-diag-box');
            btn.disabled = true;
            btn.textContent = '⏳ Menguji Koneksi...';
            box.style.display = 'block';
            box.innerHTML = '⏳ Sedang menguji koneksi ke seluruh akun Web App Gmail dan Web App Google Drive...';

            try {
                await saveAppSettings();
                const res = await fetch('api.php?action=test_connection', { method: 'POST' });
                const data = await res.json();
                await loadAppStatus();

                if (data.ok && data.diagnosis) {
                    const gmList = data.diagnosis.gmail_accounts || [];
                    const gm = data.diagnosis.gmail || {};
                    const gd = data.diagnosis.gdrive || {};

                    const gmAccountsHtml = gmList.length > 0
                        ? gmList.map((item, i) => `
                            <div style="margin-bottom:6px; padding:9px 12px; border-radius:8px; background:${!item.enabled ? '#f1f5f9' : (item.ok ? '#dcfce7' : '#fee2e2')}; color:${!item.enabled ? '#475569' : (item.ok ? '#15803d' : '#b91c1c')}; font-size:13px;">
                                <strong>${!item.enabled ? '⏸️' : (item.ok ? '✅' : '❌')} EMAIL #${i + 1} (${escapeHtml(item.email || item.label || '')}):</strong> ${escapeHtml(item.message || '')}
                            </div>
                        `).join('')
                        : `<div style="margin-bottom:8px; padding:10px 12px; border-radius:8px; background:${gm.ok ? '#dcfce7' : '#fee2e2'}; color:${gm.ok ? '#15803d' : '#b91c1c'}; font-size:13px;">
                            <strong>${gm.ok ? '✅ GMAIL OK:' : '❌ GMAIL:'}</strong> ${escapeHtml(gm.message || '')}
                           </div>`;

                    box.innerHTML = `
                        <div style="font-weight:800; margin-bottom:8px; font-size:14px; color:var(--charcoal);">Hasil Diagnosa Koneksi Multi-Email &amp; Google Drive:</div>
                        ${gmAccountsHtml}
                        <div style="padding:10px 12px; border-radius:8px; background:${gd.ok ? '#dcfce7' : '#fee2e2'}; color:${gd.ok ? '#15803d' : '#b91c1c'}; font-size:13px;">
                            <strong>${gd.ok ? '✅ GOOGLE DRIVE OK:' : '❌ GOOGLE DRIVE:'}</strong> ${escapeHtml(gd.message || '')}
                            ${gd.folder_url ? ` — <a href="${escapeHtml(gd.folder_url)}" target="_blank" style="font-weight:700; color:#15803d;">Buka Folder GDrive ↗</a>` : ''}
                        </div>
                    `;
                }
            } catch (e) {
                box.innerHTML = '<span style="color:var(--danger);">Gagal menjalankan tes koneksi.</span>';
            } finally {
                btn.disabled = false;
                btn.textContent = '🔍 Tes & Diagnosa Koneksi Gmail + GDrive';
            }
        }

        async function clearSyncLogs() {
            await fetch('api.php?action=clear_logs', { method: 'POST' });
            await loadAppStatus();
            showToast('🗑️ Log aktivitas berhasil dibersihkan.');
        }

        async function toggleSiskohatStatus(id, status) {
            const res = await fetch('api.php?action=mark_siskohat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, status })
            });
            const data = await res.json();
            await loadAppStatus();
            if (data.ok) {
                showToast(data.message);
            }
        }

        function resetCountdown() {
            const interval = parseInt((appState.settings && appState.settings.auto_sync_interval_sec) || 30, 10);
            countdownSec = isNaN(interval) || interval < 10 ? 30 : interval;
        }

        function startAutoSyncTimer() {
            if (timerHandle) clearInterval(timerHandle);
            timerHandle = setInterval(() => {
                const enabled = document.getElementById('toggle-auto-sync').checked;
                const dot = document.getElementById('sync-dot');
                const txt = document.getElementById('sync-countdown-text');

                if (!enabled) {
                    dot.classList.add('paused');
                    txt.textContent = 'Auto-Sync Dijeda';
                    return;
                }

                dot.classList.remove('paused');
                if (isSyncing) {
                    txt.textContent = 'Mengecek email...';
                    return;
                }

                countdownSec--;
                txt.textContent = `Cek otomatis: ${countdownSec} dtk`;
                if (countdownSec <= 0) {
                    resetCountdown();
                    syncEmailsNow(true);
                }
            }, 1000);
        }

        function escapeHtml(str) {
            return String(str ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        window.addEventListener('DOMContentLoaded', async () => {
            await loadAppStatus();
            resetCountdown();
            startAutoSyncTimer();
        });
    </script>
</body>
</html>
