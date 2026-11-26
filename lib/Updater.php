<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/version.php';

/**
 * Update check and self-install for copies installed with PhotoFinderSetup.exe.
 *
 * The feed is the same XML shape as the Joomla update feeds on the InfiNet update
 * server: <updates><update><version/><sha256/><downloads><downloadurl/>...</update>.
 * A copy running from a source checkout (e.g. under XAMPP) can still check, but it
 * is told to update from source instead of being offered the installer.
 */
final class Updater {
    private const CACHE_FILE = PF_DATA . '/update-check.json';
    private const CACHE_TTL = 3600;          // seconds between automatic checks
    private const DOWNLOAD_DIR = PF_DATA . '/updates';

    /** True when this copy was laid down by the installer (bundled PHP + uninstaller). */
    public static function isInstalled(): bool {
        return is_file(PF_ROOT . '/php/php.exe') && is_file(PF_ROOT . '/unins000.exe');
    }

    public static function feedUrl(): string {
        $s = pf_load_settings();
        $u = trim((string)($s['update_feed_url'] ?? ''));
        return $u !== '' ? $u : PF_UPDATE_FEED;
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
            $feed = self::fetchFeed();
            $result['latest'] = $feed['version'];
            $newer = version_compare($feed['version'], PF_VERSION, '>');
            $file = basename((string)(parse_url($feed['url'], PHP_URL_PATH) ?? ''));
            if ($newer && !self::signatureValid($feed['version'], $feed['sha256'], $file, $feed['signature'])) {
                // Never advertise what install() would refuse.
                $result['error'] = 'Version ' . $feed['version'] . ' on the update server is not signed by InfiNet; ignoring it.';
            } else {
                $result['available'] = $newer;
                $result['notes'] = $feed['notes'];
            }
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        if (!is_dir(PF_DATA)) mkdir(PF_DATA, 0777, true);
        file_put_contents(self::CACHE_FILE, json_encode($result, JSON_UNESCAPED_SLASHES));
        return $result;
    }

    /**
     * Downloads the advertised installer, verifies its sha256 against the feed, and
     * starts it detached in silent mode. The installer stops this server and the
     * worker, replaces the program files (data/ is never touched), and starts both
     * again. The caller should poll `version` until it reports the new number.
     */
    public static function install(): array {
        if (!self::isInstalled()) {
            throw new RuntimeException('This copy runs from source, not from the installer. Update it from the source repository.');
        }
        $feed = self::fetchFeed();
        if (!version_compare($feed['version'], PF_VERSION, '>')) {
            throw new RuntimeException('Already up to date (' . PF_VERSION . ').');
        }
        $url = $feed['url'];
        $feedParts = parse_url(self::feedUrl());
        $dlParts = parse_url($url);
        // The installer must come from the same server as the feed that vouches for it.
        if (!$dlParts || strtolower($dlParts['host'] ?? '') !== strtolower($feedParts['host'] ?? '')
            || ($dlParts['scheme'] ?? '') !== ($feedParts['scheme'] ?? '')) {
            throw new RuntimeException('Update download is not on the feed server: ' . $url);
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $feed['sha256'])) {
            throw new RuntimeException('Update feed has no sha256 for the installer; refusing to run it.');
        }
        // The feed is only trusted as far as its signature: the server that hosts it
        // could be the thing that was compromised.
        if (!self::signatureValid($feed['version'], $feed['sha256'], basename((string)($dlParts['path'] ?? '')), $feed['signature'])) {
            throw new RuntimeException('Update ' . $feed['version'] . ' is not signed by InfiNet; refusing to install it.');
        }

        if (!is_dir(self::DOWNLOAD_DIR)) mkdir(self::DOWNLOAD_DIR, 0777, true);
        foreach (glob(self::DOWNLOAD_DIR . '/*.exe') ?: [] as $old) @unlink($old);
        $dest = self::DOWNLOAD_DIR . '/PhotoFinderSetup-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $feed['version']) . '.exe';

        [$status, $body] = self::httpGet($url, 300);
        if ($status !== 200 || $body === '') {
            throw new RuntimeException("Download failed (HTTP {$status}).");
        }
        $got = hash('sha256', $body);
        if (!hash_equals($feed['sha256'], $got)) {
            throw new RuntimeException('Downloaded installer does not match the feed checksum; not running it.');
        }
        if (file_put_contents($dest, $body) === false) {
            throw new RuntimeException('Could not save the installer to ' . $dest);
        }

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
        pf_log('Update to ' . $feed['version'] . ' started (installer ' . basename($dest) . ').');
        @unlink(self::CACHE_FILE);
        return ['ok' => true, 'version' => $feed['version']];
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

    /** @return array{version:string,url:string,sha256:string,signature:string,notes:string} */
    private static function fetchFeed(): array {
        $feedUrl = self::feedUrl();
        $parts = parse_url($feedUrl);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if ($scheme !== 'https' && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('Update feed must be https.');
        }
        // Cache-buster: the feed sits behind Cloudflare, which would otherwise serve a
        // stale copy for a while after a release. (Safe here; it is only the Joomla
        // updater that names downloads after the URL.)
        $sep = str_contains($feedUrl, '?') ? '&' : '?';
        [$status, $body] = self::httpGet($feedUrl . $sep . 't=' . time(), 20);
        if ($status !== 200) throw new RuntimeException("Update feed returned HTTP {$status}.");

        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if (!$xml || !isset($xml->update)) throw new RuntimeException('Update feed is not valid XML.');

        $best = null;
        foreach ($xml->update as $u) {
            $v = trim((string)$u->version);
            if ($v === '') continue;
            if ($best === null || version_compare($v, $best['version'], '>')) {
                $best = [
                    'version' => $v,
                    'url' => trim((string)($u->downloads->downloadurl ?? '')),
                    'sha256' => strtolower(trim((string)$u->sha256)),
                    'signature' => trim((string)($u->signature ?? '')),
                    'notes' => trim((string)($u->description ?? '')),
                ];
            }
        }
        if ($best === null || $best['url'] === '') throw new RuntimeException('Update feed lists no version.');
        return $best;
    }

    /** @return array{0:int,1:string} */
    private static function httpGet(string $url, int $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'PhotoFinder/' . PF_VERSION,
        ]);
        pf_curl_tls($ch);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Could not reach the update server: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string)$body];
    }
}
