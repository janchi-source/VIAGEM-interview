<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "OK: {$message}\n";
}

use Viagem\Domain\ParcelNumber;
use Viagem\Geo\Krovak;
use Viagem\Import\GmlGeometryParser;
use Viagem\Database;
use Viagem\Geo\GeometryCodec;
use Viagem\Import\Importer;
use Viagem\Tiles\TileCache;

check(ParcelNumber::format(1234, 5, 0) === '1234/5', 'formatuje parcelu s pododdelením');
check(ParcelNumber::format(56, null, 1) === 'st. 56', 'formatuje stavebnú parcelu');
check(ParcelNumber::parse('123')['kmenove'] === 123, 'parsuje kmeňové číslo');
check(ParcelNumber::parse('st.56/2') === [
    'kmenove' => 56,
    'pododdeleni' => 2,
    'druhCislovani' => 1,
], 'parsuje stavebnú parcelu bez medzery');
check(ParcelNumber::parse('123/4')['pododdeleni'] === 4, 'parsuje pododdelenie');

[$longitude, $latitude] = Krovak::toWgs84(-740000.0, -1040000.0);
check(abs($longitude - 14.4544325) < 0.00001, 'prevádza Křovákovú dĺžku');
check(abs($latitude - 50.1177416) < 0.00001, 'prevádza Křovákovú šírku');

$document = new DOMDocument();
$document->loadXML('<gml:Polygon xmlns:gml="http://www.opengis.net/gml/3.2"><gml:exterior><gml:LinearRing><gml:posList>0 0 1 0 1 1 0 0</gml:posList></gml:LinearRing></gml:exterior></gml:Polygon>');
check(count(GmlGeometryParser::parseMultiPolygon($document->documentElement)) === 1, 'parsuje Polygon');

$fixture = tempnam(sys_get_temp_dir(), 'viagem-');
if ($fixture === false) throw new RuntimeException('Nepodarilo sa vytvoriť dočasný fixture.');
shell_exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/make-fixture.php') . ' ' . escapeshellarg($fixture) . ' 2');
$database = tempnam(sys_get_temp_dir(), 'viagem-db-');
if ($database === false) throw new RuntimeException('Nepodarilo sa vytvoriť dočasnú DB.');
$connection = Database::connect($database);
$counts = (new Importer($connection))->importFile($fixture);
check(($counts['Parcela'] ?? 0) === 5, 'importuje parcely z fixture');
check((int) $connection->query('SELECT COUNT(*) FROM parcel')->fetchColumn() === 5, 'uloží parcely do SQLite');
$geometry = $connection->query('SELECT geom FROM parcel WHERE id = "P1"')->fetchColumn();
check(count(GmlGeometryParser::parseMultiPolygon($document->documentElement)) === 1 && is_string($geometry), 'uloží geometriu parcely');
check(count(\Viagem\Geo\GeometryCodec::decode($geometry)) === 1 && count(\Viagem\Geo\GeometryCodec::decode($geometry)[0]) === 2, 'zachová dieru ako druhý prstenec');
$roundTrip = [[[15.0, 50.0, 15.1, 50.0, 15.1, 50.1, 15.0, 50.0]]];
$decodedRoundTrip = GeometryCodec::decode(GeometryCodec::encode($roundTrip));
check(abs($decodedRoundTrip[0][0][2] - 15.1) < 1e-7 && abs($decodedRoundTrip[0][0][5] - 50.1) < 1e-7, 'kóduje a dekóduje geometriu');
$tileDirectory = sys_get_temp_dir() . '/viagem-tiles-' . bin2hex(random_bytes(3));
$cache = new TileCache($tileDirectory);
$cache->put(1, 2, 3, 'pbf');
check($cache->get(1, 2, 3) === 'pbf', 'číta cache dlaždice');
$cache->clear();
check($cache->get(1, 2, 3) === null, 'čistí cache dlaždíc');
unlink($fixture);
unlink($database);

echo "Všetky testy prešli.\n";
