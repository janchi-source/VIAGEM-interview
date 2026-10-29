<?php

declare(strict_types=1);

namespace Viagem\Geo;

final class TileMath
{
    public static function lonToX(float $lon, int $z): float
    {
        return (($lon + 180.0) / 360.0) * (1 << $z);
    }

    public static function latToY(float $lat, int $z): float
    {
        $lat = max(-85.05112878, min(85.05112878, $lat));
        return (1.0 - asinh(tan(deg2rad($lat))) / M_PI) / 2.0 * (1 << $z);
    }

    /** @return array{min_lon: float, min_lat: float, max_lon: float, max_lat: float} */
    public static function tileBounds(int $z, int $x, int $y, float $buffer = 0.0): array
    {
        $scale = 1 << $z;
        $minX = $x - $buffer / 4096.0; $maxX = $x + 1.0 + $buffer / 4096.0;
        $minY = $y - $buffer / 4096.0; $maxY = $y + 1.0 + $buffer / 4096.0;
        return ['min_lon' => $minX / $scale * 360.0 - 180.0, 'max_lon' => $maxX / $scale * 360.0 - 180.0,
            'max_lat' => rad2deg(atan(sinh(M_PI * (1.0 - 2.0 * $minY / $scale)))),
            'min_lat' => rad2deg(atan(sinh(M_PI * (1.0 - 2.0 * $maxY / $scale))))];
    }

    /** @return array{min_x: int, max_x: int, min_y: int, max_y: int} */
    public static function tileRange(int $z, array $bbox): array
    {
        $scale = (1 << $z) - 1;
        return ['min_x' => max(0, (int) floor(self::lonToX($bbox['min_lon'], $z))),
            'max_x' => min($scale, (int) floor(self::lonToX($bbox['max_lon'], $z))),
            'min_y' => max(0, (int) floor(self::latToY($bbox['max_lat'], $z))),
            'max_y' => min($scale, (int) floor(self::latToY($bbox['min_lat'], $z)))];
    }
}
