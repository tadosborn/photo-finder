<?php
declare(strict_types=1);

// Photo Finder background indexing worker.
// Runs in the signed-in user's context (scheduled task PhotoFinderWorker).
// Watches the SQLite DB for indexing requests from the web UI (Apache),
// processes them, and writes results back. The AI backend and its key come from Settings.

require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Indexer.php';

ini_set('memory_limit', '512M');

function log_line(string $msg): void {
    $stamp = date('Y-m-d H:i:s');
    echo "[{$stamp}] {$msg}\n";
    pf_log($msg);
}

function trim_worker_log(): void {
    $f = PF_DATA . '/worker.log';
    if (!is_file($f) || filesize($f) < 2_000_000) return;
    $lines = file($f) ?: [];
    file_put_contents($f, implode('', array_slice($lines, -2000)));
}

log_line('Worker starting. PID=' . getmypid());
Db::get();
Db::workerHeartbeat('worker started');

$idleSleep = 2;
$lastSettingsLoad = 0;
$settings = pf_load_settings();
$consecutiveErrors = 0;

while (true) {
    $now = time();

    if ($now - $lastSettingsLoad > 30) {
        $settings = pf_load_settings();
        $lastSettingsLoad = $now;
    }

    $status = Db::workerStatus();

    if (!$status['active']) {
        Db::workerHeartbeat();
        sleep($idleSleep);
        continue;
    }

    $batchSize = max(1, min(25, (int)($settings['batch_size'] ?? 10)));
    Db::workerHeartbeat('starting batch of ' . $batchSize . ' images...');
    try {
        $r = Indexer::processBatch($settings, $batchSize);
    } catch (Throwable $e) {
        log_line('FATAL processBatch: ' . $e->getMessage());
        Db::workerHeartbeat('error: ' . substr($e->getMessage(), 0, 200), 0, 1);
        $consecutiveErrors++;
        if ($consecutiveErrors >= 5) {
            log_line('Too many consecutive errors. Deactivating.');
            Db::workerSetActive(0);
            $consecutiveErrors = 0;
        }
        sleep(5);
        continue;
    }

    if (!empty($r['message'])) {
        log_line('Batch message: ' . $r['message']);
        // "setup-required:" makes the page show the Choose-a-provider banner.
        Db::workerHeartbeat((!empty($r['needs_setup']) ? 'setup-required: ' : '') . $r['message'], 0, $r['errors']);
        Db::workerSetActive(0);
        $consecutiveErrors = 0;
        continue;
    }

    // A rejected key fails every photo: stop at once with a status the page can show.
    $authErr = false;
    if ($r['errors'] > 0 && !empty($r['messages'])) {
        foreach ($r['messages'] as $m) {
            if (stripos($m, 'not logged in') !== false
                || stripos($m, 'please run /login') !== false
                || stripos($m, 'unauthorized') !== false
                || stripos($m, 'key was rejected') !== false
                || stripos($m, 'HTTP 401') !== false) {
                $authErr = true;
                break;
            }
        }
    }
    if ($authErr) {
        log_line('The AI service rejected the API key. Stopping until Settings are fixed.');
        Db::workerHeartbeat('setup-required: the AI service rejected the API key. Check it in Settings.', 0, $r['errors']);
        Db::workerSetActive(0);
        $consecutiveErrors = 0;
        continue;
    }

    if ($r['processed'] === 0 && $r['errors'] === 0) {
        log_line('No pending work. Going idle.');
        Db::workerHeartbeat('idle: nothing to index', 0, 0);
        Db::workerSetActive(0);
        $consecutiveErrors = 0;
        sleep($idleSleep);
        continue;
    }

    $stats = Db::stats();
    $msg = sprintf('batch: +%d processed, +%d errors. Pending=%d',
        $r['processed'], $r['errors'], $stats['pending']);
    log_line($msg);
    Db::workerHeartbeat($msg, $r['processed'], $r['errors']);
    $consecutiveErrors = ($r['processed'] > 0) ? 0 : ($consecutiveErrors + 1);

    if ($consecutiveErrors >= 5) {
        log_line('5 batches with no progress. Pausing.');
        Db::workerSetActive(0);
        $consecutiveErrors = 0;
    }

    trim_worker_log();
}
