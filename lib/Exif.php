<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/** Reads GPS + capture-date EXIF/XMP via ExifTool, in batches.
 *  Read-only — never modifies files. */
final class Exif {
    public static function toolPath(): ?string {
        $s = pf_load_settings();
        $p = (string)($s['exiftool_path'] ?? '');
        if ($p !== '' && is_file($p)) return $p;
        $bundled = PF_ROOT . '/bin/exiftool.exe';
        return is_file($bundled) ? $bundled : null;
    }

    /** Returns [path => ['lat'=>?float, 'lon'=>?float, 'alt'=>?float, 'taken_at'=>?string, 'faces'=>string[]]].
     *  Paths whose files have no extractable GPS/date/faces are omitted.
     *  All four data points come from a single ExifTool call per chunk to minimize warmup cost. */
    public static function extractBatch(array $paths): array {
        $paths = array_values(array_filter($paths, 'is_file'));
        if (!$paths) return [];
        $tool = self::toolPath();
        if (!$tool) return [];

        // Index input paths by normalized form so we can map ExifTool's
        // SourceFile (which it reports with forward slashes) back to the caller's key.
        $byNorm = [];
        foreach ($paths as $p) $byNorm[pf_norm_path($p)] = $p;

        $out = [];
        // Chunk to stay well under the ~8KB Windows command-line cap on long paths.
        foreach (array_chunk($paths, 25) as $chunk) {
            $cmd = array_merge(
                [$tool, '-json', '-n', '-q', '-q',
                 '-GPSLatitude', '-GPSLongitude', '-GPSAltitude',
                 '-DateTimeOriginal', '-CreateDate', '-SubSecDateTimeOriginal',
                 '-RegionName', '-RegionPersonDisplayName', '-PersonInImage'],
                $chunk
            );
            [$exit, $stdout, $stderr] = pf_run_cmd($cmd);
            if ($stdout === '') continue;
            $data = json_decode($stdout, true);
            if (!is_array($data)) continue;
            foreach ($data as $row) {
                $src = (string)($row['SourceFile'] ?? '');
                if ($src === '') continue;
                $key = $byNorm[pf_norm_path($src)] ?? $src;

                $lat = isset($row['GPSLatitude'])  ? (float)$row['GPSLatitude']  : null;
                $lon = isset($row['GPSLongitude']) ? (float)$row['GPSLongitude'] : null;
                $alt = isset($row['GPSAltitude'])  ? (float)$row['GPSAltitude']  : null;
                if ($lat !== null && ($lat < -90 || $lat > 90 || $lat == 0.0)) $lat = null;
                if ($lon !== null && ($lon < -180 || $lon > 180 || $lon == 0.0)) $lon = null;
                if ($lat === null || $lon === null) { $lat = null; $lon = null; }

                $taken = (string)($row['SubSecDateTimeOriginal']
                                ?? $row['DateTimeOriginal']
                                ?? $row['CreateDate']
                                ?? '');
                // Normalize "2024:03:15 14:23:45" → "2024-03-15T14:23:45"
                if ($taken !== '' && preg_match(
                    '/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/',
                    $taken, $m
                )) {
                    $taken = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}";
                } else {
                    $taken = '';
                }

                $faces = self::parseFaces($row);

                if ($lat === null && $taken === '' && $alt === null && !$faces) continue;
                $out[$key] = [
                    'lat'      => $lat,
                    'lon'      => $lon,
                    'alt'      => $alt,
                    'taken_at' => $taken ?: null,
                    'faces'    => $faces,
                ];
            }
        }
        return $out;
    }

    /** Pull person/face names out of ExifTool's RegionName/RegionPersonDisplayName/PersonInImage
     *  fields. Filters obvious noise ("unknown", "face_N" placeholders). */
    private static function parseFaces(array $row): array {
        $names = [];
        foreach (['RegionName', 'RegionPersonDisplayName', 'PersonInImage'] as $k) {
            if (!isset($row[$k])) continue;
            $v = $row[$k];
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
        return array_values(array_unique($names));
    }
}
