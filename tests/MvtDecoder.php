<?php

declare(strict_types=1);

final class MvtDecoder
{
    /** @return array<string, list<array<string, mixed>>> */
    public static function decode(string $tile): array
    {
        $layers = [];
        foreach (self::messages($tile, 3) as $layerBlob) {
            $name = ''; $features = []; $keys = []; $values = [];
            foreach (self::fields($layerBlob) as $field) {
                if ($field['number'] === 1) $name = $field['value'];
                if ($field['number'] === 3) $keys[] = $field['value'];
                if ($field['number'] === 4) $values[] = self::value($field['value']);
                if ($field['number'] !== 2) continue;
                $feature = [];
                foreach (self::fields($field['value']) as $featureField) {
                    if ($featureField['number'] === 1) $feature['id'] = $featureField['value'];
                    if ($featureField['number'] === 3) $feature['type'] = $featureField['value'];
                    if ($featureField['number'] === 4) $feature['geometry'] = self::geometry($featureField['value']);
                    if ($featureField['number'] === 2) $feature['_tags'] = self::packed($featureField['value']);
                }
                $features[] = $feature;
            }
            foreach ($features as &$feature) {
                $properties = [];
                foreach ($feature['_tags'] ?? [] as $i => $tag) {
                    if ($i % 2 === 0 && isset($keys[$tag], $feature['_tags'][$i + 1])) $properties[$keys[$tag]] = $values[$feature['_tags'][$i + 1]] ?? null;
                }
                unset($feature['_tags']);
                $feature['properties'] = $properties;
            }
            unset($feature);
            $layers[$name] = $features;
        }
        return $layers;
    }

    private static function value(string $blob): mixed
    {
        foreach (self::fields($blob) as $field) {
            if ($field['number'] === 1 || $field['number'] === 2) return $field['value'];
            if ($field['number'] === 3) return $field['value'];
            if ($field['number'] === 4) return $field['value'];
            if ($field['number'] === 7) return $field['value'] !== 0;
        }
        return null;
    }

    /** @return list<int> */
    private static function packed(string $blob): array
    {
        $values = []; $offset = 0;
        while ($offset < strlen($blob)) $values[] = self::varint($blob, $offset);
        return $values;
    }

    /** @return list<array{number:int, value:mixed}> */
    private static function fields(string $blob): array
    {
        $fields = []; $offset = 0;
        while ($offset < strlen($blob)) {
            $tag = self::varint($blob, $offset); $number = $tag >> 3; $wire = $tag & 7;
            if ($wire === 0) $value = self::varint($blob, $offset);
            elseif ($wire === 2) { $length = self::varint($blob, $offset); $value = substr($blob, $offset, $length); $offset += $length; }
            elseif ($wire === 1) { $value = unpack('e', substr($blob, $offset, 8))[1]; $offset += 8; }
            else throw new RuntimeException('Nepodporovaný protobuf wire type.');
            $fields[] = ['number' => $number, 'value' => $value];
        }
        return $fields;
    }

    /** @return list<string> */
    private static function messages(string $blob, int $number): array
    {
        $messages = [];
        foreach (self::fields($blob) as $field) {
            if ($field['number'] === $number && is_string($field['value'])) $messages[] = $field['value'];
        }
        return $messages;
    }

    /** @return list<array{command:int, values:list<int>}> */
    private static function geometry(string $blob): array
    {
        $values = []; $offset = 0;
        while ($offset < strlen($blob)) $values[] = self::varint($blob, $offset);
        $result = []; $i = 0;
        while ($i < count($values)) {
            $command = $values[$i] & 7; $count = $values[$i] >> 3; $i++;
            $coords = [];
            $coordinateCount = $count * ($command === 1 || $command === 2 ? 2 : 0);
            for ($n = 0; $n < $coordinateCount && $i < count($values); $n++) $coords[] = $values[$i++];
            $result[] = ['command' => $command, 'values' => $coords];
        }
        return $result;
    }

    private static function varint(string $blob, int &$offset): int
    {
        $value = 0; $shift = 0;
        do {
            $byte = ord($blob[$offset++]); $value += ($byte & 127) * (2 ** $shift); $shift += 7;
        } while (($byte & 128) !== 0);
        return (int) $value;
    }
}
