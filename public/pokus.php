<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Viagem\Database;
use Viagem\Geo\GeometryCodec;

header('Content-Type: application/geo+json; charset=utf-8');

try {
    $parts = explode(',', (string) ($_GET['bbox'] ?? ''));
    if (count($parts) !== 4 || array_filter($parts, static fn (string $value): bool => !is_numeric(trim($value)))) {
        throw new InvalidArgumentException('Použite bbox=minLon,minLat,maxLon,maxLat.');
    }
    [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
    if ($minLon >= $maxLon || $minLat >= $maxLat || $minLon < -180 || $maxLon > 180 || $minLat < -90 || $maxLat > 90) {
        throw new InvalidArgumentException('Bbox má neplatné rozsahy.');
    }

    $pdo = Database::connect((string) $config['db_path'], true);
    $statement = $pdo->prepare(
        'SELECT p.id, p.kmenove_cislo, p.pododdeleni, p.druh_cislovani,
                p.druh_pozemku, p.zpusob_vyuziti, p.vymera, p.bpej,
                p.label_lon, p.label_lat, p.geom
         FROM parcel_rtree r
         INNER JOIN parcel p ON p.rtree_id = r.id
         WHERE r.max_lon >= :min_lon AND r.min_lon <= :max_lon
           AND r.max_lat >= :min_lat AND r.min_lat <= :max_lat'
    );
    $statement->execute([
        'min_lon' => $minLon,
        'max_lon' => $maxLon,
        'min_lat' => $minLat,
        'max_lat' => $maxLat,
    ]);

    $features = [];
    while ($row = $statement->fetch()) {
        if (!is_string($row['geom'])) continue;
        $properties = [
            'id' => $row['id'],
            'kmenoveCislo' => $row['kmenove_cislo'],
            'pododdeleni' => $row['pododdeleni'],
            'druhCislovani' => $row['druh_cislovani'],
            'druhPozemku' => $row['druh_pozemku'],
            'zpusobVyuziti' => $row['zpusob_vyuziti'],
            'vymera' => $row['vymera'],
            'bpej' => json_decode((string) $row['bpej'], true, 512, JSON_THROW_ON_ERROR),
        ];
        $features[] = [
            'type' => 'Feature',
            'id' => $row['id'],
            'properties' => $properties,
            'geometry' => json_decode(GeometryCodec::toGeoJson($row['geom']), true, 512, JSON_THROW_ON_ERROR),
        ];
    }
    echo json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 500);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
