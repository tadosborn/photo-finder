<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Claude.php';
require_once __DIR__ . '/ClaudeCli.php';
require_once __DIR__ . '/Thumb.php';
require_once __DIR__ . '/Faces.php';
require_once __DIR__ . '/Exif.php';

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
        $backend = (string)($settings['backend'] ?? 'api');
        $maxDim = (int)$settings['max_image_dim'];

        if ($backend === 'cli') {
            $configured = (string)($settings['cli_path'] ?? '');
            $cliPath = ClaudeCli::resolvePath($configured);
            if ($cliPath === null) {
                return ['processed' => 0, 'errors' => 0,
                    'message' => 'Claude CLI path not configured or not found. Open Settings and click Auto-detect.'];
            }
            if ($cliPath !== $configured) {
                // The saved path went stale (VS Code removes the old extension folder on
                // update). Remember the current one so Settings shows what is really used.
                $saved = pf_load_settings();
                $saved['cli_path'] = $cliPath;
                pf_save_settings($saved);
                pf_log('Claude CLI path updated: ' . $cliPath);
            }
            $client = new ClaudeCli($cliPath, (string)($settings['cli_model'] ?? 'haiku'));
        } else {
            $apiKey = (string)($settings['api_key'] ?? '');
            if ($apiKey === '') {
                return ['processed' => 0, 'errors' => 0, 'message' => 'No API key configured.'];
            }
            $client = new Claude($apiKey, (string)$settings['model']);
        }

        $items = Db::nextPending($limit);
        $processed = 0;
        $errors = 0;
        $messages = [];

        if ($backend === 'cli' && count($items) > 0) {
            $usable = [];
            foreach ($items as $item) {
                if (!is_file($item['path'])) {
                    Db::markError($item['id'], 'File missing: ' . $item['path']);
                    pf_log('  ✗ ' . basename($item['path']) . ': file missing');
                    $errors++;
                    continue;
                }
                $usable[] = $item;
            }
            if ($usable) {
                $paths = array_column($usable, 'path');
                $names = array_map('basename', $paths);
                // Pull GPS + capture date for the whole batch in one ExifTool call.
                // Doing it up front means errored items still get location/date.
                $exifMap = Exif::extractBatch($paths);
                foreach ($usable as $item) {
                    $e = $exifMap[$item['path']] ?? null;
                    if ($e) Db::updateExif($item['id'], $e['lat'], $e['lon'], $e['alt'], $e['taken_at']);
                }
                $withGps = 0;
                foreach ($exifMap as $e) if ($e['lat'] !== null) $withGps++;
                pf_log('  EXIF: ' . count($exifMap) . '/' . count($paths) . ' have date, ' . $withGps . ' have GPS');
                pf_log('→ analyzing ' . count($paths) . ': ' . implode(', ', $names));
                $t0 = microtime(true);
                try {
                    $analyses = $client->analyzeImages($paths);
                } catch (ClaudeParseException $e) {
                    // The CLI answered but nothing in the reply could be used.
                    // Leave $analyses empty so every photo gets its own retry below.
                    pf_log('✗ batch reply unusable after ' . round(microtime(true) - $t0, 1) . 's; retrying each photo on its own');
                    $analyses = [];
                } catch (Throwable $e) {
                    pf_log('✗ batch FAILED after ' . round(microtime(true) - $t0, 1) . 's: ' . $e->getMessage());
                    foreach ($usable as $item) {
                        Db::markError($item['id'], 'Batch failed: ' . $e->getMessage());
                        $errors++;
                    }
                    $messages[] = 'Batch error: ' . $e->getMessage();
                    return ['processed' => $processed, 'errors' => $errors, 'messages' => $messages];
                }
                $wall = microtime(true) - $t0;
                $env  = ($client instanceof ClaudeCli) ? ($client->lastEnvelope ?? []) : [];
                $api  = isset($env['duration_api_ms']) ? ((int)$env['duration_api_ms']) / 1000 : null;
                $cliTotal = isset($env['duration_ms']) ? ((int)$env['duration_ms']) / 1000 : null;
                $tokensIn  = $env['usage']['input_tokens']  ?? null;
                $tokensOut = $env['usage']['output_tokens'] ?? null;
                $turns     = $env['num_turns'] ?? null;
                $bits = [sprintf('%.1fs wall', $wall)];
                if ($cliTotal !== null) $bits[] = sprintf('%.1fs cli', $cliTotal);
                if ($api !== null)      $bits[] = sprintf('%.1fs api', $api);
                if ($turns !== null)    $bits[] = $turns . ' turn' . ($turns == 1 ? '' : 's');
                if ($tokensIn !== null && $tokensOut !== null) {
                    $bits[] = $tokensIn . ' in / ' . $tokensOut . ' out tokens';
                    $bits[] = sprintf('%.0f tokens/photo', ($tokensIn + $tokensOut) / max(1, count($paths)));
                }
                pf_log('← batch: ' . implode(' · ', $bits));
                foreach ($usable as $item) {
                    $analysis = $analyses[$item['path']] ?? null;
                    $base = basename($item['path']);
                    if (!self::hasDescription($analysis)) {
                        // The batch reply skipped this photo, named the wrong path, or left it
                        // blank. Asking about it on its own recovers nearly all of these.
                        try {
                            $analysis = $client->analyzeImage($item['path']);
                        } catch (Throwable $e) {
                            Db::markError($item['id'], 'Retry on its own failed: ' . $e->getMessage());
                            pf_log('  ✗ ' . $base . ': retry failed: ' . $e->getMessage());
                            $errors++;
                            $messages[] = $base . ': ' . $e->getMessage();
                            continue;
                        }
                        if (!self::hasDescription($analysis)) {
                            Db::markError($item['id'], 'Claude returned no description, even when asked about this photo on its own');
                            pf_log('  ✗ ' . $base . ': no description, even on its own');
                            $errors++;
                            continue;
                        }
                        pf_log('  ↻ ' . $base . ': recovered by asking on its own');
                    }
                    try {
                        $info = @getimagesize($item['path']) ?: [0, 0];
                        $analysis['faces'] = $exifMap[$item['path']]['faces'] ?? [];
                        Db::markIndexed($item['id'], $analysis, (int)$info[0], (int)$info[1]);
                        Thumb::ensureThumb($item['id'], $item['path']);
                        $desc = (string)($analysis['description'] ?? '');
                        if (mb_strlen($desc) > 100) $desc = mb_substr($desc, 0, 100) . '…';
                        pf_log('  ✓ ' . $base . ': ' . $desc);
                        self::logVerboseAnalysis($analysis, $exifMap[$item['path']] ?? null, (int)$info[0], (int)$info[1]);
                        $processed++;
                    } catch (Throwable $e) {
                        Db::markError($item['id'], $e->getMessage());
                        pf_log('  ✗ ' . $base . ': ' . $e->getMessage());
                        $errors++;
                        $messages[] = $base . ': ' . $e->getMessage();
                    }
                }
            }
            return ['processed' => $processed, 'errors' => $errors, 'messages' => $messages];
        }

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
                pf_log('→ analyzing ' . $base);
                $t0 = microtime(true);
                $prepped = Thumb::prepareForApi($path, $maxDim);
                $analysis = $client->analyzeImage($prepped['bytes']);
                Db::markIndexed($id, $analysis, $prepped['width'], $prepped['height']);
                $e = Exif::extractBatch([$path])[$path] ?? null;
                if ($e) Db::updateExif($id, $e['lat'], $e['lon'], $e['alt'], $e['taken_at']);
                Thumb::ensureThumb($id, $path);
                $desc = (string)($analysis['description'] ?? '');
                if (mb_strlen($desc) > 100) $desc = mb_substr($desc, 0, 100) . '…';
                pf_log('  ✓ ' . $base . ' (' . round(microtime(true) - $t0, 1) . 's): ' . $desc);
                self::logVerboseAnalysis($analysis, $e, $prepped['width'], $prepped['height']);
                $processed++;
            } catch (Throwable $e) {
                Db::markError($id, $e->getMessage());
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
