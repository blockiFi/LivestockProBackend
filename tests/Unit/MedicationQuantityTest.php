<?php

namespace Tests\Unit;

use App\Support\MedicationQuantity;
use PHPUnit\Framework\TestCase;

class MedicationQuantityTest extends TestCase
{
    public function test_converts_to_base_units(): void
    {
        $this->assertSame(0.5, MedicationQuantity::toBase(500, 'mg'));
        $this->assertSame(1500.0, MedicationQuantity::toBase(1.5, 'kg'));
        $this->assertSame(1000.0, MedicationQuantity::toBase(1, 'L'));
        $this->assertSame(10.0, MedicationQuantity::toBase(10, 'ml'));
        $this->assertSame(3.0, MedicationQuantity::toBase(3, 'tablet'));
        $this->assertNull(MedicationQuantity::toBase(3, 'spoon'));
    }

    public function test_families_and_compatibility(): void
    {
        $this->assertSame('g', MedicationQuantity::baseUnit('kg'));
        $this->assertSame('ml', MedicationQuantity::baseUnit('L'));
        $this->assertTrue(MedicationQuantity::compatible('mg', 'g'));
        $this->assertTrue(MedicationQuantity::compatible('L', 'ml'));
        $this->assertFalse(MedicationQuantity::compatible('ml', 'g'));
        $this->assertFalse(MedicationQuantity::compatible('tablet', 'g'));
        $this->assertFalse(MedicationQuantity::compatible(null, 'g'));
    }

    public function test_breakdown_into_packs_and_remainder(): void
    {
        $this->assertSame(
            ['full_packs' => 1, 'loose' => 80.0, 'label' => '1 sachet + 80 g'],
            MedicationQuantity::breakdown(180, 100, 'sachet', 'g')
        );
        $this->assertSame('2 sachets', MedicationQuantity::breakdown(200, 100, 'sachet', 'g')['label']);
        $this->assertSame('990 ml', MedicationQuantity::breakdown(990, 1000, 'bottle', 'ml')['label']);
        $this->assertSame('0 g', MedicationQuantity::breakdown(0, 100, 'sachet', 'g')['label']);
        $this->assertSame('3 boxes + 2.5 tablet', MedicationQuantity::breakdown(32.5, 10, 'box', 'tablet')['label']);
    }
}
