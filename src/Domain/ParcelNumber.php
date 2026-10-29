<?php

declare(strict_types=1);

namespace Viagem\Domain;

final class ParcelNumber
{
    public static function format(int $kmenove, ?int $pododdeleni, int $druhCislovani): string
    {
        $number = (string) $kmenove;
        if ($pododdeleni !== null) {
            $number .= '/' . $pododdeleni;
        }

        return $druhCislovani === 1 ? 'st. ' . $number : $number;
    }

    /**
     * @return array{kmenove: int, pododdeleni: int|null, druhCislovani: int}
     */
    public static function parse(string $text): array
    {
        $text = trim($text);
        if (preg_match('/^st\.\s*(\d+)(?:\/(\d+))?$/iu', $text, $matches) === 1) {
            return [
                'kmenove' => (int) $matches[1],
                'pododdeleni' => isset($matches[2]) ? (int) $matches[2] : null,
                'druhCislovani' => 1,
            ];
        }
        if (preg_match('/^(\d+)(?:\/(\d+))?$/', $text, $matches) === 1) {
            return [
                'kmenove' => (int) $matches[1],
                'pododdeleni' => isset($matches[2]) ? (int) $matches[2] : null,
                'druhCislovani' => 0,
            ];
        }

        throw new \InvalidArgumentException('Neplatné číslo parcely.');
    }
}
