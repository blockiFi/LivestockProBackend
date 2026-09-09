<?php

namespace Tests\Unit;

use App\Services\ScheduleImportService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ScheduleImportJsonDecodeTest extends TestCase
{
    private ScheduleImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduleImportService(
            $this->createMock(\App\Services\LlmService::class)
        );
    }

    public function test_decodes_plain_json_object(): void
    {
        $json = $this->decode('{"vaccinations":[{"age_days":1,"name":"IB"}],"medications":[],"feedings":[]}');

        $this->assertIsArray($json);
        $this->assertCount(1, $json['vaccinations']);
    }

    public function test_decodes_empty_object_as_array(): void
    {
        $json = $this->decode('{}');

        $this->assertSame([], $json);
    }

    public function test_strips_markdown_fences_with_preamble(): void
    {
        $raw = "Here is the schedule:\n```json\n{\"vaccinations\":[{\"age_days\":9,\"name\":\"1st IBD\"}],\"feedings\":[]}\n```\n";
        $json = $this->decode($raw);

        $this->assertSame('1st IBD', $json['vaccinations'][0]['name']);
    }

    public function test_extracts_json_object_from_trailing_text(): void
    {
        $raw = "Sure.\n{\"vaccinations\":[{\"age_days\":14,\"name\":\"Newcastle\"}],\"medications\":[],\"feedings\":[]}\nThanks!";
        $json = $this->decode($raw);

        $this->assertSame(14, $json['vaccinations'][0]['age_days']);
    }

    public function test_extract_item_list_accepts_alias_keys(): void
    {
        $method = new ReflectionMethod(ScheduleImportService::class, 'extractItemList');
        $method->setAccessible(true);

        $rows = $method->invoke($this->service, [
            'vaccines' => [['age_days' => 1, 'name' => 'Marek']],
        ], ['vaccinations', 'vaccines']);

        $this->assertCount(1, $rows);
        $this->assertSame('Marek', $rows[0]['name']);
    }

    public function test_parse_age_days_from_day_label(): void
    {
        $method = new ReflectionMethod(ScheduleImportService::class, 'parseAgeDays');
        $method->setAccessible(true);

        $this->assertSame(112, $method->invoke($this->service, ['age_days' => 'Day 112']));
        $this->assertSame(3, $method->invoke($this->service, ['day' => 3]));
    }

    private function decode(string $raw): ?array
    {
        $method = new ReflectionMethod(ScheduleImportService::class, 'safeJsonDecode');
        $method->setAccessible(true);

        return $method->invoke($this->service, $raw);
    }
}
