<?php
/**
 * UniGo - global config, bootstrapped on every page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE_URL', '/unigo'); // change to match your local path/vhost
define('APP_NAME', 'UniGo');

// Get a key at https://console.cloud.google.com/google/maps-apis
// (enable "Maps JavaScript API"), then restrict it to your domain.
define('GOOGLE_MAPS_API_KEY', 'YOUR_GOOGLE_MAPS_API_KEY');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * A failed CSRF check throws CsrfException (so it stays unit-testable).
 * This handler converts it into a proper 403 for real requests, and keeps
 * the message deliberately vague so it cannot be used as an oracle.
 */
set_exception_handler(function (Throwable $e) {
    if ($e instanceof CsrfException) {
        http_response_code(403);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html><head><title>403 - Request Rejected</title></head><body '
            . 'style="font-family:Calibri,Arial,sans-serif;max-width:560px;margin:80px auto;color:#1c2321">'
            . '<h2 style="color:#8c1d1d">403 &mdash; Request rejected</h2>'
            . '<p>This request could not be verified as coming from this site, so it was not processed.</p>'
            . '<p style="color:#556">This usually means the page was left open for a long time before you '
            . 'submitted it. Please go back, reload the page, and try again.</p>'
            . '<p><a href="' . BASE_URL . '/">Return to UniGo</a></p>'
            . '</body></html>';
        exit;
    }

    // Anything else is an unexpected failure. Log it, show nothing useful.
    error_log('[UniGo] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo '<h2>Something went wrong</h2><p>The request could not be completed. Please try again.</p>';
    exit;
});

