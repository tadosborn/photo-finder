<?php
declare(strict_types=1);

// Installer helper (command line only): writes the photo folders chosen in the setup
// wizard into settings.json (in the data folder, see pf_data_dir()) through the app's
// own settings code, records the timezone, and moves settings from earlier versions forward.
//
//   php configure.php [--photo-dirs-file=<UTF-8 text, one folder per line>] [--timezone=<IANA id>]
//                     [--update-check=0|1]
//
// Without --photo-dirs-file the folder list is left exactly as it is, which is what a
// silent update does.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/lib/util.php';

$opts = getopt('', ['photo-dirs-file:', 'timezone:', 'update-check:']);
$settings = pf_load_settings();
$changed = [];

if (isset($opts['update-check'])) {
    $on = (string)$opts['update-check'] === '1';
    if ((bool)($settings['update_check'] ?? true) !== $on) $changed[] = 'automatic update checks: ' . ($on ? 'on' : 'off');
    $settings['update_check'] = $on;
}

if (isset($opts['timezone'])) {
    $tz = (string)$opts['timezone'];
    if (in_array($tz, timezone_identifiers_list(), true) && ($settings['timezone'] ?? '') !== $tz) {
        $settings['timezone'] = $tz;
        $changed[] = 'timezone: ' . $tz;
    }
}

if (isset($opts['photo-dirs-file'])) {
    $raw = @file_get_contents((string)$opts['photo-dirs-file']);
    if ($raw === false) { fwrite(STDERR, "Cannot read the folder list file.\n"); exit(2); }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;   // tolerate a UTF-8 BOM
    $dirs = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
        $d = pf_norm_path(trim($line));
        if ($d === '') continue;
        $key = strtolower($d);
        if (!isset($dirs[$key])) $dirs[$key] = $d;
    }
    $settings['photo_dirs'] = array_values($dirs);
    $changed[] = 'photo folders: ' . (count($dirs) ? implode('; ', $dirs) : '(none)');
    foreach ($dirs as $d) {
        if (!is_dir($d)) echo "Note: folder does not exist yet: {$d}\n";
    }
}

// ---- settings from earlier versions ----
// 1.0.3 removed the Claude Code backend: Anthropic does not allow third-party apps to run
// work through people's Claude subscriptions (use an API key instead). Those copies now
// have no provider chosen, and the app asks the user to pick one.
if (($settings['backend'] ?? '') === 'cli') {
    $settings['backend'] = '';
    $changed[] = 'Claude Code option removed: choose how photos are described in Settings';
}
if (($settings['backend'] ?? '') === 'api') {
    $settings['backend'] = 'anthropic';
    $changed[] = 'backend: anthropic';
}
if (in_array($settings['model'] ?? '', ['', 'claude-haiku-4-5-20251001'], true)) {
    $settings['model'] = 'claude-haiku-5-5';     // the old default; a deliberate choice is kept
    $changed[] = 'Anthropic model: claude-haiku-5-5';
}
unset($settings['cli_path'], $settings['cli_model']);

pf_save_settings($settings);
echo $changed ? 'Updated ' . implode(', ', $changed) . "\n" : "Settings unchanged.\n";
