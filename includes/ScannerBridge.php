<?php
/**
 * ScannerBridge
 * Membaca driver scanner yang aktif pada komputer Windows (WIA / TWAIN / WMI Imaging Devices)
 * dan melakukan pemindaian (scanning) langsung dari perangkat scanner ke file JPG <= 50 KB.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/BiovisaProcessor.php';

class ScannerBridge
{
    /**
     * Deteksi seluruh driver scanner yang aktif pada komputer Windows ini
     */
    public static function listActiveScanners(): array
    {
        $psScriptPath = getTempDir() . DIRECTORY_SEPARATOR . 'list_scanners.ps1';
        $psCode = <<<'PS1'
$ErrorActionPreference = 'SilentlyContinue'
$devices = @()

function Add-ScannerDevice($id, $name, $mfg, $dtype, $status, $src) {
    if ([string]::IsNullOrWhiteSpace($name)) { return }
    foreach ($ex in $script:devices) {
        if ($ex.name -eq $name) { return }
    }
    $script:devices += @{
        id = [string]$id
        name = [string]$name
        manufacturer = [string]$mfg
        driver_type = [string]$dtype
        status = [string]$status
        source = [string]$src
    }
}

# 1. Baca dari WIA.DeviceManager (Driver Scanner WIA Aktif)
try {
    $dm = New-Object -ComObject WIA.DeviceManager
    foreach ($d in $dm.DeviceInfos) {
        $name = ''
        $mfg  = ''
        try { $name = [string]$d.Properties('Name').Value } catch {}
        try { $mfg  = [string]$d.Properties('Manufacturer').Value } catch {}
        if ($name -ne '') {
            Add-ScannerDevice ([string]$d.DeviceID) $name $mfg 'WIA Hardware Scanner' 'Aktif (Siap Scan)' 'wia'
        }
    }
} catch {}

# 2. Baca dari Windows Registry StillImage / Imaging Class ({6bdd1fc6-810f-11d0-bec7-08002be2092f})
#    Mendeteksi driver scanner resmi seperti FUJITSU SP-1120N, EPSON L3250, EPSON L3210, EPSON L565, Canon, Brother, HP
try {
    $regPath = 'HKLM:\SYSTEM\CurrentControlSet\Control\Class\{6bdd1fc6-810f-11d0-bec7-08002be2092f}'
    if (Test-Path $regPath) {
        Get-ChildItem $regPath -ErrorAction SilentlyContinue | ForEach-Object {
            $p = Get-ItemProperty $_.PSPath -ErrorAction SilentlyContinue
            if ($p -and ($p.FriendlyName -or $p.DriverDesc)) {
                $fName = if ($p.FriendlyName) { [string]$p.FriendlyName } else { [string]$p.DriverDesc }
                $dDesc = [string]$p.DriverDesc
                $mfg   = if ($p.ProviderName) { [string]$p.ProviderName } elseif ($p.Manufacturer) { [string]$p.Manufacturer } else { 'Windows Imaging' }
                $display = if ($fName -and $dDesc -and ($fName -ne $dDesc) -and ($dDesc -notlike '*WSD*')) { "$fName ($dDesc)" } else { $fName }
                $devId = if ($p.MatchingDeviceId) { [string]$p.MatchingDeviceId } else { "REG_IMG_" + $_.PSChildName }
                Add-ScannerDevice $devId $display $mfg 'WIA / TWAIN Hardware Scanner Driver' 'Terdaftar & Siap' 'registry_imaging'
            }
        }
    }
} catch {}

# 3. Baca dari C:\Windows\twain_32 (Driver TWAIN Data Source *.ds seperti Epson Scan 2, Fujitsu PaperStream IP)
try {
    if (Test-Path 'C:\Windows\twain_32') {
        Get-ChildItem 'C:\Windows\twain_32' -Recurse -Filter '*.ds' -ErrorAction SilentlyContinue | ForEach-Object {
            if ($_.Name -eq 'wiatwain.ds' -or $_.Name -like '*DiskImport*' -or $_.Name -like '*Reprocess*') { return }
            $vi = $_.VersionInfo
            $pName = if ($vi.ProductName) { [string]$vi.ProductName.Trim() } else { '' }
            $fDesc = if ($vi.FileDescription) { [string]$vi.FileDescription.Trim() } else { '' }
            $cName = if ($vi.CompanyName) { [string]$vi.CompanyName.Trim() } else { 'TWAIN Driver' }
            $label = if ($fDesc -and $pName -and ($fDesc -ne $pName)) { "$pName - $fDesc" } elseif ($pName) { "$pName TWAIN Scanner" } elseif ($fDesc) { $fDesc } else { $_.BaseName }
            if ($label -ne '' -and $label -notlike '*Operating System*') {
                Add-ScannerDevice ("TWAIN_" + $_.BaseName) $label $cName 'TWAIN Data Source Driver' 'Aktif' 'twain_ds'
            }
        }
    }
} catch {}

# 4. Baca juga dari WMI / PnP Windows Class 'Image' & 'Scanner'
try {
    $pnpList = Get-CimInstance -ClassName Win32_PnPEntity -Filter "Status='OK' AND (PNPClass='Image' OR Service='StillCam' OR Name LIKE '%Scan%' OR Name LIKE '%WIA%' OR Name LIKE '%TWAIN%' OR Name LIKE '%EPSON%' OR Name LIKE '%FUJITSU%')"
    foreach ($p in $pnpList) {
        $pName = [string]$p.Name
        if ($pName -ne '') {
            Add-ScannerDevice ([string]$p.DeviceID) $pName ([string]$p.Manufacturer) 'Windows Imaging / TWAIN Driver' 'Aktif (Terhubung)' 'pnp'
        }
    }
} catch {}

$out = @{
    ok = $true
    count = $devices.Count
    devices = $devices
}
ConvertTo-Json -InputObject $out -Depth 4 -Compress
PS1;

        file_put_contents($psScriptPath, $psCode);
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Sta -File ' . escapeshellarg($psScriptPath) . ' 2>&1';
        $rawOut = @shell_exec($cmd);

        $decoded = is_string($rawOut) ? json_decode(trim($rawOut), true) : null;
        $devices = (is_array($decoded) && isset($decoded['devices']) && is_array($decoded['devices']))
            ? $decoded['devices']
            : [];

        // Selalu sediakan opsi Dialog Driver Scanner Default Windows (WIA CommonDialog)
        $finalDevices = [];
        foreach ($devices as $dev) {
            if (!empty($dev['name'])) {
                $finalDevices[] = $dev;
            }
        }

        return [
            'ok' => true,
            'count' => count($finalDevices),
            'devices' => $finalDevices,
            'has_hardware_scanner' => count($finalDevices) > 0
        ];
    }

    /**
     * Lakukan pemindaian (Scan) dari driver scanner aktif yang dipilih pada komputer
     * lalu kembalikan path file sementara atau bytes gambar hasil scan
     */
    public static function scanFromActiveDriver(array $options = []): array
    {
        $deviceId   = trim((string)($options['device_id'] ?? ''));
        $useDialog  = !empty($options['use_dialog']);
        $dpi        = intval($options['dpi'] ?? 150);
        if ($dpi < 75 || $dpi > 600) {
            $dpi = 150;
        }
        $colorMode  = strtolower(trim((string)($options['color_mode'] ?? 'color'))); // 'color' | 'grayscale'
        $colorType  = ($colorMode === 'grayscale') ? 2 : 1; // WIA_INTENT_IMAGE_TYPE_*

        // Mode foto (bukan full halaman A4): WIA intent MaximizeQuality + bias foto
        $scanProfile = strtolower(trim((string)($options['scan_profile'] ?? 'photo')));
        $usePhotoMode = ($scanProfile !== 'document' && $scanProfile !== 'fullpage');
        // WIA_IPS_CUR_INTENT flags
        $wiaMinimizeSize      = 65536;   // 0x10000 — dokumen penuh
        $wiaMaximizeQuality   = 131072;  // 0x20000 — mode foto / objek di kaca
        $wiaBias              = $usePhotoMode ? $wiaMaximizeQuality : $wiaMinimizeSize;
        $wiaItemIntent        = $colorType + ($usePhotoMode ? $wiaMaximizeQuality : $wiaMinimizeSize);

        $tempOutFile = TEMP_DIR . DIRECTORY_SEPARATOR . 'scan_' . uniqid() . '.jpg';
        $psScriptPath = TEMP_DIR . DIRECTORY_SEPARATOR . 'exec_scan.ps1';

        $escapedOut = str_replace("'", "''", $tempOutFile);
        $escapedDev = str_replace("'", "''", $deviceId);
        $dialogFlag = $useDialog ? '$true' : '$false';
        $photoFlag  = $usePhotoMode ? '$true' : '$false';

        $psCode = <<<PS1
\$ErrorActionPreference = 'Stop'
\$outPath = '$escapedOut'
\$targetId = '$escapedDev'
\$useDialog = $dialogFlag
\$photoMode = $photoFlag
\$dpi = $dpi
\$colorType = $colorType
\$wiaBias = $wiaBias
\$wiaItemIntent = $wiaItemIntent
\$wiaFormatJPEG = '{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}'

function Set-WiaPhotoScanProperties {
    param(\$item)
    if (\$null -eq \$item) { return }
    # WIA_IPS_CUR_INTENT, resolusi, dan opsi yang umum untuk mode foto / auto-detect
    try { \$item.Properties('6146').Value = \$wiaItemIntent } catch {}
    try { \$item.Properties('6147').Value = \$dpi } catch {}
    try { \$item.Properties('6148').Value = \$dpi } catch {}
    if (-not \$photoMode) { return }
    # Ukuran halaman otomatis (driver yang mendukung)
    try { \$item.Properties('3097').Value = 0 } catch {}
    # Document handling: flatbed / scan objek tunggal
    try { \$item.Properties('3088').Value = 1 } catch {}
    foreach (\$propId in @('4103','4104','6144','6145','3096','3264')) {
        try {
            \$p = \$item.Properties(\$propId)
            if (\$null -ne \$p -and \$p.IsReadOnly -eq \$false) {
                \$name = [string]\$p.Name
                if (\$name -match 'Auto|Crop|Detect|Picture|Photo|Area') {
                    # Aktifkan nilai "on" / 1 jika tersedia di driver
                    if (\$p.SubType -eq 2) { \$p.Value = 1 } else { \$p.Value = \$true }
                }
            }
        } catch {}
    }
}

try {
    \$imgFile = \$null
    \$usedScannerName = ''

    function Try-ConnectAndTransferWia(\$dmObj, \$preferId) {
        \$candidates = @()
        foreach (\$d in \$dmObj.DeviceInfos) {
            \$dId = [string]\$d.DeviceID
            \$dName = ''
            try { \$dName = [string]\$d.Properties('Name').Value } catch {}
            if (\$preferId -ne '' -and (\$dId -eq \$preferId -or \$dName -eq \$preferId -or (\$dName -ne '' -and \$preferId -like "*\$dName*"))) {
                \$candidates = ,\$d + \$candidates
            } else {
                \$candidates += \$d
            }
        }
        foreach (\$cand in \$candidates) {
            try {
                \$dev = \$cand.Connect()
                if (\$null -ne \$dev -and \$dev.Items.Count -ge 1) {
                    \$it = \$dev.Items.Item(1)
                    Set-WiaPhotoScanProperties -item \$it
                    \$img = \$it.Transfer(\$wiaFormatJPEG)
                    if (\$null -ne \$img) {
                        \$sName = 'Scanner Aktif'
                        try { \$sName = [string]\$cand.Properties('Name').Value } catch {}
                        return @{ img = \$img; name = \$sName }
                    }
                }
            } catch {}
        }
        return \$null
    }

    if (\$useDialog) {
        try {
            # Dialog WIA: bias MaximizeQuality = profil foto (bukan minimize/full halaman)
            \$dlg = New-Object -ComObject WIA.CommonDialog
            \$imgFile = \$dlg.ShowAcquireImage(1, \$colorType, \$wiaBias, \$wiaFormatJPEG, \$false, \$true, \$false)
            \$usedScannerName = 'Dialog Driver Scanner Windows (Mode Foto)'
        } catch {
            # Jika dialog gagal (misal terhalang session/background atau memilih device WSD offline), otomatis scan langsung dari USB WIA yang aktif
            \$dm = New-Object -ComObject WIA.DeviceManager
            \$resWia = Try-ConnectAndTransferWia \$dm \$targetId
            if (\$null -ne \$resWia) {
                \$imgFile = \$resWia.img
                \$usedScannerName = \$resWia.name
            } else {
                throw
            }
        }
    } else {
        \$dm = New-Object -ComObject WIA.DeviceManager
        \$resWia = Try-ConnectAndTransferWia \$dm \$targetId
        if (\$null -ne \$resWia) {
            \$imgFile = \$resWia.img
            \$usedScannerName = \$resWia.name
        } else {
            # Fallback ke CommonDialog jika DeviceInfos langsung belum berhasil
            \$dlg = New-Object -ComObject WIA.CommonDialog
            \$imgFile = \$dlg.ShowAcquireImage(1, \$colorType, \$wiaBias, \$wiaFormatJPEG, \$false, \$true, \$false)
            \$usedScannerName = 'Driver Scanner Windows (Mode Foto)'
        }
    }

    if (\$null -eq \$imgFile) {
        Write-Output (ConvertTo-Json @{ ok = \$false; error = 'Pemindaian dibatalkan atau tidak ada gambar dari scanner.' } -Compress)
        exit 0
    }

    if (Test-Path \$outPath) { Remove-Item \$outPath -Force }
    \$imgFile.SaveFile(\$outPath)

    Write-Output (ConvertTo-Json @{
        ok = \$true
        scanner_name = \$usedScannerName
        file_path = \$outPath
    } -Compress)
} catch {
    \$errMsg = \$_.Exception.Message
    if (\$errMsg -match '80210015' -or \$errMsg -match 'No scanner') {
        \$errMsg = 'Tidak ada perangkat scanner fisik yang sedang menyala / terhubung ke port USB atau jaringan komputer ini. Pastikan kabel USB scanner terpasang dan scanner dalam keadaan ON.'
    } elseif (\$errMsg -match '80210006' -or \$errMsg -match 'busy') {
        \$errMsg = 'Driver scanner sedang sibuk digunakan aplikasi lain. Tunggu beberapa detik lalu coba lagi.'
    } elseif (\$errMsg -match '80210064' -or \$errMsg -match 'cancel') {
        \$errMsg = 'Proses scan dibatalkan oleh pengguna.'
    }
    Write-Output (ConvertTo-Json @{ ok = \$false; error = \$errMsg } -Compress)
}
PS1;

        file_put_contents($psScriptPath, $psCode);
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Sta -File ' . escapeshellarg($psScriptPath) . ' 2>&1';
        $rawOut = @shell_exec($cmd);

        $jsonLine = '';
        if (is_string($rawOut)) {
            $lines = preg_split('/\r\n|\r|\n/', trim($rawOut));
            foreach (array_reverse($lines) as $ln) {
                $ln = trim($ln);
                if (strpos($ln, '{') === 0 && substr($ln, -1) === '}') {
                    $jsonLine = $ln;
                    break;
                }
            }
        }

        $decoded = $jsonLine !== '' ? json_decode($jsonLine, true) : null;
        if (!is_array($decoded) || empty($decoded['ok'])) {
            @unlink($tempOutFile);
            return [
                'ok' => false,
                'error' => $decoded['error'] ?? 'Tidak dapat membaca dari driver scanner. Pastikan perangkat scanner menyala dan terhubung ke komputer.'
            ];
        }

        if (!is_file($tempOutFile) || filesize($tempOutFile) === 0) {
            return [
                'ok' => false,
                'error' => 'File hasil scan kosong atau gagal disimpan dari driver scanner.'
            ];
        }

        return [
            'ok' => true,
            'temp_path' => $tempOutFile,
            'scanner_name' => $decoded['scanner_name'] ?? 'Scanner Aktif'
        ];
    }
}
