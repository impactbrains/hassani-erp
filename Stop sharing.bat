@echo off
REM Hassani ERP - stop sharing the app with other computers.
title Hassani ERP - Stop sharing
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0starter\stop-sharing.ps1"
if errorlevel 1 pause
