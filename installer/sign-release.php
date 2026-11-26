<?php
declare(strict_types=1);

// Signs one Photo Finder release for the update feed. Build-time only; never shipped.
//   php sign-release.php <version> <sha256> <installer file name>   (secret key, base64, on STDIN)
// Prints the base64 Ed25519 signature, after checking it against the public key(s)
// compiled into lib/version.php — so a release signed with a key the app does not
// trust can never be produced by accident.
// The message comes from Updater::signedMessage(), the same function the app verifies with.

if (PHP_SAPI !== 'cli' || $argc !== 4) { fwrite(STDERR, "usage: php sign-release.php <version> <sha256> <file>\n"); exit(2); }
require_once __DIR__ . '/../lib/Updater.php';

[, $version, $sha, $file] = $argv;
if (!preg_match('/^[a-f0-9]{64}$/', $sha)) { fwrite(STDERR, "bad sha256\n"); exit(2); }
$sk = base64_decode(trim((string)stream_get_contents(STDIN)), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { fwrite(STDERR, "secret key missing or malformed\n"); exit(2); }

$sig = base64_encode(sodium_crypto_sign_detached(Updater::signedMessage($version, $sha, $file), $sk));
sodium_memzero($sk);
if (!Updater::signatureValid($version, $sha, $file, $sig)) {
    fwrite(STDERR, "signature does not verify against PF_UPDATE_KEYS in lib/version.php (wrong key?)\n");
    exit(1);
}
echo $sig;
