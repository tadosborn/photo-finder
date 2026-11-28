<?php
declare(strict_types=1);

// Downloads and unpacks the on-PC AI runtime in the background (lib/Deblur.php): for Fix blur, for
// Enlarge (its model too) or for People (the face models too).
// Started (hidden) by Deblur::startSetup():   php deblur-setup.php [<data folder> [deblur|enlarge|faces]]
// Command line only; the web server never serves this file.

if (PHP_SAPI !== 'cli' || $argc > 3 || ($argc === 3 && !in_array($argv[2], ['deblur', 'enlarge', 'faces'], true))) {
    fwrite(STDERR, "usage: php deblur-setup.php [<data folder> [deblur|enlarge|faces]]\n");
    exit(2);
}
if ($argc >= 2 && is_dir($argv[1])) putenv('PHOTOFINDER_DATA=' . $argv[1]);   // before util.php fixes PF_DATA
require_once __DIR__ . '/lib/Deblur.php';
set_time_limit(0);
Deblur::runSetup($argv[2] ?? 'deblur');
