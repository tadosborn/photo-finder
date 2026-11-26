# Builds the Photo Finder Windows installer.
#
#   pwsh ./build.ps1 [-SignCommand '<command with $f>']
#
# Output: dist\PhotoFinderSetup-<version>.exe and its .sha256. The version comes from
# lib/version.php (PF_VERSION). GitHub Actions runs exactly this (.github/workflows/build.yml);
# official releases are then Authenticode-signed by SignPath and published with an Ed25519-signed
# update feed by the maintainer (the update signing key is never in this repository).
#
# Everything bundled is downloaded from its publisher and verified before use:
#   PHP 8.3 NTS x64 (windows.php.net sha256), ExifTool (exiftool.org sha256),
#   Mozilla CA bundle (curl.se sha256). The Microsoft VC++ runtime is NOT bundled: Setup downloads
#   it from Microsoft only on a PC that lacks it, and checks Microsoft's signature first.
#
# -SignCommand: optional Authenticode signing during compile (setup AND uninstaller), passed to
#   Inno Setup as a SignTool; $f is replaced with the file to sign, $q with a double quote.
param(
    [string]$PhpBranch = '8.3',
    [string]$SignCommand = ''
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$src   = $PSScriptRoot
$dist  = Join-Path $src 'dist'
$cache = Join-Path $dist '.cache'
$stage = Join-Path $dist '.stage'
$iscc = @("$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe", "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
          "$env:ProgramFiles\Inno Setup 6\ISCC.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $iscc) { throw 'Inno Setup 6 not found (winget install JRSoftware.InnoSetup, or choco install innosetup).' }

$verLine = Select-String -Path (Join-Path $src 'lib\version.php') -Pattern "const PF_VERSION = '([0-9][0-9A-Za-z.\-]*)';"
if (-not $verLine) { throw 'PF_VERSION not found in lib/version.php' }
$version = $verLine.Matches[0].Groups[1].Value
Write-Host "== Photo Finder $version =="
New-Item -ItemType Directory -Force -Path $cache, $dist | Out-Null

function Get-Verified([string]$url, [string]$name, [string]$sha256) {
    # Each download lands in its own folder under the cache, keyed by file name.
    $dir = Join-Path $cache ($name -replace '[^A-Za-z0-9._-]', '_')
    $file = Join-Path $dir $name
    if ((Test-Path $file) -and $sha256 -and ((Get-FileHash $file -Algorithm SHA256).Hash -eq $sha256.ToUpper())) { return $file }
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    Write-Host "  downloading $url"
    Invoke-WebRequest -Uri $url -OutFile $file -UseBasicParsing -UserAgent 'Wget/1.21 (PhotoFinder build)'
    if ($sha256) {
        $got = (Get-FileHash $file -Algorithm SHA256).Hash
        if ($got -ne $sha256.ToUpper()) { Remove-Item $file -Force; throw "sha256 mismatch for $name (want $sha256, got $got)" }
        Write-Host "  sha256 OK $name"
    }
    return $file
}

# ---- 1. dependencies ----
Write-Host '[1/4] Dependencies'
$rel = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json'
$phpRel = $rel.$PhpBranch
$phpZipInfo = $phpRel.'nts-vs16-x64'.zip
if (-not $phpZipInfo) { $phpZipInfo = $phpRel.'nts-vs17-x64'.zip }
if (-not $phpZipInfo) { throw "No NTS x64 build listed for PHP $PhpBranch" }
$phpZip = Get-Verified "https://windows.php.net/downloads/releases/$($phpZipInfo.path)" $phpZipInfo.path $phpZipInfo.sha256
Write-Host "  PHP $($phpRel.version)"

$exifVer = (Invoke-RestMethod 'https://exiftool.org/ver.txt').ToString().Trim()
$exifName = "exiftool-${exifVer}_64.zip"
$sums = (Invoke-WebRequest 'https://exiftool.org/checksums.txt' -UseBasicParsing).Content
if ($sums -is [byte[]]) { $sums = [Text.Encoding]::ASCII.GetString($sums) }
$m = [regex]::Match($sums, "SHA2-256\($([regex]::Escape($exifName))\)\s*=\s*([0-9a-fA-F]{64})")
if (-not $m.Success) { throw "No sha256 for $exifName in exiftool.org/checksums.txt" }
# exiftool.org hosts the Windows zip on SourceForge; downloads.sourceforge.net redirects straight to
# a mirror. The sha256 comes from exiftool.org itself, so the mirror is not trusted for content.
$exifZip = Get-Verified "https://downloads.sourceforge.net/project/exiftool/$exifName" $exifName $m.Groups[1].Value
Write-Host "  ExifTool $exifVer"

$caRaw = (Invoke-WebRequest 'https://curl.se/ca/cacert.pem.sha256' -UseBasicParsing).Content
if ($caRaw -is [byte[]]) { $caRaw = [Text.Encoding]::ASCII.GetString($caRaw) }   # served as octet-stream
$caSha = ($caRaw.Trim() -split '\s+')[0]
if ($caSha -notmatch '^[0-9a-fA-F]{64}$') { throw 'unexpected cacert.pem.sha256 content' }
$caFile = Get-Verified 'https://curl.se/ca/cacert.pem' 'cacert.pem' $caSha


# ---- 2. stage ----
Write-Host '[2/4] Staging'
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
$app = Join-Path $stage 'app'
New-Item -ItemType Directory -Force -Path $app, (Join-Path $app 'lib'), (Join-Path $app 'bin') | Out-Null

$appFiles = 'index.php', 'api.php', 'file.php', 'thumb.php', 'worker.php', 'router.php', 'configure.php',
            'install.ps1', 'uninstall.ps1', 'install.bat', 'uninstall.bat',
            'start.bat', 'worker.bat', 'silent-worker.vbs', 'silent-server.vbs', 'launch.vbs',
            'LICENSE', 'PRIVACY.md', 'THIRD-PARTY-NOTICES.md'
foreach ($f in $appFiles) { Copy-Item (Join-Path $src $f) (Join-Path $app $f) }
Copy-Item (Join-Path $src 'lib\*.php') (Join-Path $app 'lib')

$phpDir = Join-Path $app 'php'
Expand-Archive -Path $phpZip -DestinationPath $phpDir
# Not needed at runtime: debugger, CGI, dev headers, ini templates, ICU (~30 MB, intl only).
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue (Join-Path $phpDir 'dev'), (Join-Path $phpDir 'phpdbg*'),
    (Join-Path $phpDir 'php-cgi.exe'), (Join-Path $phpDir 'php.ini-*'), (Join-Path $phpDir 'icu*.dll'),
    (Join-Path $phpDir 'ext\php_intl.dll')
Copy-Item $caFile (Join-Path $phpDir 'cacert.pem')
@'
[PHP]
; Photo Finder's bundled PHP. Written by build.ps1. The timezone comes from the app's settings
; (set by install.ps1); outbound TLS trust is set per request in pf_curl_tls()
; (php\cacert.pem plus the Windows certificate store).
engine = On
memory_limit = 512M
max_execution_time = 600
error_reporting = E_ALL & ~E_DEPRECATED
; Errors go to stderr (server.log, worker-stderr.log), never into a JSON response.
display_errors = Off
display_startup_errors = Off
log_errors = On
extension_dir = "ext"
extension=curl
extension=exif
extension=fileinfo
extension=gd
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sodium

[Date]
date.timezone = "UTC"
'@ | Set-Content -Path (Join-Path $phpDir 'php.ini') -Encoding ascii

$exifTmp = Join-Path $stage 'exiftool'
Expand-Archive -Path $exifZip -DestinationPath $exifTmp
$exifRoot = Get-ChildItem $exifTmp -Directory | Select-Object -First 1
Copy-Item (Join-Path $exifRoot.FullName 'exiftool(-k).exe') (Join-Path $app 'bin\exiftool.exe')
Copy-Item (Join-Path $exifRoot.FullName 'exiftool_files') (Join-Path $app 'bin\exiftool_files') -Recurse
$readme = Get-ChildItem $exifRoot.FullName -Filter 'README*' | Select-Object -First 1
if ($readme) { Copy-Item $readme.FullName (Join-Path $app 'bin\exiftool_README.txt') }
Remove-Item $exifTmp -Recurse -Force

$php = Join-Path $phpDir 'php.exe'   # the bundled PHP (it has GD) draws the icon
& $php (Join-Path $src 'installer\make-icon.php') (Join-Path $app 'photofinder.ico')
if ($LASTEXITCODE -ne 0) { throw 'icon generation failed' }

# ---- 3. prove the staged runtime works from an unrelated working directory ----
Write-Host '[3/4] Checking the bundled runtime'
$probe = 'foreach ([''pdo_sqlite'',''gd'',''exif'',''mbstring'',''curl'',''sodium'',''simplexml'',''openssl''] as $e) if (!extension_loaded($e)) echo ''missing:'', $e, '' ''; $p = new PDO(''sqlite::memory:''); $p->exec(''create virtual table t using fts5(x)''); echo ''ok php '', PHP_VERSION, '' sqlite '', $p->query(''select sqlite_version()'')->fetchColumn();'
Push-Location $env:SystemRoot
try { $out = (& $php -r $probe 2>&1) -join ' ' } finally { Pop-Location }
if ($LASTEXITCODE -ne 0 -or $out -notmatch '^ok php' -or $out -match 'missing:|Warning') { throw "bundled PHP check failed: $out" }
Write-Host "  $out"
$exifOut = (& (Join-Path $app 'bin\exiftool.exe') -ver 2>&1) -join ' '
if ($exifOut.Trim() -ne $exifVer) { throw "bundled ExifTool check failed: $exifOut" }
Write-Host "  exiftool $exifOut"
foreach ($f in Get-ChildItem $app -Recurse -Include *.php) {
    $lint = & $php -l $f.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { throw "lint failed: $lint" }
}
Write-Host '  PHP lint OK'

# ---- 4. compile ----
Write-Host '[4/4] Compiling installer'
$exeName = "PhotoFinderSetup-$version.exe"
$exe = Join-Path $dist $exeName
if (Test-Path $exe) { Remove-Item $exe -Force }
$isccArgs = @('/Q', "/DAppVersion=$version", "/DStageDir=$stage", "/DOutDir=$dist")
if ($SignCommand) { $isccArgs += '/DSignTool', "/Spfsign=$SignCommand" }
& $iscc @isccArgs (Join-Path $src 'installer\PhotoFinder.iss')
if ($LASTEXITCODE -ne 0 -or -not (Test-Path $exe)) { throw "ISCC failed ($LASTEXITCODE)" }
$sha = (Get-FileHash $exe -Algorithm SHA256).Hash.ToLower()
"$sha  $exeName" | Set-Content -Path "$exe.sha256" -Encoding ascii -NoNewline
$as = Get-AuthenticodeSignature $exe
$signedBy = if ($as.SignerCertificate) { $as.SignerCertificate.GetNameInfo('SimpleName', $false) } else { 'not Authenticode-signed' }
Write-Host ("  {0} ({1:N1} MB) sha256 {2} ({3})" -f $exeName, ((Get-Item $exe).Length / 1MB), $sha, $signedBy)
