<?php

declare(strict_types=1);

// Vercel funkcia: /api/* a /tiles/* z public/index.php, súbory systému sú len na čítanie.
require dirname(__DIR__) . '/bootstrap.php';

$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
try {
    $database = \Viagem\Database::connect((string) $config['db_path'], true);
    $app = new \Viagem\Http\App($database, new \Viagem\Tiles\TileCache(sys_get_temp_dir() . '/viagem-tiles'));
    $app->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', is_string($requestedPath) ? $requestedPath : '/', $_GET)->send();
} catch (\Throwable $error) {
    error_log($error->__toString());
    \Viagem\Http\Response::error('Služba je dočasne nedostupná.', 503)->send();
}
