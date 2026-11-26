<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

// Extract person/face names from image XMP metadata.
// Supports the major schemas that digiKam, Lightroom, Picasa, Windows Photo
// Gallery, and Apple Photos all use in some form:
//   - MWG-rs:RegionList (Metadata Working Group regions, what digiKam writes)
//   - XMP-MP:RegionInfo (Microsoft Photo, what Win Photo Gallery wrote)
//   - IPTC4xmpExt:PersonInImage (newer "people depicted" tag)

final class Faces {
    public static function exiftool(): string {
        $local = PF_ROOT . '/bin/exiftool.exe';
        if (is_file($local)) return $local;
        return 'exiftool';
    }

    /** Extract names from a single file. Returns deduplicated array of strings. */
    public static function extractOne(string $path): array {
        if (!is_file($path)) return [];
        $cmd = escapeshellarg(self::exiftool())
            . ' -j -RegionName -RegionPersonDisplayName -PersonInImage -Subject '
            . escapeshellarg($path) . ' 2>nul';
        $out = shell_exec($cmd);
        $data = json_decode((string)$out, true);
        if (!is_array($data) || empty($data[0])) return [];
        return self::namesFromRow($data[0]);
    }

    /** Batch extract from a folder. Returns [path => [names]]. Much faster
     *  than calling extractOne in a loop (single exiftool process for all). */
    public static function extractBatch(string $dir): array {
        if (!is_dir($dir)) return [];
        $cmd = escapeshellarg(self::exiftool())
            . ' -r -j -ext jpg -ext jpeg -ext png '
            . '-RegionName -RegionPersonDisplayName -PersonInImage -Subject '
            . escapeshellarg($dir) . ' 2>nul';
        $out = shell_exec($cmd);
        $data = json_decode((string)$out, true);
        if (!is_array($data)) return [];
        $result = [];
        foreach ($data as $row) {
            $names = self::namesFromRow($row);
            if (!$names) continue;
            $p = pf_norm_path((string)($row['SourceFile'] ?? ''));
            if ($p !== '') $result[$p] = $names;
        }
        return $result;
    }

    private static function namesFromRow(array $row): array {
        $names = [];
        foreach (['RegionName', 'RegionPersonDisplayName', 'PersonInImage'] as $k) {
            if (!isset($row[$k])) continue;
            $v = $row[$k];
            // ExifTool returns either a comma-separated string or an array
            if (is_string($v)) {
                foreach (explode(',', $v) as $n) {
                    $n = trim($n);
                    if ($n !== '' && stripos($n, 'face') !== 0 && stripos($n, 'unknown') !== 0) {
                        $names[] = $n;
                    }
                }
            } elseif (is_array($v)) {
                foreach ($v as $n) {
                    if (is_string($n)) {
                        $n = trim($n);
                        if ($n !== '' && stripos($n, 'unknown') !== 0) $names[] = $n;
                    }
                }
            }
        }
        // Optional: also pick out names from Subject keywords that LOOK like person names.
        // Heuristic: 2-word capitalized strings. Conservative — avoid false positives.
        // (Disabled by default; uncomment if you want this fallback.)
        // if (isset($row['Subject']) && is_array($row['Subject'])) {
        //     foreach ($row['Subject'] as $s) {
        //         if (preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', trim($s))) $names[] = trim($s);
        //     }
        // }
        return array_values(array_unique($names));
    }
}
