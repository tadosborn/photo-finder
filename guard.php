<?php
declare(strict_types=1);

// Runs before every request (apache/httpd.conf: php_value auto_prepend_file).
//
// The server listens on loopback only, but a loopback app with no login is still reachable
// from any web page the user visits (an <img> or a form aimed at 127.0.0.1). api.php has
// state-changing GETs (clear_all) and file-moving POSTs, so every request must come from this
// app's own pages. Browsers label that with Sec-Fetch-Site; the Host check defeats DNS
// rebinding. Which files are reachable at all is decided in httpd.conf.

(static function (): void {
    if (PHP_SAPI === 'cli') return;   // never applies to the worker or command-line scripts

    $port = (string)($_SERVER['SERVER_PORT'] ?? '');
    $self = ["127.0.0.1:{$port}", "localhost:{$port}"];
    $refuse = static function (int $code, string $why): void {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $why;
        exit;
    };

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!in_array($host, $self, true)) {
        $refuse(403, 'Photo Finder only answers on http://127.0.0.1:' . $port . '/');
    }

    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
    $origin = strtolower((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

    // Opening the app itself from a link, a bookmark or the Start menu is fine.
    $isPageOpen = $method === 'GET' && ($path === '/' || $path === '/index.php') && ($mode === '' || $mode === 'navigate');
    if ($isPageOpen) return;

    if ($site !== '' && $site !== 'same-origin' && $site !== 'none') {
        $refuse(403, 'Cross-site request refused.');
    }
    if ($origin !== '' && !in_array($origin, array_map(static fn($h) => 'http://' . $h, $self), true)) {
        $refuse(403, 'Cross-origin request refused.');
    }
})();
