<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Enhance.php';
require_once __DIR__ . '/Deblur.php';
require_once __DIR__ . '/Thumb.php';

/**
 * Restore the original: undo "Replace the original" (Enhance, Fix blur, Enlarge, batches).
 *
 * Replacing keeps the photo as it was beside it, as "<name> (original).<ext>" (pf_kept_original_path;
 * a second replacement keeps the first one's result as "(original 2)", and so on, so "(original)" is
 * always what the photo was before it was first replaced). Restoring puts that file back under the
 * photo's own name and keeps the version that had replaced it beside it as "<name> (<what it was>)",
 * e.g. "(enhanced)": a version, out of the gallery like the others. Nothing is deleted. The photo
 * keeps its place in the index, its description and its people; its size, thumbnail, Clean up
 * measurements and faces are taken again from the restored file.
 */
final class Restore {
    /** Photo $path's kept original, or null. */
    public static function keptOriginal(string $path): ?string {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $p = pf_norm_path(dirname($path)) . '/' . pathinfo($path, PATHINFO_FILENAME) . ' (original)' . ($ext !== '' ? '.' . $ext : '');
        return is_file($p) ? $p : null;
    }

    /** What replaced photo $row, as a version's label ("enhanced", "deblurred", "enlarged 2x"...). */
    private static function label(array $row): string {
        $l = trim((string)($row['replaced_with'] ?? ''));
        if ($l !== '' && $l !== 'replaced') return $l;
        // Replaced before 1.0.18 recorded it: a batch's replacements are in its record.
        $b = json_decode((string)@file_get_contents(Deblur::dir() . '/batch-replaced.json'), true);
        $kind = is_array($b) ? (string)($b['items'][(string)$row['id']]['kind'] ?? '') : '';
        return Deblur::SUFFIX[$kind] ?? 'replaced';
    }

    /** "<name> (<label>).<ext>" beside $path, or "(<label> 2)" and so on: never an existing file. */
    private static function versionPath(string $path, string $label): string {
        $dir = pf_norm_path(dirname($path));
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        for ($n = 1; $n < 1000; $n++) {
            $p = $dir . '/' . $stem . ($n === 1 ? " ({$label})" : " ({$label} {$n})") . ($ext !== '' ? '.' . $ext : '');
            if (!file_exists($p)) return $p;
        }
        throw new RuntimeException('Too many versions of this photo already.');
    }

    /** Put photo $row's kept original back in its place. Returns the photo's row for the page and
     *  the name the replacing version is now kept under. */
    public static function restore(array $row): array {
        $path = (string)$row['path'];
        if (!pf_path_inside_roots($path, pf_load_settings()['photo_dirs'] ?? [])) throw new RuntimeException('This photo is outside your photo folders.');
        if (!is_file($path)) throw new RuntimeException('The photo is no longer on disk.');
        $orig = self::keptOriginal($path);
        if ($orig === null) throw new RuntimeException('This photo has no kept original to restore.');
        $ver = self::versionPath($path, self::label($row));
        if (!@rename($path, $ver)) throw new RuntimeException('Could not move the current version aside (is the photo open in another program?). Nothing was changed.');
        if (!@rename($orig, $path)) {
            @rename($ver, $path);
            throw new RuntimeException('Could not put the original back. Nothing was changed.');
        }
        $id = (string)$row['id'];
        // The version that had replaced it: indexed like any saved version (out of the gallery).
        $verId = Enhance::indexCopy($row, $ver);
        Db::setGalleryHidden($verId);
        [$vw, $vh] = pf_image_size($ver) ?? [0, 0];
        Db::get()->prepare('UPDATE images SET width = :w, height = :h WHERE id = :id')->execute([':w' => $vw, ':h' => $vh, ':id' => $verId]);
        Thumb::ensureThumb($verId, $ver);
        // The kept original's own row: its file is the photo again.
        Db::deleteImage(pf_path_id($orig));
        @unlink(PF_THUMBS . '/' . pf_path_id($orig) . '.jpg');
        // The photo: its own size, thumbnail, measurements and faces from the restored file.
        clearstatcache(true, $path);
        Db::upsertPending($id, $path, (int)filesize($path), (int)filemtime($path));
        [$w, $h] = pf_image_size($path) ?? [0, 0];
        Db::get()->prepare('UPDATE images SET width = :w, height = :h, analyzed_at = NULL, replaced_with = NULL, exported_at = NULL WHERE id = :id')
            ->execute([':w' => $w, ':h' => $h, ':id' => $id]);
        Db::touchUpdated($id);
        Db::rescanFaces($id);
        @unlink(PF_THUMBS . '/' . $id . '.jpg');
        Thumb::ensureThumb($id, $path);
        Deblur::discard($id);   // results made from the replaced version: not of this photo any more
        // A batch's review no longer shows it as replaced.
        $bf = Deblur::dir() . '/batch-replaced.json';
        $b = json_decode((string)@file_get_contents($bf), true);
        if (is_array($b) && isset($b['items'][$id])) { unset($b['items'][$id]); Deblur::writeJson($bf, $b); }
        return ['ok' => true, 'image' => Enhance::rowForPage($id), 'version' => basename($ver), 'original' => basename($orig)];
    }

    /** Every photo in the gallery with a kept original: [{id, name, original}]. */
    public static function restorable(): array {
        $out = [];
        $rows = Db::get()->query("SELECT path FROM images WHERE path LIKE '% (original).%'")->fetchAll(PDO::FETCH_COLUMN);
        $photo = Db::get()->prepare('SELECT id, path FROM images WHERE id = :id AND gallery_hidden = 0');
        foreach ($rows as $kept) {
            $p = preg_replace('/ \(original\)(\.[^.\/]*)$/i', '$1', (string)$kept);
            if (!is_string($p) || $p === $kept || !is_file((string)$kept)) continue;
            $photo->execute([':id' => pf_path_id($p)]);
            $r = $photo->fetch(PDO::FETCH_ASSOC);
            $photo->closeCursor();
            if ($r && is_file((string)$r['path'])) $out[] = ['id' => (string)$r['id'], 'name' => basename((string)$r['path']), 'original' => basename((string)$kept)];
        }
        return $out;
    }

    /** Restore photos $ids (all that have a kept original when empty). */
    public static function restoreMany(array $ids = []): array {
        $want = $ids ? array_flip(array_map('strval', $ids)) : null;
        $done = [];
        $failed = [];
        foreach (self::restorable() as $r) {
            if ($want !== null && !isset($want[$r['id']])) continue;
            try {
                $row = Db::getById($r['id']);
                if (!$row) continue;
                $res = self::restore($row);
                $done[] = ['id' => $r['id'], 'name' => $r['name'], 'version' => $res['version']];
            } catch (Throwable $e) {
                $failed[] = ['id' => $r['id'], 'name' => $r['name'], 'error' => $e->getMessage()];
            }
        }
        return ['restored' => $done, 'failed' => $failed, 'stats' => Db::stats()];
    }
}
