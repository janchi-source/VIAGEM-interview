<?php

declare(strict_types=1);

namespace Viagem\Domain;

use PDO;
use Viagem\Geo\GeometryCodec;

final class ParcelRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*, k.nazev AS ku_nazev, o.nazev AS obec_nazev,
                    r.min_lon, r.min_lat, r.max_lon, r.max_lat
             FROM parcel p
             LEFT JOIN ku k ON k.kod = p.ku_kod
             LEFT JOIN obec o ON o.kod = k.obec_kod
             LEFT JOIN parcel_rtree r ON r.id = p.rtree_id
             WHERE p.id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) return null;

        $number = ParcelNumber::format((int) $row['kmenove_cislo'], $row['pododdeleni'] === null ? null : (int) $row['pododdeleni'], (int) $row['druh_cislovani']);
        $result = [
            'id' => $row['id'], 'cislo' => $number,
            'katastralni_uzemi' => ['kod' => $row['ku_kod'], 'nazev' => $row['ku_nazev']],
            'obec' => $row['obec_nazev'],
            'druh_pozemku' => ['kod' => $row['druh_pozemku'], 'nazev' => Codebook::landType($row['druh_pozemku'])],
            'zpusob_vyuziti' => ['kod' => $row['zpusob_vyuziti'], 'nazev' => Codebook::landUse($row['zpusob_vyuziti'])],
            'vymera' => $row['vymera'],
            'bpej' => json_decode((string) $row['bpej'], true, 512, JSON_THROW_ON_ERROR),
            'bbox' => ['min_lon' => $row['min_lon'], 'min_lat' => $row['min_lat'], 'max_lon' => $row['max_lon'], 'max_lat' => $row['max_lat']],
            'budovy' => $this->buildings((int) $row['ku_kod'], $id),
            'odkaz' => 'https://nahlizenidokn.cuzk.cz/ZobrazObjekt.aspx?typ=parcela&id=' . rawurlencode($id),
            'geojson' => $row['geom'] === null ? null : json_decode(GeometryCodec::toGeoJson((string) $row['geom']), true, 512, JSON_THROW_ON_ERROR),
        ];
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function search(?int $ku, string $text): array
    {
        $parsed = ParcelNumber::parse($text);
        $sql = 'SELECT p.id, p.ku_kod, p.kmenove_cislo, p.pododdeleni, p.druh_cislovani, k.nazev AS ku_nazev
                FROM parcel p LEFT JOIN ku k ON k.kod = p.ku_kod
                WHERE p.kmenove_cislo = :kmenove AND p.druh_cislovani = :druh';
        $params = ['kmenove' => $parsed['kmenove'], 'druh' => $parsed['druhCislovani']];
        if ($parsed['pododdeleni'] === null) {
            $sql .= ' AND p.pododdeleni IS NULL';
        } else {
            $sql .= ' AND p.pododdeleni = :pododdeleni';
            $params['pododdeleni'] = $parsed['pododdeleni'];
        }
        if ($ku !== null) {
            $sql .= ' AND p.ku_kod = :ku';
            $params['ku'] = $ku;
        }
        $sql .= ' ORDER BY p.id LIMIT 100';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $result = [];
        foreach ($statement as $row) {
            $result[] = ['id' => $row['id'], 'cislo' => ParcelNumber::format((int) $row['kmenove_cislo'], $row['pododdeleni'] === null ? null : (int) $row['pododdeleni'], (int) $row['druh_cislovani']), 'ku' => ['kod' => $row['ku_kod'], 'nazev' => $row['ku_nazev']]];
        }
        return $result;
    }

    /** @return list<array{kod:int, nazev:string}> */
    public function listKatastralniUzemi(): array
    {
        $statement = $this->pdo->query('SELECT kod, nazev FROM ku ORDER BY nazev, kod');
        return array_map(static fn (array $row): array => ['kod' => (int) $row['kod'], 'nazev' => (string) $row['nazev']], $statement->fetchAll());
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return [
            'parcely' => (int) $this->pdo->query('SELECT COUNT(*) FROM parcel')->fetchColumn(),
            'katastralni_uzemi' => (int) $this->pdo->query('SELECT COUNT(*) FROM ku')->fetchColumn(),
            'obce' => (int) $this->pdo->query('SELECT COUNT(*) FROM obec')->fetchColumn(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function buildings(int $ku, string $parcelId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM stavebni_objekt WHERE ku_kod = :ku AND identifikacni_parcela = :parcel ORDER BY id');
        $statement->execute(['ku' => $ku, 'parcel' => $parcelId]);
        $result = [];
        foreach ($statement as $row) {
            $address = $this->pdo->prepare(
                'SELECT * FROM adresni_misto
                 WHERE ku_kod = :ku
                   AND (cislo_domovni IS :domovni OR cislo_domovni = :domovni)
                   AND (cislo_orientacni IS :orientacni OR cislo_orientacni = :orientacni)
                 ORDER BY id'
            );
            $address->execute(['ku' => $ku, 'domovni' => $row['cislo_domovni'], 'orientacni' => $row['cislo_orientacni']]);
            $addresses = [];
            foreach ($address as $item) {
                $addresses[] = ['id' => $item['id'], 'adresa' => self::address($item)];
            }
            $result[] = ['id' => $row['id'], 'cislo_domovni' => $row['cislo_domovni'], 'cislo_orientacni' => $row['cislo_orientacni'], 'typ' => Codebook::buildingType($row['typ'] ?? null), 'adresy' => $addresses];
        }
        return $result;
    }

    private static function address(array $row): string
    {
        $parts = [];
        if ($row['cislo_domovni'] !== null) $parts[] = (string) $row['cislo_domovni'];
        if ($row['cislo_orientacni'] !== null) $parts[] = (string) $row['cislo_orientacni'];
        return implode('/', $parts);
    }
}
