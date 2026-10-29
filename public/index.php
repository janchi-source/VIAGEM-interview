<?php

declare(strict_types=1);

$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestedPath)) {
    if ($requestedPath === '/') {
        readfile(__DIR__ . '/index.html');
        return true;
    }
    $file = __DIR__ . $requestedPath;
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';
try {
    $database = \Viagem\Database::connect((string) $config['db_path'], true);
    $app = new \Viagem\Http\App($database, new \Viagem\Tiles\TileCache((string) $config['tiles_path']));
    $app->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', is_string($requestedPath) ? $requestedPath : '/', $_GET)->send();
} catch (\Throwable $error) {
    error_log($error->__toString());
    \Viagem\Http\Response::error('Služba je dočasne nedostupná.', 503)->send();
}
