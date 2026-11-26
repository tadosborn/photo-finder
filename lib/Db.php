<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

final class Db {
    private static ?PDO $pdo = null;

    public static function get(): PDO {
        if (self::$pdo) return self::$pdo;
        if (!is_dir(PF_DATA)) mkdir(PF_DATA, 0777, true);
        $pdo = new PDO('sqlite:' . PF_DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    private static function migrate(PDO $pdo): void {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS images (
                id TEXT PRIMARY KEY,
                path TEXT UNIQUE NOT NULL,
                size INTEGER,
                mtime INTEGER,
                width INTEGER,
                height INTEGER,
                taken_at TEXT,
                description TEXT,
                tags TEXT,
                text_in_image TEXT,
                indexed_at INTEGER,
                status TEXT NOT NULL DEFAULT 'pending',
                error TEXT,
                exported_at INTEGER,
                export_error TEXT
            )
        SQL);
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS worker_status (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                active INTEGER NOT NULL DEFAULT 0,
                last_heartbeat INTEGER,
                last_log TEXT,
                last_processed INTEGER DEFAULT 0,
                last_errors INTEGER DEFAULT 0,
                last_launch_attempt INTEGER DEFAULT 0,
                launch_count_since_alive INTEGER DEFAULT 0
            )
        SQL);
        $pdo->exec("INSERT OR IGNORE INTO worker_status (id, active) VALUES (1, 0)");
        $wsCols = $pdo->query("PRAGMA table_info(worker_status)")->fetchAll(PDO::FETCH_ASSOC);
        $wsColNames = array_column($wsCols, 'name');
        if (!in_array('last_launch_attempt', $wsColNames, true)) {
            $pdo->exec('ALTER TABLE worker_status ADD COLUMN last_launch_attempt INTEGER DEFAULT 0');
        }
        if (!in_array('launch_count_since_alive', $wsColNames, true)) {
            $pdo->exec('ALTER TABLE worker_status ADD COLUMN launch_count_since_alive INTEGER DEFAULT 0');
        }

        $existingCols = $pdo->query("PRAGMA table_info(images)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($existingCols, 'name');
        if (!in_array('exported_at', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN exported_at INTEGER');
        }
        if (!in_array('export_error', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN export_error TEXT');
        }
        if (!in_array('faces', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN faces TEXT');
        }
        if (!in_array('description_manual', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN description_manual INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('error_acknowledged', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN error_acknowledged INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('export_error_acknowledged', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN export_error_acknowledged INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('lat', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN lat REAL');
        }
        if (!in_array('lon', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN lon REAL');
        }
        if (!in_array('gps_alt', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN gps_alt REAL');
        }
        // Which AI backend + model wrote the description ("anthropic:claude-haiku-5-5",
        // "local:<model>"...). NULL = described before 1.0.3, model not recorded.
        if (!in_array('described_by', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN described_by TEXT');
        }
        // Queued to be described again by the current model. The photo stays 'ok' (searchable,
        // counted as indexed) with its old description until the new one replaces it.
        if (!in_array('redescribe', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN redescribe INTEGER NOT NULL DEFAULT 0');
        }
        // A year taken from the photo's folder ("...\2005\DSC02954.jpg"), used to place photos
        // without a camera date in date order. '' = none; NULL = not worked out yet.
        if (!in_array('folder_year', $colNames, true)) {
            $pdo->exec('ALTER TABLE images ADD COLUMN folder_year TEXT');
        }
        $todo = $pdo->query('SELECT id, path FROM images WHERE folder_year IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        if ($todo) {
            $pdo->beginTransaction();
            $upd = $pdo->prepare('UPDATE images SET folder_year = :y WHERE id = :id');
            foreach ($todo as $r) $upd->execute([':y' => self::folderYear($r['path']), ':id' => $r['id']]);
            $pdo->commit();
        }
        // ---- Clean up (lib/Cleanup.php): measurements from each photo's thumbnail ----
        foreach ([
            'sharpness' => 'REAL', 'sharpness_coarse' => 'REAL', 'lum_mean' => 'REAL', 'lum_std' => 'REAL',
            'lum_high' => 'REAL', 'dark_frac' => 'REAL', 'bright_frac' => 'REAL', 'dhash' => 'TEXT',
            'dhash_v' => 'TEXT', 'analyzed_at' => 'INTEGER', 'cleanup_keep' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $type) {
            if (!in_array($col, $colNames, true)) {
                $pdo->exec("ALTER TABLE images ADD COLUMN {$col} {$type}");
            }
        }
        // ---- end Clean up ----
        // taken_at already exists in initial schema (top of file) — just make sure
        // there's an index so future map/timeline views are fast.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_images_taken_at ON images(taken_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_images_latlon  ON images(lat, lon) WHERE lat IS NOT NULL');
        // FTS5 doesn't support ALTER TABLE ADD COLUMN; if our FTS table is missing
        // any of the searchable columns we want, drop and recreate it, then repopulate.
        $ftsCols = $pdo->query("PRAGMA table_info(images_fts)")->fetchAll(PDO::FETCH_ASSOC);
        $ftsColNames = array_column($ftsCols, 'name');
        $required = ['description', 'tags', 'text_in_image', 'faces', 'filename'];
        $missing = array_diff($required, $ftsColNames);
        if (count($ftsColNames) > 0 && $missing) {
            $pdo->exec('DROP TABLE IF EXISTS images_fts');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_images_status ON images(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_images_mtime ON images(mtime)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_images_exported_at ON images(exported_at)');
        $pdo->exec(<<<SQL
            CREATE VIRTUAL TABLE IF NOT EXISTS images_fts USING fts5(
                id UNINDEXED,
                description,
                tags,
                text_in_image,
                faces,
                filename,
                tokenize = 'porter unicode61'
            )
        SQL);
        // If FTS was just (re)created empty, repopulate from existing images rows.
        // For filename, we tokenize on path separators / underscores / digits so
        // a filename like "IMG_0228.JPG" becomes searchable as "img", "0228", "img_0228", "jpg".
        $needsReindex = (int)$pdo->query("SELECT COUNT(*) FROM images_fts")->fetchColumn() === 0
                     && (int)$pdo->query("SELECT COUNT(*) FROM images WHERE status='ok'")->fetchColumn() > 0;
        if ($needsReindex) {
            $rows = $pdo->query("SELECT id, COALESCE(description,'') d, COALESCE(tags,'') t,
                                        COALESCE(text_in_image,'') tx, COALESCE(faces,'') f, path
                                 FROM images WHERE status='ok'")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO images_fts (id, description, tags, text_in_image, faces, filename)
                 VALUES (:id, :d, :t, :tx, :f, :fn)'
            );
            foreach ($rows as $r) {
                $stmt->execute([
                    ':id' => $r['id'], ':d' => $r['d'], ':t' => $r['t'],
                    ':tx' => $r['tx'], ':f' => $r['f'],
                    ':fn' => self::filenameTokens($r['path']),
                ]);
            }
            $pdo->commit();
        }
    }

    /** Tokenize a filename + parent folder name for FTS5 search. Splits on
     *  separators / digits / non-word chars so a name like "IMG_0228.JPG" in
     *  folder "Camera Roll/2021" becomes "IMG_0228.JPG Camera Roll 2021 IMG 0228 JPG". */
    public static function filenameTokens(string $path): string {
        $base = basename($path);
        $parent = basename(dirname($path));
        $stem = pathinfo($base, PATHINFO_FILENAME);
        $ext = pathinfo($base, PATHINFO_EXTENSION);
        // Also split on _ - . and CamelCase boundaries
        $split = preg_split('/[_\-\. ]+|(?<=[a-z])(?=[A-Z])/', $stem) ?: [];
        $pieces = array_merge([$base, $stem, $parent, $ext], $split);
        return implode(' ', array_filter(array_unique(array_map('strval', $pieces))));
    }

    /** Scan is additive: NEW paths are inserted as pending; existing paths get
     *  their size/mtime refreshed but status/description are NOT touched.
     *
     *  Rationale: anything that writes metadata into a file (our own embed step,
     *  digiKam writing face XMP, OneDrive resyncs) nudges the file size or mtime,
     *  and we don't want a routine scan to re-bill Claude for a photo just
     *  because metadata moved. To re-index a photo, use the explicit paths:
     *  Reset errors, Reset to AI (per-photo), or Clear index.
     *
     *  Returns true when the row was newly inserted, false when it already existed. */
    public static function upsertPending(string $id, string $path, int $size, int $mtime): bool {
        $pdo = self::get();
        $existing = $pdo->prepare('SELECT 1 FROM images WHERE path = :path');
        $existing->execute([':path' => $path]);
        $found = $existing->fetchColumn();
        // End the read before writing. A still-open read can't be upgraded to a write once
        // another process (the worker's 2-second heartbeat) has written, and SQLite then
        // fails with "database is locked" at once instead of waiting.
        $existing->closeCursor();
        if ($found) {
            $pdo->prepare('UPDATE images SET size = :size, mtime = :mtime WHERE path = :path')
                ->execute([':size' => $size, ':mtime' => $mtime, ':path' => $path]);
            return false;
        }
        $pdo->prepare(
            "INSERT INTO images (id, path, size, mtime, status, folder_year)
             VALUES (:id, :path, :size, :mtime, 'pending', :fy)"
        )->execute([':id' => $id, ':path' => $path, ':size' => $size, ':mtime' => $mtime, ':fy' => self::folderYear($path)]);
        return true;
    }

    /** The year of the nearest folder named like one ("2005", "2012 Vacation", "1998-trip"), or ''. */
    public static function folderYear(string $path): string {
        $dirs = explode('/', str_replace('\\', '/', dirname($path)));
        for ($i = count($dirs) - 1; $i >= 0; $i--) {
            if (preg_match('/^(19[5-9]\d|20[0-9]\d)(?:$|[ _\-.])/', $dirs[$i], $m)) return $m[1];
        }
        return '';
    }

    public static function markIndexed(string $id, array $analysis, int $width, int $height, ?string $describedBy = null): void {
        $pdo = self::get();
        $pdo->beginTransaction();
        try {
            $tags = isset($analysis['tags']) && is_array($analysis['tags'])
                ? implode(', ', array_map('strval', $analysis['tags']))
                : '';
            $description = (string)($analysis['description'] ?? '');
            $textInImage = (string)($analysis['text_in_image'] ?? '');
            // Face names come from the photo's own metadata (read at scan time), not from the
            // AI. Keep them unless this analysis brings its own, so re-describing a photo never
            // wipes them.
            $facesNew = array_key_exists('faces', $analysis)
                ? (is_array($analysis['faces']) ? implode(', ', array_map('strval', $analysis['faces'])) : (string)$analysis['faces'])
                : null;
            $u = $pdo->prepare(
                'UPDATE images SET description = :d, tags = :t, text_in_image = :tx, faces = COALESCE(:f, faces),
                 width = :w, height = :h, indexed_at = :ia, status = \'ok\', error = NULL, redescribe = 0,
                 described_by = COALESCE(:by, described_by)
                 WHERE id = :id'
            );
            $u->execute([
                ':d' => $description,
                ':t' => $tags,
                ':tx' => $textInImage,
                ':f' => $facesNew,
                ':w' => $width,
                ':h' => $height,
                ':ia' => time(),
                ':by' => $describedBy,
                ':id' => $id,
            ]);
            $fq = $pdo->prepare('SELECT faces FROM images WHERE id = :id');
            $fq->execute([':id' => $id]);
            $faces = (string)$fq->fetchColumn();
            $pdo->prepare('DELETE FROM images_fts WHERE id = :id')->execute([':id' => $id]);
            $pathRow = $pdo->prepare('SELECT path FROM images WHERE id = :id');
            $pathRow->execute([':id' => $id]);
            $imgPath = (string)$pathRow->fetchColumn();
            $filename = self::filenameTokens($imgPath);
            $pdo->prepare(
                'INSERT INTO images_fts (id, description, tags, text_in_image, faces, filename)
                 VALUES (:id, :d, :t, :tx, :f, :fn)'
            )->execute([':id' => $id, ':d' => $description, ':t' => $tags, ':tx' => $textInImage, ':f' => $faces, ':fn' => $filename]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** User-edited metadata: description, tags, faces, text_in_image.
     *  Marks the row as manually edited so the worker won't overwrite on re-index.
     *  Pass null for any field to leave it unchanged. Pass empty string to clear. */
    public static function updateMetadata(string $id, ?string $description, ?string $tags, ?string $faces, ?string $textInImage): void {
        $pdo = self::get();
        $pdo->beginTransaction();
        try {
            $sets = ['description_manual = 1'];
            $params = [':id' => $id];
            if ($description !== null) { $sets[] = 'description = :d';  $params[':d']  = $description; }
            if ($tags        !== null) { $sets[] = 'tags = :t';         $params[':t']  = $tags; }
            if ($faces       !== null) { $sets[] = 'faces = :f';        $params[':f']  = $faces; }
            if ($textInImage !== null) { $sets[] = 'text_in_image = :x'; $params[':x'] = $textInImage; }
            $pdo->prepare('UPDATE images SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

            $pathRow = $pdo->prepare('SELECT path FROM images WHERE id = :id');
            $pathRow->execute([':id' => $id]);
            $imgPath = (string)$pathRow->fetchColumn();
            $pdo->prepare('DELETE FROM images_fts WHERE id = :id')->execute([':id' => $id]);
            $pdo->prepare(
                "INSERT INTO images_fts (id, description, tags, text_in_image, faces, filename)
                 SELECT id, COALESCE(description,''), COALESCE(tags,''),
                        COALESCE(text_in_image,''), COALESCE(faces,''), :fn
                 FROM images WHERE id = :id"
            )->execute([':id' => $id, ':fn' => self::filenameTokens($imgPath)]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Clear the manual flag so the worker re-indexes the photo next time. */
    public static function resetManualDescription(string $id): void {
        self::get()->prepare(
            "UPDATE images SET description_manual = 0, status = 'pending' WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public static function updateExif(string $id, ?float $lat, ?float $lon, ?float $alt, ?string $takenAt): void {
        $sets = [];
        $params = [':id' => $id];
        if ($lat !== null)        { $sets[] = 'lat = :lat';      $params[':lat'] = $lat; }
        if ($lon !== null)        { $sets[] = 'lon = :lon';      $params[':lon'] = $lon; }
        if ($alt !== null)        { $sets[] = 'gps_alt = :alt';  $params[':alt'] = $alt; }
        if ($takenAt !== null && $takenAt !== '') {
            $sets[] = 'taken_at = :ta'; $params[':ta'] = $takenAt;
        }
        if (!$sets) return;
        self::get()->prepare('UPDATE images SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
    }

    public static function updateFaces(string $id, array $faces): void {
        $pdo = self::get();
        $facesStr = implode(', ', array_map('strval', $faces));
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE images SET faces = :f WHERE id = :id')
                ->execute([':f' => $facesStr, ':id' => $id]);
            // Rebuild this row's FTS entry using current image columns
            $pdo->prepare('DELETE FROM images_fts WHERE id = :id')->execute([':id' => $id]);
            $pathRow = $pdo->prepare('SELECT path FROM images WHERE id = :id');
            $pathRow->execute([':id' => $id]);
            $imgPath = (string)$pathRow->fetchColumn();
            $pdo->prepare(
                "INSERT INTO images_fts (id, description, tags, text_in_image, faces, filename)
                 SELECT id, COALESCE(description,''), COALESCE(tags,''),
                        COALESCE(text_in_image,''), COALESCE(faces,''), :fn
                 FROM images WHERE id = :id"
            )->execute([':id' => $id, ':fn' => self::filenameTokens($imgPath)]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** A photo queued only to be described again failed: keep its old description (and
     *  searchability), drop it from the queue, and record why. */
    public static function markRedescribeFailed(string $id, string $error): void {
        self::get()->prepare(
            'UPDATE images SET redescribe = 0, error = :e WHERE id = :id'
        )->execute([':e' => substr('Re-describe failed: ' . $error, 0, 500), ':id' => $id]);
    }

    public static function markError(string $id, string $error): void {
        self::get()->prepare(
            'UPDATE images SET status = \'error\', error = :e, indexed_at = :ia WHERE id = :id'
        )->execute([':e' => substr($error, 0, 500), ':ia' => time(), ':id' => $id]);
    }

    public static function stats(): array {
        $pdo = self::get();
        $row = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS indexed,
                SUM(CASE WHEN status = 'pending' OR (status = 'ok' AND COALESCE(redescribe, 0) = 1) THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'error' AND COALESCE(error_acknowledged, 0) = 0 THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN status = 'error' AND COALESCE(error_acknowledged, 0) = 1 THEN 1 ELSE 0 END) AS errors_acknowledged,
                SUM(CASE WHEN status = 'ok' AND exported_at IS NOT NULL AND (indexed_at IS NULL OR exported_at >= indexed_at) THEN 1 ELSE 0 END) AS exported,
                SUM(CASE WHEN status = 'ok' AND (exported_at IS NULL OR exported_at < indexed_at) AND (export_error IS NULL OR export_error = '') THEN 1 ELSE 0 END) AS export_pending,
                SUM(CASE WHEN export_error IS NOT NULL AND export_error != '' AND COALESCE(export_error_acknowledged, 0) = 0 THEN 1 ELSE 0 END) AS export_errors,
                SUM(CASE WHEN export_error IS NOT NULL AND export_error != '' AND COALESCE(export_error_acknowledged, 0) = 1 THEN 1 ELSE 0 END) AS export_errors_acknowledged,
                SUM(CASE WHEN lat IS NOT NULL AND lon IS NOT NULL THEN 1 ELSE 0 END) AS with_location,
                SUM(CASE WHEN taken_at IS NOT NULL AND taken_at != '' THEN 1 ELSE 0 END) AS with_date
             FROM images"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'indexed' => (int)($row['indexed'] ?? 0),
            'pending' => (int)($row['pending'] ?? 0),
            'errors' => (int)($row['errors'] ?? 0),
            'errors_acknowledged' => (int)($row['errors_acknowledged'] ?? 0),
            'exported' => (int)($row['exported'] ?? 0),
            'export_pending' => (int)($row['export_pending'] ?? 0),
            'export_errors' => (int)($row['export_errors'] ?? 0),
            'export_errors_acknowledged' => (int)($row['export_errors_acknowledged'] ?? 0),
            'with_location' => (int)($row['with_location'] ?? 0),
            'with_date' => (int)($row['with_date'] ?? 0),
        ];
    }

    public static function nextExportPending(int $limit): array {
        $stmt = self::get()->prepare(
            "SELECT id, path, description, tags, text_in_image FROM images
             WHERE status = 'ok'
               AND (exported_at IS NULL OR exported_at < indexed_at)
               AND (export_error IS NULL OR export_error = '')
             ORDER BY rowid ASC LIMIT :n"
        );
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function markExported(string $id): void {
        self::get()->prepare(
            'UPDATE images SET exported_at = :t, export_error = NULL WHERE id = :id'
        )->execute([':t' => time(), ':id' => $id]);
    }

    public static function markExportError(string $id, string $error): void {
        self::get()->prepare(
            'UPDATE images SET export_error = :e WHERE id = :id'
        )->execute([':e' => substr($error, 0, 500), ':id' => $id]);
    }

    public static function resetExportErrors(): int {
        return self::get()->exec("UPDATE images SET export_error = NULL WHERE export_error IS NOT NULL");
    }

    public static function workerStatus(): array {
        $row = self::get()->query('SELECT active, last_heartbeat, last_log, last_processed, last_errors FROM worker_status WHERE id = 1')
            ->fetch(PDO::FETCH_ASSOC) ?: [];
        $hb = (int)($row['last_heartbeat'] ?? 0);
        // Threshold is generous because batches can take 30+ seconds with no chance to heartbeat.
        $alive = $hb > 0 && (time() - $hb) < 90;
        return [
            'active' => (int)($row['active'] ?? 0),
            'alive' => $alive,
            'last_heartbeat' => $hb,
            'seconds_since_heartbeat' => $hb > 0 ? (time() - $hb) : null,
            'last_log' => (string)($row['last_log'] ?? ''),
            'last_processed' => (int)($row['last_processed'] ?? 0),
            'last_errors' => (int)($row['last_errors'] ?? 0),
        ];
    }

    public static function workerSetActive(int $active): void {
        self::get()->prepare('UPDATE worker_status SET active = :a WHERE id = 1')
            ->execute([':a' => $active ? 1 : 0]);
    }

    public static function workerHeartbeat(string $log = '', int $processed = 0, int $errors = 0): void {
        // Reset the launch-attempt counter when we see a real heartbeat — proves the worker is up.
        self::get()->prepare(
            'UPDATE worker_status SET last_heartbeat = :t, last_log = COALESCE(NULLIF(:l, \'\'), last_log),
             last_processed = last_processed + :p, last_errors = last_errors + :e,
             launch_count_since_alive = 0 WHERE id = 1'
        )->execute([':t' => time(), ':l' => $log, ':p' => $processed, ':e' => $errors]);
    }

    /** Watchdog: if the worker should be running (active=1) but isn't heartbeating,
     *  attempt to relaunch it via the PhotoFinderWorker scheduled task.
     *  Rate-limited so we don't hammer schtasks. Gives up after 3 attempts without
     *  a successful heartbeat (something's persistently wrong; manual intervention needed).
     *  Returns ['attempted' => bool, 'reason' => string]. */
    public static function workerWatchdog(): array {
        require_once __DIR__ . '/util.php';
        $row = self::get()->query(
            'SELECT active, last_heartbeat, last_launch_attempt, launch_count_since_alive
             FROM worker_status WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $active = (int)($row['active'] ?? 0);
        $hb = (int)($row['last_heartbeat'] ?? 0);
        $lastLaunch = (int)($row['last_launch_attempt'] ?? 0);
        $launchCount = (int)($row['launch_count_since_alive'] ?? 0);
        $now = time();
        $secsSinceHb = $hb > 0 ? $now - $hb : PHP_INT_MAX;
        $secsSinceLaunch = $lastLaunch > 0 ? $now - $lastLaunch : PHP_INT_MAX;

        if (!$active) return ['attempted' => false, 'reason' => 'worker not active'];
        if ($secsSinceHb < 90) return ['attempted' => false, 'reason' => 'worker is alive'];
        if ($secsSinceLaunch < 60) return ['attempted' => false, 'reason' => 'recently attempted; backing off'];
        if ($launchCount >= 3) return ['attempted' => false, 'reason' => 'gave up after 3 launches without heartbeat'];

        // Fire the scheduled task
        $cmd = ['C:\\Windows\\System32\\schtasks.exe', '/run', '/tn', 'PhotoFinderWorker'];
        [$exit, $stdout, $stderr] = pf_run_cmd($cmd);
        $ok = ($exit === 0);

        self::get()->prepare(
            'UPDATE worker_status SET last_launch_attempt = :t,
             launch_count_since_alive = launch_count_since_alive + 1,
             last_log = :log WHERE id = 1'
        )->execute([
            ':t' => $now,
            ':log' => $ok ? 'watchdog relaunched worker' : 'watchdog relaunch FAILED: ' . trim($stderr ?: $stdout),
        ]);
        return [
            'attempted' => true,
            'reason' => $ok ? 'launched via schtasks' : 'schtasks failed: ' . trim($stderr ?: $stdout),
            'attempt' => $launchCount + 1,
        ];
    }

    public static function workerResetCounters(): void {
        self::get()->exec('UPDATE worker_status SET last_processed = 0, last_errors = 0 WHERE id = 1');
    }

    public static function nextPending(int $limit): array {
        // Skip rows the user has manually edited — they don't want re-indexing.
        $stmt = self::get()->prepare(
            "SELECT id, path, status FROM images
             WHERE (status = 'pending' OR (status = 'ok' AND COALESCE(redescribe, 0) = 1))
               AND description_manual = 0
             ORDER BY (status = 'ok') ASC, rowid ASC LIMIT :n"
        );
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById(string $id): ?array {
        $stmt = self::get()->prepare('SELECT * FROM images WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Photos matching $query (all photos when empty), one page at a time.
     *  $sort: 'new' (date taken, newest first; the default), 'old', 'folder' (by folder, then
     *         file name) or 'relevance' (best match first; only meaningful with a query).
     * Each row carries `sort_date`: the camera date, else the year of its folder ("2005"),
     * else null. Photos without either come last.
     */
    public static function search(string $query, int $limit = 100, int $offset = 0, string $sort = 'new'): array {
        $query = trim($query);
        $date = "COALESCE(NULLIF(i.taken_at, ''), NULLIF(i.folder_year, ''))";
        $cols = "i.id, i.path, i.description, i.tags, i.faces, i.width, i.height, i.lat, i.lon, i.taken_at, {$date} AS sort_date";
        $order = match ($sort) {
            'old'       => "({$date} IS NULL), {$date} ASC, i.path",
            'folder'    => 'i.path',
            'relevance' => $query !== '' ? 'bm25(images_fts)' : "({$date} IS NULL), {$date} DESC, i.path",
            default     => "({$date} IS NULL), {$date} DESC, i.path",
        };
        $page = function (PDOStatement $stmt) use ($limit, $offset): array {
            $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        if ($query === '') {
            return $page(self::get()->prepare("SELECT {$cols} FROM images i WHERE i.status = 'ok' ORDER BY {$order} LIMIT :n OFFSET :o"));
        }
        try {
            $stmt = self::get()->prepare(
                "SELECT {$cols}, snippet(images_fts, 1, '<mark>', '</mark>', '...', 12) AS snip
                 FROM images_fts JOIN images i ON i.id = images_fts.id
                 WHERE images_fts MATCH :q AND i.status = 'ok'
                 ORDER BY {$order} LIMIT :n OFFSET :o"
            );
            $stmt->bindValue(':q', self::buildFtsQuery($query));
            return $page($stmt);
        } catch (PDOException) {
            $order = str_replace('bm25(images_fts)', "({$date} IS NULL), {$date} DESC, i.path", $order);
            $stmt = self::get()->prepare(
                "SELECT {$cols} FROM images i
                 WHERE i.status = 'ok' AND (i.description LIKE :q OR i.tags LIKE :q OR i.text_in_image LIKE :q OR i.faces LIKE :q)
                 ORDER BY {$order} LIMIT :n OFFSET :o"
            );
            $stmt->bindValue(':q', '%' . $query . '%');
            return $page($stmt);
        }
    }

    private static function buildFtsQuery(string $q): string {
        $q = trim($q);
        if ($q === '') return '""';

        // Honor quoted phrases as-is: "red car" → "red car"
        // Honor explicit AND / OR / NOT operators
        // Honor user-supplied wildcards (word*)
        // Honor +word -word for required / excluded terms
        // Default: each plain word becomes a prefix match (word*)
        // For everything else, strip dangerous characters that would break FTS5.
        $pieces = [];
        $i = 0;
        $len = strlen($q);
        while ($i < $len) {
            if ($q[$i] === ' ' || $q[$i] === "\t") { $i++; continue; }
            if ($q[$i] === '"') {
                $end = strpos($q, '"', $i + 1);
                if ($end === false) {
                    // Unclosed quote — take the rest as a phrase
                    $phrase = substr($q, $i + 1);
                    $i = $len;
                } else {
                    $phrase = substr($q, $i + 1, $end - $i - 1);
                    $i = $end + 1;
                }
                $phrase = trim($phrase);
                if ($phrase !== '') $pieces[] = '"' . $phrase . '"';
                continue;
            }
            // Grab the next whitespace-delimited token
            $j = $i;
            while ($j < $len && $q[$j] !== ' ' && $q[$j] !== "\t") $j++;
            $tok = substr($q, $i, $j - $i);
            $i = $j;

            $upper = strtoupper($tok);
            if (in_array($upper, ['AND', 'OR', 'NOT', 'NEAR'], true)) {
                $pieces[] = $upper;
                continue;
            }
            // + or - prefix → keep the operator, process the rest
            $op = '';
            if ($tok !== '' && ($tok[0] === '+' || $tok[0] === '-')) {
                $op = $tok[0];
                $tok = substr($tok, 1);
            }
            // Preserve user-supplied wildcard
            $userWildcard = str_ends_with($tok, '*');
            if ($userWildcard) $tok = substr($tok, 0, -1);
            // Sanitize: keep letters, digits, underscore (FTS5 token chars)
            $clean = preg_replace('/[^\p{L}\p{N}_]+/u', '', $tok);
            if ($clean === '' || $clean === null) continue;
            // Default to prefix match
            $piece = '"' . $clean . '"*';
            $pieces[] = $op . $piece;
        }
        return implode(' ', $pieces) ?: '""';
    }

    public static function purgeMissingFiles(): array {
        $pdo = self::get();
        $rows = $pdo->query('SELECT id, path FROM images')->fetchAll(PDO::FETCH_ASSOC);
        $missing = [];
        foreach ($rows as $r) {
            if (!is_file($r['path'])) $missing[] = $r['id'];
        }
        if (!$missing) return ['removed' => 0, 'scanned' => count($rows)];
        $pdo->beginTransaction();
        foreach (array_chunk($missing, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $pdo->prepare("DELETE FROM images_fts WHERE id IN ($ph)")->execute($chunk);
            $pdo->prepare("DELETE FROM images WHERE id IN ($ph)")->execute($chunk);
        }
        $pdo->commit();
        return ['removed' => count($missing), 'scanned' => count($rows)];
    }

    /** Indexed photos per describing model ('' = before 1.0.3, model unknown). */
    public static function describedByCounts(): array {
        $rows = self::get()->query(
            "SELECT COALESCE(described_by, '') AS m, COUNT(*) AS n FROM images
             WHERE status = 'ok' AND COALESCE(description_manual, 0) = 0 AND COALESCE(redescribe, 0) = 0
             GROUP BY COALESCE(described_by, '') ORDER BY n DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(string)$r['m']] = (int)$r['n'];
        return $out;
    }

    public static function redescribeQueued(): int {
        return (int)self::get()->query("SELECT COUNT(*) FROM images WHERE status = 'ok' AND COALESCE(redescribe, 0) = 1")->fetchColumn();
    }

    /** Queue every photo described by a model other than $label (hand-written ones are kept). */
    public static function queueOtherModels(string $label): int {
        $st = self::get()->prepare(
            "UPDATE images SET redescribe = 1, error = NULL
             WHERE status = 'ok' AND COALESCE(description_manual, 0) = 0 AND COALESCE(redescribe, 0) = 0
               AND COALESCE(described_by, '') != :label"
        );
        $st->execute([':label' => $label]);
        return $st->rowCount();
    }

    public static function resetErrors(): int {
        // A photo with a hand-written description is never re-analyzed (listPending skips it),
        // so re-queueing it would leave it "pending" forever. Mark it done instead.
        return self::get()->exec(
            "UPDATE images SET
                status = CASE WHEN COALESCE(description_manual, 0) = 1 AND TRIM(COALESCE(description, '')) != ''
                              THEN 'ok' ELSE 'pending' END,
                error = NULL, error_acknowledged = 0
             WHERE status = 'error'"
        );
    }

    public static function listPending(int $limit = 500): array {
        $stmt = self::get()->prepare(
            "SELECT id, path, size, mtime, COALESCE(description_manual, 0) AS description_manual
             FROM images
             WHERE status = 'pending'
             ORDER BY rowid ASC
             LIMIT :n"
        );
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function listErrors(int $limit = 500): array {
        $stmt = self::get()->prepare(
            "SELECT id, path, error, indexed_at, COALESCE(error_acknowledged, 0) AS acknowledged
             FROM images
             WHERE status = 'error'
             ORDER BY COALESCE(error_acknowledged, 0) ASC, indexed_at DESC
             LIMIT :n"
        );
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function listExportErrors(int $limit = 500): array {
        $stmt = self::get()->prepare(
            "SELECT id, path, export_error, COALESCE(export_error_acknowledged, 0) AS acknowledged
             FROM images
             WHERE export_error IS NOT NULL AND export_error != ''
             ORDER BY COALESCE(export_error_acknowledged, 0) ASC, rowid DESC
             LIMIT :n"
        );
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function acknowledgeError(string $id, string $type): void {
        if ($type === 'export') {
            self::get()->prepare('UPDATE images SET export_error_acknowledged = 1 WHERE id = :id')
                ->execute([':id' => $id]);
        } else {
            self::get()->prepare("UPDATE images SET error_acknowledged = 1 WHERE id = :id AND status = 'error'")
                ->execute([':id' => $id]);
        }
    }

    public static function retryError(string $id, string $type): void {
        if ($type === 'export') {
            self::get()->prepare(
                'UPDATE images SET export_error = NULL, export_error_acknowledged = 0 WHERE id = :id'
            )->execute([':id' => $id]);
        } else {
            self::get()->prepare(
                "UPDATE images SET status = 'pending', error = NULL, error_acknowledged = 0 WHERE id = :id"
            )->execute([':id' => $id]);
        }
    }

    public static function acknowledgeAllErrors(string $type): int {
        if ($type === 'export') {
            return self::get()->exec(
                "UPDATE images SET export_error_acknowledged = 1
                 WHERE export_error IS NOT NULL AND export_error != ''
                   AND COALESCE(export_error_acknowledged, 0) = 0"
            );
        }
        return self::get()->exec(
            "UPDATE images SET error_acknowledged = 1
             WHERE status = 'error' AND COALESCE(error_acknowledged, 0) = 0"
        );
    }

    public static function deleteImage(string $id): void {
        $pdo = self::get();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM images_fts WHERE id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM images WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Remove many rows (and their FTS entries) from the index in one transaction.
     *  Does not touch files on disk — the caller handles that. */
    public static function deleteImages(array $ids): void {
        $ids = array_values(array_filter(array_map('strval', $ids), fn($i) => $i !== ''));
        if (!$ids) return;
        $pdo = self::get();
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($ids, 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $pdo->prepare("DELETE FROM images_fts WHERE id IN ($ph)")->execute($chunk);
                $pdo->prepare("DELETE FROM images WHERE id IN ($ph)")->execute($chunk);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function clearAll(): void {
        $pdo = self::get();
        $pdo->exec('DELETE FROM images');
        $pdo->exec('DELETE FROM images_fts');
    }
}
