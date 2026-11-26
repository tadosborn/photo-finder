# Opened by the PhotoFinderLogin scheduled task when the web UI's "Re-authenticate" button
# is clicked. Finds the Claude CLI now rather than at install time: VS Code deletes the old
# extension folder on every update, so a path saved at install goes stale within weeks.
$ErrorActionPreference = 'Stop'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$php = Join-Path $dir 'php\php.exe'
if (-not (Test-Path $php)) { $php = 'C:\xampp\php\php.exe' }
$Host.UI.RawUI.WindowTitle = 'Photo Finder - Claude sign-in'

# Same detection the indexer uses (lib/ClaudeCli.php), so both always agree.
# (No double quotes inside -r code: Windows PowerShell 5.1 does not escape them for native programs.)
$code = 'require ''lib/util.php''; require ''lib/ClaudeCli.php''; echo ClaudeCli::resolvePath(pf_load_settings()[''cli_path''] ?? '''') ?? '''';'
Push-Location $dir
try { $claude = (& $php -r $code) -join '' } finally { Pop-Location }

Write-Host ''
Write-Host 'Photo Finder re-authentication'
Write-Host '------------------------------'
if (-not $claude -or -not (Test-Path $claude)) {
    Write-Host 'Claude Code was not found on this PC.' -ForegroundColor Yellow
    Write-Host 'Install it from https://claude.com/claude-code (or switch Photo Finder to the API backend in Settings),'
    Write-Host 'then click Re-authenticate again.'
    Read-Host 'Press Enter to close'
    exit 1
}
Write-Host "Using $claude"
Write-Host 'Complete the sign-in in the browser that opens, then close this window.'
Write-Host ''
& $claude /login
