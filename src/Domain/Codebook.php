<?php

declare(strict_types=1);

namespace Viagem\Domain;

final class Codebook
{
    // Texty menej používaných kódov treba pri rozšírení overiť vo VFR číselníku.
    /** @var array<int, string> */
    private const LAND_TYPES = [
        2 => 'orná půda', 3 => 'chmelnice', 4 => 'vinice', 5 => 'zahrada',
        6 => 'ovocný sad', 7 => 'trvalý travní porost', 10 => 'lesní pozemek',
        11 => 'vodní plocha', 13 => 'zastavěná plocha a nádvoří', 14 => 'ostatní plocha',
    ];

    /** @var array<int, string> */
    private const LAND_USES = [
        1 => 'skleník', 2 => 'sklad', 3 => 'garáž', 4 => 'zemědělská stavba',
        5 => 'stavba pro rodinnou rekreaci', 6 => 'stavba občanského vybavení',
        7 => 'stavba technického vybavení', 8 => 'stavba pro výrobu a skladování',
        9 => 'stavba pro dopravu', 10 => 'jiná stavba', 11 => 'zeleň',
        12 => 'sportoviště a rekreační plocha', 13 => 'komunikace',
    ];

    /** @var array<int, string> */
    private const BUILDING_TYPES = [
        1 => 'budova s číslem domovním', 2 => 'budova s číslem evidenčním',
        3 => 'budova bez čísla', 4 => 'rozestavěná budova',
    ];

    public static function landType(int|string|null $code): ?string
    {
        return self::lookup(self::LAND_TYPES, $code);
    }

    public static function druhPozemku(int|string|null $code): ?string
    {
        return self::landType($code);
    }

    public static function landUse(int|string|null $code): ?string
    {
        return self::lookup(self::LAND_USES, $code);
    }

    public static function zpusobVyuziti(int|string|null $code): ?string
    {
        return self::landUse($code);
    }

    public static function buildingType(int|string|null $code): ?string
    {
        return self::lookup(self::BUILDING_TYPES, $code);
    }

    public static function typStavebnihoObjektu(int|string|null $code): ?string
    {
        return self::buildingType($code);
    }

    /** @param array<int, string> $book */
    private static function lookup(array $book, int|string|null $code): ?string
    {
        if ($code === null || $code === '') return null;
        $number = (int) $code;
        return $book[$number] ?? 'kód ' . $number;
    }
}
