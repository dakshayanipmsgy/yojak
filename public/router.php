<?php

declare(strict_types=1);

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicPath = __DIR__ . $uriPath;

if ($uriPath !== '/' && is_file($publicPath)) {
    $mimeType = mime_content_type($publicPath) ?: 'application/octet-stream';
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($publicPath));
    readfile($publicPath);
    return true;
}

require __DIR__ . '/index.php';
