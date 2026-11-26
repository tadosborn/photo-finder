@echo off
REM Photo Finder one-time setup launcher.
REM Calls install.ps1 with ExecutionPolicy bypass so PowerShell doesn't refuse to run it.
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1"
