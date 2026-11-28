<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Exif.php';
require_once __DIR__ . '/Exporter.php';
require_once __DIR__ . '/Thumb.php';   // the kept original's thumbnail

/**
 * Photo enhancement: white balance, levels, brightness, contrast, saturation and mild
 * sharpening, with GD only.
 *
 * The browser draws the preview (the "Enhance" block in index.php) and this class saves the
 * full-size photo from the same settings and the same arithmetic, so the preview is what gets
 * saved (tested: identical pixels). knobs(), gains(), curves() and tables() are mirrored by
 * enhKnobs(), enhGains(), enhCurves() and enhTables() in index.php: change one, change the other.
 *
 * Per pixel:
 *   L_k(v)  per-channel curve: white-balance gain (warmth, tint) -> levels (black and white
 *           point) -> brightness (midtone curve (1+k)x/(1+kx)) -> contrast (S-curve)
 *   out_c = sum_k M[c][k] * L_k(o_k) + a * (Y(L(o)) - Y(L(b)))
 *           M = saturation around Rec.601 luma, b = the blurred pixel, a = sharpen amount.
 *           The sharpening is a luma-only unsharp mask, so it adds no colour fringes.
 * All of it folds into integer tables; the full-size pass is three table lookups per input
 * pixel (one image) or six (with sharpening), see apply().
 *
 * Sharpening works at screen scale: the blur is made at the preview size (1600 px) and
 * enlarged, so the full-size photo gets the same visible crispness the preview shows. It
 * helps slightly soft photos. It cannot undo real blur (focus or camera shake).
 *
 * The photo is processed in its stored orientation and keeps its EXIF Orientation, so face
 * regions, dimensions and every other orientation-dependent tag stay valid. Only the preview
 * is rotated (GD ignores EXIF orientation).
 */
final class Enhance {
    public const PREVIEW_MAX = 1600;
    public const MAX_PIXELS = 50_000_000;
    public const KEYS = ['brightness', 'contrast', 'warmth', 'tint', 'saturation', 'sharpen'];
    private const STATS_MAX = 400;
    private const FP = 64;                       // table fixed point: 6 fractional bits
    private const W = [0.299, 0.587, 0.114];     // Rec.601 luma, as GD's grayscale filter
    private const QUALITY = 92;

    // ---- Settings ----

    public static function neutral(): array {
        return ['levels' => null, 'brightness' => 0, 'contrast' => 0, 'warmth' => 0, 'tint' => 0, 'saturation' => 0, 'sharpen' => 0];
    }

    /** Clamp whatever the page sent to the ranges the sliders offer. */
    public static function sanitize(array $in): array {
        $p = self::neutral();
        foreach (self::KEYS as $k) {
            $v = (int)round((float)($in[$k] ?? 0));
            $p[$k] = max($k === 'sharpen' ? 0 : -100, min(100, $v));
        }
        $lv = $in['levels'] ?? null;
        if (is_array($lv) && isset($lv['lo'], $lv['hi'])) {
            $lo = max(0.0, min(0.3, (float)$lv['lo']));
            $hi = max(0.5, min(1.0, (float)$lv['hi']));
            if ($hi - $lo < 0.3) $hi = min(1.0, $lo + 0.3);
            if ($lo > 0.0 || $hi < 1.0) $p['levels'] = ['lo' => round($lo, 4), 'hi' => round($hi, 4)];
        }
        return $p;
    }

    public static function isNeutral(array $p): bool {
        if ($p['levels'] !== null) return false;
        foreach (self::KEYS as $k) if ($p[$k] !== 0) return false;
        return true;
    }

    /** Slider values to the numbers the curves use (mirrored by enhKnobs()). */
    private static function knobs(array $p): array {
        $b = $p['brightness'] / 100;
        return [
            'k' => 2 ** (2.5 * $b) - 1,            // brightness: slope of the midtone curve at black is 1+k
            'c' => $p['contrast'] / 100 * 0.8,     // contrast: S-curve strength, monotonic for |c| <= 1
            'T' => $p['warmth'] / 100 * 0.4,       // warmth: log gain, red up / blue down
            'N' => $p['tint'] / 100 * 0.3,         // tint: log gain, green down (magenta) / up (green)
            's' => 1 + $p['saturation'] / 100,     // saturation factor around luma
            'a' => $p['sharpen'] / 100,            // unsharp-mask amount
        ];
    }

    /** White-balance gains, scaled so a mid grey keeps its brightness. */
    private static function gains(float $T, float $N): array {
        $g = [exp($T), exp(-$N), exp(-$T)];
        $n = self::W[0] * $g[0] + self::W[1] * $g[1] + self::W[2] * $g[2];
        return [$g[0] / $n, $g[1] / $n, $g[2] / $n];
    }

    /** Per-channel curves L[ch][0..255] -> 0..255 (float). Mirrored by enhCurves(). */
    public static function curves(array $p): array {
        $q = self::knobs($p);
        $g = self::gains($q['T'], $q['N']);
        $lo = $p['levels'] !== null ? $p['levels']['lo'] : 0.0;
        $hi = $p['levels'] !== null ? $p['levels']['hi'] : 1.0;
        $span = $hi - $lo;
        $k = $q['k'];
        $c = $q['c'];
        $L = [[], [], []];
        for ($ch = 0; $ch < 3; $ch++) {
            for ($v = 0; $v < 256; $v++) {
                $x = ($g[$ch] * $v / 255 - $lo) / $span;
                $x = $x < 0 ? 0.0 : ($x > 1 ? 1.0 : $x);
                if ($k != 0.0) $x = (1 + $k) * $x / (1 + $k * $x);
                if ($c != 0.0) $x = $x + $c * (2 * $x - 1) * $x * (1 - $x);
                $L[$ch][$v] = 255 * $x;
            }
        }
        return $L;
    }

    /** Integer tables: T[c][k][v] (colour, incl. the sharpening's own-pixel term) and U[k][v]
     *  (the blurred pixel's term), both scaled by FP. Mirrored by enhTables(). */
    public static function tables(array $p): array {
        $L = self::curves($p);
        $q = self::knobs($p);
        $s = $q['s'];
        $a = $q['a'];
        $T = [];
        $U = [];
        for ($c = 0; $c < 3; $c++) {
            for ($k = 0; $k < 3; $k++) {
                $m = ($c === $k ? $s : 0.0) + (1 - $s) * self::W[$k] + $a * self::W[$k];
                for ($v = 0; $v < 256; $v++) $T[$c][$k][$v] = (int)floor(self::FP * $m * $L[$k][$v] + 0.5);
            }
        }
        for ($k = 0; $k < 3; $k++) {
            $m = $a * self::W[$k];
            for ($v = 0; $v < 256; $v++) $U[$k][$v] = (int)floor(self::FP * $m * $L[$k][$v] + 0.5);
        }
        return [$T, $U];
    }

    // ---- Pixels ----

    /** Enhance $im in place. $blur (same size as $im, read shifted by $bx,$by) is needed when
     *  sharpen > 0; see blurFor(). */
    public static function apply(\GdImage $im, array $p, ?\GdImage $blur = null, int $bx = 0, int $by = 0): void {
        [$T, $U] = self::tables($p);
        if ($p['sharpen'] <= 0) $blur = null;
        // Pack the three output channels into one integer, 21 bits each, so one sum per pixel
        // does all three: field c = bias + sum_k T[c][k][in_k] - sum_k U[k][blur_k].
        $lowest = 0;
        $highest = 0;
        for ($c = 0; $c < 3; $c++) {
            $min = 0;
            $max = 0;
            for ($k = 0; $k < 3; $k++) {
                $min += min($T[$c][$k]) - ($blur ? max($U[$k]) : 0);
                $max += max($T[$c][$k]) - ($blur ? min($U[$k]) : 0);
            }
            $lowest = min($lowest, $min);
            $highest = max($highest, $max);
        }
        $bias = -$lowest;
        $top = $highest + $bias;
        if ($top >= (1 << 21)) throw new RuntimeException('Enhance settings out of range');
        $sh2 = 1 << 42;
        $sh1 = 1 << 21;
        $all = $sh2 + $sh1 + 1;
        $tp = [[], [], []];
        $up = [[], [], []];
        for ($k = 0; $k < 3; $k++) {
            $base = $k === 0 ? $bias * $all : 0;
            for ($v = 0; $v < 256; $v++) {
                $tp[$k][$v] = $base + $T[0][$k][$v] * $sh2 + $T[1][$k][$v] * $sh1 + $T[2][$k][$v];
                $up[$k][$v] = $U[$k][$v] * $all;
            }
        }
        // field -> clamped 0..255, already shifted into place
        $cr = [];
        $cg = [];
        $cb = [];
        for ($i = 0; $i <= $top; $i++) {
            $v = (int)floor(($i - $bias + self::FP / 2) / self::FP);
            $v = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            $cr[$i] = $v << 16;
            $cg[$i] = $v << 8;
            $cb[$i] = $v;
        }
        [$t0, $t1, $t2] = $tp;
        $m = 0x1FFFFF;
        $w = imagesx($im);
        $h = imagesy($im);
        imagealphablending($im, false);
        if ($blur === null) {
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $c = imagecolorat($im, $x, $y);
                    $s = $t0[($c >> 16) & 255] + $t1[($c >> 8) & 255] + $t2[$c & 255];
                    imagesetpixel($im, $x, $y, $cr[$s >> 42] | $cg[($s >> 21) & $m] | $cb[$s & $m]);
                }
            }
            return;
        }
        [$u0, $u1, $u2] = $up;
        $bw = imagesx($blur);
        $bh = imagesy($blur);
        $xs = [];
        for ($x = 0; $x < $w; $x++) $xs[$x] = min($bw - 1, max(0, $x - $bx));
        for ($y = 0; $y < $h; $y++) {
            $yy = min($bh - 1, max(0, $y - $by));
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $d = imagecolorat($blur, $xs[$x], $yy);
                $s = $t0[($c >> 16) & 255] + $t1[($c >> 8) & 255] + $t2[$c & 255]
                   - $u0[($d >> 16) & 255] - $u1[($d >> 8) & 255] - $u2[$d & 255];
                imagesetpixel($im, $x, $y, $cr[$s >> 42] | $cg[($s >> 21) & $m] | $cb[$s & $m]);
            }
        }
    }

    /** The blurred copy the unsharp mask subtracts, at screen scale: GD's 3x3 Gaussian on the
     *  preview-size image, enlarged back to full size. Returns [image, shiftX, shiftY]: GD's
     *  filtered enlargement lands (f-1)/2 pixels up and to the left, so apply() reads it back
     *  shifted by that much. */
    public static function blurFor(\GdImage $im): array {
        $w = imagesx($im);
        $h = imagesy($im);
        if (max($w, $h) <= self::PREVIEW_MAX * 1.1) {
            $b = imagecreatetruecolor($w, $h);
            imagecopy($b, $im, 0, 0, 0, 0, $w, $h);
            imagefilter($b, IMG_FILTER_GAUSSIAN_BLUR);
            return [$b, 0, 0];
        }
        $small = self::fit($im, self::PREVIEW_MAX);
        imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        imagesetinterpolation($small, IMG_BELL);
        $b = imagescale($small, $w, $h, IMG_BELL);
        if (!$b) throw new RuntimeException('Not enough memory to sharpen this photo');
        $bx = (int)round(($w / imagesx($small) - 1) / 2);
        $by = (int)round(($h / imagesy($small) - 1) / 2);
        $small = null;
        return [$b, $bx, $by];
    }

    /** Scale to fit $max (longest side), with GD's area-averaging resampler. */
    public static function fit(\GdImage $im, int $max): \GdImage {
        $w = imagesx($im);
        $h = imagesy($im);
        $r = min(1.0, $max / max($w, $h));
        $nw = max(1, (int)round($w * $r));
        $nh = max(1, (int)round($h * $r));
        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $out;
    }

    /** The image as displayed for EXIF Orientation 1..8 (all eight, mirrored ones too). */
    public static function orient(\GdImage $im, int $o): \GdImage {
        if ($o === 2) { imageflip($im, IMG_FLIP_HORIZONTAL); return $im; }
        if ($o === 3) return imagerotate($im, 180, 0) ?: $im;
        if ($o === 4) { imageflip($im, IMG_FLIP_VERTICAL); return $im; }
        if ($o >= 5 && $o <= 8) {
            $r = imagerotate($im, $o === 8 ? 90 : -90, 0) ?: $im;     // 5,6,7: 90° clockwise; 8: counter-clockwise
            if ($o === 5) imageflip($r, IMG_FLIP_HORIZONTAL);          // transpose
            if ($o === 7) imageflip($r, IMG_FLIP_VERTICAL);            // transverse
            return $r;
        }
        return $im;
    }

    /** Decode a photo for enhancing. Returns [GdImage in stored orientation (opaque truecolour),
     *  EXIF orientation 1..8, whether it carries an EXIF thumbnail]. */
    public static function load(string $path): array {
        $info = @getimagesize($path);
        if (!$info) throw new RuntimeException('This file is not a picture Photo Finder can read.');
        [$w, $h, $type] = $info;
        if ($w * $h > self::MAX_PIXELS) {
            throw new RuntimeException(sprintf('This picture is too large to enhance (%.0f megapixels; the limit is %d).', $w * $h / 1e6, self::MAX_PIXELS / 1e6));
        }
        $im = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
            default => false,
        };
        if (!$im) {
            throw new RuntimeException('Photo Finder cannot enhance ' . strtoupper(pathinfo($path, PATHINFO_EXTENSION)) . ' files. JPEG, PNG, WebP, GIF and BMP work.');
        }
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        if ($type !== IMAGETYPE_JPEG) {
            // Transparent areas become white: the result is a JPEG.
            $bg = imagecreatetruecolor($w, $h);
            imagefilledrectangle($bg, 0, 0, $w - 1, $h - 1, 0xFFFFFF);
            imagealphablending($bg, true);
            imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
            $im = null;
            $im = $bg;
        }
        $orient = 1;
        $thumb = false;
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $e = @exif_read_data($path, null, true);
            if (is_array($e)) {
                $o = (int)($e['IFD0']['Orientation'] ?? 1);
                $orient = $o >= 1 && $o <= 8 ? $o : 1;
                $thumb = isset($e['THUMBNAIL']) || isset($e['COMPUTED']['Thumbnail.FileType']);
            }
        }
        return [$im, $orient, $thumb];
    }

    private static function encode(\GdImage $im, int $quality): string {
        ob_start();
        imagejpeg($im, null, $quality);
        $bytes = (string)ob_get_clean();
        if ($bytes === '') throw new RuntimeException('JPEG encoding failed');
        return $bytes;
    }

    // ---- Auto ----

    /** Auto settings from a small copy of the photo (any orientation). */
    public static function auto(\GdImage $s): array {
        $w = imagesx($s);
        $h = imagesy($s);
        $px = [];
        $gw = [0.0, 0.0, 0.0];
        $gwn = 0;
        $sh = [0.0, 0.0, 0.0];
        $shn = 0;
        $lh = array_fill(0, 256, 0);       // luma of unclipped pixels
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($s, $x, $y) & 0xFFFFFF;
                $px[] = $c;
                $r = $c >> 16;
                $g = ($c >> 8) & 255;
                $b = $c & 255;
                if (max($r, $g, $b) >= 250) continue;        // clipped: says nothing about the light
                $l = ($r * 77 + $g * 150 + $b * 29) >> 8;
                $lh[$l]++;
                if ($l >= 20 && $l <= 235) { $gw[0] += $r; $gw[1] += $g; $gw[2] += $b; $gwn++; }
                if ($l >= 10 && $l <= 60) { $sh[0] += $r; $sh[1] += $g; $sh[2] += $b; $shn++; }
            }
        }
        $n = count($px);

        // White balance: the colour of the light, estimated three ways: the shadows (luma 10-60),
        // grey world (the midtones) and white patch (the brightest unclipped 2%). A colour cast
        // from the light tints all three; a colourful scene does not: a sunset's silhouettes are
        // not orange, a blue sky has no shadows, a red wall is not the brightest thing. So only a
        // correction all three agree on is used, the smallest of the three, damped and clamped.
        // No shadows to check against (high-key, sky, snow): no correction.
        $toTN = static function (array $e): array {
            $e = array_map(static fn($v) => log(max(1.0, $v)), $e);
            return [($e[2] - $e[0]) / 2, $e[1] - ($e[0] + $e[2]) / 2];
        };
        $T = 0.0;
        $N = 0.0;
        $unclipped = array_sum($lh);
        if ($gwn > $n * 0.05 && $shn > $n * 0.02 && $unclipped > 0) {
            [$Ts, $Ns] = $toTN([$sh[0] / $shn, $sh[1] / $shn, $sh[2] / $shn]);
            [$Tg, $Ng] = $toTN([$gw[0] / $gwn, $gw[1] / $gwn, $gw[2] / $gwn]);
            $want = max(1, (int)ceil($unclipped * 0.02));
            $acc = 0;
            $lt = 255;
            for (; $lt > 0; $lt--) { $acc += $lh[$lt]; if ($acc >= $want) break; }
            $wp = [0.0, 0.0, 0.0];
            $wpn = 0;
            foreach ($px as $c) {
                $r = $c >> 16; $g = ($c >> 8) & 255; $b = $c & 255;
                if (max($r, $g, $b) >= 250 || (($r * 77 + $g * 150 + $b * 29) >> 8) < $lt) continue;
                $wp[0] += $r; $wp[1] += $g; $wp[2] += $b; $wpn++;
            }
            [$Tw, $Nw] = $wpn ? $toTN([$wp[0] / $wpn, $wp[1] / $wpn, $wp[2] / $wpn]) : [0.0, 0.0];
            $agree = static function (float ...$v): float {
                foreach ($v as $x) if ($x * $v[0] <= 0) return 0.0;
                usort($v, static fn($a, $b) => abs($a) <=> abs($b));
                return $v[0];
            };
            $T = $agree($Tg, $Tw, $Ts) * 0.8;
            $N = $agree($Ng, $Nw, $Ns) * 0.8;
        }
        $T = abs($T) < 0.02 ? 0.0 : max(-0.25, min(0.25, $T));
        $N = abs($N) < 0.015 ? 0.0 : max(-0.12, min(0.12, $N));
        $p = self::neutral();
        $p['warmth'] = (int)round($T / 0.4 * 100);
        $p['tint'] = (int)round($N / 0.3 * 100);

        // Levels: per-channel 0.5% / 99.5% points of the white-balanced photo. One black and
        // one white point for all three channels (darkest and brightest of the three), so the
        // stretch adds contrast without shifting colours; colour is the white balance's job.
        $q = self::knobs($p);
        $g = self::gains($q['T'], $q['N']);
        $hist = [array_fill(0, 1024, 0), array_fill(0, 1024, 0), array_fill(0, 1024, 0)];
        foreach ($px as $c) {
            $hist[0][min(1023, (int)($g[0] * ($c >> 16) / 255 * 1023 + 0.5))]++;
            $hist[1][min(1023, (int)($g[1] * (($c >> 8) & 255) / 255 * 1023 + 0.5))]++;
            $hist[2][min(1023, (int)($g[2] * ($c & 255) / 255 * 1023 + 0.5))]++;
        }
        $pct = static function (array $hs, float $f): float {
            $need = array_sum($hs) * $f;
            $acc = 0;
            foreach ($hs as $i => $cnt) { $acc += $cnt; if ($acc >= $need) return $i / 1023; }
            return 1.0;
        };
        $lo = min($pct($hist[0], 0.005), $pct($hist[1], 0.005), $pct($hist[2], 0.005));
        $hi = max($pct($hist[0], 0.995), $pct($hist[1], 0.995), $pct($hist[2], 0.995));
        $lo = min($lo, 0.10);                    // never crush more than this
        $hi = max($hi, 0.6);
        if ($hi - $lo < 0.5) $hi = min(1.0, $lo + 0.5);   // stretch at most 2x
        if ($lo > 0.004 || $hi < 0.996) $p['levels'] = ['lo' => round($lo, 4), 'hi' => round($hi, 4)];

        // Brightness: move the median towards a normal exposure, part of the way.
        $L = self::curves($p);
        $lum = [];
        $chroma = 0.0;
        foreach ($px as $c) {
            $r = $L[0][$c >> 16]; $gg = $L[1][($c >> 8) & 255]; $b = $L[2][$c & 255];
            $lum[] = (0.299 * $r + 0.587 * $gg + 0.114 * $b) / 255;
            $chroma += (max($r, $gg, $b) - min($r, $gg, $b)) / 255;
        }
        sort($lum);
        $med = $lum[intdiv($n, 2)];
        $p995 = $lum[min($n - 1, (int)($n * 0.995))];
        $dark = 0;
        foreach ($lum as $v) { if ($v >= 0.1) break; $dark++; }
        $dark /= $n;
        $k = 0.0;
        if ($med < 0.30) {
            $m = max($med, 0.02);
            $t = $m + (0.42 - $m) * 0.75;
            $k = ($t - $m) / ($m * (1 - $t));
            if ($p995 > 0.85 && $dark > 0.5) $k = min($k, 2.0);   // night or low-key: brighten gently
            $k = min($k, 4.0);
        } elseif ($med > 0.62) {
            $t = $med - ($med - 0.55) * 0.6;
            $k = max(-0.4, ($t - $med) / ($med * (1 - $t)));
        }
        $p['brightness'] = (int)round(max(-100, min(100, log(1 + $k, 2) / 2.5 * 100)));

        // Contrast: a gentle S-curve for flat photos (small spread of brightness).
        $kk = self::knobs($p)['k'];
        $sum = 0.0;
        $sq = 0.0;
        foreach ($lum as $v) { $v = (1 + $kk) * $v / (1 + $kk * $v); $sum += $v; $sq += $v * $v; }
        $sd = sqrt(max(0.0, $sq / $n - ($sum / $n) ** 2));
        $p['contrast'] = $dark > 0.5 ? 0 : ($sd < 0.16 ? 20 : ($sd < 0.20 ? 10 : 0));

        // Saturation: a small lift, less for photos that are already colourful, none for B&W.
        $chroma /= $n;
        $p['saturation'] = $chroma < 0.04 ? 0 : ($chroma > 0.30 ? 4 : 10);

        // Sharpening: mild; milder where brightening has lifted dark, noisy areas.
        $p['sharpen'] = $kk > 0.9 ? 15 : 30;
        return self::sanitize($p);
    }

    // ---- Colour profiles (for the preview only; the saved file copies the original's) ----

    /** The original JPEG's ICC_PROFILE APP2 segments, verbatim ('' if none). */
    private static function iccSegments(string $path): string {
        $fh = @fopen($path, 'rb');
        if (!$fh) return '';
        $out = '';
        try {
            if (fread($fh, 2) !== "\xFF\xD8") return '';
            while (!feof($fh)) {
                $hdr = fread($fh, 4);
                if (strlen($hdr) < 4 || $hdr[0] !== "\xFF") break;
                $marker = ord($hdr[1]);
                if ($marker === 0xDA || $marker === 0xD9) break;   // image data starts
                $len = unpack('n', substr($hdr, 2, 2))[1];
                if ($len < 2) break;
                if ($marker === 0xE2) {
                    $body = (string)fread($fh, $len - 2);
                    if (str_starts_with($body, "ICC_PROFILE\0")) $out .= $hdr . $body;
                } else {
                    fseek($fh, $len - 2, SEEK_CUR);
                }
            }
        } finally {
            fclose($fh);
        }
        return $out;
    }

    /** 'srgb' (or no profile), 'p3' (Display P3 / DCI-P3, wide-gamut phones) or 'other'. The
     *  profile's name is ASCII ('desc') or UTF-16BE ('mluc'), so both spellings are checked. */
    private static function iccKind(string $segments): string {
        if ($segments === '') return 'srgb';
        $has = static fn(string $s): bool => str_contains($segments, $s)
            || str_contains($segments, implode('', array_map(static fn($ch) => "\0" . $ch, str_split($s))));
        if ($has('P3')) return 'p3';
        if ($has('sRGB')) return 'srgb';
        return 'other';
    }

    /** Split a JPEG into [APPn/COM segments after SOI, everything from the first other marker]. */
    private static function jpegSplit(string $jpg): array {
        if (!str_starts_with($jpg, "\xFF\xD8")) throw new RuntimeException('Not a JPEG');
        $pos = 2;
        $len = strlen($jpg);
        while ($pos + 4 <= $len && $jpg[$pos] === "\xFF") {
            $marker = ord($jpg[$pos + 1]);
            if ($marker === 0xFF) { $pos++; continue; }                       // fill byte
            if (!(($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE)) break;
            $pos += 2 + unpack('n', substr($jpg, $pos + 2, 2))[1];
        }
        return [substr($jpg, 2, $pos - 2), substr($jpg, $pos)];
    }

    // ---- Opening the enhance view ----

    /** What the enhance view needs: the photo at preview size (displayed orientation, with the
     *  original's colour profile so it looks the same as in the lightbox) and the Auto settings. */
    public static function open(array $row): array {
        $t0 = microtime(true);
        $path = (string)$row['path'];
        [$im, $orient] = self::load($path);
        $w = imagesx($im);
        $h = imagesy($im);
        $base = self::fit($im, self::PREVIEW_MAX);
        $im = null;
        $sample = self::fit($base, self::STATS_MAX);
        $auto = self::auto($sample);
        $sample = null;
        $base = self::orient($base, $orient);
        $jpg = self::encode($base, 90);
        $icc = self::iccSegments($path);
        $kind = self::iccKind($icc);
        if ($kind !== 'srgb') {
            [$head, $rest] = self::jpegSplit($jpg);
            $jpg = "\xFF\xD8" . $head . $icc . $rest;
        }
        $rotated = $orient >= 5;
        return [
            'ok' => true,
            'id' => (string)$row['id'],
            'name' => basename($path),
            'width' => $rotated ? $h : $w,
            'height' => $rotated ? $w : $h,
            'preview' => [
                'width' => imagesx($base),
                'height' => imagesy($base),
                'color' => $kind,
                'src' => 'data:image/jpeg;base64,' . base64_encode($jpg),
            ],
            'auto' => $auto,
            'neutral' => self::neutral(),
            'can_replace' => self::canReplace($path),
            'copy_name' => basename(self::copyPath($path)),
            'seconds' => round(microtime(true) - $t0, 2),
        ];
    }

    /** Replacing writes JPEG data under the original's name, so only JPEG originals. */
    public static function canReplace(string $path): bool {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true);
    }

    /** "<name> (enhanced).jpg" next to the original, or (enhanced 2), (enhanced 3)... Never an
     *  existing file. Enhancing a copy again counts on from it rather than nesting the suffix. */
    public static function copyPath(string $path): string {
        $dir = pf_norm_path(dirname($path));
        $stem = (string)preg_replace('/ \(enhanced(?: \d+)?\)$/i', '', pathinfo($path, PATHINFO_FILENAME));
        for ($n = 1; $n < 1000; $n++) {
            $cand = $dir . '/' . $stem . ($n === 1 ? ' (enhanced)' : " (enhanced {$n})") . '.jpg';
            if (!file_exists($cand)) return $cand;
        }
        throw new RuntimeException('Too many enhanced copies of this photo already');
    }

    // ---- Saving ----

    private static function exiftool(): ?string {
        $t = Exif::toolPath() ?? Exporter::autoDetect();
        return ($t !== null && is_file($t)) ? $t : null;
    }

    /** Write the enhanced photo, as a copy next to the original or in its place (the original
     *  goes to the Recycle Bin first). Metadata is copied from the original with ExifTool, and
     *  the index and thumbnail are updated. The caller has checked the path against the roots. */
    public static function save(array $row, array $params, string $mode): array {
        $t0 = microtime(true);
        $times = [];
        $lap = static function (string $name) use (&$times, &$t0): void {
            $now = microtime(true);
            $times[$name] = round($now - $t0, 2);
            $t0 = $now;
        };
        $start = $t0;
        $path = (string)$row['path'];
        if (!is_file($path)) throw new RuntimeException('The photo is no longer on disk.');
        $p = self::sanitize($params);
        if (self::isNeutral($p)) throw new RuntimeException('Nothing to save: no changes are set.');
        if ($mode === 'replace' && !self::canReplace($path)) {
            throw new RuntimeException('Replacing works for JPEG photos only. Save as a copy instead.');
        }
        $tool = self::exiftool();
        if ($tool === null) {
            throw new RuntimeException('ExifTool is needed to keep the photo\'s date, place and description. Set its path in Settings.');
        }
        $tmp = PF_DATA . '/enhance-tmp';
        if (!is_dir($tmp) && !@mkdir($tmp, 0777, true) && !is_dir($tmp)) throw new RuntimeException('Cannot create ' . $tmp);
        $tag = bin2hex(random_bytes(6));
        $carrier = "{$tmp}/{$tag}-meta.jpg";
        $argFile = "{$tmp}/{$tag}-args.txt";
        $exifThumb = "{$tmp}/{$tag}-exifthumb.jpg";
        $out = "{$tmp}/{$tag}.jpg";

        [$im, $orient, $hasThumb] = self::load($path);
        $lap('load');

        // ExifTool copies the original's metadata (-TagsFromFile -all:all, plus the "unsafe" EXIF
        // tags, the colour profile and the IPTC character set, without which non-ASCII IPTC text
        // is cut short) onto a tiny stand-in JPEG while the pixels are processed; its metadata
        // segments then go in front of the enhanced image data, so the ~2 s ExifTool takes costs
        // nothing. The EXIF thumbnail is replaced by an enhanced one. The HDR gain-map and
        // motion-photo descriptions are left out: that extra data is not in the new file.
        // Nothing is written if the copy fails: a photo without its date and place is worse.
        if ($hasThumb) {
            $th = self::fit($im, 160);
            [$tb] = self::blurFor($th);
            self::apply($th, $p, $tb);
            imagejpeg($th, $exifThumb, 85);
        }
        imagejpeg(imagecreatetruecolor(8, 8), $carrier, 50);
        $args = ['-n', '-TagsFromFile', pf_norm_path($path), '-all:all', '-unsafe', '-ICC_Profile', '-IPTC:CodedCharacterSet',
                 '--XMP-hdrgm:all', '--XMP-GContainer:all', '--XMP-GCamera:all', '--ThumbnailImage', '--PreviewImage'];
        if ($hasThumb) $args[] = '-ThumbnailImage<=' . $exifThumb;
        array_push($args, '-m', '-overwrite_original', $carrier);
        file_put_contents($argFile, implode("\n", $args) . "\n");
        $proc = proc_open([$tool, '-charset', 'filename=UTF8', '-@', $argFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) throw new RuntimeException('Could not start ExifTool');
        fclose($pipes[0]);

        try {
            [$blur, $bx, $by] = $p['sharpen'] > 0 ? self::blurFor($im) : [null, 0, 0];
            $lap('blur');
            self::apply($im, $p, $blur, $bx, $by);
            $blur = null;
            $lap('pixels');
            $jpg = self::encode($im, self::QUALITY);
            $lap('encode');
            $stdout = (string)stream_get_contents($pipes[1]);
            $stderr = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $proc = null;
            $lap('metadata_wait');
            if (!preg_match('/\b1 image files? (updated|unchanged)/i', $stdout)) {
                throw new RuntimeException('Could not copy the photo\'s metadata: ' . trim($stderr !== '' ? $stderr : $stdout));
            }
            [$meta] = self::jpegSplit((string)file_get_contents($carrier));
            [, $data] = self::jpegSplit($jpg);
            if (file_put_contents($out, "\xFF\xD8" . $meta . $data) === false) throw new RuntimeException('Could not write ' . $out);
        } finally {
            if (isset($proc) && is_resource($proc)) {
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                proc_close($proc);
            }
            @unlink($carrier);
            @unlink($argFile);
            @unlink($exifThumb);
        }

        $kept = null;
        if ($mode === 'replace') {
            // The original is kept next to it as "<name> (original).jpg" (Clean up's Kept originals
            // list deletes them in bulk when the user chooses).
            $keptPath = pf_kept_original_path($path);
            if (!@rename($path, $keptPath)) {
                @unlink($out);
                throw new RuntimeException('Could not rename the original to keep it (is it open in another program?). Nothing was changed.');
            }
            if (!self::moveInto($out, $path)) {
                @rename($keptPath, $path);
                throw new RuntimeException('Could not put the enhanced photo in the original\'s place. Nothing was changed.');
            }
            $keptId = self::indexCopy($row, $keptPath);
            Thumb::ensureThumb($keptId, $keptPath);
            $kept = self::rowForPage($keptId);
            $id = (string)$row['id'];
            clearstatcache(true, $path);
            Db::upsertPending($id, $path, (int)filesize($path), (int)filemtime($path));
            $dest = $path;
        } else {
            $dest = self::copyPath($path);
            if (!self::moveInto($out, $dest)) {
                @unlink($out);
                throw new RuntimeException('Could not write ' . $dest);
            }
            $id = self::indexCopy($row, $dest);
        }
        $lap('write_index');
        self::writeGridThumb($id, $im, $orient);
        $im = null;
        $lap('thumbnail');
        $times['total'] = round(microtime(true) - $start, 2);
        return [
            'ok' => true,
            'mode' => $mode,
            'name' => basename($dest),
            'image' => self::rowForPage($id),
            'kept' => $kept,
            'seconds' => $times,
            'stats' => Db::stats(),
        ];
    }

    private static function moveInto(string $from, string $to): bool {
        if (@rename($from, $to)) return true;
        if (@copy($from, $to)) { @unlink($from); return true; }
        return false;
    }

    /** Index the copy the way a scan would, carrying over the original's description, tags,
     *  people, text, date and place so no AI call is needed. Returns the new id.
     *  (Also used by Deblur for its "(deblurred)" / "(restored)" copies.) */
    public static function indexCopy(array $orig, string $dest): string {
        $id = pf_path_id($dest);
        clearstatcache(true, $dest);
        Db::upsertPending($id, $dest, (int)filesize($dest), (int)filemtime($dest));
        $pdo = Db::get();
        $pdo->beginTransaction();
        try {
            $now = time();
            // Embedded metadata came along with the copy, so it is as current as the original's.
            $pdo->prepare(
                "UPDATE images SET (width, height, taken_at, description, tags, text_in_image, faces, indexed_at,
                        status, error, description_manual, lat, lon, gps_alt, described_by, redescribe,
                        exported_at, export_error) =
                   (SELECT width, height, taken_at, description, tags, text_in_image, faces, :now,
                        CASE WHEN status = 'ok' THEN 'ok' ELSE 'pending' END, NULL, description_manual,
                        lat, lon, gps_alt, described_by, 0,
                        CASE WHEN status = 'ok' AND exported_at IS NOT NULL AND exported_at >= indexed_at THEN :now2 ELSE NULL END,
                        NULL
                    FROM images WHERE id = :orig)
                 WHERE id = :id"
            )->execute([':now' => $now, ':now2' => $now, ':orig' => (string)$orig['id'], ':id' => $id]);
            $pdo->prepare('DELETE FROM images_fts WHERE id = :id')->execute([':id' => $id]);
            $pdo->prepare(
                "INSERT INTO images_fts (id, description, tags, text_in_image, faces, filename)
                 SELECT id, COALESCE(description,''), COALESCE(tags,''), COALESCE(text_in_image,''), COALESCE(faces,''), :fn
                 FROM images WHERE id = :id AND status = 'ok'"
            )->execute([':id' => $id, ':fn' => Db::filenameTokens($dest)]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** The grid thumbnail, as Thumb::ensureThumb makes it, from the enhanced pixels. */
    private static function writeGridThumb(string $id, \GdImage $im, int $orient): void {
        if (!is_dir(PF_THUMBS)) @mkdir(PF_THUMBS, 0777, true);
        $t = self::orient(self::fit($im, 280), $orient);
        imagejpeg($t, PF_THUMBS . '/' . $id . '.jpg', 80);
    }

    /** The row the page's grid and lightbox use, plus v (file time) to refresh cached images. */
    public static function rowForPage(string $id): array {
        $r = Db::getById($id) ?? [];
        $r['sort_date'] = ($r['taken_at'] ?? '') !== '' ? $r['taken_at'] : (($r['folder_year'] ?? '') !== '' ? $r['folder_year'] : null);
        $r['v'] = (int)($r['mtime'] ?? time());
        return $r;
    }
}
