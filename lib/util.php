<?php
declare(strict_types=1);

const PF_ROOT = __DIR__ . '/..';

/** Where the index, thumbnails, settings and logs live.
 *  - PHOTOFINDER_DATA, if set (tests, unusual setups);
 *  - an installed copy (C:\Program Files\Photo Finder, read-only to the user):
 *    %LOCALAPPDATA%\Photo Finder, so updates and uninstalls never touch the data;
 *  - a source checkout (e.g. under XAMPP): its own data\ folder, as always.
 *  silent-worker.vbs, silent-server.vbs and install.ps1 apply the same rule. */
function pf_data_dir(): string {
    $env = getenv('PHOTOFINDER_DATA');
    if (is_string($env) && trim($env) !== '') return rtrim(str_replace('\\', '/', trim($env)), '/');
    if (is_file(PF_ROOT . '/php/php.exe') && is_file(PF_ROOT . '/unins000.exe')) {
        $local = getenv('LOCALAPPDATA');
        if (is_string($local) && $local !== '') return rtrim(str_replace('\\', '/', $local), '/') . '/Photo Finder';
    }
    return PF_ROOT . '/data';
}
define('PF_DATA', pf_data_dir());
define('PF_THUMBS', PF_DATA . '/thumbs');
define('PF_SETTINGS_FILE', PF_DATA . '/settings.json');
define('PF_DB_FILE', PF_DATA . '/index.sqlite');

// install.ps1 records the PC's timezone in settings (an installed copy cannot edit its own
// php.ini under Program Files), so log timestamps are local time.
(static function (): void {
    $tz = (string)(pf_load_settings()['timezone'] ?? '');
    if ($tz !== '') @date_default_timezone_set($tz);
})();
const PF_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'];

function pf_load_settings(): array {
    $defaults = [
        'photo_dirs' => [],
        'excluded_dirs' => ['Screenshots'],
        // How photos are described: '' until the user chooses in Settings, then
        // 'anthropic' | 'openai' | 'local' (see lib/Describer.php). No key is ever taken
        // from the environment: only what the user types into Settings is used.
        'backend' => 'local',   // the free option on this PC; Settings offers the cloud ones
        'api_key' => '',                                  // Anthropic
        'model' => 'claude-haiku-5-5',
        'openai_base_url' => '',                          // cloud, OpenAI-compatible
        'openai_api_key' => '',
        'openai_model' => '',
        'local_base_url' => 'http://localhost:11434/v1',  // Ollama's default; LM Studio: :1234/v1
        'local_model' => '',
        'max_image_dim' => 1568,
        'batch_size' => 5,
        'exiftool_path' => '',
        'export_batch_size' => 25,
        'export_description' => true,
        'export_tags' => true,
        'export_ocr_text' => true,
        'export_replace_description' => true,
        'export_overwrite_original' => true,
        // Check GitHub for a newer release when the app opens (Settings > Updates).
        'update_check' => true,
    ];
    if (!is_file(PF_SETTINGS_FILE)) return $defaults;
    $raw = file_get_contents(PF_SETTINGS_FILE);
    $data = json_decode($raw, true);
    if (!is_array($data)) return $defaults;
    return array_merge($defaults, $data);
}

function pf_save_settings(array $settings): void {
    if (!is_dir(PF_DATA)) mkdir(PF_DATA, 0777, true);
    file_put_contents(PF_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function pf_norm_path(string $p): string {
    $p = str_replace('\\', '/', $p);
    return rtrim($p, '/');
}

function pf_path_id(string $absPath): string {
    return sha1(strtolower(pf_norm_path($absPath)));
}

/** A photo's own size as it is shown: [width, height] from the file's header, swapped when its
 *  EXIF Orientation turns it a quarter (5..8). Null when the file cannot be read. */
function pf_image_size(string $path): ?array {
    $i = @getimagesize($path);
    if (!$i || $i[0] <= 0 || $i[1] <= 0) return null;
    $o = ($i[2] ?? 0) === IMAGETYPE_JPEG && function_exists('exif_read_data') ? (int)(@exif_read_data($path)['Orientation'] ?? 1) : 1;
    return $o >= 5 && $o <= 8 ? [(int)$i[1], (int)$i[0]] : [(int)$i[0], (int)$i[1]];
}

/** Where "Replace the original" keeps the original: "<name> (original).<ext>" next to it, or
 *  "(original 2)" and so on, never an existing file. Clean up lists these for deleting in bulk. */
function pf_kept_original_path(string $path): string {
    $dir = pf_norm_path(dirname($path));
    $stem = pathinfo($path, PATHINFO_FILENAME);
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    for ($n = 1; $n < 1000; $n++) {
        $cand = $dir . '/' . $stem . ($n === 1 ? ' (original)' : " (original {$n})") . ($ext !== '' ? '.' . $ext : '');
        if (!file_exists($cand)) return $cand;
    }
    throw new RuntimeException('Too many kept originals of this photo already.');
}

function pf_is_image_file(string $path): bool {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, PF_IMAGE_EXTS, true);
}

function pf_path_inside_roots(string $absPath, array $roots): bool {
    $abs = pf_norm_path($absPath);
    foreach ($roots as $root) {
        $r = pf_norm_path($root);
        if ($r === '') continue;
        if (stripos($abs, $r . '/') === 0 || strcasecmp($abs, $r) === 0) return true;
    }
    return false;
}

function pf_json(mixed $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function pf_input_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** TLS trust for outbound HTTPS. The installer's bundled PHP has no CA store of its
 *  own: use the Mozilla bundle shipped beside it, plus the Windows certificate store,
 *  which is the only place a corporate proxy or antivirus HTTPS-scanning root lives. */
function pf_curl_tls(CurlHandle $ch): void {
    $bundle = PF_ROOT . '/php/cacert.pem';
    if (is_file($bundle)) curl_setopt($ch, CURLOPT_CAINFO, $bundle);
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
}

function pf_run_cmd(array $cmd): array {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return [-1, '', 'proc_open failed'];
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [(int)$exit, (string)$stdout, (string)$stderr];
}

/**
 * Runs one action of pf-helper.ps1 (the app's only PowerShell, a file shipped with it).
 * $in is written to a file the helper reads (-In); the helper writes its answer to a file
 * (-Out), which is returned. Nothing but file paths and fixed words goes on the command line:
 * PowerShell code on a command line is what antivirus behaviour shields block.
 * @return array{0:int,1:string,2:string} [exit code, output file contents, stderr]
 */
function pf_helper(string $action, ?string $in = null, array $opts = []): array {
    $dir = PF_DATA . '/helper';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $base = $dir . '/' . $action . '-' . bin2hex(random_bytes(6));
    $win = fn(string $p): string => str_replace('/', '\\', $p);
    $ps = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    $cmd = [$ps, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', $win(PF_ROOT . '/pf-helper.ps1'), '-Action', $action, '-Out', $win($base . '.out')];
    if ($in !== null) {
        file_put_contents($base . '.in', $in);
        array_push($cmd, '-In', $win($base . '.in'));
    }
    foreach ($opts as $k => $v) array_push($cmd, '-' . $k, (string)$v);
    try {
        [$exit, , $err] = pf_run_cmd($cmd);
        $out = is_file($base . '.out') ? (string)file_get_contents($base . '.out') : '';
    } finally {
        @unlink($base . '.in');
        @unlink($base . '.out');
    }
    return [$exit, $out, $err];
}

/** Map of profile-SID => profile directory (forward-slash, no trailing slash),
 *  read once from the Windows ProfileList registry key. Used to figure out
 *  which user's Recycle Bin a given file should go to.
 *
 *  Files are moved into <drive>\$Recycle.Bin\<SID> by this code rather than the
 *  shell's "send to Recycle Bin" API, which needs the user's own session; the
 *  owning user is found from the file's profile path. */
function pf_profile_sids(): array {
    static $profiles = null;
    if ($profiles !== null) return $profiles;
    $profiles = [];
    [, $out] = pf_helper('profiles');
    foreach (preg_split('/\r?\n/', trim((string)$out)) ?: [] as $line) {
        $t = explode("\t", $line, 2);
        if (count($t) === 2 && trim($t[0]) !== '' && trim($t[1]) !== '') {
            $profiles[trim($t[0])] = pf_norm_path(trim($t[1]));
        }
    }
    return $profiles;
}

/** Resolve the interactive-user SID whose profile directory contains $absPath
 *  (longest prefix match), restricted to real user SIDs (S-1-5-21-…). A path in no
 *  profile (D:\Photos, C:\Data...) belongs to the account the server runs as, when
 *  that is a real user (an installed Photo Finder runs as the signed-in user). */
function pf_owner_sid_for_path(string $absPath): ?string {
    $abs = pf_norm_path($absPath);
    $bestSid = null; $bestLen = -1;
    foreach (pf_profile_sids() as $sid => $dir) {
        if ($dir === '' || strncmp($sid, 'S-1-5-21-', 9) !== 0) continue;
        if (stripos($abs, $dir . '/') === 0 && strlen($dir) > $bestLen) {
            $bestSid = $sid; $bestLen = strlen($dir);
        }
    }
    return $bestSid ?? pf_current_user_sid();
}

/** SID of the account this process runs as, if it is a real user (S-1-5-21-…);
 *  null for SYSTEM and service accounts. */
function pf_current_user_sid(): ?string {
    static $sid = false;
    if ($sid !== false) return $sid;
    $sid = null;
    $whoami = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\whoami.exe';
    [$exit, $out] = pf_run_cmd([$whoami, '/user', '/fo', 'csv', '/nh']);
    if ($exit === 0 && preg_match('/"(S-1-5-21-[0-9-]+)"/', $out, $m)) $sid = $m[1];
    return $sid;
}

function pf_recycle_token(): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $s = '';
    for ($i = 0; $i < 6; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $s;
}

/** Move a single file into the owning user's Windows Recycle Bin by writing the
 *  $I metadata + $R data pair directly (the documented Win10 recycle format),
 *  so it shows up in that user's Recycle Bin and can be restored normally.
 *  Returns ['ok' => bool, 'error' => string], plus 'recycled_as' (the $R file) on success. */
function pf_recycle_one(string $path): array {
    if (!is_file($path)) return ['ok' => false, 'error' => 'file not found on disk'];
    $win = str_replace('/', '\\', $path);
    if (!preg_match('/^([A-Za-z]):\\\\/', $win, $m)) {
        return ['ok' => false, 'error' => 'unsupported path (needs a drive letter)'];
    }
    $sid = pf_owner_sid_for_path($path);
    if ($sid === null) {
        return ['ok' => false, 'error' => 'could not determine the user Recycle Bin for this path'];
    }
    $drive = strtoupper($m[1]);
    $binDir = $drive . ':\\$Recycle.Bin\\' . $sid;
    if (!is_dir($binDir) && !@mkdir($binDir, 0777, true) && !is_dir($binDir)) {
        return ['ok' => false, 'error' => 'recycle bin folder unavailable: ' . $binDir];
    }
    $ext = pathinfo($win, PATHINFO_EXTENSION);
    $extPart = $ext !== '' ? '.' . $ext : '';
    $iFile = $rFile = '';
    for ($i = 0; $i < 8; $i++) {
        $token = pf_recycle_token();
        $iCand = $binDir . '\\$I' . $token . $extPart;
        $rCand = $binDir . '\\$R' . $token . $extPart;
        if (!file_exists($iCand) && !file_exists($rCand)) { $iFile = $iCand; $rFile = $rCand; break; }
    }
    if ($iFile === '') return ['ok' => false, 'error' => 'could not allocate a recycle slot'];

    $size = (int)@filesize($path);
    // Windows FILETIME = (unix_seconds + 11644473600) * 10,000,000 (100ns ticks).
    $filetime = (time() + 11644473600) * 10000000;
    $u16 = @iconv('UTF-8', 'UTF-16LE', $win);
    if ($u16 === false) $u16 = mb_convert_encoding($win, 'UTF-16LE', 'UTF-8');
    $wchars = (int)(strlen($u16) / 2) + 1; // original-path length in WCHARs, incl. null
    // $I layout (v2): int64 header(=2), int64 size, int64 deletion FILETIME,
    //                 int32 name length, UTF-16LE path + null terminator.
    $meta = pack('P', 2) . pack('P', $size) . pack('P', $filetime)
          . pack('V', $wchars) . $u16 . "\x00\x00";
    if (@file_put_contents($iFile, $meta) === false) {
        return ['ok' => false, 'error' => 'could not write recycle metadata (permission?)'];
    }
    // Atomic move within the same volume; leaves no half state.
    if (!@rename($path, $rFile)) {
        @unlink($iFile);
        return ['ok' => false, 'error' => 'could not move file into recycle bin'];
    }
    return ['ok' => true, 'error' => '', 'recycled_as' => $rFile];
}

/** Move one or more files to the owning user's Windows Recycle Bin (recoverable).
 *  Returns a map of absolute-path => ['ok' => bool, 'error' => string].
 *  Callers MUST validate each path against the configured roots first. */
function pf_recycle_files(array $paths): array {
    $paths = array_values(array_filter(array_map('strval', $paths), fn($p) => $p !== ''));
    if (!$paths) return [];
    $results = [];
    foreach ($paths as $p) {
        $results[$p] = pf_recycle_one($p);
    }
    return $results;
}

/** Append a single timestamped line to data/worker.log so the UI's
 *  live-log panel can tail it. Safe to call from any process. */
function pf_log(string $msg): void {
    if (!is_dir(PF_DATA)) @mkdir(PF_DATA, 0777, true);
    $stamp = date('Y-m-d H:i:s');
    @file_put_contents(PF_DATA . '/worker.log', "[{$stamp}] {$msg}\n", FILE_APPEND);
}

/** Read the last N lines of data/worker.log without slurping the whole file. */
function pf_log_tail(int $lines = 200, int $maxBytes = 200_000): array {
    $f = PF_DATA . '/worker.log';
    if (!is_file($f)) return [];
    $size = filesize($f) ?: 0;
    $offset = max(0, $size - $maxBytes);
    $fh = @fopen($f, 'rb');
    if (!$fh) return [];
    if ($offset > 0) fseek($fh, $offset);
    $blob = stream_get_contents($fh) ?: '';
    fclose($fh);
    // Drop the leading partial line if we seeked into the middle of one
    if ($offset > 0) {
        $nl = strpos($blob, "\n");
        if ($nl !== false) $blob = substr($blob, $nl + 1);
    }
    $arr = preg_split('/\r?\n/', rtrim($blob, "\r\n")) ?: [];
    return array_slice($arr, -$lines);
}

function pf_mime_for_ext(string $ext): string {
    return match (strtolower($ext)) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'tif', 'tiff' => 'image/tiff',
        default => 'application/octet-stream',
    };
}
