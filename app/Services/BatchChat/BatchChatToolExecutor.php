<?php

namespace App\Services\BatchChat;

use App\Models\BatchScheduleItem;
use App\Models\Farm;
use App\Models\FeedingBatchScheduleItem;
use App\Models\Flock;
use App\Models\FlockDailyRecord;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryFlockEggReport;
use App\Models\PoultryFlockWeightReport;
use App\Models\PoultryMedicationRecord;
use App\Models\PoultryMortalityReport;
use App\Models\PoultryVaccinationRecord;
use App\Models\SalesRecord;
use App\Models\User;
use App\Services\CustomerResolver;
use App\Services\FeedUsageInventoryService;
use App\Services\FlockSaleCullingService;
use App\Services\SalesProfitLossService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class BatchChatToolExecutor
{
    public function __construct(
        protected BatchChatContextService $context,
        protected BatchChatToolRegistry $registry,
        protected SalesProfitLossService $profitLoss,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, resource_id?: int|null, refresh?: list<string>, data?: mixed}
     */
    public function execute(string $name, array $args, Farm $farm, Flock $flock, User $user): array
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if ($this->registry->isWriteTool($name) && ! $flock->isActive()) {
            return $this->fail('This batch has ended. No further updates are allowed.');
        }

        return match ($name) {
            'get_batch_overview' => $this->ok('Batch overview', ['overview' => $this->context->build($flock)], []),
            'get_egg_stock' => $this->eggStock($farm, $flock, $args),
            'list_recent_records' => $this->listRecent($flock, $args),
            'get_schedule_status' => $this->scheduleStatus($flock),
            'get_profit_loss' => $this->ok('Profit & loss', [
                'profit_loss' => $this->profitLoss->flockSummary(
                    (int) $farm->id,
                    (int) $flock->id,
                    isset($args['date_from']) ? (string) $args['date_from'] : null,
                    isset($args['date_to']) ? (string) $args['date_to'] : null
                ),
            ], []),
            'create_daily_record' => $this->createDaily($farm, $flock, $user, $args),
            'create_mortality_report' => $this->createMortality($farm, $flock, $user, $args),
            'create_weight_report' => $this->createWeight($farm, $flock, $user, $args),
            'create_egg_report' => $this->createEgg($farm, $flock, $user, $args),
            'create_feed_usage' => $this->createFeed($farm, $flock, $user, $args),
            'create_medication_record' => $this->createMedication($farm, $flock, $user, $args),
            'create_vaccination_record' => $this->createVaccination($farm, $flock, $user, $args),
            'create_expenditure' => $this->createExpenditure($farm, $flock, $user, $args),
            'create_flock_sale' => $this->createFlockSale($farm, $flock, $user, $args),
            'create_product_sale' => $this->createProductSale($farm, $flock, $user, $args),
            default => $this->fail("Unknown tool: {$name}"),
        };
    }

    /**
     * @param  list<string>  $perms
     */
    private function canAny(User $user, Farm $farm, array $perms): bool
    {
        foreach ($perms as $perm) {
            try {
                if ($user->hasPermissionTo($perm, 'api', $farm)) {
                    return true;
                }
            } catch (\Throwable) {
                // permission may not exist
            }
        }

        return false;
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    /**
     * @param  list<string>  $refresh
     */
    private function ok(string $message, array $data = [], array $refresh = [], ?int $resourceId = null): array
    {
        return array_filter([
            'ok' => true,
            'message' => $message,
            'data' => $data !== [] ? $data : null,
            'refresh' => $refresh !== [] ? $refresh : null,
            'resource_id' => $resourceId,
        ], fn ($v) => $v !== null);
    }

    private function eggStock(Farm $farm, Flock $flock, array $args): array
    {
        $date = $args['date'] ?? Carbon::today()->toDateString();
        $stock = $this->profitLoss->computeEggStock((int) $farm->id, (int) $flock->id, (string) $date);

        return $this->ok('Egg stock', ['egg_stock' => $stock], []);
    }

    private function listRecent(Flock $flock, array $args): array
    {
        $type = (string) ($args['type'] ?? 'daily');
        $allowed = [
            'daily', 'mortality', 'eggs', 'weights', 'feed', 'medications',
            'vaccinations', 'expenditures', 'bird_sales', 'product_sales',
        ];
        if (! in_array($type, $allowed, true)) {
            return $this->fail('Invalid record type');
        }

        $dateFrom = isset($args['date_from']) && $args['date_from'] !== ''
            ? (string) $args['date_from']
            : null;
        $dateTo = isset($args['date_to']) && $args['date_to'] !== ''
            ? (string) $args['date_to']
            : null;

        // Default window stays ~14 days when no range is provided (matches embedded context).
        if ($dateFrom === null && $dateTo === null) {
            $dateFrom = Carbon::today()->subDays(13)->toDateString();
            $dateTo = Carbon::today()->toDateString();
        }

        $limit = min(300, max(1, (int) ($args['limit'] ?? ($dateFrom && $dateTo ? 200 : 14))));
        $payload = $this->context->listRecords($flock, $type, $dateFrom, $dateTo, $limit);

        return $this->ok('Records', $payload, []);
    }

    private function scheduleStatus(Flock $flock): array
    {
        $today = Carbon::today()->toDateString();
        $feedingPending = FeedingBatchScheduleItem::query()
            ->whereHas('batchSchedule', fn ($q) => $q->where('flock_id', $flock->id))
            ->whereDate('feeding_date', '<=', $today)
            ->whereIn('status', ['pending', 'missed', 'overdue'])
            ->count();
        $medVacPending = BatchScheduleItem::query()
            ->whereHas('batchSchedule', fn ($q) => $q->where('flock_id', $flock->id))
            ->whereDate('scheduled_date', '<=', $today)
            ->whereIn('status', ['pending', 'missed', 'overdue'])
            ->count();

        return $this->ok('Schedule status', [
            'feeding_pending_or_overdue' => $feedingPending,
            'medication_vaccination_pending_or_overdue' => $medVacPending,
        ], []);
    }

    private function createDaily(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['create records', 'manage records', 'update flocks', 'manage flocks'])) {
            return $this->fail('You do not have permission to create daily records.');
        }

        $date = (string) ($args['date'] ?? '');
        if ($date === '') {
            return $this->fail('date is required');
        }

        $existing = FlockDailyRecord::where('flock_id', $flock->id)->whereDate('date', $date)->first();
        if ($existing) {
            return $this->fail("A daily record already exists for {$date}.");
        }

        $mortality = (int) ($args['mortality_count'] ?? 0);
        $culls = (int) ($args['culling_count'] ?? 0);
        $eggs = (int) ($args['eggs_collected'] ?? 0);
        $broken = (int) ($args['eggs_broken'] ?? 0);
        $feed = (float) ($args['feed_consumed_kg'] ?? 0);
        $water = (float) ($args['water_consumed_liters'] ?? 0);

        try {
            $record = FlockDailyRecord::create([
                'farm_id' => $farm->id,
                'flock_id' => $flock->id,
                'date' => $date,
                'total_birds' => (int) $flock->actual_quantity,
                'mortality_count' => $mortality,
                'culling_count' => $culls,
                'mortality' => $mortality,
                'culls' => $culls,
                'eggs_collected' => $eggs,
                'eggs_broken' => $broken,
                'feed_consumed_kg' => $feed,
                'feed_consumption_kg' => $feed,
                'water_consumed_liters' => $water,
                'notes' => $args['notes'] ?? null,
                'recorded_by' => $user->id,
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Failed to create daily record: '.$e->getMessage());
        }

        return $this->ok('Daily record created', [], ['daily', 'eggs', 'overview'], (int) $record->id);
    }

    private function createMortality(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['create flock mortality reports', 'manage mortality records', 'create records', 'manage records'])) {
            return $this->fail('You do not have permission to create mortality reports.');
        }

        $count = (int) ($args['mortality_count'] ?? 0);
        if ($count < 1) {
            return $this->fail('mortality_count must be at least 1');
        }

        $birds = max(1, (int) $flock->actual_quantity);
        $report = PoultryMortalityReport::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'poultry_type_id' => $flock->poultry_type_id,
            'date' => $args['date'],
            'mortality_count' => $count,
            'bird_count' => $birds,
            'mortality_percentage' => round(($count / $birds) * 100, 2),
            'notes' => $args['notes'] ?? null,
            'recorded_by' => $user->id,
        ]);

        return $this->ok('Mortality report created', [], ['mortality', 'overview'], (int) $report->id);
    }

    private function createWeight(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['create flock weight reports', 'manage weight records', 'create records', 'manage records'])) {
            return $this->fail('You do not have permission to create weight reports.');
        }

        $report = PoultryFlockWeightReport::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'average_weight' => (float) $args['average_weight'],
            'sample_size' => (int) ($args['sample_size'] ?? 0),
            'number_of_birds' => (int) ($args['sample_size'] ?? $flock->actual_quantity),
            'report_date' => $args['report_date'],
            'notes' => $args['notes'] ?? null,
            'recorded_by' => $user->id,
        ]);

        return $this->ok('Weight report created', [], ['weights', 'overview'], (int) $report->id);
    }

    private function createEgg(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['create flock egg reports', 'manage egg records', 'create records', 'manage records'])) {
            return $this->fail('You do not have permission to create egg reports.');
        }

        $collected = (int) ($args['eggs_collected'] ?? 0);
        $birds = max(1, (int) $flock->actual_quantity);
        $report = PoultryFlockEggReport::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'date' => $args['date'],
            'eggs_collected' => $collected,
            'eggs_broken' => (int) ($args['eggs_broken'] ?? 0),
            'bird_count' => $birds,
            'production_percentage' => round(($collected / $birds) * 100, 2),
            'notes' => $args['notes'] ?? null,
            'recorded_by' => $user->id,
        ]);

        return $this->ok('Egg report created', [], ['eggs', 'overview'], (int) $report->id);
    }

    private function createFeed(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['create feed usages', 'manage feed usages'])) {
            return $this->fail('You do not have permission to create feed usages.');
        }

        $inventoryId = (int) ($args['poultry_feed_inventory_id'] ?? 0);
        $qty = (float) ($args['quantity_kg'] ?? 0);
        if ($inventoryId < 1 || $qty <= 0) {
            return $this->fail('poultry_feed_inventory_id and quantity_kg are required.');
        }

        try {
            $usage = DB::transaction(function () use ($farm, $flock, $user, $inventoryId, $qty, $args) {
                $inventory = PoultryFeedInventory::where('farm_id', $farm->id)->findOrFail($inventoryId);
                FeedUsageInventoryService::deductFromInventory($inventory, $qty);

                $usage = PoultryFeedUsage::create([
                    'farm_id' => $farm->id,
                    'poultry_feed_inventory_id' => $inventory->id,
                    'poultry_feed_type_id' => $inventory->poultry_feed_type_id,
                    'flock_id' => $flock->id,
                    'quantity' => $qty,
                    'unit_cost' => (float) ($inventory->unit_cost ?? 0),
                    'usage_date' => $args['usage_date'],
                    'created_by' => $user->id,
                ]);

                FlockExpenditure::recordFromFeedUsage($usage);

                return $usage;
            });
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok('Feed usage created', [], ['feed', 'overview'], (int) $usage->id);
    }

    private function createMedication(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['create medications', 'manage medications', 'create records', 'manage records'])) {
            return $this->fail('You do not have permission to create medication records.');
        }

        $record = PoultryMedicationRecord::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'poultry_medication_id' => (int) $args['poultry_medication_id'],
            'poultry_medication_inventory_id' => $args['poultry_medication_inventory_id'] ?? null,
            'date' => $args['date'],
            'administered_by' => $user->id,
            'dosage' => (int) ($args['dosage'] ?? 0),
            'quantity' => (float) ($args['quantity'] ?? 0),
            'notes' => $args['notes'] ?? null,
        ]);

        return $this->ok('Medication record created', [], ['medication', 'overview'], (int) $record->id);
    }

    private function createVaccination(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['create vaccines', 'manage vaccines', 'create records', 'manage records'])) {
            return $this->fail('You do not have permission to create vaccination records.');
        }

        $record = PoultryVaccinationRecord::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'poultry_vaccine_id' => (int) $args['poultry_vaccine_id'],
            'poultry_vaccine_inventory_id' => $args['poultry_vaccine_inventory_id'] ?? null,
            'date' => $args['date'],
            'administered_by' => $user->id,
            'dosage' => (int) ($args['dosage'] ?? 0),
            'quantity' => (float) ($args['quantity'] ?? 0),
            'notes' => $args['notes'] ?? null,
        ]);

        return $this->ok('Vaccination record created', [], ['vaccination', 'overview'], (int) $record->id);
    }

    private function createExpenditure(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['create records', 'manage records', 'update flocks', 'manage flocks'])) {
            return $this->fail('You do not have permission to create expenditures.');
        }

        $category = (string) ($args['category'] ?? 'other');
        if (! in_array($category, FlockExpenditure::CATEGORIES, true)) {
            return $this->fail('Invalid expenditure category.');
        }

        $exp = FlockExpenditure::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'category' => $category,
            'amount' => round((float) $args['amount'], 2),
            'description' => $args['description'] ?? null,
            'payment_method' => $args['payment_method'] ?? null,
            'date' => $args['date'],
            'source_type' => 'manual',
            'created_by' => $user->id,
        ]);

        return $this->ok('Expenditure created', [], ['expenditure', 'overview'], (int) $exp->id);
    }

    private function createFlockSale(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['update flocks', 'manage flocks', 'create sales', 'manage sales'])) {
            return $this->fail('You do not have permission to create flock sales.');
        }

        $quantity = (int) ($args['quantity'] ?? 0);
        if ($quantity < 1) {
            return $this->fail('quantity must be at least 1');
        }
        if ($quantity > (int) $flock->actual_quantity) {
            return $this->fail('Cannot sell more birds than the current live flock count ('.$flock->actual_quantity.' available).');
        }

        $customerFields = CustomerResolver::resolveForFarm(
            $farm,
            isset($args['customer_id']) ? (int) $args['customer_id'] : null,
            $args['customer_name'] ?? null,
            $args['customer_phone'] ?? null
        );
        if ($customerFields === null) {
            return $this->fail('Customer does not belong to this farm.');
        }

        $unitPrice = round((float) $args['unit_price'], 2);
        $total = round($quantity * $unitPrice, 2);
        $date = (string) $args['date'];

        try {
            $batchClosed = false;
            $sale = DB::transaction(function () use ($farm, $flock, $user, $quantity, $unitPrice, $total, $date, $customerFields, $args, &$batchClosed) {
                [$dailyRecordId, $cullsApplied] = FlockSaleCullingService::applySaleCulling(
                    $flock,
                    $date,
                    $quantity,
                    $user->id
                );

                $sale = FlockSale::create([
                    'farm_id' => $farm->id,
                    'customer_id' => $customerFields['customer_id'],
                    'flock_id' => $flock->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $total,
                    'date' => $date,
                    'customer_name' => $customerFields['customer_name'],
                    'customer_phone' => $customerFields['customer_phone'],
                    'notes' => $args['notes'] ?? null,
                    'daily_record_id' => $dailyRecordId,
                    'culls_applied' => $cullsApplied,
                    'created_by' => $user->id,
                ]);

                $batchClosed = FlockSaleCullingService::syncBatchStatusAfterSaleChange($flock, $date);

                return $sale;
            });
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $msg = $batchClosed
            ? 'Flock sale recorded. Batch ended — all birds have been sold.'
            : 'Flock sale recorded.';

        return $this->ok($msg, ['batch_closed' => $batchClosed], ['sales', 'overview'], (int) $sale->id);
    }

    private function createProductSale(Farm $farm, Flock $flock, User $user, array $args): array
    {
        if (! $this->canAny($user, $farm, ['create sales', 'manage sales'])) {
            return $this->fail('You do not have permission to create product sales.');
        }

        $type = (string) ($args['type'] ?? '');
        if (! in_array($type, SalesRecord::TYPES, true)) {
            return $this->fail('Invalid product sale type.');
        }

        $qty = (float) ($args['quantity'] ?? 0);
        $date = (string) ($args['date'] ?? '');
        if ($qty <= 0 || $date === '') {
            return $this->fail('date and quantity are required.');
        }

        if ($type === 'egg') {
            $check = $this->profitLoss->validateEggSaleQuantity((int) $farm->id, (int) $flock->id, $date, $qty);
            if (! ($check['valid'] ?? false)) {
                return $this->fail($check['message'] ?? 'Insufficient egg stock.');
            }
        }

        $customerFields = CustomerResolver::resolveForFarm(
            $farm,
            isset($args['customer_id']) ? (int) $args['customer_id'] : null,
            $args['customer_name'] ?? null,
            $args['customer_phone'] ?? null
        );
        if ($customerFields === null) {
            return $this->fail('Customer does not belong to this farm.');
        }

        $unitPrice = round((float) $args['unit_price'], 2);
        $total = round($qty * $unitPrice, 2);

        $sale = SalesRecord::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'type' => $type,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'total_amount' => $total,
            'amount_paid' => 0,
            'date' => $date,
            'customer_id' => $customerFields['customer_id'],
            'customer_name' => $customerFields['customer_name'],
            'customer_phone' => $customerFields['customer_phone'],
            'payment_status' => 'pending',
            'notes' => $args['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        return $this->ok('Product sale recorded', [], ['sales', 'eggs', 'overview'], (int) $sale->id);
    }
}
