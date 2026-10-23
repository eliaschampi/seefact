<?php

declare(strict_types=1);

namespace See\Support;

/**
 * Peruvian RUC check digit (módulo 11). Ported from NecoFact, where it was
 * verified against the SUNAT registry.
 */
final class RucNumber
{
    private const array WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function isValid(string $ruc): bool
    {
        if (preg_match('/^\d{11}$/D', $ruc) !== 1) {
            return false;
        }

        $sum = 0;

        foreach (self::WEIGHTS as $index => $weight) {
            $sum += $weight * (int) $ruc[$index];
        }

        return (int) $ruc[10] === (11 - ($sum % 11)) % 10;
    }
}
