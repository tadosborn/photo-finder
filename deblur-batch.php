<?php
declare(strict_types=1);

// Runs a Fix blur / Enlarge batch in the background (lib/Deblur.php, runBatch): the photos one
// after another, each saved as one copy next to the original. Started (hidden) by
// Deblur::batchStart():   php deblur-batch.php <data folder>
// Command line only; the web server never serves this file.

if (PHP_SAPI !== 'cli' || $argc !== 2) { fwrite(STDERR, "usage: php deblur-batch.php <data folder>\n"); exit(2); }
if (is_dir($argv[1])) putenv('PHOTOFINDER_DATA=' . $argv[1]);   // before util.php fixes PF_DATA
@proc_nice(19);   // idle priority (Windows: IDLE_PRIORITY_CLASS), inherited by the Python it starts: the app stays responsive
ini_set('memory_limit', '2048M');   // colour correction holds a full-size photo (and a blurred copy) in GD
require_once __DIR__ . '/lib/Deblur.php';
set_time_limit(0);
Deblur::runBatch();
