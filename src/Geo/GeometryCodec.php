<?php

declare(strict_types=1);

namespace Viagem\Geo;

final class GeometryCodec
{
    private const SCALE = 10000000;

    /** @param list<list<list<float>>> $multipolygon */
    public static function encode(array $multipolygon): string
    {
        $blob = pack('V', count($multipolygon));
        foreach ($multipolygon as $polygon) {
            $blob .= pack('V', count($polygon));
            foreach ($polygon as $ring) {
                $points = count($ring) / 2;
                if ($points !== (int) $points) {
                    throw new \InvalidArgumentException('Prstenec musí obsahovať dvojice súradníc.');
                }
                $blob .= pack('V', (int) $points);
                for ($i = 0; $i < count($ring); $i += 2) {
                    $blob .= pack('l2', (int) round($ring[$i] * self::SCALE), (int) round($ring[$i + 1] * self::SCALE));
                }
            }
        }
        return $blob;
    }

    /** @return list<list<list<float>>> */
    public static function decode(string $blob): array
    {
        $offset = 0;
        $polygons = [];
        $polygonCount = self::readUint($blob, $offset);
        for ($p = 0; $p < $polygonCount; $p++) {
            $rings = [];
            $ringCount = self::readUint($blob, $offset);
            for ($r = 0; $r < $ringCount; $r++) {
                $ring = [];
                $pointCount = self::readUint($blob, $offset);
                for ($i = 0; $i < $pointCount; $i++) {
                    $ring[] = self::readInt($blob, $offset) / self::SCALE;
                    $ring[] = self::readInt($blob, $offset) / self::SCALE;
                }
                $rings[] = $ring;
            }
            $polygons[] = $rings;
        }
        return $polygons;
    }

    /** @return array{min_lon: float, min_lat: float, max_lon: float, max_lat: float} */
    public static function bbox(array $multipolygon): array
    {
        $values = [];
        foreach ($multipolygon as $polygon) {
            foreach ($polygon as $ring) {
                for ($i = 0; $i < count($ring); $i += 2) {
                    $values[] = [$ring[$i], $ring[$i + 1]];
                }
            }
        }
        if ($values === []) {
            throw new \InvalidArgumentException('Prázdna geometria nemá obálku.');
        }
        return [
            'min_lon' => min(array_column($values, 0)),
            'min_lat' => min(array_column($values, 1)),
            'max_lon' => max(array_column($values, 0)),
            'max_lat' => max(array_column($values, 1)),
        ];
    }

    public static function toGeoJson(string $blob): string
    {
        $coordinates = self::decode($blob);
        return json_encode(['type' => 'MultiPolygon', 'coordinates' => $coordinates], JSON_THROW_ON_ERROR);
    }

    private static function readUint(string $blob, int &$offset): int
    {
        $value = unpack('V', substr($blob, $offset, 4));
        if ($value === false || strlen(substr($blob, $offset, 4)) !== 4) {
            throw new \UnexpectedValueException('Poškodená geometria.');
        }
        $offset += 4;
        return (int) $value[1];
    }

    private static function readInt(string $blob, int &$offset): int
    {
        $value = unpack('l', substr($blob, $offset, 4));
        if ($value === false || strlen(substr($blob, $offset, 4)) !== 4) {
            throw new \UnexpectedValueException('Poškodená geometria.');
        }
        $offset += 4;
        return (int) $value[1];
    }
}
