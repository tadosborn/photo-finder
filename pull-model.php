<?php
declare(strict_types=1);

// Downloads an Ollama model in the background and records progress for the Settings page.
// Started (hidden) by LocalAI::startPull():  php pull-model.php <model> <ollama base URL>
// Command line only; the web server never serves this file.

if (PHP_SAPI !== 'cli' || $argc !== 3) { fwrite(STDERR, "usage: php pull-model.php <model> <base>\n"); exit(2); }
require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/OpenAICompat.php';
require_once __DIR__ . '/lib/LocalAI.php';

$model = $argv[1];
$base = rtrim($argv[2], '/');
if (!OpenAICompat::isLoopbackUrl($base)) { fwrite(STDERR, "not a local address\n"); exit(2); }

$state = ['model' => $model, 'status' => 'starting', 'completed' => 0, 'total' => 0, 'done' => false, 'error' => '', 'updated' => time()];
$layers = [];   // digest => [completed, total]
$lastWrite = 0.0;
$save = function (bool $force = false) use (&$state, &$lastWrite): void {
    if (!$force && microtime(true) - $lastWrite < 0.5) return;
    $state['updated'] = time();
    @file_put_contents(LocalAI::PULL_FILE . '.tmp', json_encode($state));
    @rename(LocalAI::PULL_FILE . '.tmp', LocalAI::PULL_FILE);
    $lastWrite = microtime(true);
};
$save(true);

$buffer = '';
$ch = curl_init($base . '/api/pull');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'stream' => true]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 0,                 // a multi-GB download takes as long as it takes
    CURLOPT_LOW_SPEED_LIMIT => 1,         // ...but not a stalled one
    CURLOPT_LOW_SPEED_TIME => 300,
    CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, &$state, &$layers, $save): int {
        $buffer .= $chunk;
        while (($nl = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $nl));
            $buffer = substr($buffer, $nl + 1);
            if ($line === '') continue;
            $e = json_decode($line, true);
            if (!is_array($e)) continue;
            if (isset($e['error'])) { $state['error'] = (string)$e['error']; continue; }
            $state['status'] = (string)($e['status'] ?? $state['status']);
            if (isset($e['digest'], $e['total'])) {
                $layers[$e['digest']] = [(int)($e['completed'] ?? 0), (int)$e['total']];
                $state['completed'] = array_sum(array_column($layers, 0));
                $state['total'] = array_sum(array_column($layers, 1));
            }
            if (($e['status'] ?? '') === 'success') $state['done'] = true;
        }
        $save();
        return strlen($chunk);
    },
]);
$ok = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err = curl_error($ch);
curl_close($ch);

if (!$state['done'] && $state['error'] === '') {
    $state['error'] = $ok === false ? ('Download stopped: ' . $err) : ('Ollama answered ' . $code . ' without finishing.');
}
$state['done'] = true;
$save(true);
pf_log($state['error'] === '' ? "Model {$model} downloaded." : "Model {$model} download failed: {$state['error']}");
exit($state['error'] === '' ? 0 : 1);
