<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/** The CLI ran, but none of its batch reply could be matched to the photos asked about.
 *  The caller retries those photos one at a time instead of failing them all. */
final class ClaudeParseException extends RuntimeException {}

final class ClaudeCli {
    private const PROMPT = <<<TXT
        Read the image at the path below using the Read tool, then output ONLY a JSON object
        with these exact keys: "description" (one or two factual sentences, max ~200 chars),
        "tags" (array of 8-20 lowercase keywords for searching: objects, scene, colors, activity,
        time-of-day, mood, style), "text_in_image" (any readable text visible in the image, or
        empty string if none). Do not identify specific people; use descriptive labels like
        "man with beard", "two children". No markdown fences, no preamble — just the raw JSON.

        Image path: %s
        TXT;

    private const BATCH_PROMPT = <<<TXT
        You will catalog multiple images for a searchable photo library. For EACH image path
        listed at the bottom of this message:
        1. Use the Read tool to view the image.
        2. Build one JSON entry with these exact keys:
           - "path": the exact input path string (copy verbatim; used as identifier)
           - "description": one or two factual sentences (max ~200 chars) describing scene,
             subjects, setting, colors, mood, notable details. Be specific and concrete.
           - "tags": array of 8-20 lowercase keywords for searching (objects, scene, colors,
             activity, time-of-day, season, weather, mood, style). Include synonyms.
           - "text_in_image": any readable text visible (signs, labels, captions, screenshots),
             or empty string if none.

        Do not identify specific people; use descriptive labels like "man with beard",
        "two children", "elderly woman".

        Output ONE single JSON object with this exact shape and nothing else:
        {"results": [ {entry1}, {entry2}, ... ]}

        No markdown fences. No preamble. No commentary. Just the raw JSON object.
        If you cannot read an image, still include an entry for it with an empty description
        and a tags array containing just "unreadable".

        Image paths to catalog:
        %s
        TXT;

    /** Captures the last JSON envelope returned by the CLI so the caller can
     *  surface timing + token-usage detail in the verbose log. */
    public ?array $lastEnvelope = null;

    public function __construct(
        private string $cliPath,
        private string $model = 'haiku',
    ) {
        if (!is_file($cliPath)) {
            throw new RuntimeException('Claude CLI not found at: ' . $cliPath);
        }
    }

    public function analyzeImages(array $imagePaths): array {
        if (!$imagePaths) return [];
        if (count($imagePaths) === 1) {
            $single = $this->analyzeImage($imagePaths[0]);
            return [$imagePaths[0] => $single];
        }

        $listing = '';
        foreach ($imagePaths as $i => $p) {
            $listing .= ($i + 1) . '. ' . $p . "\n";
        }
        $prompt = sprintf(self::BATCH_PROMPT, $listing);
        $cmd = [
            $this->cliPath,
            '-p', $prompt,
            '--output-format', 'json',
            '--model', $this->model,
            '--allowed-tools', 'Read',
            '--no-session-persistence',
            '--permission-mode', 'bypassPermissions',
        ];

        [$exit, $stdout, $stderr] = $this->run($cmd);
        if ($exit !== 0) {
            throw new RuntimeException('claude CLI exit ' . $exit . ': ' . trim($stderr ?: $stdout));
        }
        $envelope = json_decode($stdout, true);
        if (!is_array($envelope) || !isset($envelope['result'])) {
            throw new RuntimeException('Unexpected CLI envelope: ' . substr($stdout, 0, 300));
        }
        $this->lastEnvelope = $envelope;
        if (!empty($envelope['is_error'])) {
            throw new RuntimeException('CLI reported error: ' . substr((string)$envelope['result'], 0, 300));
        }

        $text = trim((string)$envelope['result']);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
        $entries = self::parseBatchResults($text);

        $byNorm = [];
        $byBase = [];
        foreach ($entries as $entry) {
            if (!isset($entry['path'])) continue;
            $key = strtolower(pf_norm_path((string)$entry['path']));
            $byNorm[$key] = [
                'description' => (string)($entry['description'] ?? ''),
                'tags' => array_values(array_filter(
                    (array)($entry['tags'] ?? []),
                    fn($t) => is_string($t) && trim($t) !== ''
                )),
                'text_in_image' => (string)($entry['text_in_image'] ?? ''),
            ];
            $byBase[strtolower(basename($key))][] = $key;
        }

        // The model sometimes gets the folder part of a path wrong. If the exact path
        // isn't there, accept a reply whose file name matches, but only when that file
        // name is unique in both the request and the reply.
        $inputBaseCount = array_count_values(array_map(fn($p) => strtolower(basename(pf_norm_path($p))), $imagePaths));
        $out = [];
        foreach ($imagePaths as $p) {
            $key = strtolower(pf_norm_path($p));
            $hit = $byNorm[$key] ?? null;
            if ($hit === null) {
                $base = strtolower(basename($key));
                if (($inputBaseCount[$base] ?? 0) === 1 && count($byBase[$base] ?? []) === 1) {
                    $hit = $byNorm[$byBase[$base][0]];
                }
            }
            $out[$p] = $hit;
        }

        $missing = count(array_filter($out, fn($v) => $v === null));
        if ($missing === count($imagePaths)) {
            self::logRawResponse($text, 'all-missing');
            throw new ClaudeParseException(sprintf(
                'Batch parse recovered 0 of %d entries (saved raw response to data/last_failed_response.txt). Preview: %s',
                count($imagePaths),
                substr($text, 0, 200)
            ));
        }
        if ($missing > 0) {
            self::logRawResponse($text, 'partial');
        }
        return $out;
    }

    private static function parseBatchResults(string $text): array {
        foreach ([$text, self::repairJson($text)] as $attempt) {
            $parsed = json_decode($attempt, true);
            if (is_array($parsed) && isset($parsed['results']) && is_array($parsed['results'])) {
                return $parsed['results'];
            }
            $brace = strpos($attempt, '{');
            $end = strrpos($attempt, '}');
            if ($brace !== false && $end !== false && $end > $brace) {
                $candidate = substr($attempt, $brace, $end - $brace + 1);
                $parsed = json_decode($candidate, true);
                if (is_array($parsed) && isset($parsed['results']) && is_array($parsed['results'])) {
                    return $parsed['results'];
                }
                if (is_array($parsed) && isset($parsed['path'])) {
                    return [$parsed];
                }
            }
            if (preg_match('/\[\s*\{[\s\S]*\}\s*\]/', $attempt, $m)) {
                $arr = json_decode($m[0], true);
                if (is_array($arr)) return $arr;
            }
        }
        return self::extractObjects(self::repairJson($text));
    }

    private static function repairJson(string $text): string {
        // Insert missing commas between value-end and the next key (common model output bug).
        $text = preg_replace('/(\])(\s*)("[A-Za-z_][^"]*"\s*:)/u', '$1,$2$3', $text) ?? $text;
        $text = preg_replace('/(\})(\s*)("[A-Za-z_][^"]*"\s*:)/u', '$1,$2$3', $text) ?? $text;
        $text = preg_replace('/("(?:[^"\\\\]|\\\\.)*")(\s*)("[A-Za-z_][^"]*"\s*:)/u', '$1,$2$3', $text) ?? $text;
        // A value written as several strings, e.g. "text_in_image": "TROOP 96", "FORT COLLINS".
        // Join them into one string. Only matches right after a key's colon and only when the
        // run of strings ends the object, so tag arrays and the next key are never touched.
        $str = '"(?:[^"\\\\]|\\\\.)*"';
        $text = preg_replace_callback(
            '/(:\s*)(' . $str . '(?:\s*,\s*' . $str . ')+)(?=\s*\})/u',
            function (array $m): string {
                preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/u', $m[2], $parts);
                $vals = array_map(fn($s) => (string)json_decode($s), $parts[0]);
                return $m[1] . json_encode(implode("\n", $vals), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            },
            $text
        ) ?? $text;
        return $text;
    }

    private static function extractObjects(string $text): array {
        $out = [];
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            if ($text[$i] !== '{') { $i++; continue; }
            $depth = 0;
            $inString = false;
            $escape = false;
            $start = $i;
            for ($j = $i; $j < $len; $j++) {
                $c = $text[$j];
                if ($escape) { $escape = false; continue; }
                if ($c === '\\') { $escape = true; continue; }
                if ($c === '"') { $inString = !$inString; continue; }
                if ($inString) continue;
                if ($c === '{') $depth++;
                elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $candidate = substr($text, $start, $j - $start + 1);
                        $obj = json_decode($candidate, true);
                        if (!is_array($obj)) $obj = json_decode(self::repairJson($candidate), true);
                        if (is_array($obj) && isset($obj['path']) && isset($obj['description'])) {
                            $out[] = $obj;
                            $i = $j + 1;
                        } else {
                            // Not an entry. Usually it's the {"results": [...]} wrapper that one
                            // bad entry made unparseable, so step inside it to keep the good ones.
                            $i = $start + 1;
                        }
                        continue 2;
                    }
                }
            }
            // Never closed (reply cut off): step inside so the complete entries still count.
            $i = $start + 1;
        }
        return $out;
    }

    private static function logRawResponse(string $text, string $tag): void {
        if (!is_dir(PF_DATA)) return;
        $path = PF_DATA . '/last_failed_response.txt';
        $header = "=== " . date('Y-m-d H:i:s') . " [{$tag}] ===\n";
        @file_put_contents($path, $header . $text . "\n\n", FILE_APPEND);
    }

    public function analyzeImage(string $imagePath): array {
        if (!is_file($imagePath)) {
            throw new RuntimeException('Image not found: ' . $imagePath);
        }

        $prompt = sprintf(self::PROMPT, $imagePath);
        $cmd = [
            $this->cliPath,
            '-p', $prompt,
            '--output-format', 'json',
            '--model', $this->model,
            '--allowed-tools', 'Read',
            '--no-session-persistence',
            '--permission-mode', 'bypassPermissions',
        ];

        [$exit, $stdout, $stderr] = $this->run($cmd);
        if ($exit !== 0) {
            throw new RuntimeException('claude CLI exit ' . $exit . ': ' . trim($stderr ?: $stdout));
        }

        $envelope = json_decode($stdout, true);
        if (!is_array($envelope) || !isset($envelope['result'])) {
            throw new RuntimeException('Unexpected CLI envelope: ' . substr($stdout, 0, 300));
        }
        $this->lastEnvelope = $envelope;
        if (!empty($envelope['is_error'])) {
            throw new RuntimeException('CLI reported error: ' . substr((string)$envelope['result'], 0, 300));
        }

        $text = trim((string)$envelope['result']);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            $brace = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($brace !== false && $end !== false && $end > $brace) {
                $parsed = json_decode(substr($text, $brace, $end - $brace + 1), true);
            }
        }
        if (!is_array($parsed)) {
            throw new RuntimeException('Could not parse JSON from CLI output: ' . substr($text, 0, 200));
        }

        return [
            'description' => (string)($parsed['description'] ?? ''),
            'tags' => array_values(array_filter(
                (array)($parsed['tags'] ?? []),
                fn($t) => is_string($t) && trim($t) !== ''
            )),
            'text_in_image' => (string)($parsed['text_in_image'] ?? ''),
            'usage' => $envelope['usage'] ?? [],
        ];
    }

    private function run(array $cmd): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to launch claude CLI');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return [$exit, (string)$stdout, (string)$stderr];
    }

    public static function autoDetect(): ?string {
        $env = getenv('USERPROFILE') ?: getenv('HOME');
        if (!$env) return null;
        $candidates = [];
        $extRoot = $env . '/.vscode/extensions';
        if (is_dir($extRoot)) {
            foreach (glob($extRoot . '/anthropic.claude-code-*') ?: [] as $dir) {
                $exe = $dir . '/resources/native-binary/claude.exe';
                if (is_file($exe)) $candidates[] = $exe;
            }
            foreach (glob($extRoot . '/anthropic.claude-code-*') ?: [] as $dir) {
                $exe = $dir . '/resources/native-binary/claude';
                if (is_file($exe)) $candidates[] = $exe;
            }
        }
        $cursorRoot = $env . '/.cursor/extensions';
        if (is_dir($cursorRoot)) {
            foreach (glob($cursorRoot . '/anthropic.claude-code-*') ?: [] as $dir) {
                $exe = $dir . '/resources/native-binary/claude.exe';
                if (is_file($exe)) $candidates[] = $exe;
            }
        }
        $npmGlobal = ($env . '/AppData/Roaming/npm/claude.cmd');
        if (is_file($npmGlobal)) $candidates[] = $npmGlobal;

        // The standalone installer (claude.ai/install.ps1) keeps a stable path that
        // updates in place, so prefer it over an editor extension's versioned folder.
        $native = $env . '/.local/bin/claude.exe';
        if (is_file($native)) return pf_norm_path($native);

        if (!$candidates) return null;
        // Newest extension by version number. A plain string sort ranks 2.1.99 above 2.1.318.
        usort($candidates, fn($a, $b) => version_compare(self::versionOf($b), self::versionOf($a)));
        return pf_norm_path($candidates[0]);
    }

    private static function versionOf(string $path): string {
        return preg_match('/claude-code-(\d+(?:\.\d+)*)/', $path, $m) ? $m[1] : '0';
    }

    /** The configured CLI if it still exists, otherwise the newest installed one.
     *  VS Code deletes the old extension folder on every update, which strands a saved path. */
    public static function resolvePath(string $configured): ?string {
        if ($configured !== '' && is_file($configured)) return $configured;
        return self::autoDetect();
    }
}
