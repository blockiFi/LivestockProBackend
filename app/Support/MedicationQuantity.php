<?php

namespace App\Support;

/**
 * Unit-aware medication quantities. Stock is stored in a base unit per family:
 * mass in g, volume in ml, count in tablet.
 */
final class MedicationQuantity
{
    public const PACK_TYPES = ['sachet', 'bottle', 'vial', 'tin', 'bag', 'box', 'pack', 'other'];

    /** unit => [family, factor to base unit] */
    private const UNITS = [
        'mg' => ['mass', 0.001],
        'g' => ['mass', 1.0],
        'kg' => ['mass', 1000.0],
        'ml' => ['volume', 1.0],
        'l' => ['volume', 1000.0],
        'tablet' => ['count', 1.0],
    ];

    private const BASE_UNITS = ['mass' => 'g', 'volume' => 'ml', 'count' => 'tablet'];

    public const DEPLETION_REASONS = ['wasted', 'expired', 'damaged', 'lost', 'other'];

    /** @return list<string> */
    public static function units(): array
    {
        return ['mg', 'g', 'kg', 'ml', 'L', 'tablet'];
    }

    public static function family(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        return self::UNITS[strtolower(trim($unit))][0] ?? null;
    }

    public static function baseUnit(?string $unit): ?string
    {
        $family = self::family($unit);

        return $family ? self::BASE_UNITS[$family] : null;
    }

    public static function toBase(float $amount, string $unit): ?float
    {
        $def = self::UNITS[strtolower(trim($unit))] ?? null;

        return $def ? round($amount * $def[1], 4) : null;
    }

    public static function compatible(?string $unit, ?string $baseUnit): bool
    {
        $family = self::family($unit);

        return $family !== null && $family === self::family($baseUnit);
    }

    public static function familyUnitsLabel(string $baseUnit): string
    {
        return match (self::family($baseUnit)) {
            'mass' => 'g, mg or kg',
            'volume' => 'ml or L',
            default => 'tablets',
        };
    }

    public static function formatAmount(float $amount, string $unit): string
    {
        $text = rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.');

        return "{$text} {$unit}";
    }

    public static function packNoun(?string $packType, float $count): string
    {
        $noun = $packType && $packType !== 'other' ? $packType : 'pack';
        if (abs($count - 1) < 1e-9) {
            return $noun;
        }

        return match ($noun) {
            'box' => 'boxes',
            default => $noun.'s',
        };
    }

    /**
     * Split remaining stock into whole packs plus the opened remainder, e.g. 180 g of 100 g sachets
     * gives 1 sachet + 80 g.
     *
     * @return array{full_packs:int, loose:float, label:string}
     */
    public static function breakdown(float $remainingBase, ?float $packSizeBase, ?string $packType, ?string $baseUnit): array
    {
        $remaining = max(0.0, round($remainingBase, 4));
        $unit = $baseUnit ?: '';

        if (! $packSizeBase || $packSizeBase <= 0) {
            return ['full_packs' => 0, 'loose' => $remaining, 'label' => trim(self::formatAmount($remaining, $unit))];
        }

        $full = (int) floor(($remaining + 1e-9) / $packSizeBase);
        $loose = round($remaining - $full * $packSizeBase, 4);
        if ($loose < 1e-6) {
            $loose = 0.0;
        }

        $parts = [];
        if ($full > 0) {
            $parts[] = $full.' '.self::packNoun($packType, $full);
        }
        if ($loose > 0 || $full === 0) {
            $parts[] = self::formatAmount($loose, $unit);
        }

        return ['full_packs' => $full, 'loose' => $loose, 'label' => implode(' + ', $parts)];
    }
}
