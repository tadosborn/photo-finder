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
 * Fix blur: makes a sharper copy of a blurred photo, on this PC or with Google's Gemini.
 *
 * On this PC: the NAFNet deblurring model (MIT licence, from OpenCV's model zoo) on ONNX Runtime,
 * run by deblur.py under its own Python. That runtime (Python embeddable, onnxruntime, numpy,
 * Pillow and the model, about 139 MB) is downloaded on first use into data/deblur, each file
 * checked against the SHA-256 pinned in PACK. Good on camera shake and motion blur; it cannot
 * bring back a photo that is out of focus. About 2 minutes per 3 MP photo on a typical processor.
 *
 * Cloud: Gemini image editing (Interactions API) with the user's own Google AI key. The model
 * redraws the photo, so faces and text can come out different. It is offered as a second try,
 * sends the photo to Google, and costs a few cents per photo (billed by Google).
 *
 * Both run as background jobs, started through ShellExecute (pf-helper "launch", as
 * pull-model.php is) so they inherit none of the web server's handles. Each writes its state to
 * data/deblur/jobs/<id>-<kind>.json and its result to <id>-<kind>.jpg; the page polls status()
 * and shows the result before anything is written next to the photo. save() then writes
 * "<name> (deblurred).jpg" or "<name> (restored).jpg" beside the original, with the original's
 * metadata, and indexes it like an Enhance copy. The original is never changed.
 *
 * Pixels stay in the file's stored orientation (no EXIF rotation is applied), so the original's
 * Orientation tag, copied along, stays right for the new file.
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
    public const KINDS = ['local', 'cloud'];
    public const SUFFIX = ['local' => 'deblurred', 'cloud' => 'restored'];

    /** Gemini image-editing models offered in Settings (prices from Google's pricing page, Nov 2026). */
    public const GEMINI_MODELS = [
        'gemini-nano-banana-2.1' => ['label' => 'Nano Banana 2.1, 2K', 'size' => '2K', 'cost' => 'about $0.05 per photo'],
        'gemini-3.1-flash-lite-image' => ['label' => 'Nano Banana 2 Lite, 1K (cheaper, smaller)', 'size' => '1K', 'cost' => 'about $0.03 per photo'],
    ];
    public const GEMINI_DEFAULT = 'gemini-nano-banana-2.1';
    public const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';
    private const GEMINI_RATIOS = ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];
    private const CLOUD_MAX_SIDE = 3072;
    private const PROMPT = 'Restore this photo: remove the blur from camera shake, motion or focus so it looks sharp and '
        . 'natural, as if the picture had been taken correctly. Keep everything else exactly the same: the same '
        . 'people, faces, expressions, poses, clothing, objects, text, colours, lighting, framing and composition. '
        . 'Do not add, remove or restyle anything.';

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

    // ---- The on-PC runtime ----

    public static function isReady(): bool {
        $r = json_decode((string)@file_get_contents(self::packDir() . '/ready.json'), true);
        return is_array($r) && ($r['version'] ?? 0) === self::PACK_VERSION && is_file(self::python()) && is_file(self::model());
    }

    public static function setupStatus(): ?array {
        $s = json_decode((string)@file_get_contents(self::setupFile()), true);
        if (!is_array($s)) return null;
        if (empty($s['done']) && empty($s['error']) && ($s['updated'] ?? 0) < time() - 120) {
            $s['error'] = 'The download stopped. Try again.';
        }
        return $s;
    }

    public static function startSetup(): void {
        if (self::isReady()) return;
        $st = self::setupStatus();
        if ($st && empty($st['done']) && empty($st['error'])) throw new RuntimeException('The download is already running.');
        self::writeJson(self::setupFile(), ['status' => 'starting', 'done' => false, 'error' => '', 'got' => 0, 'total' => self::packBytes(), 'updated' => time()]);
        self::launch(self::phpExe(), [self::win(PF_ROOT . '/deblur-setup.php'), self::win(PF_DATA)]);
    }

    /** Runs in deblur-setup.php: download, check and unpack the runtime. A file already in
     *  PF_DEBLUR_CACHE (a folder) with the right SHA-256 is copied instead of downloaded. */
    public static function runSetup(): void {
        $total = self::packBytes();
        $state = ['status' => 'downloading', 'done' => false, 'error' => '', 'got' => 0, 'total' => $total, 'file' => '', 'updated' => time()];
        $save = function () use (&$state): void { $state['updated'] = time(); self::writeJson(self::setupFile(), $state); };
        $dl = self::dir() . '/download';
        try {
            foreach ([$dl, self::packDir()] as $d) if (!is_dir($d) && !@mkdir($d, 0777, true)) throw new RuntimeException("Cannot create $d");
            $before = 0;
            foreach (self::PACK as $f) {
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
            foreach (self::PACK as $f) @unlink($dl . '/' . $f['file']);   // unpacked; the downloads are no longer needed
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

    /** Start fixing photo $row on this PC ('local') or with Gemini ('cloud'). */
    public static function start(array $row, string $kind): void {
        if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException('Unknown kind.');
        $id = (string)$row['id'];
        $st = self::jobStatus($id, $kind);
        if (($st['state'] ?? '') === 'running') throw new RuntimeException('Already working on this photo.');
        // Check everything before recording a job, so a refusal leaves no "running" state behind.
        if ($kind === 'local' && !self::isReady()) throw new RuntimeException('Fix blur is not set up on this PC yet.');
        if ($kind === 'cloud' && !self::hasCloudKey()) throw new RuntimeException('Add a Google Gemini API key in Settings > Fix blur first.');
        if (!is_dir(self::jobsDir())) @mkdir(self::jobsDir(), 0777, true);
        $jf = self::jobFile($id, $kind, 'json');
        @unlink(self::jobFile($id, $kind, 'jpg'));
        self::writeJson($jf, ['state' => 'running', 'done' => 0, 'total' => 0, 'started' => time(), 'updated' => time()]);
        try {
            if ($kind === 'local') {
                self::launch(self::win(self::python()), ['-I', self::win(PF_ROOT . '/deblur.py'), self::win(self::model()),
                    self::win((string)$row['path']), self::win(self::jobFile($id, $kind, 'jpg')), self::win($jf)]);
            } else {
                self::launch(self::phpExe(), [self::win(PF_ROOT . '/deblur-cloud.php'), $id, self::win(PF_DATA)]);
            }
        } catch (Throwable $e) {
            self::writeJson($jf, ['state' => 'error', 'error' => $e->getMessage(), 'updated' => time()]);
            throw $e;
        }
    }

    public static function jobStatus(string $id, string $kind): array {
        $j = json_decode((string)@file_get_contents(self::jobFile($id, $kind, 'json')), true);
        if (!is_array($j)) return ['state' => 'none'];
        if (($j['state'] ?? '') === 'running' && ($j['updated'] ?? 0) < time() - 180) {
            $j['state'] = 'error';
            $j['error'] = 'It stopped without finishing. Try again.';
        }
        if (($j['state'] ?? '') === 'done' && !is_file(self::jobFile($id, $kind, 'jpg'))) $j = ['state' => 'none'];
        return $j;
    }

    /** Everything the Fix blur panel shows for one photo. */
    public static function status(string $id): array {
        $m = self::cloudModel();
        return [
            'ready' => self::isReady(),
            'setup' => self::setupStatus(),
            'download_mb' => (int)round(self::packBytes() / 1048576),
            'cloud_key' => self::hasCloudKey(),
            'cloud_model' => self::GEMINI_MODELS[$m]['label'],
            'cloud_cost' => self::GEMINI_MODELS[$m]['cost'],
            'local' => self::jobStatus($id, 'local'),
            'cloud' => self::jobStatus($id, 'cloud'),
        ];
    }

    public static function discard(string $id): void {
        foreach (self::KINDS as $k) {
            if ((self::jobStatus($id, $k)['state'] ?? '') === 'running') continue;
            @unlink(self::jobFile($id, $k, 'jpg'));
            @unlink(self::jobFile($id, $k, 'json'));
        }
    }

    // ---- Cloud: Gemini image editing ----

    /** Runs in deblur-cloud.php. */
    public static function runCloud(string $id): void {
        $jf = self::jobFile($id, 'cloud', 'json');
        $state = ['state' => 'running', 'started' => time(), 'updated' => time()];
        try {
            $row = Db::getById($id);
            if (!$row || !is_file((string)$row['path'])) throw new RuntimeException('The photo is no longer on disk.');
            $s = pf_load_settings();
            $key = trim((string)($s['gemini_api_key'] ?? ''));
            if ($key === '') throw new RuntimeException('No Google Gemini API key is set.');
            $model = self::cloudModel();
            [$jpeg, $w, $h] = self::cloudInput((string)$row['path']);
            $body = [
                'model' => $model,
                'input' => [
                    ['type' => 'text', 'text' => self::PROMPT],
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
            $out = self::jobFile($id, 'cloud', 'jpg');
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

    /** The photo as a JPEG of at most CLOUD_MAX_SIDE px, in its stored orientation. */
    private static function cloudInput(string $path): array {
        $info = @getimagesize($path);
        if (!$info) throw new RuntimeException('This photo cannot be read.');
        [$w, $h] = $info;
        if ($info[2] === IMAGETYPE_JPEG && max($w, $h) <= self::CLOUD_MAX_SIDE && filesize($path) < 12_000_000) {
            return [(string)file_get_contents($path), $w, $h];
        }
        $src = @imagecreatefromstring((string)file_get_contents($path));
        if (!$src) throw new RuntimeException('This photo cannot be read.');
        $im = Enhance::fit($src, self::CLOUD_MAX_SIDE);
        ob_start();
        imagejpeg($im, null, 92);
        return [(string)ob_get_clean(), imagesx($im), imagesy($im)];
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

    /** "<name> (deblurred).jpg" / "<name> (restored).jpg" beside the original, never an existing file. */
    public static function copyPath(string $path, string $kind): string {
        $suffix = self::SUFFIX[$kind];
        $dir = pf_norm_path(dirname($path));
        $stem = (string)preg_replace('/ \((?:deblurred|restored)(?: \d+)?\)$/i', '', pathinfo($path, PATHINFO_FILENAME));
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
        $res = self::jobFile($id, $kind, 'jpg');
        $job = self::jobStatus($id, $kind);
        if (($job['state'] ?? '') !== 'done' || !is_file($res)) throw new RuntimeException('There is no result to save yet.');
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
        $args = ['-n', '-TagsFromFile', pf_norm_path($path), '-all:all', '-unsafe', '-ICC_Profile', '-IPTC:CodedCharacterSet',
                 '--XMP-hdrgm:all', '--XMP-GContainer:all', '--XMP-GCamera:all', '--ThumbnailImage', '--PreviewImage',
                 '-ExifIFD:ExifImageWidth=' . $w, '-ExifIFD:ExifImageHeight=' . $h,
                 '-m', '-overwrite_original', pf_norm_path($tmp)];
        file_put_contents($argFile, implode("\n", $args) . "\n");
        [$exit, $stdout, $stderr] = pf_run_cmd([$tool, '-charset', 'filename=UTF8', '-@', $argFile]);
        @unlink($argFile);
        if (!preg_match('/\b1 image files? (updated|unchanged)/i', (string)$stdout)) {
            @unlink($tmp);
            throw new RuntimeException('Could not copy the photo\'s metadata: ' . trim((string)($stderr ?: $stdout)));
        }
        if ($mode === 'replace') {
            $rec = pf_recycle_files([$path])[$path] ?? ['ok' => false, 'error' => 'no response'];
            if (!$rec['ok']) {
                @unlink($tmp);
                throw new RuntimeException('Could not move the original to the Recycle Bin (' . $rec['error'] . '). Nothing was changed.');
            }
            if (!@rename($tmp, $path) && !(@copy($tmp, $path) && @unlink($tmp))) {
                throw new RuntimeException('The original is in the Recycle Bin, but the fixed photo could not be put in its place. It is at ' . $tmp);
            }
            $newId = (string)$row['id'];
            $dest = $path;
            clearstatcache(true, $path);
            Db::upsertPending($newId, $path, (int)filesize($path), (int)filemtime($path));
            // New pixels: its size, and Clean up's measurements, change.
            Db::get()->prepare('UPDATE images SET width = :w, height = :h, analyzed_at = NULL WHERE id = :id')
                ->execute([':w' => $w, ':h' => $h, ':id' => $newId]);
            @unlink(PF_THUMBS . '/' . $newId . '.jpg');
        } else {
            $dest = self::copyPath($path, $kind);
            if (!@rename($tmp, $dest)) {
                if (!@copy($tmp, $dest)) { @unlink($tmp); throw new RuntimeException('Could not write ' . $dest); }
                @unlink($tmp);
            }
            $newId = Enhance::indexCopy($row, $dest);
        }
        Thumb::ensureThumb($newId, $dest);
        if ($mode === 'replace') {
            // Every result was made from the old original: clear them all.
            self::discard($id);
        } else {
            // Keep the result on view (to compare with the other one, or save that too), marked saved.
            $j = self::jobStatus($id, $kind);
            $j['saved'] = basename($dest);
            self::writeJson(self::jobFile($id, $kind, 'json'), $j);
        }
        $r = Db::getById($newId) ?? [];
        $r['sort_date'] = ($r['taken_at'] ?? '') !== '' ? $r['taken_at'] : (($r['folder_year'] ?? '') !== '' ? $r['folder_year'] : null);
        $r['v'] = (int)($r['mtime'] ?? time());
        return ['ok' => true, 'mode' => $mode, 'name' => basename($dest), 'image' => $r, 'stats' => Db::stats()];
    }

    // ---- Helpers ----

    private static function writeJson(string $file, array $data): void {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents($file . '.tmp', json_encode($data));
        @rename($file . '.tmp', $file);
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
