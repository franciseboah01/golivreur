<?php

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Emulate Apache mod_rewrite: serve static files directly if they exist
$publicFile = __DIR__.'/public'.$uri;
if ($uri !== '/' && file_exists($publicFile) && is_file($publicFile)) {
    // Injecter les en-têtes CORS sur les médias (/storage, /images) pour Flutter Web (Chrome)
    if (preg_match('#^/(storage|images)/#', $uri)) {
        $ext = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => (function_exists('mime_content_type') ? (mime_content_type($publicFile) ?: 'application/octet-stream') : 'application/octet-stream'),
        };

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Content-Type: '.$mime);
        header('Content-Length: '.filesize($publicFile));
        readfile($publicFile);

        return true;
    }

    return false;
}

require_once __DIR__.'/public/index.php';
