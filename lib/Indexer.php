<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Describer.php';
require_once __DIR__ . '/Thumb.php';
require_once __DIR__ . '/Faces.php';
require_once __DIR__ . '/Exif.php';
require_once __DIR__ . '/Cleanup.php';

final class Indexer {
    public static function scan(array $dirs, array $excludedDirs = []): array {
        $found = 0;
        $newFiles = 0;
        $skippedDirs = [];
        $excludedHits = 0;
        $metaGps = 0; $metaDate = 0; $metaFaces = 0;
        $metaBuffer = []; // path => id; flushed in chunks of 50 to ExifTool
        // Normalize excluded names: lowercase, trimmed, slash-stripped.
        $excludedSet = [];
        foreach ($excludedDirs as $e) {
            $e = strtolower(trim((string)$e, " \t\n\r\0/\\"));
            if ($e !== '') $excludedSet[$e] = true;
        }

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                $skippedDirs[] = $dir;
                continue;
            }
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iter as $fileInfo) {
                if (!$fileInfo->isFile()) continue;
                $path = $fileInfo->getPathname();
                if (!pf_is_image_file($path)) continue;
                // Skip if any segment of the path is in the exclude set
                if ($excludedSet) {
                    $segments = preg_split('#[\\\\/]+#', $path) ?: [];
                    $isExcluded = false;
                    foreach ($segments as $seg) {
                        if (isset($excludedSet[strtolower($seg)])) { $isExcluded = true; break; }
                    }
                    if ($isExcluded) { $excludedHits++; continue; }
                }
                $found++;
                $abs = pf_norm_path(realpath($path) ?: $path);
                $id = pf_path_id($abs);
                if (Db::upsertPending($id, $abs, $fileInfo->getSize(), $fileInfo->getMTime())) {
                    $newFiles++;
                }
                $metaBuffer[$abs] = $id;
                if (count($metaBuffer) >= 50) {
                    $r = self::flushMetaBuffer($metaBuffer);
                    $metaGps += $r['gps']; $metaDate += $r['date']; $metaFaces += $r['faces'];
                    $metaBuffer = [];
                }
            }
        }
        if ($metaBuffer) {
            $r = self::flushMetaBuffer($metaBuffer);
            $metaGps += $r['gps']; $metaDate += $r['date']; $metaFaces += $r['faces'];
        }
        return [
            'found' => $found,
            'new_files' => $newFiles,
            'new_or_changed' => $newFiles, // legacy alias for older UI builds
            'skipped_dirs' => $skippedDirs,
            'excluded_hits' => $excludedHits,
            'meta_gps' => $metaGps,
            'meta_date' => $metaDate,
            'meta_faces' => $metaFaces,
        ];
    }

    /** Verbose per-photo lines: tags + faces + OCR (line 1), and date + GPS + dims (line 2).
     *  Each line is skipped if it would be empty, to keep the log scannable. */
    private static function logVerboseAnalysis(array $analysis, ?array $exif, int $w, int $h): void {
        $tags = isset($analysis['tags']) && is_array($analysis['tags']) ? $analysis['tags'] : [];
        $faces = $analysis['faces'] ?? [];
        if (!is_array($faces)) $faces = $faces ? [(string)$faces] : [];
        $ocr = trim((string)($analysis['text_in_image'] ?? ''));

        $line1 = [];
        if ($tags)  $line1[] = 'tags: ' . implode(', ', array_slice($tags, 0, 12))
                              . (count($tags) > 12 ? ' (+' . (count($tags) - 12) . ')' : '');
        if ($faces) $line1[] = 'people: ' . implode(', ', $faces);
        if ($ocr !== '') {
            $shortOcr = mb_strlen($ocr) > 80 ? mb_substr($ocr, 0, 80) . '…' : $ocr;
            $line1[] = 'OCR: "' . $shortOcr . '"';
        }
        if ($line1) pf_log('      ' . implode(' · ', $line1));

        $line2 = [];
        if (!empty($exif['taken_at'])) $line2[] = '📅 ' . str_replace('T', ' ', (string)$exif['taken_at']);
        if (!empty($exif['lat']) && !empty($exif['lon'])) {
            $line2[] = sprintf('📍 %.4f, %.4f', (float)$exif['lat'], (float)$exif['lon']);
        }
        if ($w > 0 && $h > 0) $line2[] = sprintf('📐 %d×%d', $w, $h);
        if ($line2) pf_log('      ' . implode(' · ', $line2));
    }

    /** Refresh GPS / capture date / face names for the given [path => id] map,
     *  via a single ExifTool batch call. Updates the DB in place.
     *  Never touches description/tags/status — Claude is NOT called here. */
    private static function flushMetaBuffer(array $idByPath): array {
        if (!$idByPath) return ['gps' => 0, 'date' => 0, 'faces' => 0];
        $gps = 0; $date = 0; $faces = 0;
        $exif = Exif::extractBatch(array_keys($idByPath));
        foreach ($exif as $path => $e) {
            $id = $idByPath[$path] ?? null;
            if (!$id) continue;
            if ($e['lat'] !== null || $e['alt'] !== null || $e['taken_at']) {
                Db::updateExif($id, $e['lat'], $e['lon'], $e['alt'], $e['taken_at']);
                if ($e['lat'] !== null) $gps++;
                if ($e['taken_at']) $date++;
            }
            // Only overwrite faces when the file actually has named regions —
            // an empty result must not clobber manually-entered names from the editor.
            if (!empty($e['faces'])) {
                Db::updateFaces($id, $e['faces']);
                $faces++;
            }
        }
        return ['gps' => $gps, 'date' => $date, 'faces' => $faces];
    }

    /** Remove already-indexed photos that match the current excluded_dirs.
     *  Returns the number of rows removed. */
    public static function purgeExcluded(array $excludedDirs): int {
        if (!$excludedDirs) return 0;
        $excludedSet = [];
        foreach ($excludedDirs as $e) {
            $e = strtolower(trim((string)$e, " \t\n\r\0/\\"));
            if ($e !== '') $excludedSet[$e] = true;
        }
        if (!$excludedSet) return 0;

        $pdo = Db::get();
        $toDelete = [];
        $rows = $pdo->query('SELECT id, path FROM images')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            foreach (preg_split('#[\\\\/]+#', $r['path']) ?: [] as $seg) {
                if (isset($excludedSet[strtolower($seg)])) {
                    $toDelete[] = $r['id'];
                    break;
                }
            }
        }
        if (!$toDelete) return 0;

        $pdo->beginTransaction();
        $chunks = array_chunk($toDelete, 200);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $pdo->prepare("DELETE FROM images_fts WHERE id IN ($placeholders)")->execute($chunk);
            $pdo->prepare("DELETE FROM images WHERE id IN ($placeholders)")->execute($chunk);
        }
        $pdo->commit();
        return count($toDelete);
    }

    private static function hasDescription(?array $analysis): bool {
        return $analysis !== null && trim((string)($analysis['description'] ?? '')) !== '';
    }

    public static function processBatch(array $settings, int $limit): array {
        [$client, $why] = Describer::fromSettings($settings);
        if ($client === null) {
            return ['processed' => 0, 'errors' => 0, 'message' => $why, 'needs_setup' => true];
        }
        $maxDim = (int)$settings['max_image_dim'];
        $label = $client->label();

        $items = Db::nextPending($limit);
        $processed = 0;
        $errors = 0;
        $messages = [];

        foreach ($items as $item) {
            $id = $item['id'];
            $path = $item['path'];
            $base = basename($path);
            try {
                if (!is_file($path)) {
                    Db::markError($id, 'File missing: ' . $path);
                    pf_log('  ✗ ' . $base . ': file missing');
                    $errors++;
                    continue;
                }
                pf_log('→ analyzing ' . $base . ' with ' . $label);
                $t0 = microtime(true);
                $prepped = Thumb::prepareForApi($path, $maxDim);
                $analysis = $client->analyzeImage($prepped['bytes']);
                if (trim((string)($analysis['description'] ?? '')) === '') {
                    throw new RuntimeException('The AI returned an empty description.');
                }
                // The photo's own size, not the (smaller) copy the AI was sent: Clean up's Small
                // list, its best-copy choice and Enlarge go by it.
                Db::markIndexed($id, $analysis, $prepped['full_width'], $prepped['full_height'], $label);
                $e = Exif::extractBatch([$path])[$path] ?? null;
                if ($e) Db::updateExif($id, $e['lat'], $e['lon'], $e['alt'], $e['taken_at']);
                Thumb::ensureThumb($id, $path);
                // Clean up: measure sharpness, exposure and the duplicate hashes from the new
                // thumbnail (local, no AI). It must never fail the photo.
                try {
                    Cleanup::analyzeAndStore($id);
                } catch (Throwable $ce) {
                    pf_log('  (clean-up analysis skipped for ' . $base . ': ' . $ce->getMessage() . ')');
                }
                $desc = (string)($analysis['description'] ?? '');
                if (mb_strlen($desc) > 100) $desc = mb_substr($desc, 0, 100) . '…';
                pf_log('  ✓ ' . $base . ' (' . round(microtime(true) - $t0, 1) . 's): ' . $desc);
                self::logVerboseAnalysis($analysis, $e, $prepped['width'], $prepped['height']);
                $processed++;
            } catch (Throwable $e) {
                if (($item['status'] ?? '') === 'ok') {
                    Db::markRedescribeFailed($id, $e->getMessage());   // keeps the old description
                } else {
                    Db::markError($id, $e->getMessage());
                }
                pf_log('  ✗ ' . $base . ': ' . $e->getMessage());
                $errors++;
                $messages[] = $base . ': ' . $e->getMessage();
            }
        }
        return [
            'processed' => $processed,
            'errors' => $errors,
            'messages' => $messages,
        ];
    }
}
