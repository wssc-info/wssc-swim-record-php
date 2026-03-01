<?php
/**
 * PHP built-in server router for the West Side Record Board SPA.
 *
 * Usage:
 *   php -S localhost:8080 router.php   (from phpRecordManagement/)
 *
 * Routing rules:
 *   /api/*          → let PHP handle (records.php API)
 *   /assets/*, etc. → serve the static file as-is
 *   anything else   → serve index.html  (SPA client-side routing fallback)
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// --- API requests: let PHP dispatch normally ---------------------------------
if (str_starts_with($uri, '/api/')) {
    return false;
}

// --- Static files that actually exist on disk --------------------------------
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;   // built-in server will serve the file with correct MIME type
}

// --- SPA fallback: everything else gets index.html --------------------------
// (React Router / client-side navigation)
$index = __DIR__ . '/index.html';
if (!file_exists($index)) {
    http_response_code(503);
    echo '<pre>index.html not found — run: npm run build (from west-side-records-react/)</pre>';
    exit;
}

readfile($index);
