<?php

declare(strict_types=1);

$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestedPath)) {
    $file = __DIR__ . $requestedPath;
    if (is_file($file)) {
        return false;
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
