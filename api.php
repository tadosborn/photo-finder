<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Indexer.php';
require_once __DIR__ . '/lib/ClaudeCli.php';
require_once __DIR__ . '/lib/Exporter.php';
require_once __DIR__ . '/lib/Updater.php';

set_time_limit(300);
ini_set('memory_limit', '512M');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'settings_get': {
            $s = pf_load_settings();
            $apiKeySet = $s['api_key'] !== '';
            unset($s['api_key']);
            $s['api_key_set'] = $apiKeySet;
            if (($s['cli_path'] ?? '') === '') {
                $s['cli_path_suggested'] = ClaudeCli::autoDetect() ?? '';
            }
            if (($s['exiftool_path'] ?? '') === '') {
                $s['exiftool_path_suggested'] = Exporter::autoDetect() ?? '';
            }
            pf_json($s);
        }
        case 'detect_cli': {
            $found = ClaudeCli::autoDetect();
            pf_json(['cli_path' => $found, 'found' => $found !== null]);
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
            if (isset($in['backend']) && in_array($in['backend'], ['api', 'cli'], true)) {
                $current['backend'] = $in['backend'];
            }
            if (isset($in['api_key']) && is_string($in['api_key']) && $in['api_key'] !== '') {
                $current['api_key'] = $in['api_key'];
            }
            if (isset($in['model']) && is_string($in['model']) && $in['model'] !== '') {
                $current['model'] = $in['model'];
            }
            if (isset($in['cli_model']) && is_string($in['cli_model']) && $in['cli_model'] !== '') {
                $current['cli_model'] = $in['cli_model'];
            }
            if (isset($in['cli_path']) && is_string($in['cli_path'])) {
                $current['cli_path'] = pf_norm_path($in['cli_path']);
            }
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
        case 'index_tick': {
            // Legacy endpoint: still used by start.bat (user-context) but disabled under Apache.
            if (php_sapi_name() !== 'cli-server') {
                pf_json(['processed' => 0, 'errors' => 0,
                    'message' => 'Direct indexing is disabled under Apache for security. Use the worker (worker.bat) instead.',
                    'stats' => Db::stats()]);
            }
            $s = pf_load_settings();
            $limit = (int)($_GET['n'] ?? $s['batch_size']);
            $result = Indexer::processBatch($s, max(1, min(25, $limit)));
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
        case 'worker_login': {
            // Pops open a visible terminal that runs `claude /login`.
            $cmd = ['C:\\Windows\\System32\\schtasks.exe', '/run', '/tn', 'PhotoFinderLogin'];
            [$exit, $stdout, $stderr] = pf_run_cmd($cmd);
            if ($exit !== 0) {
                pf_json(['ok' => false,
                    'error' => 'Could not open login window. Did you run install.bat? ' . trim($stderr ?: $stdout),
                    'exit' => $exit], 500);
            }
            pf_json(['ok' => true,
                'message' => 'A login window should open on your desktop. Complete the OAuth flow and close it.']);
        }
        case 'search': {
            $q = (string)($_GET['q'] ?? '');
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 120)));
            $rows = Db::search($q, $limit);
            pf_json(['query' => $q, 'count' => count($rows), 'results' => $rows]);
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
        default:
            pf_json(['error' => 'unknown action', 'action' => $action], 400);
    }
} catch (Throwable $e) {
    pf_json(['error' => $e->getMessage()], 500);
}
