<?php
declare(strict_types=1);

// Build helper: prints the DLLs that the given Windows binaries need from a search folder,
// following each DLL's own imports. build.ps1 uses it to copy only the Apache DLLs the app's
// modules actually load.
//   php pe-deps.php <searchDir> <binary> [<binary> ...]
// Prints one file name per line (lower case). Exit 1 if a binary cannot be read.

if (PHP_SAPI !== 'cli' || $argc < 3) { fwrite(STDERR, "usage: php pe-deps.php <searchDir> <binary>...\n"); exit(2); }

/** Names of the DLLs a PE file imports (import directory only). */
function pe_imports(string $file): ?array {
    $d = @file_get_contents($file);
    if ($d === false || strlen($d) < 0x40 || substr($d, 0, 2) !== 'MZ') return null;
    $pe = unpack('V', substr($d, 0x3c, 4))[1];
    if (substr($d, $pe, 4) !== "PE\0\0") return null;
    $coff = $pe + 4;
    $nSec = unpack('v', substr($d, $coff + 2, 2))[1];
    $optSize = unpack('v', substr($d, $coff + 16, 2))[1];
    $opt = $coff + 20;
    $magic = unpack('v', substr($d, $opt, 2))[1];
    $impRva = unpack('V', substr($d, $opt + ($magic === 0x20b ? 112 : 96) + 8, 4))[1];
    if ($impRva === 0) return [];
    $secs = [];
    for ($i = 0, $s = $opt + $optSize; $i < $nSec; $i++, $s += 40) {
        $secs[] = unpack('Vvsize/Vva/Vrawsize/Vraw', substr($d, $s + 8, 16));
    }
    $off = static function (int $rva) use ($secs): ?int {
        foreach ($secs as $x) {
            if ($rva >= $x['va'] && $rva < $x['va'] + max($x['vsize'], $x['rawsize'])) return $rva - $x['va'] + $x['raw'];
        }
        return null;
    };
    $o = $off($impRva);
    if ($o === null) return null;
    $out = [];
    for (;; $o += 20) {
        $e = unpack('Vilt/Vts/Vfc/Vname/Viat', substr($d, $o, 20));
        if ($e['name'] === 0) break;
        $n = $off($e['name']);
        if ($n === null) return null;
        $out[] = strtolower(substr($d, $n, strpos($d, "\0", $n) - $n));
    }
    return $out;
}

$dir = rtrim(str_replace('\\', '/', $argv[1]), '/');
$queue = array_slice($argv, 2);
$found = [];
while ($queue) {
    $f = array_shift($queue);
    $imports = pe_imports($f);
    if ($imports === null) { fwrite(STDERR, "cannot read imports of $f\n"); exit(1); }
    foreach ($imports as $dll) {
        if (isset($found[$dll]) || !is_file("$dir/$dll")) continue;   // system DLLs are not in $dir
        $found[$dll] = true;
        $queue[] = "$dir/$dll";
    }
}
ksort($found);
echo implode("\n", array_keys($found)), "\n";
