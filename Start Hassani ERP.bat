@echo off
REM Hassani ERP - double-click to start. Keep the window open while the shop is working.
title Hassani ERP
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0starter\start.ps1"
if errorlevel 1 pause
