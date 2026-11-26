<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

final class Thumb {
    public static function prepareForApi(string $absPath, int $maxDim): array {
        $info = @getimagesize($absPath);
        if (!$info) throw new RuntimeException('Not a readable image');
        [$w, $h, $type] = $info;
        $im = self::loadGd($absPath, $type);
        if (!$im) throw new RuntimeException('Failed to decode image');

        $im = self::applyOrientation($im, $absPath);
        [$w, $h] = [imagesx($im), imagesy($im)];

        if (max($w, $h) > $maxDim) {
            $ratio = $maxDim / max($w, $h);
            $nw = max(1, (int)round($w * $ratio));
            $nh = max(1, (int)round($h * $ratio));
            $resized = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($resized, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($im);
            $im = $resized;
            $w = $nw;
            $h = $nh;
        }

        ob_start();
        imagejpeg($im, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($im);
        if ($bytes === false || $bytes === '') throw new RuntimeException('JPEG encode failed');
        return ['bytes' => $bytes, 'width' => $w, 'height' => $h];
    }

    public static function ensureThumb(string $id, string $absPath, int $size = 280): ?string {
        $thumbPath = PF_THUMBS . '/' . $id . '.jpg';
        if (is_file($thumbPath) && filemtime($thumbPath) >= filemtime($absPath)) {
            return $thumbPath;
        }
        if (!is_dir(PF_THUMBS)) mkdir(PF_THUMBS, 0777, true);
        try {
            $info = @getimagesize($absPath);
            if (!$info) return null;
            [$w, $h, $type] = $info;
            $im = self::loadGd($absPath, $type);
            if (!$im) return null;
            $im = self::applyOrientation($im, $absPath);
            [$w, $h] = [imagesx($im), imagesy($im)];

            $ratio = $size / max($w, $h);
            $nw = max(1, (int)round($w * $ratio));
            $nh = max(1, (int)round($h * $ratio));
            $thumb = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($thumb, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagejpeg($thumb, $thumbPath, 80);
            imagedestroy($thumb);
            imagedestroy($im);
            return $thumbPath;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function loadGd(string $path, int $type): \GdImage|false {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
            default => false,
        };
    }

    private static function applyOrientation(\GdImage $im, string $path): \GdImage {
        if (!function_exists('exif_read_data')) return $im;
        $exif = @exif_read_data($path);
        if (!$exif || empty($exif['Orientation'])) return $im;
        return match ((int)$exif['Orientation']) {
            3 => imagerotate($im, 180, 0) ?: $im,
            6 => imagerotate($im, -90, 0) ?: $im,
            8 => imagerotate($im, 90, 0) ?: $im,
            default => $im,
        };
    }
}
