<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';

use Viagem\Database;
use Viagem\Geo\TileMath;
use Viagem\Tiles\TileBuilder;
use Viagem\Tiles\TileCache;

const MIN_ZOOM = 9;
const MAX_ZOOM = 15;

$options = getopt('', ['district']);
$databasePath = (string) $config['db_path'];
if (!is_file($databasePath)) throw new RuntimeException('Databáza neexistuje, najprv spusti bin/import.php: ' . $databasePath);
$pdo = Database::connect($databasePath, true);
if (isset($options['district'])) {
    // Celý okres: obálka všetkých katastrálnych území.
    $row = $pdo->query('SELECT MIN(min_lon) AS min_lon, MIN(min_lat) AS min_lat, MAX(max_lon) AS max_lon, MAX(max_lat) AS max_lat FROM ku')->fetch();
    if (!is_array($row) || $row['min_lon'] === null) throw new RuntimeException('Tabuľka ku je prázdna, najprv spusti bin/import.php.');
    $bbox = array_map('floatval', $row);
} else {
    // Okolie Jičína, kam sa mapa otvára.
    $bbox = ['min_lon' => 15.30, 'min_lat' => 50.41, 'max_lon' => 15.41, 'max_lat' => 50.46];
}

$tiles = [];
for ($z = MIN_ZOOM; $z <= MAX_ZOOM; $z++) {
    $range = TileMath::tileRange($z, $bbox);
    for ($x = $range['min_x']; $x <= $range['max_x']; $x++) {
        for ($y = $range['min_y']; $y <= $range['max_y']; $y++) $tiles[] = [$z, $x, $y];
    }
}

$builder = new TileBuilder($pdo);
$cache = new TileCache((string) $config['tiles_path']);
$total = count($tiles);
$built = 0;
$skipped = 0;
$started = microtime(true);
fwrite(STDERR, sprintf("Dlaždice z%d–z%d, obálka %.3f,%.3f – %.3f,%.3f: %d\n", MIN_ZOOM, MAX_ZOOM, $bbox['min_lon'], $bbox['min_lat'], $bbox['max_lon'], $bbox['max_lat'], $total));
foreach ($tiles as $index => [$z, $x, $y]) {
    if ($cache->get($z, $x, $y) !== null) {
        $skipped++;
        continue;
    }
    $tileStarted = microtime(true);
    $cache->put($z, $x, $y, $builder->build($z, $x, $y));
    $built++;
    fwrite(STDERR, sprintf("[%d/%d] %d/%d/%d %.0f ms\n", $index + 1, $total, $z, $x, $y, (microtime(true) - $tileStarted) * 1000));
}
fwrite(STDERR, sprintf("Hotovo: %d vytvorených, %d preskočených, %.1f s\n", $built, $skipped, microtime(true) - $started));
