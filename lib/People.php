<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Deblur.php';
require_once __DIR__ . '/Exif.php';
require_once __DIR__ . '/Exporter.php';

/**
 * People: who is in each photo, found and compared on this PC.
 *
 * faces.py (the on-PC runtime Fix blur uses, plus OpenCV's YuNet and SFace face models) finds the
 * faces in every photo and turns each into 128 numbers; faces of one person have numbers that point
 * the same way. Nothing about a face leaves this PC: cloud AI services do not recognise people, by
 * their own rules, and are never asked to.
 *
 * The user teaches it: the photos' own named face regions (digiKam, Picasa, Windows Photo Gallery,
 * Lightroom) are taken as confirmed; "Who is this?" offers groups of unnamed faces that look like one
 * person; each person's page suggests faces that look like them, and only the user confirms. A
 * photo's confirmed names join its People line and search (Db::refreshFaces), are given to the
 * describing AI so its description names them (Db::peopleForPrompt), and Embed metadata writes them
 * into the file as people and face regions (embedData).
 *
 * Faces live in Db's face table (box 0..1 of the upright photo); pictures of each face in
 * PF_DATA/people/faces. A photo is scanned once (images.faces_scanned_at); replacing it with a fix
 * or rotating it scans it again, keeping who each face is (by where it is).
 */
final class People {
    /** SFace cosine likeness: OpenCV's same-person threshold. Measured on 157 named faces of 14
     *  people in the maintainer's photos (2026-11-27): 94% of same-person pairs reach it and 0.5% of
     *  different-person pairs do; a face's best match was the right person 148 times in 149. */
    public const SUGGEST_MIN = 0.363;
    /** Suggestions this alike are ticked in advance (different people: 0.06% of pairs). */
    public const SUGGEST_TICK = 0.45;
    /** "Who is this?" groups: faces this alike to the group's seed and to its average face (faces.py clusters). */
    public const GROUP_LINK = 0.50;
    private const CHUNK = 60;

    public static function dir(): string { return PF_DATA . '/people'; }
    public static function thumbsDir(): string { return self::dir() . '/faces'; }
    private static function scanFile(): string { return self::dir() . '/scan.json'; }
    private static function cancelFile(): string { return self::dir() . '/scan.cancel'; }

    // ------------------------------------------------------------------ status and setup

    public static function status(): array {
        $pdo = Db::get();
        $q = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
        $setup = Deblur::setupStatus();
        return [
            'ready' => Deblur::isFacesReady(),
            'download_bytes' => Deblur::isFacesReady() ? 0 : Deblur::setupBytes('faces'),
            'setup' => $setup && ($setup['which'] ?? '') === 'faces' ? $setup : null,
            'setup_busy' => $setup && empty($setup['done']) && empty($setup['error']) && ($setup['which'] ?? '') !== 'faces',
            'scan' => self::scanStatus(),
            'photos' => $q("SELECT COUNT(*) FROM images WHERE status = 'ok' AND gallery_hidden = 0"),
            'to_scan' => self::toScan(),
            'faces' => $q('SELECT COUNT(*) FROM face'),
            'named' => $q('SELECT COUNT(*) FROM face WHERE person_id IS NOT NULL'),
            'unnamed' => $q('SELECT COUNT(*) FROM face WHERE person_id IS NULL AND ignored = 0'),
            'people' => $q('SELECT COUNT(*) FROM person'),
            'redescribe' => Db::redescribeQueued(),
        ];
    }

    private static function toScan(): int {
        return (int)Db::get()->query("SELECT COUNT(*) FROM images WHERE status = 'ok' AND gallery_hidden = 0 AND faces_scanned_at IS NULL")->fetchColumn();
    }

    public static function scanStatus(): ?array {
        $s = json_decode((string)@file_get_contents(self::scanFile()), true);
        if (!is_array($s)) return null;
        if (in_array($s['state'] ?? '', ['starting', 'running'], true) && ($s['updated'] ?? 0) < time() - 300) {
            $s['state'] = 'error';
            $s['error'] = 'Finding faces stopped. Start it again; it carries on where it left off.';
        }
        return $s;
    }

    private static function scanRunning(): bool {
        return in_array(self::scanStatus()['state'] ?? '', ['starting', 'running'], true);
    }

    // ------------------------------------------------------------------ finding faces

    /** Start finding faces in the photos not scanned yet (in the background, at low priority). */
    public static function scanStart(): array {
        if (!Deblur::isFacesReady()) throw new RuntimeException('Face recognition is not set up yet.');
        if (self::scanRunning()) return self::scanStatus() ?? [];
        $n = self::toScan();
        if ($n === 0) return ['state' => 'done', 'done' => 0, 'total' => 0, 'faces' => 0];
        @unlink(self::cancelFile());
        $s = ['state' => 'starting', 'done' => 0, 'total' => $n, 'faces' => 0, 'named' => 0, 'errors' => 0, 'started' => time(), 'updated' => time()];
        Deblur::writeJson(self::scanFile(), $s);
        Deblur::launch(Deblur::phpExe(), [Deblur::win(PF_ROOT . '/faces-job.php'), Deblur::win(PF_DATA)]);
        return $s;
    }

    public static function scanCancel(): void {
        if (!is_dir(self::dir())) @mkdir(self::dir(), 0777, true);
        @touch(self::cancelFile());
    }

    /** Runs in faces-job.php: the unscanned photos, CHUNK at a time, until none are left. */
    public static function runScan(): void {
        $s = self::scanStatus() ?? [];
        $s = ['state' => 'running', 'done' => 0, 'total' => self::toScan(), 'faces' => 0, 'named' => 0, 'errors' => 0,
              'started' => (int)($s['started'] ?? time()), 'updated' => time()];
        $save = function () use (&$s): void { $s['updated'] = time(); Deblur::writeJson(self::scanFile(), $s); };
        $save();
        $work = self::dir() . '/scan';
        if (!is_dir($work)) @mkdir($work, 0777, true);
        try {
            while (true) {
                if (is_file(self::cancelFile())) { $s['state'] = 'cancelled'; break; }
                $rows = Db::get()->query("SELECT id, path FROM images WHERE status = 'ok' AND gallery_hidden = 0 AND faces_scanned_at IS NULL
                                          ORDER BY rowid LIMIT " . self::CHUNK)->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) { $s['state'] = 'done'; break; }
                $todo = [];
                $tag = base_convert((string)time(), 10, 36);
                foreach ($rows as $r) {
                    if (is_file((string)$r['path'])) $todo[] = ['id' => (string)$r['id'], 'path' => pf_norm_path((string)$r['path']), 'tag' => $tag];
                    else { self::importPhoto((string)$r['id'], [], []); $s['done']++; }
                }
                if (!$todo) { $save(); continue; }
                $base = $s['done'];
                $found = self::detect($todo, $work, function (int $done) use (&$s, $save, $base): bool {
                    $s['done'] = $base + $done;   // photo by photo, for the progress bar
                    if (time() - $s['updated'] >= 2) $save();
                    return !is_file(self::cancelFile());
                });
                $s['done'] = $base;
                $regions = self::metadataRegions(array_column($todo, 'path'));
                foreach ($todo as $i => $t) {
                    if (!array_key_exists($t['id'], $found)) {
                        // faces.py stopped before this photo: the first one it never answered is
                        // the one that stopped it, so it is passed over; the rest are tried again.
                        if ($found !== [] && $i === count($found)) { self::importPhoto($t['id'], [], []); $s['errors']++; $s['done']++; }
                        continue;
                    }
                    $r = $found[$t['id']];
                    if (isset($r['error'])) $s['errors']++;
                    $s['named'] += self::importPhoto($t['id'], (array)($r['faces'] ?? []), $regions[strtolower($t['path'])] ?? []);
                    $s['faces'] += count((array)($r['faces'] ?? []));
                    $s['done']++;
                }
                if ($found === []) throw new RuntimeException('The face finder stopped without an answer (see ' . $work . '\\python-stderr.txt).');
                $save();
            }
        } catch (PeopleCancelled) {
            $s['state'] = 'cancelled';
        } catch (Throwable $e) {
            $s['state'] = 'error';
            $s['error'] = $e->getMessage();
        }
        $save();
    }

    /** faces.py detect over $todo: [id => ['faces' => [...]] | ['error' => '...']]. */
    private static function detect(array $todo, string $work, callable $tick): array {
        $job = $work . '/job.json';
        $out = $work . '/out.jsonl';
        $state = $work . '/python.json';
        $err = $work . '/python-stderr.txt';
        file_put_contents($job, json_encode($todo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @unlink($out);
        @unlink($state);
        $cmd = array_map([Deblur::class, 'win'], [Deblur::python(), PF_ROOT . '/faces.py', 'detect', Deblur::facesDir(), $job, $out, self::thumbsDir(), $state]);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $err . '.out', 'w'], 2 => ['file', $err, 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) throw new RuntimeException('Could not start the on-PC AI.');
        fclose($pipes[0]);
        while (proc_get_status($proc)['running']) {
            $p = json_decode((string)@file_get_contents($state), true) ?: [];
            if (!$tick((int)($p['done'] ?? 0))) {
                proc_terminate($proc);
                proc_close($proc);
                throw new PeopleCancelled();
            }
            usleep(400000);
        }
        proc_close($proc);
        $found = [];
        foreach (@file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && isset($r['id'])) $found[(string)$r['id']] = $r;
        }
        return $found;
    }

    /** The named face regions photos $paths carry (MWG, as digiKam / Lightroom / Picasa write; and
     *  Microsoft's, as Windows Photo Gallery wrote): [lower-case path => [['name', 'stored' => box
     *  in the file's stored frame, 'cands' => [box as the photo shows]]]]. Programs differ in
     *  whether a rotated photo's regions are measured as stored or as shown, so both are tried. */
    private static function metadataRegions(array $paths): array {
        $tool = Exif::toolPath() ?? Exporter::autoDetect();
        if ($tool === null || !$paths) return [];
        $argFile = self::dir() . '/scan/regions-args.txt';
        file_put_contents($argFile, implode("\n", array_merge(['-j', '-n', '-struct', '-RegionInfo', '-RegionInfoMP', '-Orientation'], $paths)) . "\n");
        [, $stdout] = pf_run_cmd([$tool, '-charset', 'filename=UTF8', '-@', $argFile]);
        @unlink($argFile);
        $out = [];
        foreach ((array)json_decode((string)$stdout, true) as $row) {
            if (!is_array($row) || !isset($row['SourceFile'])) continue;
            $o = (int)($row['Orientation'] ?? 1);
            $regs = [];
            foreach ((array)($row['RegionInfo']['RegionList'] ?? []) as $g) {
                $a = $g['Area'] ?? null;
                $name = self::cleanName((string)($g['Name'] ?? ''));
                if ($name === '' || !is_array($a) || ($g['Type'] ?? 'Face') !== 'Face') continue;
                $box = [(float)$a['X'] - (float)$a['W'] / 2, (float)$a['Y'] - (float)$a['H'] / 2, (float)$a['W'], (float)$a['H']];
                $regs[] = ['name' => $name, 'stored' => $box, 'cands' => [self::toUpright($box, $o), $box]];
            }
            foreach ((array)($row['RegionInfoMP']['Regions'] ?? []) as $g) {
                $name = self::cleanName((string)($g['PersonDisplayName'] ?? ''));
                $r = array_map('floatval', explode(',', (string)($g['Rectangle'] ?? '')));
                if ($name === '' || count($r) !== 4) continue;
                $regs[] = ['name' => $name, 'stored' => $r, 'cands' => [self::toUpright($r, $o), $r]];
            }
            if ($regs) $out[strtolower(pf_norm_path((string)$row['SourceFile']))] = $regs;
        }
        return $out;
    }

    /** A box (x, y, w, h, 0..1) in a photo's stored frame, as the photo shows (EXIF Orientation). */
    public static function toUpright(array $b, int $o): array {
        [$x, $y, $w, $h] = $b;
        return match ($o) {
            6 => [1 - $y - $h, $x, $h, $w],
            8 => [$y, 1 - $x - $w, $h, $w],
            3 => [1 - $x - $w, 1 - $y - $h, $w, $h],
            default => [$x, $y, $w, $h],
        };
    }

    /** The other way: a box as the photo shows, in its stored frame (what face regions use). */
    public static function toStored(array $b, int $o): array {
        [$x, $y, $w, $h] = $b;
        return match ($o) {
            6 => [$y, 1 - $x - $w, $h, $w],
            8 => [1 - $y - $h, $x, $h, $w],
            3 => [1 - $x - $w, 1 - $y - $h, $w, $h],
            default => [$x, $y, $w, $h],
        };
    }

    private static function iou(array $a, array $b): float {
        $x1 = max($a[0], $b[0]); $y1 = max($a[1], $b[1]);
        $x2 = min($a[0] + $a[2], $b[0] + $b[2]); $y2 = min($a[1] + $a[3], $b[1] + $b[3]);
        $i = max(0.0, $x2 - $x1) * max(0.0, $y2 - $y1);
        $u = $a[2] * $a[3] + $b[2] * $b[3] - $i;
        return $u > 0 ? $i / $u : 0.0;
    }

    /**
     * Store photo $id's faces from faces.py, replacing earlier ones: who each earlier face was (and
     * "not this person" marks) carries over to the new face in the same place, then the photo's own
     * named face regions name the faces they cover. Returns how many faces got a name from them.
     */
    public static function importPhoto(string $id, array $faces, array $regions): int {
        $pdo = Db::get();
        $old = $pdo->prepare('SELECT id, x, y, w, h, person_id, ignored, source, confirmed_at, thumb FROM face WHERE image_id = :id');
        $old->execute([':id' => $id]);
        $old = $old->fetchAll(PDO::FETCH_ASSOC);
        $seeded = 0;
        $named = false;
        $pdo->beginTransaction();
        try {
            $rej = [];
            foreach ($old as $o) {
                $q = $pdo->prepare('SELECT person_id FROM face_reject WHERE face_id = :f');
                $q->execute([':f' => $o['id']]);
                $rej[$o['id']] = $q->fetchAll(PDO::FETCH_COLUMN);
            }
            $ins = $pdo->prepare('INSERT INTO face (image_id, n, x, y, w, h, score, px, emb, thumb) VALUES (:i, :n, :x, :y, :w, :h, :s, :px, :e, :t)');
            $new = [];
            foreach (array_values($faces) as $n => $f) {
                $emb = base64_decode((string)($f['emb'] ?? ''), true);
                $ins->bindValue(':i', $id);
                $ins->bindValue(':n', $n, PDO::PARAM_INT);
                foreach (['x', 'y', 'w', 'h'] as $k) $ins->bindValue(':' . $k, (float)$f[$k]);
                $ins->bindValue(':s', (float)($f['score'] ?? 0));
                $ins->bindValue(':px', (int)($f['px'] ?? 0), PDO::PARAM_INT);
                $ins->bindValue(':e', $emb !== false && strlen($emb) === 512 ? $emb : null, $emb !== false && strlen($emb) === 512 ? PDO::PARAM_LOB : PDO::PARAM_NULL);
                $ins->bindValue(':t', (string)($f['thumb'] ?? ''));
                $ins->execute();
                $new[] = ['id' => (int)$pdo->lastInsertId(), 'box' => [(float)$f['x'], (float)$f['y'], (float)$f['w'], (float)$f['h']], 'person' => null];
            }
            $set = $pdo->prepare('UPDATE face SET person_id = :p, ignored = :ig, source = :src, confirmed_at = :c WHERE id = :id');
            $addRej = $pdo->prepare('INSERT OR IGNORE INTO face_reject (face_id, person_id) VALUES (:f, :p)');
            // Who each earlier face was, onto the new face in the same place. A face the user named,
            // ignored or said "not P" to that is not found this time (a photo rotated so faces lie
            // sideways, say) is kept as it was, so nothing the user taught is lost; turned back,
            // the next scan finds it again in the same place.
            $drop = [];
            $kept = false;
            foreach ($old as $o) {
                if ($o['person_id'] === null && !(int)$o['ignored'] && !$rej[$o['id']]) { $drop[] = (int)$o['id']; continue; }
                $best = -1; $bv = 0.3;
                foreach ($new as $k => $nf) {
                    $v = self::iou([(float)$o['x'], (float)$o['y'], (float)$o['w'], (float)$o['h']], $nf['box']);
                    if ($v >= $bv && $nf['person'] === null) { $best = $k; $bv = $v; }
                }
                if ($best < 0) { $kept = $kept || $o['person_id'] !== null; continue; }
                $set->execute([':p' => $o['person_id'], ':ig' => (int)$o['ignored'], ':src' => $o['source'], ':c' => $o['confirmed_at'], ':id' => $new[$best]['id']]);
                $new[$best]['person'] = $o['person_id'] ?? 0;
                foreach ($rej[$o['id']] as $p) $addRej->execute([':f' => $new[$best]['id'], ':p' => $p]);
                $drop[] = (int)$o['id'];
            }
            if ($drop) {
                $ph = implode(',', $drop);
                $pdo->exec("DELETE FROM face_reject WHERE face_id IN ($ph)");
                $pdo->exec("DELETE FROM face WHERE id IN ($ph)");
            }
            // The photo's own named face regions.
            $extra = [];
            foreach ($regions as $g) {
                $best = -1; $bv = 0.25;
                foreach ($new as $k => $nf) {
                    foreach ($g['cands'] as $c) {
                        $v = self::iou($c, $nf['box']);
                        if ($v >= $bv) { $best = $k; $bv = $v; }
                    }
                }
                $pid = self::ensurePerson($g['name']);
                if ($best >= 0 && ($new[$best]['person'] ?? null) === null) {
                    $set->execute([':p' => $pid, ':ig' => 0, ':src' => 'metadata', ':c' => time(), ':id' => $new[$best]['id']]);
                    $new[$best]['person'] = $pid;
                    $seeded++;
                } elseif ($best < 0) {
                    // Not a face found here (too small, turned away): kept, so Embed metadata writes it back.
                    $extra[mb_strtolower($g['name'])] = ['name' => $g['name'], 'box' => $g['stored']];
                }
            }
            $pdo->prepare('UPDATE images SET faces_scanned_at = :t, regions_extra = :x WHERE id = :id')
                ->execute([':t' => time(), ':x' => $extra ? json_encode(array_values($extra)) : null, ':id' => $id]);
            $pdo->commit();
            $named = $kept || (bool)array_filter($new, static fn($nf) => !empty($nf['person']));
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        // Pictures of the earlier faces that are gone.
        $keep = array_flip(array_map(static fn($f) => (string)($f['thumb'] ?? ''), $faces));
        $dropped = array_flip($drop);
        foreach ($old as $o) {
            if ($o['thumb'] && isset($dropped[(int)$o['id']]) && !isset($keep[$o['thumb']])) @unlink(self::thumbsDir() . '/' . basename((string)$o['thumb']));
        }
        if ($named || $old) Db::refreshFaces($id);
        if ($seeded) self::namesChanged([$id]);
        return $seeded;
    }

    /** Photo $id will show new pixels (a fix replaced it): find its faces again, keeping who they are. */
    public static function invalidate(string $id): void {
        Db::get()->prepare('UPDATE images SET faces_scanned_at = NULL WHERE id = :id')->execute([':id' => $id]);
    }

    /** Photo $id was turned a quarter ('cw' or 'ccw'): its faces turn with it, then are found again. */
    public static function rotated(string $id, string $dir): void {
        $pdo = Db::get();
        $st = $pdo->prepare('SELECT id, x, y, w, h FROM face WHERE image_id = :id');
        $st->execute([':id' => $id]);
        $up = $pdo->prepare('UPDATE face SET x = :x, y = :y, w = :w, h = :h WHERE id = :id');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            [$x, $y, $w, $h] = [(float)$f['x'], (float)$f['y'], (float)$f['w'], (float)$f['h']];
            $b = $dir === 'cw' ? [1 - $y - $h, $x, $h, $w] : [$y, 1 - $x - $w, $h, $w];
            $up->execute([':x' => $b[0], ':y' => $b[1], ':w' => $b[2], ':h' => $b[3], ':id' => $f['id']]);
        }
        self::invalidate($id);
    }

    // ------------------------------------------------------------------ naming

    /** A name as people are listed: one line, no commas (the People line separates names with them). */
    public static function cleanName(string $n): string {
        $n = trim(preg_replace('/[\s,;]+/u', ' ', $n) ?? '');
        return mb_substr($n, 0, 80);
    }

    public static function ensurePerson(string $name): int {
        $name = self::cleanName($name);
        if ($name === '') throw new InvalidArgumentException('Type a name.');
        $pdo = Db::get();
        $st = $pdo->prepare('SELECT id FROM person WHERE name = :n COLLATE NOCASE');
        $st->execute([':n' => $name]);
        $id = $st->fetchColumn();
        if ($id !== false) return (int)$id;
        $pdo->prepare('INSERT INTO person (name, created_at) VALUES (:n, :t)')->execute([':n' => $name, ':t' => time()]);
        return (int)$pdo->lastInsertId();
    }

    private static function faceIds(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
    }

    /** The photos faces $ids are in. */
    private static function imagesOf(array $faceIds): array {
        if (!$faceIds) return [];
        $out = [];
        foreach (array_chunk($faceIds, 500) as $c) {
            $st = Db::get()->prepare('SELECT DISTINCT image_id FROM face WHERE id IN (' . implode(',', array_fill(0, count($c), '?')) . ')');
            $st->execute($c);
            $out = array_merge($out, $st->fetchAll(PDO::FETCH_COLUMN));
        }
        return array_values(array_unique($out));
    }

    private static function imagesOfPerson(int $pid): array {
        $st = Db::get()->prepare('SELECT DISTINCT image_id FROM face WHERE person_id = :p');
        $st->execute([':p' => $pid]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Photos whose names changed: People line and search now, a new description (unless the user
     *  wrote it) and Embed metadata again. */
    public static function namesChanged(array $imageIds): void {
        $pdo = Db::get();
        foreach (array_chunk(array_values(array_unique($imageIds)), 300) as $c) {
            foreach ($c as $id) Db::refreshFaces((string)$id);
            $ph = implode(',', array_fill(0, count($c), '?'));
            $pdo->prepare("UPDATE images SET redescribe = 1, error = NULL WHERE id IN ($ph) AND status = 'ok' AND COALESCE(description_manual, 0) = 0")->execute($c);
            $pdo->prepare("UPDATE images SET exported_at = NULL, export_error = NULL WHERE id IN ($ph)")->execute($c);
        }
    }

    /** Faces $faceIds are person $personId, or a person called $name (made if new). */
    public static function assign(array $faceIds, ?int $personId, ?string $name): array {
        $faceIds = self::faceIds($faceIds);
        if (!$faceIds) throw new InvalidArgumentException('No faces chosen.');
        $pid = $personId ?: self::ensurePerson((string)$name);
        if (!self::personRow($pid)) throw new InvalidArgumentException('That person is not in the list.');
        $pdo = Db::get();
        $before = self::imagesOf($faceIds);
        foreach (array_chunk($faceIds, 500) as $c) {
            $ph = implode(',', array_fill(0, count($c), '?'));
            $pdo->prepare("UPDATE face SET person_id = ?, ignored = 0, source = 'user', confirmed_at = ? WHERE id IN ($ph)")->execute(array_merge([$pid, time()], $c));
            $pdo->prepare("DELETE FROM face_reject WHERE person_id = ? AND face_id IN ($ph)")->execute(array_merge([$pid], $c));
        }
        self::namesChanged($before);
        return self::personRow($pid) ?? [];
    }

    /** Faces $faceIds are not who they were confirmed as (and are not suggested as them again). */
    public static function unassign(array $faceIds): void {
        $faceIds = self::faceIds($faceIds);
        $pdo = Db::get();
        $imgs = self::imagesOf($faceIds);
        foreach ($faceIds as $f) {
            $p = $pdo->prepare('SELECT person_id FROM face WHERE id = :f');
            $p->execute([':f' => $f]);
            $pid = $p->fetchColumn();
            if ($pid) $pdo->prepare('INSERT OR IGNORE INTO face_reject (face_id, person_id) VALUES (:f, :p)')->execute([':f' => $f, ':p' => $pid]);
            $pdo->prepare('UPDATE face SET person_id = NULL, source = NULL, confirmed_at = NULL WHERE id = :f')->execute([':f' => $f]);
        }
        self::namesChanged($imgs);
    }

    /** Faces $faceIds are not person $personId: not suggested as them again. */
    public static function reject(array $faceIds, int $personId): void {
        $st = Db::get()->prepare('INSERT OR IGNORE INTO face_reject (face_id, person_id) VALUES (:f, :p)');
        foreach (self::faceIds($faceIds) as $f) $st->execute([':f' => $f, ':p' => $personId]);
    }

    /** Faces $faceIds are not someone to name (strangers, a poster, not a face): out of the groups. */
    public static function ignore(array $faceIds, bool $on = true): void {
        $faceIds = self::faceIds($faceIds);
        $named = [];
        foreach (array_chunk($faceIds, 500) as $c) {
            $ph = implode(',', array_fill(0, count($c), '?'));
            $st = Db::get()->prepare("SELECT DISTINCT image_id FROM face WHERE person_id IS NOT NULL AND id IN ($ph)");
            $st->execute($c);
            $named = array_merge($named, $st->fetchAll(PDO::FETCH_COLUMN));
            Db::get()->prepare('UPDATE face SET ignored = ?' . ($on ? ', person_id = NULL, source = NULL, confirmed_at = NULL' : '') . " WHERE id IN ($ph)")
                ->execute(array_merge([$on ? 1 : 0], $c));
        }
        if ($named) self::namesChanged($named);
    }

    /** Rename person $id; to a name someone else has = the same person: merged. */
    public static function rename(int $id, string $name): array {
        $name = self::cleanName($name);
        if ($name === '') throw new InvalidArgumentException('Type a name.');
        $st = Db::get()->prepare('SELECT id FROM person WHERE name = :n COLLATE NOCASE AND id <> :id');
        $st->execute([':n' => $name, ':id' => $id]);
        $other = $st->fetchColumn();
        if ($other !== false) return self::merge($id, (int)$other);
        Db::get()->prepare('UPDATE person SET name = :n WHERE id = :id')->execute([':n' => $name, ':id' => $id]);
        self::namesChanged(self::imagesOfPerson($id));
        return self::personRow($id) ?? [];
    }

    /** Person $from is person $into: their faces join, $from goes. */
    public static function merge(int $from, int $into): array {
        if ($from === $into || !self::personRow($from) || !self::personRow($into)) throw new InvalidArgumentException('Choose two different people.');
        $pdo = Db::get();
        $imgs = self::imagesOfPerson($from);
        $pdo->prepare('UPDATE face SET person_id = :i WHERE person_id = :f')->execute([':i' => $into, ':f' => $from]);
        $pdo->prepare('UPDATE OR IGNORE face_reject SET person_id = :i WHERE person_id = :f')->execute([':i' => $into, ':f' => $from]);
        $pdo->prepare('DELETE FROM face_reject WHERE person_id = :f')->execute([':f' => $from]);
        $pdo->prepare('DELETE FROM person WHERE id = :f')->execute([':f' => $from]);
        self::namesChanged($imgs);
        return self::personRow($into) ?? [];
    }

    /** Take person $id off the list: their faces are unnamed again. */
    public static function deletePerson(int $id): void {
        $pdo = Db::get();
        $imgs = self::imagesOfPerson($id);
        $pdo->prepare('UPDATE face SET person_id = NULL, source = NULL, confirmed_at = NULL WHERE person_id = :p')->execute([':p' => $id]);
        $pdo->prepare('DELETE FROM face_reject WHERE person_id = :p')->execute([':p' => $id]);
        $pdo->prepare('DELETE FROM person WHERE id = :p')->execute([':p' => $id]);
        self::namesChanged($imgs);
    }

    /** Forget everything about faces: found faces, people, their pictures. Photos are scanned again
     *  only if asked. The names photos carry in their own metadata stay on their People line. */
    public static function forget(): void {
        $pdo = Db::get();
        $imgs = $pdo->query('SELECT DISTINCT image_id FROM face WHERE person_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('DELETE FROM face_reject');
        $pdo->exec('DELETE FROM face');
        $pdo->exec('DELETE FROM person');
        $pdo->exec('UPDATE images SET faces_scanned_at = NULL, regions_extra = NULL');
        foreach (glob(self::thumbsDir() . '/*.jpg') ?: [] as $t) @unlink($t);
        @unlink(self::dir() . '/groups.json');
        self::namesChanged($imgs);
    }

    // ------------------------------------------------------------------ what the People window shows

    public static function personRow(int $id): ?array {
        $st = Db::get()->prepare('SELECT p.id, p.name, COUNT(f.id) AS faces, COUNT(DISTINCT f.image_id) AS photos,
                (SELECT f2.id FROM face f2 WHERE f2.person_id = p.id ORDER BY (f2.source = \'user\') DESC, f2.px DESC LIMIT 1) AS cover
            FROM person p LEFT JOIN face f ON f.person_id = p.id WHERE p.id = :id GROUP BY p.id');
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::outPerson($r) : null;
    }

    private static function outPerson(array $r): array {
        return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'faces' => (int)$r['faces'], 'photos' => (int)$r['photos'],
                'cover' => $r['cover'] !== null ? (int)$r['cover'] : null];
    }

    /** Everyone named, most photos first. */
    public static function people(): array {
        $rows = Db::get()->query('SELECT p.id, p.name, COUNT(f.id) AS faces, COUNT(DISTINCT f.image_id) AS photos,
                (SELECT f2.id FROM face f2 WHERE f2.person_id = p.id ORDER BY (f2.source = \'user\') DESC, f2.px DESC LIMIT 1) AS cover
            FROM person p LEFT JOIN face f ON f.person_id = p.id GROUP BY p.id ORDER BY photos DESC, p.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'outPerson'], $rows);
    }

    /** Face rows for the page: id, photo, picture, box. */
    private static function faceRows(array $ids): array {
        if (!$ids) return [];
        $out = [];
        foreach (array_chunk($ids, 500) as $c) {
            $st = Db::get()->prepare('SELECT f.id, f.image_id, f.x, f.y, f.w, f.h, f.person_id, f.ignored, f.source, p.name
                FROM face f LEFT JOIN person p ON p.id = f.person_id WHERE f.id IN (' . implode(',', array_fill(0, count($c), '?')) . ')');
            $st->execute($c);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = self::outFace($r);
        }
        $sorted = [];
        foreach ($ids as $i) if (isset($out[(int)$i])) $sorted[] = $out[(int)$i];
        return $sorted;
    }

    private static function outFace(array $r): array {
        return ['id' => (int)$r['id'], 'image_id' => (string)$r['image_id'],
                'box' => [round((float)$r['x'], 4), round((float)$r['y'], 4), round((float)$r['w'], 4), round((float)$r['h'], 4)],
                'person_id' => $r['person_id'] !== null ? (int)$r['person_id'] : null, 'name' => $r['name'] ?? null,
                'ignored' => (bool)(int)$r['ignored'], 'source' => $r['source'] ?? null];
    }

    /** "Who is this?": groups of unnamed faces that look like one person, largest first (each with
     *  up to $show faces; the rest are counted). Cached until any face changes. */
    public static function groups(int $show = 24, int $limit = 60): array {
        $sig = implode(':', Db::get()->query('SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(SUM(COALESCE(person_id, 0)), 0), COALESCE(SUM(ignored), 0) FROM face')->fetch(PDO::FETCH_NUM))
             . ':' . (int)Db::get()->query('SELECT COUNT(*) FROM face_reject')->fetchColumn();
        $cache = self::dir() . '/groups.json';
        $c = json_decode((string)@file_get_contents($cache), true);
        if (!is_array($c) || ($c['sig'] ?? '') !== $sig) {
            $c = ['sig' => $sig, 'groups' => self::match(['mode' => 'clusters', 'link' => self::GROUP_LINK, 'min' => 2, 'limit' => 500])['clusters'] ?? []];
            Deblur::writeJson($cache, $c);
        }
        $out = [];
        foreach (array_slice($c['groups'], 0, $limit) as $g) {
            $out[] = ['size' => count($g), 'faces' => self::faceRows(array_slice($g, 0, $show)), 'all' => $g];
        }
        return ['groups' => $out, 'total' => count($c['groups'])];
    }

    /** Person $id: their confirmed faces (newest first, a page at a time) and the faces that look like them. */
    public static function person(int $id, int $page = 0, int $per = 120): array {
        $p = self::personRow($id);
        if (!$p) throw new InvalidArgumentException('That person is not in the list.');
        $st = Db::get()->prepare('SELECT f.id FROM face f JOIN images i ON i.id = f.image_id WHERE f.person_id = :p
                                  ORDER BY COALESCE(NULLIF(i.taken_at, \'\'), i.folder_year) DESC, f.id DESC LIMIT :n OFFSET :o');
        $st->bindValue(':p', $id, PDO::PARAM_INT);
        $st->bindValue(':n', $per, PDO::PARAM_INT);
        $st->bindValue(':o', $page * $per, PDO::PARAM_INT);
        $st->execute();
        $sug = self::match(['mode' => 'suggest', 'person' => $id, 'min' => self::SUGGEST_MIN, 'limit' => 600])['suggestions'] ?? [];
        $score = [];
        foreach ($sug as $s) $score[(int)$s['face']] = (float)$s['score'];
        $faces = self::faceRows(array_keys($score));
        foreach ($faces as &$f) { $f['score'] = $score[$f['id']]; $f['tick'] = $f['score'] >= self::SUGGEST_TICK; }
        unset($f);
        return ['person' => $p, 'confirmed' => self::faceRows($st->fetchAll(PDO::FETCH_COLUMN)), 'page' => $page, 'per' => $per, 'suggestions' => $faces];
    }

    /** The faces in photo $imageId, left to right, with who each is. */
    public static function photoFaces(string $imageId): array {
        $st = Db::get()->prepare('SELECT id FROM face WHERE image_id = :i ORDER BY x');
        $st->execute([':i' => $imageId]);
        $scanned = Db::get()->prepare('SELECT faces_scanned_at FROM images WHERE id = :i');
        $scanned->execute([':i' => $imageId]);
        return ['faces' => self::faceRows($st->fetchAll(PDO::FETCH_COLUMN)), 'scanned' => $scanned->fetchColumn() !== null];
    }

    /** The named people face $faceId looks most like. */
    public static function who(int $faceId): array {
        $out = [];
        foreach (self::match(['mode' => 'who', 'face' => $faceId, 'min' => 0.30])['who'] ?? [] as $w) {
            $p = self::personRow((int)$w['person']);
            if ($p) $out[] = ['person' => $p, 'score' => (float)$w['score'], 'likely' => (float)$w['score'] >= self::SUGGEST_MIN];
        }
        return $out;
    }

    public static function thumbPath(int $faceId): ?string {
        $st = Db::get()->prepare('SELECT thumb FROM face WHERE id = :f');
        $st->execute([':f' => $faceId]);
        $t = basename((string)$st->fetchColumn());
        $p = self::thumbsDir() . '/' . $t;
        return $t !== '' && is_file($p) ? $p : null;
    }

    /** faces.py match: comparisons over every face (NumPy). */
    private static function match(array $req): array {
        if (!Deblur::isFacesReady()) return [];
        $dir = self::dir() . '/match';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $tag = bin2hex(random_bytes(5));
        $in = $dir . "/{$tag}-req.json";
        $out = $dir . "/{$tag}-out.json";
        file_put_contents($in, json_encode($req));
        try {
            [$exit, , $err] = pf_run_cmd(array_map([Deblur::class, 'win'], [Deblur::python(), PF_ROOT . '/faces.py', 'match', PF_DB_FILE, $in, $out]));
            $r = json_decode((string)@file_get_contents($out), true);
            if ($exit !== 0 || !is_array($r)) {
                $last = trim((string)strrchr("\n" . trim($err), "\n"));
                throw new RuntimeException('Comparing faces failed' . ($last !== '' ? ': ' . mb_substr($last, 0, 200) : '.'));
            }
            return $r;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    // ------------------------------------------------------------------ Embed metadata

    /** What Embed metadata writes for photo $id: its people's names, and their face regions in the
     *  file's stored frame (MWG, which Windows, digiKam and Lightroom read), with the named regions
     *  it already had that Photo Finder found no face for. Null when it has no confirmed people. */
    public static function embedData(string $id, string $path): ?array {
        $st = Db::get()->prepare('SELECT f.x, f.y, f.w, f.h, p.name FROM face f JOIN person p ON p.id = f.person_id WHERE f.image_id = :i ORDER BY f.x');
        $st->execute([':i' => $id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        $info = @getimagesize($path);
        if (!$info) return null;
        $o = ($info[2] ?? 0) === IMAGETYPE_JPEG && function_exists('exif_read_data') ? (int)(@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $regions = [];
        $names = [];
        foreach ($rows as $r) {
            $regions[] = ['name' => (string)$r['name'], 'box' => self::toStored([(float)$r['x'], (float)$r['y'], (float)$r['w'], (float)$r['h']], $o)];
            $names[mb_strtolower((string)$r['name'])] = (string)$r['name'];
        }
        $x = Db::get()->prepare('SELECT regions_extra FROM images WHERE id = :i');
        $x->execute([':i' => $id]);
        foreach ((array)json_decode((string)$x->fetchColumn(), true) as $g) {
            if (!is_array($g) || isset($names[mb_strtolower((string)($g['name'] ?? ''))])) continue;
            $regions[] = ['name' => (string)$g['name'], 'box' => array_map('floatval', (array)$g['box'])];
            $names[mb_strtolower((string)$g['name'])] = (string)$g['name'];
        }
        return ['names' => array_values($names), 'regions' => $regions, 'width' => (int)$info[0], 'height' => (int)$info[1]];
    }
}

/** Finding faces was stopped (People::runScan). */
final class PeopleCancelled extends RuntimeException {}
