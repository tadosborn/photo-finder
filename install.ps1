# Photo Finder setup: registers the scheduled tasks that run it in YOUR user context
# (no admin needed) and starts it. Run by PhotoFinderSetup.exe after it copies the files,
# and safe to re-run by hand (install.bat) to repair the registration. Idempotent.
#
#   -Quiet           no prompts (the installer passes this)
#   -PhotoDirsFile   UTF-8 text file, one photo folder per line, from the setup wizard
#   -UpdateCheck     1/0: automatic update checks on/off, from the setup wizard (omit = unchanged)
#   -SkipTasks       configure only; do not register or start tasks (side-by-side testing)
#
# Windows PowerShell 5.1 syntax on purpose: that is the PowerShell every Windows PC has.
param(
    [switch]$Quiet,
    [string]$PhotoDirsFile = '',
    [ValidateSet('', '0', '1')][string]$UpdateCheck = '',
    [switch]$SkipTasks
)

$ErrorActionPreference = 'Stop'
$projectDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$account = "$env:USERDOMAIN\$env:USERNAME"
$bundledPhp = Join-Path $projectDir 'php\php.exe'
$installed = Test-Path $bundledPhp
$php = if ($installed) { $bundledPhp } else { 'C:\xampp\php\php.exe' }
$url = 'http://127.0.0.1:8765/'
# Same rule as pf_data_dir() in lib/util.php: an installed copy keeps its data per user in
# %LOCALAPPDATA%\Photo Finder (Program Files is read-only); a source checkout uses .\data.
$dataDir = if ($env:PHOTOFINDER_DATA) { $env:PHOTOFINDER_DATA }
           elseif ($installed -and (Test-Path (Join-Path $projectDir 'unins000.exe'))) { Join-Path $env:LOCALAPPDATA 'Photo Finder' }
           else { Join-Path $projectDir 'data' }
$logFile = Join-Path $dataDir 'install.log'

function Write-Step([string]$msg, [string]$color = 'Gray') {
    Write-Host $msg -ForegroundColor $color
    Add-Content -Path $logFile -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg) -Encoding UTF8
}
function Exit-Setup([int]$code) {
    if (-not $Quiet) { Read-Host 'Press Enter to close' | Out-Null }
    exit $code
}
function Invoke-Php([string[]]$phpArgs) {
    # Windows PowerShell 5.1 turns a native program's stderr into a terminating error under
    # ErrorActionPreference=Stop; collect it as text instead. $LASTEXITCODE survives.
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { return @(& $php @phpArgs 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
}
function Get-OwnPhp([string]$script) {
    # Only php.exe processes running THIS copy's scripts; never another install's.
    @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine.ToLower().Contains(($projectDir + '\').ToLower()) -and $_.CommandLine.ToLower().Contains($script) })
}
function Get-OwnServer {
    # This copy's Apache (parent and child process), never another install's or XAMPP's.
    @(Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.ExecutablePath -and $_.ExecutablePath.ToLower().StartsWith(($projectDir + '\').ToLower()) })
}
$httpd = Join-Path $projectDir 'apache\bin\httpd.exe'
function Set-ServerEnv {
    # The variables apache\conf\httpd.conf reads (silent-server.vbs sets the same).
    $env:PF_APP = $projectDir.Replace('\', '/')
    $env:PF_DATA = $dataDir.Replace('\', '/')
    $env:PF_PORT = '8765'
    $env:PATH = (Join-Path $projectDir 'php') + ';' + $env:PATH
}

New-Item -ItemType Directory -Force -Path $dataDir | Out-Null
Write-Step "Photo Finder setup: program $projectDir, data $dataDir (account $account)"

# ---- PHP sanity: the bundled runtime must actually start (VC++ runtime present, extensions load) ----
# (No double quotes inside -r code: Windows PowerShell 5.1 does not escape them for native programs.)
$check = Invoke-Php @('-r', 'foreach ([''pdo_sqlite'',''gd'',''exif'',''mbstring'',''curl'',''sodium'',''simplexml''] as $e) if (!extension_loaded($e)) echo ''missing:'', $e, '' '';')
if (-not (Test-Path $php) -or $LASTEXITCODE -ne 0 -or "$check" -match 'missing:|Warning|Fatal') {
    Write-Step "PHP check FAILED ($php): $check" 'Red'
    Exit-Setup 1
}
Write-Step 'PHP runtime OK.'

# ---- Web server sanity: Apache parses its config and loads PHP as a module ----
if ($installed) {
    if (-not (Test-Path $httpd)) { Write-Step "Web server missing: $httpd" 'Red'; Exit-Setup 1 }
    Set-ServerEnv
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $t = @(& $httpd -d "$($env:PF_APP)/apache" -f conf/httpd.conf -t 2>&1 | ForEach-Object { "$_" }) } finally { $ErrorActionPreference = $old }
    if ($LASTEXITCODE -ne 0 -or "$t" -notmatch 'Syntax OK') {
        Write-Step "Web server check FAILED: $t" 'Red'
        Exit-Setup 1
    }
    Write-Step 'Web server (Apache + PHP) OK.'
}

# ---- Timezone for log timestamps (stored in settings; Program Files is read-only) ----
$tz = ''
if ($installed) {
    $tzMap = @{
        'Mountain Standard Time' = 'America/Denver'; 'US Mountain Standard Time' = 'America/Phoenix'
        'Pacific Standard Time' = 'America/Los_Angeles'; 'Central Standard Time' = 'America/Chicago'
        'Eastern Standard Time' = 'America/New_York'; 'US Eastern Standard Time' = 'America/Indiana/Indianapolis'
        'Alaskan Standard Time' = 'America/Anchorage'; 'Hawaiian Standard Time' = 'Pacific/Honolulu'
        'Atlantic Standard Time' = 'America/Halifax'; 'Newfoundland Standard Time' = 'America/St_Johns'
        'Canada Central Standard Time' = 'America/Regina'; 'GMT Standard Time' = 'Europe/London'
        'W. Europe Standard Time' = 'Europe/Berlin'; 'Romance Standard Time' = 'Europe/Paris'
        'Central Europe Standard Time' = 'Europe/Budapest'; 'Central European Standard Time' = 'Europe/Warsaw'
        'FLE Standard Time' = 'Europe/Kiev'; 'GTB Standard Time' = 'Europe/Bucharest'
        'AUS Eastern Standard Time' = 'Australia/Sydney'; 'New Zealand Standard Time' = 'Pacific/Auckland'
        'India Standard Time' = 'Asia/Kolkata'; 'China Standard Time' = 'Asia/Shanghai'
        'Tokyo Standard Time' = 'Asia/Tokyo'; 'UTC' = 'UTC'
    }
    $winTz = (Get-TimeZone).Id
    $tz = $tzMap[$winTz]
    if (-not $tz) { $tz = 'UTC' }
    Write-Step "Timezone: $tz (Windows: $winTz)"
}

# ---- Settings: photo folders from the wizard, timezone, update checks; moves old settings forward ----
$cfgArgs = @((Join-Path $projectDir 'configure.php'))
if ($PhotoDirsFile -and (Test-Path $PhotoDirsFile)) { $cfgArgs += "--photo-dirs-file=$PhotoDirsFile" }
if ($tz) { $cfgArgs += "--timezone=$tz" }
if ($UpdateCheck) { $cfgArgs += "--update-check=$UpdateCheck" }
Push-Location $projectDir
try { $out = Invoke-Php $cfgArgs } finally { Pop-Location }
foreach ($line in @($out)) { if ("$line") { Write-Step "  $line" } }
if ($LASTEXITCODE -ne 0) { Write-Step 'Writing settings FAILED.' 'Red'; Exit-Setup 1 }

if ($SkipTasks) {
    Write-Step 'Skipping scheduled tasks (-SkipTasks).' 'Yellow'
    exit 0
}

# ---- Scheduled tasks ----
function Register-PfTask([string]$name, $action, $triggers, [TimeSpan]$limit, [string]$desc) {
    $principal = New-ScheduledTaskPrincipal -UserId $account -LogonType Interactive -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit $limit
    if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $name -Confirm:$false
    }
    $p = @{ TaskName = $name; Action = $action; Principal = $principal; Settings = $settings; Description = $desc }
    if ($triggers) { $p.Trigger = $triggers }
    Register-ScheduledTask @p | Out-Null
    Write-Step "Registered task $name."
}

try {
    $atLogon = New-ScheduledTaskTrigger -AtLogOn -User $account
    if ($installed) {
        Register-PfTask 'PhotoFinderServer' `
            (New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$(Join-Path $projectDir 'silent-server.vbs')`"" -WorkingDirectory $projectDir) `
            $atLogon ([TimeSpan]::Zero) 'Photo Finder web app on http://127.0.0.1:8765 (this PC only). Starts at sign-in.'
    }
    Register-PfTask 'PhotoFinderWorker' `
        (New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$(Join-Path $projectDir 'silent-worker.vbs')`"" -WorkingDirectory $projectDir) `
        $atLogon ([TimeSpan]::Zero) 'Photo Finder background indexer. Runs in your user context. Triggered by the web UI or at sign-in.'
} catch {
    Write-Step "Registering tasks FAILED: $_" 'Red'
    Exit-Setup 1
}

# 1.0.0 - 1.0.2 had a PhotoFinderLogin task for the Claude Code backend, removed in 1.0.3.
# Remove it only if it belongs to this copy.
$oldLogin = Get-ScheduledTask -TaskName 'PhotoFinderLogin' -ErrorAction SilentlyContinue
if ($oldLogin -and (($oldLogin.Actions | ForEach-Object { "$($_.Arguments) $($_.WorkingDirectory)" }) -join ' ').ToLower().Contains(($projectDir + '\').ToLower())) {
    Unregister-ScheduledTask -TaskName 'PhotoFinderLogin' -Confirm:$false
    Write-Step 'Removed the old PhotoFinderLogin task (Claude Code sign-in, no longer used).'
}

# The old (pre-task) startup shortcut, but only if it starts THIS copy: another copy on the
# PC (e.g. a source checkout under XAMPP) may still rely on its own.
$oldLnk = Join-Path ([Environment]::GetFolderPath('Startup')) 'Photo Finder Worker.lnk'
if (Test-Path $oldLnk) {
    $lnkArgs = (New-Object -ComObject WScript.Shell).CreateShortcut($oldLnk).Arguments
    if ($lnkArgs.ToLower().Contains(($projectDir + '\').ToLower())) {
        Remove-Item $oldLnk -Force
        Write-Step 'Removed old startup shortcut.'
    }
}

# ---- Start ----
if ($installed -and -not (Get-OwnServer)) {
    Start-ScheduledTask -TaskName 'PhotoFinderServer'
}
if (-not (Get-OwnPhp 'worker.php')) {
    Start-ScheduledTask -TaskName 'PhotoFinderWorker'
}
function Test-PfServer {
    try { return (Invoke-WebRequest -UseBasicParsing -TimeoutSec 2 "${url}api.php?action=version").StatusCode -eq 200 } catch { return $false }
}
if ($installed) {
    $up = $false
    for ($attempt = 1; $attempt -le 2 -and -not $up; $attempt++) {
        if ($attempt -eq 2) {
            # One retry: a just-stopped server can take a moment to release the port.
            Write-Step 'Web app not answering yet; starting it again.' 'Yellow'
            Start-ScheduledTask -TaskName 'PhotoFinderServer'
        }
        for ($i = 0; $i -lt 20 -and -not $up; $i++) { Start-Sleep -Milliseconds 500; $up = Test-PfServer }
    }
    if ($up) { Write-Step "Web app is running at $url" 'Green' }
    else { Write-Step "Web app did not answer at $url; see $(Join-Path $dataDir 'server.log')" 'Yellow' }
}
Start-Sleep -Seconds 1
if (Get-OwnPhp 'worker.php') { Write-Step 'Worker is running.' 'Green' } else { Write-Step 'Worker did not start yet; the web app retries it when you start indexing.' 'Yellow' }

Write-Step 'Setup complete.' 'Green'
Exit-Setup 0
