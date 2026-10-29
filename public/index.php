<?php

declare(strict_types=1);

$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestedPath)) {
    $file = __DIR__ . $requestedPath;
    if (is_file($file)) {
        return false;
    }
    if (preg_match('#^/tiles/(\\d+)/(\\d+)/(\\d+)\\.pbf$#', $requestedPath, $matches) === 1) {
        require dirname(__DIR__) . '/bootstrap.php';
        $cache = new \Viagem\Tiles\TileCache((string) $config['tiles_path']);
        $z = (int) $matches[1]; $x = (int) $matches[2]; $y = (int) $matches[3];
        $tile = $cache->get($z, $x, $y);
        if ($tile === null) {
            $database = \Viagem\Database::connect((string) $config['db_path'], true);
            $tile = (new \Viagem\Tiles\TileBuilder($database))->build($z, $x, $y);
            $cache->put($z, $x, $y, $tile);
        }
        header('Content-Type: application/vnd.mapbox-vector-tile');
        header('Cache-Control: public, max-age=86400');
        echo $tile;
        return;
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
