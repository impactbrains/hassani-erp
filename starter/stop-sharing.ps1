# Hassani ERP - stop sharing the app with other computers (undoes "Host on Wi-Fi").
# Removes the firewall rule, sets SERVER_HOST back to 127.0.0.1 and restarts the app.

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
function Set-EnvValue($key, $value) {
    $envFile = Join-Path $Root '.env'
    if (-not (Test-Path $envFile)) { return }
    $pattern = '^\s*' + [regex]::Escape($key) + '\s*='
    $found = $false
    $out = foreach ($line in @(Get-Content $envFile)) {
        if ($line -match $pattern) { $found = $true; "$key=$value" } else { $line }
    }
    if (-not $found) { $out = @($out) + "$key=$value" }
    [System.IO.File]::WriteAllLines($envFile, [string[]]@($out), (New-Object System.Text.UTF8Encoding($false)))
}
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

Say ''
Say '  Hassani ERP - stop sharing' 'Cyan'
Say ''
Get-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
Say 'Firewall rule removed.' 'Green'
Set-EnvValue 'SERVER_HOST' '127.0.0.1'
Say 'Sharing switched off in .env (SERVER_HOST=127.0.0.1).' 'Green'
Say 'Restarting Hassani ERP ...' 'Yellow'
Stop-Hassani
Start-Process explorer.exe -ArgumentList "`"$(Join-Path $Root 'Start Hassani ERP.bat')`""
Say ''
Say 'Done. Hassani ERP now only opens on this computer. (The network stays marked Private; that is harmless.)' 'Green'
Finish 0
