# Removes Photo Finder's scheduled tasks and stops THIS copy's web server and worker.
# No admin required (tasks are user-scoped). Run by the uninstaller with -Quiet, and before
# an upgrade with -StopOnly (the upgrade's install.ps1 re-registers everything).
# Leaves data\ (index, thumbnails, settings) alone: the uninstaller asks about that.
#   -AppDir   the copy to act on (the installer runs this script from its temp folder)
param(
    [switch]$Quiet,
    [switch]$StopOnly,
    [string]$AppDir = ''
)

$ErrorActionPreference = 'Continue'
$projectDir = if ($AppDir) { $AppDir.TrimEnd('\') } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
# Match on the folder plus a backslash so "Photo Finder" never matches "Photo Finder 2".
$needle = ($projectDir + '\').ToLower()

if (-not $StopOnly) {
    foreach ($name in @('PhotoFinderServer', 'PhotoFinderWorker', 'PhotoFinderLogin')) {
        $t = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        if (-not $t) { Write-Host "Task $name not present."; continue }
        # Only remove tasks that belong to this copy; another install may own the name.
        $args0 = ($t.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments) $($_.WorkingDirectory)" }) -join ' '
        if ($args0.ToLower().Contains($needle)) {
            Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
            Unregister-ScheduledTask -TaskName $name -Confirm:$false
            Write-Host "Removed scheduled task: $name"
        } else {
            Write-Host "Task $name belongs to another copy of Photo Finder; left alone."
        }
    }
    $oldLnk = Join-Path ([Environment]::GetFolderPath('Startup')) 'Photo Finder Worker.lnk'
    if (Test-Path $oldLnk) {
        $sh = New-Object -ComObject WScript.Shell
        if ($sh.CreateShortcut($oldLnk).Arguments.ToLower().Contains($needle)) {
            Remove-Item $oldLnk -Force; Write-Host 'Removed old startup shortcut.'
        }
    }
} else {
    foreach ($name in @('PhotoFinderServer', 'PhotoFinderWorker')) {
        Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    }
}

Write-Host 'Stopping Photo Finder processes from this folder...'
# The web server (Apache: a parent and a child httpd.exe) and the worker (php.exe). Before
# 1.0.5 the web server was php.exe too, so an upgrade from those still finds it here.
Get-CimInstance Win32_Process -Filter "Name='httpd.exe' OR Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { ($_.ExecutablePath -and $_.ExecutablePath.ToLower().StartsWith($needle)) -or
                   ($_.CommandLine -and $_.CommandLine.ToLower().Contains($needle)) } |
    ForEach-Object { Write-Host ("  Stopping PID " + $_.ProcessId); Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
# A process that just exited can hold its DLLs for a moment; give the file copy a clean start.
Start-Sleep -Milliseconds 500

Write-Host ''
Write-Host 'Photo Finder stopped.' -ForegroundColor Green
if (-not $Quiet) { Read-Host 'Press Enter' | Out-Null }
