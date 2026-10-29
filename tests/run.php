<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "OK: {$message}\n";
}

use Viagem\Domain\ParcelNumber;
use Viagem\Geo\Krovak;

check(ParcelNumber::format(1234, 5, 0) === '1234/5', 'formatuje parcelu s pododdelením');
check(ParcelNumber::format(56, null, 1) === 'st. 56', 'formatuje stavebnú parcelu');
check(ParcelNumber::parse('123')['kmenove'] === 123, 'parsuje kmeňové číslo');
check(ParcelNumber::parse('st.56/2') === [
    'kmenove' => 56,
    'pododdeleni' => 2,
    'druhCislovani' => 1,
], 'parsuje stavebnú parcelu bez medzery');
check(ParcelNumber::parse('123/4')['pododdeleni'] === 4, 'parsuje pododdelenie');

[$longitude, $latitude] = Krovak::toWgs84(-740000.0, -1040000.0);
check(abs($longitude - 14.4544325) < 0.00001, 'prevádza Křovákovú dĺžku');
check(abs($latitude - 50.1177416) < 0.00001, 'prevádza Křovákovú šírku');
