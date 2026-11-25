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
        $psScriptPath = TEMP_DIR . DIRECTORY_SEPARATOR . 'list_scanners.ps1';
        $psCode = <<<'PS1'
$ErrorActionPreference = 'SilentlyContinue'
$devices = @()

# 1. Baca dari WIA.DeviceManager (Driver Scanner WIA Aktif)
try {
    $dm = New-Object -ComObject WIA.DeviceManager
    foreach ($d in $dm.DeviceInfos) {
        $name = ''
        $mfg  = ''
        try { $name = [string]$d.Properties('Name').Value } catch {}
        try { $mfg  = [string]$d.Properties('Manufacturer').Value } catch {}
        if ($name -ne '') {
            $devices += @{
                id = [string]$d.DeviceID
                name = $name
                manufacturer = $mfg
                driver_type = 'WIA Hardware Scanner'
                status = 'Aktif (Siap Scan)'
                source = 'wia'
            }
        }
    }
} catch {}

# 2. Baca juga dari WMI / PnP Windows Class 'Image' & 'Scanner' (Driver TWAIN / WIA / MFP Scanner yang aktif)
try {
    $pnpList = Get-CimInstance -ClassName Win32_PnPEntity -Filter "Status='OK' AND (PNPClass='Image' OR Service='StillCam' OR Name LIKE '%Scan%' OR Name LIKE '%WIA%' OR Name LIKE '%TWAIN%')"
    foreach ($p in $pnpList) {
        $pName = [string]$p.Name
        if ($pName -eq '') { continue }
        $already = $false
        foreach ($ex in $devices) {
            if ($ex.name -eq $pName) { $already = $true; break }
        }
        if (-not $already) {
            $devices += @{
                id = [string]$p.DeviceID
                name = $pName
                manufacturer = [string]$p.Manufacturer
                driver_type = 'Windows Imaging / TWAIN Driver'
                status = 'Aktif (Terhubung)'
                source = 'pnp'
            }
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
        $colorIntent = ($colorMode === 'grayscale') ? 2 : 1;

        $tempOutFile = TEMP_DIR . DIRECTORY_SEPARATOR . 'scan_' . uniqid() . '.jpg';
        $psScriptPath = TEMP_DIR . DIRECTORY_SEPARATOR . 'exec_scan.ps1';

        $escapedOut = str_replace("'", "''", $tempOutFile);
        $escapedDev = str_replace("'", "''", $deviceId);
        $dialogFlag = $useDialog ? '$true' : '$false';

        $psCode = <<<PS1
\$ErrorActionPreference = 'Stop'
\$outPath = '$escapedOut'
\$targetId = '$escapedDev'
\$useDialog = $dialogFlag
\$dpi = $dpi
\$intent = $colorIntent
\$wiaFormatJPEG = '{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}'

try {
    \$imgFile = \$null
    \$usedScannerName = ''

    if (\$useDialog) {
        # Buka dialog interaktif driver scanner Windows
        \$dlg = New-Object -ComObject WIA.CommonDialog
        \$imgFile = \$dlg.ShowAcquireImage(1, \$intent, 65536, \$wiaFormatJPEG, \$false, \$true, \$false)
        \$usedScannerName = 'Dialog Driver Scanner Windows'
    } else {
        \$dm = New-Object -ComObject WIA.DeviceManager
        \$targetInfo = \$null

        foreach (\$d in \$dm.DeviceInfos) {
            if (\$targetId -ne '' -and [string]\$d.DeviceID -eq \$targetId) {
                \$targetInfo = \$d
                break
            }
        }
        if (\$null -eq \$targetInfo) {
            foreach (\$d in \$dm.DeviceInfos) {
                if (\$d.Type -eq 1) {
                    \$targetInfo = \$d
                    break
                }
            }
        }
        if (\$null -eq \$targetInfo -and \$dm.DeviceInfos.Count -gt 0) {
            \$targetInfo = \$dm.DeviceInfos.Item(1)
        }

        if (\$null -ne \$targetInfo) {
            try { \$usedScannerName = [string]\$targetInfo.Properties('Name').Value } catch { \$usedScannerName = 'Scanner Aktif' }
            \$device = \$targetInfo.Connect()
            \$item = \$device.Items.Item(1)

            # Set properti resolusi DPI & Color Intent jika didukung oleh driver scanner
            try { \$item.Properties('6146').Value = \$intent } catch {}
            try { \$item.Properties('6147').Value = \$dpi } catch {}
            try { \$item.Properties('6148').Value = \$dpi } catch {}

            \$imgFile = \$item.Transfer(\$wiaFormatJPEG)
        } else {
            # Fallback ke CommonDialog jika DeviceInfos kosong tapi driver TWAIN/WIA tersedia lewat dialog
            \$dlg = New-Object -ComObject WIA.CommonDialog
            \$imgFile = \$dlg.ShowAcquireImage(1, \$intent, 65536, \$wiaFormatJPEG, \$false, \$true, \$false)
            \$usedScannerName = 'Driver Scanner Windows'
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
