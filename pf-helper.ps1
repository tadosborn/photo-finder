# Photo Finder's Windows helper: the few things PHP cannot ask Windows itself.
#
# The app runs it as
#   powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File pf-helper.ps1 -Action <name> [-In <file>] [-Out <file>] [-Budget <ms>] [-Exts <list>]
# and passes every value through files in its data folder, never on the command line. Code on
# a PowerShell command line (-EncodedCommand / -Command) is what antivirus behaviour shields
# block (Avast "IDP.HELU.PSE97 - Command line detection", seen 2026-11-26), so the app has no
# such command lines at all; everything PowerShell does for it is in this one file.
#
# Windows PowerShell 5.1 syntax on purpose: that is the PowerShell every Windows PC has.
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('environment', 'list', 'stat', 'settimes', 'pcinfo', 'profiles', 'launch')]
    [string]$Action,
    [string]$In = '',
    [string]$Out = '',
    [int]$Budget = 800,
    [string]$Exts = ''
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$enc = New-Object System.Text.UTF8Encoding $false
$epoch = New-Object DateTime 1970, 1, 1, 0, 0, 0, ([DateTimeKind]::Utc)
# Cloud-only (OneDrive Files On-Demand) and offline files: never opened, so never downloaded.
$cloud = 0x400000 -bor 0x1000 -bor 0x40000

function Write-OutFile([string]$text) { [IO.File]::WriteAllText($Out, $text, $enc) }

# '0' free, '1' in use by another program, '2' no permission. Exclusive open, nothing read.
function Get-LockState([string]$path) {
    try { $fs = [IO.File]::Open($path, 'Open', 'Read', 'None'); $fs.Dispose(); return '0' }
    catch {
        $ex = $_.Exception
        if ($ex.InnerException) { $ex = $ex.InnerException }
        if (($ex.HResult -band 0xFFFF) -eq 5) { return '2' }
        return '1'
    }
}

switch ($Action) {

    # This user's folders, OneDrive roots, cloud sync roots, shared libraries, protected
    # pictures (wallpaper, lock screen, account picture) and fixed drives (Find photos on this PC).
    'environment' {
        $ErrorActionPreference = 'SilentlyContinue'
        $o = [ordered]@{}
        $o.sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $o.profile = [Environment]::GetFolderPath('UserProfile')
        $o.desktop = [Environment]::GetFolderPath('Desktop')
        $o.documents = [Environment]::GetFolderPath('MyDocuments')
        $o.pictures = [Environment]::GetFolderPath('MyPictures')
        $o.videos = [Environment]::GetFolderPath('MyVideos')
        $o.music = [Environment]::GetFolderPath('MyMusic')
        $sf = Get-ItemProperty 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\Shell Folders'
        $o.downloads = [string]$sf.'{374DE290-123F-4565-9164-39C4925E467B}'
        $od = New-Object System.Collections.ArrayList
        foreach ($v in @($env:OneDrive, $env:OneDriveCommercial, $env:OneDriveConsumer)) { if ($v) { [void]$od.Add([string]$v) } }
        $acc = @(Get-ChildItem 'HKCU:\Software\Microsoft\OneDrive\Accounts')
        foreach ($k in $acc) { $f = (Get-ItemProperty $k.PSPath).UserFolder; if ($f) { [void]$od.Add([string]$f) } }
        foreach ($d in @(Get-ChildItem -LiteralPath $o.profile -Directory -Filter 'OneDrive*' -Force)) { [void]$od.Add($d.FullName) }
        $o.onedrive = @($od)
        $mp = New-Object System.Collections.ArrayList
        foreach ($k in $acc) {
            foreach ($t in @(Get-ChildItem (Join-Path $k.PSPath 'Tenants'))) {
                foreach ($p in (Get-ItemProperty $t.PSPath).PSObject.Properties) { if ($p.Name -match '^[A-Za-z]:\\') { [void]$mp.Add([string]$p.Name) } }
            }
            $c = Get-ItemProperty (Join-Path $k.PSPath 'ScopeIdToMountPointPathCache')
            if ($c) { foreach ($p in $c.PSObject.Properties) { if ([string]$p.Value -match '^[A-Za-z]:\\') { [void]$mp.Add([string]$p.Value) } } }
        }
        $o.onedrive_mounts = @($mp)
        $sr = New-Object System.Collections.ArrayList
        foreach ($k in @(Get-ChildItem 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\SyncRootManager')) {
            $u = Get-ItemProperty (Join-Path $k.PSPath 'UserSyncRoots')
            if ($u) { $p = $u.PSObject.Properties[$o.sid]; if ($p) { [void]$sr.Add([string]$p.Value) } }
        }
        $o.sync_roots = @($sr)
        $pr = New-Object System.Collections.ArrayList
        [void]$pr.Add([string](Get-ItemProperty 'HKCU:\Control Panel\Desktop').Wallpaper)
        $w = Get-ItemProperty 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\Wallpapers'
        if ($w) { foreach ($p in $w.PSObject.Properties) { if ($p.Name -like 'BackgroundHistoryPath*') { [void]$pr.Add([string]$p.Value) } } }
        foreach ($td in @((Join-Path $env:LOCALAPPDATA 'Microsoft\Windows\Themes'), (Join-Path $env:APPDATA 'Microsoft\Windows\Themes'))) {
            foreach ($t in @(Get-ChildItem -LiteralPath $td -Filter '*.theme')) {
                foreach ($l in @(Get-Content -LiteralPath $t.FullName)) {
                    if ($l -match '^\s*Wallpaper\s*=\s*(.+?)\s*$') { [void]$pr.Add([Environment]::ExpandEnvironmentVariables($Matches[1])) }
                }
            }
        }
        $csp = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\PersonalizationCSP'
        if ($csp) { foreach ($n in @('LockScreenImagePath', 'DesktopImagePath')) { if ($csp.$n) { [void]$pr.Add([string]$csp.$n) } } }
        $ap = Get-ItemProperty ('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\AccountPicture\Users\' + $o.sid)
        if ($ap) { foreach ($p in $ap.PSObject.Properties) { if ($p.Name -like 'Image*') { [void]$pr.Add([string]$p.Value) } } }
        $o.protected = @($pr | Where-Object { $_ })
        $o.fixed_drives = @([IO.DriveInfo]::GetDrives() | Where-Object { $_.DriveType -eq 'Fixed' -and $_.IsReady } | ForEach-Object { $_.Name })
        $o.system_drive = [string]$env:SystemDrive
        Write-OutFile (ConvertTo-Json $o -Depth 4 -Compress)
    }

    # Lists the folders in -In (one per line, not recursively) until -Budget ms have passed.
    # Output lines: "D<TAB>n" folder start, "d<TAB>attrs<TAB>name" sub-folder,
    # "f<TAB>attrs<TAB>size<TAB>mtime<TAB>ctime<TAB>lock<TAB>name" file, "E<TAB>status", "X<TAB>count".
    'list' {
        $dirs = [IO.File]::ReadAllLines($In, $enc)
        $w = New-Object IO.StreamWriter($Out, $false, $enc)
        $want = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
        foreach ($x in $Exts.Split(',')) { if ($x) { [void]$want.Add($x) } }
        $sw = [Diagnostics.Stopwatch]::StartNew()
        $n = 0
        foreach ($d in $dirs) {
            if ($n -gt 0 -and $sw.ElapsedMilliseconds -gt $Budget) { break }
            $w.WriteLine("D`t$n")
            $st = 'ok'
            try {
                foreach ($e in (New-Object IO.DirectoryInfo $d).EnumerateFileSystemInfos()) {
                    $a = [int]$e.Attributes
                    if ($a -band 16) { $w.WriteLine("d`t$a`t" + $e.Name); continue }
                    $lock = '-'
                    $x = $e.Extension
                    if ($x.Length -gt 1 -and $want.Contains($x.Substring(1)) -and -not ($a -band $cloud)) { $lock = Get-LockState $e.FullName }
                    $mt = [long][Math]::Floor(($e.LastWriteTimeUtc - $epoch).TotalSeconds)
                    $ct = [long][Math]::Floor(($e.CreationTimeUtc - $epoch).TotalSeconds)
                    $w.WriteLine("f`t$a`t" + $e.Length + "`t$mt`t$ct`t$lock`t" + $e.Name)
                }
            } catch [UnauthorizedAccessException] { $st = 'denied' }
              catch [IO.DirectoryNotFoundException] { $st = 'missing' }
              catch { $st = ($_.Exception.Message -replace '[\r\n\t]+', ' ') }
            $w.WriteLine("E`t$st")
            $n++
        }
        $w.WriteLine("X`t$n")
        $w.Close()
    }

    # Attributes, size, times and in-use state of the files in -In (one per line).
    # Output lines: "i<TAB>0" missing, "i<TAB>1<TAB>attrs<TAB>size<TAB>mtime<TAB>ctime<TAB>lock".
    'stat' {
        $w = New-Object IO.StreamWriter($Out, $false, $enc)
        $i = 0
        foreach ($l in [IO.File]::ReadAllLines($In, $enc)) {
            try {
                $f = New-Object IO.FileInfo $l
                if (-not $f.Exists) { $w.WriteLine("$i`t0") }
                else {
                    $a = [int]$f.Attributes
                    $lock = '-'
                    if (-not ($a -band $cloud)) { $lock = Get-LockState $f.FullName }
                    $mt = [long][Math]::Floor(($f.LastWriteTimeUtc - $epoch).TotalSeconds)
                    $ct = [long][Math]::Floor(($f.CreationTimeUtc - $epoch).TotalSeconds)
                    $w.WriteLine("$i`t1`t$a`t" + $f.Length + "`t$mt`t$ct`t$lock")
                }
            } catch { $w.WriteLine("$i`t0") }
            $i++
        }
        $w.Close()
    }

    # Sets creation and modified times: -In lines are "path<TAB>ctime<TAB>mtime" (Unix seconds, 0 = leave).
    'settimes' {
        foreach ($l in [IO.File]::ReadAllLines($In, $enc)) {
            $p = $l.Split("`t")
            try {
                $f = New-Object IO.FileInfo $p[0]
                if ([long]$p[1] -gt 0) { $f.CreationTimeUtc = $epoch.AddSeconds([double]$p[1]) }
                if ([long]$p[2] -gt 0) { $f.LastWriteTimeUtc = $epoch.AddSeconds([double]$p[2]) }
            } catch { }
        }
    }

    # Graphics cards (with dedicated video memory) and installed memory, for the free local option.
    # Win32_VideoController.AdapterRAM stops at 4 GB, so the driver's 64-bit qwMemorySize wins.
    'pcinfo' {
        $ErrorActionPreference = 'SilentlyContinue'
        $cls = 'HKLM:\SYSTEM\CurrentControlSet\Control\Class\{4d36e968-e325-11ce-bfc1-08002be10318}'
        $reg = @(Get-ChildItem $cls | ForEach-Object { $p = Get-ItemProperty $_.PSPath; [pscustomobject]@{ d = [string]$p.DriverDesc; m = $p.'HardwareInformation.qwMemorySize' } })
        $g = @(Get-CimInstance Win32_VideoController | ForEach-Object {
            $n = $_.Name
            $r = $reg | Where-Object { $_.d -eq $n -and $_.m } | Select-Object -First 1
            [pscustomobject]@{ name = $n; vram = [int64]$(if ($r) { $r.m } else { $_.AdapterRAM }) }
        })
        Write-OutFile ([pscustomobject]@{ gpus = $g; ram = [int64](Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory } | ConvertTo-Json -Compress -Depth 3)
    }

    # User profiles: lines "SID<TAB>profile folder" (which user's Recycle Bin a file belongs to).
    'profiles' {
        $ErrorActionPreference = 'SilentlyContinue'
        $lines = foreach ($k in Get-ChildItem 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList') {
            $p = (Get-ItemProperty $k.PSPath -Name ProfileImagePath).ProfileImagePath
            if ($p) { "$($k.PSChildName)`t$p" }
        }
        Write-OutFile (@($lines) -join "`n")
    }

    # Starts a program through ShellExecute (Start-Process), so it inherits none of the web
    # server's handles and can raise the UAC prompt an installer needs. -In is JSON:
    # {"file": "...", "args": ["..."], "hidden": true|false}. An argument with spaces is quoted
    # here (Windows PowerShell joins ArgumentList without quoting); one that already contains
    # quotes (/LOG="C:\some path\x.log") is passed as it is.
    'launch' {
        $j = [IO.File]::ReadAllText($In, $enc) | ConvertFrom-Json
        $p = @{ FilePath = [string]$j.file }
        if ($j.args) {
            $p.ArgumentList = @($j.args | ForEach-Object { $a = [string]$_; if ($a -match '\s' -and $a -notmatch '"') { '"' + $a + '"' } else { $a } })
        }
        if ($j.hidden) { $p.WindowStyle = 'Hidden' }
        try { Start-Process @p }
        catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
    }
}
exit 0
