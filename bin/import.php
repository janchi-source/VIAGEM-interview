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
$databasePath = (string) $config['db_path'];
$temporaryDatabase = $databasePath . '.tmp';
if (is_file($temporaryDatabase) && !unlink($temporaryDatabase)) throw new RuntimeException('Nepodarilo sa odstrániť dočasnú DB.');
$pdo = Database::connect($temporaryDatabase);
$importer = new Importer($pdo);
$counts = [];
$municipalities = $requestedMunicipalities;
if ($file !== null) {
    // Jeden lokálny VFR súbor (napr. OB_xxxxxx_UKSH alebo fixture).
    if (!is_file($file)) throw new RuntimeException('VFR súbor neexistuje: ' . $file);
    $counts = $importer->importFile($file, $requestedMunicipalities === [] ? null : $requestedMunicipalities);
} else {
    // ST_UKSG nesie len správnu štruktúru; parcely sú v súboroch OB_<kód obce>_UKSH.
    $downloader = new VfrDownloader((string) $config['cuzk_base_url'], (string) $config['vfr_path']);
    $date ??= $downloader->latestDate();
    if ($municipalities === []) {
        $structure = $downloader->download($date);
        foreach ((new \Viagem\Import\VfrReader())->records($structure, ['Obec']) as $record) {
            if (($record['KodOkresu'] ?? null) === (int) $config['district_code'] && isset($record['KodObce'])) $municipalities[] = (int) $record['KodObce'];
        }
    }
    $municipalities = array_values(array_unique($municipalities));
    if ($municipalities === []) throw new RuntimeException('Okres ' . $config['district_code'] . ' nemá v ST_UKSG žiadne obce.');
    foreach ($municipalities as $index => $code) {
        $municipalityFile = $downloader->download($date, 'OB_' . $code . '_UKSH');
        foreach ($importer->importFile($municipalityFile) as $type => $count) $counts[$type] = ($counts[$type] ?? 0) + $count;
        unlink($municipalityFile);
        fwrite(STDERR, sprintf("[%d/%d] obec %d, parcely spolu: %d\n", $index + 1, count($municipalities), $code, $counts['Parcela'] ?? 0));
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
