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
        $temporaryPath = null;
        $uris = [$path];
        if (str_ends_with(strtolower($path), '.zip')) {
            $base = substr($path, 0, -4);
            $uris = ['zip://' . $path . '#' . basename($base), 'zip://' . $path . '#data.xml'];
            if (!in_array('zip', stream_get_wrappers(), true)) {
                $temporaryPath = $this->extractXml($path);
                $uris = [$temporaryPath];
            }
        }
        $opened = false;
        foreach ($uris as $uri) {
            if (@$reader->open($uri)) {
                $opened = true;
                break;
            }
        }
        if (!$opened) {
            if ($temporaryPath !== null) @unlink($temporaryPath);
            throw new \RuntimeException('Nepodarilo sa otvoriť VFR súbor: ' . $path);
        }
        try {
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
        } finally {
            $reader->close();
            if ($temporaryPath !== null) @unlink($temporaryPath);
        }
    }

    private function extractXml(string $archive): string
    {
        $listing = [];
        exec('unzip -Z1 ' . escapeshellarg($archive) . ' 2>/dev/null', $listing, $status);
        if ($status !== 0 || $listing === []) {
            throw new \RuntimeException('VFR ZIP nie je čitateľný: ' . $archive);
        }
        $entry = null;
        foreach ($listing as $candidate) {
            if (str_ends_with(strtolower($candidate), '.xml')) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) throw new \RuntimeException('VFR ZIP neobsahuje XML: ' . $archive);
        $temporary = tempnam(sys_get_temp_dir(), 'vfr-');
        if ($temporary === false) throw new \RuntimeException('Nepodarilo sa vytvoriť dočasný VFR súbor.');
        $command = 'unzip -p ' . escapeshellarg($archive) . ' ' . escapeshellarg($entry);
        $process = proc_open($command, [1 => ['file', $temporary, 'wb'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            @unlink($temporary);
            throw new \RuntimeException('Nepodarilo sa rozbaliť VFR ZIP.');
        }
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            @unlink($temporary);
            throw new \RuntimeException('Nepodarilo sa rozbaliť VFR ZIP: ' . trim($error));
        }
        return $temporary;
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
