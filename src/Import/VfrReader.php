<?php

declare(strict_types=1);

namespace Viagem\Import;

use DOMDocument;
use DOMElement;
use Viagem\Geo\Krovak;
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
        $this->applyVfrFields($node, $type, $record);
        $point = GmlGeometryParser::parsePoint($node);
        if ($point !== null) $record['point'] = $this->toWgs84Point($point);
        $geometry = GmlGeometryParser::parseMultiPolygon($node);
        if ($geometry !== []) $record['geometry'] = array_map(fn (array $polygon): array => array_map($this->toWgs84Ring(...), $polygon), $geometry);
        $record['bpej'] ??= $this->children($node, 'BPEJ');
        return $record;
    }

    /** Pole skutočného VFR: vlastný `Kod`, odkazy `<Obec><Kod>` a prípony `...Kod`/`...Parcely` v názvoch. */
    private const OWN_CODE = ['Okres' => 'KodOkresu', 'Obec' => 'KodObce', 'CastObce' => 'KodCastObce', 'KatastralniUzemi' => 'KodKatastralnihoUzemi', 'Ulice' => 'KodUlice'];
    private const PARENT_CODE = ['Okres' => 'KodOkresu', 'Obec' => 'KodObce', 'CastObce' => 'KodCastObce', 'KatastralniUzemi' => 'KodKatastralnihoUzemi'];
    private const RENAMED = ['VymeraParcely' => 'Vymera', 'DruhCislovaniKod' => 'DruhCislovani', 'DruhPozemkuKod' => 'DruhPozemku', 'ZpusobVyuzitiKod' => 'ZpusobVyuziti', 'ZpusobyVyuzitiPozemku' => 'ZpusobVyuziti', 'PododdeleniCisla' => 'PoddeleniCisla'];
    private const DIRECT = ['Nazev', 'KmenoveCislo', 'PoddeleniCisla', 'CisloDomovni', 'CisloOrientacni'];

    /** @param array<string, mixed> $record */
    private function applyVfrFields(DOMElement $node, string $type, array &$record): void
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            $name = $child->localName ?? $child->nodeName;
            $text = trim($child->textContent);
            if ($name === 'Kod' && isset(self::OWN_CODE[$type])) {
                $record[self::OWN_CODE[$type]] = (int) $text;
            } elseif (isset(self::PARENT_CODE[$name])) {
                foreach ($child->childNodes as $grandchild) {
                    if ($grandchild instanceof DOMElement && ($grandchild->localName ?? '') === 'Kod') $record[self::PARENT_CODE[$name]] = (int) trim($grandchild->textContent);
                }
            } elseif (isset(self::RENAMED[$name])) {
                $record[self::RENAMED[$name]] = is_numeric($text) ? (int) $text : $text;
            } elseif (in_array($name, self::DIRECT, true)) {
                $record[$name] = is_numeric($text) && $name !== 'Nazev' ? (int) $text : $text;
            } elseif ($name === 'PlatiOd') {
                $record['PlatiOd'] = substr($text, 0, 10);
            } elseif ($name === 'BonitovaneDily') {
                foreach ($child->getElementsByTagName('BonitovanyDil') as $part) {
                    $kod = $this->value($part, 'BonitovanaJednotkaKod');
                    if ($kod === null || !is_numeric($kod)) continue;
                    $area = $this->value($part, 'Vymera');
                    $record['bpej'][] = ['kod' => (int) $kod, 'vymera' => is_numeric($area) ? (int) $area : null];
                }
            } elseif ($name === 'ZpusobyOchranyPozemku') {
                foreach ($child->getElementsByTagName('ZpusobOchrany') as $item) {
                    $kod = $this->value($item, 'Kod');
                    if ($kod === null || !is_numeric($kod)) continue;
                    $type = $this->value($item, 'TypOchranyKod');
                    $record['ochrana'][] = ['kod' => (int) $kod, 'typ' => is_numeric($type) ? (int) $type : null];
                }
            } elseif ($name === 'CislaDomovni') {
                $first = $this->value($child, 'CisloDomovni');
                if ($first !== null && is_numeric($first)) $record['CisloDomovni'] = (int) $first;
            } elseif ($name === 'IdentifikacniParcela') {
                $record['IdentifikacniParcela'] = $this->value($child, 'Id') ?? $text;
            }
        }
    }

    /** VFR je v S-JTSK (EPSG:5514); hodnoty už v stupňoch (fixtures) sa nemenia. */
    private function toWgs84Point(array $point): array
    {
        return abs($point[0]) <= 180.0 ? $point : Krovak::toWgs84($point[0], $point[1]);
    }

    /** @param list<float> $ring @return list<float> */
    private function toWgs84Ring(array $ring): array
    {
        if ($ring === [] || abs($ring[0]) <= 180.0) return $ring;
        $converted = [];
        for ($i = 0; $i + 1 < count($ring); $i += 2) array_push($converted, ...Krovak::toWgs84($ring[$i], $ring[$i + 1]));
        return $converted;
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
