<?php
declare(strict_types=1);

// Fix blur / Enlarge with Google's Gemini, in the background (lib/Deblur.php).
// Started (hidden) by Deblur::start():   php deblur-cloud.php <photo id> [<data folder> [<kind>]]
// <kind> is cloud (the default), chain or up_cloud. Command line only; the web server never serves this file.

$kinds = ['cloud', 'chain', 'up_cloud'];
if (PHP_SAPI !== 'cli' || $argc < 2 || $argc > 4 || !preg_match('/^[a-f0-9]{40}$/', $argv[1])
    || ($argc === 4 && !in_array($argv[3], $kinds, true))) {
    fwrite(STDERR, "usage: php deblur-cloud.php <photo id> [<data folder> [cloud|chain|up_cloud]]\n");
    exit(2);
}
if ($argc >= 3 && is_dir($argv[2])) putenv('PHOTOFINDER_DATA=' . $argv[2]);   // before util.php fixes PF_DATA
require_once __DIR__ . '/lib/Deblur.php';
set_time_limit(0);
Deblur::runCloud($argv[1], $argv[3] ?? 'cloud');
