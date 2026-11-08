<?php
/**
 * NetworkMonitor - Sicherheits-Router für den eingebauten PHP-Webserver (php -S)
 *
 * Da der integrierte PHP-Webserver (.deb-Paket / systemd-Dienst) keine .htaccess-Dateien
 * auswertet, übernimmt dieses Router-Skript die strikte Zugriffskontrolle (Whitelisting):
 * - Blockiert (HTTP 403 Forbidden) jeden direkten HTTP-Zugriff auf:
 *     - /data/* (SQLite-Datenbank network_monitor.sqlite, custom_config.json, Logs)
 *     - /includes/* (interne PHP-Klassen)
 *     - /packaging/* (Build-Skripte)
 *     - /config.php, /cli_scan.php, /router.php, /database.sql, /VERSION, /*.md, /.git*
 * - Erlaubt ausschließlich die definierten öffentlichen Einstiegspunkte
 *   (index.php, login.php, api.php, hilfe.php) sowie statische Assets unter /assets/*.
 * - Setzt automatisch HTTP-Security-Header für alle Antworten.
 */

if (!headers_sent()) {
    if (function_exists('header_remove')) {
        @header_remove('X-Powered-By');
    }
    @header('X-Frame-Options: SAMEORIGIN');
    @header('X-Content-Type-Options: nosniff');
    @header('Referrer-Policy: same-origin');
    @header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    @header("Content-Security-Policy: frame-ancestors 'self'");
}

$rawUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
$qPos = strpos($rawUri, '?');
$uriPath = ($qPos !== false) ? substr($rawUri, 0, $qPos) : $rawUri;
$uriPath = rawurldecode($uriPath);

if ($uriPath === '' || $uriPath === '/') {
    $uriPath = '/index.php';
}

// Verhindere Pfad-Traversal (.. oder Null-Bytes)
if (strpos($uriPath, '..') !== false || strpos($uriPath, "\0") !== false) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    exit;
}

// Statische Assets unter /assets/ zulassen (CSS, JS, Icons, Fonts)
if (preg_match('#^/assets/[a-zA-Z0-9._-]+\.(css|js|svg|png|jpg|jpeg|gif|ico|woff|woff2|ttf|map)$#i', $uriPath)) {
    $assetFile = __DIR__ . $uriPath;
    if (is_file($assetFile)) {
        return false; // Vom PHP Built-in Webserver direkt mit passendem MIME-Type ausliefern
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    exit;
}

if ($uriPath === '/favicon.ico') {
    http_response_code(204);
    exit;
}

// Whitelist erlaubter PHP-Endpunkte
$allowedEndpoints = array(
    '/index.php' => __DIR__ . '/index.php',
    '/login.php' => __DIR__ . '/login.php',
    '/api.php'   => __DIR__ . '/api.php',
    '/hilfe.php' => __DIR__ . '/hilfe.php',
);

if (isset($allowedEndpoints[$uriPath]) && is_file($allowedEndpoints[$uriPath])) {
    $_SERVER['SCRIPT_NAME'] = $uriPath;
    $_SERVER['SCRIPT_FILENAME'] = $allowedEndpoints[$uriPath];
    require $allowedEndpoints[$uriPath];
    exit;
}

// Alles andere (insb. /data/*, /includes/*, /config.php, /database.sql, /cli_scan.php) strikt abweisen
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo '403 Forbidden - Direct access denied.';
exit;
