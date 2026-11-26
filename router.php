<?php
declare(strict_types=1);

// Router for PHP's built-in web server, which installed copies use instead of Apache:
//   php -S 127.0.0.1:8765 -t <app> <app>/router.php
//
// The server listens on loopback only, but a loopback app with no login is still
// reachable from any web page the user visits (an <img> or a form aimed at
// 127.0.0.1). api.php has state-changing GETs (clear_all) and file-deleting POSTs,
// so every request must come from this app's own pages. Browsers label that with
// Sec-Fetch-Site; the Host check defeats DNS rebinding.

$port = (string)($_SERVER['SERVER_PORT'] ?? '');
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$self = ["127.0.0.1:{$port}", "localhost:{$port}"];

function pf_router_refuse(int $code, string $why): bool {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $why;
    return true;
}

if (!in_array($host, $self, true)) {
    return pf_router_refuse(403, 'Photo Finder only answers on http://127.0.0.1:' . $port . '/');
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
$mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
$origin = strtolower((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

// Opening the app itself from a link, a bookmark or the Start menu is fine.
$isPageOpen = $method === 'GET' && ($path === '/' || $path === '/index.php') && ($mode === '' || $mode === 'navigate');
if (!$isPageOpen) {
    if ($site !== '' && $site !== 'same-origin' && $site !== 'none') {
        return pf_router_refuse(403, 'Cross-site request refused.');
    }
    if ($origin !== '' && !in_array($origin, array_map(fn($h) => 'http://' . $h, $self), true)) {
        return pf_router_refuse(403, 'Cross-origin request refused.');
    }
}

// Only the app's entry points are served. data/ (index, settings with any API key),
// lib/, php/, bin/ and the maintenance scripts are never reachable over HTTP.
$public = ['/' => true, '/index.php' => true, '/api.php' => true, '/file.php' => true, '/thumb.php' => true];
if (!isset($public[$path])) {
    return pf_router_refuse(404, 'Not found');
}
return false; // let the built-in server run the entry point ('/' resolves to index.php)
