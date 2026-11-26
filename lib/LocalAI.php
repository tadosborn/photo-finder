<?php
declare(strict_types=1);

/**
 * The free "on this PC" option: checks whether this PC can run a local vision model, finds
 * Ollama and its models, and downloads a recommended model.
 *
 * Nothing here sends anything off the PC except Ollama's own model download, which the user
 * starts with a button. The recommended models are small vision models from Ollama's library;
 * which one depends on the graphics card.
 */
final class LocalAI {
    /** Recommended Ollama models: a bigger one for PCs with a real graphics card, a small one otherwise. */
    public const RECOMMENDED = [
        'gpu' => ['model' => 'bonsai-2:9b', 'size' => '3.2 GB'],
        'cpu' => ['model' => 'bonsai-2:4b', 'size' => '1.6 GB'],
    ];
    /** Dedicated video memory from which the graphics card, not the processor, does the work. */
    private const GPU_MIN_BYTES = 6 * 1024 ** 3;
    private const PC_CACHE = PF_DATA . '/pc-check.json';
    public const PULL_FILE = PF_DATA . '/model-pull.json';

    /** Graphics cards and memory, cached for a day (it takes PowerShell a second or two). */
    public static function pcInfo(bool $refresh = false): array {
        if (!$refresh && is_file(self::PC_CACHE) && filemtime(self::PC_CACHE) > time() - 86400) {
            $c = json_decode((string)file_get_contents(self::PC_CACHE), true);
            if (is_array($c)) return $c;
        }
        [$exit, $out] = pf_helper('pcinfo');   // pf-helper.ps1: graphics cards and memory
        $j = json_decode(trim((string)$out), true);
        $gpus = [];
        foreach ((array)($j['gpus'] ?? []) as $g) {
            $name = trim((string)($g['name'] ?? ''));
            // Remote-desktop and fallback display drivers are not graphics cards.
            if ($name === '' || preg_match('/Remote Display|Basic Display|Basic Render|Hyper-V|Virtual/i', $name)) continue;
            $gpus[] = ['name' => $name, 'vram' => max(0, (int)($g['vram'] ?? 0))];
        }
        $info = ['gpus' => $gpus, 'ram' => (int)($j['ram'] ?? 0), 'checked' => time(), 'ok' => $exit === 0 && is_array($j)];
        @file_put_contents(self::PC_CACHE, json_encode($info));
        return $info;
    }

    /** 'gpu' when a graphics card with enough dedicated memory is present, else 'cpu'. */
    public static function tier(array $pc): string {
        foreach ($pc['gpus'] as $g) {
            // Integrated graphics (Intel UHD/Iris, AMD Radeon Graphics on an APU) share system memory.
            $integrated = (bool)preg_match('/Intel\(R\) (UHD|HD|Iris)|AMD Radeon\(TM\) Graphics|Radeon Graphics$/i', $g['name']);
            if (!$integrated && $g['vram'] >= self::GPU_MIN_BYTES) return 'gpu';
        }
        return 'cpu';
    }

    /** Ollama's native API address from the configured OpenAI-compatible one (".../v1"). */
    public static function nativeBase(string $openAiBase): string {
        return preg_replace('~/v1/?$~', '', rtrim(trim($openAiBase), '/')) ?: 'http://localhost:11434';
    }

    /** Is Ollama running here, is it installed, and which models does it have (with vision support)? */
    public static function ollama(string $openAiBase): array {
        $base = self::nativeBase($openAiBase);
        $local = (string)getenv('LOCALAPPDATA');
        $exe = $local !== '' ? str_replace('\\', '/', $local) . '/Programs/Ollama/ollama.exe' : '';
        $out = ['base' => $base, 'running' => false, 'version' => '', 'installed' => $exe !== '' && is_file($exe), 'models' => []];
        [$code, $body] = self::http('GET', $base . '/api/version', null, 3);
        if ($code !== 200) return $out;
        $out['running'] = true;
        $out['installed'] = true;
        $out['version'] = (string)(json_decode($body, true)['version'] ?? '');
        [$code, $body] = self::http('GET', $base . '/api/tags', null, 5);
        foreach ((array)(json_decode($body, true)['models'] ?? []) as $m) {
            $name = (string)($m['name'] ?? $m['model'] ?? '');
            if ($name === '') continue;
            [$c2, $b2] = self::http('POST', $base . '/api/show', json_encode(['model' => $name]), 5);
            $caps = (array)(json_decode($b2, true)['capabilities'] ?? []);
            $out['models'][] = ['name' => $name, 'size' => (int)($m['size'] ?? 0),
                                'vision' => $caps ? in_array('vision', $caps, true) : null];
        }
        return $out;
    }

    /** Starts downloading a model in the background (a hidden php.exe running pull-model.php). */
    public static function startPull(string $model, string $openAiBase): void {
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*(:[A-Za-z0-9._-]+)?$~', $model)) {
            throw new InvalidArgumentException('Not a model name.');
        }
        $st = self::pullStatus();
        if ($st && empty($st['done']) && ($st['updated'] ?? 0) > time() - 30) {
            throw new RuntimeException('A model download is already running.');
        }
        // The installed copy's own php.exe; else (a source checkout) the PHP running this code.
        // Under Apache PHP_BINARY is httpd.exe, so then the php.exe beside the loaded php.ini.
        $php = '';
        foreach ([PF_ROOT . '/php/php.exe',
                  strtolower(basename(PHP_BINARY)) === 'php.exe' ? PHP_BINARY : '',
                  php_ini_loaded_file() ? dirname(php_ini_loaded_file()) . '/php.exe' : ''] as $c) {
            if ($c !== '' && is_file($c)) { $php = $c; break; }
        }
        if ($php === '') throw new RuntimeException('php.exe not found.');
        @file_put_contents(self::PULL_FILE, json_encode(['model' => $model, 'status' => 'starting', 'updated' => time()]));
        $win = fn(string $p): string => str_replace('/', '\\', $p);
        // Started through ShellExecute (pf-helper.ps1 "launch") so the download does not inherit
        // the web server's handles (see Updater::install for why that matters). The data folder
        // goes along as an argument: a test or portable copy may not use the default one.
        [$exit, , $err] = pf_helper('launch', json_encode([
            'file' => $win($php),
            'args' => [$win(PF_ROOT . '/pull-model.php'), $model, self::nativeBase($openAiBase), $win(PF_DATA)],
            'hidden' => true,
        ]));
        if ($exit !== 0) throw new RuntimeException('Could not start the download: ' . trim((string)$err));
    }

    public static function pullStatus(): ?array {
        if (!is_file(self::PULL_FILE)) return null;
        $s = json_decode((string)file_get_contents(self::PULL_FILE), true);
        return is_array($s) ? $s : null;
    }

    /** Small HTTP helper for Ollama on this PC. Returns [status, body]; status 0 = no answer. */
    public static function http(string $method, string $url, ?string $json, int $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => $timeout,
                                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $body = curl_exec($ch);
        $code = $body === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, $body === false ? '' : (string)$body];
    }
}
