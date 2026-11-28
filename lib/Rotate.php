<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Exif.php';
require_once __DIR__ . '/Exporter.php';
require_once __DIR__ . '/Thumb.php';
require_once __DIR__ . '/People.php';

/**
 * Rotate a photo a quarter turn, losslessly: only the EXIF Orientation tag changes (ExifTool),
 * never the pixels, so nothing is re-compressed and turning it back restores it exactly.
 * Windows, browsers and photo apps show a photo the way its Orientation tag says.
 *
 * Orientation values, in clockwise order: 1 -> 6 -> 3 -> 8 (normal, 90, 180, 270), and for a
 * mirrored photo 2 -> 7 -> 4 -> 5. The index keeps the photo (a rescan only records the new size
 * and date, it never re-describes it); its thumbnail is redrawn and Clean up measures it again.
 * JPEG only: other formats either have no Orientation tag or many programs ignore it there.
 */
final class Rotate {
    private const CW = [1 => 6, 6 => 3, 3 => 8, 8 => 1, 2 => 7, 7 => 4, 4 => 5, 5 => 2];

    public static function canRotate(string $path): bool {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true);
    }

    public static function orientation(string $path): int {
        $o = function_exists('exif_read_data') ? (int)(@exif_read_data($path)['Orientation'] ?? 1) : 1;
        return isset(self::CW[$o]) ? $o : 1;
    }

    /** Turn photo $row a quarter turn ('cw' or 'ccw'). Returns the row the page shows. */
    public static function rotate(array $row, string $dir): array {
        $path = (string)$row['path'];
        if (!is_file($path)) throw new RuntimeException('The photo is no longer on disk.');
        if (!self::canRotate($path)) throw new RuntimeException('Rotating works for JPEG photos only.');
        $tool = Exif::toolPath() ?? Exporter::autoDetect();
        if ($tool === null || !is_file($tool)) throw new RuntimeException('ExifTool is needed to rotate photos. Set its path in Settings > Embed metadata.');
        $cur = self::orientation($path);
        $new = $dir === 'ccw' ? (int)array_search($cur, self::CW, true) : self::CW[$cur];

        // The path goes in an argument file so non-ASCII names survive Windows' command line.
        $tmp = PF_DATA . '/rotate-tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
        $argFile = $tmp . '/' . bin2hex(random_bytes(6)) . '-args.txt';
        file_put_contents($argFile, implode("\n", ['-n', '-Orientation=' . $new, '-m', '-overwrite_original', pf_norm_path($path)]) . "\n");
        try {
            [, $stdout, $stderr] = pf_run_cmd([$tool, '-charset', 'filename=UTF8', '-@', $argFile]);
        } finally {
            @unlink($argFile);
        }
        if (!preg_match('/\b1 image files? updated/i', (string)$stdout)) {
            throw new RuntimeException('Could not rotate: ' . trim((string)($stderr ?: $stdout)));
        }

        $id = (string)$row['id'];
        clearstatcache(true, $path);
        Db::upsertPending($id, $path, (int)filesize($path), (int)filemtime($path));
        // Clean up measures thumbnails; this one changes, so measure it again.
        Db::get()->prepare('UPDATE images SET analyzed_at = NULL, updated_at = :t WHERE id = :id')->execute([':t' => time(), ':id' => $id]);
        People::rotated($id, $dir === 'ccw' ? 'ccw' : 'cw');   // its faces turn with it
        @unlink(PF_THUMBS . '/' . $id . '.jpg');
        Thumb::ensureThumb($id, $path);

        $r = Db::getById($id) ?? [];
        $r['sort_date'] = ($r['taken_at'] ?? '') !== '' ? $r['taken_at'] : (($r['folder_year'] ?? '') !== '' ? $r['folder_year'] : null);
        $r['v'] = (int)($r['mtime'] ?? time());
        $r['orientation'] = $new;
        return $r;
    }
}
