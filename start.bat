@echo off
REM Launch the Photo Finder web app on http://127.0.0.1:8765 in this window (for debugging).
REM Installed copies normally run it hidden via the PhotoFinderServer task instead.
REM Press Ctrl+C in this window to stop.
cd /d "%~dp0"
set "PF_PHP=%~dp0php\php.exe"
if not exist "%PF_PHP%" set "PF_PHP=c:\xampp\php\php.exe"
echo Photo Finder starting on http://127.0.0.1:8765
start "" http://127.0.0.1:8765
"%PF_PHP%" -S 127.0.0.1:8765 -t "%~dp0." "%~dp0router.php"
