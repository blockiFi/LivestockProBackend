<?php

namespace App\Support;

use App\Models\SubscriptionPlan;
use InvalidArgumentException;

/**
 * How often a farm pays: monthly, every 6 months, or yearly.
 *
 * Plans store one monthly price. Longer cycles charge the months up front
 * with a discount (5% for 6 months, 10% for a year by default), rounded to
 * whole naira so checkout amounts read cleanly.
 */
final class BillingCycle
{
    public const MONTHLY = 'monthly';
    public const BIANNUAL = 'biannual';
    public const ANNUAL = 'annual';

    public const ALL = [self::MONTHLY, self::BIANNUAL, self::ANNUAL];

    /** Paystack's names for each interval, used when creating Paystack plans. */
    private const PAYSTACK_INTERVALS = [
        self::MONTHLY => 'monthly',
        self::BIANNUAL => 'biannually',
        self::ANNUAL => 'annually',
    ];

    private const MONTHS = [
        self::MONTHLY => 1,
        self::BIANNUAL => 6,
        self::ANNUAL => 12,
    ];

    private const LABELS = [
        self::MONTHLY => 'Monthly',
        self::BIANNUAL => 'Every 6 months',
        self::ANNUAL => 'Yearly',
    ];

    public static function isValid(?string $cycle): bool
    {
        return in_array($cycle, self::ALL, true);
    }

    public static function normalize(?string $cycle): string
    {
        return self::isValid($cycle) ? $cycle : self::MONTHLY;
    }

    public static function months(string $cycle): int
    {
        return self::MONTHS[self::assertValid($cycle)];
    }

    public static function label(string $cycle): string
    {
        return self::LABELS[self::assertValid($cycle)];
    }

    public static function discountPercent(string $cycle): float
    {
        self::assertValid($cycle);

        return (float) config("subscription.billing_cycle_discounts.{$cycle}", 0);
    }

    public static function paystackInterval(string $cycle): string
    {
        return self::PAYSTACK_INTERVALS[self::assertValid($cycle)];
    }

    public static function fromPaystackInterval(?string $interval): ?string
    {
        $cycle = array_search($interval, self::PAYSTACK_INTERVALS, true);

        return $cycle === false ? null : $cycle;
    }

    /**
     * What one payment for this cycle costs, in kobo.
     */
    public static function priceKobo(SubscriptionPlan $plan, string $cycle): int
    {
        return self::priceKoboFor((int) $plan->price_kobo, $cycle);
    }

    public static function priceKoboFor(int $monthlyKobo, string $cycle): int
    {
        $months = self::months($cycle);
        $full = $monthlyKobo * $months;

        if ($months === 1) {
            return $full;
        }

        $discounted = $full * (100 - self::discountPercent($cycle)) / 100;

        // Round to whole naira.
        return (int) (round($discounted / 100) * 100);
    }

    /**
     * Monthly-equivalent revenue in kobo, for MRR.
     */
    public static function monthlyEquivalentKobo(int $monthlyKobo, ?string $cycle): float
    {
        $cycle = self::normalize($cycle);

        return self::priceKoboFor($monthlyKobo, $cycle) / self::months($cycle);
    }

    /**
     * Every cycle's price for a plan, for the billing page and plan catalog.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function optionsFor(SubscriptionPlan $plan): array
    {
        return array_map(function (string $cycle) use ($plan) {
            $priceKobo = self::priceKobo($plan, $cycle);
            $fullKobo = (int) $plan->price_kobo * self::months($cycle);

            return [
                'cycle' => $cycle,
                'label' => self::label($cycle),
                'months' => self::months($cycle),
                'discount_percent' => self::discountPercent($cycle),
                'price_kobo' => $priceKobo,
                'price' => $priceKobo / 100,
                'monthly_equivalent' => round($priceKobo / self::months($cycle) / 100, 2),
                'savings' => ($fullKobo - $priceKobo) / 100,
            ];
        }, self::ALL);
    }

    private static function assertValid(string $cycle): string
    {
        if (! self::isValid($cycle)) {
            throw new InvalidArgumentException("Unknown billing cycle [{$cycle}]");
        }

        return $cycle;
    }
}
