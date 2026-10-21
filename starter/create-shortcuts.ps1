# Puts a "Hassani ERP" shortcut on the Desktop and, if you agree, in the Startup folder
# so the app starts by itself when the shop computer is switched on.
$Root = Split-Path -Parent $PSScriptRoot
# Point at the .bat (it always shows errors); it runs the same starter\start.ps1 as the .exe and the shortcut still gets the Hassani icon.
$target = Join-Path $Root 'Start Hassani ERP.bat'
if (-not (Test-Path $target)) { $target = Join-Path $Root 'Start Hassani ERP.exe' }
$icon = Join-Path $Root 'starter\hassani.ico'
$shell = New-Object -ComObject WScript.Shell

function Make-Shortcut($path) {
    $s = $shell.CreateShortcut($path)
    $s.TargetPath = $target
    $s.WorkingDirectory = $Root
    if (Test-Path $icon) { $s.IconLocation = $icon }
    $s.Description = 'Start Hassani ERP'
    $s.Save()
}

Make-Shortcut (Join-Path ([Environment]::GetFolderPath('Desktop')) 'Hassani ERP.lnk')
Write-Host 'Desktop shortcut created.' -ForegroundColor Green
$answer = Read-Host 'Also start Hassani ERP automatically when Windows starts? [y/N]'
if ($answer -match '^[Yy]') {
    Make-Shortcut (Join-Path ([Environment]::GetFolderPath('Startup')) 'Hassani ERP.lnk')
    Write-Host 'Added to Startup.' -ForegroundColor Green
}
Start-Sleep -Seconds 2