<?php

declare(strict_types=1);

namespace Viagem\Tiles;

final class MvtLayer
{
    private const EXTENT = 4096;
    private array $features = [];
    private array $keys = [];
    private array $values = [];
    private array $keyIndex = [];
    private array $valueIndex = [];

    public function __construct(private readonly string $name)
    {
    }

    /** @param list<list<list<float>>> $polygons @param array<string, scalar|null> $attributes */
    public function addPolygon(int $id, array $polygons, array $attributes = []): void
    {
        $geometry = [];
        $cursorX = 0; $cursorY = 0;
        foreach ($polygons as $polygon) {
            foreach ($polygon as $ringIndex => $ring) {
                if ($ringIndex === 0 && Geometry::signedArea($ring) < 0.0) $ring = Geometry::reverseRing($ring);
                if ($ringIndex > 0 && Geometry::signedArea($ring) > 0.0) $ring = Geometry::reverseRing($ring);
                if (count($ring) < 6) continue;
                $points = [];
                for ($i = 0; $i + 1 < count($ring); $i += 2) $points[] = [(int) $ring[$i], (int) $ring[$i + 1]];
                if (count($points) > 1 && $points[0] === $points[count($points) - 1]) array_pop($points);
                if (count($points) < 3) continue;
                $geometry[] = (1 | (1 << 3));
                $dx = $points[0][0] - $cursorX; $dy = $points[0][1] - $cursorY;
                $geometry[] = self::zigzag($dx); $geometry[] = self::zigzag($dy);
                $cursorX = $points[0][0]; $cursorY = $points[0][1];
                $geometry[] = 2 | ((count($points) - 1) << 3);
                for ($i = 1; $i < count($points); $i++) {
                    $dx = $points[$i][0] - $cursorX; $dy = $points[$i][1] - $cursorY;
                    $geometry[] = self::zigzag($dx); $geometry[] = self::zigzag($dy);
                    $cursorX = $points[$i][0]; $cursorY = $points[$i][1];
                }
                $geometry[] = 7 | (1 << 3);
            }
        }
        if ($geometry === []) return;
        $this->features[] = ['id' => $id, 'tags' => $this->tags($attributes), 'type' => 3, 'geometry' => $geometry];
    }

    /** @param array{0: int, 1: int} $point @param array<string, scalar|null> $attributes */
    public function addPoint(int $id, array $point, array $attributes = []): void
    {
        $this->features[] = ['id' => $id, 'tags' => $this->tags($attributes), 'type' => 1,
            'geometry' => [9, self::zigzag($point[0]), self::zigzag($point[1])]];
    }

    public function encode(): string
    {
        $features = '';
        foreach ($this->features as $feature) {
            $tags = Protobuf::packed($feature['tags']);
            $geometry = Protobuf::packed($feature['geometry']);
            $body = Protobuf::uint(1, $feature['id']) . Protobuf::bytes(2, $tags)
                . Protobuf::uint(3, $feature['type']) . Protobuf::bytes(4, $geometry);
            $features .= Protobuf::bytes(2, $body);
        }
        $keys = ''; foreach ($this->keys as $key) $keys .= Protobuf::bytes(3, $key);
        $values = ''; foreach ($this->values as $value) $values .= Protobuf::bytes(4, $this->encodeValue($value));
        return Protobuf::bytes(1, $this->name) . $features . $keys . $values . Protobuf::uint(5, self::EXTENT) . Protobuf::uint(15, 2);
    }

    /** @param array<string, scalar|null> $attributes @return list<int> */
    private function tags(array $attributes): array
    {
        $tags = [];
        foreach ($attributes as $key => $value) {
            if ($value === null) continue;
            if (!isset($this->keyIndex[$key])) { $this->keyIndex[$key] = count($this->keys); $this->keys[] = $key; }
            $canonical = get_debug_type($value) . ':' . (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            if (!isset($this->valueIndex[$canonical])) { $this->valueIndex[$canonical] = count($this->values); $this->values[] = $value; }
            $tags[] = $this->keyIndex[$key]; $tags[] = $this->valueIndex[$canonical];
        }
        return $tags;
    }

    private function encodeValue(mixed $value): string
    {
        if (is_string($value)) return Protobuf::bytes(1, $value);
        if (is_int($value)) return Protobuf::uint(4, $value);
        if (is_float($value)) return Protobuf::double(3, $value);
        return Protobuf::uint(7, $value ? 1 : 0);
    }

    private static function zigzag(int $value): int
    {
        return $value < 0 ? (-$value * 2) - 1 : $value * 2;
    }
}
