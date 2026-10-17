@echo off
REM Stops Hassani ERP if its window was closed in an unusual way and the app is still running.
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$f='storage\framework\hassani-processes.txt'; if (Test-Path $f) { Get-Content $f | ForEach-Object { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue }; Remove-Item $f }; Get-CimInstance Win32_Process -Filter \"Name = 'php.exe'\" | Where-Object { $_.CommandLine -match 'artisan|server\.php' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }; Write-Host 'Hassani ERP stopped.'"
timeout /t 3 >nul
