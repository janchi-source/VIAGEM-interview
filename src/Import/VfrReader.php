<?php

declare(strict_types=1);

namespace Viagem\Import;

use DOMDocument;
use DOMElement;
use XMLReader;

final class VfrReader
{
    /** @param list<string>|null $types @return \Generator<int, array<string, mixed>> */
    public function records(string $path, ?array $types = null): \Generator
    {
        $reader = new XMLReader();
        $uris = [$path];
        if (str_ends_with(strtolower($path), '.zip')) {
            $base = substr($path, 0, -4);
            $uris = ['zip://' . $path . '#' . basename($base), 'zip://' . $path . '#data.xml'];
        }
        $opened = false;
        foreach ($uris as $uri) {
            if (@$reader->open($uri)) {
                $opened = true;
                break;
            }
        }
        if (!$opened) {
            throw new \RuntimeException('Nepodarilo sa otvoriť VFR súbor: ' . $path);
        }
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || !$reader->hasAttributes) continue;
            $id = $reader->getAttribute('gml:id');
            if ($id === null) continue;
            $node = $reader->expand();
            if (!$node instanceof \DOMElement) continue;
            $type = $this->type($node);
            if ($types !== null && !in_array($type, $types, true)) continue;
            if ($this->value($node, 'Nespravny') === 'true') continue;
            yield $this->record($node, $type, $id);
        }
        $reader->close();
    }

    /** @return array<string, mixed> */
    private function record(DOMElement $node, string $type, string $id): array
    {
        $record = ['id' => $id, 'type' => $type];
        foreach (['KodOkresu', 'KodObce', 'KodCastObce', 'KodKatastralnihoUzemi', 'KodKU', 'KmenoveCislo', 'PoddeleniCisla', 'DruhCislovani', 'DruhPozemku', 'ZpusobVyuziti', 'Vymera', 'CisloDomovni', 'CisloOrientacni'] as $name) {
            $value = $this->value($node, $name);
            if ($value !== null) $record[$name] = is_numeric($value) ? (int) $value : $value;
        }
        $point = GmlGeometryParser::parsePoint($node);
        if ($point !== null) $record['point'] = $point;
        $geometry = GmlGeometryParser::parseMultiPolygon($node);
        if ($geometry !== []) $record['geometry'] = $geometry;
        $record['bpej'] = $this->children($node, 'BPEJ');
        return $record;
    }

    private function type(DOMElement $node): string
    {
        $name = $node->localName ?? $node->nodeName;
        return preg_replace('/PropertyType$|Type$/', '', $name) ?: $name;
    }

    private function value(DOMElement $node, string $wanted): ?string
    {
        foreach ($node->getElementsByTagName('*') as $child) {
            if (($child->localName ?? $child->nodeName) === $wanted) return trim($child->textContent);
        }
        return null;
    }

    /** @return list<array<string, string>> */
    private function children(DOMElement $node, string $wanted): array
    {
        $result = [];
        foreach ($node->getElementsByTagName('*') as $child) {
            if (($child->localName ?? $child->nodeName) === $wanted) {
                $result[] = ['value' => trim($child->textContent)];
            }
        }
        return $result;
    }
}
