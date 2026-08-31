<?php

namespace Tests\Unit;

use App\Services\ScheduleImportService;
use PHPUnit\Framework\TestCase;

class ScheduleImportFeedingLayoutTest extends TestCase
{
    private ScheduleImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduleImportService(
            $this->createMock(\App\Services\LlmService::class)
        );
    }

    public function test_resolve_feeding_layout_uses_llm_value_when_valid(): void
    {
        $this->assertSame('per_day', $this->service->resolveFeedingLayout('per_day', []));
        $this->assertSame('range', $this->service->resolveFeedingLayout('range', []));
    }

    public function test_infer_per_day_when_rows_are_single_days_with_varying_quantities(): void
    {
        $layout = $this->service->inferFeedingLayoutFromRows([
            ['start_day' => 1, 'end_day' => 1, 'quantity' => 40],
            ['start_day' => 2, 'end_day' => 2, 'quantity' => 45],
        ]);

        $this->assertSame('per_day', $layout);
    }

    public function test_infer_range_when_rows_include_multi_day_spans(): void
    {
        $layout = $this->service->inferFeedingLayoutFromRows([
            ['start_day' => 1, 'end_day' => 7, 'quantity' => 40],
            ['start_day' => 8, 'end_day' => 14, 'quantity' => 50],
        ]);

        $this->assertSame('range', $layout);
    }

    public function test_finalize_per_day_expands_ranges_into_single_days(): void
    {
        $rows = [
            [
                'start_day' => 1,
                'end_day' => 3,
                'feeding_day' => 1,
                'quantity' => 40.0,
                'feed_type_id' => 1,
                'feeding_times' => [],
            ],
        ];

        $final = $this->service->finalizeFeedingRows($rows, 'per_day');

        $this->assertCount(3, $final);
        $this->assertSame(1, $final[0]['start_day']);
        $this->assertSame(1, $final[0]['end_day']);
        $this->assertSame(2, $final[1]['start_day']);
        $this->assertSame(3, $final[2]['start_day']);
    }

    public function test_finalize_range_merges_identical_adjacent_days(): void
    {
        $rows = [
            [
                'start_day' => 1,
                'end_day' => 1,
                'feeding_day' => 1,
                'quantity' => 40.0,
                'feed_type_id' => 1,
                'feeding_times' => [['time' => '08:00', 'percentage' => 50]],
            ],
            [
                'start_day' => 2,
                'end_day' => 2,
                'feeding_day' => 2,
                'quantity' => 40.0,
                'feed_type_id' => 1,
                'feeding_times' => [['time' => '08:00', 'percentage' => 50]],
            ],
        ];

        $final = $this->service->finalizeFeedingRows($rows, 'range');

        $this->assertCount(1, $final);
        $this->assertSame(1, $final[0]['start_day']);
        $this->assertSame(2, $final[0]['end_day']);
    }
}
