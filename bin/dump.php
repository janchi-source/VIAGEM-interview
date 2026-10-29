<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Viagem\Import\VfrReader;

$path = $argv[1] ?? throw new InvalidArgumentException('Použitie: bin/dump.php súbor.xml[.zip]');
$counts = [];
$parcels = [];
foreach ((new VfrReader())->records($path) as $record) {
    $type = (string) $record['type'];
    $counts[$type] = ($counts[$type] ?? 0) + 1;
    if (in_array($type, ['Parcela', 'Parcel'], true) && count($parcels) < 3) $parcels[] = $record;
}
foreach ($counts as $type => $count) echo $type . ': ' . $count . PHP_EOL;
foreach ($parcels as $parcel) {
    echo json_encode($parcel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
}
