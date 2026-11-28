<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';

final class Exporter {
    public function __construct(
        private string $exiftoolPath,
        private bool $writeDescription,
        private bool $writeTags,
        private bool $writeOcrText,
        private bool $replaceDescription,
        private bool $overwriteOriginal,
    ) {
        if (!is_file($exiftoolPath)) {
            throw new RuntimeException('ExifTool not found at: ' . $exiftoolPath);
        }
    }

    public static function autoDetect(): ?string {
        $local = PF_ROOT . '/bin/exiftool.exe';
        if (is_file($local)) return pf_norm_path(realpath($local) ?: $local);
        foreach (['C:/Program Files/digiKam/exiftool.exe', 'C:/tools/exiftool/exiftool.exe', 'C:/exiftool/exiftool.exe'] as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }

    public function processBatch(array $items): array {
        if (!$items) return ['processed' => 0, 'errors' => 0, 'messages' => []];
        require_once __DIR__ . '/People.php';
        $items = array_values($items);
        // The people named in each (People): written as names and face regions.
        foreach ($items as &$it) $it['people'] = People::embedData((string)$it['id'], (string)$it['path']);
        unset($it);

        // One ExifTool run for the whole batch, one command per photo. -echo3/-echo4 print a
        // {pf:N} marker on stdout and stderr after photo N's command finishes, so the output
        // can be split per photo. (Earlier versions split on {ready}, which ExifTool only
        // prints in -stay_open mode, so every message landed on the first photo and the rest
        // were failed as "Exit 1".) The last command has no -execute: a trailing -execute
        // starts an empty extra command that fails with "No file specified".
        $args = '';
        $last = count($items) - 1;
        foreach ($items as $n => $item) {
            $mark = '{pf:' . ($n + 1) . '}';
            $args .= $this->argsFor($item) . "-echo3\n{$mark}\n-echo4\n{$mark}\n" . ($n < $last ? "-execute\n" : '');
        }
        [, $stdout, $stderr] = $this->runArgs($args);
        $outByN = self::splitByMarker($stdout);
        $errByN = self::splitByMarker($stderr);

        $processed = 0;
        $errors = 0;
        $messages = [];
        foreach ($items as $n => $item) {
            $out = $outByN[$n + 1] ?? null;
            $err = $errByN[$n + 1] ?? '';
            if ($out === null) {
                // No marker for this photo (ExifTool stopped early): run it alone.
                [, $out, $err] = $this->runArgs($this->argsFor($item));
            }
            if (self::isUpdated($out) || preg_match('/Nothing to do/i', $out . $err)) {
                Db::markExported($item['id']);
                $processed++;
            } else {
                $line = self::extractErrorLine(trim($err)) ?: self::extractErrorLine(trim($out)) ?: 'ExifTool gave no result';
                Db::markExportError($item['id'], $line);
                $errors++;
                $messages[] = basename($item['path']) . ': ' . $line;
            }
        }

        return ['processed' => $processed, 'errors' => $errors, 'messages' => array_filter($messages)];
    }

    private static function isUpdated(string $out): bool {
        return (bool)preg_match('/\b[1-9]\d* image files? (updated|created|unchanged)/i', $out);
    }

    /** Map N => the output printed before the {pf:N} marker line. */
    private static function splitByMarker(string $text): array {
        $byN = [];
        $parts = preg_split('/^\{pf:(\d+)\}\r?$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($k = 1; $k < count($parts); $k += 2) {
            $byN[(int)$parts[$k]] = $parts[$k - 1];
        }
        return $byN;
    }

    /** ExifTool arguments that write one photo's description and tags (no -execute). */
    private function argsFor(array $item): string {
        $description = (string)($item['description'] ?? '');
        $tagsCsv = (string)($item['tags'] ?? '');
        $ocrText = trim((string)($item['text_in_image'] ?? ''));

        $tags = array_values(array_filter(array_map('trim', explode(',', $tagsCsv)), fn($t) => $t !== ''));
        if ($this->writeOcrText && $ocrText !== '') {
            foreach (preg_split('/\s+/', $ocrText) ?: [] as $word) {
                $w = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $word) ?? '';
                if (strlen($w) >= 3) $tags[] = strtolower($w);
            }
        }
        $tags = array_values(array_unique(array_map('strval', $tags)));

        // Argfile values are one per line, so a newline inside a value would split it.
        $description = trim(preg_replace('/\s*[\r\n]+\s*/', ' ', $description) ?? $description);

        $a = '';
        if ($this->writeDescription && $description !== '') {
            $mode = $this->replaceDescription ? '=' : '<';
            $a .= "-XMP-dc:Description{$mode}{$description}\n";
            $a .= "-IPTC:Caption-Abstract{$mode}{$description}\n";
            $a .= "-EXIF:ImageDescription{$mode}{$description}\n";
            $a .= "-XMP-dc:Title{$mode}" . self::shortTitle($description) . "\n";
        }
        if ($this->writeTags && $tags) {
            foreach ($tags as $tag) {
                $tag = trim(preg_replace('/[\r\n]+/', ' ', $tag) ?? $tag);
                $a .= "-XMP-dc:Subject-={$tag}\n-XMP-dc:Subject+={$tag}\n";
                $a .= "-IPTC:Keywords-={$tag}\n-IPTC:Keywords+={$tag}\n";
                $a .= "-XMP-microsoft:LastKeywordXMP-={$tag}\n-XMP-microsoft:LastKeywordXMP+={$tag}\n";
            }
        }
        $p = $item['people'] ?? null;
        if (is_array($p) && $p['regions']) {
            // Who is in it: IPTC's PersonInImage (the names) and MWG face regions (where each is,
            // as Windows, digiKam and Lightroom read them; centre and size, 0..1 of the file's
            // stored frame). The regions are written whole, so the list is exactly these people.
            $a .= "-XMP-iptcExt:PersonInImage=\n";
            foreach ($p['names'] as $n) $a .= '-XMP-iptcExt:PersonInImage+=' . $n . "\n";
            $list = [];
            foreach ($p['regions'] as $g) {
                [$x, $y, $w, $h] = array_map(static fn($v) => max(0.0, min(1.0, (float)$v)), $g['box']);
                $list[] = sprintf('{Area={X=%.5f,Y=%.5f,W=%.5f,H=%.5f,Unit=normalized},Name=%s,Type=Face}',
                                  $x + $w / 2, $y + $h / 2, $w, $h, self::structValue((string)$g['name']));
            }
            $a .= sprintf("-XMP-mwg-rs:RegionInfo={AppliedToDimensions={W=%d,H=%d,Unit=pixel},RegionList=[%s]}\n",
                          (int)$p['width'], (int)$p['height'], implode(',', $list));
        }
        return $a . $item['path'] . "\n";
    }

    /** A value inside an ExifTool structure: , ] } | and a leading [ { or space are escaped with |. */
    private static function structValue(string $v): string {
        $v = preg_replace('/[,\]\}|]/', '|$0', trim(preg_replace('/[\r\n]+/', ' ', $v) ?? $v)) ?? $v;
        return preg_match('/^[\[\{ ]/', $v) ? '|' . $v : $v;
    }

    /** Run ExifTool on an argfile body. Options go in -common_args so they apply to every
     *  -execute group; options at the top of the argfile only reach the first photo, which is
     *  how earlier versions left *_original backup copies beside most photos. */
    private function runArgs(string $body): array {
        $argFile = tempnam(sys_get_temp_dir(), 'pf_args_');
        if ($argFile === false || file_put_contents($argFile, $body) === false) {
            throw new RuntimeException('Could not write ExifTool argument file');
        }
        $cmd = [$this->exiftoolPath, '-charset', 'filename=UTF8', '-@', $argFile,
                '-common_args', '-charset', 'filename=UTF8', '-m', '-codedcharacterset=utf8'];
        if ($this->overwriteOriginal) $cmd[] = '-overwrite_original';
        try {
            return $this->run($cmd);
        } finally {
            @unlink($argFile);
        }
    }

    private static function shortTitle(string $description): string {
        $description = trim($description);
        if (strlen($description) <= 80) return $description;
        $cut = substr($description, 0, 77);
        $lastSpace = strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 40) $cut = substr($cut, 0, $lastSpace);
        return $cut . '...';
    }

    private static function extractErrorLine(string $output): string {
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (stripos($line, 'error') !== false || stripos($line, 'unrecognized') !== false) {
                return trim($line);
            }
        }
        return trim(substr($output, 0, 200));
    }

    private function run(array $cmd): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) throw new RuntimeException('Failed to launch exiftool');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return [$exit, (string)$stdout, (string)$stderr];
    }
}
