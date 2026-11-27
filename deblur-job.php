<?php
declare(strict_types=1);

// A Fix blur / Enlarge job, in the background (lib/Deblur.php): makes the result (on this PC
// with the runtime's Python, or with Google's Gemini), then saves it as a copy next to the photo.
// Started (hidden) by Deblur::start():   php deblur-job.php <photo id> <data folder> <kind>
// <kind>: local, cloud, chain, up_local or up_cloud. Command line only; the web server never serves this file.

if (PHP_SAPI !== 'cli' || $argc !== 4 || !preg_match('/^[a-f0-9]{40}$/', $argv[1])
    || !in_array($argv[3], ['local', 'cloud', 'chain', 'up_local', 'up_cloud'], true)) {
    fwrite(STDERR, "usage: php deblur-job.php <photo id> <data folder> local|cloud|chain|up_local|up_cloud\n");
    exit(2);
}
if (is_dir($argv[2])) putenv('PHOTOFINDER_DATA=' . $argv[2]);   // before util.php fixes PF_DATA
require_once __DIR__ . '/lib/Deblur.php';
set_time_limit(0);
Deblur::runJob($argv[1], $argv[3]);
