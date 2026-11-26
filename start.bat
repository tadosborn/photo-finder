@echo off
REM Runs Photo Finder's web server (Apache) in this window on http://127.0.0.1:8765, for debugging.
REM Installed copies normally run it hidden via the PhotoFinderServer task; stop that one first.
REM Errors go to server.log in the data folder. Press Ctrl+C in this window to stop.
setlocal
cd /d "%~dp0"
if not exist "%~dp0apache\bin\httpd.exe" (
    echo This copy has no bundled Apache. A source checkout is served by XAMPP's Apache instead.
    exit /b 1
)
set "PF_APP=%~dp0"
set "PF_APP=%PF_APP:~0,-1%"
set "PF_APP=%PF_APP:\=/%"
if defined PHOTOFINDER_DATA (set "PF_DATA=%PHOTOFINDER_DATA%") else if exist "%~dp0unins000.exe" (set "PF_DATA=%LOCALAPPDATA%\Photo Finder") else (set "PF_DATA=%~dp0data")
if not exist "%PF_DATA%" mkdir "%PF_DATA%"
set "PF_DATA=%PF_DATA:\=/%"
set "PF_PORT=8765"
set "PATH=%~dp0php;%PATH%"
echo Photo Finder starting on http://127.0.0.1:%PF_PORT%  (log: %PF_DATA%/server.log)
start "" http://127.0.0.1:%PF_PORT%/
"%~dp0apache\bin\httpd.exe" -d "%PF_APP%/apache" -f conf/httpd.conf
