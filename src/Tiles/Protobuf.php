<?php

declare(strict_types=1);

namespace Viagem\Tiles;

final class Protobuf
{
    public static function varint(int $value): string
    {
        $value = max(0, $value);
        $result = '';
        do {
            $byte = $value & 0x7f;
            $value = intdiv($value, 128);
            $result .= chr($value > 0 ? $byte | 0x80 : $byte);
        } while ($value > 0);
        return $result;
    }

    public static function uint(int $field, int $value): string
    {
        return self::varint(($field << 3) | 0) . self::varint($value);
    }

    public static function bytes(int $field, string $value): string
    {
        return self::varint(($field << 3) | 2) . self::varint(strlen($value)) . $value;
    }

    public static function double(int $field, float $value): string
    {
        // MVT používa little-endian fixed64 bez ohľadu na architektúru servera.
        return self::varint(($field << 3) | 1) . pack('e', $value);
    }

    /** @param list<int> $values */
    public static function packed(array $values): string
    {
        $body = '';
        foreach ($values as $value) $body .= self::varint($value);
        return $body;
    }
}
