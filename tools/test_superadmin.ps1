# Uji end-to-end fitur Super Admin (SILAHUT E-VISA)
# Jalankan: powershell -File tools/test_superadmin.ps1

$ErrorActionPreference = 'Stop'
$base = 'http://localhost/e-visa'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

function Invoke-Api {
    param([string]$Action, $Payload = $null)
    $uri = "$base/api.php?action=$Action"
    if ($null -eq $Payload) {
        return Invoke-WebRequest -Uri $uri -UseBasicParsing -WebSession $session -TimeoutSec 60
    }
    $json = $Payload | ConvertTo-Json -Depth 6
    return Invoke-WebRequest -Uri $uri -Method Post -Body $json -ContentType 'application/json' `
        -UseBasicParsing -WebSession $session -TimeoutSec 60
}

function Invoke-Json {
    param([string]$Action, $Payload = $null)
    $r = Invoke-Api -Action $Action -Payload $Payload
    return ($r.Content | ConvertFrom-Json)
}

# --- Login ---
$login = Invoke-WebRequest -Uri "$base/login.php" -UseBasicParsing -WebSession $session -TimeoutSec 30
$csrf = ([regex]::Match($login.Content, 'name="csrf_token" value="([^"]+)"')).Groups[1].Value
$body = @{ username = 'superadmin'; password = 'Silahut@09f944ad'; csrf_token = $csrf }
$resp = Invoke-WebRequest -Uri "$base/login.php" -Method Post -Body $body -UseBasicParsing -WebSession $session -TimeoutSec 30 -MaximumRedirection 0 -ErrorAction SilentlyContinue
Write-Output "1. Login superadmin: HTTP $($resp.StatusCode) $(if($resp.StatusCode -eq 302){'OK'}else{'GAGAL'})"

$slug = 'kota-malang'

# --- Simpan instansi baru ---
$j = Invoke-JSON 'save_lembaga' @{
    slug = $slug; nama = 'Kantor Kemenhaj Kota Malang (Uji)'
    source_email = 'kemenhaj.malang@example.com'; target_gdrive_email = 'gdrive.malang@example.com'
    gdrive_folder_name = 'Biovisa_Jamaah_Kemenhaj_Malang'
}
Write-Output "2. Simpan instansi baru: ok=$($j.ok) $($j.message) $($j.error)"

# --- Daftar instansi ---
$j = Invoke-JSON 'list_lembaga'
$newId = ''
foreach ($l in $j.lembaga) { if ($l.slug -eq $slug) { $newId = $l.id } }
Write-Output "3. Daftar instansi: total=$($j.lembaga.Count) baru_terdaftar=$([bool]$newId)"

# --- Daftar pengguna ---
$j = Invoke-JSON 'list_users'
Write-Output "4. Daftar pengguna: ok=$($j.ok) total=$($j.users.Count)"

# --- Simpan pengguna baru ---
$j = Invoke-JSON 'save_user' @{
    username = 'admin_malang'; nama = 'Admin Malang'; password = 'MalangTest123'
    role = 'admin'; lembaga_id = $newId
}
Write-Output "5. Simpan pengguna baru: ok=$($j.ok) $($j.message) $($j.error)"

# --- Update password (lama benar) ---
$j = Invoke-JSON 'change_my_password' @{
    current_password = 'Silahut@09f944ad'; new_password = 'Silahut@09f944ad'
}
Write-Output "6. Update password (lama benar): ok=$($j.ok) $($j.message) $($j.error)"

# --- Update password (lama salah) -> harus ditolak ---
$j = Invoke-JSON 'change_my_password' @{
    current_password = 'SALAH-SEKALI'; new_password = 'BaruSekali123'
}
Write-Output "7. Update password (lama salah, HARUS ditolak): ok=$($j.ok) $($j.error)"

# --- Status agregat ---
$j = Invoke-JSON 'status'
Write-Output "8. Status agregat: total=$($j.stats.total_biovisa) super=$($j.is_super_admin) instansi=$($j.lembaga_list.Count)"

# --- Edit instansi ---
$j = Invoke-JSON 'save_lembaga' @{
    id = $newId; slug = $slug; nama = 'Kantor Kemenhaj Kota Malang (Uji Edit)'
    source_email = 'kemenhaj.malang@example.com'; target_gdrive_email = 'gdrive.malang@example.com'
    gdrive_folder_name = 'Biovisa_Jamaah_Kemenhaj_Malang'
}
Write-Output "9. Edit instansi: ok=$($j.ok) $($j.message) $($j.error)"
