@echo off
REM Photo Finder background indexer - runs in your user context.
REM Watches the SQLite DB for indexing requests from the web UI and processes them.
REM Press Ctrl+C to stop. Or just close this window.
cd /d "%~dp0"
set "PF_PHP=%~dp0php\php.exe"
if not exist "%PF_PHP%" set "PF_PHP=c:\xampp\php\php.exe"
echo Photo Finder worker starting. Leave this window open.
echo (Use http://127.0.0.1:8765/ to control it.)
echo.
"%PF_PHP%" worker.php
echo.
echo Worker exited. Press any key to close.
pause >nul
