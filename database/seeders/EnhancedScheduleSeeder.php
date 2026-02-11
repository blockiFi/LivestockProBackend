<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Schedule;
use App\Models\ScheduleItem;
use App\Models\PoultryType;
use App\Models\MedicationProduct;
use App\Models\PoultryVaccineProduct;

class EnhancedScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $poultryTypes = PoultryType::all();
        
        // Get available medications and vaccines (NOT products)
        $medications = \App\Models\PoultryMedication::pluck('id')->toArray();
        $vaccines = \App\Models\PoultryVaccine::pluck('id')->toArray();
        
        // Get first farm for default schedules
        $defaultFarmId = \App\Models\Farm::first()->id;

        foreach ($poultryTypes as $poultryType) {
            // Create vaccination schedules for each poultry type
            $this->createVaccinationSchedules($poultryType, $vaccines, $defaultFarmId);
            
            // Create medication schedules for each poultry type
            $this->createMedicationSchedules($poultryType, $medications, $defaultFarmId);
        }
    }

    /**
     * Create vaccination schedules for a poultry type
     */
    private function createVaccinationSchedules($poultryType, $vaccines, $farmId)
    {
        $vaccinationSchedules = $this->getVaccinationSchedulesForType($poultryType->name);
        
        foreach ($vaccinationSchedules as $scheduleData) {
            $schedule = Schedule::create([
                'schedule_type' => 'vaccination',
                'poultry_type_id' => $poultryType->id,
                'type' => 'default',
                'name' => $scheduleData['name'],
                'description' => $scheduleData['description'],
                'farm_id' => $farmId,
            ]);

            // Create schedule items for this vaccination schedule
            foreach ($scheduleData['items'] as $itemData) {
                ScheduleItem::create([
                    'schedule_id' => $schedule->id,
                    'name' => $itemData['name'],
                    'description' => $itemData['description'],
                    'age_days' => $itemData['day_number'],
                    'poultry_vaccine_id' => !empty($vaccines) ? $vaccines[array_rand($vaccines)] : null,
                    'dose' => 1,
                    'dose_unit' => 'ml',
                ]);
            }
        }
    }

    /**
     * Create medication schedules for a poultry type
     */
    private function createMedicationSchedules($poultryType, $medications, $farmId)
    {
        $medicationSchedules = $this->getMedicationSchedulesForType($poultryType->name);
        
        foreach ($medicationSchedules as $scheduleData) {
            $schedule = Schedule::create([
                'schedule_type' => 'medication',
                'poultry_type_id' => $poultryType->id,
                'type' => 'default',
                'name' => $scheduleData['name'],
                'description' => $scheduleData['description'],
                'farm_id' => $farmId,
            ]);

            // Create schedule items for this medication schedule
            foreach ($scheduleData['items'] as $itemData) {
                ScheduleItem::create([
                    'schedule_id' => $schedule->id,
                    'name' => $itemData['name'],
                    'description' => $itemData['description'],
                    'age_days' => $itemData['day_number'],
                    'poultry_medication_id' => !empty($medications) ? $medications[array_rand($medications)] : null,
                    'dose' => 1,
                    'dose_unit' => 'ml',
                ]);
            }
        }
    }

    /**
     * Get vaccination schedule data for each poultry type
     */
    private function getVaccinationSchedulesForType($typeName)
    {
        $schedules = [
            'Broiler' => [
                [
                    'name' => 'Broiler Standard Vaccination Program',
                    'description' => 'Complete vaccination program for broiler chickens from day 1 to market age (42 days). Covers all essential vaccines for optimal disease prevention.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Marek\'s Disease Vaccine - Day 1',
                            'description' => 'Administered at hatchery via subcutaneous injection. Provides protection against Marek\'s disease throughout the bird\'s life.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Newcastle Disease + IB - Day 1',
                            'description' => 'Combined vaccine for Newcastle Disease and Infectious Bronchitis, administered via spray or eye drop method.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Gumboro Disease (IBD) - Day 14',
                            'description' => 'First dose of Infectious Bursal Disease vaccine administered via drinking water to protect immune system development.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Newcastle Disease Booster - Day 21',
                            'description' => 'Booster dose for Newcastle Disease to maintain immunity, administered via drinking water or spray.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Gumboro Disease Booster - Day 24',
                            'description' => 'Second dose of IBD vaccine to ensure adequate protection of the immune system.',
                            'day_number' => 24,
                        ],
                    ],
                ],
                [
                    'name' => 'Broiler Intensive Care Vaccination Schedule',
                    'description' => 'Enhanced vaccination program for high-density broiler operations with increased disease pressure. Includes additional boosters and respiratory protection.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Day-Old Multi-Vaccine',
                            'description' => 'Combined Marek\'s, Newcastle, and IB vaccine administered at hatchery.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiosis Vaccine - Day 5',
                            'description' => 'Live oocyst vaccine administered via spray to prevent coccidiosis in litter-raised birds.',
                            'day_number' => 5,
                        ],
                        [
                            'name' => 'IBD Intermediate - Day 12',
                            'description' => 'Intermediate strain Gumboro vaccine for early protection in high-challenge environments.',
                            'day_number' => 12,
                        ],
                        [
                            'name' => 'Respiratory Complex Vaccine - Day 18',
                            'description' => 'Combined vaccine for common respiratory diseases including IB variant strains.',
                            'day_number' => 18,
                        ],
                        [
                            'name' => 'IBD + ND Combined Booster - Day 28',
                            'description' => 'Final booster combining Gumboro and Newcastle protection for market-ready birds.',
                            'day_number' => 28,
                        ],
                    ],
                ],
                [
                    'name' => 'Broiler Minimal Intervention Program',
                    'description' => 'Streamlined vaccination program for low-density, free-range broiler operations with reduced disease challenge. Focuses on essential vaccines only.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Marek\'s Disease - Hatchery',
                            'description' => 'Single subcutaneous injection at hatchery for lifetime Marek\'s protection.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Newcastle + IB Combo - Day 7',
                            'description' => 'Combined ND/IB vaccine administered via coarse spray method.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'IBD Single Dose - Day 14',
                            'description' => 'Single dose intermediate-plus strain for Gumboro protection.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Newcastle Booster - Day 28',
                            'description' => 'Final Newcastle booster before market age.',
                            'day_number' => 28,
                        ],
                    ],
                ],
                [
                    'name' => 'Broiler Export Quality Program',
                    'description' => 'Premium vaccination schedule designed for export-quality broilers requiring certification. Includes comprehensive disease coverage and documentation.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Triple Combo - Day 1',
                            'description' => 'Marek\'s, ND, and IB administered at certified hatchery with full traceability.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Avian Influenza Vaccine - Day 7',
                            'description' => 'Inactivated AI vaccine for export markets requiring AI protection certification.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'IBD Immune Complex - Day 14',
                            'description' => 'Advanced immune complex vaccine for superior Gumboro protection.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Salmonella Prevention - Day 21',
                            'description' => 'Salmonella vaccine for food safety compliance in export markets.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Final Multi-Booster - Day 35',
                            'description' => 'Combined booster for ND, IB, and IBD before export certification.',
                            'day_number' => 35,
                        ],
                    ],
                ],
            ],
            'Layer' => [
                [
                    'name' => 'Layer Pullet to Production Schedule',
                    'description' => 'Comprehensive vaccination program for layer chickens from day 1 through onset of lay (18 weeks). Designed to protect egg production and bird health.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Marek\'s Disease - Day 1',
                            'description' => 'Subcutaneous injection at hatchery for lifetime protection against Marek\'s disease.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'IB + ND Day-Old',
                            'description' => 'Combined live vaccine for respiratory protection from day one.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'IBD First Dose - Day 10',
                            'description' => 'Live intermediate strain Gumboro vaccine via drinking water.',
                            'day_number' => 10,
                        ],
                        [
                            'name' => 'ND + IB Booster - Week 4',
                            'description' => 'First booster for respiratory disease protection.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'IBD Booster - Week 5',
                            'description' => 'Second Gumboro dose for sustained immune protection.',
                            'day_number' => 35,
                        ],
                        [
                            'name' => 'Fowl Pox - Week 8',
                            'description' => 'Wing-web vaccination for fowl pox protection during rearing.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Egg Drop Syndrome - Week 10',
                            'description' => 'Inactivated vaccine to prevent egg production losses.',
                            'day_number' => 70,
                        ],
                        [
                            'name' => 'ND + IB Killed Vaccine - Week 16',
                            'description' => 'Inactivated vaccine for long-lasting immunity through laying cycle.',
                            'day_number' => 112,
                        ],
                    ],
                ],
                [
                    'name' => 'Layer Premium Production Program',
                    'description' => 'Enhanced vaccination schedule for high-producing commercial layers. Includes additional protection for egg quality and extended production cycles.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Day-Old Full Protection',
                            'description' => 'Marek\'s, ND, IB, and Reovirus combined vaccine at hatchery.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiosis Prevention - Day 5',
                            'description' => 'Live coccidiosis vaccine for natural immunity development.',
                            'day_number' => 5,
                        ],
                        [
                            'name' => 'IBD + Reovirus - Week 3',
                            'description' => 'Combined protection against Gumboro and malabsorption syndrome.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Mycoplasma Vaccine - Week 6',
                            'description' => 'Live Mycoplasma gallisepticum vaccine for respiratory health.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Avian Encephalomyelitis - Week 10',
                            'description' => 'Protection against AE to prevent egg production issues.',
                            'day_number' => 70,
                        ],
                        [
                            'name' => 'Salmonella Enteritidis - Week 12',
                            'description' => 'First dose of SE vaccine for food safety compliance.',
                            'day_number' => 84,
                        ],
                        [
                            'name' => 'Multi-Killed Vaccine - Week 14',
                            'description' => 'Inactivated combination vaccine including IB variant strains.',
                            'day_number' => 98,
                        ],
                        [
                            'name' => 'Pre-Lay Booster - Week 17',
                            'description' => 'Final comprehensive booster before onset of lay.',
                            'day_number' => 119,
                        ],
                    ],
                ],
                [
                    'name' => 'Layer Organic/Free-Range Schedule',
                    'description' => 'Specialized vaccination program for organic and free-range layer operations. Emphasizes live vaccines and natural immunity with minimal intervention.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Marek\'s Natural Strain - Day 1',
                            'description' => 'HVT strain Marek\'s vaccine suitable for organic certification.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'ND + IB Live - Week 1',
                            'description' => 'Mild strain live vaccine for respiratory protection.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Gumboro Mild Strain - Week 3',
                            'description' => 'Mild intermediate IBD vaccine for gentle immunity.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Fowl Pox Live - Week 8',
                            'description' => 'Traditional wing-web method for pox protection.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'ND Booster - Week 12',
                            'description' => 'Live Newcastle booster for sustained protection.',
                            'day_number' => 84,
                        ],
                    ],
                ],
                [
                    'name' => 'Layer Breeder Parent Stock Program',
                    'description' => 'Specialized vaccination program for layer parent stock. Designed to provide maternal antibody transfer and extended production cycle protection.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Breeder Day-Old Protection',
                            'description' => 'Enhanced day-old vaccine cocktail including Reovirus and CAV.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'IBD Vector Vaccine - Week 2',
                            'description' => 'HVT-IBD vector vaccine for superior Gumboro protection.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Salmonella Bivalent - Week 6',
                            'description' => 'SE and ST combined vaccine for breeder food safety.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Egg Drop + IB Killed - Week 12',
                            'description' => 'Inactivated vaccine for egg quality protection.',
                            'day_number' => 84,
                        ],
                        [
                            'name' => 'CAV Protection - Week 14',
                            'description' => 'Chicken Anemia Virus vaccine for progeny protection.',
                            'day_number' => 98,
                        ],
                        [
                            'name' => 'Comprehensive Killed - Week 16',
                            'description' => 'Multi-valent killed vaccine for long-lasting immunity.',
                            'day_number' => 112,
                        ],
                    ],
                ],
            ],
            'Cockerel' => [
                [
                    'name' => 'Cockerel Fast-Growth Program',
                    'description' => 'Vaccination schedule optimized for male birds raised for meat production. Emphasizes rapid protection and minimal stress during fast growth phase.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Day-Old Essential Vaccines',
                            'description' => 'Combined Marek\'s, ND, and IB for immediate protection.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'IBD Early Protection - Day 10',
                            'description' => 'Early Gumboro vaccine for fast-growing cockerels.',
                            'day_number' => 10,
                        ],
                        [
                            'name' => 'Newcastle Booster - Day 21',
                            'description' => 'ND booster for maintained respiratory protection.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Final Protection - Day 35',
                            'description' => 'Last vaccine before market weight achievement.',
                            'day_number' => 35,
                        ],
                    ],
                ],
                [
                    'name' => 'Cockerel Standard Meat Production',
                    'description' => 'Balanced vaccination program for cockerels raised to standard market weight. Provides comprehensive disease protection during grow-out period.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Hatchery Vaccination',
                            'description' => 'Marek\'s disease vaccine administered via injection at hatchery.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Respiratory Protection - Week 1',
                            'description' => 'ND and IB combo vaccine via spray application.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Gumboro Week 2',
                            'description' => 'IBD vaccine in drinking water for immune protection.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'ND + IB Booster - Week 4',
                            'description' => 'Respiratory disease booster for continued protection.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'IBD Booster - Week 5',
                            'description' => 'Second Gumboro dose for optimal immunity.',
                            'day_number' => 35,
                        ],
                    ],
                ],
                [
                    'name' => 'Cockerel Breeder Program',
                    'description' => 'Extended vaccination schedule for cockerels selected as breeding stock. Ensures long-term health and fertility.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Marek\'s + Reovirus - Day 1',
                            'description' => 'Combined protection against Marek\'s and malabsorption.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'ND + IB Live - Week 2',
                            'description' => 'Live respiratory vaccine for active immunity development.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'IBD + CAV - Week 4',
                            'description' => 'Combined Gumboro and Chicken Anemia Virus protection.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'Fowl Pox - Week 8',
                            'description' => 'Wing-web vaccination for long-term pox protection.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Killed Vaccine - Week 16',
                            'description' => 'Inactivated multi-valent vaccine for sustained immunity.',
                            'day_number' => 112,
                        ],
                    ],
                ],
                [
                    'name' => 'Cockerel Free-Range Program',
                    'description' => 'Vaccination schedule tailored for free-range cockerel production. Enhanced protection against environmental disease challenges.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Day-Old Protection',
                            'description' => 'Essential vaccines: Marek\'s, ND, IB administered at hatchery.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiosis Live - Day 5',
                            'description' => 'Live oocyst vaccine for natural coccidiosis immunity.',
                            'day_number' => 5,
                        ],
                        [
                            'name' => 'IBD Enhanced - Week 2',
                            'description' => 'Stronger IBD vaccine for outdoor environment exposure.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Fowl Cholera - Week 6',
                            'description' => 'Protection against Pasteurella in free-range settings.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Multi-Booster - Week 8',
                            'description' => 'Combined booster for ND, IB, and IBD before outdoor access.',
                            'day_number' => 56,
                        ],
                    ],
                ],
            ],
            'Pullet' => [
                [
                    'name' => 'Pullet Rearing Standard Program',
                    'description' => 'Complete vaccination schedule for pullets during rearing phase (0-18 weeks). Prepares birds for successful transition to laying house.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Day-Old Essential Protection',
                            'description' => 'Marek\'s, ND, and IB vaccines administered at hatchery.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'IBD Week 2',
                            'description' => 'First Gumboro vaccine for immune system protection.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'ND + IB Booster - Week 4',
                            'description' => 'Respiratory disease booster during early rearing.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'IBD Booster - Week 6',
                            'description' => 'Second Gumboro dose for complete protection.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Fowl Pox - Week 10',
                            'description' => 'Wing-web vaccination for fowl pox immunity.',
                            'day_number' => 70,
                        ],
                        [
                            'name' => 'Egg Drop Syndrome - Week 14',
                            'description' => 'Inactivated vaccine to protect future egg production.',
                            'day_number' => 98,
                        ],
                        [
                            'name' => 'Pre-Lay Killed Vaccine - Week 16',
                            'description' => 'Comprehensive inactivated vaccine before point of lay.',
                            'day_number' => 112,
                        ],
                    ],
                ],
                [
                    'name' => 'Pullet Premium Layer Development',
                    'description' => 'Enhanced vaccination program for premium layer pullets. Focuses on optimal preparation for high egg production and longevity.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Multi-Vaccine Day 1',
                            'description' => 'Comprehensive day-old protection including Marek\'s, ND, IB, and Reovirus.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiosis Vaccine - Day 5',
                            'description' => 'Live vaccine for natural coccidiosis immunity during rearing.',
                            'day_number' => 5,
                        ],
                        [
                            'name' => 'IBD + Reovirus - Week 3',
                            'description' => 'Combined protection for immune and digestive systems.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Mycoplasma - Week 6',
                            'description' => 'Live MG vaccine for respiratory health management.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Salmonella SE - Week 8',
                            'description' => 'First SE vaccine dose for food safety compliance.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Avian Encephalomyelitis - Week 10',
                            'description' => 'AE vaccine to prevent future production problems.',
                            'day_number' => 70,
                        ],
                        [
                            'name' => 'SE Booster - Week 12',
                            'description' => 'Second Salmonella Enteritidis vaccine dose.',
                            'day_number' => 84,
                        ],
                        [
                            'name' => 'Final Killed Vaccine - Week 15',
                            'description' => 'Multi-valent inactivated vaccine for long-lasting protection.',
                            'day_number' => 105,
                        ],
                    ],
                ],
                [
                    'name' => 'Pullet Organic Certification Program',
                    'description' => 'Vaccination schedule compliant with organic standards. Uses approved live vaccines and minimal intervention approach.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Marek\'s HVT Strain - Day 1',
                            'description' => 'Approved Marek\'s vaccine for organic production systems.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'ND + IB Mild Live - Week 1',
                            'description' => 'Gentle live vaccine for respiratory protection.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Gumboro Mild - Week 3',
                            'description' => 'Mild strain IBD vaccine suitable for organic standards.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Fowl Pox Natural - Week 8',
                            'description' => 'Traditional fowl pox vaccination method.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'ND Live Booster - Week 14',
                            'description' => 'Final Newcastle booster before lay period.',
                            'day_number' => 98,
                        ],
                    ],
                ],
                [
                    'name' => 'Pullet Breeder Replacement Program',
                    'description' => 'Specialized vaccination for pullets destined for breeder flock replacement. Ensures superior immunity and progeny protection.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Enhanced Day-Old - Day 1',
                            'description' => 'Premium vaccine combination including CAV and Reovirus.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'IBD Vector Vaccine - Week 2',
                            'description' => 'Advanced HVT-IBD recombinant vaccine.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Reovirus Booster - Week 4',
                            'description' => 'Additional Reovirus protection for breeding stock.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'Salmonella Bivalent - Week 8',
                            'description' => 'SE and ST vaccine for breeder food safety requirements.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'AE + EDS - Week 12',
                            'description' => 'Combined vaccine for egg quality and progeny protection.',
                            'day_number' => 84,
                        ],
                        [
                            'name' => 'Multi-Killed Vaccine - Week 14',
                            'description' => 'Comprehensive inactivated vaccine for maternal antibody transfer.',
                            'day_number' => 98,
                        ],
                        [
                            'name' => 'Pre-Breeder Booster - Week 18',
                            'description' => 'Final vaccine before breeding period commences.',
                            'day_number' => 126,
                        ],
                    ],
                ],
            ],
            'Dual Purpose' => [
                [
                    'name' => 'Dual Purpose Balanced Program',
                    'description' => 'Versatile vaccination schedule for dual-purpose breeds raised for both meat and eggs. Provides balanced protection for extended production period.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Day-Old Foundation Vaccines',
                            'description' => 'Marek\'s, Newcastle, and IB for basic protection.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'IBD Week 2',
                            'description' => 'Gumboro vaccine for immune system development.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'ND + IB Booster - Week 4',
                            'description' => 'Respiratory disease booster for growing birds.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'IBD Booster - Week 6',
                            'description' => 'Second Gumboro dose for sustained protection.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Fowl Pox - Week 10',
                            'description' => 'Wing-web vaccination for long-term pox immunity.',
                            'day_number' => 70,
                        ],
                        [
                            'name' => 'Multi-Killed Vaccine - Week 14',
                            'description' => 'Inactivated vaccine for extended production cycle.',
                            'day_number' => 98,
                        ],
                    ],
                ],
                [
                    'name' => 'Dual Purpose Homestead Program',
                    'description' => 'Simplified vaccination schedule for small-scale dual-purpose flocks. Focuses on essential protection with minimal intervention.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Marek\'s Vaccine - Day 1',
                            'description' => 'Essential Marek\'s protection for backyard flocks.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'ND + IB Combo - Week 1',
                            'description' => 'Combined respiratory disease vaccine.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Gumboro - Week 3',
                            'description' => 'Single dose IBD vaccine for homestead birds.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Newcastle Booster - Week 8',
                            'description' => 'ND booster for continued protection.',
                            'day_number' => 56,
                        ],
                    ],
                ],
                [
                    'name' => 'Dual Purpose Heritage Breed Program',
                    'description' => 'Specialized vaccination for heritage dual-purpose breeds. Gentler approach respecting slower maturation rates and robust natural immunity.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Marek\'s Natural Strain - Day 1',
                            'description' => 'Mild Marek\'s vaccine suitable for heritage breeds.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Respiratory Protection - Week 2',
                            'description' => 'Gentle ND and IB vaccine for slower-growing birds.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'IBD Mild Strain - Week 4',
                            'description' => 'Mild Gumboro vaccine appropriate for heritage genetics.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'Fowl Pox Traditional - Week 12',
                            'description' => 'Traditional wing-web method for heritage flock protection.',
                            'day_number' => 84,
                        ],
                        [
                            'name' => 'ND Booster - Week 16',
                            'description' => 'Final Newcastle booster for adult heritage birds.',
                            'day_number' => 112,
                        ],
                    ],
                ],
                [
                    'name' => 'Dual Purpose Commercial Program',
                    'description' => 'Intensive vaccination schedule for commercial dual-purpose operations. Maximizes both meat yield and egg production potential.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Complete Day-Old Protection',
                            'description' => 'Comprehensive vaccine including Marek\'s, ND, IB, and Reovirus.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiosis - Day 5',
                            'description' => 'Live coccidiosis vaccine for floor-raised dual-purpose birds.',
                            'day_number' => 5,
                        ],
                        [
                            'name' => 'IBD Enhanced - Week 2',
                            'description' => 'Intermediate-plus IBD vaccine for commercial protection.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'ND + IB + IBD Booster - Week 5',
                            'description' => 'Triple combination booster for growing phase.',
                            'day_number' => 35,
                        ],
                        [
                            'name' => 'Fowl Pox + Fowl Cholera - Week 8',
                            'description' => 'Combined protection for extended outdoor access.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Egg Drop Prevention - Week 12',
                            'description' => 'EDS vaccine for birds entering lay period.',
                            'day_number' => 84,
                        ],
                        [
                            'name' => 'Killed Vaccine Final - Week 16',
                            'description' => 'Multi-valent inactivated vaccine for dual production cycle.',
                            'day_number' => 112,
                        ],
                    ],
                ],
            ],
        ];

        return $schedules[$typeName] ?? [];
    }

    /**
     * Get medication schedule data for each poultry type
     */
    private function getMedicationSchedulesForType($typeName)
    {
        $schedules = [
            'Broiler' => [
                [
                    'name' => 'Broiler Growth Performance Program',
                    'description' => 'Medication schedule designed to optimize broiler growth performance and feed efficiency. Includes antibiotics, vitamins, and growth promoters at critical growth stages.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Starter Medication - Day 1-7',
                            'description' => 'Water-soluble vitamins and electrolytes to support chick vitality and reduce early mortality. Administered via drinking water.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiostat Program - Day 7-35',
                            'description' => 'In-feed coccidiostat (e.g., Salinomycin) to prevent coccidiosis during peak susceptibility period.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Growth Promoter Phase 1 - Day 1-21',
                            'description' => 'Approved antibiotic growth promoter in feed to enhance feed conversion and growth rate during starter phase.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Stress Management - Day 14',
                            'description' => 'Vitamin C and E supplementation during vaccination periods to reduce stress and support immune response.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Mycoplasma Prevention - Day 21-35',
                            'description' => 'Preventive antibiotic (e.g., Tylosin) administered via drinking water to control chronic respiratory disease.',
                            'day_number' => 21,
                        ],
                    ],
                ],
                [
                    'name' => 'Broiler Antibiotic-Free Program',
                    'description' => 'Comprehensive medication schedule for antibiotic-free broiler production. Utilizes alternatives including probiotics, prebiotics, essential oils, and organic acids.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Probiotic Starter - Day 1-7',
                            'description' => 'Multi-strain probiotic in drinking water to establish beneficial gut microbiota from day one.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Organic Acid Program - Day 1-42',
                            'description' => 'Blend of organic acids in feed to maintain gut health and control pathogenic bacteria throughout grow-out.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Essential Oil Blend - Day 7-35',
                            'description' => 'Phytogenic feed additive containing essential oils (oregano, thyme) for natural antimicrobial and growth-promoting effects.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Enzyme Complex - Day 14-42',
                            'description' => 'Multi-enzyme preparation to improve nutrient digestibility and reduce digestive disorders.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Immune Modulator - Day 21-28',
                            'description' => 'Beta-glucan and vitamin complex to enhance natural immunity during critical growth phase.',
                            'day_number' => 21,
                        ],
                    ],
                ],
                [
                    'name' => 'Broiler Therapeutic Intervention Protocol',
                    'description' => 'Emergency medication protocol for broiler flocks experiencing health challenges. Designed for rapid intervention under veterinary supervision.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Broad Spectrum Antibiotic - Day 1',
                            'description' => 'First-line broad-spectrum antibiotic (e.g., Amoxicillin) for treatment of bacterial infections. Requires veterinary prescription.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Anti-Coccidial Treatment - Day 3',
                            'description' => 'Therapeutic anti-coccidial (e.g., Amprolium) for clinical coccidiosis outbreaks. 5-day treatment course.',
                            'day_number' => 3,
                        ],
                        [
                            'name' => 'Respiratory Treatment - Day 7',
                            'description' => 'Targeted respiratory antibiotic (e.g., Enrofloxacin) for chronic respiratory disease treatment.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Supportive Therapy - Day 10',
                            'description' => 'Electrolyte and vitamin supplementation to support recovery and reduce treatment stress.',
                            'day_number' => 10,
                        ],
                    ],
                ],
                [
                    'name' => 'Broiler Heat Stress Management',
                    'description' => 'Specialized medication program for broilers during heat stress periods. Focuses on electrolyte balance, vitamin supplementation, and stress mitigation.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Electrolyte Therapy - Continuous',
                            'description' => 'Balanced electrolyte solution in drinking water to maintain hydration and mineral balance during high temperatures.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Vitamin C Supplementation - Daily',
                            'description' => 'High-dose vitamin C (ascorbic acid) to reduce oxidative stress and improve heat tolerance.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Antioxidant Complex - Week 2-6',
                            'description' => 'Selenium, vitamin E, and other antioxidants to protect against heat-induced cellular damage.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Bicarbonate Buffer - As needed',
                            'description' => 'Sodium bicarbonate in feed or water to combat respiratory alkalosis during panting.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Betaine Supplement - Week 3-6',
                            'description' => 'Betaine to improve osmotic regulation and maintain performance under heat stress.',
                            'day_number' => 21,
                        ],
                    ],
                ],
            ],
            'Layer' => [
                [
                    'name' => 'Layer Pullet Development Program',
                    'description' => 'Medication schedule for layer pullets from day-old to point of lay. Ensures optimal development of skeletal structure, immune system, and reproductive organs.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Chick Starter Vitamins - Day 1-14',
                            'description' => 'Complete vitamin and mineral supplement to support early development and reduce first-week mortality.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiosis Control - Day 14-112',
                            'description' => 'Continuous low-dose coccidiostat in feed during rearing to allow controlled immunity development.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Calcium-Phosphorus Balance - Week 6-16',
                            'description' => 'Carefully formulated calcium and phosphorus supplement to ensure proper skeletal development before lay.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Mycoplasma Medication - Week 8-10',
                            'description' => 'Preventive antibiotic treatment (e.g., Tylosin) to control Mycoplasma gallisepticum during critical rearing phase.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Pre-Lay Calcium Boost - Week 16-18',
                            'description' => 'Increased calcium supplementation with vitamin D3 to prepare pullets for eggshell formation.',
                            'day_number' => 112,
                        ],
                    ],
                ],
                [
                    'name' => 'Layer Production Support Program',
                    'description' => 'Comprehensive medication schedule for laying hens in peak production. Focuses on maintaining egg quality, shell strength, and sustained productivity.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Calcium Fortification - Continuous',
                            'description' => 'High-calcium supplement with optimal calcium-phosphorus ratio to maintain strong eggshells throughout lay cycle.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Vitamin E + Selenium - Weekly',
                            'description' => 'Antioxidant supplementation to improve egg quality, fertility, and immune function in laying hens.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Biotin Supplement - Bi-weekly',
                            'description' => 'Biotin supplementation to prevent fatty liver syndrome and maintain feather quality.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Methionine Boost - Continuous',
                            'description' => 'DL-Methionine supplement to support egg production and feather development.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Respiratory Health - Monthly',
                            'description' => 'Preventive respiratory medication (e.g., Tylosin) administered monthly to maintain flock health.',
                            'day_number' => 30,
                        ],
                    ],
                ],
                [
                    'name' => 'Layer Organic Production Program',
                    'description' => 'Medication schedule compliant with organic certification standards. Utilizes approved natural supplements and preventive health management.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Organic Probiotic - Continuous',
                            'description' => 'Certified organic multi-strain probiotic to maintain gut health and natural disease resistance.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Apple Cider Vinegar - Weekly',
                            'description' => 'Organic apple cider vinegar in drinking water to support digestive health and natural pH balance.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Herbal Supplement Blend - Bi-weekly',
                            'description' => 'Certified organic herbal blend (garlic, oregano, thyme) for natural antimicrobial support.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Diatomaceous Earth - Monthly',
                            'description' => 'Food-grade diatomaceous earth for natural parasite control and digestive support.',
                            'day_number' => 30,
                        ],
                        [
                            'name' => 'Kelp Supplement - Weekly',
                            'description' => 'Organic kelp meal providing natural trace minerals and iodine for optimal health.',
                            'day_number' => 7,
                        ],
                    ],
                ],
                [
                    'name' => 'Layer Flock Health Maintenance',
                    'description' => 'Preventive medication program for maintaining layer flock health and preventing common production diseases. Reduces need for therapeutic interventions.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Quarterly Deworming - Every 90 days',
                            'description' => 'Regular deworming program using approved anthelmintics to control internal parasites.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Vitamin Complex - Weekly',
                            'description' => 'Comprehensive vitamin supplement (A, D3, E, K, B-complex) to prevent nutritional deficiencies.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Mycotoxin Binder - Continuous',
                            'description' => 'Feed additive to bind mycotoxins and prevent toxic effects on egg production and liver health.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Stress Vitamin Boost - Monthly',
                            'description' => 'High-dose vitamin C and E during stress periods (weather changes, production peaks).',
                            'day_number' => 30,
                        ],
                        [
                            'name' => 'Prebiotic Fiber - Continuous',
                            'description' => 'Prebiotic supplement (e.g., mannan-oligosaccharides) to support beneficial gut bacteria.',
                            'day_number' => 1,
                        ],
                    ],
                ],
            ],
            'Cockerel' => [
                [
                    'name' => 'Cockerel Rapid Growth Protocol',
                    'description' => 'Intensive medication program for cockerels targeting maximum growth rate and meat yield. Includes growth enhancers and metabolic support.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'High-Energy Supplement - Day 1-42',
                            'description' => 'Concentrated vitamin and energy supplement to support rapid early growth in male birds.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Protein Efficiency Enhancer - Day 7-35',
                            'description' => 'Enzyme complex to improve protein digestibility and muscle development.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Antibiotic Growth Promoter - Day 1-28',
                            'description' => 'Approved AGP (e.g., Bacitracin) to enhance feed efficiency during rapid growth phase.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Leg Health Support - Day 14-42',
                            'description' => 'Calcium, phosphorus, and vitamin D3 supplement to prevent leg disorders in fast-growing cockerels.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Metabolic Acidosis Prevention - Day 21-42',
                            'description' => 'Sodium bicarbonate and potassium carbonate to prevent sudden death syndrome.',
                            'day_number' => 21,
                        ],
                    ],
                ],
                [
                    'name' => 'Cockerel Natural Growth Program',
                    'description' => 'Antibiotic-free medication schedule for cockerels raised using natural growth methods. Emphasizes gut health and natural immunity.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Probiotic Foundation - Day 1-49',
                            'description' => 'Continuous probiotic supplementation to establish robust gut microbiome from day one.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Phytogenic Blend - Day 7-42',
                            'description' => 'Plant-based feed additive (essential oils, herbs) for natural growth promotion.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Organic Acid Treatment - Day 1-49',
                            'description' => 'Butyric and propionic acid blend to control pathogens and improve gut health.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Immune Support - Day 14-35',
                            'description' => 'Beta-glucan and vitamin C complex to strengthen natural immune defenses.',
                            'day_number' => 14,
                        ],
                    ],
                ],
                [
                    'name' => 'Cockerel Breeder Development',
                    'description' => 'Medication program for cockerels selected as breeding stock. Focuses on fertility, health, and longevity rather than rapid growth.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Balanced Growth Support - Day 1-126',
                            'description' => 'Moderate vitamin and mineral supplement to ensure healthy, sustainable growth rate.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Vitamin E + Selenium - Week 4-18',
                            'description' => 'Antioxidant supplementation to support future fertility and semen quality.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'Zinc Methionine - Week 8-18',
                            'description' => 'Organic zinc supplement to support reproductive development and feather quality.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Respiratory Health - Week 12-16',
                            'description' => 'Preventive respiratory medication to ensure long-term breeding soundness.',
                            'day_number' => 84,
                        ],
                        [
                            'name' => 'Pre-Breeding Conditioning - Week 16-20',
                            'description' => 'Comprehensive vitamin and mineral boost before introduction to breeding pens.',
                            'day_number' => 112,
                        ],
                    ],
                ],
                [
                    'name' => 'Cockerel Free-Range Management',
                    'description' => 'Medication schedule for free-range cockerel production. Addresses challenges of outdoor rearing including parasite exposure and variable nutrition.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Comprehensive Vitamins - Continuous',
                            'description' => 'Complete vitamin supplement to compensate for variable nutrient availability in free-range system.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiosis Control - Day 7-56',
                            'description' => 'Ionophore coccidiostat to manage increased coccidiosis risk in outdoor environments.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Regular Deworming - Every 30 days',
                            'description' => 'Monthly deworming program to control intestinal parasites from outdoor exposure.',
                            'day_number' => 30,
                        ],
                        [
                            'name' => 'Immune Enhancement - Day 14-70',
                            'description' => 'Immune-boosting supplement (vitamins A, C, E) to counter environmental stressors.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Trace Mineral Mix - Weekly',
                            'description' => 'Supplemental trace minerals (copper, iron, manganese) to ensure adequate nutrition.',
                            'day_number' => 7,
                        ],
                    ],
                ],
            ],
            'Pullet' => [
                [
                    'name' => 'Pullet Optimal Development Program',
                    'description' => 'Comprehensive medication schedule for pullet rearing from day-old to point of lay. Ensures proper body weight, skeletal development, and reproductive readiness.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Early Chick Vitamins - Day 1-21',
                            'description' => 'Water-soluble vitamin complex to support early growth and reduce first-week mortality in pullet chicks.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiosis Management - Day 21-119',
                            'description' => 'Controlled coccidiostat exposure program to build immunity while preventing clinical disease.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Skeletal Development - Week 4-12',
                            'description' => 'Calcium, phosphorus, and vitamin D3 supplement during critical bone growth period.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'Feather Development - Week 6-14',
                            'description' => 'Methionine and cysteine supplement to support complete feather development and prevent feather pecking.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Respiratory Prevention - Week 8-12',
                            'description' => 'Preventive antibiotic (e.g., Tylosin) during high-risk respiratory disease period.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Pre-Lay Preparation - Week 14-18',
                            'description' => 'Increased calcium and vitamin D3 to prepare reproductive system for egg production.',
                            'day_number' => 98,
                        ],
                    ],
                ],
                [
                    'name' => 'Pullet Premium Quality Program',
                    'description' => 'Enhanced medication schedule for premium layer pullet production. Focuses on achieving uniform flock development and optimal production potential.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Probiotic Foundation - Day 1-126',
                            'description' => 'Continuous probiotic supplementation to establish optimal gut health from day one through point of lay.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Enzyme Complex - Week 2-16',
                            'description' => 'Multi-enzyme preparation to maximize nutrient utilization and growth uniformity.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Vitamin E + Selenium - Week 4-18',
                            'description' => 'Antioxidant program to support immune development and future egg quality.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'Biotin Supplement - Week 8-16',
                            'description' => 'Biotin supplementation to prevent fatty liver and ensure optimal metabolic function.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Mycoplasma Control - Week 10-14',
                            'description' => 'Targeted Mycoplasma medication to prevent chronic respiratory issues.',
                            'day_number' => 70,
                        ],
                        [
                            'name' => 'Uniformity Enhancement - Week 12-16',
                            'description' => 'Specialized supplement to improve body weight uniformity before transfer.',
                            'day_number' => 84,
                        ],
                        [
                            'name' => 'Reproductive Priming - Week 16-18',
                            'description' => 'Vitamin A and E boost to support reproductive tract development.',
                            'day_number' => 112,
                        ],
                    ],
                ],
                [
                    'name' => 'Pullet Antibiotic-Free Rearing',
                    'description' => 'Complete antibiotic-free medication program for pullet rearing. Utilizes natural alternatives and strong biosecurity for disease prevention.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Multi-Strain Probiotic - Continuous',
                            'description' => 'Advanced probiotic blend to establish competitive exclusion against pathogenic bacteria.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Organic Acid Program - Day 1-126',
                            'description' => 'Continuous organic acid supplementation for gut health and pathogen control.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Essential Oil Blend - Week 3-16',
                            'description' => 'Phytogenic feed additive for natural antimicrobial activity and growth promotion.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Immunomodulator - Week 6-14',
                            'description' => 'Beta-glucan and nucleotide supplement to strengthen natural immune defenses.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Herbal Respiratory Support - Week 8-12',
                            'description' => 'Natural herbal blend (oregano, thyme, eucalyptus) for respiratory health support.',
                            'day_number' => 56,
                        ],
                    ],
                ],
                [
                    'name' => 'Pullet Stress Management Program',
                    'description' => 'Specialized medication schedule focused on minimizing stress during critical pullet rearing events. Reduces impact of vaccination, debeaking, and transfer.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Arrival Stress Relief - Day 1-3',
                            'description' => 'High-dose electrolyte and glucose solution to recover from hatch and transport stress.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Vaccination Support - Week 2, 4, 6, 10',
                            'description' => 'Vitamin C and E supplementation 24 hours before and after each vaccination.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Beak Treatment Recovery - Week 6-7',
                            'description' => 'Pain management and nutritional support following beak trimming procedure.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Heat Stress Prevention - Summer months',
                            'description' => 'Electrolyte balance and vitamin C during hot weather periods.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Transfer Preparation - Week 17-18',
                            'description' => 'Stress vitamin complex before and after move to laying house.',
                            'day_number' => 119,
                        ],
                    ],
                ],
            ],
            'Dual Purpose' => [
                [
                    'name' => 'Dual Purpose Balanced Health Program',
                    'description' => 'Versatile medication schedule for dual-purpose breeds. Supports both meat production potential and long-term egg-laying capability.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Foundation Vitamins - Day 1-28',
                            'description' => 'Complete vitamin and mineral supplement to support early growth in dual-purpose chicks.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiosis Control - Day 14-84',
                            'description' => 'Moderate-dose coccidiostat appropriate for slower-growing dual-purpose birds.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Growth Support - Week 4-12',
                            'description' => 'Balanced protein and energy supplement for steady, sustainable growth rate.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'Skeletal Development - Week 6-16',
                            'description' => 'Calcium and phosphorus balance for both meat conformation and future egg production.',
                            'day_number' => 42,
                        ],
                        [
                            'name' => 'Respiratory Health - Week 10-14',
                            'description' => 'Preventive respiratory medication for dual-purpose flock health.',
                            'day_number' => 70,
                        ],
                        [
                            'name' => 'Pre-Production Boost - Week 18-22',
                            'description' => 'Vitamin and mineral supplement to support transition to laying phase.',
                            'day_number' => 126,
                        ],
                    ],
                ],
                [
                    'name' => 'Dual Purpose Homestead Health',
                    'description' => 'Simplified medication program for small-scale dual-purpose flocks. Focuses on essential health maintenance with minimal intervention.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Chick Starter Vitamins - Week 1-2',
                            'description' => 'Basic vitamin supplement for newly hatched or purchased chicks.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Apple Cider Vinegar - Weekly',
                            'description' => 'Natural digestive health support and pH balance maintenance.',
                            'day_number' => 7,
                        ],
                        [
                            'name' => 'Garlic Supplement - Bi-weekly',
                            'description' => 'Natural immune support and mild antiparasitic properties.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Calcium Supplement - Week 16 onwards',
                            'description' => 'Oyster shell or calcium supplement as birds approach laying age.',
                            'day_number' => 112,
                        ],
                    ],
                ],
                [
                    'name' => 'Dual Purpose Heritage Breed Care',
                    'description' => 'Medication schedule tailored for heritage dual-purpose breeds. Respects slower growth rates and emphasizes natural health maintenance.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Gentle Start Vitamins - Day 1-14',
                            'description' => 'Mild vitamin supplement appropriate for hardy heritage breed chicks.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Natural Coccidiosis Management - Week 3-10',
                            'description' => 'Low-dose natural coccidiosis control allowing immunity development.',
                            'day_number' => 21,
                        ],
                        [
                            'name' => 'Herbal Blend - Monthly',
                            'description' => 'Traditional herbal supplement (oregano, garlic, cayenne) for overall health.',
                            'day_number' => 30,
                        ],
                        [
                            'name' => 'Mineral Supplement - Week 8-20',
                            'description' => 'Trace mineral supplement to ensure adequate nutrition during slow growth.',
                            'day_number' => 56,
                        ],
                        [
                            'name' => 'Seasonal Deworming - Every 90 days',
                            'description' => 'Quarterly deworming appropriate for heritage breeds with outdoor access.',
                            'day_number' => 90,
                        ],
                    ],
                ],
                [
                    'name' => 'Dual Purpose Commercial Production',
                    'description' => 'Intensive medication program for commercial dual-purpose operations. Optimizes both meat yield and subsequent egg production.',
                    'status' => 'active',
                    'items' => [
                        [
                            'name' => 'Performance Vitamins - Day 1-126',
                            'description' => 'High-performance vitamin and mineral complex for optimal dual-purpose production.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Probiotic Program - Continuous',
                            'description' => 'Multi-strain probiotic for gut health and performance enhancement.',
                            'day_number' => 1,
                        ],
                        [
                            'name' => 'Coccidiostat Rotation - Day 14-98',
                            'description' => 'Rotational coccidiostat program to prevent resistance and ensure protection.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Enzyme Complex - Week 2-18',
                            'description' => 'Digestive enzymes to maximize feed efficiency in dual-purpose birds.',
                            'day_number' => 14,
                        ],
                        [
                            'name' => 'Growth Phase Support - Week 4-12',
                            'description' => 'Growth-promoting supplement during meat accumulation phase.',
                            'day_number' => 28,
                        ],
                        [
                            'name' => 'Transition Medication - Week 14-18',
                            'description' => 'Specialized supplement to support shift from growth to pre-laying phase.',
                            'day_number' => 98,
                        ],
                        [
                            'name' => 'Laying Preparation - Week 18-22',
                            'description' => 'Calcium, vitamin D3, and reproductive support for onset of lay.',
                            'day_number' => 126,
                        ],
                    ],
                ],
            ],
        ];

        return $schedules[$typeName] ?? [];
    }
}
