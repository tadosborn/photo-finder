<?php
declare(strict_types=1);

// Draws the Photo Finder icon (a photo with a magnifier) and writes a multi-size .ico
// made of PNG images (Windows Vista and later read these).
//   php make-icon.php <out.ico>

if ($argc < 2) { fwrite(STDERR, "usage: php make-icon.php <out.ico>\n"); exit(2); }
$S = 256;

function pf_icon_canvas(int $s) {
    $im = imagecreatetruecolor($s, $s);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    return $im;
}

function pf_rounded_rect($im, int $x1, int $y1, int $x2, int $y2, int $r, int $col): void {
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $col);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $col);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $col);
    }
}

$im = pf_icon_canvas($S);
imageantialias($im, true);
$bgTop = [0x2f, 0x5f, 0xd0];
$bgBot = [0x5b, 0x3f, 0xd8];
// Background tile: vertical gradient clipped to a rounded square.
$mask = pf_icon_canvas($S);
pf_rounded_rect($mask, 8, 8, $S - 9, $S - 9, 52, imagecolorallocate($mask, 255, 255, 255));
for ($y = 0; $y < $S; $y++) {
    $t = $y / ($S - 1);
    $c = imagecolorallocate($im, (int)($bgTop[0] + ($bgBot[0] - $bgTop[0]) * $t),
        (int)($bgTop[1] + ($bgBot[1] - $bgTop[1]) * $t), (int)($bgTop[2] + ($bgBot[2] - $bgTop[2]) * $t));
    for ($x = 0; $x < $S; $x++) {
        if (((imagecolorat($mask, $x, $y) >> 24) & 0x7f) < 64) imagesetpixel($im, $x, $y, $c);
    }
}

// Photo card with a mountain and a sun.
$white = imagecolorallocate($im, 255, 255, 255);
$sky   = imagecolorallocate($im, 0xd9, 0xe6, 0xff);
$hill  = imagecolorallocate($im, 0x3b, 0x8f, 0x5a);
$hill2 = imagecolorallocate($im, 0x2c, 0x6e, 0x45);
$sun   = imagecolorallocate($im, 0xf5, 0xb7, 0x2b);
pf_rounded_rect($im, 40, 52, 196, 172, 14, $white);
imagefilledrectangle($im, 52, 64, 184, 160, $sky);
imagefilledellipse($im, 150, 92, 30, 30, $sun);
imagefilledpolygon($im, [52, 160, 98, 100, 140, 160], $hill2);
imagefilledpolygon($im, [90, 160, 138, 112, 184, 160], $hill);

// Magnifier.
$ink  = imagecolorallocate($im, 0x12, 0x1a, 0x2e);
imagesetthickness($im, 1);
$handle = [168, 178, 186, 160, 236, 210, 218, 228];
imagefilledpolygon($im, $handle, $ink);
imagefilledellipse($im, 160, 170, 92, 92, $white);
imagefilledellipse($im, 160, 170, 66, 66, imagecolorallocatealpha($im, 0xd9, 0xe6, 0xff, 20));
imagefilledellipse($im, 148, 158, 16, 16, imagecolorallocatealpha($im, 255, 255, 255, 30));

$images = [];
foreach ([256, 64, 48, 32, 24, 16] as $size) {
    $dst = pf_icon_canvas($size);
    imagealphablending($dst, false);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $size, $size, $S, $S);
    imagesavealpha($dst, true);
    ob_start();
    imagepng($dst, null, 9);
    $images[$size] = (string)ob_get_clean();
}

// ICONDIR + ICONDIRENTRY[] + PNG payloads.
$count = count($images);
$out = pack('vvv', 0, 1, $count);
$offset = 6 + 16 * $count;
$payload = '';
foreach ($images as $size => $png) {
    $out .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset);
    $offset += strlen($png);
    $payload .= $png;
}
file_put_contents($argv[1], $out . $payload);
echo "wrote {$argv[1]} (" . strlen($out . $payload) . " bytes, sizes " . implode('/', array_keys($images)) . ")\n";
