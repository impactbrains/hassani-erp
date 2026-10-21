@echo off
REM Hassani ERP - share the app with the other computers on the shop Wi-Fi.
title Hassani ERP - Host on Wi-Fi
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0starter\host-wifi.ps1"
if errorlevel 1 pause
