<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Indexer.php';
require_once __DIR__ . '/lib/Describer.php';
require_once __DIR__ . '/lib/Claude.php';
require_once __DIR__ . '/lib/OpenAICompat.php';
require_once __DIR__ . '/lib/LocalAI.php';
require_once __DIR__ . '/lib/Exporter.php';
require_once __DIR__ . '/lib/Updater.php';

set_time_limit(300);
ini_set('memory_limit', '512M');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** Provider settings from a Settings form post. Blank key fields keep the saved key. */
function pf_provider_fields(array $in, array $saved): array {
    $out = [];
    if (isset($in['backend']) && in_array($in['backend'], ['anthropic', 'openai', 'local', ''], true)) {
        $out['backend'] = $in['backend'];
    }
    if (isset($in['model']) && is_string($in['model']) && array_key_exists($in['model'], Claude::MODELS)) {
        $out['model'] = $in['model'];
    }
    foreach (['api_key', 'openai_api_key'] as $k) {
        if (isset($in[$k]) && is_string($in[$k]) && trim($in[$k]) !== '') $out[$k] = trim($in[$k]);
    }
    foreach (['openai_base_url', 'openai_model', 'local_base_url', 'local_model'] as $k) {
        if (isset($in[$k]) && is_string($in[$k])) $out[$k] = trim($in[$k]);
    }
    return $out;
}

// ---- Find photos on this PC and consolidate them (lib/Consolidate.php) ----------------------
// Search and move steps are short batches the page drives. Anything that writes is POST-only.
if (str_starts_with($action, 'consolidate_')) {
    require_once __DIR__ . '/lib/Consolidate.php';
    try {
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $in = $isPost ? pf_input_json() : [];
        $needPost = function () use ($isPost): void {
            if (!$isPost) pf_json(['error' => 'POST required'], 405);
        };
        switch ($action) {
            case 'consolidate_status':
                pf_json(Consolidate::status());
            case 'consolidate_results':
                pf_json(Consolidate::results((string)($_GET['id'] ?? '')));
            case 'consolidate_thumb': {
                $file = Consolidate::thumb((int)($_GET['id'] ?? 0));
                if ($file === null) { http_response_code(404); exit; }
                header('Content-Type: image/jpeg');
                header('Cache-Control: private, max-age=3600');
                readfile($file);
                exit;
            }
            case 'consolidate_run':
                pf_json(Consolidate::runSummary((string)($_GET['run_id'] ?? '')));
            case 'consolidate_search_start':
                $needPost();
                pf_json(Consolidate::searchStart(!empty($in['drives'])));
            case 'consolidate_search_tick':
                $needPost();
                pf_json(Consolidate::searchTick((string)($in['id'] ?? '')));
            case 'consolidate_search_cancel':
                $needPost();
                pf_json(Consolidate::searchCancel((string)($in['id'] ?? '')));
            case 'consolidate_start':
                $needPost();
                pf_json(Consolidate::moveStart((string)($in['search_id'] ?? ''), (string)($in['target'] ?? ''),
                    is_array($in['ids'] ?? null) ? $in['ids'] : []));
            case 'consolidate_tick':
                $needPost();
                pf_json(Consolidate::moveTick((string)($in['run_id'] ?? '')));
            case 'consolidate_cancel':
                $needPost();
                pf_json(Consolidate::moveCancel((string)($in['run_id'] ?? '')));
            case 'consolidate_finish':
                $needPost();
                pf_json(Consolidate::moveFinish((string)($in['run_id'] ?? '')));
            case 'consolidate_undo_tick':
                $needPost();
                pf_json(Consolidate::undoTick((string)($in['run_id'] ?? '')));
            default:
                pf_json(['error' => 'unknown action', 'action' => $action], 400);
        }
    } catch (Throwable $e) {
        pf_json(['error' => $e->getMessage()], 500);
    }
}
// ---- end of Find photos / consolidate -------------------------------------------------------

try {
    switch ($action) {
        case 'settings_get': {
            $s = pf_load_settings();
            // Keys never leave the server side: the page only learns whether one is set.
            foreach (['api_key', 'openai_api_key'] as $k) {
                $s[$k . '_set'] = trim((string)($s[$k] ?? '')) !== '';
                unset($s[$k]);
            }
            $s['backend'] = Describer::backendOf($s);
            $s['anthropic_models'] = Claude::MODELS;
            if (($s['exiftool_path'] ?? '') === '') {
                $s['exiftool_path_suggested'] = Exporter::autoDetect() ?? '';
            }
            pf_json($s);
        }
        case 'provider_status': {
            [$client, $why] = Describer::fromSettings(pf_load_settings());
            pf_json(['configured' => $client !== null, 'label' => $client ? $client->label() : '', 'message' => $why]);
        }
        case 'provider_test': {
            // Describes a small generated picture with the settings in the form (not yet
            // saved). A blank key field means "use the saved key".
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pf_json(['error' => 'POST required'], 405);
            $trial = array_merge(pf_load_settings(), pf_provider_fields(pf_input_json(), pf_load_settings()));
            [$client, $why] = Describer::fromSettings($trial);
            if ($client === null) pf_json(['ok' => false, 'error' => $why]);
            $t0 = microtime(true);
            try {
                $a = $client->analyzeImage(Describer::testImage());
            } catch (Throwable $e) {
                pf_json(['ok' => false, 'error' => $e->getMessage()]);
            }
            pf_json(['ok' => true, 'label' => $client->label(), 'seconds' => round(microtime(true) - $t0, 1),
                     'description' => $a['description'], 'tags' => $a['tags']]);
        }
        case 'provider_models': {
            // Model names offered by an OpenAI-compatible service (or the local server).
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pf_json(['error' => 'POST required'], 405);
            $in = pf_input_json();
            $local = ($in['backend'] ?? '') === 'local';
            $saved = pf_load_settings();
            $url = trim((string)($in['base_url'] ?? ''));
            $key = $local ? '' : (trim((string)($in['api_key'] ?? '')) ?: (string)($saved['openai_api_key'] ?? ''));
            if ($local && !OpenAICompat::isLoopbackUrl($url)) pf_json(['ok' => false, 'error' => 'The local option only accepts an address on this PC.']);
            try {
                pf_json(['ok' => true, 'models' => (new OpenAICompat($url, $key, 'x', $local))->listModels()]);
            } catch (Throwable $e) {
                pf_json(['ok' => false, 'error' => $e->getMessage()]);
            }
        }
        // ---- The free option: can this PC run a local model, and getting one ----
        case 'local_check': {
            $s = pf_load_settings();
            $url = trim((string)($_GET['base_url'] ?? '')) ?: (string)($s['local_base_url'] ?? '');
            if (!OpenAICompat::isLoopbackUrl($url)) pf_json(['ok' => false, 'error' => 'The local option only accepts an address on this PC.']);
            $pc = LocalAI::pcInfo(!empty($_GET['refresh']));
            $tier = LocalAI::tier($pc);
            pf_json(['ok' => true, 'pc' => $pc, 'tier' => $tier, 'recommended' => LocalAI::RECOMMENDED[$tier],
                     'ollama' => LocalAI::ollama($url), 'pull' => LocalAI::pullStatus(),
                     'photos' => (int)(Db::stats()['total'] ?? 0)]);
        }
        case 'local_pull': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pf_json(['error' => 'POST required'], 405);
            $in = pf_input_json();
            $url = trim((string)($in['base_url'] ?? '')) ?: (string)(pf_load_settings()['local_base_url'] ?? '');
            if (!OpenAICompat::isLoopbackUrl($url)) pf_json(['ok' => false, 'error' => 'The local option only accepts an address on this PC.']);
            try {
                LocalAI::startPull(trim((string)($in['model'] ?? '')), $url);
            } catch (Throwable $e) {
                pf_json(['ok' => false, 'error' => $e->getMessage()]);
            }
            pf_json(['ok' => true]);
        }
        case 'local_pull_status': {
            pf_json(['ok' => true, 'pull' => LocalAI::pullStatus()]);
        }

        case 'model_stats': {
            [$client] = Describer::fromSettings(pf_load_settings());
            $label = $client ? $client->label() : '';
            $counts = Db::describedByCounts();
            $other = 0;
            foreach ($counts as $m => $c) if ($m !== $label) $other += $c;
            pf_json(['current' => $label, 'counts' => $counts, 'other' => $other, 'queued' => Db::redescribeQueued()]);
        }
        case 'reindex_other': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pf_json(['error' => 'POST required'], 405);
            [$client, $why] = Describer::fromSettings(pf_load_settings());
            if ($client === null) pf_json(['ok' => false, 'error' => $why]);
            pf_json(['ok' => true, 'queued' => Db::queueOtherModels($client->label()), 'stats' => Db::stats()]);
        }
        case 'detect_exiftool': {
            $found = Exporter::autoDetect();
            pf_json(['exiftool_path' => $found, 'found' => $found !== null]);
        }
        case 'export_tick': {
            $s = pf_load_settings();
            $tool = (string)($s['exiftool_path'] ?? '');
            if ($tool === '') $tool = Exporter::autoDetect() ?? '';
            if ($tool === '' || !is_file($tool)) {
                pf_json(['processed' => 0, 'errors' => 0,
                    'message' => 'ExifTool not found. Set the path in Settings.',
                    'stats' => Db::stats()]);
            }
            $batch = (int)($_GET['n'] ?? $s['export_batch_size']);
            $batch = max(1, min(100, $batch));
            $items = Db::nextExportPending($batch);
            if (!$items) {
                pf_json(['processed' => 0, 'errors' => 0, 'stats' => Db::stats()]);
            }
            $exp = new Exporter(
                $tool,
                (bool)$s['export_description'],
                (bool)$s['export_tags'],
                (bool)$s['export_ocr_text'],
                (bool)$s['export_replace_description'],
                (bool)$s['export_overwrite_original'],
            );
            $r = $exp->processBatch($items);
            $r['stats'] = Db::stats();
            pf_json($r);
        }
        case 'export_reset_errors': {
            $n = Db::resetExportErrors();
            pf_json(['reset' => $n, 'stats' => Db::stats()]);
        }
        case 'settings_save': {
            $in = pf_input_json();
            $current = pf_load_settings();
            if (isset($in['photo_dirs']) && is_array($in['photo_dirs'])) {
                $current['photo_dirs'] = array_values(array_filter(array_map(
                    fn($d) => pf_norm_path((string)$d),
                    $in['photo_dirs']
                ), fn($d) => $d !== ''));
            }
            if (isset($in['excluded_dirs']) && is_array($in['excluded_dirs'])) {
                $current['excluded_dirs'] = array_values(array_filter(array_map(
                    fn($d) => trim((string)$d, " \t\n\r/\\"),
                    $in['excluded_dirs']
                ), fn($d) => $d !== ''));
            }
            $current = array_merge($current, pf_provider_fields($in, $current));
            if (($current['backend'] ?? '') === 'local' && !OpenAICompat::isLoopbackUrl((string)$current['local_base_url'])) {
                pf_json(['error' => 'The local option only accepts an address on this PC (localhost / 127.0.0.1).'], 400);
            }
            unset($current['cli_path'], $current['cli_model']);
            if (isset($in['max_image_dim'])) {
                $current['max_image_dim'] = max(256, min(2000, (int)$in['max_image_dim']));
            }
            if (isset($in['batch_size'])) {
                $current['batch_size'] = max(1, min(25, (int)$in['batch_size']));
            }
            if (isset($in['exiftool_path']) && is_string($in['exiftool_path'])) {
                $current['exiftool_path'] = pf_norm_path($in['exiftool_path']);
            }
            if (isset($in['export_batch_size'])) {
                $current['export_batch_size'] = max(1, min(100, (int)$in['export_batch_size']));
            }
            foreach (['export_description', 'export_tags', 'export_ocr_text',
                     'export_replace_description', 'export_overwrite_original', 'update_check'] as $k) {
                if (isset($in[$k])) $current[$k] = (bool)$in[$k];
            }
            pf_save_settings($current);
            pf_json(['ok' => true]);
        }
        case 'version': {
            pf_json(['version' => PF_VERSION, 'installed' => Updater::isInstalled()]);
        }
        case 'update_check': {
            $force = !empty($_GET['force']);
            if (!$force && empty(pf_load_settings()['update_check'])) {
                pf_json(['current' => PF_VERSION, 'installed' => Updater::isInstalled(), 'disabled' => true, 'available' => false]);
            }
            pf_json(Updater::check($force));
        }
        case 'update_install': {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pf_json(['error' => 'POST required'], 405);
            pf_json(Updater::install());
        }
        case 'stats': {
            pf_json(Db::stats());
        }
        case 'scan': {
            $s = pf_load_settings();
            $result = Indexer::scan($s['photo_dirs'], $s['excluded_dirs'] ?? []);
            $result['stats'] = Db::stats();
            pf_json($result);
        }
        case 'purge_excluded': {
            $s = pf_load_settings();
            $removed = Indexer::purgeExcluded($s['excluded_dirs'] ?? []);
            pf_json(['removed' => $removed, 'stats' => Db::stats()]);
        }
        case 'purge_missing': {
            $result = Db::purgeMissingFiles();
            $result['stats'] = Db::stats();
            pf_json($result);
        }
        case 'worker_log': {
            $lines = max(20, min(500, (int)($_GET['lines'] ?? 200)));
            pf_json(['lines' => pf_log_tail($lines)]);
        }
        case 'worker_status': {
            // Watchdog: try to auto-relaunch if the worker is supposed to be running but isn't.
            // Cheap to call on every poll because it's rate-limited internally.
            $watchdog = Db::workerWatchdog();
            $status = Db::workerStatus();
            if ($watchdog['attempted']) $status['watchdog'] = $watchdog;
            pf_json($status);
        }
        case 'worker_start': {
            Db::workerResetCounters();
            Db::workerSetActive(1);
            pf_json(['ok' => true, 'worker' => Db::workerStatus()]);
        }
        case 'worker_stop': {
            Db::workerSetActive(0);
            pf_json(['ok' => true, 'worker' => Db::workerStatus()]);
        }
        case 'worker_launch': {
            // Kicks the PhotoFinderWorker scheduled task into running.
            // The task itself is registered in your user context by install.bat.
            $cmd = ['C:\\Windows\\System32\\schtasks.exe', '/run', '/tn', 'PhotoFinderWorker'];
            [$exit, $stdout, $stderr] = pf_run_cmd($cmd);
            if ($exit !== 0) {
                pf_json(['ok' => false,
                    'error' => 'Could not launch worker. Did you run install.bat? schtasks said: ' . trim($stderr ?: $stdout),
                    'exit' => $exit], 500);
            }
            pf_json(['ok' => true, 'message' => 'Worker launch requested']);
        }

        case 'search': {
            $q = (string)($_GET['q'] ?? '');
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 120)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $sort = in_array($_GET['sort'] ?? '', ['new', 'old', 'folder', 'relevance'], true) ? $_GET['sort'] : 'new';
            $rows = Db::search($q, $limit + 1, $offset, $sort);   // one extra row says whether there is more
            $more = count($rows) > $limit;
            if ($more) array_pop($rows);
            pf_json(['query' => $q, 'count' => count($rows), 'offset' => $offset, 'more' => $more, 'results' => $rows]);
        }
        case 'reset_errors': {
            $n = Db::resetErrors();
            pf_json(['reset' => $n, 'stats' => Db::stats()]);
        }
        case 'errors_list': {
            pf_json([
                'index_errors'  => Db::listErrors(500),
                'export_errors' => Db::listExportErrors(500),
                'pending'       => Db::listPending(500),
                'stats'         => Db::stats(),
            ]);
        }
        case 'acknowledge_error': {
            $in = pf_input_json();
            $id = preg_replace('/[^a-f0-9]/i', '', (string)($in['id'] ?? ''));
            $type = ($in['type'] ?? 'index') === 'export' ? 'export' : 'index';
            if (!$id) pf_json(['error' => 'missing id'], 400);
            Db::acknowledgeError($id, $type);
            pf_json(['ok' => true, 'stats' => Db::stats()]);
        }
        case 'retry_error': {
            $in = pf_input_json();
            $id = preg_replace('/[^a-f0-9]/i', '', (string)($in['id'] ?? ''));
            $type = ($in['type'] ?? 'index') === 'export' ? 'export' : 'index';
            if (!$id) pf_json(['error' => 'missing id'], 400);
            Db::retryError($id, $type);
            pf_json(['ok' => true, 'stats' => Db::stats()]);
        }
        case 'acknowledge_all_errors': {
            $in = pf_input_json();
            $type = ($in['type'] ?? 'index') === 'export' ? 'export' : 'index';
            $n = Db::acknowledgeAllErrors($type);
            pf_json(['acknowledged' => $n, 'stats' => Db::stats()]);
        }
        case 'delete_image': {
            $in = pf_input_json();
            $id = preg_replace('/[^a-f0-9]/i', '', (string)($in['id'] ?? ''));
            if (!$id) pf_json(['error' => 'missing id'], 400);
            Db::deleteImage($id);
            pf_json(['ok' => true, 'stats' => Db::stats()]);
        }
        case 'clear_all': {
            Db::clearAll();
            pf_json(['ok' => true, 'stats' => Db::stats()]);
        }
        case 'delete_files': {
            // Move the real photo files to the Windows Recycle Bin AND remove
            // their index rows. Accepts {ids:[...]} for bulk or {id:"..."} for one.
            $in = pf_input_json();
            $rawIds = $in['ids'] ?? null;
            if (!is_array($rawIds)) {
                $rawIds = isset($in['id']) ? [$in['id']] : [];
            }
            $ids = [];
            foreach ($rawIds as $rawId) {
                $cid = preg_replace('/[^a-f0-9]/i', '', (string)$rawId);
                if ($cid !== '') $ids[$cid] = true;   // dedupe
            }
            $ids = array_keys($ids);
            if (!$ids) pf_json(['error' => 'no valid ids'], 400);

            $settings = pf_load_settings();
            $roots = $settings['photo_dirs'] ?? [];

            $toRecycle = [];     // path => id (file exists, inside roots)
            $removeIds = [];     // ids to drop from the index (gone or recycled)
            $failed = [];        // [id, path, error]

            foreach ($ids as $id) {
                $row = Db::getById($id);
                if (!$row) { $failed[] = ['id' => $id, 'path' => '', 'error' => 'not found in index']; continue; }
                $path = (string)$row['path'];
                // Same guard file.php uses — never delete outside the user's photo folders.
                if (!pf_path_inside_roots($path, $roots)) {
                    $failed[] = ['id' => $id, 'path' => $path, 'error' => 'outside configured photo folders'];
                    continue;
                }
                if (!is_file($path)) {
                    $removeIds[] = $id;        // already gone on disk — just clean the index
                    continue;
                }
                $toRecycle[$path] = $id;
            }

            $recycleResults = $toRecycle ? pf_recycle_files(array_keys($toRecycle)) : [];
            foreach ($toRecycle as $path => $id) {
                $res = $recycleResults[$path] ?? ['ok' => false, 'error' => 'no response'];
                if ($res['ok']) {
                    $removeIds[] = $id;
                } else {
                    $failed[] = ['id' => $id, 'path' => $path, 'error' => $res['error']];
                }
            }
            if ($removeIds) Db::deleteImages($removeIds);

            pf_json([
                'ok' => true,
                'deleted' => count($removeIds),
                'deleted_ids' => array_values($removeIds),
                'failed' => $failed,
                'stats' => Db::stats(),
            ]);
        }
        case 'image_info': {
            $id = (string)($_GET['id'] ?? '');
            $row = Db::getById($id);
            if (!$row) pf_json(['error' => 'not found'], 404);
            pf_json($row);
        }
        case 'update_metadata': {
            $in = pf_input_json();
            $id = preg_replace('/[^a-f0-9]/i', '', (string)($in['id'] ?? ''));
            if (!$id) pf_json(['error' => 'missing id'], 400);
            if (!Db::getById($id)) pf_json(['error' => 'not found'], 404);
            // Each field is optional; null means "leave alone", '' means "clear"
            $d  = array_key_exists('description', $in)    ? (string)$in['description']    : null;
            $t  = array_key_exists('tags', $in)           ? (string)$in['tags']           : null;
            $f  = array_key_exists('faces', $in)          ? (string)$in['faces']          : null;
            $x  = array_key_exists('text_in_image', $in)  ? (string)$in['text_in_image']  : null;
            Db::updateMetadata($id, $d, $t, $f, $x);
            pf_json(['ok' => true, 'image' => Db::getById($id)]);
        }
        case 'reset_manual': {
            // Clear the manual flag and reset to pending so worker will re-index
            $id = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['id'] ?? $_POST['id'] ?? ''));
            if (!$id) pf_json(['error' => 'missing id'], 400);
            Db::resetManualDescription($id);
            pf_json(['ok' => true, 'image' => Db::getById($id)]);
        }

        // ---- Clean up (lib/Cleanup.php): blurry, badly exposed and duplicate photos ----
        // Analysis is local (thumbnails only, no AI). Deleting goes through delete_files above.
        case 'cleanup_status':
        case 'cleanup_list':
        case 'cleanup_analyze':
        case 'cleanup_keep':
        case 'cleanup_unkeep_all': {
            require_once __DIR__ . '/lib/Cleanup.php';
            $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
            if (!in_array($action, ['cleanup_status', 'cleanup_list'], true) && !$isPost) {
                pf_json(['error' => 'POST required'], 405);
            }
            switch ($action) {
                case 'cleanup_status':
                    pf_json(['status' => Cleanup::status(), 'config' => Cleanup::config()]);
                case 'cleanup_analyze': {
                    $in = pf_input_json();
                    pf_json(Cleanup::analyzeBatch((int)($in['limit'] ?? Cleanup::BATCH_DEFAULT)));
                }
                case 'cleanup_list': {
                    // Only what the review cards and the lightbox show.
                    $slim = function (array $r): array {
                        unset($r['dhash'], $r['dhash_v'], $r['mtime'], $r['dark_frac'], $r['lum_std']);
                        return $r;
                    };
                    $kind = (string)($_GET['kind'] ?? '');
                    if ($kind === 'blurry') {
                        pf_json(['kind' => $kind, 'items' => array_map($slim, Cleanup::blurry()), 'status' => Cleanup::status()]);
                    }
                    if ($kind === 'exposure') {
                        pf_json(['kind' => $kind, 'items' => array_map($slim, Cleanup::exposure()), 'status' => Cleanup::status()]);
                    }
                    if ($kind === 'duplicates') {
                        $d = Cleanup::duplicates((int)($_GET['dist'] ?? Cleanup::DUP_DEFAULT));
                        foreach ($d['groups'] as &$g) $g['members'] = array_map($slim, $g['members']);
                        unset($g);
                        pf_json(['kind' => $kind] + $d + ['status' => Cleanup::status()]);
                    }
                    pf_json(['error' => 'kind must be blurry, exposure or duplicates'], 400);
                }
                case 'cleanup_keep': {
                    $in = pf_input_json();
                    $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
                    $n = Cleanup::setKeep($ids, ($in['keep'] ?? true) !== false);
                    pf_json(['ok' => true, 'changed' => $n, 'status' => Cleanup::status()]);
                }
                case 'cleanup_unkeep_all':
                    pf_json(['ok' => true, 'changed' => Cleanup::clearAllKeep(), 'status' => Cleanup::status()]);
            }
        }
        // ---- end Clean up ----

        default:
            pf_json(['error' => 'unknown action', 'action' => $action], 400);
    }
} catch (Throwable $e) {
    pf_json(['error' => $e->getMessage()], 500);
}
