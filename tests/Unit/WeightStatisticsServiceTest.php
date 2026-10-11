<?php

namespace Tests\Unit;

use App\Services\WeightStatisticsService;
use PHPUnit\Framework\TestCase;

class WeightStatisticsServiceTest extends TestCase
{
    private WeightStatisticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WeightStatisticsService();
    }

    public function test_computes_mean_median_min_max(): void
    {
        $stats = $this->service->compute([1000, 1100, 900, 1200, 800]);

        $this->assertSame(5, $stats['count']);
        $this->assertEquals(1000.0, $stats['mean']);
        $this->assertEquals(1000.0, $stats['median']);
        $this->assertEquals(800.0, $stats['min']);
        $this->assertEquals(1200.0, $stats['max']);
    }

    public function test_computes_sample_std_dev_and_cv(): void
    {
        // Sample SD of [1000,1100,900,1200,800] = sqrt(100000/4) = 158.11
        $stats = $this->service->compute([1000, 1100, 900, 1200, 800]);

        $this->assertEqualsWithDelta(158.11, $stats['std_dev'], 0.01);
        $this->assertEqualsWithDelta(15.81, $stats['cv_percent'], 0.01);
    }

    public function test_uniformity_uses_ten_percent_band_of_mean(): void
    {
        // Mean 1000 -> band 900..1100: 1000, 1100, 900 are within (3 of 5).
        $stats = $this->service->compute([1000, 1100, 900, 1200, 800]);

        $this->assertSame(3, $stats['birds_within_band']);
        $this->assertEquals(60.0, $stats['uniformity_percent']);
        $this->assertEquals(900.0, $stats['band_low']);
        $this->assertEquals(1100.0, $stats['band_high']);
    }

    public function test_uniformity_respects_custom_band(): void
    {
        // Band ±5% of 1000 -> 950..1050: only 1000.
        $stats = $this->service->compute([1000, 1100, 900, 1200, 800], null, 5);

        $this->assertSame(1, $stats['birds_within_band']);
        $this->assertEquals(20.0, $stats['uniformity_percent']);
    }

    public function test_deviation_from_target(): void
    {
        $stats = $this->service->compute([1000, 1100, 900, 1200, 800], 1250);

        $this->assertEquals(-250.0, $stats['deviation_g']);
        $this->assertEquals(-20.0, $stats['deviation_percent']);
    }

    public function test_ignores_zero_and_negative_weights(): void
    {
        $stats = $this->service->compute([0, -5, 1000, 1000]);

        $this->assertSame(2, $stats['count']);
        $this->assertEquals(1000.0, $stats['mean']);
        $this->assertEquals(0.0, $stats['std_dev']);
        $this->assertEquals(100.0, $stats['uniformity_percent']);
    }

    public function test_empty_input_returns_nulls(): void
    {
        $stats = $this->service->compute([]);

        $this->assertSame(0, $stats['count']);
        $this->assertNull($stats['mean']);
        $this->assertNull($stats['uniformity_percent']);
    }

    public function test_flags_outliers_relative_to_median(): void
    {
        // 100 g is a likely typo (should be 1000 g); 5000 g is far above 2x median.
        $flags = $this->service->outlierFlags([1000, 1020, 980, 1010, 100, 5000, 990]);

        $this->assertSame([false, false, false, false, true, true, false], $flags);
    }

    public function test_estimates_uniformity_from_summary_range(): void
    {
        // SD ≈ 400 / d2(50)=4.498 → 88.9 g; normal share within ±150 g ≈ 90.8%.
        $r = $this->service->estimateFromSummary(1500, 1300, 1700, 50);

        $this->assertSame('estimated', $r['method']);
        $this->assertEqualsWithDelta(88.93, $r['std_dev'], 0.05);
        $this->assertEqualsWithDelta(90.8, $r['uniformity_percent'], 0.2);
        $this->assertSame(45, $r['birds_within_band']);
    }

    public function test_counted_birds_within_band_overrides_estimate(): void
    {
        $r = $this->service->estimateFromSummary(1500, 1300, 1700, 50, 10, 41);

        $this->assertSame('counted', $r['method']);
        $this->assertEqualsWithDelta(82.0, $r['uniformity_percent'], 0.001);
    }

    public function test_summary_without_range_has_no_uniformity(): void
    {
        $r = $this->service->estimateFromSummary(1500, null, null, 50);

        $this->assertNull($r['uniformity_percent']);
        $this->assertNull($r['method']);
    }
}
