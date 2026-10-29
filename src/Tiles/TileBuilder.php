<?php

declare(strict_types=1);

namespace Viagem\Tiles;

use PDO;
use Viagem\Geo\GeometryCodec;
use Viagem\Geo\TileMath;

final class TileBuilder
{
    private const EXTENT = 4096;
    private const BUFFER = 64;
    private const MAX_ZOOM = 15;
    private const MIN_PIXELS = [10 => 0.0, 11 => 1.5, 12 => 1.0, 13 => 0.5];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function build(int $z, int $x, int $y): string
    {
        if ($z < 0 || $z > self::MAX_ZOOM || $x < 0 || $y < 0 || $x >= (1 << $z) || $y >= (1 << $z)) {
            throw new \InvalidArgumentException('Neplatné súradnice dlaždice.');
        }
        $bounds = TileMath::tileBounds($z, $x, $y, self::BUFFER);
        $tile = '';
        $layers = [];
        [$ku, $kuLabel] = $this->buildKuLayers($z, $x, $y, $bounds);
        if ($ku !== '') $layers[] = $ku;
        if ($kuLabel !== '') $layers[] = $kuLabel;
        if ($z >= 10) {
            [$parcels, $parcelLabels] = $this->buildParcelLayers($z, $x, $y, $bounds);
            if ($parcels !== '') $layers[] = $parcels;
            if ($parcelLabels !== '') $layers[] = $parcelLabels;
        }
        return implode('', array_map(static fn (string $layer): string => Protobuf::bytes(3, $layer), $layers));
    }

    /** @return array{0: string, 1: string} */
    private function buildKuLayers(int $z, int $x, int $y, array $bounds): array
    {
        $layer = new MvtLayer('ku');
        $labels = new MvtLayer('ku_label');
        $statement = $this->pdo->prepare('SELECT kod, nazev, geom, label_lon, label_lat FROM ku WHERE max_lon >= :min_lon AND min_lon <= :max_lon AND max_lat >= :min_lat AND min_lat <= :max_lat');
        $statement->execute($bounds);
        foreach ($statement as $row) {
            if (!is_string($row['geom'])) continue;
            $polygons = $this->project(GeometryCodec::decode($row['geom']), $z, $x, $y);
            if ($polygons !== []) $layer->addPolygon((int) $row['kod'], $polygons, ['nazev' => $row['nazev']]);
            if ($row['label_lon'] !== null && $row['label_lat'] !== null) {
                $layerLabel = $this->projectPoint((float) $row['label_lon'], (float) $row['label_lat'], $z, $x, $y);
                $labels->addPoint((int) $row['kod'], $layerLabel, ['nazev' => $row['nazev']]);
            }
        }
        return [$layer->encode(), $labels->encode()];
    }

    /** @return array{0: string, 1: string} */
    private function buildParcelLayers(int $z, int $x, int $y, array $bounds): array
    {
        $layer = new MvtLayer('parcels');
        $labels = new MvtLayer('parcel_label');
        $tileBounds = TileMath::tileBounds($z, $x, $y);
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.geom, p.druh_pozemku, p.vymera, p.label_lon, p.label_lat,
                    r.min_lon, r.min_lat, r.max_lon, r.max_lat
             FROM parcel_rtree r INNER JOIN parcel p ON p.rtree_id = r.id
             WHERE r.max_lon >= :min_lon AND r.min_lon <= :max_lon
               AND r.max_lat >= :min_lat AND r.min_lat <= :max_lat'
        );
        $statement->execute($bounds);
        $threshold = self::MIN_PIXELS[$z] ?? 0.0;
        foreach ($statement as $row) {
            $width = TileMath::lonToX((float) $row['max_lon'], $z) - TileMath::lonToX((float) $row['min_lon'], $z);
            $height = TileMath::latToY((float) $row['max_lat'], $z) - TileMath::latToY((float) $row['min_lat'], $z);
            if ($threshold > 0.0 && max(abs($width), abs($height)) * self::EXTENT < $threshold) continue;
            if (!is_string($row['geom'])) continue;
            $polygons = $this->project(GeometryCodec::decode($row['geom']), $z, $x, $y);
            if ($polygons !== []) {
                $layer->addPolygon($this->featureId((string) $row['id']), $polygons, ['druh' => $row['druh_pozemku'], 'vymera' => $row['vymera']]);
            }
            $labelInTile = $row['label_lon'] !== null && $row['label_lat'] !== null
                && (float) $row['label_lon'] >= $tileBounds['min_lon']
                && (float) $row['label_lon'] <= $tileBounds['max_lon']
                && (float) $row['label_lat'] >= $tileBounds['min_lat']
                && (float) $row['label_lat'] <= $tileBounds['max_lat'];
            if ($z === self::MAX_ZOOM && $labelInTile) {
                $point = $this->projectPoint((float) $row['label_lon'], (float) $row['label_lat'], $z, $x, $y);
                $labels->addPoint($this->featureId((string) $row['id']), $point, ['cislo' => (string) $row['id']]);
            }
        }
        return [$layer->encode(), $labels->encode()];
    }

    /** @param list<list<list<float>>> $multipolygon @return list<list<list<float>>> */
    private function project(array $multipolygon, int $z, int $x, int $y): array
    {
        $result = [];
        foreach ($multipolygon as $polygon) {
            $rings = [];
            foreach ($polygon as $ring) {
                $projected = [];
                for ($i = 0; $i + 1 < count($ring); $i += 2) {
                    $point = $this->projectPoint($ring[$i], $ring[$i + 1], $z, $x, $y);
                    $projected[] = $point[0]; $projected[] = $point[1];
                }
                $clipped = Geometry::clipRing($projected, -self::BUFFER, -self::BUFFER, self::EXTENT + self::BUFFER, self::EXTENT + self::BUFFER);
                $simplified = Geometry::quantizeAndSimplify($clipped);
                if ($simplified !== []) $rings[] = $simplified;
            }
            if ($rings !== []) $result[] = $rings;
        }
        return $result;
    }

    /** @return array{0: int, 1: int} */
    private function projectPoint(float $lon, float $lat, int $z, int $x, int $y): array
    {
        return [
            (int) round((TileMath::lonToX($lon, $z) - $x) * self::EXTENT),
            (int) round((TileMath::latToY($lat, $z) - $y) * self::EXTENT),
        ];
    }

    private function featureId(string $id): int
    {
        return is_numeric($id) ? (int) $id : (int) sprintf('%u', crc32($id));
    }
}
