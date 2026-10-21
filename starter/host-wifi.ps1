# Hassani ERP - share the app with the other computers on the shop Wi-Fi / network.
#
# Double-click "Host on Wi-Fi.bat". It:
#   1. asks Windows for permission once (administrator, needed for the firewall),
#   2. sets SERVER_HOST=0.0.0.0 in .env,
#   3. adds a Windows Firewall rule that lets ONLY computers on the same local network in,
#   4. restarts the server, and
#   5. shows the address to type on the other computers (and copies it to the clipboard).
# "Stop sharing.bat" undoes all of it. Nothing here opens the app to the internet.

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
$RuleName = 'Hassani ERP (shop network)'

function Say($text, $color = 'Gray') { Write-Host $text -ForegroundColor $color }
function Test-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}
function Finish($code = 0) {
    Write-Host ''
    Read-Host 'Press Enter to close' | Out-Null
    exit $code
}

# ---- 1. administrator (Windows shows one "Do you want to allow..." question)
if (-not (Test-Admin)) {
    Say 'Windows will now ask for permission once. Click Yes.' 'Yellow'
    Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
    exit 0
}

function Get-EnvValue($key, $default) {
    $envFile = Join-Path $Root '.env'
    if (Test-Path $envFile) {
        foreach ($line in Get-Content $envFile) {
            if ($line -match ('^\s*' + [regex]::Escape($key) + '\s*=\s*"?([^"#]*?)"?\s*$')) { return $Matches[1] }
        }
    }
    return $default
}
# Sets KEY=value in .env (edits the line if it is there, adds it if not). Leaves everything else as it is.
function Set-EnvValue($key, $value) {
    $envFile = Join-Path $Root '.env'
    if (-not (Test-Path $envFile)) {
        $example = Join-Path $Root '.env.example'
        if (Test-Path $example) { Copy-Item $example $envFile } else { New-Item -ItemType File -Path $envFile | Out-Null }
    }
    $lines = @(Get-Content $envFile)
    $pattern = '^\s*' + [regex]::Escape($key) + '\s*='
    $found = $false
    $out = foreach ($line in $lines) {
        if ($line -match $pattern) { $found = $true; "$key=$value" } else { $line }
    }
    if (-not $found) { $out = @($out) + "$key=$value" }
    [System.IO.File]::WriteAllLines($envFile, [string[]]@($out), (New-Object System.Text.UTF8Encoding($false)))
}
# The address of this computer on the shop network (the adapter that has a gateway, so not a VPN or virtual one).
function Get-LanAddress {
    $configs = @(Get-NetIPConfiguration -ErrorAction SilentlyContinue | Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' })
    if (-not $configs.Count) { return $null }
    $best = $configs | Sort-Object { try { $_.NetIPv4Interface.InterfaceMetric } catch { 9999 } } | Select-Object -First 1
    return @{ Ip = ($best.IPv4Address | Select-Object -First 1).IPAddress; Index = $best.InterfaceIndex }
}
# Stops the start window and everything it started, so the next start reads the new .env.
function Stop-Hassani {
    $ErrorActionPreference = 'SilentlyContinue'
    $me = $PID
    $starters = Get-CimInstance Win32_Process | Where-Object { $_.ProcessId -ne $me -and $_.CommandLine -match 'starter\\start\.ps1' }
    foreach ($p in $starters) {
        $parent = Get-CimInstance Win32_Process -Filter "ProcessId = $($p.ParentProcessId)"
        if ($parent -and $parent.Name -match '^(cmd|Start Hassani ERP)\.exe$') { & taskkill.exe /PID $parent.ProcessId /T /F 2>&1 | Out-Null }
        & taskkill.exe /PID $p.ProcessId /T /F 2>&1 | Out-Null
    }
    $pidFile = Join-Path $Root 'storage\framework\hassani-processes.txt'
    if (Test-Path $pidFile) {
        Get-Content $pidFile | ForEach-Object { $proc = Get-Process -Id $_; if ($proc -and $proc.ProcessName -eq 'php') { & taskkill.exe /PID $_ /T /F 2>&1 | Out-Null } }
        Remove-Item $pidFile -Force
    }
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object { $_.CommandLine -match [regex]::Escape($Root) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
    Start-Sleep -Seconds 2
}
# Starts the app again as the normal user (explorer.exe opens it without administrator rights).
function Start-Hassani {
    $bat = Join-Path $Root 'Start Hassani ERP.bat'
    Start-Process explorer.exe -ArgumentList "`"$bat`""
}
function Wait-ForServer($port) {
    for ($i = 0; $i -lt 120; $i++) {
        try {
            $req = [System.Net.HttpWebRequest]::Create("http://127.0.0.1:$port/up")
            $req.Proxy = $null; $req.Timeout = 3000; $req.AllowAutoRedirect = $false
            $resp = $req.GetResponse(); $resp.Close(); return $true
        } catch {
            if ($_.Exception.Response) { return $true }
            Start-Sleep -Milliseconds 750
        }
    }
    return $false
}

Say ''
Say '  Hassani ERP - host on the shop Wi-Fi / network' 'Cyan'
Say ''
$port = [int](Get-EnvValue 'SERVER_PORT' '8000')
$lan = Get-LanAddress
if (-not $lan) {
    Say 'This computer is not connected to a Wi-Fi or cable network. Connect it to the shop network, then run this again.' 'Red'
    Finish 1
}

# ---- 2. .env
Set-EnvValue 'SERVER_HOST' '0.0.0.0'
Say 'Sharing switched on in .env (SERVER_HOST=0.0.0.0).' 'Green'

# ---- 3. firewall: only the local network, never the internet
Get-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
New-NetFirewallRule -DisplayName $RuleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort $port -RemoteAddress LocalSubnet -Profile Private, Domain | Out-Null
Say "Firewall rule added: computers on this network may open port $port." 'Green'

# Windows treats unknown Wi-Fi as "Public", and Public networks block incoming connections.
$netProfile = Get-NetConnectionProfile -InterfaceIndex $lan.Index -ErrorAction SilentlyContinue
if ($netProfile -and $netProfile.NetworkCategory -eq 'Public') {
    Say ''
    Say "Windows calls this network '$($netProfile.Name)' a Public network, which blocks other computers." 'Yellow'
    $answer = Read-Host 'Is this your own shop network, and may I mark it as Private? [Y/n]'
    if ($answer -eq '' -or $answer -match '^[Yy]') {
        Set-NetConnectionProfile -InterfaceIndex $lan.Index -NetworkCategory Private
        Say 'Network marked as Private.' 'Green'
    } else {
        Say 'Left as Public. Other computers will not be able to connect until it is Private.' 'Yellow'
    }
}

# ---- 4. restart
Say ''
Say 'Restarting Hassani ERP (this takes about half a minute) ...' 'Yellow'
Stop-Hassani
Start-Hassani
$up = Wait-ForServer $port

# ---- 5. the address
$address = "http://$($lan.Ip):$port"
Say ''
Say '================================================================' 'Green'
if ($up) { Say '  Hassani ERP is shared on the shop network.' 'Green' } else { Say '  Sharing is set up. The app is still starting; give it a minute.' 'Yellow' }
Say "  On the other computers or phones (same Wi-Fi), open:  $address" 'Green'
Say '================================================================' 'Green'
try { Set-Clipboard -Value $address; Say '(The address is copied. Paste it into the browser on the other computer.)' 'DarkGray' } catch { }
Say ''
Say 'Good to know:' 'Gray'
Say ' - Keep the black Hassani window open on THIS computer: it is the server.' 'Gray'
Say ' - This is plain http, fine on your own shop network. Do not use it on public Wi-Fi.' 'Gray'
Say ' - USB and Bluetooth scanners work on every computer. The camera scanner only works on this computer (browsers block cameras on http addresses).' 'Gray'
Say ' - To stop sharing, double-click "Stop sharing.bat".' 'Gray'
Finish 0
