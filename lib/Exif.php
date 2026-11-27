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
    // ---- Details for the photo view ----

    /** What the photo view shows about one file: its own size as shown, DPI, file size and
     *  type, colour space, camera, lens and exposure, dates and rotation. Read with PHP's own
     *  readers (the file's header and EXIF block only; nothing is changed, no ExifTool), so it
     *  is quick enough to do every time a photo is opened. Missing values are left out. */
    public static function details(string $path): array {
        if (!is_file($path)) return ['exists' => false];
        $app = [];
        $info = @getimagesize($path, $app);
        $out = ['exists' => true, 'bytes' => (int)filesize($path), 'modified' => (int)filemtime($path)];
        if (!$info) return $out;
        $type = (int)$info[2];
        $out['type'] = ['1' => 'GIF', '2' => 'JPEG', '3' => 'PNG', '6' => 'BMP', '7' => 'TIFF', '8' => 'TIFF', '18' => 'WebP'][(string)$type]
            ?? strtoupper(pathinfo($path, PATHINFO_EXTENSION));
        [$out['width'], $out['height']] = pf_image_size($path) ?? [(int)$info[0], (int)$info[1]];
        $e = in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM], true) && function_exists('exif_read_data')
            ? (@exif_read_data($path) ?: []) : [];
        $num = static function ($v): ?float {   // "72/1", "28/10" or a number
            if (is_array($v)) $v = $v[0] ?? null;
            if ($v === null || $v === '') return null;
            if (is_string($v) && str_contains($v, '/')) {
                [$a, $b] = array_map('floatval', explode('/', $v, 2));
                return $b != 0.0 ? $a / $b : null;
            }
            return is_numeric($v) ? (float)$v : null;
        };
        // Resolution: EXIF first, else the JPEG's JFIF header.
        $dpi = $num($e['XResolution'] ?? null);
        if ($dpi !== null && (int)($e['ResolutionUnit'] ?? 2) === 3) $dpi *= 2.54;   // per centimetre
        if (!$dpi && isset($app['APP0']) && str_starts_with($app['APP0'], "JFIF\0") && strlen($app['APP0']) >= 12) {
            $u = ord($app['APP0'][7]);
            $d = (ord($app['APP0'][8]) << 8) | ord($app['APP0'][9]);
            if ($u === 1 && $d > 0) $dpi = $d; elseif ($u === 2 && $d > 0) $dpi = $d * 2.54;
        }
        if ($dpi) $out['dpi'] = (int)round($dpi);
        // Colour: the embedded ICC profile's name when it is a common one, else EXIF's colour space.
        $icc = (string)($app['APP2'] ?? '');
        foreach (['Adobe RGB', 'Display P3', 'ProPhoto', 'sRGB'] as $name) {
            if ($icc !== '' && stripos($icc, $name) !== false) { $out['color'] = $name; break; }
        }
        if (!isset($out['color']) && isset($e['ColorSpace'])) $out['color'] = (int)$e['ColorSpace'] === 1 ? 'sRGB' : 'uncalibrated';
        $make = trim((string)($e['Make'] ?? ''));
        $model = trim((string)($e['Model'] ?? ''));
        $camera = $make !== '' && stripos($model, $make) !== 0 ? trim("$make $model") : ($model !== '' ? $model : $make);
        if ($camera !== '') $out['camera'] = $camera;
        $lens = trim((string)($e['LensModel'] ?? $e['UndefinedTag:0xA434'] ?? ''));
        if ($lens !== '') $out['lens'] = $lens;
        if (($f = $num($e['FNumber'] ?? null)) !== null && $f > 0) $out['aperture'] = 'f/' . rtrim(rtrim(number_format($f, 1), '0'), '.');
        if (($t = $num($e['ExposureTime'] ?? null)) !== null && $t > 0) {
            $out['shutter'] = $t >= 1 ? rtrim(rtrim(number_format($t, 1), '0'), '.') . ' s' : '1/' . (int)round(1 / $t) . ' s';
        }
        $iso = $e['ISOSpeedRatings'] ?? $e['PhotographicSensitivity'] ?? null;
        if (is_array($iso)) $iso = $iso[0] ?? null;
        if ($iso) $out['iso'] = (int)$iso;
        if (($fl = $num($e['FocalLength'] ?? null)) !== null && $fl > 0) $out['focal'] = rtrim(rtrim(number_format($fl, 1), '0'), '.') . ' mm';
        if (!empty($e['FocalLengthIn35mmFilm'])) $out['focal35'] = (int)$e['FocalLengthIn35mmFilm'] . ' mm';
        if (isset($e['Flash'])) $out['flash'] = ((int)$e['Flash'] & 1) ? 'fired' : 'did not fire';
        if (!empty($e['DateTimeOriginal'])) $out['taken'] = (string)$e['DateTimeOriginal'];
        $sw = trim((string)($e['Software'] ?? ''));
        if ($sw !== '') $out['software'] = mb_substr($sw, 0, 60);
        $o = (int)($e['Orientation'] ?? 1);
        $out['rotation'] = [1 => '', 2 => 'mirrored', 3 => 'turned 180°', 4 => 'mirrored upside down', 5 => 'mirrored and turned',
                            6 => 'turned 90° right', 7 => 'mirrored and turned', 8 => 'turned 90° left'][$o] ?? '';
        return $out;
    }
}
