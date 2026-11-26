<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Indexer.php';

/**
 * "Find photos on this PC" and gather them into the photo library.
 *
 * SEARCH. Walks the user's folders (Desktop, Downloads, Documents, Pictures, Videos, the rest of
 * the user folder, every OneDrive) and optionally the other fixed drives, in short batches the
 * page drives (searchStart / searchTick / searchCancel). A Windows PowerShell helper lists each
 * folder with the names, attributes, sizes and times Windows already keeps, so OneDrive files
 * that are only in the cloud are recognised by their attributes and never opened (opening one
 * would download it). For local pictures only the image header is read (EXIF + dimensions);
 * file contents are never read while searching.
 *
 * CONSOLIDATE. Moves the ticked files into <target>\From <origin>\<original sub-folders>\<name>,
 * one at a time in page-driven batches (moveStart / moveTick / moveCancel / moveFinish):
 *   - same volume, not cloud-only: rename() (atomic);
 *   - otherwise: copy, flush, verify size + SHA-256, then send the original to the Recycle Bin
 *     with pf_recycle_files(). Nothing is ever unlinked except Photo Finder's own temporary copy.
 * Exact duplicates (same size + same SHA-256) already in the library are skipped. Every move is
 * written to the consolidate_moves journal, and a whole run can be undone (undoTick).
 *
 * Tests point the search at a synthetic profile with PF_CONSOLIDATE_PROFILE (see environment()).
 */
final class Consolidate {
    // =============================================================================================
    // RULES: everything that decides what is searched, how a picture is classified and what
    // starts ticked is in this block, so it can be read (and tested) in one place.
    // =============================================================================================

    /** Picture types searched for. RAW and HEIC files are moved like any photo; Photo Finder
     *  itself indexes only the PF_IMAGE_EXTS types (lib/util.php). */
    public const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'heif'];
    public const RAW_EXTS = ['cr2', 'cr3', 'nef', 'arw', 'dng', 'orf', 'rw2'];

    /** Windows file attributes. A file with any CLOUD_MASK bit is an OneDrive (or other cloud)
     *  placeholder whose data is not on this PC: reading it would download it. */
    public const ATTR_HIDDEN = 0x2;
    public const ATTR_SYSTEM = 0x4;
    public const ATTR_DIRECTORY = 0x10;
    public const ATTR_REPARSE = 0x400;
    public const CLOUD_MASK = 0x400000 /* RECALL_ON_DATA_ACCESS */ | 0x1000 /* OFFLINE */ | 0x40000 /* RECALL_ON_OPEN */;

    /** Folder names never searched, wherever they are (lower case). */
    public const SKIP_ANYWHERE = [
        'appdata' => 'program data (AppData)',
        'application data' => 'program data (AppData)',
        'local settings' => 'program data (AppData)',
        'program files' => 'installed programs',
        'program files (x86)' => 'installed programs',
        'programdata' => 'program data',
        'windows' => 'Windows system files',
        '$recycle.bin' => 'Recycle Bin',
        'system volume information' => 'Windows system files',
        'xampp' => 'development server (xampp)',
        'node_modules' => 'code packages',
        'vendor' => 'code packages',
        'bower_components' => 'code packages',
        'site-packages' => 'code packages',
        '__pycache__' => 'code cache',
        'venv' => 'code packages',
        'saved games' => 'game data',
        'my games' => 'game data',
        'steam' => 'game library',
        'steamapps' => 'game library',
        'steamlibrary' => 'game library',
        'epic games' => 'game library',
        'gog galaxy' => 'game library',
        'gog games' => 'game library',
        'origin games' => 'game library',
        'ea games' => 'game library',
        'ubisoft game launcher' => 'game library',
        'riot games' => 'game library',
        'battle.net' => 'game library',
        'xboxgames' => 'game library',
        'cache' => 'app or browser cache',
        'caches' => 'app or browser cache',
        'code cache' => 'app or browser cache',
        'gpucache' => 'app or browser cache',
        'user data' => 'browser profile',
        'temporary internet files' => 'browser cache',
        'inetcache' => 'browser cache',
        'icloudphotos' => 'synced by iCloud Photos',
        'icloud photos' => 'synced by iCloud Photos',
        'icloud drive' => 'synced by iCloud Drive',
        'iclouddrive' => 'synced by iCloud Drive',
        'google photos' => 'Google Photos backup folder',
        'snagit' => 'Snagit capture library',
        'camtasia' => 'Camtasia projects',
        'techsmith' => 'TechSmith app data',
        'onenote notebooks' => 'OneNote notebooks',
        'outlook files' => 'Outlook data files',
        'custom office templates' => 'Office templates',
        'zoom' => 'Zoom recordings',
        'windowspowershell' => 'PowerShell settings',
        'iisexpress' => 'development server',
        'my web sites' => 'development server',
        'itunes' => 'iTunes library',
        'ipod photo cache' => 'iTunes photo cache',
        'virtualbox vms' => 'virtual machines',
        'onedrivetemp' => 'OneDrive temporary files',
    ];

    /** Names skipped only directly inside a searched place (the top of Desktop, a OneDrive, the
     *  user folder, a drive). */
    public const SKIP_AT_TOP = [
        'music' => 'music library (album art)',
        'contacts' => 'Windows contacts',
        'favorites' => 'browser favorites',
        'links' => 'Explorer links',
        'searches' => 'saved searches',
        '3d objects' => '3D objects',
        'source' => 'code projects',
        'repos' => 'code projects',
        'tracing' => 'diagnostic traces',
        'temp' => 'temporary files',
        'tmp' => 'temporary files',
        'dropbox' => 'synced by Dropbox: moving a file out removes it from Dropbox',
        'google drive' => 'synced by Google Drive: moving a file out removes it from Google Drive',
        'my drive' => 'synced by Google Drive: moving a file out removes it from Google Drive',
        'box' => 'synced by Box: moving a file out removes it from Box',
        'box sync' => 'synced by Box: moving a file out removes it from Box',
        'mega' => 'synced by MEGA',
        'megasync' => 'synced by MEGA',
        'pcloud drive' => 'synced by pCloud',
        'nextcloud' => 'synced by Nextcloud',
        'owncloud' => 'synced by ownCloud',
        'sync' => 'synced by Sync.com',
        'tresorit' => 'synced by Tresorit',
        'crossdevice' => 'phone sync folder (Phone Link)',
    ];

    /** OneDrive's own folders at the top of a OneDrive: apps and people link to the files in them. */
    public const SKIP_AT_ONEDRIVE_TOP = [
        'apps' => 'OneDrive app folders (apps keep their data here)',
        'attachments' => 'attachments shared from email (links in emails point here)',
        'email attachments' => 'attachments shared from email (links in emails point here)',
        'microsoft teams chat files' => 'files shared in Teams chats (links in chats point here)',
        'microsoft copilot chat files' => 'files shared with Copilot',
        'recordings' => 'Teams meeting recordings',
        'whiteboards' => 'Microsoft Whiteboard files',
        'personal vault' => 'OneDrive Personal Vault',
    ];

    /** Names skipped directly inside a drive root ("other drives" option). Your own user folder
     *  is searched as a place of its own; other people's profiles are never searched. */
    public const SKIP_AT_DRIVE_TOP = [
        'users' => 'user profiles (yours is searched separately; other people\'s never are)',
        'documents and settings' => 'user profiles',
        'perflogs' => 'Windows system files',
        'recovery' => 'Windows system files',
        'config.msi' => 'Windows system files',
        'msocache' => 'Office installer cache',
        'intel' => 'driver files',
        'amd' => 'driver files',
        'nvidia' => 'driver files',
        'drivers' => 'driver files',
        'dell' => 'manufacturer files',
        'hp' => 'manufacturer files',
        'lenovo' => 'manufacturer files',
        'swsetup' => 'manufacturer files',
        'inetpub' => 'web server',
        'wamp' => 'development server',
        'wamp64' => 'development server',
    ];

    public const SKIP_PREFIXES = [
        'creative cloud files' => 'synced by Adobe Creative Cloud',
        'visual studio' => 'Visual Studio projects',
        'sql server management studio' => 'SQL Server projects',
    ];

    public const SKIP_SUFFIXES = [
        '.lrdata' => 'Lightroom previews',
        '.cocatalog' => 'Capture One catalog',
        '.photoslibrary' => 'Apple Photos library',
        '.aplibrary' => 'Aperture library',
    ];

    /** A folder (below the top of a searched place) that contains one of these is a code
     *  project, a program, a website or a photo app's own folder: the whole folder is skipped. */
    public const MARKER_NAMES = [
        '.git' => 'code project (.git)',
        '.svn' => 'code project (.svn)',
        '.hg' => 'code project (.hg)',
        '.gitignore' => 'code project (.gitignore)',
        'package.json' => 'code project (package.json)',
        'composer.json' => 'code project (composer.json)',
        'pom.xml' => 'code project (pom.xml)',
        'build.gradle' => 'code project (build.gradle)',
        'cargo.toml' => 'code project (Cargo.toml)',
        'go.mod' => 'code project (go.mod)',
        'pyproject.toml' => 'code project (pyproject.toml)',
        'requirements.txt' => 'code project (requirements.txt)',
        'setup.py' => 'code project (setup.py)',
        'gemfile' => 'code project (Gemfile)',
        'cmakelists.txt' => 'code project (CMakeLists.txt)',
        'makefile' => 'code project (Makefile)',
        'dockerfile' => 'code project (Dockerfile)',
        'docker-compose.yml' => 'code project (docker-compose.yml)',
        '.vscode' => 'code project (.vscode)',
        '.idea' => 'code project (.idea)',
        '.project' => 'code project (Eclipse)',
        'index.html' => 'website (index.html)',
        'index.htm' => 'website (index.htm)',
        'index.php' => 'website (index.php)',
        'default.htm' => 'website (default.htm)',
        'default.aspx' => 'website (default.aspx)',
        'web.config' => 'website (web.config)',
        '.htaccess' => 'website (.htaccess)',
        'wp-config.php' => 'website (WordPress)',
        '.nomedia' => 'marked as not a photo folder (.nomedia)',
    ];

    public const MARKER_EXTS = [
        'sln' => 'code project (*.sln)',
        'csproj' => 'code project (*.csproj)',
        'vbproj' => 'code project (*.vbproj)',
        'vcxproj' => 'code project (*.vcxproj)',
        'fsproj' => 'code project (*.fsproj)',
        'xcodeproj' => 'code project (*.xcodeproj)',
        'uproject' => 'code project (*.uproject)',
        'exe' => 'program folder (contains programs)',
        'dll' => 'program folder (contains programs)',
        'msi' => 'program folder (contains installers)',
        'sys' => 'program folder (contains drivers)',
        'lrcat' => 'Lightroom catalog folder',
        'cosessiondb' => 'Capture One session',
        // Source code: a downloaded add-on, theme or website keeps its images next to its code.
        'php' => 'code or website files (*.php)', 'js' => 'code or website files (*.js)', 'mjs' => 'code files (*.mjs)',
        'ts' => 'code files (*.ts)', 'jsx' => 'code files (*.jsx)', 'tsx' => 'code files (*.tsx)', 'vue' => 'code files (*.vue)',
        'css' => 'website files (*.css)', 'scss' => 'website files (*.scss)', 'py' => 'code files (*.py)',
        'java' => 'code files (*.java)', 'cs' => 'code files (*.cs)', 'cpp' => 'code files (*.cpp)', 'c' => 'code files (*.c)',
        'h' => 'code files (*.h)', 'rb' => 'code files (*.rb)', 'go' => 'code files (*.go)', 'rs' => 'code files (*.rs)',
        'swift' => 'code files (*.swift)', 'kt' => 'code files (*.kt)', 'jsp' => 'website files (*.jsp)',
        'asp' => 'website files (*.asp)', 'aspx' => 'website files (*.aspx)',
    ];

    /** Folders named by an ID (a GUID or a long hex hash) belong to an app, not to a person. */
    public const ID_FOLDER_RE = '/^\{?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}?$|^[0-9a-f]{24,}$/i';
    /** A file of these types with a camera make but no capture date came from a scanner or copier. */
    public const SCAN_EXTS = ['tif', 'tiff'];

    /** Classification. A picture with camera EXIF (Make/Model/DateTimeOriginal) is a photo; a
     *  screenshot by name or folder is a screenshot; a small picture without camera data is an
     *  "asset" (icons, web graphics): listed, never ticked by default. */
    public const ASSET_MAX_BYTES = 50 * 1024;   // smaller than this, no camera data => asset
    public const ASSET_MAX_SIDE = 400;          // long side under this many pixels => asset
    public const CAMERA_NAME_RE = '/^(?:IMG[_\-]?\d|DSC[NF_]?\d|_DSC\d|_MG_\d|P\d{7}$|PXL_\d|MVIMG_\d|PANO_\d|\d{8}_\d{6}|GOPR\d|GP\d{6}|DJI_\d|SAM_\d|CIMG\d|PICT\d|HPIM\d|KIF_\d|\d{3}_\d{4}$)/i';
    public const SCREENSHOT_NAME_RE = '/screen ?shot|screen[ _\-]?(?:capture|clip)|^snip(?:ping)?[ _\-]|^capture\d*$|^snag[_\-]?\d|^greenshot|^sharex|^lightshot/i';
    public const SCREENSHOT_FOLDERS = ['screenshots', 'screenshot', 'screen shots', 'screen captures', 'screen capture',
        'captures', 'sharex', 'greenshot', 'lightshot', 'snips', 'snipping tool'];
    public const ASSET_NAME_RE = '/(?:^|[ _.\-])(?:icons?|logos?|favicon|sprites?|button|btn|banner|thumbs?|thumbnail|avatar|emoji|placeholder|spacer|bullet|arrow|bg|background|badge|watermark|signature|folder|albumart\w*|cover)(?:[ _.\-\d]|$)|^image\d{3}$/i';
    /** Common screen sizes (either orientation): a PNG of exactly this size with no camera data is a screenshot. */
    public const SCREEN_SIZES = ['1280x720', '1280x800', '1280x1024', '1366x768', '1440x900', '1536x864', '1600x900',
        '1680x1050', '1920x1080', '1920x1200', '2160x1440', '2256x1504', '2560x1080', '2560x1440', '2560x1600',
        '2880x1800', '3000x2000', '3440x1440', '3840x1600', '3840x2160', '750x1334', '828x1792', '1080x1920',
        '1080x2340', '1080x2400', '1125x2436', '1170x2532', '1179x2556', '1242x2688', '1284x2778', '1290x2796',
        '1440x2960', '1440x3040', '1440x3200'];
    /** Folders whose pictures are probably a desktop slideshow (Windows stores the slideshow
     *  folder in an encoded form Photo Finder cannot read, so this goes by name). */
    public const WALLPAPER_FOLDER_RE = '/(?:^|\/)(?:wallpapers?|backgrounds?|desktop backgrounds?|slide ?shows?)(?:\/|$)/i';

    /** Groups only start ticked in these places (and their sub-folders), or in a OneDrive. */
    public const SAFE_PLACES = ['desktop', 'downloads', 'documents', 'pictures'];

    /** Why a sub-folder is not searched. null = search it; '' = skipped silently (it is
     *  searched as a place of its own). $depth is 1 for a folder directly inside a searched
     *  place. $siblings = lower-cased names of everything next to it. */
    public static function folderSkipReason(string $name, int $attrs, int $depth, string $rootKind, bool $inSyncRoot, array $siblings = []): ?string {
        $l = strtolower($name);
        if ($attrs & (self::ATTR_HIDDEN | self::ATTR_SYSTEM)) return 'hidden or system folder';
        // A reparse point outside a cloud sync root is a junction or a link to another folder.
        // (Inside OneDrive every folder carries the reparse attribute.)
        if (($attrs & self::ATTR_REPARSE) && !$inSyncRoot) return 'link to another folder';
        if ($l !== '' && $l[0] === '.') return 'app settings folder (name starts with a dot)';
        if ($l !== '' && $l[0] === '$') return 'system folder';
        if (isset(self::SKIP_ANYWHERE[$l])) return self::SKIP_ANYWHERE[$l];
        if (preg_match(self::ID_FOLDER_RE, $l)) return 'app data (folder named by an ID)';
        if ($depth === 1) {
            if (isset(self::SKIP_AT_TOP[$l])) return self::SKIP_AT_TOP[$l];
            if ($rootKind === 'onedrive' && isset(self::SKIP_AT_ONEDRIVE_TOP[$l])) return self::SKIP_AT_ONEDRIVE_TOP[$l];
            if ($rootKind === 'drive' && isset(self::SKIP_AT_DRIVE_TOP[$l])) return self::SKIP_AT_DRIVE_TOP[$l];
        }
        foreach (self::SKIP_PREFIXES as $p => $why) if (str_starts_with($l, $p)) return $why;
        foreach (self::SKIP_SUFFIXES as $s => $why) if (str_ends_with($l, $s)) return $why;
        // A saved web page: "Page_files" next to "Page.htm(l)".
        if (str_ends_with($l, '_files')) {
            $stem = substr($l, 0, -6);
            foreach (['.htm', '.html', '.mht', '.mhtml', '.php', '.aspx'] as $x) {
                if (isset($siblings[$stem . $x])) return 'saved web page';
            }
        }
        return null;
    }

    /** Why a folder is left alone because of what it contains (code project, program, website,
     *  photo-app folder). Only applied below the top of a searched place: one stray
     *  package.json in Downloads must not hide all of Downloads. $entries: [['name','dir'],...] */
    public static function folderMarkerReason(array $entries): ?string {
        foreach ($entries as $e) {
            $l = strtolower((string)$e['name']);
            if (isset(self::MARKER_NAMES[$l])) return self::MARKER_NAMES[$l];
            $dot = strrpos($l, '.');
            if ($dot !== false && $dot > 0) {
                $x = substr($l, $dot + 1);
                if (isset(self::MARKER_EXTS[$x])) return self::MARKER_EXTS[$x];
            }
        }
        return null;
    }

    /** Classify one picture: [kind, basis]. kind = photo | screenshot | image | asset.
     *  $relDir: its folder relative to the searched place (forward slashes).
     *  $meta: ['w','h','make','model','taken'] from its header, or null for a cloud-only file,
     *  whose header is never read (it is classified by name and size only). */
    public static function classify(string $name, string $relDir, int $size, ?array $meta): array {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $camera = trim(trim((string)($meta['make'] ?? '')) . ' ' . trim((string)($meta['model'] ?? '')));
        $taken = (string)($meta['taken'] ?? '');
        $hasDate = $taken !== '' && !str_starts_with($taken, '0000');
        $w = (int)($meta['w'] ?? 0);
        $h = (int)($meta['h'] ?? 0);
        $small = $size < self::ASSET_MAX_BYTES || ($w > 0 && max($w, $h) < self::ASSET_MAX_SIDE);
        if ($camera !== '') {
            // A TIFF with a make but no capture date comes from a scanner or copier.
            if (!$hasDate && in_array($ext, self::SCAN_EXTS, true)) return ['image', 'scan (' . self::dedupeCamera($camera) . ')'];
            return ['photo', 'camera data: ' . self::dedupeCamera($camera)];
        }
        // Only a capture date, and tiny: a thumbnail someone saved, not the photo.
        if ($hasDate && !$small) return ['photo', 'camera data (date taken)'];
        if (in_array($ext, self::RAW_EXTS, true)) return ['photo', 'camera RAW file'];
        if (preg_match(self::SCREENSHOT_NAME_RE, $stem)) return ['screenshot', 'screenshot name'];
        foreach (explode('/', strtolower($relDir)) as $seg) {
            if ($seg !== '' && in_array($seg, self::SCREENSHOT_FOLDERS, true)) return ['screenshot', 'in a screenshots folder'];
        }
        if (in_array($ext, ['heic', 'heif'], true)) return ['photo', 'phone photo (HEIC)'];
        if ($ext === 'png' && $w > 0 && (in_array("{$w}x{$h}", self::SCREEN_SIZES, true) || in_array("{$h}x{$w}", self::SCREEN_SIZES, true))) {
            return ['screenshot', 'screen-sized PNG'];
        }
        if ($ext === 'png' && $meta !== null && preg_match('/^IMG_\d+$/i', $stem)) return ['screenshot', 'phone screenshot name'];
        if ($size < self::ASSET_MAX_BYTES) return ['asset', 'small file (' . max(1, (int)round($size / 1024)) . ' KB)'];
        if ($w > 0 && max($w, $h) < self::ASSET_MAX_SIDE) return ['asset', "small picture ({$w}×{$h})"];
        if (preg_match(self::ASSET_NAME_RE, $stem)) return ['asset', 'icon or web graphic name'];
        if (preg_match(self::CAMERA_NAME_RE, $stem)) {
            return ['photo', $meta === null ? 'camera-style name (cloud-only file, not opened)' : 'camera-style name'];
        }
        return ['image', $meta === null ? 'cloud-only file (not opened)' : 'no camera data'];
    }

    private static function dedupeCamera(string $camera): string {
        // "Canon Canon EOS R6" -> "Canon EOS R6"
        $parts = preg_split('/\s+/', $camera) ?: [];
        if (count($parts) > 1 && strcasecmp($parts[0], $parts[1]) === 0) array_shift($parts);
        return implode(' ', $parts);
    }

    /** Why a group does not start ticked: [reason|null, notes[], caution]. A null reason = it
     *  starts ticked. Only groups in a safe personal place that hold mostly camera photos
     *  qualify. caution = moving them could break something (a shared library, another photo
     *  app's catalog): "Tick all" leaves those alone, only ticking them one by one works. */
    public static function groupReason(array $g, array $root, array $ctx): array {
        $dir = $g['dir'];
        $notes = [];
        foreach ($ctx['shared'] as $s) {
            if (self::inside($dir, $s)) return ['in a shared or team library: moving a file out removes it for everyone', $notes, true];
        }
        foreach ($ctx['lightroom'] as $lr => $why) {
            if (self::inside($dir, $lr)) return [$why, $notes, true];
        }
        foreach ($ctx['digikam'] as $dk) {
            if (!self::inside($dir, $dk)) continue;
            if ($ctx['target'] !== '' && self::inside($ctx['target'], $dk)) {
                $notes[] = 'digiKam album: the library is in the same album, so digiKam finds the moved photos on its next scan';
            } else {
                return ['in a digiKam album: digiKam would lose track of moved photos', $notes, true];
            }
        }
        $from = self::syncRootOf($dir, $ctx['sync_roots']);
        $to = $ctx['target'] !== '' ? self::syncRootOf($ctx['target'], $ctx['sync_roots']) : null;
        if ($from !== null && $from !== $to) {
            $notes[] = $to === null
                ? 'moving takes these out of ' . basename($from) . ': they will no longer be in that cloud'
                : 'moves these from ' . basename($from) . ' to ' . basename($to);
        }
        if ($g['cloud'] > 0) $notes[] = $g['cloud'] . ' only in the cloud: downloaded when moved';
        $rel = strtolower($g['rel']);
        if (preg_match(self::WALLPAPER_FOLDER_RE, $rel)) return ['looks like a wallpaper or slideshow folder', $notes];
        if ($root['kind'] === 'drive') return ['on another drive', $notes];
        if ($root['kind'] === 'known' && !in_array(strtolower($root['label']), self::SAFE_PLACES, true)) {
            return ['in your ' . $root['label'] . ' folder', $notes];
        }
        if ($root['kind'] === 'profile') {
            $first = explode('/', $rel)[0];
            if (!in_array($first, self::SAFE_PLACES, true)) return ['not one of your usual photo places', $notes];
        }
        if ($g['bucket'] === 'screenshots') return ['screenshots', $notes];
        $n = max(1, $g['count']);
        $photos = $g['mix']['photo'];
        if ($photos * 2 < $n) {
            if ($photos > 0) return ["only {$photos} of {$g['count']} have camera data", $notes];
            if ($g['mix']['asset'] * 2 >= $n) return ['looks like program or web images (small, no camera data)', $notes];
            return ['no camera photos (downloads, edits, scans)', $notes];
        }
        if ($g['selectable'] === 0) return ['nothing here can be moved', $notes];
        return [null, $notes];
    }

    // =============================================================================================
    // Paths
    // =============================================================================================

    /** Forward slashes, no trailing slash, except a drive root ("C:/"). */
    public static function norm(string $p): string {
        $p = rtrim(str_replace('\\', '/', trim($p)), '/');
        if (preg_match('#^[A-Za-z]:$#', $p)) $p .= '/';
        return $p;
    }

    public static function inside(string $path, string $root): bool {
        $p = strtolower(self::norm($path));
        $r = rtrim(strtolower(self::norm($root)), '/');
        if ($r === '') return false;
        return $p === $r || str_starts_with($p, $r . '/');
    }

    private static function join(string $dir, string $name): string {
        return rtrim($dir, '/') . '/' . $name;
    }

    public static function win(string $p): string {
        return str_replace('/', '\\', $p);
    }

    private static function syncRootOf(string $path, array $roots): ?string {
        $best = null;
        foreach ($roots as $r) {
            if (self::inside($path, $r) && ($best === null || strlen($r) > strlen($best))) $best = $r;
        }
        return $best;
    }

    private static function dir(): string {
        $d = PF_DATA . '/consolidate';
        if (!is_dir($d)) mkdir($d, 0777, true);
        return $d;
    }

    // =============================================================================================
    // Storage
    // =============================================================================================

    private static bool $migrated = false;

    /** The index database, with this feature's tables (each in its own CREATE TABLE IF NOT EXISTS). */
    public static function db(): PDO {
        $pdo = Db::get();
        if (self::$migrated) return $pdo;
        self::$migrated = true;
        // Pictures found by the current search (replaced by the next search).
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS consolidate_found (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                search_id TEXT NOT NULL,
                path TEXT NOT NULL,
                dir TEXT NOT NULL,
                root_idx INTEGER NOT NULL,
                size INTEGER, mtime INTEGER, ctime INTEGER, attrs INTEGER,
                cloud INTEGER NOT NULL DEFAULT 0,
                excluded TEXT,
                kind TEXT, basis TEXT, width INTEGER, height INTEGER, camera TEXT, taken TEXT
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cfound_search ON consolidate_found(search_id, kind)');
        // One row per consolidation run.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS consolidate_runs (
                run_id TEXT PRIMARY KEY,
                search_id TEXT,
                target TEXT NOT NULL,
                state TEXT NOT NULL,
                started_at INTEGER, finished_at INTEGER, undone_at INTEGER,
                meta TEXT
            )
        SQL);
        // The journal: every file a run moved (or skipped, or failed on), with how and its hash.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS consolidate_moves (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id TEXT NOT NULL,
                found_id INTEGER,
                old_path TEXT NOT NULL,
                new_path TEXT,
                origin TEXT, rel TEXT,
                method TEXT,
                sha256 TEXT,
                size INTEGER, mtime INTEGER, ctime INTEGER,
                cloud INTEGER NOT NULL DEFAULT 0,
                recycled_as TEXT,
                status TEXT NOT NULL DEFAULT 'queued',
                note TEXT,
                moved_at INTEGER, undone_at INTEGER
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cmoves_run ON consolidate_moves(run_id, status)');
        // Sizes (and hashes, once computed) of the library's files while a run is going, for the
        // duplicate check. Emptied when the run finishes.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS consolidate_sizes (
                run_id TEXT NOT NULL,
                size INTEGER NOT NULL,
                path TEXT NOT NULL,
                sha256 TEXT
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_csizes_run ON consolidate_sizes(run_id, size)');
        return $pdo;
    }

    /** One search or move step at a time (a double click must not run two batches at once). */
    private static function locked(callable $fn): mixed {
        $fh = fopen(self::dir() . '/.lock', 'c');
        if ($fh === false) return $fn();
        flock($fh, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function jobFile(): string {
        return self::dir() . '/search.json';
    }

    private static function loadJob(): ?array {
        $f = self::jobFile();
        if (!is_file($f)) return null;
        $j = json_decode((string)file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }

    private static function saveJob(array $job): void {
        $tmp = self::jobFile() . '.tmp';
        file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        rename($tmp, self::jobFile());
    }

    private static function requireJob(string $id): array {
        $job = self::loadJob();
        if (!$job || $job['id'] !== $id) throw new RuntimeException('That search is no longer available. Search again.');
        return $job;
    }

    // =============================================================================================
    // Environment: the places searched
    // =============================================================================================

    /** Test override: a synthetic user profile. Production never sets it. */
    private static function testProfile(): ?string {
        $p = getenv('PF_CONSOLIDATE_PROFILE');
        return is_string($p) && trim($p) !== '' ? self::norm($p) : null;
    }

    /** This user's folders, OneDrive roots, cloud sync roots, shared libraries, protected
     *  pictures (wallpaper, lock screen, account picture) and fixed drives, read from Windows. */
    public static function environment(): array {
        $out = self::dir() . '/env-' . bin2hex(random_bytes(4)) . '.json';
        $script = str_replace('__OUT__', self::psq($out), <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
$ProgressPreference = 'SilentlyContinue'
$o = [ordered]@{}
$o.sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$o.profile = [Environment]::GetFolderPath('UserProfile')
$o.desktop = [Environment]::GetFolderPath('Desktop')
$o.documents = [Environment]::GetFolderPath('MyDocuments')
$o.pictures = [Environment]::GetFolderPath('MyPictures')
$o.videos = [Environment]::GetFolderPath('MyVideos')
$o.music = [Environment]::GetFolderPath('MyMusic')
$sf = Get-ItemProperty 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\Shell Folders'
$o.downloads = [string]$sf.'{374DE290-123F-4565-9164-39C4925E467B}'
$od = New-Object System.Collections.ArrayList
foreach ($v in @($env:OneDrive, $env:OneDriveCommercial, $env:OneDriveConsumer)) { if ($v) { [void]$od.Add([string]$v) } }
$acc = @(Get-ChildItem 'HKCU:\Software\Microsoft\OneDrive\Accounts')
foreach ($k in $acc) { $f = (Get-ItemProperty $k.PSPath).UserFolder; if ($f) { [void]$od.Add([string]$f) } }
foreach ($d in @(Get-ChildItem -LiteralPath $o.profile -Directory -Filter 'OneDrive*' -Force)) { [void]$od.Add($d.FullName) }
$o.onedrive = @($od)
$mp = New-Object System.Collections.ArrayList
foreach ($k in $acc) {
  foreach ($t in @(Get-ChildItem (Join-Path $k.PSPath 'Tenants'))) { foreach ($p in (Get-ItemProperty $t.PSPath).PSObject.Properties) { if ($p.Name -match '^[A-Za-z]:\\') { [void]$mp.Add([string]$p.Name) } } }
  $c = Get-ItemProperty (Join-Path $k.PSPath 'ScopeIdToMountPointPathCache')
  if ($c) { foreach ($p in $c.PSObject.Properties) { if ([string]$p.Value -match '^[A-Za-z]:\\') { [void]$mp.Add([string]$p.Value) } } }
}
$o.onedrive_mounts = @($mp)
$sr = New-Object System.Collections.ArrayList
foreach ($k in @(Get-ChildItem 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\SyncRootManager')) {
  $u = Get-ItemProperty (Join-Path $k.PSPath 'UserSyncRoots')
  if ($u) { $p = $u.PSObject.Properties[$o.sid]; if ($p) { [void]$sr.Add([string]$p.Value) } }
}
$o.sync_roots = @($sr)
$pr = New-Object System.Collections.ArrayList
[void]$pr.Add([string](Get-ItemProperty 'HKCU:\Control Panel\Desktop').Wallpaper)
$w = Get-ItemProperty 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\Wallpapers'
if ($w) { foreach ($p in $w.PSObject.Properties) { if ($p.Name -like 'BackgroundHistoryPath*') { [void]$pr.Add([string]$p.Value) } } }
foreach ($td in @((Join-Path $env:LOCALAPPDATA 'Microsoft\Windows\Themes'), (Join-Path $env:APPDATA 'Microsoft\Windows\Themes'))) {
  foreach ($t in @(Get-ChildItem -LiteralPath $td -Filter '*.theme')) {
    foreach ($l in @(Get-Content -LiteralPath $t.FullName)) { if ($l -match '^\s*Wallpaper\s*=\s*(.+?)\s*$') { [void]$pr.Add([Environment]::ExpandEnvironmentVariables($Matches[1])) } }
  }
}
$csp = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\PersonalizationCSP'
if ($csp) { foreach ($n in @('LockScreenImagePath', 'DesktopImagePath')) { if ($csp.$n) { [void]$pr.Add([string]$csp.$n) } } }
$ap = Get-ItemProperty ('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\AccountPicture\Users\' + $o.sid)
if ($ap) { foreach ($p in $ap.PSObject.Properties) { if ($p.Name -like 'Image*') { [void]$pr.Add([string]$p.Value) } } }
$o.protected = @($pr | Where-Object { $_ })
$o.fixed_drives = @([IO.DriveInfo]::GetDrives() | Where-Object { $_.DriveType -eq 'Fixed' -and $_.IsReady } | ForEach-Object { $_.Name })
$o.system_drive = [string]$env:SystemDrive
[IO.File]::WriteAllText(__OUT__, (ConvertTo-Json $o -Depth 4 -Compress), (New-Object Text.UTF8Encoding $false))
PS);
        self::powershell($script);
        $raw = is_file($out) ? (string)file_get_contents($out) : '';
        @unlink($out);
        $e = json_decode($raw, true);
        if (!is_array($e)) throw new RuntimeException('Could not read your folder locations from Windows.');
        $list = fn($v) => array_values(array_filter(array_map(fn($x) => self::norm((string)$x), is_array($v) ? $v : ($v === null ? [] : [$v])), fn($x) => $x !== ''));
        $env = [
            'sid' => (string)($e['sid'] ?? ''),
            'profile' => self::norm((string)($e['profile'] ?? '')),
            'desktop' => self::norm((string)($e['desktop'] ?? '')),
            'downloads' => self::norm((string)($e['downloads'] ?? '')),
            'documents' => self::norm((string)($e['documents'] ?? '')),
            'pictures' => self::norm((string)($e['pictures'] ?? '')),
            'videos' => self::norm((string)($e['videos'] ?? '')),
            'music' => self::norm((string)($e['music'] ?? '')),
            'onedrive' => $list($e['onedrive'] ?? []),
            'onedrive_mounts' => $list($e['onedrive_mounts'] ?? []),
            'sync_roots' => $list($e['sync_roots'] ?? []),
            'protected' => $list($e['protected'] ?? []),
            'protected_dirs' => [],
            'drives' => $list($e['fixed_drives'] ?? []),
            'system_drive' => self::norm((string)($e['system_drive'] ?? 'C:')),
            'test' => false,
        ];
        $test = self::testProfile();
        if ($test !== null) {
            // A synthetic profile: its Desktop, Downloads, ... and OneDrive* folders stand in for
            // the real ones. Protected pictures, shared libraries and "drives" come from env.
            $envList = fn(string $k) => array_values(array_filter(array_map([self::class, 'norm'], explode(';', (string)getenv($k)))));
            $env['profile'] = $test;
            foreach (['desktop' => 'Desktop', 'downloads' => 'Downloads', 'documents' => 'Documents',
                      'pictures' => 'Pictures', 'videos' => 'Videos', 'music' => 'Music'] as $k => $n) {
                $env[$k] = $test . '/' . $n;
            }
            $env['onedrive'] = array_map(fn($d) => self::norm($d), glob($test . '/OneDrive*', GLOB_ONLYDIR) ?: []);
            $env['sync_roots'] = $env['onedrive'];
            $env['onedrive_mounts'] = array_merge($env['onedrive'], $envList('PF_CONSOLIDATE_SHARED'));
            $env['protected'] = $envList('PF_CONSOLIDATE_PROTECT');
            $env['drives'] = $envList('PF_CONSOLIDATE_DRIVES');
            $env['test'] = true;
        }
        // Unique, existing OneDrive roots.
        $seen = [];
        $env['onedrive'] = array_values(array_filter($env['onedrive'], function ($d) use (&$seen) {
            $k = strtolower($d);
            if (isset($seen[$k]) || !is_dir($d)) return false;
            return $seen[$k] = true;
        }));
        $env['sync_roots'] = array_values(array_unique(array_merge($env['sync_roots'], $env['onedrive'])));
        return $env;
    }

    /** The searched places, in walk order: [path, label, kind] with kind known|onedrive|profile|drive. */
    private static function roots(array $env, bool $drives): array {
        $roots = [];
        $add = function (string $path, string $label, string $kind) use (&$roots): void {
            $p = self::norm($path);
            if ($p === '' || !is_dir($p)) return;
            foreach ($roots as $r) if (strcasecmp($r['path'], $p) === 0) return;
            $roots[] = ['path' => $p, 'label' => $label, 'kind' => $kind];
        };
        $add($env['desktop'], 'Desktop', 'known');
        $add($env['downloads'], 'Downloads', 'known');
        $add($env['documents'], 'Documents', 'known');
        $add($env['pictures'], 'Pictures', 'known');
        $add($env['videos'], 'Videos', 'known');
        foreach ($env['onedrive'] as $od) $add($od, basename($od), 'onedrive');
        $add($env['profile'], 'User folder', 'profile');
        if ($drives) {
            foreach ($env['drives'] as $d) {
                $add($d, strtoupper(substr($d, 0, 1)) . '-drive', 'drive');
            }
        }
        return $roots;
    }

    private static function defaultTarget(array $settings, array $env): string {
        $t = trim((string)($settings['consolidate_target'] ?? ''));
        if ($t === '') $t = (string)(($settings['photo_dirs'] ?? [])[0] ?? '');
        if ($t === '') $t = $env['pictures'] ?? '';
        return self::norm($t);
    }

    // =============================================================================================
    // Search
    // =============================================================================================

    /** Dialog state: the last search, recent runs, the default target. Read-only, and quick:
     *  it does not ask Windows for the folder locations (starting the helper takes seconds on
     *  some PCs), so the drive list shown is the one the last search found. */
    public static function status(): array {
        $s = pf_load_settings();
        $job = self::loadJob();
        $profile = self::testProfile() ?? self::norm((string)getenv('USERPROFILE'));
        return [
            'search' => $job ? self::progress($job) : null,
            'runs' => self::runs(8),
            'target' => self::win(self::defaultTarget($s, ['pictures' => $profile !== '' ? $profile . '/Pictures' : ''])),
            'drives' => array_map([self::class, 'win'], $job['fixed_drives'] ?? []),
            'test_mode' => self::testProfile() !== null,
        ];
    }

    public static function searchStart(bool $drives): array {
        return self::locked(function () use ($drives): array {
            $env = self::environment();
            $s = pf_load_settings();
            $target = self::defaultTarget($s, $env);
            $photoDirs = array_values(array_filter(array_map([self::class, 'norm'], $s['photo_dirs'] ?? [])));
            $roots = self::roots($env, $drives);

            $skipPaths = [];
            foreach ($photoDirs as $d) $skipPaths[strtolower($d)] = 'already in your photo library';
            if ($target !== '') $skipPaths[strtolower($target)] = 'the folder photos are gathered into';
            $skipPaths[strtolower(self::norm(realpath(PF_DATA) ?: PF_DATA))] = "Photo Finder's own data";
            if ($env['music'] !== '') $skipPaths[strtolower($env['music'])] = 'music library (album art)';

            $accounts = array_map('strtolower', $env['onedrive']);
            $shared = array_values(array_filter($env['onedrive_mounts'], fn($m) => !in_array(strtolower($m), $accounts, true)));

            $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $job = [
                'id' => $id, 'status' => 'running', 'started_at' => time(), 'finished_at' => null,
                'busy' => 0.0, 'ticks' => 0, 'drives' => $drives,
                'roots' => $roots, 'target' => $target, 'photo_dirs' => $photoDirs,
                'skip_paths' => $skipPaths,
                'root_paths' => array_map(fn($r) => strtolower($r['path']), $roots),
                'sync_roots' => $env['sync_roots'], 'shared' => $shared,
                'protected' => array_fill_keys(array_map('strtolower', $env['protected']), true),
                'queue' => [], 'dirs_done' => 0, 'images' => 0, 'hidden' => 0, 'errors' => 0,
                'skipped' => [], 'skipped_count' => 0, 'skip_reasons' => [],
                'lightroom' => [], 'lightroom_roots' => [], 'digikam' => [], 'current' => '',
                'test' => $env['test'], 'fixed_drives' => $env['drives'],
            ];
            foreach ($roots as $i => $r) {
                $why = self::pathSkipReason($job, $r['path'], true);
                if ($why !== null) { self::noteSkip($job, $r['path'], $why); continue; }
                $job['queue'][] = [$r['path'], $i, 0];
            }
            // A new search replaces the previous one's results and preview cache.
            self::db()->prepare('DELETE FROM consolidate_found WHERE search_id != ?')->execute([$id]);
            foreach (glob(self::dir() . '/thumbs/*.jpg') ?: [] as $t) @unlink($t);
            self::saveJob($job);
            return self::progress($job);
        });
    }

    public static function searchTick(string $id): array {
        return self::locked(function () use ($id): array {
            $job = self::requireJob($id);
            if ($job['status'] !== 'running') return self::progress($job);
            $t0 = microtime(true);
            self::classifyPending($job, $t0 + 0.8);
            if ($job['queue']) {
                $batch = array_slice($job['queue'], 0, 400);
                [$done, $listings] = self::listDirs(array_column($batch, 0), 1500);
                $job['queue'] = array_slice($job['queue'], $done);
                $pdo = self::db();
                $pdo->beginTransaction();
                try {
                    foreach ($listings as $k => $l) self::absorbListing($job, $batch[$k], $l);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            self::classifyPending($job, $t0 + 3.2);
            if (!$job['queue'] && self::unclassified($job['id']) === 0) self::finalize($job, 'done');
            $job['busy'] += microtime(true) - $t0;
            $job['ticks']++;
            self::saveJob($job);
            return self::progress($job);
        });
    }

    public static function searchCancel(string $id): array {
        return self::locked(function () use ($id): array {
            $job = self::requireJob($id);
            if ($job['status'] === 'running') {
                $t0 = microtime(true);
                self::classifyPending($job, $t0 + 2.0);
                self::finalize($job, 'cancelled');
                $job['busy'] += microtime(true) - $t0;
                self::saveJob($job);
            }
            return self::progress($job);
        });
    }

    private static function progress(array $job): array {
        $end = $job['finished_at'] ?? time();
        return [
            'id' => $job['id'],
            'status' => $job['status'],
            'dirs_done' => $job['dirs_done'],
            'dirs_queued' => count($job['queue']),
            'images' => $job['images'],
            'unclassified' => $job['status'] === 'running' ? self::unclassified($job['id']) : 0,
            'skipped' => $job['skipped_count'],
            'unsearched' => (int)($job['unsearched'] ?? 0),
            'current' => self::win((string)$job['current']),
            'elapsed' => max(0, (int)$end - (int)$job['started_at']),
            'busy' => round((float)$job['busy'], 1),
            'started_at' => $job['started_at'],
            'drives' => $job['drives'],
            'test' => $job['test'] ?? false,
        ];
    }

    private static function unclassified(string $id): int {
        $st = self::db()->prepare('SELECT COUNT(*) FROM consolidate_found WHERE search_id = ? AND kind IS NULL');
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    }

    private static function noteSkip(array &$job, string $path, string $why): void {
        $job['skipped_count']++;
        $job['skip_reasons'][$why] = ($job['skip_reasons'][$why] ?? 0) + 1;
        if (count($job['skipped']) < 3000) $job['skipped'][] = [$path, $why];
    }

    /** A path that is (inside) the library, the target, Photo Finder's data or the music folder. */
    private static function pathSkipReason(array $job, string $path, bool $prefix): ?string {
        $l = strtolower($path);
        if (!$prefix) return $job['skip_paths'][$l] ?? null;
        foreach ($job['skip_paths'] as $p => $why) if (self::inside($l, $p)) return $why;
        return null;
    }

    /** Take in one listed folder: queue its sub-folders, record its pictures. */
    private static function absorbListing(array &$job, array $item, array $listing): void {
        [$dir, $ri, $depth] = $item;
        $job['dirs_done']++;
        $job['current'] = $dir;
        $status = $listing['status'];
        if ($status !== 'ok') {
            $job['errors']++;
            self::noteSkip($job, $dir, $status === 'denied' ? 'no permission to open it'
                : ($status === 'missing' ? 'folder disappeared during the search' : 'could not open it (' . $status . ')'));
            return;
        }
        $entries = $listing['entries'];
        $root = $job['roots'][$ri];
        $names = [];
        foreach ($entries as $e) {
            $l = strtolower($e['name']);
            $names[$l] = true;
            if (!$e['dir'] && str_ends_with($l, '.lrcat')) $job['lightroom'][] = self::join($dir, $e['name']);
            if (!$e['dir'] && $l === 'digikam4.db' && !in_array($dir, $job['digikam'], true)) $job['digikam'][] = $dir;
        }
        if ($depth >= 1) {
            $why = self::folderMarkerReason($entries);
            if ($why !== null) { self::noteSkip($job, $dir, $why); return; }
        }
        $ins = self::db()->prepare(
            'INSERT INTO consolidate_found (search_id, path, dir, root_idx, size, mtime, ctime, attrs, cloud, excluded)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($entries as $e) {
            $path = self::join($dir, $e['name']);
            if ($e['dir']) {
                $l = strtolower($path);
                if (in_array($l, $job['root_paths'], true)) continue;   // searched as a place of its own
                $why = self::pathSkipReason($job, $path, false);
                if ($why === null) {
                    $why = self::folderSkipReason($e['name'], $e['attrs'], $depth + 1, $root['kind'],
                        self::syncRootOf($path, $job['sync_roots']) !== null, $names);
                }
                if ($why === null) $job['queue'][] = [$path, $ri, $depth + 1];
                else self::noteSkip($job, $path, $why);
                continue;
            }
            $ext = strtolower(pathinfo($e['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, self::IMAGE_EXTS, true) && !in_array($ext, self::RAW_EXTS, true)) continue;
            if ($e['attrs'] & (self::ATTR_HIDDEN | self::ATTR_SYSTEM)) { $job['hidden']++; continue; }
            $excluded = null;
            if (isset($job['protected'][strtolower($path)]) || isset($job['protected'][strtolower(self::win($path))])) {
                $excluded = 'used as your desktop background, lock screen or account picture';
            } elseif ($e['lock'] === '1') {
                $excluded = 'in use by another program';
            } elseif ($e['lock'] === '2') {
                $excluded = 'Photo Finder is not allowed to open it';
            }
            $ins->execute([$job['id'], $path, $dir, $ri, $e['size'], $e['mtime'], $e['ctime'], $e['attrs'],
                ($e['attrs'] & self::CLOUD_MASK) ? 1 : 0, $excluded]);
            $job['images']++;
        }
    }

    private static function relDir(array $root, string $dir): string {
        $rp = rtrim($root['path'], '/');
        if (strcasecmp($dir, $rp) === 0 || strcasecmp($dir, $root['path']) === 0) return '';
        return ltrim(substr($dir, strlen($rp)), '/');
    }

    /** Classify found pictures until $deadline (stat data is in the row; local files get their
     *  header read, cloud-only files never do). */
    private static function classifyPending(array &$job, float $deadline): void {
        $pdo = self::db();
        $sel = $pdo->prepare('SELECT id, path, dir, root_idx, size, cloud FROM consolidate_found WHERE search_id = ? AND kind IS NULL LIMIT 200');
        $upd = $pdo->prepare('UPDATE consolidate_found SET kind = ?, basis = ?, width = ?, height = ?, camera = ?, taken = ? WHERE id = ?');
        while (microtime(true) < $deadline) {
            $sel->execute([$job['id']]);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            $sel->closeCursor();
            if (!$rows) return;
            $pdo->beginTransaction();
            try {
                foreach ($rows as $r) {
                    $meta = (int)$r['cloud'] ? null : self::readHeader((string)$r['path']);
                    $rel = self::relDir($job['roots'][(int)$r['root_idx']], (string)$r['dir']);
                    [$kind, $basis] = self::classify(basename((string)$r['path']), $rel, (int)$r['size'], $meta);
                    $camera = $meta ? self::dedupeCamera(trim($meta['make'] . ' ' . $meta['model'])) : '';
                    $upd->execute([$kind, $basis, $meta['w'] ?? null, $meta['h'] ?? null, $camera, $meta['taken'] ?? '', (int)$r['id']]);
                    if (microtime(true) >= $deadline) break;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }

    /** EXIF camera fields and pixel size, from the header only (never the whole file). */
    private static function readHeader(string $path): array {
        $m = ['w' => 0, 'h' => 0, 'make' => '', 'model' => '', 'taken' => ''];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        try {
            if (in_array($ext, ['jpg', 'jpeg', 'tif', 'tiff', 'heic', 'heif'], true) && function_exists('exif_read_data')) {
                $e = @exif_read_data($path, null, false, false);
                if (is_array($e)) {
                    $str = fn($v) => is_string($v) ? trim(str_replace("\0", '', $v)) : '';
                    $m['make'] = $str($e['Make'] ?? '');
                    $m['model'] = $str($e['Model'] ?? '');
                    $m['taken'] = $str($e['DateTimeOriginal'] ?? '');
                    $m['w'] = (int)($e['COMPUTED']['Width'] ?? 0);
                    $m['h'] = (int)($e['COMPUTED']['Height'] ?? 0);
                }
            }
            if ($m['w'] === 0 && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff'], true)) {
                $sz = @getimagesize($path);
                if ($sz) { $m['w'] = (int)$sz[0]; $m['h'] = (int)$sz[1]; }
            }
        } catch (Throwable) {
            // unreadable header: classified by name and size
        }
        return $m;
    }

    private static function finalize(array &$job, string $status): void {
        self::db()->prepare("UPDATE consolidate_found SET kind = 'image', basis = 'not checked (search was stopped)'
                             WHERE search_id = ? AND kind IS NULL")->execute([$job['id']]);
        // Which folders do the Lightroom catalogs found manage? Read-only; a catalog Lightroom has
        // open (locked) cannot be read, so then everything in that searched place stays unticked.
        $lr = [];
        foreach (array_unique($job['lightroom']) as $cat) {
            $folders = self::lightroomRoots($cat);
            if ($folders === null) {
                foreach ($job['roots'] as $r) {
                    if (self::inside($cat, $r['path'])) {
                        $lr[$r['path']] = 'a Lightroom catalog (' . basename($cat) . ') may manage photos here and could not be read';
                        break;
                    }
                }
            } else {
                foreach ($folders as $f) $lr[$f] = 'managed by Lightroom (' . basename($cat) . '): Lightroom would lose track of moved photos';
            }
        }
        $job['lightroom_roots'] = $lr;
        $job['status'] = $status;
        $job['finished_at'] = time();
        $job['unsearched'] = count($job['queue']);   // folders a stopped search never reached
        $job['queue'] = [];
    }

    private static function lightroomRoots(string $catalog): ?array {
        try {
            $pdo = new PDO('sqlite:' . $catalog, null, null, [
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $rows = $pdo->query('SELECT absolutePath FROM AgLibraryRootFolder')->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_filter(array_map(fn($p) => self::norm((string)$p), $rows)));
        } catch (Throwable) {
            return null;
        }
    }

    // =============================================================================================
    // Review
    // =============================================================================================

    /** Found pictures grouped by folder (screenshots in groups of their own), with what starts
     *  ticked and why the rest does not, plus the folders that were not searched. */
    public static function results(string $id): array {
        $job = self::requireJob($id);
        $st = self::db()->prepare(
            'SELECT id, path, dir, root_idx, size, cloud, excluded, kind, basis, width, height, camera, taken
             FROM consolidate_found WHERE search_id = ? ORDER BY dir, path'
        );
        $st->execute([$id]);
        $groups = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $kind = (string)($r['kind'] ?? 'image');
            $bucket = $kind === 'screenshot' ? 'screenshots' : 'pictures';
            $key = $r['dir'] . '|' . $bucket;
            if (!isset($groups[$key])) {
                $root = $job['roots'][(int)$r['root_idx']];
                $groups[$key] = [
                    'key' => $key, 'dir' => (string)$r['dir'], 'root_idx' => (int)$r['root_idx'],
                    'root' => $root['label'], 'rel' => self::relDir($root, (string)$r['dir']), 'bucket' => $bucket,
                    'count' => 0, 'size' => 0, 'cloud' => 0, 'excluded' => 0, 'selectable' => 0,
                    'mix' => ['photo' => 0, 'screenshot' => 0, 'image' => 0, 'asset' => 0], 'files' => [],
                ];
            }
            $g = &$groups[$key];
            $g['count']++;
            $g['size'] += (int)$r['size'];
            $g['mix'][$kind] = ($g['mix'][$kind] ?? 0) + 1;
            if ((int)$r['cloud']) $g['cloud']++;
            if ($r['excluded'] !== null) $g['excluded']++;
            elseif ($kind !== 'asset') $g['selectable']++;
            $g['files'][] = [
                'i' => (int)$r['id'], 'n' => basename((string)$r['path']), 's' => (int)$r['size'], 'k' => $kind,
                'b' => (string)$r['basis'], 'c' => (int)$r['cloud'], 'x' => $r['excluded'],
                'w' => (int)$r['width'], 'h' => (int)$r['height'], 't' => (string)$r['taken'],
            ];
            unset($g);
        }
        $ctx = [
            'shared' => $job['shared'], 'lightroom' => $job['lightroom_roots'] ?? [], 'digikam' => $job['digikam'],
            'target' => $job['target'], 'sync_roots' => $job['sync_roots'],
        ];
        $out = [];
        $tot = ['groups' => 0, 'files' => 0, 'size' => 0, 'preselected_files' => 0, 'preselected_size' => 0,
                'preselected_groups' => 0, 'mix' => ['photo' => 0, 'screenshot' => 0, 'image' => 0, 'asset' => 0]];
        foreach ($groups as $g) {
            $why = self::groupReason($g, $job['roots'][$g['root_idx']], $ctx);
            $reason = $why[0];
            $g['preselect'] = $reason === null;
            $g['reason'] = $reason;
            $g['notes'] = $why[1];
            $g['caution'] = (bool)($why[2] ?? false);
            $samples = [];
            foreach ($g['files'] as &$f) {
                $f['d'] = $g['preselect'] && $f['x'] === null && in_array($f['k'], ['photo', 'image'], true);
                if ($f['d']) { $tot['preselected_files']++; $tot['preselected_size'] += $f['s']; }
                if (!$f['c'] && $f['k'] !== 'asset' && count($samples) < 4) $samples[] = $f['i'];
            }
            unset($f);
            if (count($samples) < 4) {
                foreach ($g['files'] as $f) if (!$f['c'] && $f['k'] === 'asset' && count($samples) < 4) $samples[] = $f['i'];
            }
            $g['samples'] = $samples;
            $g['dir'] = self::win($g['dir']);
            $tot['groups']++;
            $tot['files'] += $g['count'];
            $tot['size'] += $g['size'];
            if ($g['preselect']) $tot['preselected_groups']++;
            foreach ($g['mix'] as $k => $n) $tot['mix'][$k] = ($tot['mix'][$k] ?? 0) + $n;
            $out[] = $g;
        }
        usort($out, fn($a, $b) => [$b['preselect'], $a['root_idx'], strtolower($a['dir']), $a['bucket']]
                                  <=> [$a['preselect'], $b['root_idx'], strtolower($b['dir']), $b['bucket']]);
        $skipped = [];
        foreach ($job['skipped'] as [$p, $why]) {
            $skipped[$why] ??= ['reason' => $why, 'count' => $job['skip_reasons'][$why] ?? 0, 'paths' => []];
            if (count($skipped[$why]['paths']) < 200) $skipped[$why]['paths'][] = self::win($p);
        }
        usort($skipped, fn($a, $b) => $b['count'] <=> $a['count']);
        return [
            'search' => self::progress($job),
            'roots' => array_map(fn($r) => ['label' => $r['label'], 'path' => self::win($r['path']), 'kind' => $r['kind']], $job['roots']),
            'target' => self::win($job['target']),
            'totals' => $tot,
            'hidden' => $job['hidden'],
            'groups' => $out,
            'skipped' => array_values($skipped),
            'skipped_count' => $job['skipped_count'],
        ];
    }

    /** A small preview of a found picture, made in Photo Finder's data folder. Only for local
     *  files: a cloud-only file is never opened. Returns the JPEG's path, or null. */
    public static function thumb(int $id): ?string {
        $job = self::loadJob();
        if (!$job) return null;
        $st = self::db()->prepare('SELECT path, cloud FROM consolidate_found WHERE id = ? AND search_id = ?');
        $st->execute([$id, $job['id']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if (!$r || (int)$r['cloud'] || !is_file((string)$r['path'])) return null;
        $path = (string)$r['path'];
        $dir = self::dir() . '/thumbs';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $file = $dir . '/' . $id . '.jpg';
        if (is_file($file)) return $file;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) return null;
        $im = false;
        $orient = 1;
        if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
            $e = @exif_read_data($path, null, false, false);
            if (is_array($e)) $orient = (int)($e['Orientation'] ?? 1);
            // The embedded EXIF thumbnail: tiny, and needs no decoding of the full photo.
            $t = @exif_thumbnail($path);
            if (is_string($t) && $t !== '') $im = @imagecreatefromstring($t);
        }
        if (!$im) {
            $info = @getimagesize($path);
            if (!$info || $info[0] * $info[1] > 50_000_000) return null;
            $im = match ($info[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG => @imagecreatefrompng($path),
                IMAGETYPE_GIF => @imagecreatefromgif($path),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
                IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
                default => false,
            };
        }
        if (!$im) return null;
        $im = match ($orient) {
            3 => imagerotate($im, 180, 0) ?: $im,
            6 => imagerotate($im, -90, 0) ?: $im,
            8 => imagerotate($im, 90, 0) ?: $im,
            default => $im,
        };
        $w = imagesx($im);
        $h = imagesy($im);
        $ratio = 112 / max($w, $h, 1);
        $nw = max(1, (int)round($w * min(1, $ratio)));
        $nh = max(1, (int)round($h * min(1, $ratio)));
        $t = imagecreatetruecolor($nw, $nh);
        imagefill($t, 0, 0, imagecolorallocate($t, 10, 12, 16));
        imagecopyresampled($t, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($t, $file, 78);
        return is_file($file) ? $file : null;
    }

    // =============================================================================================
    // Consolidate
    // =============================================================================================

    /** Check the folder photos are gathered into. Returns it normalized. */
    public static function validateTarget(string $target): string {
        $t = self::norm($target);
        if (!preg_match('#^[A-Za-z]:/#', $t)) throw new RuntimeException('Choose a folder on this PC, for example C:\\Users\\You\\Pictures.');
        if (preg_match('#^[A-Za-z]:/$#', $t)) throw new RuntimeException('Choose a folder, not a whole drive.');
        if (preg_match('#(^|/)\.\.?(/|$)#', $t)) throw new RuntimeException('Write the folder path out in full (no "." or "..").');
        if (preg_match('/[<>"|?*]/', substr($t, 2))) throw new RuntimeException('That folder name has characters Windows does not allow.');
        $l = strtolower($t);
        foreach (['/windows', '/program files', '/program files (x86)', '/programdata', '/$recycle.bin'] as $bad) {
            if (str_starts_with(substr($l, 2), $bad . '/') || substr($l, 2) === $bad) throw new RuntimeException('Photo Finder will not put photos in that system folder.');
        }
        if (str_contains($l . '/', '/appdata/')) throw new RuntimeException('Photo Finder will not put photos in AppData.');
        if (file_exists($t) && !is_dir($t)) throw new RuntimeException('That path is a file, not a folder.');
        return $t;
    }

    /** Start moving the given found pictures into $target. */
    public static function moveStart(string $searchId, string $target, array $ids): array {
        return self::locked(function () use ($searchId, $target, $ids): array {
            $job = self::requireJob($searchId);
            if ($job['status'] === 'running') throw new RuntimeException('The search is still running.');
            $t = self::validateTarget($target);
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
            if (!$ids) throw new RuntimeException('Nothing is selected.');
            $s = pf_load_settings();
            $photoDirs = array_values(array_filter(array_map([self::class, 'norm'], $s['photo_dirs'] ?? [])));
            $pdo = self::db();
            $rows = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare("SELECT * FROM consolidate_found WHERE search_id = ? AND id IN ($ph) ORDER BY id");
                $st->execute(array_merge([$searchId], $chunk));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;
            }
            $created = [];
            self::mkdirs($t, $created);
            $runId = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $meta = [
                'sync_roots' => $job['sync_roots'], 'photo_dirs' => $photoDirs, 'created_dirs' => $created,
                'prepared' => false, 'added_photo_dir' => false, 'scan' => null, 'refused' => 0, 'bytes' => 0,
            ];
            $ins = $pdo->prepare(
                'INSERT INTO consolidate_moves (run_id, found_id, old_path, origin, rel, size, mtime, ctime, cloud, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'queued\')'
            );
            $pdo->beginTransaction();
            try {
                $queued = 0;
                foreach ($rows as $r) {
                    $p = (string)$r['path'];
                    $inLibrary = self::inside($p, $t);
                    foreach ($photoDirs as $d) if (self::inside($p, $d)) $inLibrary = true;
                    if ($r['excluded'] !== null || $inLibrary) { $meta['refused']++; continue; }
                    $root = $job['roots'][(int)$r['root_idx']];
                    $ins->execute([$runId, (int)$r['id'], $p, $root['label'], self::relDir($root, (string)$r['dir']),
                        (int)$r['size'], (int)$r['mtime'], (int)$r['ctime'], (int)$r['cloud']]);
                    $queued++;
                }
                if ($queued === 0) throw new RuntimeException('None of the selected pictures can be moved.');
                $pdo->prepare('INSERT INTO consolidate_runs (run_id, search_id, target, state, started_at, meta) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$runId, $searchId, $t, 'running', time(), json_encode($meta, JSON_UNESCAPED_SLASHES)]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                foreach (array_reverse($created) as $d) @rmdir($d);
                throw $e;
            }
            $s['consolidate_target'] = $t;
            pf_save_settings($s);
            return self::runProgress($runId);
        });
    }

    private static function getRun(string $runId): array {
        $st = self::db()->prepare('SELECT * FROM consolidate_runs WHERE run_id = ?');
        $st->execute([$runId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if (!$r) throw new RuntimeException('That consolidation is not in the journal.');
        $r['meta'] = json_decode((string)$r['meta'], true) ?: [];
        return $r;
    }

    private static function saveRun(array $run): void {
        self::db()->prepare('UPDATE consolidate_runs SET state = ?, finished_at = ?, undone_at = ?, meta = ? WHERE run_id = ?')
            ->execute([$run['state'], $run['finished_at'], $run['undone_at'], json_encode($run['meta'], JSON_UNESCAPED_SLASHES), $run['run_id']]);
    }

    /** Move the next few files. */
    public static function moveTick(string $runId): array {
        return self::locked(function () use ($runId): array {
            $run = self::getRun($runId);
            if ($run['state'] !== 'running') return self::runProgress($runId);
            $t0 = microtime(true);
            if (empty($run['meta']['prepared'])) {
                self::prepareSizes($run);
                $run['meta']['prepared'] = true;
                self::saveRun($run);
                return self::runProgress($runId) + ['phase' => 'prepared'];
            }
            $pdo = self::db();
            $st = $pdo->prepare("SELECT * FROM consolidate_moves WHERE run_id = ? AND status = 'queued' ORDER BY id LIMIT 60");
            $st->execute([$runId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($rows) {
                $stats = self::statFiles(array_column($rows, 'old_path'));
                $times = [];
                foreach ($rows as $i => $row) {
                    if ($i > 0 && microtime(true) - $t0 > 3.0) break;
                    self::moveOne($run, $row, $stats[$i] ?? ['exists' => false], $times);
                }
                if ($times) self::setTimes($times);
                self::saveRun($run);
            }
            return self::runProgress($runId);
        });
    }

    public static function moveCancel(string $runId): array {
        return self::locked(function () use ($runId): array {
            self::db()->prepare("UPDATE consolidate_moves SET status = 'cancelled', note = 'not moved: you stopped the consolidation'
                                 WHERE run_id = ? AND status = 'queued'")->execute([$runId]);
            return self::runProgress($runId);
        });
    }

    /** After the last batch: make sure the target is a photo folder, scan the new files into
     *  the index, and report. */
    public static function moveFinish(string $runId): array {
        return self::locked(function () use ($runId): array {
            $run = self::getRun($runId);
            if ($run['state'] === 'running') {
                $pdo = self::db();
                $pdo->prepare("UPDATE consolidate_moves SET status = 'cancelled', note = 'not moved: you stopped the consolidation'
                               WHERE run_id = ? AND status = 'queued'")->execute([$runId]);
                $s = pf_load_settings();
                if (!pf_path_inside_roots($run['target'], $s['photo_dirs'] ?? [])) {
                    $s['photo_dirs'] = array_values(array_merge($s['photo_dirs'] ?? [], [pf_norm_path($run['target'])]));
                    pf_save_settings($s);
                    $run['meta']['added_photo_dir'] = true;
                }
                // Index only the folders this run filled ("From Desktop", ...), not the whole library.
                $st = $pdo->prepare("SELECT new_path FROM consolidate_moves WHERE run_id = ? AND status = 'moved'");
                $st->execute([$runId]);
                $dirs = [];
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $np) {
                    $rel = ltrim(substr((string)$np, strlen(rtrim($run['target'], '/'))), '/');
                    $first = explode('/', $rel)[0];
                    $dirs[strtolower($first)] = self::join($run['target'], $first);
                }
                $scan = null;
                if ($dirs) {
                    $scan = Indexer::scan(array_values($dirs), $s['excluded_dirs'] ?? []);
                    unset($scan['skipped_dirs']);
                }
                $run['meta']['scan'] = $scan;
                $pdo->prepare('DELETE FROM consolidate_sizes WHERE run_id = ?')->execute([$runId]);
                // "Review the last search" then shows only what is still where it was found.
                $pdo->prepare("DELETE FROM consolidate_found WHERE id IN
                               (SELECT found_id FROM consolidate_moves WHERE run_id = ? AND status = 'moved')")->execute([$runId]);
                $run['state'] = 'done';
                $run['finished_at'] = time();
                self::saveRun($run);
            }
            return self::runSummary($runId);
        });
    }

    /** Sizes of everything already in the library (index rows + a listing of the target), for
     *  the duplicate check. Hashes are only computed later, when sizes match. */
    private static function prepareSizes(array $run): void {
        $pdo = self::db();
        $pdo->prepare('DELETE FROM consolidate_sizes WHERE run_id = ?')->execute([$run['run_id']]);
        $seen = [];
        $ins = $pdo->prepare('INSERT INTO consolidate_sizes (run_id, size, path) VALUES (?, ?, ?)');
        $rows = $pdo->query('SELECT path, size FROM images WHERE size IS NOT NULL')->fetchAll(PDO::FETCH_NUM);
        $pdo->beginTransaction();
        try {
            foreach ($rows as [$p, $sz]) {
                $seen[strtolower(self::norm((string)$p))] = true;
                $ins->execute([$run['run_id'], (int)$sz, self::norm((string)$p)]);
            }
            if (is_dir($run['target'])) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($run['target'], FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($it as $f) {
                    if (!$f->isFile()) continue;
                    $ext = strtolower($f->getExtension());
                    if (!in_array($ext, self::IMAGE_EXTS, true) && !in_array($ext, self::RAW_EXTS, true)) continue;
                    $p = self::norm($f->getPathname());
                    if (isset($seen[strtolower($p)])) continue;
                    $seen[strtolower($p)] = true;
                    $ins->execute([$run['run_id'], (int)$f->getSize(), $p]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Where a file goes: <target>/From <origin>/<sub-folders>/<name>, never over an existing file. */
    private static function destinationFor(array $run, array $row): array {
        $base = self::join($run['target'], 'From ' . preg_replace('/[<>:"\/\\\\|?*]+/', '-', (string)$row['origin']));
        $rel = trim((string)$row['rel'], '/');
        $name = basename((string)$row['old_path']);
        $dir = $rel === '' ? $base : self::join($base, $rel);
        $note = null;
        if (strlen(self::join($dir, $name)) > 240) {
            // Keep the path inside Windows' 260-character limit: keep only the last folder name.
            $dir = $rel === '' ? $base : self::join($base, basename($rel));
            $note = 'folder path shortened (it was too long for Windows)';
        }
        return [$dir, $name, $note];
    }

    private static function moveOne(array &$run, array $row, array $st, array &$times): void {
        $pdo = self::db();
        $src = (string)$row['old_path'];
        $fail = function (string $why) use ($pdo, $row): void {
            $pdo->prepare("UPDATE consolidate_moves SET status = 'failed', note = ?, moved_at = ? WHERE id = ?")
                ->execute([$why, time(), (int)$row['id']]);
        };
        try {
            if (empty($st['exists'])) {
                // Interrupted right after the move, before the journal said so (the planned
                // destination is written first): record it, so Undo can still put it back.
                $planned = (string)($row['new_path'] ?? '');
                clearstatcache();
                if ($planned !== '' && is_file($planned) && filesize($planned) === (int)$row['size']) {
                    $pdo->prepare("UPDATE consolidate_moves SET status = 'moved', method = 'rename', note = 'move finished after an interruption', moved_at = ? WHERE id = ?")
                        ->execute([time(), (int)$row['id']]);
                    return;
                }
                $fail('not found any more (moved or deleted after the search)');
                return;
            }
            foreach (array_merge([$run['target']], $run['meta']['photo_dirs'] ?? []) as $d) {
                if (self::inside($src, $d)) { $fail('already in your photo library'); return; }
            }
            if ($st['lock'] === '1') { $fail('in use by another program (close it and try again)'); return; }
            if ($st['lock'] === '2') { $fail('Photo Finder is not allowed to open it'); return; }
            $cloud = ((int)$st['attrs'] & self::CLOUD_MASK) !== 0;
            $size = (int)$st['size'];
            $hash = null;
            $dup = self::findDuplicate($run['run_id'], $src, $size, $hash);
            if ($dup !== null) {
                $pdo->prepare("UPDATE consolidate_moves SET status = 'duplicate', new_path = ?, sha256 = ?, size = ?, cloud = ?,
                               note = 'already in the library: left where it is', moved_at = ? WHERE id = ?")
                    ->execute([$dup, $hash, $size, $cloud ? 1 : 0, time(), (int)$row['id']]);
                return;
            }
            [$destDir, $name, $note] = self::destinationFor($run, $row);
            $created = $run['meta']['created_dirs'] ?? [];
            self::mkdirs($destDir, $created);
            $run['meta']['created_dirs'] = $created;
            self::saveRun($run);
            $dest = self::freeName(self::join($destDir, $name));
            $pdo->prepare('UPDATE consolidate_moves SET new_path = ? WHERE id = ?')->execute([$dest, (int)$row['id']]);
            $r = self::safeMove($src, $dest, $cloud, $run['meta']['sync_roots'] ?? [], $hash);
            if (!$r['ok']) { $fail($r['error']); return; }
            if ($r['method'] === 'copy') $times[] = [$r['dest'], (int)$st['ctime'], (int)$st['mtime']];
            $pdo->prepare("UPDATE consolidate_moves SET status = 'moved', new_path = ?, method = ?, sha256 = ?, size = ?,
                           mtime = ?, ctime = ?, cloud = ?, recycled_as = ?, note = ?, moved_at = ? WHERE id = ?")
                ->execute([$r['dest'], $r['method'], $r['sha'], $size, (int)$st['mtime'], (int)$st['ctime'],
                    $cloud ? 1 : 0, $r['recycled_as'], $note, time(), (int)$row['id']]);
            $pdo->prepare('INSERT INTO consolidate_sizes (run_id, size, path, sha256) VALUES (?, ?, ?, ?)')
                ->execute([$run['run_id'], $size, $r['dest'], $r['sha']]);
            $run['meta']['bytes'] = (int)($run['meta']['bytes'] ?? 0) + $size;
        } catch (Throwable $e) {
            $fail($e->getMessage());
        }
    }

    /** An exact copy of $src already in the library (or moved earlier in this run), by size and
     *  then SHA-256. Hashes only when the sizes match. */
    private static function findDuplicate(string $runId, string $src, int $size, ?string &$srcHash): ?string {
        $pdo = self::db();
        $st = $pdo->prepare('SELECT rowid, path, sha256 FROM consolidate_sizes WHERE run_id = ? AND size = ?');
        $st->execute([$runId, $size]);
        $cands = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        foreach ($cands as $c) {
            $p = (string)$c['path'];
            if (strcasecmp(self::norm($p), self::norm($src)) === 0) continue;
            clearstatcache(true, $p);
            if (!is_file($p) || filesize($p) !== $size) continue;
            $srcHash ??= hash_file('sha256', $src) ?: null;
            if ($srcHash === null) return null;
            $h = $c['sha256'];
            if ($h === null) {
                $h = hash_file('sha256', $p) ?: null;
                if ($h !== null) $pdo->prepare('UPDATE consolidate_sizes SET sha256 = ? WHERE rowid = ?')->execute([$h, (int)$c['rowid']]);
            }
            if ($h !== null && hash_equals($h, $srcHash)) return $p;
        }
        return null;
    }

    /** Move one file safely. Same volume (and not cloud-only, or within one OneDrive): rename().
     *  Otherwise copy + flush + verify size and SHA-256, then the original goes to the Recycle Bin.
     *  Returns ['ok', 'error', 'dest', 'method', 'sha', 'recycled_as']. */
    private static function safeMove(string $src, string $dest, bool $cloud, array $syncRoots, ?string $hash): array {
        $res = ['ok' => false, 'error' => '', 'dest' => '', 'method' => '', 'sha' => $hash, 'recycled_as' => null];
        $destDir = dirname($dest);
        $sv = @stat($src);
        $dv = @stat($destDir);
        if (!$sv || !$dv) { $res['error'] = 'could not read the file or the destination folder'; return $res; }
        $sameVolume = $sv['dev'] === $dv['dev'];
        $srcRoot = self::syncRootOf($src, $syncRoots);
        $sameCloud = $srcRoot !== null && $srcRoot === self::syncRootOf($destDir, $syncRoots);
        if ($sameVolume && (!$cloud || $sameCloud)) {
            // A local file is hashed for the journal (Undo checks it); a cloud-only one is not,
            // because reading it would download it.
            if (!$cloud && $res['sha'] === null) $res['sha'] = hash_file('sha256', $src) ?: null;
            $dest = self::freeName($dest);
            if (!@rename($src, $dest)) {
                $err = error_get_last()['message'] ?? '';
                $res['error'] = 'could not move it' . (stripos($err, 'denied') !== false || stripos($err, 'being used') !== false
                    ? ' (in use by another program, or no permission)' : ($err !== '' ? ': ' . self::shortErr($err) : ''));
                return $res;
            }
            clearstatcache();
            if (!is_file($dest)) { $res['error'] = 'the move did not complete'; return $res; }
            return ['ok' => true, 'error' => '', 'dest' => $dest, 'method' => $cloud ? 'rename-cloud' : 'rename',
                    'sha' => $res['sha'], 'recycled_as' => null];
        }
        $c = self::copyVerified($src, $dest);
        if (!$c['ok']) { $res['error'] = $c['error']; return $res; }
        $rec = pf_recycle_files([$src])[$src] ?? ['ok' => false, 'error' => 'no response'];
        if (!$rec['ok']) {
            @unlink($c['dest']);   // Photo Finder's own verified copy; the original is untouched
            $res['error'] = 'copied, but the original could not go to the Recycle Bin (' . $rec['error'] . '); the copy was removed';
            return $res;
        }
        return ['ok' => true, 'error' => '', 'dest' => $c['dest'], 'method' => 'copy', 'sha' => $c['sha'],
                'recycled_as' => isset($rec['recycled_as']) ? self::norm((string)$rec['recycled_as']) : null];
    }

    /** Copy to a temporary name, flush to disk, verify size and SHA-256 against the source,
     *  keep the modified time, then give it its final (free) name. */
    private static function copyVerified(string $src, string $dest): array {
        $tmp = $dest . '.' . bin2hex(random_bytes(3)) . '.pf-partial';
        $in = @fopen($src, 'rb');
        if (!$in) return ['ok' => false, 'error' => 'could not open it (in use, or no permission)'];
        $out = @fopen($tmp, 'xb');
        if (!$out) { fclose($in); return ['ok' => false, 'error' => 'could not write in the destination folder']; }
        $ctx = hash_init('sha256');
        $n = 0;
        $ok = true;
        $err = '';
        while (!feof($in)) {
            $buf = fread($in, 1 << 20);
            if ($buf === false) { $ok = false; $err = 'read error'; break; }
            if ($buf === '') break;
            hash_update($ctx, $buf);
            $w = fwrite($out, $buf);
            if ($w !== strlen($buf)) { $ok = false; $err = 'write error (disk full?)'; break; }
            $n += $w;
        }
        fflush($out);
        if (function_exists('fsync')) @fsync($out);
        fclose($out);
        fclose($in);
        $srcSha = hash_final($ctx);
        clearstatcache();
        if ($ok && ($n !== filesize($src) || filesize($tmp) !== $n)) { $ok = false; $err = 'size check failed'; }
        if ($ok && !hash_equals($srcSha, (string)hash_file('sha256', $tmp))) { $ok = false; $err = 'the copy did not match the original'; }
        $mt = (int)@filemtime($src);
        if ($ok && $mt > 0) @touch($tmp, $mt, $mt);
        if ($ok) {
            $final = self::freeName($dest);
            if (!@rename($tmp, $final)) { $ok = false; $err = 'could not name the copy'; }
            else return ['ok' => true, 'error' => '', 'dest' => $final, 'sha' => $srcSha];
        }
        @unlink($tmp);   // Photo Finder's own partial copy
        return ['ok' => false, 'error' => 'copy failed: ' . $err];
    }

    private static function freeName(string $path): string {
        clearstatcache();
        if (!file_exists($path)) return $path;
        $dir = dirname($path);
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        for ($i = 2; $i < 10000; $i++) {
            $c = self::join($dir, $stem . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : ''));
            if (!file_exists($c)) return $c;
        }
        throw new RuntimeException('no free file name');
    }

    private static function mkdirs(string $dir, array &$created): void {
        $missing = [];
        $d = self::norm($dir);
        while (!is_dir($d)) {
            $missing[] = $d;
            $p = self::norm(dirname($d));
            if ($p === $d) break;
            $d = $p;
        }
        foreach (array_reverse($missing) as $m) {
            if (!@mkdir($m) && !is_dir($m)) throw new RuntimeException('could not create the folder ' . self::win($m));
            $created[] = $m;
        }
    }

    private static function shortErr(string $e): string {
        $e = preg_replace('/^[a-z_]+\(.*?\):\s*/i', '', $e) ?? $e;
        return mb_substr($e, 0, 160);
    }

    private static function counts(string $runId): array {
        $st = self::db()->prepare('SELECT status, COUNT(*) n, COALESCE(SUM(size), 0) b FROM consolidate_moves WHERE run_id = ? GROUP BY status');
        $st->execute([$runId]);
        $c = ['queued' => 0, 'moved' => 0, 'duplicate' => 0, 'failed' => 0, 'cancelled' => 0, 'undone' => 0, 'undo_failed' => 0];
        $bytes = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c[$r['status']] = (int)$r['n'];
            if ($r['status'] === 'moved') $bytes = (int)$r['b'];
        }
        $c['total'] = array_sum($c);
        $c['bytes_moved'] = $bytes;
        return $c;
    }

    public static function runProgress(string $runId): array {
        $run = self::getRun($runId);
        $c = self::counts($runId);
        return [
            'run_id' => $runId, 'state' => $run['state'], 'target' => self::win($run['target']),
            'counts' => $c, 'finished' => $c['queued'] === 0,
            'prepared' => !empty($run['meta']['prepared']),
        ];
    }

    /** What a run did, for the summary screen and the journal list. */
    public static function runSummary(string $runId): array {
        $run = self::getRun($runId);
        $st = self::db()->prepare('SELECT * FROM consolidate_moves WHERE run_id = ? ORDER BY id');
        $st->execute([$runId]);
        $dups = [];
        $failed = [];
        $undoFailed = [];
        $methods = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if ($m['status'] === 'duplicate') $dups[] = ['path' => self::win((string)$m['old_path']), 'same_as' => self::win((string)$m['new_path'])];
            if ($m['status'] === 'failed') $failed[] = ['path' => self::win((string)$m['old_path']), 'reason' => (string)$m['note']];
            if ($m['status'] === 'undo_failed') $undoFailed[] = ['path' => self::win((string)$m['old_path']), 'now_at' => self::win((string)$m['new_path']), 'reason' => (string)$m['note']];
            if (in_array($m['status'], ['moved', 'undone', 'undo_failed'], true)) $methods[(string)$m['method']] = ($methods[(string)$m['method']] ?? 0) + 1;
        }
        return self::runProgress($runId) + [
            'started_at' => (int)$run['started_at'], 'finished_at' => $run['finished_at'] !== null ? (int)$run['finished_at'] : null,
            'undone_at' => $run['undone_at'] !== null ? (int)$run['undone_at'] : null,
            'duplicates' => $dups, 'failed_list' => $failed, 'undo_failed_list' => $undoFailed, 'methods' => $methods,
            'scan' => $run['meta']['scan'] ?? null, 'added_photo_dir' => !empty($run['meta']['added_photo_dir']),
            'refused' => (int)($run['meta']['refused'] ?? 0),
        ];
    }

    public static function runs(int $limit): array {
        $st = self::db()->prepare('SELECT run_id FROM consolidate_runs ORDER BY started_at DESC, rowid DESC LIMIT ?');
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $p = self::runProgress((string)$id);
            $run = self::getRun((string)$id);
            $p['started_at'] = (int)$run['started_at'];
            $p['undone_at'] = $run['undone_at'] !== null ? (int)$run['undone_at'] : null;
            $out[] = $p;
        }
        return $out;
    }

    // =============================================================================================
    // Undo
    // =============================================================================================

    /** Put the next few files of a run back where they came from. */
    public static function undoTick(string $runId): array {
        return self::locked(function () use ($runId): array {
            $run = self::getRun($runId);
            if (!in_array($run['state'], ['done', 'undoing'], true)) {
                if ($run['state'] === 'running') throw new RuntimeException('Finish or stop the consolidation first.');
                return self::runSummary($runId) + ['undo_finished' => true];
            }
            if ($run['state'] !== 'undoing') { $run['state'] = 'undoing'; self::saveRun($run); }
            $t0 = microtime(true);
            $pdo = self::db();
            $st = $pdo->prepare("SELECT * FROM consolidate_moves WHERE run_id = ? AND status = 'moved' ORDER BY id DESC LIMIT 60");
            $st->execute([$runId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($rows) {
                $stats = self::statFiles(array_column($rows, 'new_path'));
                $times = [];
                foreach ($rows as $i => $row) {
                    if ($i > 0 && microtime(true) - $t0 > 3.0) break;
                    self::undoOne($run, $row, $stats[$i] ?? ['exists' => false], $times);
                }
                if ($times) self::setTimes($times);
                return self::runSummary($runId) + ['undo_finished' => false];
            }
            // Done: remove the folders this run created, if they are empty now, and the target
            // from the photo folders if this run added it and it is gone.
            $dirs = $run['meta']['created_dirs'] ?? [];
            usort($dirs, fn($a, $b) => strlen($b) <=> strlen($a));
            foreach ($dirs as $d) {
                if (is_dir($d) && count(scandir($d) ?: []) <= 2) @rmdir($d);
            }
            if (!empty($run['meta']['added_photo_dir']) && !is_dir($run['target'])) {
                $s = pf_load_settings();
                $s['photo_dirs'] = array_values(array_filter($s['photo_dirs'] ?? [], fn($d) => strcasecmp(pf_norm_path((string)$d), pf_norm_path($run['target'])) !== 0));
                pf_save_settings($s);
            }
            $run['state'] = 'undone';
            $run['undone_at'] = time();
            self::saveRun($run);
            return self::runSummary($runId) + ['undo_finished' => true];
        });
    }

    private static function undoOne(array &$run, array $row, array $st, array &$times): void {
        $pdo = self::db();
        $id = (int)$row['id'];
        $new = (string)$row['new_path'];
        $old = (string)$row['old_path'];
        $sha = $row['sha256'] !== null ? (string)$row['sha256'] : null;
        $bin = $row['recycled_as'] !== null ? (string)$row['recycled_as'] : null;
        $done = function (string $note = '') use ($pdo, $id, $new): void {
            $pdo->prepare("UPDATE consolidate_moves SET status = 'undone', note = ?, undone_at = ? WHERE id = ?")
                ->execute([$note !== '' ? $note : null, time(), $id]);
            Db::deleteImages([pf_path_id($new)]);
        };
        $fail = function (string $why) use ($pdo, $id): void {
            $pdo->prepare("UPDATE consolidate_moves SET status = 'undo_failed', note = ?, undone_at = ? WHERE id = ?")
                ->execute([$why, time(), $id]);
        };
        try {
            clearstatcache();
            $newCloud = !empty($st['exists']) && ((int)$st['attrs'] & self::CLOUD_MASK) !== 0;
            if (file_exists($old)) {
                // Something is at the original place again. If it is the original itself (put
                // back from the Recycle Bin by hand), only the library copy needs to go.
                if ($row['method'] === 'copy' && $sha !== null && is_file($old) && hash_equals($sha, (string)hash_file('sha256', $old))) {
                    if (!empty($st['exists'])) {
                        $r = pf_recycle_files([$new])[$new] ?? ['ok' => false, 'error' => 'no response'];
                        if (!$r['ok']) { $fail('the original is back, but the library copy could not go to the Recycle Bin: ' . $r['error']); return; }
                    }
                    $done('the original was already back; the library copy went to the Recycle Bin');
                    return;
                }
                $fail('a different file is now at the original location; left this one in the library');
                return;
            }
            $created = [];
            self::mkdirs(dirname($old), $created);
            if (empty($st['exists'])) {
                if ($row['method'] === 'copy' && $bin !== null && is_file($bin)) {
                    if (!self::restoreFromBin($bin, $old)) { $fail('could not restore the original from the Recycle Bin'); return; }
                    $done('restored the original from the Recycle Bin (the library copy was already gone)');
                    return;
                }
                $fail('the moved file is no longer at ' . self::win($new));
                return;
            }
            if ($st['lock'] === '1') { $fail('in use by another program (close it and try again)'); return; }
            $changed = $sha !== null && !$newCloud && !hash_equals($sha, (string)hash_file('sha256', $new));
            if ($row['method'] === 'copy' && $bin !== null && is_file($bin) && !$changed) {
                // Copied originally: bring the original back out of the Recycle Bin, then send the
                // library copy there.
                if (!self::restoreFromBin($bin, $old)) { $fail('could not restore the original from the Recycle Bin'); return; }
                $r = pf_recycle_files([$new])[$new] ?? ['ok' => false, 'error' => 'no response'];
                $done($r['ok'] ? '' : 'the original is back; the library copy could not go to the Recycle Bin (' . $r['error'] . ')');
                return;
            }
            // Otherwise put the file itself back, with the same safe move.
            $r = self::safeMove($new, $old, $newCloud, $run['meta']['sync_roots'] ?? [], $changed ? null : $sha);
            if (!$r['ok']) { $fail($r['error']); return; }
            if (strcasecmp(self::norm($r['dest']), self::norm($old)) !== 0) {
                $fail('put back as ' . self::win($r['dest']) . ' (the original name was taken)');
                return;
            }
            if ($r['method'] === 'copy') $times[] = [$r['dest'], (int)$row['ctime'], (int)$row['mtime']];
            $done($changed ? 'it had changed since the move; the changed version was put back' : '');
        } catch (Throwable $e) {
            $fail($e->getMessage());
        }
    }

    /** Move a recycled file ($R…) back to $to and remove its Recycle Bin entry ($I…). Both are on
     *  the same drive (the bin is on the original file's drive), so this is a rename. */
    private static function restoreFromBin(string $rFile, string $to): bool {
        $r = self::norm($rFile);
        $base = basename($r);
        if (!str_starts_with($base, '$R') || !str_contains(strtolower($r), '/$recycle.bin/')) return false;
        if (file_exists($to) || !@rename($r, $to)) return false;
        @unlink(self::join(dirname($r), '$I' . substr($base, 2)));
        return true;
    }

    // =============================================================================================
    // Windows PowerShell helpers (the bundled PHP has no FFI/COM to read file attributes)
    // =============================================================================================

    private static function psq(string $s): string {
        // PowerShell treats the typographic quotes as quotes too: double every one of them.
        return "'" . preg_replace("/(['\x{2018}\x{2019}\x{201A}\x{201B}])/u", '$1$1', $s) . "'";
    }

    private static function powershell(string $script): string {
        $exe = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $b64 = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        [$exit, $out, $err] = pf_run_cmd([$exe, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $b64]);
        if ($exit !== 0) throw new RuntimeException('The Windows helper failed: ' . mb_substr(trim($err !== '' ? $err : $out), 0, 300));
        return $out;
    }

    /** List folders (not recursively) until about $budgetMs have passed. Returns
     *  [number of folders listed, [index => ['status', 'entries' => [[name, dir, attrs, size,
     *  mtime, ctime, lock]]]]]. lock: '0' free, '1' in use, '2' no permission, '-' not checked
     *  (cloud-only files are never opened). */
    private static function listDirs(array $dirs, int $budgetMs): array {
        $base = self::dir() . '/list-' . bin2hex(random_bytes(4));
        file_put_contents($base . '.in', implode("\n", array_map([self::class, 'win'], $dirs)));
        $exts = implode(',', array_merge(self::IMAGE_EXTS, self::RAW_EXTS));
        $script = strtr(<<<'PS'
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$enc = New-Object System.Text.UTF8Encoding $false
$dirs = [System.IO.File]::ReadAllLines(__IN__, $enc)
$w = New-Object System.IO.StreamWriter(__OUT__, $false, $enc)
$exts = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
foreach ($x in __EXTS__.Split(',')) { [void]$exts.Add($x) }
$epoch = New-Object DateTime 1970, 1, 1, 0, 0, 0, ([DateTimeKind]::Utc)
$cloud = 0x400000 -bor 0x1000 -bor 0x40000
$sw = [Diagnostics.Stopwatch]::StartNew()
$n = 0
foreach ($d in $dirs) {
  if ($n -gt 0 -and $sw.ElapsedMilliseconds -gt __BUDGET__) { break }
  $w.WriteLine("D`t$n")
  $st = 'ok'
  try {
    foreach ($e in (New-Object System.IO.DirectoryInfo $d).EnumerateFileSystemInfos()) {
      $a = [int]$e.Attributes
      if ($a -band 16) { $w.WriteLine("d`t$a`t" + $e.Name); continue }
      $lock = '-'
      $x = $e.Extension
      if ($x.Length -gt 1 -and $exts.Contains($x.Substring(1)) -and -not ($a -band $cloud)) {
        # Exclusive open, read access, nothing read: fails while another program has it open.
        try { $fs = [System.IO.File]::Open($e.FullName, 'Open', 'Read', 'None'); $fs.Dispose(); $lock = '0' }
        catch { $ex = $_.Exception; if ($ex.InnerException) { $ex = $ex.InnerException }; if (($ex.HResult -band 0xFFFF) -eq 5) { $lock = '2' } else { $lock = '1' } }
      }
      $mt = [long][Math]::Floor(($e.LastWriteTimeUtc - $epoch).TotalSeconds)
      $ct = [long][Math]::Floor(($e.CreationTimeUtc - $epoch).TotalSeconds)
      $w.WriteLine("f`t$a`t" + $e.Length + "`t$mt`t$ct`t$lock`t" + $e.Name)
    }
  } catch [System.UnauthorizedAccessException] { $st = 'denied' }
    catch [System.IO.DirectoryNotFoundException] { $st = 'missing' }
    catch { $st = ($_.Exception.Message -replace '[\r\n\t]+', ' ') }
  $w.WriteLine("E`t$st")
  $n++
}
$w.WriteLine("X`t$n")
$w.Close()
PS, ['__IN__' => self::psq($base . '.in'), '__OUT__' => self::psq($base . '.out'),
     '__EXTS__' => self::psq($exts), '__BUDGET__' => (string)$budgetMs]);
        try {
            self::powershell($script);
            $raw = is_file($base . '.out') ? (string)file_get_contents($base . '.out') : '';
        } finally {
            @unlink($base . '.in');
            @unlink($base . '.out');
        }
        $listings = [];
        $cur = -1;
        $done = 0;
        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') continue;
            $p = explode("\t", $line);
            switch ($p[0]) {
                case 'D':
                    $cur = (int)$p[1];
                    $listings[$cur] = ['status' => 'ok', 'entries' => []];
                    break;
                case 'd':
                    if ($cur >= 0 && count($p) >= 3) {
                        $listings[$cur]['entries'][] = ['name' => $p[2], 'dir' => true, 'attrs' => (int)$p[1],
                            'size' => 0, 'mtime' => 0, 'ctime' => 0, 'lock' => '-'];
                    }
                    break;
                case 'f':
                    if ($cur >= 0 && count($p) >= 7) {
                        $listings[$cur]['entries'][] = ['name' => $p[6], 'dir' => false, 'attrs' => (int)$p[1],
                            'size' => (int)$p[2], 'mtime' => (int)$p[3], 'ctime' => (int)$p[4], 'lock' => $p[5]];
                    }
                    break;
                case 'E':
                    if ($cur >= 0) $listings[$cur]['status'] = $p[1] ?? 'error';
                    break;
                case 'X':
                    $done = (int)$p[1];
                    break;
            }
        }
        if ($done === 0 && $dirs) throw new RuntimeException('The folder listing helper returned nothing.');
        return [$done, $listings];
    }

    /** Attributes, size, times and in-use state of files, right before they are moved. */
    private static function statFiles(array $paths): array {
        $base = self::dir() . '/stat-' . bin2hex(random_bytes(4));
        file_put_contents($base . '.in', implode("\n", array_map([self::class, 'win'], $paths)));
        $script = strtr(<<<'PS'
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$enc = New-Object System.Text.UTF8Encoding $false
$lines = [System.IO.File]::ReadAllLines(__IN__, $enc)
$w = New-Object System.IO.StreamWriter(__OUT__, $false, $enc)
$epoch = New-Object DateTime 1970, 1, 1, 0, 0, 0, ([DateTimeKind]::Utc)
$cloud = 0x400000 -bor 0x1000 -bor 0x40000
$i = 0
foreach ($l in $lines) {
  try {
    $f = New-Object System.IO.FileInfo $l
    if (-not $f.Exists) { $w.WriteLine("$i`t0") }
    else {
      $a = [int]$f.Attributes
      $lock = '-'
      if (-not ($a -band $cloud)) {
        try { $fs = [System.IO.File]::Open($f.FullName, 'Open', 'Read', 'None'); $fs.Dispose(); $lock = '0' }
        catch { $ex = $_.Exception; if ($ex.InnerException) { $ex = $ex.InnerException }; if (($ex.HResult -band 0xFFFF) -eq 5) { $lock = '2' } else { $lock = '1' } }
      }
      $mt = [long][Math]::Floor(($f.LastWriteTimeUtc - $epoch).TotalSeconds)
      $ct = [long][Math]::Floor(($f.CreationTimeUtc - $epoch).TotalSeconds)
      $w.WriteLine("$i`t1`t$a`t" + $f.Length + "`t$mt`t$ct`t$lock")
    }
  } catch { $w.WriteLine("$i`t0") }
  $i++
}
$w.Close()
PS, ['__IN__' => self::psq($base . '.in'), '__OUT__' => self::psq($base . '.out')]);
        try {
            self::powershell($script);
            $raw = is_file($base . '.out') ? (string)file_get_contents($base . '.out') : '';
        } finally {
            @unlink($base . '.in');
            @unlink($base . '.out');
        }
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $p = explode("\t", rtrim($line, "\r"));
            if (count($p) < 2) continue;
            $i = (int)$p[0];
            $out[$i] = $p[1] === '1' && count($p) >= 7
                ? ['exists' => true, 'attrs' => (int)$p[2], 'size' => (int)$p[3], 'mtime' => (int)$p[4], 'ctime' => (int)$p[5], 'lock' => $p[6]]
                : ['exists' => false, 'attrs' => 0, 'size' => 0, 'mtime' => 0, 'ctime' => 0, 'lock' => '-'];
        }
        return $out;
    }

    /** Give copies the original's creation and modified times (PHP can only set the latter). */
    private static function setTimes(array $items): void {
        $base = self::dir() . '/times-' . bin2hex(random_bytes(4));
        file_put_contents($base . '.in', implode("\n", array_map(fn($t) => self::win($t[0]) . "\t" . $t[1] . "\t" . $t[2], $items)));
        $script = strtr(<<<'PS'
$ErrorActionPreference = 'Stop'
$enc = New-Object System.Text.UTF8Encoding $false
$epoch = New-Object DateTime 1970, 1, 1, 0, 0, 0, ([DateTimeKind]::Utc)
foreach ($l in [System.IO.File]::ReadAllLines(__IN__, $enc)) {
  $p = $l.Split("`t")
  try {
    $f = New-Object System.IO.FileInfo $p[0]
    if ([long]$p[1] -gt 0) { $f.CreationTimeUtc = $epoch.AddSeconds([double]$p[1]) }
    if ([long]$p[2] -gt 0) { $f.LastWriteTimeUtc = $epoch.AddSeconds([double]$p[2]) }
  } catch { }
}
PS, ['__IN__' => self::psq($base . '.in')]);
        try {
            self::powershell($script);
        } finally {
            @unlink($base . '.in');
        }
    }
}
