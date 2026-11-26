<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/version.php';

/**
 * Update check and self-install for copies installed with PhotoFinderSetup.exe.
 *
 * Source: the latest GitHub release of this repository (PF_UPDATE_SOURCE). A release counts
 * only if it carries `photofinder-release.json`, a manifest signed with the maintainer's offline
 * Ed25519 key:  {"version", "file", "sha256", "signature", "notes"}. The app checks that
 * signature against PF_UPDATE_KEYS, downloads the named installer from the same release and
 * checks its sha256, so GitHub (or anyone who could alter the release) cannot slip in an
 * installer the maintainer did not sign.
 *
 * A copy running from a source checkout can still check, but it is told to update from source
 * instead of being offered the installer.
 */
final class Updater {
    public const MANIFEST = 'photofinder-release.json';
    private const CACHE_FILE = PF_DATA . '/update-check.json';
    private const CACHE_TTL = 3600;          // seconds between automatic checks
    private const DOWNLOAD_DIR = PF_DATA . '/updates';

    /** True when this copy was laid down by the installer (bundled PHP + uninstaller). */
    public static function isInstalled(): bool {
        return is_file(PF_ROOT . '/php/php.exe') && is_file(PF_ROOT . '/unins000.exe');
    }

    /** The GitHub "latest release" API address (Settings key update_feed_url overrides it for tests). */
    public static function sourceUrl(): string {
        $s = pf_load_settings();
        $u = trim((string)($s['update_feed_url'] ?? ''));
        return $u !== '' ? $u : PF_UPDATE_SOURCE;
    }

    /** Returns the check result; uses a cached copy younger than an hour unless $force. */
    public static function check(bool $force = false): array {
        if (!$force && is_file(self::CACHE_FILE)) {
            $cached = json_decode((string)file_get_contents(self::CACHE_FILE), true);
            if (is_array($cached) && (time() - (int)($cached['checked_at'] ?? 0)) < self::CACHE_TTL
                && ($cached['current'] ?? '') === PF_VERSION) {
                return $cached;
            }
        }
        $result = [
            'current' => PF_VERSION,
            'installed' => self::isInstalled(),
            'checked_at' => time(),
            'available' => false,
            'latest' => null,
            'notes' => '',
            'error' => null,
        ];
        try {
            $rel = self::latestRelease();
            $result['latest'] = $rel['version'];
            $result['available'] = version_compare($rel['version'], PF_VERSION, '>');
            $result['notes'] = $rel['notes'];
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        if (!is_dir(PF_DATA)) mkdir(PF_DATA, 0777, true);
        file_put_contents(self::CACHE_FILE, json_encode($result, JSON_UNESCAPED_SLASHES));
        return $result;
    }

    /**
     * Downloads the latest signed release's installer and verifies it. Returns its local path.
     * Nothing is run here (install() does that), so this is also what the tests exercise.
     */
    public static function prepareDownload(): array {
        $rel = self::latestRelease();
        if (!version_compare($rel['version'], PF_VERSION, '>')) {
            throw new RuntimeException('Already up to date (' . PF_VERSION . ').');
        }
        if (!is_dir(self::DOWNLOAD_DIR)) mkdir(self::DOWNLOAD_DIR, 0777, true);
        foreach (glob(self::DOWNLOAD_DIR . '/*.exe') ?: [] as $old) @unlink($old);
        $dest = self::DOWNLOAD_DIR . '/' . $rel['file'];

        [$status, $body] = self::httpGet($rel['installer_url'], 300, true, ['Accept: application/octet-stream']);
        if ($status !== 200 || $body === '') {
            throw new RuntimeException("Download failed (HTTP {$status}).");
        }
        if (!hash_equals($rel['sha256'], hash('sha256', $body))) {
            throw new RuntimeException('The downloaded installer does not match the signed checksum; not running it.');
        }
        if (file_put_contents($dest, $body) === false) {
            throw new RuntimeException('Could not save the installer to ' . $dest);
        }
        return ['path' => $dest, 'version' => $rel['version']];
    }

    /**
     * Downloads and verifies the update, then starts it detached in silent mode. The installer
     * stops this server and the worker, replaces the program files (the data folder is never
     * touched), and starts both again. The caller should poll `version` until it changes.
     */
    public static function install(): array {
        if (!self::isInstalled()) {
            throw new RuntimeException('This copy runs from source, not from the installer. Update it from the source repository.');
        }
        $dl = self::prepareDownload();
        $dest = $dl['path'];

        // Launched through ShellExecute (PowerShell's Start-Process), never as a child of
        // this server. A child inherits the server's handles, including its listening socket
        // on 8765 and its log file: the installer then holds the port open without answering
        // and the restarted server cannot open its log, so it never comes back (seen in
        // testing). ShellExecute passes no handles, and it is also what raises the UAC
        // prompt that a Program Files install needs. The short-lived PowerShell in between
        // inherits them but exits as soon as the installer has started.
        $log = PF_DATA . '/update-install.log';
        $launchErr = PF_DATA . '/update-launch.txt';
        $win = fn(string $p): string => str_replace('/', '\\', $p);           // paths only, never the switches
        $q = fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";  // PowerShell single-quoted literal
        $ps = '$ErrorActionPreference = "Stop"; $ProgressPreference = "SilentlyContinue";'
            . ' try { Start-Process -FilePath ' . $q($win($dest))
            . ' -ArgumentList @("/SILENT", "/SUPPRESSMSGBOXES", "/NORESTART", ' . $q('/LOG="' . $win($log) . '"') . ')'
            . ' } catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }';
        $cmd = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand '
             . base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
        $p = proc_open($cmd, [0 => ['file', 'NUL', 'r'], 1 => ['file', $launchErr, 'w'], 2 => ['file', $launchErr, 'a']],
                       $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) throw new RuntimeException('Could not start the installer.');
        $exit = proc_close($p);   // returns once Windows has started it (after the UAC answer)
        $err = trim((string)@file_get_contents($launchErr));
        if ($exit !== 0) {
            if (stripos($err, 'cancel') !== false) throw new RuntimeException('Update cancelled: Windows permission was not given.');
            throw new RuntimeException("Could not start the installer (exit {$exit})" . ($err !== '' ? ': ' . $err : '.'));
        }
        pf_log('Update to ' . $dl['version'] . ' started (installer ' . basename($dest) . ').');
        @unlink(self::CACHE_FILE);
        return ['ok' => true, 'version' => $dl['version']];
    }

    /** The exact bytes a release signature covers. installer/sign-release.php signs the same. */
    public static function signedMessage(string $version, string $sha256, string $fileName): string {
        return "photofinder\n{$version}\n" . strtolower($sha256) . "\n{$fileName}";
    }

    public static function signatureValid(string $version, string $sha256, string $fileName, string $sigB64): bool {
        if (!function_exists('sodium_crypto_sign_verify_detached')) return false;
        $sig = base64_decode($sigB64, true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) return false;
        $msg = self::signedMessage($version, $sha256, $fileName);
        foreach (PF_UPDATE_KEYS as $k) {
            $pk = base64_decode($k, true);
            if ($pk !== false && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($sig, $msg, $pk)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The latest release, accepted only with a valid signed manifest.
     * @return array{version:string,file:string,sha256:string,notes:string,installer_url:string}
     */
    private static function latestRelease(): array {
        $src = self::sourceUrl();
        $p = parse_url($src);
        $host = strtolower((string)($p['host'] ?? ''));
        if (strtolower((string)($p['scheme'] ?? '')) !== 'https' && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('The update source must be https.');
        }
        [$status, $body] = self::httpGet($src, 20, false, ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28']);
        if ($status === 404) throw new RuntimeException('No published release found on GitHub yet.');
        if ($status === 403 || $status === 429) throw new RuntimeException('GitHub is limiting update checks from this network right now; try again later.');
        if ($status !== 200) throw new RuntimeException("GitHub returned HTTP {$status} for the update check.");
        $rel = json_decode($body, true);
        if (!is_array($rel)) throw new RuntimeException('GitHub returned an unreadable release.');

        $tagVersion = ltrim((string)($rel['tag_name'] ?? ''), 'vV');
        $assets = [];
        foreach ((array)($rel['assets'] ?? []) as $a) {
            if (isset($a['name'], $a['browser_download_url'])) $assets[(string)$a['name']] = (string)$a['browser_download_url'];
        }
        if (!isset($assets[self::MANIFEST])) {
            throw new RuntimeException("Release {$tagVersion} has no signed manifest yet; it will be offered once it is signed.");
        }
        [$ms, $mbody] = self::httpGet($assets[self::MANIFEST], 20, true, ['Accept: application/octet-stream']);
        if ($ms !== 200) throw new RuntimeException("Could not read the release manifest (HTTP {$ms}).");
        $m = json_decode($mbody, true);
        $version = trim((string)($m['version'] ?? ''));
        $file = trim((string)($m['file'] ?? ''));
        $sha = strtolower(trim((string)($m['sha256'] ?? '')));
        $sig = trim((string)($m['signature'] ?? ''));
        if ($version === '' || !preg_match('/^[A-Za-z0-9._-]+\.exe$/', $file) || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new RuntimeException('The release manifest is incomplete.');
        }
        // The manifest is trusted only as far as its signature: GitHub, or whoever could alter
        // the release, is exactly what this protects against.
        if (!self::signatureValid($version, $sha, $file, $sig)) {
            throw new RuntimeException("Release {$version} is not signed by the Photo Finder maintainer; ignoring it.");
        }
        if ($tagVersion !== '' && $tagVersion !== $version) {
            throw new RuntimeException("Release tag {$tagVersion} does not match its signed manifest ({$version}); ignoring it.");
        }
        if (!isset($assets[$file])) throw new RuntimeException("Release {$version} is missing its installer ({$file}).");
        return [
            'version' => $version,
            'file' => $file,
            'sha256' => $sha,
            'notes' => trim((string)($m['notes'] ?? '')),
            'installer_url' => $assets[$file],
        ];
    }

    /**
     * GET with optional redirects (GitHub serves release files from a separate host). Only
     * https redirects are followed, except for a local test server.
     * @return array{0:int,1:string}
     */
    private static function httpGet(string $url, int $timeout, bool $follow = false, array $headers = []): array {
        $local = in_array(strtolower((string)(parse_url($url, PHP_URL_HOST) ?? '')), ['127.0.0.1', 'localhost'], true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'PhotoFinder/' . PF_VERSION,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, $local ? 'http,https' : 'https');
        } elseif (defined('CURLOPT_REDIR_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, $local ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS);
        }
        pf_curl_tls($ch);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new RuntimeException('Could not reach the update source: ' . $err);
        }
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)$body];
    }
}
