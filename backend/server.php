<?php

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Emulate Apache mod_rewrite: serve static files directly if they exist
if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri)) {
    return false;
}

require_once __DIR__ . '/public/index.php';
