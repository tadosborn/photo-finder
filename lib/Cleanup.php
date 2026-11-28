<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';

/** Clean up: finds blurry, badly exposed and duplicate photos.
 *
 *  Everything here works from the thumbnails Photo Finder already keeps in its data folder
 *  (thumbs/<id>.jpg, made by Thumb::ensureThumb), never from the original files: originals
 *  may be cloud-only OneDrive placeholders, and reading them would download the library.
 *  No AI is called, so analysing costs nothing. The AI's existing descriptions are used as
 *  extra evidence ("Blurry photo of ...", "Very dark nighttime photo ...").
 *
 *  Per photo it stores (images columns, see Db::migrate):
 *    sharpness        variance of the Laplacian of the grayscale thumbnail
 *    sharpness_coarse the same at a quarter of the size (see measureGd)
 *    lum_mean         mean luminance 0-255
 *    lum_std          luminance standard deviation
 *    lum_high         99.5th percentile luminance: the brightest highlights
 *    dark_frac        share of pixels at or below DARK_LEVEL
 *    bright_frac      share of pixels at or above BRIGHT_LEVEL
 *    dhash            64-bit difference hash (9x8 grayscale, left/right), 16 hex characters
 *    dhash_v          the same top/bottom (8x9), used to confirm near-duplicates
 *    analyzed_at      when it was measured (set even without a thumbnail, so a batch moves on)
 *    cleanup_keep     the user said "keep this one": never suggested again
 *
 *  Thresholds were calibrated on a real library of 6,749 photos by looking at the flagged
 *  thumbnails; the numbers are in the constants below. */
final class Cleanup {
    /** Measured at the thumbnail's own size (Thumb::ensureThumb makes them 280 px on the long
     *  side), so current thumbnails are not resampled again; a larger image is scaled down to it,
     *  which keeps the thresholds valid if the thumbnail size ever grows. */
    public const ANALYSIS_DIM = 280;
    public const DARK_LEVEL = 24;
    public const BRIGHT_LEVEL = 235;
    public const BATCH_DEFAULT = 150;
    public const BATCH_MAX = 300;

    // Blurry. Sharpness is the Laplacian variance; the slider moves BLUR_DEFAULT between
    // BLUR_MIN and BLUR_MAX. A photo also has to be "blur-shaped": sharpness below BLUR_RATIO x
    // its coarse sharpness. That keeps sharp photos of smooth things (clear sky, sand, a wall),
    // which score as low as blurred ones, out of the list.
    public const BLUR_DEFAULT = 40.0;
    public const BLUR_MIN     = 10.0;
    public const BLUR_MAX     = 150.0;
    public const BLUR_RATIO   = 0.12;

    // Too dark: almost black overall AND no real highlights (a moon or fireworks against a black
    // sky has bright pixels; an underexposed party shot does not).
    public const DARK_MEAN = 16.0;
    public const DARK_HIGH = 100.0;
    // Too bright: almost white overall.
    public const BRIGHT_MEAN = 232.0;
    public const BRIGHT_FRAC = 0.60;
    // Almost blank: no detail at full size nor at quarter size.
    public const BLANK_SHARP  = 30.0;
    public const BLANK_COARSE = 40.0;
    // With the AI's description saying so, a milder pixel signal is enough.
    public const DESCRIBED_DARK_MEAN   = 40.0;
    public const DESCRIBED_BRIGHT_MEAN = 170.0;

    // Duplicates: Hamming distance between hashes (bits of 64).
    public const DUP_EXACT   = 0;  // identical pictures, same file size and dimensions
    public const DUP_DEFAULT = 4;  // copies, including resized and re-saved ones
    public const DUP_SIMILAR = 6;  // also near-identical shots (bursts)
    public const DUP_MAX     = 6;  // beyond this unrelated photos start to match, and it gets slow

    /** Small photos: under this many px on the long side (old phone and camera pictures). */
    public const SMALL_SIDE = 1600;

    // ------------------------------------------------------------------ measuring

    /** Measure one image file (a thumbnail). Returns null when it cannot be decoded. */
    public static function measure(string $file): ?array {
        $info = @getimagesize($file);
        if (!$info) return null;
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG  => @imagecreatefrompng($file),
            IMAGETYPE_GIF  => @imagecreatefromgif($file),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            IMAGETYPE_BMP  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($file) : false,
            default => false,
        };
        if (!$src) return null;
        return self::measureGd($src);   // GdImage objects free themselves (PHP 8)
    }

    /** GD's C code does the decoding, scaling and grayscale conversion. The pixels are then read
     *  once with imagecolorat() for the histogram and the Laplacian. That PHP loop (about 60k
     *  pixels, ~15 ms per photo) measured faster than imageconvolution() plus reading its result
     *  back (~25 ms), is exact where GD clamps the filter output to 0-255, and needs no temporary
     *  files (imagegd() without a file name writes one to the system temp folder).
     *  Note: converts $src to grayscale in place when it is already small enough. */
    public static function measureGd(\GdImage $src): array {
        if (!imageistruecolor($src)) imagepalettetotruecolor($src);
        $w = imagesx($src);
        $h = imagesy($src);
        if (max($w, $h) > self::ANALYSIS_DIM) {
            $scale = self::ANALYSIS_DIM / max($w, $h);
            $nw = max(3, (int)round($w * $scale));
            $nh = max(3, (int)round($h * $scale));
            $g = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($g, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        } else {
            [$nw, $nh] = [$w, $h];
            $g = $src;
        }
        imagefilter($g, IMG_FILTER_GRAYSCALE);

        // Difference hashes: 9x8 compares left/right neighbours, 8x9 top/bottom.
        $tiny = imagecreatetruecolor(9, 8);
        imagecopyresampled($tiny, $g, 0, 0, 0, 0, 9, 8, $nw, $nh);
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= (imagecolorat($tiny, $x + 1, $y) & 0xFF) > (imagecolorat($tiny, $x, $y) & 0xFF) ? '1' : '0';
            }
        }
        $tall = imagecreatetruecolor(8, 9);
        imagecopyresampled($tall, $g, 0, 0, 0, 0, 8, 9, $nw, $nh);
        $vbits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $vbits .= (imagecolorat($tall, $x, $y + 1) & 0xFF) > (imagecolorat($tall, $x, $y) & 0xFF) ? '1' : '0';
            }
        }

        // Gray values, one array per row (R = G = B after the grayscale filter).
        $rows = self::grayRows($g, $nw, $nh);
        $hist = [];
        foreach ($rows as $r) {
            foreach (array_count_values($r) as $v => $c) $hist[$v] = ($hist[$v] ?? 0) + $c;
        }
        ksort($hist);
        $n = $nw * $nh;
        $sum = 0.0; $sq = 0.0; $dark = 0; $bright = 0; $seen = 0; $high = null;
        foreach ($hist as $v => $c) {
            $sum += $v * $c;
            $sq += $v * $v * $c;
            if ($v <= self::DARK_LEVEL) $dark += $c;
            if ($v >= self::BRIGHT_LEVEL) $bright += $c;
            $seen += $c;
            if ($high === null && $seen >= 0.995 * $n) $high = $v;
        }
        $mean = $sum / $n;
        $std = sqrt(max(0.0, $sq / $n - $mean * $mean));

        // The same sharpness measure at a quarter of the size. Blur removes fine detail but keeps
        // the coarse shapes, so a blurred photo scores far lower at full size than at quarter
        // size; a sharp photo of something smooth (clear sky, sand, a wall) is low at both.
        $qw = max(3, intdiv($nw, 4));
        $qh = max(3, intdiv($nh, 4));
        $q = imagecreatetruecolor($qw, $qh);
        imagecopyresampled($q, $g, 0, 0, 0, 0, $qw, $qh, $nw, $nh);

        return [
            'sharpness'        => round(self::laplacianVariance($rows), 2),
            'sharpness_coarse' => round(self::laplacianVariance(self::grayRows($q, $qw, $qh)), 2),
            'lum_mean'         => round($mean, 2),
            'lum_std'          => round($std, 2),
            'lum_high'         => (float)($high ?? 0),
            'dark_frac'        => round($dark / $n, 4),
            'bright_frac'      => round($bright / $n, 4),
            'dhash'            => self::bitsToHex($bits),
            'dhash_v'          => self::bitsToHex($vbits),
        ];
    }

    private static function bitsToHex(string $bits): string {
        $hex = '';
        foreach (str_split($bits, 4) as $nib) $hex .= dechex(bindec($nib));
        return $hex;
    }

    /** @return int[][] gray value per pixel, one array per row */
    private static function grayRows(\GdImage $g, int $w, int $h): array {
        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $r = [];
            for ($x = 0; $x < $w; $x++) $r[] = imagecolorat($g, $x, $y) & 0xFF;
            $rows[] = $r;
        }
        return $rows;
    }

    /** Variance of the 4-neighbour Laplacian over the interior pixels. */
    private static function laplacianVariance(array $rows): float {
        $h = count($rows);
        $w = $h ? count($rows[0]) : 0;
        $ls = 0; $lsq = 0; $ln = 0;
        for ($y = 1, $ey = $h - 1; $y < $ey; $y++) {
            $up = $rows[$y - 1]; $c = $rows[$y]; $dn = $rows[$y + 1];
            for ($x = 1, $ex = $w - 1; $x < $ex; $x++) {
                $l = $up[$x] + $dn[$x] + $c[$x - 1] + $c[$x + 1] - 4 * $c[$x];
                $ls += $l;
                $lsq += $l * $l;
            }
            $ln += max(0, $ex - 1);
        }
        if (!$ln) return 0.0;
        $m = $ls / $ln;
        return max(0.0, $lsq / $ln - $m * $m);
    }

    // ------------------------------------------------------------------ storing

    private const METRICS = ['sharpness', 'sharpness_coarse', 'lum_mean', 'lum_std', 'lum_high',
                             'dark_frac', 'bright_frac', 'dhash', 'dhash_v'];

    /** Measure a photo's thumbnail and store the result. Returns the metrics, or null when the
     *  photo has no thumbnail yet (analyzed_at is still set, so a batch does not stall on it;
     *  indexing the photo again measures it). */
    public static function analyzeAndStore(string $id): ?array {
        $m = self::measureId($id);
        self::store($id, $m);
        return $m;
    }

    private static function measureId(string $id): ?array {
        $thumb = PF_THUMBS . '/' . $id . '.jpg';
        return is_file($thumb) ? self::measure($thumb) : null;
    }

    private static function store(string $id, ?array $m): void {
        $sets = [];
        $params = [':id' => $id, ':t' => time()];
        foreach (self::METRICS as $col) {
            $sets[] = "{$col} = :{$col}";
            $params[":{$col}"] = $m[$col] ?? null;
        }
        Db::get()->prepare('UPDATE images SET ' . implode(', ', $sets) . ', analyzed_at = :t WHERE id = :id')
            ->execute($params);
    }

    /** Analyse up to $limit indexed photos that have not been analysed yet. Resumable: it only
     *  takes rows whose analyzed_at is still NULL. */
    public static function analyzeBatch(int $limit): array {
        $limit = max(1, min(self::BATCH_MAX, $limit));
        $t0 = microtime(true);
        $pdo = Db::get();
        $st = $pdo->prepare(
            "SELECT id FROM images WHERE status = 'ok' AND analyzed_at IS NULL ORDER BY rowid LIMIT :n"
        );
        $st->bindValue(':n', $limit, PDO::PARAM_INT);
        $st->execute();
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        $st->closeCursor();
        // Measure first, then write in one short transaction: the indexing worker writes its
        // heartbeat every 2 seconds and must not wait for a whole batch of image work.
        $measured = [];
        $noThumb = 0;
        foreach ($ids as $id) {
            $measured[(string)$id] = $m = self::measureId((string)$id);
            if ($m === null) $noThumb++;
        }
        $done = count($measured);
        $pdo->beginTransaction();
        try {
            foreach ($measured as $id => $m) self::store((string)$id, $m);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['processed' => $done, 'no_thumbnail_batch' => $noThumb,
                'seconds' => round(microtime(true) - $t0, 2)] + self::status();
    }

    public static function status(): array {
        $r = Db::get()->query(
            "SELECT COUNT(*) AS total,
                    SUM(analyzed_at IS NOT NULL) AS analyzed,
                    SUM(analyzed_at IS NOT NULL AND dhash IS NULL) AS no_thumbnail,
                    SUM(COALESCE(cleanup_keep, 0) = 1) AS kept
             FROM images WHERE status = 'ok'"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($r['total'] ?? 0);
        $analyzed = (int)($r['analyzed'] ?? 0);
        return [
            'total' => $total,
            'analyzed' => $analyzed,
            'remaining' => $total - $analyzed,
            'no_thumbnail' => (int)($r['no_thumbnail'] ?? 0),
            'kept' => (int)($r['kept'] ?? 0),
        ];
    }

    /** What the page needs to know about the thresholds. */
    public static function config(): array {
        return [
            'blur_default' => self::BLUR_DEFAULT, 'blur_min' => self::BLUR_MIN, 'blur_max' => self::BLUR_MAX,
            'dup_exact' => self::DUP_EXACT, 'dup_default' => self::DUP_DEFAULT, 'dup_similar' => self::DUP_SIMILAR,
            'batch' => self::BATCH_DEFAULT,
        ];
    }

    /** Mark photos "keep" (or clear it): kept photos are never suggested again. */
    public static function setKeep(array $ids, bool $keep): int {
        $ids = array_values(array_unique(array_filter(array_map(
            fn($i) => (string)preg_replace('/[^a-f0-9]/i', '', (string)$i), $ids), fn($i) => $i !== '')));
        if (!$ids) return 0;
        $pdo = Db::get();
        $n = 0;
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($ids, 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare("UPDATE images SET cleanup_keep = ? WHERE id IN ($ph)");
                $st->execute(array_merge([$keep ? 1 : 0], $chunk));
                $n += $st->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $n;
    }

    public static function clearAllKeep(): int {
        return (int)Db::get()->exec('UPDATE images SET cleanup_keep = 0 WHERE cleanup_keep = 1');
    }

    // ------------------------------------------------------------------ what the AI said

    /** Ids whose AI description says the photo is blurry / dark / overexposed / blank / low
     *  resolution: id => [kind => phrase]. The FTS index finds the candidates quickly; a
     *  stricter regex then drops deliberate blur ("blurred background") and colour words
     *  ("dark hair", "black night sky"). */
    public static function describedAs(): array {
        $fts = 'description : (blurri OR blur* OR unfocus* OR "out of focus" OR overexpos* OR underexpos*'
             . ' OR "very dark" OR "extremely dark" OR "too dark" OR "nearly black" OR "completely black"'
             . ' OR "almost black" OR "entirely black" OR "washed out" OR "blown out" OR "low resolution"'
             . ' OR pixelat* OR "not identifiable" OR unidentifiable OR unclear OR blank OR "minimal visibility")';
        $pdo = Db::get();
        try {
            $st = $pdo->prepare(
                "SELECT i.id, i.description FROM images_fts f JOIN images i ON i.id = f.id
                 WHERE images_fts MATCH :q AND i.status = 'ok'"
            );
            $st->execute([':q' => $fts]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            $rows = $pdo->query(
                "SELECT id, description FROM images WHERE status = 'ok' AND (description LIKE '%blur%'
                 OR description LIKE '%focus%' OR description LIKE '%expos%' OR description LIKE '%dark%'
                 OR description LIKE '%black%' OR description LIKE '%resolution%' OR description LIKE '%blank%'
                 OR description LIKE '%pixelat%' OR description LIKE '%identifiable%' OR description LIKE '%unclear%'
                 OR description LIKE '%washed%' OR description LIKE '%visibility%')"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        $out = [];
        foreach ($rows as $r) {
            $kinds = self::classifyDescription((string)$r['description']);
            if ($kinds) $out[(string)$r['id']] = $kinds;
        }
        return $out;
    }

    /** kind => matched phrase, for the defects the description states. */
    public static function classifyDescription(string $d): array {
        // Deliberately soft parts of a sharp photo are not a defect, and "slightly" is not obvious.
        $strip = [
            '/\b(?:blurr(?:ed|y)|soft[- ]focus|out[- ]of[- ]focus)\s+(?:\w+\s+){0,2}?(?:background|backdrop|landscape|foreground|distance|distant|lights|cars|figures|people|shapes?|scenery|trees|movement through)\b/i',
            '/\b(?:blurred|blurry|out[- ]of[- ]focus)\s+in\s+(?:the\s+)?(?:background|foreground|distance)\b/i',
            '/\b(?:background|people|others?|faces?|figures?|lights?|windows?)\s+(?:\w+\s+){0,2}(?:is|are|appear|appears)\s+(?:\w+\s+)?(?:blurred|blurry|out of focus)\b/i',
            '/\bmotion blur (?:suggests|indicating|effect|through)\b/i',
            '/\bslightly (?:blurred|blurry|out[- ]of[- ]focus|soft)\b/i',
        ];
        $d = preg_replace($strip, ' ', $d) ?? $d;
        $pats = [
            'blurry' => '/\b(?:(?:very |severely |extremely )?blurry|(?:severely |very )?out[- ]of[- ]focus|unfocused|motion[- ]blurred|blurred (?:motion|movement|photo|photograph|image|shot|scene|indoor|outdoor|view|close-up)|^blurred\b|(?:photo|image|picture|shot) is (?:\w+ ){0,2}blurred|focus is soft|due to (?:image )?blur|focus issues)/i',
            'dark'   => '/\b(?:(?:very|extremely|too|mostly|almost completely|nearly completely) dark(?! (?:hair|clothing|clothes|suit|jacket|blue|green|red|brown|wood|sky|background|backdrop))|(?:nearly|almost|completely|entirely|mostly)(?: entirely)? black(?! (?:and|dog|cat|car|clothing|shirt|toy|hair|night|sky|background|backdrop|space))|under-?exposed|minimal visibility|pitch[- ]black)/i',
            'bright' => '/\b(?:over-?exposed|washed[- ]out|blown[- ]out|(?:mostly|almost completely|entirely|nearly) white (?:surface|image|frame|screen))/i',
            'blank'  => '/\b(?:(?:mostly |nearly |almost )?blank (?:image|photo|frame|screen|picture)|not identifiable|unidentifiable|minimal visible detail)/i',
            'lowres' => '/\b(?:low[- ]resolution|low[- ]res|pixelated)\b/i',
        ];
        $out = [];
        foreach ($pats as $kind => $re) {
            if (preg_match($re, $d, $m)) $out[$kind] = strtolower(trim($m[0]));
        }
        return $out;
    }

    // ------------------------------------------------------------------ candidates

    private const ROW_COLS = "id, path, size, mtime, width, height, taken_at, SUBSTR(COALESCE(description, ''), 1, 400) AS description,
        tags, faces, lat, lon, sharpness, sharpness_coarse, lum_mean, lum_std, lum_high, dark_frac, bright_frac,
        dhash, dhash_v, COALESCE(cleanup_keep, 0) AS cleanup_keep";

    private static function rowOut(array $r): array {
        foreach (['size', 'mtime', 'width', 'height', 'cleanup_keep'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        }
        foreach (['sharpness', 'sharpness_coarse', 'lum_mean', 'lum_std', 'lum_high', 'dark_frac', 'bright_frac', 'lat', 'lon'] as $k) {
            $r[$k] = $r[$k] === null ? null : (float)$r[$k];
        }
        return $r;
    }

    public static function isBlank(array $r): bool {
        return $r['sharpness'] !== null && (float)$r['sharpness'] < self::BLANK_SHARP
            && (float)$r['sharpness_coarse'] < self::BLANK_COARSE;
    }

    /** Blur-shaped: much less fine detail than coarse detail (see measureGd). */
    public static function blurShaped(array $r): bool {
        return (float)$r['sharpness'] < self::BLUR_RATIO * max(1.0, (float)$r['sharpness_coarse']);
    }

    /** Blurry candidates, softest first. Each row has:
     *    pixel_blur  true when the pixels alone qualify at the slider's most sensitive end
     *                (sharpness < BLUR_MAX and blur-shaped); the page compares 'sharpness'
     *                with its slider;
     *    described   what the AI's description says (blurry / lowres), shown regardless of
     *                the slider, because the AI saw the photo at full size and the thumbnail
     *                cannot show mild blur. */
    public static function blurry(): array {
        $said = self::describedAs();
        $rows = Db::get()->query(
            'SELECT ' . self::ROW_COLS . " FROM images
             WHERE status = 'ok' AND sharpness IS NOT NULL AND COALESCE(cleanup_keep, 0) = 0
             ORDER BY sharpness ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $id = (string)$r['id'];
            $d = array_intersect_key($said[$id] ?? [], ['blurry' => 1, 'lowres' => 1]);
            if ((float)$r['sharpness'] >= self::BLUR_MAX && !$d) continue;
            $r = self::rowOut($r);
            $r['pixel_blur'] = $r['sharpness'] < self::BLUR_MAX && self::blurShaped($r) && !self::isBlank($r);
            if (!$r['pixel_blur'] && !$d) continue;
            $r['described'] = $d;
            $out[] = $r;
        }
        return $out;
    }

    /** Too dark, too bright, or (nearly) blank. Each row has 'problems':
     *  a list of ['kind' => dark|bright|blank, 'text' => ..., 'ai' => bool]. */
    public static function exposure(): array {
        $said = self::describedAs();
        $rows = Db::get()->query(
            'SELECT ' . self::ROW_COLS . " FROM images
             WHERE status = 'ok' AND lum_mean IS NOT NULL AND COALESCE(cleanup_keep, 0) = 0
             ORDER BY lum_mean ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $id = (string)$r['id'];
            $d = $said[$id] ?? [];
            $mean = (float)$r['lum_mean'];
            // Cheap pre-check before building the row.
            if ($mean > self::DARK_MEAN && $mean < self::BRIGHT_MEAN && (float)$r['sharpness'] >= self::BLANK_SHARP
                && !array_intersect_key($d, ['dark' => 1, 'bright' => 1, 'blank' => 1])) continue;
            $r = self::rowOut($r);
            $p = [];
            if (self::isBlank($r)) {
                $p[] = ['kind' => 'blank', 'text' => 'Almost blank: hardly any detail', 'ai' => false];
            } elseif (isset($d['blank']) && (float)$r['lum_std'] < 30 && (float)$r['sharpness'] < 100) {
                $p[] = ['kind' => 'blank', 'text' => 'Very little detail', 'ai' => true];
            }
            if ($mean <= self::DARK_MEAN && (float)$r['lum_high'] < self::DARK_HIGH) {
                $p[] = ['kind' => 'dark', 'text' => sprintf('Too dark: average brightness %d of 255, no highlights', round($mean)), 'ai' => false];
            } elseif (isset($d['dark']) && $mean <= self::DESCRIBED_DARK_MEAN) {
                $p[] = ['kind' => 'dark', 'text' => sprintf('Very dark: average brightness %d of 255', round($mean)), 'ai' => true];
            }
            if ($mean >= self::BRIGHT_MEAN && (float)$r['bright_frac'] >= self::BRIGHT_FRAC) {
                $p[] = ['kind' => 'bright', 'text' => sprintf('Too bright: %d%% of it is nearly white', round((float)$r['bright_frac'] * 100)), 'ai' => false];
            } elseif (isset($d['bright']) && $mean >= self::DESCRIBED_BRIGHT_MEAN) {
                $p[] = ['kind' => 'bright', 'text' => sprintf('Washed out: average brightness %d of 255', round($mean)), 'ai' => true];
            }
            if (!$p) continue;
            $r['problems'] = $p;
            $r['described'] = array_intersect_key($d, ['dark' => 1, 'bright' => 1, 'blank' => 1]);
            $out[] = $r;
        }
        return $out;
    }

    /** Small photos (under SMALL_SIDE px on the long side), smallest first: candidates for
     *  Enlarge (lib/Deblur.php). The page narrows the list further by size. Needs no analysis. */
    public static function small(): array {
        self::correctCappedSizes();
        // The limit is a number in the SQL itself: bound through execute() it would be text, and
        // SQLite orders every number before any text, so every photo would count as small.
        $rows = Db::get()->query(
            'SELECT ' . self::ROW_COLS . " FROM images
             WHERE status = 'ok' AND width > 0 AND height > 0 AND MAX(width, height) < " . (int)self::SMALL_SIDE . "
               AND COALESCE(cleanup_keep, 0) = 0
             ORDER BY width * height ASC, path"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'rowOut'], $rows);
    }

    /** Before 1.0.8 the index recorded the size of the copy the describing AI was sent (its long
     *  side cut to max_image_dim, 1568 by default), not the photo's own, and Fix blur's copies
     *  took their original's size (a cloud result is larger). Those rows are re-measured from the
     *  file's header (about 1.5 ms each; a corrected row no longer matches, so later calls only
     *  re-check the few photos that really are that size, and Fix blur's copies). */
    private static function correctCappedSizes(): void {
        $caps = array_values(array_unique([1568, (int)(pf_load_settings()['max_image_dim'] ?? 1568)]));
        $pdo = Db::get();
        $rows = $pdo->query("SELECT id, path, width, height FROM images
                             WHERE status = 'ok' AND (MAX(width, height) IN (" . implode(',', array_map('intval', $caps)) . ")
                                OR path LIKE '% (restored%).jpg' OR path LIKE '% (deblurred%).jpg')")
                    ->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;
        $upd = $pdo->prepare('UPDATE images SET width = :w, height = :h WHERE id = :id');
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $s = pf_image_size((string)$r['path']);
                if ($s && $s !== [(int)$r['width'], (int)$r['height']]) $upd->execute([':w' => $s[0], ':h' => $s[1], ':id' => $r['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Originals kept by "Replace the original" ("<name> (original).jpg"), newest first, each with
     *  the photo that replaced it (same folder, the name without the suffix) when it is indexed. */
    public static function keptOriginals(): array {
        $rows = Db::get()->query(
            'SELECT ' . self::ROW_COLS . " FROM images
             WHERE status IN ('ok', 'pending') AND (path LIKE '% (original).%' OR path LIKE '% (original %).%')
               AND COALESCE(cleanup_keep, 0) = 0
             ORDER BY mtime DESC, path"
        )->fetchAll(PDO::FETCH_ASSOC);
        $byPath = Db::get()->prepare('SELECT id, path, width, height FROM images WHERE path = :p');
        $out = [];
        foreach ($rows as $r) {
            $r = self::rowOut($r);
            $now = (string)preg_replace('/ \(original(?: \d+)?\)(\.[^.\/]*)?$/i', '$1', (string)$r['path']);
            $byPath->execute([':p' => $now]);
            $n = $byPath->fetch(PDO::FETCH_ASSOC) ?: null;
            $byPath->closeCursor();
            $r['replaced_by'] = $n ? ['id' => (string)$n['id'], 'name' => basename((string)$n['path']), 'width' => (int)$n['width'], 'height' => (int)$n['height']] : null;
            $out[] = $r;
        }
        return $out;
    }

    // ------------------------------------------------------------------ duplicates

    /** Groups of copies and near-copies, best copy first in each group.
     *
     *  $maxDist = 0 finds exact copies only: identical hashes, file size and dimensions.
     *  1..DUP_MAX also finds resized/re-saved copies and burst shots: the left/right hashes may
     *  differ in at most $maxDist of 64 bits, and the top/bottom hashes must agree as well
     *  (within $maxDist + 2 bits), which stops unrelated photos that merely share a layout
     *  (a bright band down the middle, say) from being grouped.
     *
     *  Finding pairs never compares every photo with every other: multi-index hashing splits
     *  each hash into $maxDist+1 chunks; two hashes within $maxDist bits must agree exactly on
     *  at least one chunk (pigeonhole), so only photos sharing a chunk value are compared.
     *  Blank frames (which all hash alike) are left to the exposure tab. */
    public static function duplicates(int $maxDist): array {
        $maxDist = max(0, min(self::DUP_MAX, $maxDist));
        self::correctCappedSizes();   // the best copy is the one with the most pixels
        $rows = Db::get()->query(
            'SELECT ' . self::ROW_COLS . " FROM images
             WHERE status = 'ok' AND dhash IS NOT NULL ORDER BY rowid"
        )->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map([self::class, 'rowOut'], $rows);
        $n = count($rows);
        $parent = $n ? range(0, $n - 1) : [];
        $find = function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) { $parent[$i] = $parent[$parent[$i]]; $i = $parent[$i]; }
            return $i;
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a); $rb = $find($b);
            if ($ra !== $rb) $parent[max($ra, $rb)] = min($ra, $rb);
        };

        // Exact copies.
        $byExact = [];
        foreach ($rows as $i => $r) {
            $byExact[$r['dhash'] . '|' . $r['dhash_v'] . '|' . (int)$r['size']][] = $i;
        }
        foreach ($byExact as $members) {
            for ($k = 1, $c = count($members); $k < $c; $k++) {
                if (self::sameDims($rows[$members[0]], $rows[$members[$k]])) $union($members[0], $members[$k]);
            }
        }

        if ($maxDist > 0) {
            $usable = [];
            foreach ($rows as $i => $r) {
                if (!self::isBlank($r) && $r['dhash_v'] !== null) $usable[] = $i;
            }
            $bin = [];
            $map = ['0' => '0000', '1' => '0001', '2' => '0010', '3' => '0011', '4' => '0100', '5' => '0101',
                    '6' => '0110', '7' => '0111', '8' => '1000', '9' => '1001', 'a' => '1010', 'b' => '1011',
                    'c' => '1100', 'd' => '1101', 'e' => '1110', 'f' => '1111'];
            foreach ($usable as $i) $bin[$i] = strtr(strtolower($rows[$i]['dhash']), $map);
            $chunks = $maxDist + 1;
            $compared = [];
            for ($c = 0, $start = 0; $c < $chunks; $c++) {
                $len = intdiv(64, $chunks) + ($c < 64 % $chunks ? 1 : 0);
                $buckets = [];
                foreach ($usable as $i) $buckets[substr($bin[$i], $start, $len)][] = $i;
                $start += $len;
                foreach ($buckets as $members) {
                    $m = count($members);
                    for ($a = 0; $a < $m - 1; $a++) {
                        $ia = $members[$a];
                        for ($b = $a + 1; $b < $m; $b++) {
                            $ib = $members[$b];
                            $key = $ia * $n + $ib;
                            if (isset($compared[$key])) continue;
                            $compared[$key] = true;
                            if (self::hamming($rows[$ia]['dhash'], $rows[$ib]['dhash']) <= $maxDist
                                && self::hamming($rows[$ia]['dhash_v'], $rows[$ib]['dhash_v']) <= $maxDist + 2) {
                                $union($ia, $ib);
                            }
                        }
                    }
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $n; $i++) $groups[$find($i)][] = $i;
        $out = [];
        $extra = 0;
        foreach ($groups as $members) {
            if (count($members) < 2) continue;
            usort($members, fn($a, $b) => self::betterCopy($rows[$a], $rows[$b]));
            $best = $rows[$members[0]];
            $items = [];
            $open = 0;
            foreach ($members as $pos => $i) {
                $r = $rows[$i];
                $dist = self::hamming($best['dhash'], $r['dhash']);
                $r['best'] = $pos === 0;
                $r['dist'] = $dist;
                $r['relation'] = $pos === 0 ? 'best' : self::relation($best, $r, $dist);
                if ($pos > 0 && !$r['cleanup_keep']) $open++;
                $items[] = $r;
            }
            // Nothing to decide once every extra copy is kept.
            if ($open === 0) continue;
            $extra += $open;
            $rels = array_count_values(array_column(array_slice($items, 1), 'relation'));
            $out[] = ['members' => $items, 'kind' => isset($rels['similar']) ? 'similar' : (count($rels) === 1 && isset($rels['exact']) ? 'exact' : 'copies')];
        }
        // Exact copies first, then other copies, then similar shots; biggest groups first.
        $rank = ['exact' => 0, 'copies' => 1, 'similar' => 2];
        usort($out, function ($a, $b) use ($rank) {
            return [$rank[$a['kind']], -count($a['members']), (string)$a['members'][0]['path']]
               <=> [$rank[$b['kind']], -count($b['members']), (string)$b['members'][0]['path']];
        });
        return ['groups' => $out, 'extra_copies' => $extra, 'max_dist' => $maxDist];
    }

    /** How a group member relates to the group's best copy. */
    private static function relation(array $best, array $r, int $dist): string {
        if ($r['dhash'] === $best['dhash'] && $r['dhash_v'] === $best['dhash_v']
            && (int)$r['size'] === (int)$best['size'] && self::sameDims($r, $best)) return 'exact';
        $pb = (int)$best['width'] * (int)$best['height'];
        $pr = (int)$r['width'] * (int)$r['height'];
        if ($dist <= self::DUP_DEFAULT && $pr > 0 && $pr < $pb && self::sameShape($r, $best)) return 'smaller';
        if ($dist <= 1 && self::hamming($best['dhash_v'], $r['dhash_v']) <= 2) return 'same';
        return 'similar';
    }

    private static function sameDims(array $a, array $b): bool {
        if (!$a['width'] || !$b['width'] || !$a['height'] || !$b['height']) return true;  // unknown: size decides
        return (int)$a['width'] === (int)$b['width'] && (int)$a['height'] === (int)$b['height'];
    }

    /** Same orientation and roughly the same aspect ratio (resized copies are often cropped a
     *  little: 3552x2000 and 320x192 differ by 6%). */
    private static function sameShape(array $a, array $b): bool {
        if (!$a['width'] || !$b['width'] || !$a['height'] || !$b['height']) return true;
        $ra = $a['width'] / $a['height'];
        $rb = $b['width'] / $b['height'];
        return abs($ra - $rb) / max($ra, $rb) <= 0.10;
    }

    /** Sort comparator: the better copy first. Most pixels, then the larger file, then the
     *  earlier date (capture date, else file date), then the shorter path. */
    private static function betterCopy(array $a, array $b): int {
        $pa = (int)$a['width'] * (int)$a['height'];
        $pb = (int)$b['width'] * (int)$b['height'];
        if ($pa !== $pb) return $pb <=> $pa;
        if ((int)$a['size'] !== (int)$b['size']) return (int)$b['size'] <=> (int)$a['size'];
        $da = self::dateKey($a); $db = self::dateKey($b);
        if ($da !== $db) return strcmp($da, $db);
        return [strlen((string)$a['path']), (string)$a['path']] <=> [strlen((string)$b['path']), (string)$b['path']];
    }

    private static function dateKey(array $r): string {
        $t = trim((string)($r['taken_at'] ?? ''));
        if ($t !== '') return str_replace('T', ' ', $t);
        return $r['mtime'] ? date('Y-m-d H:i:s', (int)$r['mtime']) : '9999';
    }

    /** Bits that differ between two 16-hex-character hashes. */
    public static function hamming(?string $a, ?string $b): int {
        if ($a === null || $b === null || strlen($a) !== 16 || strlen($b) !== 16) return 64;
        return self::popcount32(hexdec(substr($a, 0, 8)) ^ hexdec(substr($b, 0, 8)))
             + self::popcount32(hexdec(substr($a, 8, 8)) ^ hexdec(substr($b, 8, 8)));
    }

    private static function popcount32(int $x): int {
        $x &= 0xFFFFFFFF;
        $x -= ($x >> 1) & 0x55555555;
        $x = ($x & 0x33333333) + (($x >> 2) & 0x33333333);
        $x = ($x + ($x >> 4)) & 0x0F0F0F0F;
        $x += $x >> 8;
        $x += $x >> 16;
        return $x & 0x3F;
    }
}
