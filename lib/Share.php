<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Exif.php';
require_once __DIR__ . '/Exporter.php';
require_once __DIR__ . '/Thumb.php';

/**
 * Share: the photos the user picked, made ready for Windows' share window (the page hands them
 * to navigator.share), for downloading (one file, or a .zip of several) and for copying.
 *
 * prepare() makes a share set in PF_DATA/share/<token>/. Each photo in it is either the photo's
 * own file (full size, location kept: nothing to change) or a copy made for this share:
 *  - smaller: SMALL_SIDE pixels on its long side, re-saved, with the photo's metadata (date,
 *    description, people, camera) copied over;
 *  - without its location: the GPS position and the place names other programs write, taken
 *    out with ExifTool (a full-size copy's pixels are not touched).
 * The photos themselves never change. Sets are deleted after TTL seconds.
 */
final class Share {
    public const SMALL_SIDE = 2048;
    public const MAX_PHOTOS = 500;
    private const TTL = 3600;
    private const JPEG_QUALITY = 88;

    /** ExifTool deletions that take the location out: the GPS position (EXIF and XMP) and place names (XMP and IPTC). */
    private const NO_LOCATION = [
        '-GPS:all=', '-XMP-exif:GPS*=',
        '-XMP-photoshop:City=', '-XMP-photoshop:State=', '-XMP-photoshop:Country=',
        '-XMP-iptcCore:Location=', '-XMP-iptcCore:CountryCode=',
        '-XMP-iptcExt:LocationCreated=', '-XMP-iptcExt:LocationShown=',
        '-IPTC:City=', '-IPTC:Sub-location=', '-IPTC:Province-State=',
        '-IPTC:Country-PrimaryLocationName=', '-IPTC:Country-PrimaryLocationCode=',
    ];
    /** Formats ExifTool writes. (BMP carries no metadata, so there is no location to take out.) */
    private const WRITABLE = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff'];
    /** Formats made smaller. TIFF stays full size (GD cannot read it), and so does GIF (it may be animated). */
    private const SHRINKABLE = ['jpg', 'jpeg', 'png', 'webp', 'bmp'];

    public static function root(): string {
        return PF_DATA . '/share';
    }

    private static function dir(string $token): string {
        return self::root() . '/' . $token;
    }

    /**
     * Make a share set of photos $ids. $small: smaller copies; $location: keep the GPS position
     * and place names. Returns ['token', 'files' => [{n, id, name, type, bytes, smaller, changed}],
     * 'bytes', 'skipped' => [{id, name, reason}]].
     */
    public static function prepare(array $ids, bool $small, bool $location): array {
        self::reap();
        $ids = array_values(array_unique(array_filter(array_map(
            static fn($i) => preg_replace('/[^a-f0-9]/i', '', (string)$i), $ids))));
        if (!$ids) throw new InvalidArgumentException('No photos to share.');
        if (count($ids) > self::MAX_PHOTOS) throw new InvalidArgumentException('Share at most ' . self::MAX_PHOTOS . ' photos at a time.');
        $roots = pf_load_settings()['photo_dirs'] ?? [];
        $tool = Exif::toolPath() ?? Exporter::autoDetect();
        if ($tool !== null && !is_file($tool)) $tool = null;

        $token = bin2hex(random_bytes(8));
        $dir = self::dir($token);
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) throw new RuntimeException('Could not make a folder for the share in ' . self::root());

        $files = [];      // the set, in the order the photos were picked
        $skipped = [];
        $taken = [];      // lower-case names already used (zip entries and shared files need different names)
        $jobs = [];       // ExifTool sections: [index in $files, args, output file, fallback file or '']
        foreach ($ids as $id) {
            $row = Db::getById($id);
            $path = $row ? (string)$row['path'] : '';
            $base = $path !== '' ? basename($path) : $id;
            if ($path === '' || !is_file($path)) { $skipped[] = ['id' => $id, 'name' => $base, 'reason' => 'no longer on disk']; continue; }
            if (!pf_path_inside_roots($path, $roots)) { $skipped[] = ['id' => $id, 'name' => $base, 'reason' => 'outside your photo folders']; continue; }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $size = pf_image_size($path);
            $shrink = $small && in_array($ext, self::SHRINKABLE, true) && $size !== null && max($size) > self::SMALL_SIDE;
            $scrub = !$location && in_array($ext, self::WRITABLE, true);
            $outExt = $shrink ? ($ext === 'png' ? 'png' : 'jpg') : $ext;
            $name = self::uniqueName(pathinfo($path, PATHINFO_FILENAME), $outExt, $taken);
            $k = count($files);
            $entry = ['n' => $k, 'id' => $id, 'name' => $name, 'type' => pf_mime_for_ext($outExt),
                      'path' => $path, 'smaller' => $shrink, 'changed' => $shrink || $scrub];
            if (!$shrink && !$scrub) { $files[] = $entry; continue; }

            if (!$shrink && $tool === null) {
                $skipped[] = ['id' => $id, 'name' => $base, 'reason' => 'ExifTool (Settings > Embed metadata) is needed to take its location out'];
                continue;
            }
            $sub = $dir . '/' . $k;
            @mkdir($sub, 0777, true);
            $dest = $sub . '/' . $name;
            if ($shrink) {
                $gdFile = $sub . '/gd.' . $outExt;
                $orient = self::shrink($path, $gdFile, $outExt);
                if ($orient === null) { $skipped[] = ['id' => $id, 'name' => $base, 'reason' => 'could not be read to make it smaller']; continue; }
                if ($tool === null) {
                    // No ExifTool: the smaller copy goes without metadata (so without its location too).
                    rename($gdFile, $dest);
                } else {
                    [$w, $h] = getimagesize($gdFile) ?: [0, 0];
                    // As Fix blur's copies do: everything from the photo, minus its old thumbnail and
                    // preview and the HDR / motion-photo descriptions whose extra data the copy lacks.
                    $args = ['-TagsFromFile', pf_norm_path($path), '-all:all', '-unsafe', '-ICC_Profile', '-IPTC:CodedCharacterSet',
                             '--XMP-hdrgm:all', '--XMP-GContainer:all', '--XMP-GCamera:all', '--ThumbnailImage', '--PreviewImage',
                             '-ExifIFD:ExifImageWidth=' . $w, '-ExifIFD:ExifImageHeight=' . $h];
                    // GD turned the pixels upright for Orientation 3, 6 and 8 (Thumb::applyOrientation).
                    if (in_array($orient, [3, 6, 8], true)) $args[] = '-IFD0:Orientation#=1';
                    if ($scrub) array_push($args, ...self::NO_LOCATION);
                    array_push($args, '-o', pf_norm_path($dest), pf_norm_path($gdFile));
                    // If ExifTool fails, the bare copy is shared instead: it has no metadata at all,
                    // so no location either.
                    $jobs[] = [$k, $args, $dest, $gdFile];
                }
            } else {
                $jobs[] = [$k, [...self::NO_LOCATION, '-o', pf_norm_path($dest), pf_norm_path($path)], $dest, ''];
            }
            $entry['path'] = $dest;
            $files[] = $entry;
        }
        if ($jobs) self::runExifTool($tool, $jobs);
        foreach ($jobs as [$k, , $dest, $fallback]) {
            if (is_file($dest)) { if ($fallback !== '') @unlink($fallback); continue; }
            if ($fallback !== '' && is_file($fallback) && @rename($fallback, $dest)) continue;
            $skipped[] = ['id' => $files[$k]['id'], 'name' => $files[$k]['name'], 'reason' => 'could not take its location out'];
            $files[$k] = null;
        }
        $out = [];
        $bytes = 0;
        foreach (array_values(array_filter($files)) as $i => $f) {
            clearstatcache(true, $f['path']);
            $f['n'] = $i;
            $f['bytes'] = (int)@filesize($f['path']);
            $bytes += $f['bytes'];
            $out[] = $f;
        }
        file_put_contents($dir . '/manifest.json', json_encode(['made' => time(), 'files' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $public = array_map(static fn($f) => array_diff_key($f, ['path' => 1]), $out);
        return ['token' => $token, 'files' => $public, 'bytes' => $bytes, 'skipped' => $skipped, 'small_side' => self::SMALL_SIDE];
    }

    /** File $n of share set $token: ['path', 'name', 'type'], or null. */
    public static function file(string $token, int $n): ?array {
        $m = self::manifest($token);
        $f = $m['files'][$n] ?? null;
        if (!is_array($f) || !is_file((string)$f['path'])) return null;
        // Only the set's own copies, or photos inside the photo folders.
        $p = (string)$f['path'];
        if (!str_starts_with(pf_norm_path($p), pf_norm_path(self::dir($token)) . '/')
            && !pf_path_inside_roots($p, pf_load_settings()['photo_dirs'] ?? [])) return null;
        return $f;
    }

    /** All the set's files in one .zip (stored, not compressed: photos do not shrink). Returns its path. */
    public static function zip(string $token): string {
        $m = self::manifest($token);
        if (!$m || !$m['files']) throw new RuntimeException('This share has expired. Close Share and open it again.');
        $zipPath = self::dir($token) . '/photos.zip';
        @unlink($zipPath);
        $z = new ZipArchive();
        if ($z->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not make the .zip file.');
        foreach (array_keys($m['files']) as $n) {
            $f = self::file($token, (int)$n);
            if (!$f) continue;
            $z->addFile((string)$f['path'], (string)$f['name']);
            $z->setCompressionName((string)$f['name'], ZipArchive::CM_STORE);
        }
        if (!$z->close()) throw new RuntimeException('Could not write the .zip file.');
        return $zipPath;
    }

    public static function count(string $token): int {
        return count(self::manifest($token)['files'] ?? []);
    }

    /** Send a file to the browser; $download makes it save rather than show. */
    public static function send(string $path, string $name, string $type, bool $download): void {
        $ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $name);
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        readfile($path);
    }

    private static function manifest(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) return null;
        $m = json_decode((string)@file_get_contents(self::dir($token) . '/manifest.json'), true);
        return is_array($m) && is_array($m['files'] ?? null) ? $m : null;
    }

    /** "<stem>.<ext>", or "<stem> (2).<ext>" and so on when that name is already in the set. */
    private static function uniqueName(string $stem, string $ext, array &$taken): string {
        $stem = $stem !== '' ? $stem : 'photo';
        for ($i = 1; ; $i++) {
            $name = $stem . ($i === 1 ? '' : " ({$i})") . ($ext !== '' ? '.' . $ext : '');
            if (!isset($taken[mb_strtolower($name)])) { $taken[mb_strtolower($name)] = true; return $name; }
        }
    }

    /** Write photo $src at most SMALL_SIDE on its long side, upright, to $dest. Returns the EXIF
     *  Orientation it had (the pixels are turned for 3, 6 and 8), or null if it cannot be read. */
    private static function shrink(string $src, string $dest, string $outExt): ?int {
        $info = @getimagesize($src);
        if (!$info) return null;
        $im = Thumb::loadGd($src, (int)$info[2]);
        if (!$im) return null;
        $orient = (int)$info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') ? (int)(@exif_read_data($src)['Orientation'] ?? 1) : 1;
        $im = Thumb::applyOrientation($im, $src);
        [$w, $h] = [imagesx($im), imagesy($im)];
        $r = self::SMALL_SIDE / max($w, $h);
        $nw = max(1, (int)round($w * $r));
        $nh = max(1, (int)round($h * $r));
        $out = imagecreatetruecolor($nw, $nh);
        if ($outExt === 'png') {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $im = null;
        $ok = $outExt === 'png' ? imagepng($out, $dest, 6) : imagejpeg($out, $dest, self::JPEG_QUALITY);
        $out = null;
        return $ok ? $orient : null;
    }

    /** One ExifTool process for every job (sections split by -execute). Each job writes its own
     *  output file with -o, which ExifTool only creates when it succeeds. */
    private static function runExifTool(string $tool, array $jobs): void {
        $lines = [];
        foreach ($jobs as [, $args]) {
            array_push($lines, '-charset', 'filename=UTF8', '-m', ...$args);
            $lines[] = '-execute';
        }
        array_pop($lines);
        $argFile = self::root() . '/' . bin2hex(random_bytes(6)) . '-args.txt';
        file_put_contents($argFile, implode("\n", $lines) . "\n");
        try {
            [$exit, $stdout, $stderr] = pf_run_cmd([$tool, '-@', $argFile]);
            if ($exit !== 0) pf_log('share: exiftool exit ' . $exit . ': ' . trim(substr($stderr . ' ' . $stdout, 0, 500)));
        } finally {
            @unlink($argFile);
        }
    }

    /** Delete share sets older than TTL. */
    private static function reap(): void {
        $root = self::root();
        if (!is_dir($root)) return;
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (!preg_match('/^[a-f0-9]{16}$/', basename($d))) continue;
            if (time() - (int)@filemtime($d . '/manifest.json') < self::TTL && is_file($d . '/manifest.json')) continue;
            if (time() - (int)@filemtime($d) < 300) continue;   // still being made
            self::rmTree($d);
        }
    }

    private static function rmTree(string $d): void {
        foreach (scandir($d) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $d . '/' . $e;
            is_dir($p) && !is_link($p) ? self::rmTree($p) : @unlink($p);
        }
        @rmdir($d);
    }
}
