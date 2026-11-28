<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/version.php';   // PF_VERSION (user agent); deblur-setup.php loads nothing else that defines it
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Exif.php';
require_once __DIR__ . '/Exporter.php';
require_once __DIR__ . '/Thumb.php';
require_once __DIR__ . '/Enhance.php';

/**
 * Fix blur and Enlarge: a sharper, or a larger, version of a photo, on this PC or with Google's Gemini.
 *
 * Kinds of result (KINDS), each a background job of its own:
 *   local     Fix blur on this PC: the NAFNet deblurring model (MIT licence, from OpenCV's model
 *             zoo) on ONNX Runtime, run by deblur.py under its own Python. Good on camera shake
 *             and motion blur; it cannot bring back a photo that is out of focus. About 2 minutes
 *             per 3 MP photo on a typical processor.
 *   cloud     Fix blur with Gemini image editing (Interactions API) and the user's own Google AI
 *             key. The model redraws the photo, so faces and text can come out different. It
 *             sends the photo to Google and costs a few cents (billed by Google).
 *   chain     Two steps: Gemini works on the 'local' result instead of the original.
 *   up_local  Enlarge on this PC, 4x (2x for photos over ENLARGE_X4_SIDE): Real-ESRGAN General
 *             x4v3 (BSD-3-Clause) on the same runtime, run by enlarge.py. Seconds per photo.
 *   up_cloud  Enlarge with Gemini, redrawn at 2K.
 *
 * The on-PC runtime (Python embeddable, onnxruntime, numpy, Pillow and the NAFNet model, about
 * 139 MB) is downloaded on first use into data/deblur; the enlarging model (UPSCALE, 4 MB) when
 * Enlarge is first used. Every file is checked against the SHA-256 pinned here.
 *
 * Each job is deblur-job.php in the background, started through ShellExecute (pf-helper
 * "launch", as pull-model.php is) so it inherits none of the web server's handles; it keeps
 * going when the window is closed and is independent of every other photo's jobs. The work on
 * this PC runs at idle priority, so the app and everything else stay responsive. A job writes
 * its state to data/deblur/jobs/<id>-<kind>.json and its result to <id>-<kind>.jpg, then saves
 * the result as "<name> (<SUFFIX>).jpg" beside the original (with the original's metadata,
 * indexed like an Enhance copy). The page polls status() and shows every version side by side;
 * "Replace the original" puts the chosen one in the original's place (the original, and that
 * version's now-duplicate copy, go to the Recycle Bin).
 *
 * On this PC the pixels stay in the file's stored orientation (no EXIF rotation is applied) and
 * the result carries the original's Orientation tag, so it shows the right way up and the tag,
 * copied along on save, stays right. Gemini is sent the photo upright and its result is saved
 * with Orientation 1.
 */
final class Deblur {
    public const PACK_VERSION = 1;
    /** The on-PC runtime, pinned. 'kind': python = embeddable zip, wheel = unpacked into site-packages. */
    public const PACK = [
        ['name' => 'Python 3.13.14', 'kind' => 'python', 'file' => 'python-3.13.14-embed-amd64.zip', 'size' => 10964839,
         'url' => 'https://www.python.org/ftp/python/3.13.14/python-3.13.14-embed-amd64.zip',
         'sha256' => '90b4e5b9898b72d744650524bff92377c367f44bd5fbd09e3148656c080ad907'],
        ['name' => 'ONNX Runtime 1.32.0', 'kind' => 'wheel', 'file' => 'onnxruntime-1.32.0-cp313-cp313-win_amd64.whl', 'size' => 16697937,
         'url' => 'https://files.pythonhosted.org/packages/6f/ee/a776ccc71ba54de3739c20c7c815d9695c55db1d08e2b3c621452676c23f/onnxruntime-1.32.0-cp313-cp313-win_amd64.whl',
         'sha256' => 'c7f9aa98036ba2a47aa041a4a8c6d0a1f7bfe1bde44c131dcd15ce334f2f148d'],
        ['name' => 'NumPy 2.5.5', 'kind' => 'wheel', 'file' => 'numpy-2.5.5-cp313-cp313-win_amd64.whl', 'size' => 12573354,
         'url' => 'https://files.pythonhosted.org/packages/45/74/bb9161943fb65caf24e4bb04c788f5c7815e6cb78ac595e7f46781033c82/numpy-2.5.5-cp313-cp313-win_amd64.whl',
         'sha256' => 'e0608d1585557b68dcb6a70a41654f7536567960afad79d91752d2863b242b68'],
        ['name' => 'Pillow 13.0.0', 'kind' => 'wheel', 'file' => 'pillow-13.0.0-cp313-cp313-win_amd64.whl', 'size' => 7284777,
         'url' => 'https://files.pythonhosted.org/packages/d7/ed/1b16fb66a37e1b9e41370b93b2c614c7da761a5b2a41dc333f7af4639962/pillow-13.0.0-cp313-cp313-win_amd64.whl',
         'sha256' => '9dda0788a9932776a75d8bcc020076edc308ce2508b51f6739a45e3158b3fc42'],
        ['name' => 'NAFNet deblurring model', 'kind' => 'model', 'file' => 'deblurring_nafnet_2025may.onnx', 'size' => 91736251,
         'url' => 'https://huggingface.co/opencv/deblurring_nafnet/resolve/main/deblurring_nafnet_2025may.onnx',
         'sha256' => '07263f416febecce10193dd648e950b22e397cf521eedab1a114ef77b2bc9587'],
    ];
    /** The enlarging model (Qualcomm AI Hub's ONNX export of Real-ESRGAN General x4v3): a zip
     *  holding the .onnx and its weights (.data), which must stay side by side. */
    public const UPSCALE = ['name' => 'Real-ESRGAN enlarging model', 'kind' => 'upscale', 'file' => 'real_esrgan_general_x4v3-onnx-float.zip', 'size' => 4541448,
        'url' => 'https://qaihub-public-assets.s3.us-west-2.amazonaws.com/qai-hub-models/models/real_esrgan_general_x4v3/releases/v0.67.0/real_esrgan_general_x4v3-onnx-float.zip',
        'sha256' => '4f3619c89eb0727ff9170b977578d160fa4d696680a0cac4c7f870b52973033b'];
    /** 'color' and 'fixed' come only from a batch (runBatch): a batch's colour correction and its
     *  "all fixes" version. A batch's blur removal and enlargement are 'local' and 'up_local'. */
    public const KINDS = ['local', 'cloud', 'chain', 'up_local', 'up_cloud', 'color', 'up_local_2x', 'fixed', 'fixed_2x'];
    /** The kinds a batch makes ('_2x': the 2x size of a 4x enlargement, for the AI to choose between). */
    public const BATCH_KINDS = ['local', 'color', 'up_local_2x', 'up_local', 'fixed_2x', 'fixed'];
    private const CLOUD_KINDS = ['cloud', 'chain', 'up_cloud'];
    public const SUFFIX = ['local' => 'deblurred', 'cloud' => 'restored', 'chain' => 'deblurred, restored',
                           'up_local' => 'enlarged', 'up_cloud' => 'AI enlarged', 'color' => 'enhanced', 'up_local_2x' => 'enlarged 2x',
                           'fixed' => 'fixed', 'fixed_2x' => 'fixed 2x'];
    /** Enlarge takes photos up to ENLARGE_MAX_SIDE px on the long side: 4x up to ENLARGE_X4_SIDE,
     *  2x above it, so a result is at most 4800 px (and the job's memory stays near 300 MB). */
    public const ENLARGE_MAX_SIDE = 2400;
    public const ENLARGE_X4_SIDE = 1200;
    /** Gemini draws "2K" pictures (2048 px for a square one; 2400 × 1792 for 4:3, as it returned
     *  for real photos): only photos under 2048 px on the long side gain from its enlargement. */
    public const CLOUD_ENLARGE_SIDE = 2048;

    /** Gemini image-editing models offered in Settings (prices from Google's pricing page, Nov 2026). */
    public const GEMINI_MODELS = [
        'gemini-nano-banana-2.1' => ['label' => 'Nano Banana 2.1, 2K', 'size' => '2K', 'cost' => 'about $0.05 per photo'],
        'gemini-3.1-flash-lite-image' => ['label' => 'Nano Banana 2 Lite, 1K (cheaper, smaller)', 'size' => '1K', 'cost' => 'about $0.03 per photo'],
    ];
    public const GEMINI_DEFAULT = 'gemini-nano-banana-2.1';
    public const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';
    private const GEMINI_RATIOS = ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];
    private const CLOUD_MAX_SIDE = 3072;
    private const KEEP = 'Keep everything else exactly the same: the same people, faces, expressions, poses, clothing, '
        . 'objects, text, colours, lighting, framing and composition. Do not add, remove or restyle anything.';
    private const PROMPTS = [
        'cloud' => 'Restore this photo: remove the blur from camera shake, motion or focus so it looks sharp and '
            . 'natural, as if the picture had been taken correctly. ' . self::KEEP,
        'chain' => 'This photo has already been partly deblurred. Finish restoring it: remove any blur that remains '
            . 'and any halos, ringing or smeared texture left by the earlier sharpening, so it looks sharp and '
            . 'natural, as if the picture had been taken correctly. ' . self::KEEP,
        'up_cloud' => 'This is a small, low-resolution photo. Redraw it at a higher resolution: sharp and natural, '
            . 'with fine detail in faces, hair, fabric and textures, and without blur, noise, pixelation or JPEG '
            . 'compression blocks. ' . self::KEEP,
    ];

    // ---- Paths ----

    public static function dir(): string { return PF_DATA . '/deblur'; }
    public static function packDir(): string { return self::dir() . '/runtime'; }
    private static function jobsDir(): string { return self::dir() . '/jobs'; }
    private static function setupFile(): string { return self::dir() . '/setup.json'; }
    public static function python(): string { return self::packDir() . '/python/python.exe'; }
    public static function model(): string { return self::packDir() . '/model/' . self::PACK[4]['file']; }
    public static function jobFile(string $id, string $kind, string $ext): string {
        return self::jobsDir() . '/' . $id . '-' . $kind . '.' . $ext;
    }
    public static function packBytes(): int { return array_sum(array_column(self::PACK, 'size')); }
    private static function upscaleDir(): string { return self::packDir() . '/upscale'; }
    public static function upscaleModel(): string {
        return self::upscaleDir() . '/real_esrgan_general_x4v3-onnx-float/real_esrgan_general_x4v3_onnx/real_esrgan_general_x4v3.onnx';
    }

    // ---- The on-PC runtime ----

    public static function isReady(): bool {
        $r = json_decode((string)@file_get_contents(self::packDir() . '/ready.json'), true);
        return is_array($r) && ($r['version'] ?? 0) === self::PACK_VERSION && is_file(self::python()) && is_file(self::model());
    }

    private static function upscaleInstalled(): bool {
        $r = json_decode((string)@file_get_contents(self::upscaleDir() . '/ready.json'), true);
        return is_array($r) && ($r['sha256'] ?? '') === self::UPSCALE['sha256'] && is_file(self::upscaleModel());
    }

    /** Enlarge on this PC: the runtime and the enlarging model. */
    public static function isUpscaleReady(): bool { return self::isReady() && self::upscaleInstalled(); }

    /** What a setup for 'deblur' (the runtime) or 'enlarge' (the runtime and the enlarging model)
     *  still has to download. */
    private static function setupItems(string $which): array {
        $items = self::isReady() ? [] : self::PACK;
        if ($which === 'enlarge' && !self::upscaleInstalled()) $items[] = self::UPSCALE;
        return $items;
    }

    public static function setupBytes(string $which): int {
        return array_sum(array_column(self::setupItems($which), 'size'));
    }

    public static function setupStatus(): ?array {
        $s = json_decode((string)@file_get_contents(self::setupFile()), true);
        if (!is_array($s)) return null;
        if (empty($s['done']) && empty($s['error']) && ($s['updated'] ?? 0) < time() - 120) {
            $s['error'] = 'The download stopped. Try again.';
        }
        return $s;
    }

    /** $which: 'deblur' (the runtime) or 'enlarge' (the runtime, if missing, and the enlarging model). */
    public static function startSetup(string $which = 'deblur'): void {
        if (!in_array($which, ['deblur', 'enlarge'], true)) throw new InvalidArgumentException('Unknown setup.');
        $bytes = self::setupBytes($which);
        if ($bytes === 0) return;
        $st = self::setupStatus();
        if ($st && empty($st['done']) && empty($st['error'])) throw new RuntimeException('The download is already running.');
        self::writeJson(self::setupFile(), ['status' => 'starting', 'which' => $which, 'done' => false, 'error' => '', 'got' => 0, 'total' => $bytes, 'updated' => time()]);
        self::launch(self::phpExe(), [self::win(PF_ROOT . '/deblur-setup.php'), self::win(PF_DATA), $which]);
    }

    /** Runs in deblur-setup.php: download, check and unpack what $which needs. A file already in
     *  PF_DEBLUR_CACHE (a folder) with the right SHA-256 is copied instead of downloaded. */
    public static function runSetup(string $which = 'deblur'): void {
        $items = self::setupItems($which);
        $total = array_sum(array_column($items, 'size'));
        $state = ['status' => 'downloading', 'which' => $which, 'done' => false, 'error' => '', 'got' => 0, 'total' => $total, 'file' => '', 'updated' => time()];
        $save = function () use (&$state): void { $state['updated'] = time(); self::writeJson(self::setupFile(), $state); };
        $dl = self::dir() . '/download';
        try {
            foreach ([$dl, self::packDir()] as $d) if (!is_dir($d) && !@mkdir($d, 0777, true)) throw new RuntimeException("Cannot create $d");
            $before = 0;
            foreach ($items as $f) {
                $state['file'] = $f['name'];
                $dest = $dl . '/' . $f['file'];
                if (!(is_file($dest) && hash_file('sha256', $dest) === $f['sha256'])) {
                    $cache = (string)getenv('PF_DEBLUR_CACHE');
                    $cached = $cache !== '' ? $cache . '/' . $f['file'] : '';
                    if ($cached !== '' && is_file($cached) && hash_file('sha256', $cached) === $f['sha256']) {
                        copy($cached, $dest);
                    } else {
                        self::download($f['url'], $dest, function (int $got) use (&$state, $before, $save): void {
                            $state['got'] = $before + $got;
                            if (time() !== $state['updated']) $save();
                        });
                    }
                    if (hash_file('sha256', $dest) !== $f['sha256']) {
                        @unlink($dest);
                        throw new RuntimeException($f['name'] . ' did not download correctly (checksum mismatch). Try again.');
                    }
                }
                $before += $f['size'];
                $state['got'] = $before;
                $save();
            }
            $state['status'] = 'installing';
            $save();
            $rt = self::packDir();
            $kinds = array_column($items, 'kind');
            if (in_array('python', $kinds, true)) {
                if (is_dir($rt . '/python')) self::rmTree($rt . '/python');
                @mkdir($rt . '/model', 0777, true);
                foreach (self::PACK as $f) {
                    $src = $dl . '/' . $f['file'];
                    if ($f['kind'] === 'python') self::unzip($src, $rt . '/python');
                    elseif ($f['kind'] === 'wheel') self::unzip($src, $rt . '/python/Lib/site-packages');
                    elseif (!copy($src, self::model())) throw new RuntimeException('Could not install the model.');
                }
                // The embeddable Python only searches the folders its ._pth file lists.
                $pth = glob($rt . '/python/python3*._pth')[0] ?? null;
                if (!$pth) throw new RuntimeException('Python did not unpack correctly.');
                if (!str_contains((string)file_get_contents($pth), 'Lib\\site-packages')) file_put_contents($pth, "Lib\\site-packages\n", FILE_APPEND);
                self::writeJson($rt . '/ready.json', ['version' => self::PACK_VERSION, 'installed' => time()]);
            }
            if (in_array('upscale', $kinds, true)) {
                if (is_dir(self::upscaleDir())) self::rmTree(self::upscaleDir());
                self::unzip($dl . '/' . self::UPSCALE['file'], self::upscaleDir());
                if (!is_file(self::upscaleModel())) throw new RuntimeException('The enlarging model did not unpack correctly.');
                self::writeJson(self::upscaleDir() . '/ready.json', ['sha256' => self::UPSCALE['sha256'], 'installed' => time()]);
            }
            foreach ($items as $f) @unlink($dl . '/' . $f['file']);   // unpacked; the downloads are no longer needed
            $state['status'] = 'done';
            $state['done'] = true;
            $save();
        } catch (Throwable $e) {
            $state['error'] = $e->getMessage();
            $save();
        }
    }

    private static function download(string $url, string $dest, callable $progress): void {
        $fh = fopen($dest . '.part', 'wb');
        if (!$fh) throw new RuntimeException('Cannot write ' . $dest);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 120,
            CURLOPT_USERAGENT => 'PhotoFinder/' . PF_VERSION, CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function ($c, $dlTotal, $dlNow) use ($progress) { $progress((int)$dlNow); return 0; },
        ]);
        pf_curl_tls($ch);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if (!$ok) { @unlink($dest . '.part'); throw new RuntimeException('Download failed (' . $err . '). Check the internet connection and try again.'); }
        @unlink($dest);
        rename($dest . '.part', $dest);
    }

    private static function unzip(string $zip, string $to): void {
        $z = new ZipArchive();
        if ($z->open($zip) !== true) throw new RuntimeException('Cannot open ' . basename($zip));
        if (!is_dir($to)) @mkdir($to, 0777, true);
        if (!$z->extractTo($to)) { $z->close(); throw new RuntimeException('Cannot unpack ' . basename($zip)); }
        $z->close();
    }

    private static function rmTree(string $dir): void {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }

    // ---- Jobs ----

    public static function hasCloudKey(): bool {
        return trim((string)(pf_load_settings()['gemini_api_key'] ?? '')) !== '';
    }

    public static function cloudModel(): string {
        $m = (string)(pf_load_settings()['gemini_model'] ?? '');
        return isset(self::GEMINI_MODELS[$m]) ? $m : self::GEMINI_DEFAULT;
    }

    /** Enlarging uses a 2K model: the chosen one if it draws at 2K, else the default. */
    public static function upCloudModel(): string {
        $m = self::cloudModel();
        return self::GEMINI_MODELS[$m]['size'] === '2K' ? $m : self::GEMINI_DEFAULT;
    }

    /** Whether, and how much, photo $row can be enlarged; width/height are its own size as shown,
     *  read from the file (the index may hold an older, smaller figure; see Cleanup). */
    public static function enlargeInfo(array $row): array {
        [$w, $h] = pf_image_size((string)$row['path']) ?? [(int)($row['width'] ?? 0), (int)($row['height'] ?? 0)];
        $long = max($w, $h);
        $scale = $long <= self::ENLARGE_X4_SIDE ? 4 : 2;
        return ['ok' => $long > 0 && $long <= self::ENLARGE_MAX_SIDE, 'cloud_ok' => $long > 0 && $long < self::CLOUD_ENLARGE_SIDE,
                'scale' => $scale, 'width' => $w, 'height' => $h, 'max_side' => self::ENLARGE_MAX_SIDE];
    }

    /** Start a job of $kind (see KINDS) for photo $row. */
    public static function start(array $row, string $kind): void {
        if (!in_array($kind, ['local', 'cloud', 'chain', 'up_local', 'up_cloud'], true)) throw new InvalidArgumentException('Unknown kind.');   // the rest come from batches
        $id = (string)$row['id'];
        $st = self::jobStatus($id, $kind);
        if (($st['state'] ?? '') === 'running') throw new RuntimeException('Already working on this photo.');
        // Check everything before recording a job, so a refusal leaves no "running" state behind.
        $cloud = in_array($kind, self::CLOUD_KINDS, true);
        if ($kind === 'local' && !self::isReady()) throw new RuntimeException('Fix blur is not set up on this PC yet.');
        if ($kind === 'up_local' && !self::isUpscaleReady()) throw new RuntimeException('Enlarging is not set up on this PC yet.');
        if ($cloud && !self::hasCloudKey()) throw new RuntimeException('Add a Google Gemini API key in Settings > Fix blur first.');
        if ($kind === 'chain' && (self::jobStatus($id, 'local')['state'] ?? '') !== 'done') {
            throw new RuntimeException('Fix it on this PC first: the cloud AI then works on that result.');
        }
        if ($kind === 'up_local' || $kind === 'up_cloud') {
            $e = self::enlargeInfo($row);
            $limit = $kind === 'up_local' ? self::ENLARGE_MAX_SIDE : self::CLOUD_ENLARGE_SIDE - 1;
            if (!($kind === 'up_local' ? $e['ok'] : $e['cloud_ok'])) {
                throw new RuntimeException("This photo is already {$e['width']}×{$e['height']}: " . ($kind === 'up_local' ? 'enlarging on this PC' : 'the cloud AI')
                    . ' is for photos up to ' . number_format($limit) . ' px on the long side.');
            }
        }
        if (!is_dir(self::jobsDir())) @mkdir(self::jobsDir(), 0777, true);
        $jf = self::jobFile($id, $kind, 'json');
        @unlink(self::jobFile($id, $kind, 'jpg'));
        self::writeJson($jf, ['state' => 'running', 'done' => 0, 'total' => 0, 'started' => time(), 'updated' => time()]);
        // The job saves its result as a copy itself (runJob). Marked in a file of its own: deblur.py,
        // enlarge.py and runCloud each write the whole state file, so a flag in it would be lost.
        touch(self::jobFile($id, $kind, 'auto'));
        try {
            self::launch(self::phpExe(), [self::win(PF_ROOT . '/deblur-job.php'), $id, self::win(PF_DATA), $kind]);
        } catch (Throwable $e) {
            @unlink(self::jobFile($id, $kind, 'auto'));
            self::writeJson($jf, ['state' => 'error', 'error' => $e->getMessage(), 'updated' => time()]);
            throw $e;
        }
    }

    /** Runs in deblur-job.php, in the background: make the result, then save it as a copy next
     *  to the original, whether or not anyone is looking (every version is kept; the window
     *  then only decides which, if any, replaces the original). */
    public static function runJob(string $id, string $kind): void {
        if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException('Unknown kind.');
        try {
            if (in_array($kind, self::CLOUD_KINDS, true)) self::runCloud($id, $kind);
            else self::runLocal($id, $kind);
            if ((self::jobStatus($id, $kind)['state'] ?? '') !== 'done') return;
            try {
                $row = Db::getById($id);
                if (!$row) throw new RuntimeException('The photo is no longer in the index.');
                self::save($row, $kind, 'copy');
            } catch (Throwable $e) {
                $j = self::jobStatus($id, $kind);
                unset($j['autosave']);
                $j['save_error'] = $e->getMessage();
                self::writeJson(self::jobFile($id, $kind, 'json'), $j);
            }
        } finally {
            @unlink(self::jobFile($id, $kind, 'auto'));   // saved, failed to save, or nothing to save
        }
    }

    /** deblur.py or enlarge.py under the runtime's Python (which drops itself to idle priority). */
    private static function runLocal(string $id, string $kind): void {
        $jf = self::jobFile($id, $kind, 'json');
        $row = Db::getById($id);
        if (!$row || !is_file((string)$row['path'])) {
            self::writeJson($jf, ['state' => 'error', 'error' => 'The photo is no longer on disk.', 'updated' => time()]);
            return;
        }
        $cmd = $kind === 'local'
            ? [self::python(), '-I', PF_ROOT . '/deblur.py', self::model(), (string)$row['path'], self::jobFile($id, $kind, 'jpg'), $jf]
            : [self::python(), '-I', PF_ROOT . '/enlarge.py', self::upscaleModel(), (string)$row['path'], self::jobFile($id, $kind, 'jpg'), $jf,
               (string)self::enlargeInfo($row)['scale']];
        [, , $err] = pf_run_cmd(array_map([self::class, 'win'], $cmd));
        if ((self::jobStatus($id, $kind)['state'] ?? '') === 'running') {   // it ended without saying how
            $last = trim((string)strrchr("\n" . trim($err), "\n"));
            self::writeJson($jf, ['state' => 'error', 'error' => 'It stopped without finishing' . ($last !== '' ? ': ' . mb_substr($last, 0, 200) : '.'), 'updated' => time()]);
        }
    }

    /** The picture a finished job shows and saves: its own file, or (a batch keeps no second
     *  copy) the copy it saved next to the original. '' if neither is there. */
    public static function resultFile(string $id, string $kind, ?array $j = null): string {
        $f = self::jobFile($id, $kind, 'jpg');
        if (is_file($f)) return $f;
        $j ??= json_decode((string)@file_get_contents(self::jobFile($id, $kind, 'json')), true) ?: [];
        $sp = (string)($j['saved_path'] ?? '');
        return $sp !== '' && is_file($sp) ? $sp : '';
    }

    public static function jobStatus(string $id, string $kind): array {
        $j = json_decode((string)@file_get_contents(self::jobFile($id, $kind, 'json')), true);
        if (!is_array($j)) return ['state' => 'none'];
        // On this PC the job writes progress after every tile; a cloud job writes only when Google
        // answers, which may take up to its 240 s time-out.
        $quiet = in_array($kind, self::CLOUD_KINDS, true) ? 300 : 180;
        if (($j['state'] ?? '') === 'running' && ($j['updated'] ?? 0) < time() - $quiet) {
            $j['state'] = 'error';
            $j['error'] = 'It stopped without finishing. Try again.';
        }
        if (($j['state'] ?? '') === 'done' && self::resultFile($id, $kind, $j) === '') $j = ['state' => 'none'];
        // 'autosave': the job will save its result itself (the page waits for 'saved'). A job that
        // died between its result and its copy left the marker behind: say so after two minutes.
        unset($j['autosave']);
        if (is_file(self::jobFile($id, $kind, 'auto')) && ($j['state'] ?? '') !== 'none') {
            if (($j['state'] ?? '') === 'done' && empty($j['saved']) && empty($j['save_error']) && ($j['updated'] ?? 0) < time() - 120) {
                $j['save_error'] = 'The copy was not saved (the job stopped).';
            } else {
                $j['autosave'] = true;
            }
        }
        return $j;
    }

    /** Everything the Fix blur / Enlarge window shows for one photo. */
    public static function status(string $id): array {
        $m = self::cloudModel();
        $um = self::upCloudModel();
        $row = Db::getById($id);
        $out = [
            'ready' => self::isReady(),
            'up_ready' => self::isUpscaleReady(),
            'setup' => self::setupStatus(),
            'download_mb' => (int)round(self::packBytes() / 1048576),
            'up_download_mb' => max(1, (int)round(self::setupBytes('enlarge') / 1048576)),
            'cloud_key' => self::hasCloudKey(),
            'cloud_model' => self::GEMINI_MODELS[$m]['label'],
            'cloud_cost' => self::GEMINI_MODELS[$m]['cost'],
            'up_cloud_model' => self::GEMINI_MODELS[$um]['label'],
            'up_cloud_cost' => self::GEMINI_MODELS[$um]['cost'],
            'enlarge' => $row ? self::enlargeInfo($row) : null,
        ];
        foreach (self::KINDS as $k) $out[$k] = self::jobStatus($id, $k);
        $pick = json_decode((string)@file_get_contents(self::jobFile($id, 'ai', 'json')), true);
        $out['ai_pick'] = is_array($pick) && isset($pick['kind']) ? $pick : null;
        return $out;
    }

    public static function discard(string $id): void {
        foreach (self::KINDS as $k) {
            if ((self::jobStatus($id, $k)['state'] ?? '') === 'running') continue;
            @unlink(self::jobFile($id, $k, 'jpg'));
            @unlink(self::jobFile($id, $k, 'json'));
            @unlink(self::jobFile($id, $k, 'auto'));
        }
        @unlink(self::jobFile($id, 'ai', 'json'));
    }

    // ---- Cloud: Gemini image editing ----

    /** A 'cloud', 'chain' or 'up_cloud' job (runJob, in deblur-job.php). */
    public static function runCloud(string $id, string $kind = 'cloud'): void {
        if (!in_array($kind, self::CLOUD_KINDS, true)) throw new InvalidArgumentException('Unknown kind.');
        $jf = self::jobFile($id, $kind, 'json');
        $state = ['state' => 'running', 'started' => time(), 'updated' => time()];
        try {
            $row = Db::getById($id);
            if (!$row || !is_file((string)$row['path'])) throw new RuntimeException('The photo is no longer on disk.');
            $src = (string)$row['path'];
            if ($kind === 'chain') {   // the second step works on the first step's result
                $src = self::resultFile($id, 'local');   // its own file, or (from a batch) its saved copy
                if ((self::jobStatus($id, 'local')['state'] ?? '') !== 'done' || $src === '') {
                    throw new RuntimeException('The result from this PC is gone. Fix it on this PC again first.');
                }
            }
            $s = pf_load_settings();
            $key = trim((string)($s['gemini_api_key'] ?? ''));
            if ($key === '') throw new RuntimeException('No Google Gemini API key is set.');
            $model = $kind === 'up_cloud' ? self::upCloudModel() : self::cloudModel();
            [$jpeg, $w, $h] = self::cloudInput($src);
            $body = [
                'model' => $model,
                'input' => [
                    ['type' => 'text', 'text' => self::PROMPTS[$kind]],
                    ['type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode($jpeg)],
                ],
                'response_format' => ['type' => 'image', 'mime_type' => 'image/jpeg', 'aspect_ratio' => self::nearestRatio($w, $h),
                                      'image_size' => self::GEMINI_MODELS[$model]['size']],
            ];
            $url = self::geminiUrl($s);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
                CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 240,
            ]);
            pf_curl_tls($ch);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) throw new RuntimeException('Could not reach Google (' . $err . ').');
            $j = json_decode((string)$resp, true);
            if ($code !== 200) {
                $msg = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
                throw new RuntimeException('Google answered HTTP ' . $code . ($msg !== '' ? ': ' . $msg : '.'));
            }
            $img = is_array($j) ? self::findImage($j) : null;
            if ($img === null) {
                $text = is_array($j) ? self::findText($j) : '';
                throw new RuntimeException('Google returned no picture' . ($text !== '' ? ': ' . mb_substr($text, 0, 200) : '.'));
            }
            $bin = base64_decode($img, true);
            if ($bin === false || !@getimagesizefromstring($bin)) throw new RuntimeException('Google returned something that is not a picture.');
            $out = self::jobFile($id, $kind, 'jpg');
            $info = getimagesizefromstring($bin);
            if (($info[2] ?? 0) !== IMAGETYPE_JPEG) {          // asked for JPEG; convert if it sent PNG
                $gd = imagecreatefromstring($bin);
                if (!$gd) throw new RuntimeException('Could not read the picture Google returned.');
                imagejpeg($gd, $out, 95);
            } else {
                file_put_contents($out, $bin);
            }
            $state = ['state' => 'done', 'started' => $state['started'], 'seconds' => time() - $state['started'], 'model' => $model,
                      'width' => (int)$info[0], 'height' => (int)$info[1], 'updated' => time()];
        } catch (Throwable $e) {
            $state = ['state' => 'error', 'error' => $e->getMessage(), 'updated' => time()];
        }
        self::writeJson($jf, $state);
    }

    /** settings 'gemini_url' overrides the endpoint for tests (https, or an address on this PC). */
    private static function geminiUrl(array $s): string {
        $u = trim((string)($s['gemini_url'] ?? ''));
        if ($u === '') return self::GEMINI_URL;
        $p = parse_url($u);
        $host = strtolower((string)($p['host'] ?? ''));
        if (($p['scheme'] ?? '') === 'https' || in_array($host, ['127.0.0.1', 'localhost'], true)) return $u;
        throw new RuntimeException('gemini_url must be https.');
    }

    /** The photo as a JPEG of at most CLOUD_MAX_SIDE px, turned the way it is shown (its EXIF
     *  Orientation applied), so the AI sees it upright and draws it upright; save() then marks
     *  the result Orientation 1. */
    private static function cloudInput(string $path): array {
        $info = @getimagesize($path);
        if (!$info) throw new RuntimeException('This photo cannot be read.');
        [$w, $h] = $info;
        $o = self::orientation($path);
        if ($o === 1 && $info[2] === IMAGETYPE_JPEG && max($w, $h) <= self::CLOUD_MAX_SIDE && filesize($path) < 12_000_000) {
            return [(string)file_get_contents($path), $w, $h];
        }
        $src = @imagecreatefromstring((string)file_get_contents($path));
        if (!$src) throw new RuntimeException('This photo cannot be read.');
        $im = Enhance::orient(Enhance::fit($src, self::CLOUD_MAX_SIDE), $o);
        ob_start();
        imagejpeg($im, null, 92);
        return [(string)ob_get_clean(), imagesx($im), imagesy($im)];
    }

    /** EXIF Orientation 1..8 of a JPEG (1 for anything else). */
    private static function orientation(string $path): int {
        $o = function_exists('exif_read_data') ? (int)(@exif_read_data($path)['Orientation'] ?? 1) : 1;
        return $o >= 1 && $o <= 8 ? $o : 1;
    }

    private static function nearestRatio(int $w, int $h): string {
        $r = $w / max(1, $h);
        $best = '1:1';
        $bestD = INF;
        foreach (self::GEMINI_RATIOS as $s) {
            [$a, $b] = array_map('intval', explode(':', $s));
            $d = abs(log($r / ($a / $b)));
            if ($d < $bestD) { $bestD = $d; $best = $s; }
        }
        return $best;
    }

    /** The first image block's base64 data anywhere in the response (steps[].content[] with
     *  type "image" in the documented shape; any nested {"type":"image","data":...} otherwise). */
    private static function findImage(array $a): ?string {
        if (($a['type'] ?? '') === 'image' && is_string($a['data'] ?? null) && $a['data'] !== '') return $a['data'];
        foreach ($a as $v) {
            if (is_array($v) && ($found = self::findImage($v)) !== null) return $found;
        }
        return null;
    }

    private static function findText(array $a): string {
        if (($a['type'] ?? '') === 'text' && is_string($a['text'] ?? null)) return trim($a['text']);
        foreach ($a as $v) {
            if (is_array($v) && ($t = self::findText($v)) !== '') return $t;
        }
        return '';
    }

    // ---- Saving ----

    /** "<name> (<SUFFIX>).jpg" beside the original, never an existing file. The same suffix is not
     *  repeated ("X (enlarged).jpg" enlarged again is "X (enlarged 2).jpg"); a different one is
     *  added ("X (deblurred).jpg" enlarged is "X (deblurred) (enlarged).jpg"). */
    public static function copyPath(string $path, string $kind, ?string $suffix = null): string {
        $suffix ??= self::SUFFIX[$kind];
        $dir = pf_norm_path(dirname($path));
        $stem = (string)preg_replace('/ \(' . preg_quote($suffix, '/') . '(?: \d+)?\)$/i', '', pathinfo($path, PATHINFO_FILENAME));
        for ($n = 1; $n < 1000; $n++) {
            $cand = $dir . '/' . $stem . ($n === 1 ? " ({$suffix})" : " ({$suffix} {$n})") . '.jpg';
            if (!file_exists($cand)) return $cand;
        }
        throw new RuntimeException('Too many copies of this photo already');
    }

    /** Write the job's result with the original's metadata: as a copy next to the original
     *  ('copy'), or in its place ('replace': the original goes to the Recycle Bin first, the
     *  photo keeps its name, description and place in the index). */
    public static function save(array $row, string $kind, string $mode = 'copy'): array {
        if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException('Unknown kind.');
        if ($mode === 'replace' && !Enhance::canReplace((string)$row['path'])) {
            throw new RuntimeException('Replacing works for JPEG photos only. Save as a copy instead.');
        }
        $id = (string)$row['id'];
        $job = self::jobStatus($id, $kind);
        $res = self::resultFile($id, $kind, $job);
        if (($job['state'] ?? '') !== 'done' || $res === '') throw new RuntimeException('There is no result to save yet.');
        if ($mode === 'copy' && !empty($job['saved'])) throw new RuntimeException('This result is already saved as "' . $job['saved'] . '".');
        $path = (string)$row['path'];
        if (!is_file($path)) throw new RuntimeException('The photo is no longer on disk.');
        $tool = Exif::toolPath() ?? Exporter::autoDetect();
        if ($tool === null || !is_file($tool)) throw new RuntimeException('ExifTool is needed to keep the photo\'s date, place and description.');

        $tmp = self::jobFile($id, $kind, 'save.jpg');
        if (!copy($res, $tmp)) throw new RuntimeException('Could not prepare the copy.');
        [$w, $h] = getimagesize($tmp) ?: [0, 0];
        $argFile = self::jobFile($id, $kind, 'args.txt');
        // As Enhance does: everything from the original, minus its (blurry) thumbnail and preview
        // and the HDR/motion-photo descriptions whose extra data the new file does not have.
        // Cloud results come back upright (cloudInput turned the photo), so they are marked so.
        $args = ['-n', '-TagsFromFile', pf_norm_path($path), '-all:all', '-unsafe', '-ICC_Profile', '-IPTC:CodedCharacterSet',
                 '--XMP-hdrgm:all', '--XMP-GContainer:all', '--XMP-GCamera:all', '--ThumbnailImage', '--PreviewImage',
                 '-ExifIFD:ExifImageWidth=' . $w, '-ExifIFD:ExifImageHeight=' . $h];
        if (in_array($kind, self::CLOUD_KINDS, true)) $args[] = '-IFD0:Orientation=1';
        array_push($args, '-m', '-overwrite_original', pf_norm_path($tmp));
        file_put_contents($argFile, implode("\n", $args) . "\n");
        [$exit, $stdout, $stderr] = pf_run_cmd([$tool, '-charset', 'filename=UTF8', '-@', $argFile]);
        @unlink($argFile);
        if (!preg_match('/\b1 image files? (updated|unchanged)/i', (string)$stdout)) {
            @unlink($tmp);
            throw new RuntimeException('Could not copy the photo\'s metadata: ' . trim((string)($stderr ?: $stdout)));
        }
        $kept = null;
        if ($mode === 'replace') {
            // The original is kept next to it as "<name> (original).jpg" (Clean up's Kept originals
            // list deletes them in bulk when the user chooses); this version takes its name,
            // description and place.
            $keptPath = pf_kept_original_path($path);
            if (!@rename($path, $keptPath)) {
                @unlink($tmp);
                throw new RuntimeException('Could not rename the original to keep it (is it open in another program?). Nothing was changed.');
            }
            if (!@rename($tmp, $path) && !(@copy($tmp, $path) && @unlink($tmp))) {
                @rename($keptPath, $path);
                throw new RuntimeException('Could not put the new version in the original\'s place. Nothing was changed.');
            }
            $keptId = Enhance::indexCopy($row, $keptPath);   // its description, people, date and size, before the row changes
            Thumb::ensureThumb($keptId, $keptPath);
            $kept = Enhance::rowForPage($keptId);
            $newId = (string)$row['id'];
            $dest = $path;
            clearstatcache(true, $path);
            Db::upsertPending($newId, $path, (int)filesize($path), (int)filemtime($path));
            @unlink(PF_THUMBS . '/' . $newId . '.jpg');
        } else {
            $dest = self::copyPath($path, $kind, isset($job['suffix']) ? (string)$job['suffix'] : null);
            if (!@rename($tmp, $dest)) {
                if (!@copy($tmp, $dest)) { @unlink($tmp); throw new RuntimeException('Could not write ' . $dest); }
                @unlink($tmp);
            }
            $newId = Enhance::indexCopy($row, $dest);   // copies the original's row, size included
        }
        // New pixels: the photo's own size (a cloud result is 2K, an enlargement larger), and for
        // a replaced photo Clean up's measurements, change.
        [$sw, $sh] = pf_image_size($dest) ?? [$w, $h];
        Db::get()->prepare('UPDATE images SET width = :w, height = :h' . ($mode === 'replace' ? ', analyzed_at = NULL' : '') . ' WHERE id = :id')
            ->execute([':w' => $sw, ':h' => $sh, ':id' => $newId]);
        Thumb::ensureThumb($newId, $dest);
        $removed = '';
        if ($mode === 'replace') {
            // This version now IS the photo: its saved copy would only be a duplicate (to the
            // Recycle Bin, like the old original). The other versions' copies stay.
            $sp = (string)($job['saved_path'] ?? '');
            if ($sp !== '' && pf_norm_path($sp) !== pf_norm_path($path) && is_file($sp)) {
                $rec = pf_recycle_files([$sp])[$sp] ?? ['ok' => false];
                if ($rec['ok']) {
                    $removed = (string)($job['saved_id'] ?? pf_path_id($sp));
                    Db::deleteImage($removed);
                    @unlink(PF_THUMBS . '/' . $removed . '.jpg');
                }
            }
            // Every result was made from the old original: clear them all.
            self::discard($id);
        } else {
            // Keep the result on view (to compare with the others), marked saved.
            $j = self::jobStatus($id, $kind);
            $j['saved'] = basename($dest);
            $j['saved_path'] = $dest;
            $j['saved_id'] = $newId;
            unset($j['save_error']);
            self::writeJson(self::jobFile($id, $kind, 'json'), $j);
        }
        $r = Db::getById($newId) ?? [];
        $r['sort_date'] = ($r['taken_at'] ?? '') !== '' ? $r['taken_at'] : (($r['folder_year'] ?? '') !== '' ? $r['folder_year'] : null);
        $r['v'] = (int)($r['mtime'] ?? time());
        return ['ok' => true, 'mode' => $mode, 'name' => basename($dest), 'image' => $r, 'removed_copy' => $removed,
                'kept' => $kept, 'stats' => Db::stats()];
    }

    // ---- Batch: the same fixes for many photos, one after another, on this PC ----
    //
    // Clean up starts it for the photos selected there (batchStart). deblur-batch.php works
    // through them one at a time at idle priority (runBatch): for each photo the chosen steps run
    // each on the original on its own and, when more than one applies, all in turn (remove blur,
    // correct colour, enlarge a small photo); every version is saved as a copy next to the
    // original and kept as the photo's version of its kind (batchOne), so the Compare window and
    // the review page (batchReview, batchReplace) show them all beside the original. State goes
    // to data/deblur/batch.json; a Stop request is the file batch.cancel.

    public const BATCH_STEPS = ['deblur', 'color', 'enlarge'];
    private static function batchFile(): string { return self::dir() . '/batch.json'; }
    private static function batchCancelFile(): string { return self::dir() . '/batch.cancel'; }
    private static function batchWorkDir(): string { return self::dir() . '/batch-work'; }

    public static function batchStatus(): ?array {
        $b = json_decode((string)@file_get_contents(self::batchFile()), true);
        if (!is_array($b)) return null;
        // The runner writes at least every few seconds while it works (a step on this PC reports
        // every tile); one that has been silent for five minutes is gone.
        if (($b['state'] ?? '') === 'running' && ($b['updated'] ?? 0) < time() - 300) {
            $b['state'] = 'stopped';
            $b['note'] = 'The batch stopped unexpectedly (the PC restarted, or it was ended).';
        }
        $b['cancelling'] = ($b['state'] ?? '') === 'running' && is_file(self::batchCancelFile());
        return $b;
    }

    /** Queue $ids for the $steps (BATCH_STEPS) and start the runner. Photos outside the photo
     *  folders are left out; so are photos already fixed by an earlier batch with the same steps. */
    public static function batchStart(array $ids, array $steps, bool $ai = false): array {
        $steps = array_values(array_intersect(self::BATCH_STEPS, $steps));
        if (!$steps) throw new RuntimeException('Choose at least one fix.');
        $b = self::batchStatus();
        if ($b && ($b['state'] ?? '') === 'running') throw new RuntimeException('A batch is already running. Wait for it to finish, or stop it in Queue.');
        if (in_array('deblur', $steps, true) && !self::isReady()) {
            throw new RuntimeException('Removing blur on this PC is not set up yet: open "Fix blur" on any one photo first (a one-time download).');
        }
        if (in_array('enlarge', $steps, true) && !self::isUpscaleReady()) {
            throw new RuntimeException('Enlarging on this PC is not set up yet: open "Enlarge" on any one small photo first (a one-time download).');
        }
        if ($ai && !self::aiAvailable()) {
            throw new RuntimeException('The AI pick uses Claude, the AI that describes your photos: choose Claude and enter its API key in Settings, or untick the AI pick.');
        }
        $roots = pf_load_settings()['photo_dirs'] ?? [];
        $items = [];
        $already = 0;
        foreach (array_unique(array_map('strval', $ids)) as $id) {
            $id = (string)preg_replace('/[^a-f0-9]/', '', $id);
            $row = $id !== '' ? Db::getById($id) : null;
            if (!$row || !pf_path_inside_roots((string)$row['path'], $roots)) continue;
            if (self::batchAlreadyDone($id, $steps)) { $already++; continue; }
            $items[] = ['id' => $id, 'name' => basename((string)$row['path']), 'path' => (string)$row['path'], 'state' => 'queued'];
        }
        if (!$items) {
            throw new RuntimeException($already ? 'Every selected photo was already fixed this way by an earlier batch.' : 'None of the selected photos can be fixed.');
        }
        @unlink(self::batchCancelFile());
        $b = ['id' => bin2hex(random_bytes(4)), 'steps' => $steps, 'ai' => $ai, 'state' => 'running', 'items' => $items, 'already' => $already,
              'current' => -1, 'started' => time(), 'updated' => time()];
        self::writeJson(self::batchFile(), $b);
        try {
            self::launch(self::phpExe(), [self::win(PF_ROOT . '/deblur-batch.php'), self::win(PF_DATA)]);
        } catch (Throwable $e) {
            $b['state'] = 'stopped';
            $b['note'] = 'The batch could not start: ' . $e->getMessage();
            self::writeJson(self::batchFile(), $b);
            throw $e;
        }
        return self::batchStatus() ?? $b;
    }

    /** Ask the runner to stop: the step in progress is ended, the rest is not started. */
    public static function batchCancel(): void {
        $b = self::batchStatus();
        if ($b && ($b['state'] ?? '') === 'running') touch(self::batchCancelFile());
    }

    /** Runs in deblur-batch.php. */
    public static function runBatch(): void {
        $b = json_decode((string)@file_get_contents(self::batchFile()), true);
        if (!is_array($b) || ($b['state'] ?? '') !== 'running') return;
        $work = self::batchWorkDir();
        if (!is_dir($work)) @mkdir($work, 0777, true);
        $last = 0;
        $save = function (bool $now = true) use (&$b, &$last): void {
            if (!$now && time() === $last) return;
            $last = time();
            $b['updated'] = time();
            self::writeJson(self::batchFile(), $b);
        };
        $cancelled = false;
        $n = count($b['items']);
        for ($i = 0; $i < $n; $i++) {
            if (($b['items'][$i]['state'] ?? '') !== 'queued') continue;
            if (is_file(self::batchCancelFile())) { $cancelled = true; break; }
            $b['current'] = $i;
            $b['items'][$i] = array_merge($b['items'][$i], ['state' => 'running', 'step' => '', 'done' => 0, 'total' => 0, 'started' => time()]);
            $save();
            $tick = function (string $step, int $done = 0, int $total = 0, int $eta = 0) use (&$b, $i, $save): bool {
                $b['items'][$i] = array_merge($b['items'][$i], ['step' => $step, 'done' => $done, 'total' => $total, 'eta' => $eta]);
                $save(false);
                return !is_file(self::batchCancelFile());
            };
            try {
                $b['items'][$i] = array_merge($b['items'][$i], self::batchOne((string)$b['items'][$i]['id'], $b['steps'], (string)$b['id'], !empty($b['ai']), $tick));
            } catch (DeblurCancelled $e) {
                $b['items'][$i]['state'] = 'cancelled';
                $cancelled = true;
            } catch (Throwable $e) {
                $b['items'][$i]['state'] = 'error';
                $b['items'][$i]['error'] = $e->getMessage();
            }
            $b['items'][$i]['seconds'] = time() - (int)$b['items'][$i]['started'];
            foreach (glob($work . '/' . $b['items'][$i]['id'] . '-*') ?: [] as $f) @unlink($f);
            $save();
            if ($cancelled) break;
        }
        if ($cancelled) {
            foreach ($b['items'] as &$it) if (($it['state'] ?? '') === 'queued') $it['state'] = 'cancelled';
            unset($it);
        }
        $b['state'] = $cancelled ? 'stopped' : 'done';
        $b['current'] = -1;
        $b['finished'] = time();
        $save();
        @unlink(self::batchCancelFile());
    }

    /** One photo through the batch. Each chosen fix is applied to the original on its own and
     *  kept as that photo's version of the matching kind ('local' blur removed, 'color' colour
     *  corrected, 'up_local' enlarged); when more than one applies, they are also applied in
     *  turn (blur, then colour, then enlarge) as its 'fixed' version. Every version is saved as a
     *  copy next to the photo, so they can all be compared side by side and one put in place. */
    private static function batchOne(string $id, array $steps, string $batchId, bool $ai, callable $tick): array {
        $row = Db::getById($id);
        if (!$row || !is_file((string)$row['path'])) throw new RuntimeException('The photo is no longer on disk.');
        $work = self::batchWorkDir();
        $prog = "$work/$id-progress.json";
        $orig = (string)$row['path'];
        $t0 = time();
        $out = [];     // kind => [file, label]
        $notes = [];
        $deblur = fn(string $in, string $to, string $step) => self::runPython(
            [self::python(), '-I', PF_ROOT . '/deblur.py', self::model(), $in, $to, $prog], $prog, $step, $tick);
        $enlarge = fn(string $in, string $to, int $scale, string $step) => self::runPython(
            [self::python(), '-I', PF_ROOT . '/enlarge.py', self::upscaleModel(), $in, $to, $prog, (string)$scale], $prog, $step, $tick);

        // Each fix on its own.
        if (in_array('deblur', $steps, true)) {
            $deblur($orig, "$work/$id-d.jpg", 'removing blur');
            $out['local'] = ["$work/$id-d.jpg", 'blur removed'];
        }
        if (in_array('color', $steps, true)) {
            if (!$tick('correcting colour')) throw new DeblurCancelled();
            if (self::autoColour($orig, "$work/$id-c.jpg")) $out['color'] = ["$work/$id-c.jpg", 'colour corrected'];
            else $notes[] = 'colour already fine';
        }
        if (in_array('enlarge', $steps, true)) {
            $e = self::enlargeInfo(['path' => $orig]);
            if ($e['ok']) {
                $enlarge($orig, "$work/$id-e.jpg", $e['scale'], 'enlarging');
                $out['up_local'] = ["$work/$id-e.jpg", 'enlarged ' . $e['scale'] . '×'];
            } else {
                $notes[] = 'already large, not enlarged';
            }
        }
        if (!$out) return ['state' => 'skipped', 'note' => ucfirst(implode('; ', $notes) ?: 'nothing to change') . '.'];

        // All of them in turn, when more than one applies.
        if (count($out) > 1) {
            $cur = $out['local'][0] ?? '';
            $parts = $cur !== '' ? ['blur removed'] : [];
            if (isset($out['color'])) {
                if ($cur === '') {
                    $cur = $out['color'][0];
                    $parts[] = 'colour corrected';
                } else {
                    if (!$tick('all fixes: correcting colour')) throw new DeblurCancelled();
                    if (self::autoColour($cur, "$work/$id-dc.jpg")) { $cur = "$work/$id-dc.jpg"; $parts[] = 'colour corrected'; }
                }
            }
            if (isset($out['up_local']) && $cur !== '') {
                $enlarge($cur, "$work/$id-f.jpg", self::enlargeInfo(['path' => $cur])['scale'], 'all fixes: enlarging');
                $cur = "$work/$id-f.jpg";
                $parts[] = $out['up_local'][1];
            }
            if (count($parts) > 1) $out['fixed'] = [$cur, implode(', ', $parts)];
        }

        // Best size: a 4x enlargement also gets its 2x size (made from the 4x), to choose between.
        if (str_ends_with($out['up_local'][1] ?? '', '4×')) {
            foreach (['up_local' => 'up_local_2x', 'fixed' => 'fixed_2x'] as $k4 => $k2) {
                if (!isset($out[$k4]) || !str_ends_with($out[$k4][1], '4×')) continue;
                self::halfSize($out[$k4][0], "$work/$id-$k2.jpg");
                $out[$k2] = ["$work/$id-$k2.jpg", substr($out[$k4][1], 0, -strlen('4×')) . '2×'];
            }
        }
        $out = array_filter(array_merge(array_fill_keys(self::BATCH_KINDS, null), $out));

        // Save every version as a copy, and keep it as the photo's version of its kind.
        if (!$tick('saving')) throw new DeblurCancelled();
        if (!is_dir(self::jobsDir())) @mkdir(self::jobsDir(), 0777, true);
        $versions = [];
        foreach ($out as $kind => [$file, $label]) {
            $jpg = self::jobFile($id, $kind, 'jpg');
            if (!copy($file, $jpg)) throw new RuntimeException('Could not keep the result.');
            [$w, $h] = getimagesize($jpg) ?: [0, 0];
            @unlink(self::jobFile($id, $kind, 'auto'));
            $title = str_starts_with($kind, 'fixed') ? 'All fixes: ' . $label : ucfirst($label);
            self::writeJson(self::jobFile($id, $kind, 'json'), [
                'state' => 'done', 'started' => $t0, 'seconds' => time() - $t0, 'width' => (int)$w, 'height' => (int)$h, 'updated' => time(),
                'batch' => $batchId, 'batch_steps' => $steps, 'label' => 'Batch: ' . $title,
            ]);
            try {
                $res = self::save($row, $kind, 'copy');
            } finally {
                @unlink($jpg);   // the saved copy stands for the version from here on (resultFile)
            }
            $versions[] = ['kind' => $kind, 'label' => $title, 'name' => $res['name'], 'saved_id' => (string)($res['image']['id'] ?? ''),
                           'width' => (int)$w, 'height' => (int)$h];
        }
        $n = count($versions);
        $res = ['state' => 'done', 'versions' => $versions, 'saved' => $versions[$n - 1]['name'],
                'note' => ($n > 1 ? "$n versions saved: " : 'Saved: ') . implode('; ', array_column($versions, 'label'))
                    . ($notes ? ' (' . implode('; ', $notes) . ')' : '') . '.'];
        // The AI's pick, for the review page and the Compare window (a failed pick is only a note).
        if ($ai) {
            if (!$tick('AI comparing the versions')) throw new DeblurCancelled();
            try {
                $res['ai'] = self::aiPick($row, $versions) + ['batch' => $batchId];
                self::writeJson(self::jobFile($id, 'ai', 'json'), $res['ai']);
            } catch (DeblurCancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $res['ai'] = ['error' => $e->getMessage()];
            }
        }
        return $res;
    }

    /** The 2x size of a 4x enlargement: halved with GD's area-averaging resampler. */
    private static function halfSize(string $in, string $to): void {
        $src = @imagecreatefromjpeg($in);
        if (!$src) throw new RuntimeException('Could not read the enlargement.');
        $w = intdiv(imagesx($src), 2);
        $h = intdiv(imagesy($src), 2);
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        if (!imagejpeg($dst, $to, 95)) throw new RuntimeException('Could not write the 2× version.');
    }

    /** Whether photo $id already has a version from a batch with these $steps. */
    private static function batchAlreadyDone(string $id, array $steps): bool {
        foreach (self::BATCH_KINDS as $k) {
            $j = self::jobStatus($id, $k);
            if (($j['state'] ?? '') === 'done' && !empty($j['saved']) && ($j['batch_steps'] ?? null) === $steps) return true;
        }
        return false;
    }

    // ---- AI pick (batch): which version to keep, judged by Claude with the describing key ----

    private const JUDGE_SYSTEM = 'You compare versions of one family photo made by automatic repair steps (blur removal, '
        . 'colour correction, AI enlargement) and choose the one the owner should keep in place of the original. '
        . 'Judge in this order. 1. Faces: every face must still look natural and like the same person. Reject any '
        . 'version where a face is distorted, smeared, waxy or plastic-looking, or has changed eyes, teeth, mouth, '
        . 'skin or expression. 2. No new artifacts: halos, ringing, painted or oil-paint texture, blotches, '
        . 'over-sharpening, colour casts. 3. Then prefer more real detail and better, natural colour. Bigger is '
        . 'not better by itself: choose an enlarged version only if its close-ups look natural, and between the '
        . '2x and 4x sizes of the same version choose 4x only if it looks as natural as 2x. Choose the original '
        . 'when no version is clearly better.';

    private static function judge(): ?Claude {
        $s = pf_load_settings();
        if (Describer::backendOf($s) !== 'anthropic' || trim((string)($s['api_key'] ?? '')) === '') return null;
        return new Claude(trim((string)$s['api_key']), (string)($s['model'] ?? Claude::DEFAULT_MODEL));
    }

    /** Whether a batch can have the AI pick (Claude is the describing AI and has its key). */
    public static function aiAvailable(): bool {
        require_once __DIR__ . '/Describer.php';
        require_once __DIR__ . '/Claude.php';
        return self::judge() !== null;
    }

    private static function imageBlock(string $jpeg): array {
        return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]];
    }

    /** Close-ups of $boxes (fractions of the upright picture), each 40% larger than the box, at the
     *  picture's own resolution (at most $max px), so faces and enlargement show as they really are. */
    private static function closeUps(string $path, array $boxes, int $max = 512): array {
        [$im, $o] = Enhance::load($path);
        $im = Enhance::orient($im, $o);
        $W = imagesx($im);
        $H = imagesy($im);
        $out = [];
        foreach ($boxes as $b) {
            $bw = (float)$b['w'] * 1.4;
            $bh = (float)$b['h'] * 1.4;
            $x0 = (int)max(0, ((float)$b['x'] + (float)$b['w'] / 2 - $bw / 2) * $W);
            $y0 = (int)max(0, ((float)$b['y'] + (float)$b['h'] / 2 - $bh / 2) * $H);
            $cw = (int)max(8, min($W - $x0, $bw * $W));
            $ch = (int)max(8, min($H - $y0, $bh * $H));
            $crop = imagecrop($im, ['x' => $x0, 'y' => $y0, 'width' => $cw, 'height' => $ch]);
            if (!$crop) continue;
            if (max($cw, $ch) > $max) $crop = Enhance::fit($crop, $max);
            ob_start();
            imagejpeg($crop, null, 90);
            $out[] = (string)ob_get_clean();
        }
        return $out;
    }

    /** Ask Claude which of the photo's $versions to keep. It sees each one whole and in close-ups
     *  of the faces (or of the centre) at its own resolution. Returns [kind ('original' or a
     *  version's kind), reason, distorted kinds, faces seen]. */
    private static function aiPick(array $row, array $versions): array {
        require_once __DIR__ . '/Describer.php';
        require_once __DIR__ . '/Claude.php';
        $c = self::judge();
        if (!$c) throw new RuntimeException('it needs Claude as the describing AI, with its API key');
        $orig = (string)$row['path'];
        $view = fn(string $p) => self::imageBlock(Thumb::prepareForApi($p, 1024)['bytes']);
        $box = ['type' => 'object', 'additionalProperties' => false, 'required' => ['x', 'y', 'w', 'h'],
                'properties' => ['x' => ['type' => 'number'], 'y' => ['type' => 'number'], 'w' => ['type' => 'number'], 'h' => ['type' => 'number']]];
        $f = $c->structured('You locate human faces in photos.', [$view($orig), ['type' => 'text', 'text' =>
            'List up to 3 of the largest human faces in this photo, each as a box with x, y, w and h given as fractions (0 to 1) of the '
            . "picture's width and height, measured from its top-left corner. Return an empty list if there are no faces."]],
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['faces'], 'properties' => ['faces' => ['type' => 'array', 'items' => $box]]], 'low', 2000);
        $boxes = [];
        foreach ((array)($f['faces'] ?? []) as $b) {
            if (!is_array($b)) continue;
            $x = max(0.0, min(1.0, (float)($b['x'] ?? 0))); $y = max(0.0, min(1.0, (float)($b['y'] ?? 0)));
            $w = min(1.0 - $x, (float)($b['w'] ?? 0)); $h = min(1.0 - $y, (float)($b['h'] ?? 0));
            if ($w > 0.005 && $h > 0.005) $boxes[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        }
        $boxes = array_slice($boxes, 0, 3);
        $faces = (bool)$boxes;
        if (!$faces) $boxes = [['x' => 0.3, 'y' => 0.3, 'w' => 0.4, 'h' => 0.4]];
        $cands = [['id' => 'O', 'kind' => 'original', 'label' => 'Original', 'path' => $orig]];
        foreach ($versions as $i => $v) {
            $vr = ($v['saved_id'] ?? '') !== '' ? Db::getById((string)$v['saved_id']) : null;
            if ($vr && is_file((string)$vr['path'])) $cands[] = ['id' => 'V' . ($i + 1), 'kind' => $v['kind'], 'label' => $v['label'], 'path' => (string)$vr['path']];
        }
        if (count($cands) < 2) throw new RuntimeException('there are no versions to compare');
        $content = [['type' => 'text', 'text' => 'Candidates for one photo follow: the original (O) and ' . (count($cands) - 1)
            . ' repaired versions. For each: the whole picture, then ' . ($faces ? 'close-ups of the faces' : 'a close-up of the centre')
            . " at that version's own resolution (an enlarged version's close-ups are larger and show its real detail)."]];
        foreach ($cands as $cd) {
            [$w, $h] = pf_image_size($cd['path']) ?? [0, 0];
            $content[] = ['type' => 'text', 'text' => "Candidate {$cd['id']}: {$cd['label']}, {$w}×{$h} px. Whole picture:"];
            $content[] = $view($cd['path']);
            $content[] = ['type' => 'text', 'text' => "Candidate {$cd['id']} close-ups:"];
            foreach (self::closeUps($cd['path'], $boxes) as $jpg) $content[] = self::imageBlock($jpg);
        }
        $content[] = ['type' => 'text', 'text' => 'Which candidate should be kept? Answer with its letter-number (O, V1, V2...), '
            . 'the candidates whose faces look distorted (empty list if none), and one short plain-English sentence saying why.'];
        $ids = array_column($cands, 'id');
        $r = $c->structured(self::JUDGE_SYSTEM, $content, ['type' => 'object', 'additionalProperties' => false, 'required' => ['best', 'distorted', 'reason'],
            'properties' => ['best' => ['type' => 'string', 'enum' => $ids], 'distorted' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $ids]],
                             'reason' => ['type' => 'string']]], 'medium', 8000);
        $byId = array_column($cands, 'kind', 'id');
        $best = $byId[(string)($r['best'] ?? '')] ?? 'original';
        $distorted = array_values(array_filter(array_map(fn($x) => $byId[(string)$x] ?? null, (array)($r['distorted'] ?? []))));
        if (in_array($best, $distorted, true)) $best = 'original';   // never keep a version it says distorts a face
        return ['kind' => $best, 'reason' => trim((string)($r['reason'] ?? '')), 'distorted' => $distorted, 'faces' => $faces];
    }

    // ---- Reviewing a batch: every photo with its versions, and replacing the chosen originals ----

    private static function batchReplacedFile(): string { return self::dir() . '/batch-replaced.json'; }

    /** Photos of batch $batchId already replaced from the review page: id => [kind, name, at]. */
    private static function batchReplaced(string $batchId): array {
        $r = json_decode((string)@file_get_contents(self::batchReplacedFile()), true);
        return is_array($r) && ($r['batch'] ?? '') === $batchId && is_array($r['items'] ?? null) ? $r['items'] : [];
    }

    /** The finished photos of the current batch, $per at a time, each with its versions. */
    public static function batchReview(int $page, int $per = 40): array {
        $b = self::batchStatus();
        if (!$b) return ['items' => [], 'total' => 0, 'page' => 0, 'per' => $per];
        $replaced = self::batchReplaced((string)$b['id']);
        $done = array_values(array_filter($b['items'] ?? [], fn($i) => ($i['state'] ?? '') === 'done' && !empty($i['versions'])));
        $page = max(0, min($page, intdiv(max(0, count($done) - 1), $per)));
        $items = [];
        foreach (array_slice($done, $page * $per, $per) as $it) {
            $row = Db::getById((string)$it['id']);
            $vs = [];
            foreach ($it['versions'] as $v) {
                $vr = $v['saved_id'] !== '' ? Db::getById($v['saved_id']) : null;
                $v['exists'] = $vr && is_file((string)$vr['path']);
                $v['v'] = $vr ? (int)$vr['mtime'] : 0;
                $vs[] = $v;
            }
            $items[] = ['id' => (string)$it['id'], 'name' => (string)$it['name'], 'path' => (string)$it['path'],
                        'exists' => $row && is_file((string)$row['path']), 'v' => $row ? (int)$row['mtime'] : 0,
                        'width' => $row ? (int)$row['width'] : 0, 'height' => $row ? (int)$row['height'] : 0,
                        'versions' => $vs, 'ai' => $it['ai'] ?? null, 'replaced' => $replaced[(string)$it['id']] ?? null];
        }
        return ['batch' => ['id' => $b['id'], 'steps' => $b['steps'], 'state' => $b['state']], 'items' => $items,
                'total' => count($done), 'page' => $page, 'per' => $per, 'replaced' => count($replaced)];
    }

    /** Replace each chosen original ([['id' => .., 'kind' => ..], ...]) with that version, as
     *  "Replace the original" does for one photo. Returns one result per choice. */
    public static function batchReplace(array $choices): array {
        $b = self::batchStatus();
        if (!$b) throw new RuntimeException('There is no batch to review.');
        $roots = pf_load_settings()['photo_dirs'] ?? [];
        $rep = self::batchReplaced((string)$b['id']);
        $results = [];
        foreach (array_slice($choices, 0, 50) as $c) {
            $id = (string)preg_replace('/[^a-f0-9]/', '', (string)($c['id'] ?? ''));
            $kind = (string)($c['kind'] ?? '');
            try {
                if (!in_array($kind, self::BATCH_KINDS, true)) throw new RuntimeException('Unknown version.');
                $row = $id !== '' ? Db::getById($id) : null;
                if (!$row) throw new RuntimeException('The photo is no longer in the index.');
                if (!pf_path_inside_roots((string)$row['path'], $roots)) throw new RuntimeException('The photo is outside the photo folders.');
                $res = self::save($row, $kind, 'replace');
                $rep[$id] = ['kind' => $kind, 'name' => $res['name'], 'at' => time()];
                $results[] = ['id' => $id, 'ok' => true, 'image' => $res['image'], 'removed_copy' => $res['removed_copy'], 'kept' => $res['kept']];
            } catch (Throwable $e) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        self::writeJson(self::batchReplacedFile(), ['batch' => $b['id'], 'items' => $rep]);
        return ['results' => $results, 'stats' => Db::stats()];
    }

    /** deblur.py / enlarge.py for a batch: waits, passing its progress to $tick, and ends it
     *  when $tick says the batch was stopped. */
    private static function runPython(array $cmd, string $progress, string $step, callable $tick): void {
        @unlink($progress);
        $err = self::batchWorkDir() . '/python-stderr.txt';
        $proc = proc_open(array_map([self::class, 'win'], $cmd), [0 => ['pipe', 'r'], 1 => ['file', $err . '.out', 'w'], 2 => ['file', $err, 'w']],
            $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) throw new RuntimeException('Could not start the on-PC AI.');
        fclose($pipes[0]);
        while (true) {
            $running = proc_get_status($proc)['running'];
            $p = json_decode((string)@file_get_contents($progress), true) ?: [];
            if (!$tick($step, (int)($p['done'] ?? 0), (int)($p['total'] ?? 0), (int)($p['eta'] ?? 0))) {
                proc_terminate($proc);
                proc_close($proc);
                throw new DeblurCancelled();
            }
            if (!$running) break;
            usleep(500000);
        }
        proc_close($proc);
        $p = json_decode((string)@file_get_contents($progress), true) ?: [];
        if (($p['state'] ?? '') !== 'done') {
            $last = trim((string)strrchr("\n" . trim((string)@file_get_contents($err)), "\n"));
            throw new RuntimeException(ucfirst($step) . ' failed: ' . ((string)($p['error'] ?? '') ?: ($last !== '' ? mb_substr($last, 0, 200) : 'it stopped without finishing.')));
        }
    }

    /** Enhance's Auto settings (colour, brightness, contrast, gentle sharpening), as its Auto
     *  button gives them, applied to $in; false when Auto finds nothing to change. Pixels stay in
     *  the stored orientation (the final copy takes the original's metadata and Orientation). */
    private static function autoColour(string $in, string $out): bool {
        [$im] = Enhance::load($in);
        $sample = Enhance::fit(Enhance::fit($im, Enhance::PREVIEW_MAX), 400);
        $p = Enhance::sanitize(Enhance::auto($sample));
        $sample = null;
        if (Enhance::isNeutral($p)) return false;
        [$blur, $bx, $by] = $p['sharpen'] > 0 ? Enhance::blurFor($im) : [null, 0, 0];
        Enhance::apply($im, $p, $blur, $bx, $by);
        $blur = null;
        if (!imagejpeg($im, $out, 95)) throw new RuntimeException('Could not write the colour-corrected picture.');
        return true;
    }

    // ---- Queue: everything Fix blur / Enlarge is doing, for the Queue page ----

    /** Single-photo jobs that are running, saving, or finished in the last day; and the batch. */
    public static function queue(): array {
        $jobs = [];
        foreach (glob(self::jobsDir() . '/*.json') ?: [] as $f) {
            if (!preg_match('/^([a-f0-9]{40})-(local|cloud|chain|up_local|up_cloud)\.json$/', basename($f), $m)) continue;   // a batch's results are listed with the batch
            [, $id, $kind] = $m;
            $j = self::jobStatus($id, $kind);
            if (($j['state'] ?? 'none') === 'none' || !empty($j['batch'])) continue;   // a batch's versions are listed with the batch
            $active = ($j['state'] ?? '') === 'running' || !empty($j['autosave']);
            $updated = (int)($j['updated'] ?? @filemtime($f));
            if (!$active && $updated < time() - 86400) continue;
            $row = Db::getById($id);
            $jobs[] = [
                'id' => $id, 'kind' => $kind, 'active' => $active, 'updated' => $updated,
                'name' => $row ? basename((string)$row['path']) : '(no longer in the index)', 'path' => $row ? (string)$row['path'] : '',
                'state' => (string)($j['state'] ?? ''), 'done' => (int)($j['done'] ?? 0), 'total' => (int)($j['total'] ?? 0), 'eta' => (int)($j['eta'] ?? 0),
                'saved' => (string)($j['saved'] ?? ''), 'error' => (string)($j['error'] ?? ($j['save_error'] ?? '')),
            ];
        }
        usort($jobs, fn($a, $b) => [$b['active'], $b['updated']] <=> [$a['active'], $a['updated']]);
        $batch = self::batchStatus();
        $active = count(array_filter($jobs, fn($j) => $j['active'])) + ($batch && ($batch['state'] ?? '') === 'running' ? 1 : 0);
        return ['jobs' => array_slice($jobs, 0, 100), 'batch' => $batch, 'active' => $active];
    }

    // ---- Helpers ----

    private static function writeJson(string $file, array $data): void {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $tmp = $file . '.' . getmypid() . '.tmp';   // its own name: two writers never share one
        if (@file_put_contents($tmp, json_encode($data)) === false) return;
        // Windows refuses to replace a file another process is reading at that instant (the page
        // and Queue poll often): try again for up to 2 seconds rather than lose the write.
        for ($i = 0; $i < 100; $i++) {
            if (@rename($tmp, $file)) return;
            usleep(20000);
        }
        @unlink($tmp);
    }

    private static function win(string $p): string { return str_replace('/', '\\', $p); }

    /** The installed copy's own php.exe; else (a source checkout) the PHP running this code. */
    private static function phpExe(): string {
        foreach ([PF_ROOT . '/php/php.exe',
                  strtolower(basename(PHP_BINARY)) === 'php.exe' ? PHP_BINARY : '',
                  php_ini_loaded_file() ? dirname(php_ini_loaded_file()) . '/php.exe' : ''] as $c) {
            if ($c !== '' && is_file($c)) return self::win($c);
        }
        throw new RuntimeException('php.exe not found.');
    }

    /** Started through ShellExecute (pf-helper.ps1 "launch"), hidden, inheriting nothing. */
    private static function launch(string $file, array $args): void {
        [$exit, , $err] = pf_helper('launch', json_encode(['file' => $file, 'args' => $args, 'hidden' => true]));
        if ($exit !== 0) throw new RuntimeException('Could not start: ' . trim((string)$err));
    }
}

/** A batch was stopped (Deblur::runBatch). */
final class DeblurCancelled extends RuntimeException {}
