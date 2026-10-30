<?php

declare(strict_types=1);

namespace Viagem\Import;

use PDO;
use Viagem\Geo\GeometryCodec;

final class Importer
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, int> */
    public function importFile(string $path, ?array $municipalities = null): array
    {
        $this->pdo->exec('PRAGMA journal_mode=OFF');
        $this->pdo->exec('PRAGMA synchronous=OFF');
        \Viagem\Database::createSchema($this->pdo);
        $statements = $this->statements();
        $counts = [];
        $processed = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ((new VfrReader())->records($path) as $record) {
                if ($municipalities !== null && isset($record['KodObce']) && !in_array((int) $record['KodObce'], $municipalities, true)) {
                    continue;
                }
                $type = (string) $record['type'];
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                $this->insert($type, $record, $statements);
                $processed++;
                if ($processed % 5000 === 0) {
                    $this->pdo->commit();
                    $this->pdo->beginTransaction();
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $counts;
    }

    /** @return array<string, \PDOStatement> */
    private function statements(): array
    {
        return [
            'obec' => $this->pdo->prepare('INSERT OR REPLACE INTO obec (kod, nazev, okres_kod) VALUES (?, ?, ?)'),
            'cast' => $this->pdo->prepare('INSERT OR REPLACE INTO cast_obce (kod, obec_kod, nazev) VALUES (?, ?, ?)'),
            'ulice' => $this->pdo->prepare('INSERT OR REPLACE INTO ulice (kod, obec_kod, nazev) VALUES (?, ?, ?)'),
            'ku' => $this->pdo->prepare('INSERT OR REPLACE INTO ku (kod, nazev, obec_kod, min_lon, min_lat, max_lon, max_lat, label_lon, label_lat, parcel_count, geom) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'),
            'parcel' => $this->pdo->prepare('INSERT OR REPLACE INTO parcel (id, ku_kod, kmenove_cislo, pododdeleni, druh_cislovani, druh_pozemku, zpusob_vyuziti, vymera, bpej, label_lon, label_lat, rtree_id, geom, ochrana, plati_od) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'),
            'rtree' => $this->pdo->prepare('INSERT OR REPLACE INTO parcel_rtree (id, min_lon, max_lon, min_lat, max_lat) VALUES (?, ?, ?, ?, ?)'),
            'building' => $this->pdo->prepare('INSERT OR REPLACE INTO stavebni_objekt (id, ku_kod, cislo_domovni, cislo_orientacni, identifikacni_parcela, label_lon, label_lat, geom) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'),
            'address' => $this->pdo->prepare('INSERT OR REPLACE INTO adresni_misto (id, obec_kod, ku_kod, cislo_domovni, cislo_orientacni, label_lon, label_lat) VALUES (?, ?, ?, ?, ?, ?, ?)'),
        ];
    }

    /** @param array<string, mixed> $record @param array<string, \PDOStatement> $statements */
    private function insert(string $type, array $record, array $statements): void
    {
        $normalized = strtolower($type);
        if ($normalized === 'obec') {
            $code = $this->integer($record, ['KodObce']);
            if ($code === null) return;
            $statements['obec']->execute([$code, $this->text($record, ['Nazev', 'NazevObce']) ?? '', $this->integer($record, ['KodOkresu'])]);
            return;
        }
        if (in_array($normalized, ['castobce', 'cast_obce'], true)) {
            $code = $this->integer($record, ['KodCastObce']);
            if ($code === null) return;
            $statements['cast']->execute([$code, $this->integer($record, ['KodObce']), $this->text($record, ['Nazev', 'NazevCastObce']) ?? '']);
            return;
        }
        if ($normalized === 'ulice') {
            $code = $this->integer($record, ['KodUlice']);
            if ($code === null) return;
            $statements['ulice']->execute([$code, $this->integer($record, ['KodObce']), $this->text($record, ['Nazev']) ?? '']);
            return;
        }
        if (in_array($normalized, ['katastralniuzemi', 'katastralni_uzemi', 'ku'], true)) {
            $geometry = $record['geometry'] ?? [];
            $bbox = $geometry === [] ? null : GeometryCodec::bbox($geometry);
            $point = $record['point'] ?? [null, null];
            $code = $this->integer($record, ['KodKatastralnihoUzemi', 'KodKU']);
            if ($code === null) return;
            $statements['ku']->execute([$code, $this->text($record, ['Nazev', 'NazevKU']) ?? '', $this->integer($record, ['KodObce']), $bbox['min_lon'] ?? null, $bbox['min_lat'] ?? null, $bbox['max_lon'] ?? null, $bbox['max_lat'] ?? null, $point[0], $point[1], $geometry === [] ? null : GeometryCodec::encode($geometry)]);
            return;
        }
        if (in_array($normalized, ['parcela', 'parcel'], true)) {
            $geometry = $record['geometry'] ?? [];
            $blob = $geometry === [] ? null : GeometryCodec::encode($geometry);
            $bbox = $geometry === [] ? null : GeometryCodec::bbox($geometry);
            $point = $record['point'] ?? [null, null];
            $ku = $this->integer($record, ['KodKatastralnihoUzemi', 'KodKU']);
            $rtreeId = $this->rtreeId((string) $record['id']);
            $statements['parcel']->execute([
                $record['id'], $ku, $this->integer($record, ['KmenoveCislo']),
                $this->integer($record, ['PoddeleniCisla', 'Poddeleni']), $this->integer($record, ['DruhCislovani']),
                $this->text($record, ['DruhPozemku']), $this->text($record, ['ZpusobVyuziti']),
                $this->integer($record, ['Vymera']), json_encode($record['bpej'] ?? [], JSON_THROW_ON_ERROR),
                $point[0], $point[1], $rtreeId, $blob,
                json_encode($record['ochrana'] ?? [], JSON_THROW_ON_ERROR), $this->text($record, ['PlatiOd']),
            ]);
            if ($bbox !== null) $statements['rtree']->execute([$rtreeId, $bbox['min_lon'], $bbox['max_lon'], $bbox['min_lat'], $bbox['max_lat']]);
            return;
        }
        if (in_array($normalized, ['stavebniobjekt', 'stavebni_objekt', 'building'], true)) {
            $point = $record['point'] ?? [null, null];
            $statements['building']->execute([$record['id'], $this->integer($record, ['KodKatastralnihoUzemi', 'KodKU']), $this->integer($record, ['CisloDomovni']), $this->integer($record, ['CisloOrientacni']), $this->text($record, ['IdentifikacniParcela']), $point[0], $point[1], null]);
        }
        if (in_array($normalized, ['adresnimisto', 'adresni_misto'], true)) {
            $point = $record['point'] ?? [null, null];
            $statements['address']->execute([$record['id'], $this->integer($record, ['KodObce']), $this->integer($record, ['KodKatastralnihoUzemi', 'KodKU']), $this->integer($record, ['CisloDomovni']), $this->integer($record, ['CisloOrientacni']), $point[0], $point[1]]);
        }
    }

    /** @param array<string, mixed> $record @param list<string> $keys */
    private function integer(array $record, array $keys): ?int
    {
        foreach ($keys as $key) if (isset($record[$key]) && is_numeric($record[$key])) return (int) $record[$key];
        return null;
    }

    /** @param array<string, mixed> $record @param list<string> $keys */
    private function text(array $record, array $keys): ?string
    {
        foreach ($keys as $key) if (isset($record[$key])) return (string) $record[$key];
        return null;
    }

    private function rtreeId(string $id): int
    {
        return is_numeric($id) ? (int) $id : (int) sprintf('%u', crc32($id));
    }
}
