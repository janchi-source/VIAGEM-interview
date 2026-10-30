<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/MvtDecoder.php';

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
use Viagem\Tiles\MvtLayer;
use Viagem\Tiles\TileBuilder;
use Viagem\Tiles\Geometry;
use Viagem\Geo\TileMath;
use Viagem\Http\App;

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
$mvtLayer = new MvtLayer('test');
$mvtLayer->addPolygon(5000000000, [[[0, 0, 100, 0, 100, 100, 0, 100]]], ['druh' => 'orná pôda']);
$decoded = MvtDecoder::decode(\Viagem\Tiles\Protobuf::bytes(3, $mvtLayer->encode()));
check($decoded['test'][0]['id'] === 5000000000, 'MVT zachová 64-bitové ID');
check(count($decoded['test']) === 1, 'MVT dekóduje počet prvkov');
check(($decoded['test'][0]['properties']['druh'] ?? null) === 'orná pôda', 'MVT zachová atribúty');
check(count(Geometry::clipRing([-10, 10, 100, 10, 100, 100, -10, 10], 0, 0, 50, 50)) >= 6, 'oreže prstenec na dlaždicu');
$simplified = Geometry::quantizeAndSimplify([0, 0, 1, 0, 2, 0, 3, 0, 3, 3, 0, 0]);
check(count($simplified) >= 6, 'zjednoduší prstenec Douglas-Peuckerom');
$tileX = (int) floor(TileMath::lonToX(15.35, 13));
$tileY = (int) floor(TileMath::latToY(50.43, 13));
$tileBuilder = new TileBuilder($connection);
$tileZ9 = $tileBuilder->build(9, (int) floor(TileMath::lonToX(15.35, 9)), (int) floor(TileMath::latToY(50.43, 9)));
check(!isset(MvtDecoder::decode($tileZ9)['parcels']), 'na nízkom zoome nevytvára parcelnú vrstvu');
$tileZ12 = $tileBuilder->build(12, (int) floor(TileMath::lonToX(15.35, 12)), (int) floor(TileMath::latToY(50.43, 12)));
check(!isset(MvtDecoder::decode($tileZ12)['parcels']), 'pod z13 nevytvára parcelnú vrstvu');
$tile = $tileBuilder->build(13, $tileX, $tileY);
$tileDecoded = MvtDecoder::decode($tile);
check(isset($tileDecoded['parcels']) && count($tileDecoded['parcels']) > 0, 'builder vytvorí parcelnú dlaždicu');
$validCommands = true;
foreach ($tileDecoded['parcels'] as $feature) {
    $commands = implode('', array_column($feature['geometry'], 'command'));
    $validCommands = $validCommands && preg_match('/^(127)+$/', $commands) === 1
        && min(array_map(static fn (array $step): int => $step['command'] === 2 ? count($step['values']) : PHP_INT_MAX, $feature['geometry'])) >= 4;
}
check($validCommands, 'polygóny majú platné MVT príkazy MoveTo, LineTo, ClosePath');
$tileZ15 = $tileBuilder->build(15, (int) floor(TileMath::lonToX(15.35, 15)), (int) floor(TileMath::latToY(50.43, 15)));
check(isset(MvtDecoder::decode($tileZ15)['parcel_label']), 'builder vytvorí parcelné popisky na z15');
$httpCacheDirectory = sys_get_temp_dir() . '/viagem-http-' . bin2hex(random_bytes(3));
$app = new App($connection, new TileCache($httpCacheDirectory));
check($app->handle('GET', '/api/parcel/P1')->status === 200, 'App vráti parcelu');
check($app->handle('GET', '/api/parcels/P1')->status === 200, 'App vráti parcelu aj v množnom čísle');
$tileId = (int) $connection->query("SELECT rtree_id FROM parcel WHERE id = 'P1'")->fetchColumn();
$byTileId = json_decode($app->handle('GET', '/api/parcels/' . $tileId)->body, true);
check(($byTileId['id'] ?? null) === 'P1' && str_ends_with((string) ($byTileId['odkaz'] ?? ''), 'id=P1'), 'App nájde parcelu podľa ID z dlaždice a odkaz nesie ID parcely');
check($app->handle('GET', '/api/parcel/unknown')->status === 404, 'App vráti 404 pre neznámu parcelu');
check($app->handle('GET', '/api/parcels/search', ['text' => '101'])->status === 200, 'App vyhľadá parcelu');
check($app->handle('GET', '/api/parcels/search')->status === 400, 'App odmietne neúplné vyhľadávanie');
check($app->handle('POST', '/api/stats')->status === 405, 'App odmietne inú HTTP metódu');
check($app->handle('GET', '/api/nope')->status === 404, 'App vráti 404 pre neznámu cestu');
$cachedTile = $app->handle('GET', '/tiles/13/' . $tileX . '/' . $tileY . '.pbf')->body;
check($cachedTile !== '' && is_file($httpCacheDirectory . '/13/' . $tileX . '/' . $tileY . '.pbf'), 'App uloží dlaždicu do cache');
(new TileCache($httpCacheDirectory))->clear();
unlink($fixture);
unlink($database);

echo "Všetky testy prešli.\n";
