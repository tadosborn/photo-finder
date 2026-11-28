<?php
declare(strict_types=1);

// Finds the faces in the photos not scanned yet, in the background (lib/People.php, runScan).
// Started (hidden) by People::scanStart():   php faces-job.php <data folder>
// Command line only; the web server never serves this file.

if (PHP_SAPI !== 'cli' || $argc !== 2) { fwrite(STDERR, "usage: php faces-job.php <data folder>\n"); exit(2); }
if (is_dir($argv[1])) putenv('PHOTOFINDER_DATA=' . $argv[1]);   // before util.php fixes PF_DATA
@proc_nice(19);   // idle priority, inherited by the Python it starts: the app stays responsive
ini_set('memory_limit', '1024M');
require_once __DIR__ . '/lib/People.php';
set_time_limit(0);
People::runScan();
