<?php

declare(strict_types=1);

namespace Viagem\Import;

use DOMElement;
use DOMNode;

final class GmlGeometryParser
{
    /** @return list<list<list<float>>> */
    public static function parseMultiPolygon(DOMElement $element): array
    {
        $polygons = [];
        self::walk($element, static function (DOMElement $node) use (&$polygons): void {
            $name = self::localName($node);
            if ($name !== 'Polygon' && $name !== 'PolygonPatch') {
                return;
            }
            $rings = [];
            foreach (self::descendants($node) as $child) {
                if (self::localName($child) === 'exterior' || self::localName($child) === 'interior') {
                    $rings[] = self::parseRing($child);
                }
            }
            if ($rings !== []) {
                $polygons[] = $rings;
            }
        });

        return $polygons;
    }

    /** @return array{0: float, 1: float}|null */
    public static function parsePoint(DOMElement $element): ?array
    {
        $nodes = self::localName($element) === 'Point' ? [$element] : self::descendants($element);
        foreach ($nodes as $node) {
            if (self::localName($node) !== 'Point') {
                continue;
            }
            foreach (self::descendants($node) as $pointChild) {
                $pointName = self::localName($pointChild);
                if ($pointName !== 'pos' && $pointName !== 'posList') {
                    continue;
                }
                $values = self::numbers($pointChild->textContent);
                return count($values) >= 2 ? [$values[0], $values[1]] : null;
            }
        }
        return null;
    }

    /** @return list<float> */
    private static function parseRing(DOMElement $container): array
    {
        $arcs = [];
        foreach (self::descendants($container) as $node) {
            if (self::localName($node) === 'ArcString' || self::localName($node) === 'Arc') {
                $arcs = array_merge($arcs, self::parseArc($node));
            }
        }
        if ($arcs !== []) {
            return $arcs;
        }
        $values = [];
        foreach (self::descendants($container) as $node) {
            $name = self::localName($node);
            if ($name !== 'pos' && $name !== 'posList') {
                continue;
            }
            $values = array_merge($values, self::localName($node) === 'pos'
                ? array_slice(self::numbers($node->textContent), 0, 2)
                : self::numbers($node->textContent));
        }
        return $values;
    }

    /** @return list<float> */
    private static function parseSegment(DOMElement $node): array
    {
        foreach (self::descendants($node) as $child) {
            if (self::localName($child) === 'posList') {
                return self::numbers($child->textContent);
            }
        }
        return self::numbers($node->textContent);
    }

    /** @return list<float> */
    private static function parseArc(DOMElement $node): array
    {
        $numbers = self::numbers($node->textContent);
        $points = [];
        for ($i = 0; $i + 1 < count($numbers); $i += 2) {
            $points[] = [$numbers[$i], $numbers[$i + 1]];
        }
        if (count($points) < 3) {
            return $numbers;
        }
        $result = [];
        for ($i = 0; $i + 2 < count($points); $i += 2) {
            [$a, $b, $c] = [$points[$i], $points[$i + 1], $points[$i + 2]];
            $result = array_merge($result, self::arcPoints($a, $b, $c));
        }
        return $result;
    }

    /** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b @param array{0: float, 1: float} $c */
    private static function arcPoints(array $a, array $b, array $c): array
    {
        $d = 2.0 * ($a[0] * ($b[1] - $c[1]) + $b[0] * ($c[1] - $a[1]) + $c[0] * ($a[1] - $b[1]));
        if (abs($d) < 1e-12) {
            return [$a[0], $a[1], $b[0], $b[1], $c[0], $c[1]];
        }
        $ux = (($a[0] ** 2 + $a[1] ** 2) * ($b[1] - $c[1]) + ($b[0] ** 2 + $b[1] ** 2) * ($c[1] - $a[1]) + ($c[0] ** 2 + $c[1] ** 2) * ($a[1] - $b[1])) / $d;
        $uy = (($a[0] ** 2 + $a[1] ** 2) * ($c[0] - $b[0]) + ($b[0] ** 2 + $b[1] ** 2) * ($a[0] - $c[0]) + ($c[0] ** 2 + $c[1] ** 2) * ($b[0] - $a[0])) / $d;
        $radius = hypot($a[0] - $ux, $a[1] - $uy);
        $start = atan2($a[1] - $uy, $a[0] - $ux);
        $middle = atan2($b[1] - $uy, $b[0] - $ux);
        $end = atan2($c[1] - $uy, $c[0] - $ux);
        $direction = self::between($start, $middle, $end) ? 1.0 : -1.0;
        while (($direction > 0 && $end < $start) || ($direction < 0 && $end > $start)) {
            $end += $direction * 2.0 * M_PI;
        }
        $result = [];
        $steps = max(1, (int) ceil(abs($end - $start) / deg2rad(5.0)));
        for ($i = 0; $i <= $steps; $i++) {
            $angle = $start + ($end - $start) * $i / $steps;
            $result[] = $ux + $radius * cos($angle);
            $result[] = $uy + $radius * sin($angle);
        }
        return $result;
    }

    private static function between(float $start, float $middle, float $end): bool
    {
        while ($middle < $start) $middle += 2.0 * M_PI;
        while ($end < $start) $end += 2.0 * M_PI;
        return $middle <= $end;
    }

    /** @return list<float> */
    private static function numbers(string $text): array
    {
        preg_match_all('/[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?/', $text, $matches);
        return array_map('floatval', $matches[0]);
    }

    private static function localName(DOMNode $node): string
    {
        return $node->localName ?? $node->nodeName;
    }

    /** @return list<DOMElement> */
    private static function descendants(DOMNode $node): array
    {
        $result = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $result[] = $child;
                $result = array_merge($result, self::descendants($child));
            }
        }
        return $result;
    }

    /** @param callable(DOMElement): void $callback */
    private static function walk(DOMElement $node, callable $callback): void
    {
        $callback($node);
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                self::walk($child, $callback);
            }
        }
    }
}
