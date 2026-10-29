<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Viagem\Database;
use Viagem\Import\Importer;
use Viagem\Import\VfrDownloader;
use Viagem\Tiles\TileCache;

$options = getopt('', ['file:', 'obce:', 'date:']);
$config = require dirname(__DIR__) . '/config.php';
$root = dirname(__DIR__);
$file = $options['file'] ?? null;
$date = $options['date'] ?? null;
$requestedMunicipalities = [];
if (isset($options['obce'])) {
    foreach (explode(',', (string) $options['obce']) as $code) {
        if ($code !== '' && ctype_digit($code)) $requestedMunicipalities[] = (int) $code;
    }
}
if ($file === null) {
    $downloader = new VfrDownloader((string) $config['cuzk_base_url'], (string) $config['vfr_path']);
    $date ??= $downloader->latestDate();
    $file = $downloader->download($date);
}
if (!is_file($file)) throw new RuntimeException('VFR súbor neexistuje: ' . $file);

$databasePath = (string) $config['db_path'];
$temporaryDatabase = $databasePath . '.tmp';
if (is_file($temporaryDatabase) && !unlink($temporaryDatabase)) throw new RuntimeException('Nepodarilo sa odstrániť dočasnú DB.');
$pdo = Database::connect($temporaryDatabase);
$counts = (new Importer($pdo))->importFile($file, $requestedMunicipalities === [] ? null : $requestedMunicipalities);
$municipalities = $requestedMunicipalities;
if ($municipalities === []) {
    foreach ((new \Viagem\Import\VfrReader())->records($file, ['Obec']) as $record) {
        if (($record['KodOkresu'] ?? null) === (int) $config['district_code'] && isset($record['KodObce'])) {
            $municipalities[] = (int) $record['KodObce'];
        }
    }
}
$municipalities = array_values(array_unique($municipalities));
$jsonPath = (string) $config['vfr_path'] . '/' . ($date ?? 'import') . '_obce.json';
if (!is_dir(dirname($jsonPath))) mkdir(dirname($jsonPath), 0775, true);
file_put_contents($jsonPath, json_encode($municipalities, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$parcelCount = $counts['Parcela'] ?? $counts['Parcel'] ?? 0;
$pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')->execute(['imported_at', gmdate(DATE_ATOM)]);
$pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')->execute(['parcel_count', (string) $parcelCount]);
$pdo->exec('UPDATE ku SET parcel_count = (SELECT COUNT(*) FROM parcel WHERE parcel.ku_kod = ku.kod)');
$pdo->exec('ANALYZE');
$pdo->exec('VACUUM');
unset($pdo);
if (!rename($temporaryDatabase, $databasePath)) throw new RuntimeException('Nepodarilo sa aktivovať novú DB.');
(new TileCache((string) $config['tiles_path']))->clear();

foreach ($counts as $type => $count) echo $type . ': ' . $count . PHP_EOL;
