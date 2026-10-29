<?php

declare(strict_types=1);

namespace Viagem\Tiles;

final class Geometry
{
    /** @param list<float> $ring */
    public static function signedArea(array $ring): float
    {
        $area = 0.0;
        $count = count($ring);
        for ($i = 0; $i < $count; $i += 2) {
            $next = ($i + 2) % $count;
            $area += $ring[$i] * $ring[$next + 1] - $ring[$next] * $ring[$i + 1];
        }
        return $area / 2.0;
    }

    /** @param list<float> $ring @return list<float> */
    public static function reverseRing(array $ring): array
    {
        $reversed = [];
        for ($i = count($ring) - 2; $i >= 0; $i -= 2) {
            $reversed[] = $ring[$i];
            $reversed[] = $ring[$i + 1];
        }
        return $reversed;
    }

    /** @param list<float> $ring @return list<float> */
    public static function clipRing(array $ring, float $minX, float $minY, float $maxX, float $maxY): array
    {
        if (count($ring) < 6) return [];
        $xs = []; $ys = [];
        for ($i = 0; $i + 1 < count($ring); $i += 2) { $xs[] = $ring[$i]; $ys[] = $ring[$i + 1]; }
        if (min($xs) >= $minX && max($xs) <= $maxX && min($ys) >= $minY && max($ys) <= $maxY) return $ring;
        if (max($xs) < $minX || min($xs) > $maxX || max($ys) < $minY || min($ys) > $maxY) return [];
        $points = [];
        for ($i = 0; $i < count($ring); $i += 2) $points[] = [$ring[$i], $ring[$i + 1]];
        $inside = static fn (array $p, int $edge): bool => match ($edge) {
            0 => $p[0] >= $minX, 1 => $p[0] <= $maxX,
            2 => $p[1] >= $minY, default => $p[1] <= $maxY,
        };
        $intersect = static function (array $a, array $b, int $edge) use ($minX, $minY, $maxX, $maxY): array {
            if ($edge < 2) {
                $x = $edge === 0 ? $minX : $maxX;
                $t = ($x - $a[0]) / ($b[0] - $a[0]);
                return [$x, $a[1] + ($b[1] - $a[1]) * $t];
            }
            $y = $edge === 2 ? $minY : $maxY;
            $t = ($y - $a[1]) / ($b[1] - $a[1]);
            return [$a[0] + ($b[0] - $a[0]) * $t, $y];
        };
        for ($edge = 0; $edge < 4; $edge++) {
            if ($points === []) return [];
            $output = [];
            $previous = $points[count($points) - 1];
            foreach ($points as $current) {
                $currentInside = $inside($current, $edge);
                $previousInside = $inside($previous, $edge);
                if ($currentInside !== $previousInside) $output[] = $intersect($previous, $current, $edge);
                if ($currentInside) $output[] = $current;
                $previous = $current;
            }
            $points = $output;
        }
        $result = [];
        foreach ($points as $point) { $result[] = $point[0]; $result[] = $point[1]; }
        return $result;
    }

    /** @param list<float> $ring @return list<float> */
    public static function quantizeAndSimplify(array $ring, float $tolerance = 0.5): array
    {
        $quantized = [];
        for ($i = 0; $i + 1 < count($ring); $i += 2) {
            $point = [round($ring[$i]), round($ring[$i + 1])];
            if ($quantized === [] || $point !== $quantized[count($quantized) - 1]) $quantized[] = $point;
        }
        if (count($quantized) > 1 && $quantized[0] === $quantized[count($quantized) - 1]) array_pop($quantized);
        if (count($quantized) < 3) return [];
        $simplified = self::douglasPeucker($quantized, $tolerance);
        if (count($simplified) < 3) return [];
        $result = [];
        foreach ($simplified as $point) { $result[] = $point[0]; $result[] = $point[1]; }
        return $result;
    }

    /** @param list<array{0: float, 1: float}> $points @return list<array{0: float, 1: float}> */
    private static function douglasPeucker(array $points, float $tolerance): array
    {
        if (count($points) <= 2) return $points;
        $keep = array_fill(0, count($points), false);
        $keep[0] = $keep[count($points) - 1] = true;
        $stack = [[0, count($points) - 1]];
        $limit = $tolerance * $tolerance;
        while ($stack !== []) {
            [$start, $end] = array_pop($stack);
            $max = $limit; $index = -1;
            for ($i = $start + 1; $i < $end; $i++) {
                $distance = self::distanceSquared($points[$i], $points[$start], $points[$end]);
                if ($distance > $max) { $max = $distance; $index = $i; }
            }
            if ($index >= 0) { $keep[$index] = true; $stack[] = [$start, $index]; $stack[] = [$index, $end]; }
        }
        $result = [];
        foreach ($keep as $i => $value) if ($value) $result[] = $points[$i];
        return $result;
    }

    private static function distanceSquared(array $p, array $a, array $b): float
    {
        $dx = $b[0] - $a[0]; $dy = $b[1] - $a[1];
        if ($dx === 0.0 && $dy === 0.0) return ($p[0] - $a[0]) ** 2 + ($p[1] - $a[1]) ** 2;
        $t = max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / ($dx ** 2 + $dy ** 2)));
        return ($p[0] - ($a[0] + $t * $dx)) ** 2 + ($p[1] - ($a[1] + $t * $dy)) ** 2;
    }
}
