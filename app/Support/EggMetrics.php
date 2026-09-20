<?php

namespace App\Support;

/**
 * Canonical egg ↔ crate conversion for product sales.
 *
 * Egg sales_records store quantity in crates and unit_price per crate.
 * Egg production / stock remain in individual eggs.
 */
final class EggMetrics
{
    public const EGGS_PER_CRATE = 30;

    public static function cratesToEggs(float $crates): float
    {
        return $crates * self::EGGS_PER_CRATE;
    }

    public static function eggsToCrates(float $eggs): float
    {
        return $eggs / self::EGGS_PER_CRATE;
    }
}
