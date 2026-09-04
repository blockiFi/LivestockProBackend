<?php

namespace App\Services\FlockRecordImport;

use App\Http\Controllers\Api\FeedUsageController;
use App\Http\Controllers\Api\FlockDailyRecordController;
use App\Http\Controllers\Api\FlockEggReportController;
use App\Http\Controllers\Api\FlockExpenditureController;
use App\Http\Controllers\Api\FlockMortalityReportController;
use App\Http\Controllers\Api\FlockSaleController;
use App\Http\Controllers\Api\SalesRecordController;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockRecordImport;
use App\Models\FlockRecordImportItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlockRecordImportCommitService
{
    /**
     * @return array{
     *   succeeded: int,
     *   failed: int,
     *   skipped: int,
     *   by_type: array<string, array{succeeded:int, failed:int, skipped:int}>,
     *   failures: list<array{item_id:int, record_type:string, error:string}>
     * }
     */
    public function confirm(FlockRecordImport $draft, Farm $farm, Flock $flock, User $user): array
    {
        $summary = [
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'by_type' => [],
            'failures' => [],
        ];

        foreach (FlockRecordImportItem::CONFIRM_ORDER as $type) {
            $summary['by_type'][$type] = ['succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $items = $draft->items()->orderBy('row_index')->get()
            ->sortBy(function (FlockRecordImportItem $item) {
                $order = array_search($item->record_type, FlockRecordImportItem::CONFIRM_ORDER, true);

                return $order === false ? 999 : $order;
            })
            ->values();

        foreach ($items as $item) {
            $type = $item->record_type;
            if (! isset($summary['by_type'][$type])) {
                $summary['by_type'][$type] = ['succeeded' => 0, 'failed' => 0, 'skipped' => 0];
            }

            if ($item->status === FlockRecordImportItem::STATUS_COMMITTED) {
                $summary['skipped']++;
                $summary['by_type'][$type]['skipped']++;
                continue;
            }

            if ($item->status === FlockRecordImportItem::STATUS_INVALID
                || ! empty($item->validation_errors)) {
                $item->update(['status' => FlockRecordImportItem::STATUS_SKIPPED]);
                $summary['skipped']++;
                $summary['by_type'][$type]['skipped']++;
                continue;
            }

            try {
                $result = DB::transaction(function () use ($item, $farm, $flock, $user) {
                    return $this->commitItem($item, $farm, $flock, $user);
                });

                $item->update([
                    'status' => FlockRecordImportItem::STATUS_COMMITTED,
                    'created_resource_type' => $result['resource_type'],
                    'created_resource_id' => $result['resource_id'],
                    'validation_errors' => null,
                ]);
                $summary['succeeded']++;
                $summary['by_type'][$type]['succeeded']++;
            } catch (\Throwable $e) {
                $item->update([
                    'status' => FlockRecordImportItem::STATUS_INVALID,
                    'validation_errors' => [$e->getMessage()],
                ]);
                $summary['failed']++;
                $summary['by_type'][$type]['failed']++;
                $summary['failures'][] = [
                    'item_id' => $item->id,
                    'record_type' => $type,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $draft->update(['status' => FlockRecordImport::STATUS_CONFIRMED]);

        return $summary;
    }

    /**
     * @return array{resource_type: string, resource_id: int}
     */
    private function commitItem(FlockRecordImportItem $item, Farm $farm, Flock $flock, User $user): array
    {
        $payload = $item->payload ?? [];
        $payload['flock_id'] = $flock->id;

        return match ($item->record_type) {
            FlockRecordImportItem::TYPE_DAILY => $this->commitDaily($payload, $farm, $user),
            FlockRecordImportItem::TYPE_MORTALITY => $this->commitMortality($payload, $farm, $user),
            FlockRecordImportItem::TYPE_EGGS => $this->commitEggs($payload, $farm, $user),
            FlockRecordImportItem::TYPE_FEED_USAGE => $this->commitFeedUsage($payload, $farm, $user),
            FlockRecordImportItem::TYPE_EXPENDITURE => $this->commitExpenditure($payload, $farm, $flock, $user),
            FlockRecordImportItem::TYPE_FLOCK_SALE => $this->commitFlockSale($payload, $farm, $flock, $user),
            FlockRecordImportItem::TYPE_PRODUCT_SALE => $this->commitProductSale($payload, $farm, $flock, $user),
            default => throw new \RuntimeException('Unsupported record type: '.$item->record_type),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource_type: string, resource_id: int}
     */
    private function commitDaily(array $payload, Farm $farm, User $user): array
    {
        $response = $this->callController(
            FlockDailyRecordController::class,
            'store',
            $payload,
            $user,
            [$farm->id]
        );
        $data = $this->assertOk($response);

        return ['resource_type' => 'flock_daily_record', 'resource_id' => (int) ($data['id'] ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource_type: string, resource_id: int}
     */
    private function commitMortality(array $payload, Farm $farm, User $user): array
    {
        $response = $this->callController(
            FlockMortalityReportController::class,
            'store',
            $payload,
            $user,
            [$farm->id]
        );
        $data = $this->assertOk($response);

        return ['resource_type' => 'poultry_mortality_report', 'resource_id' => (int) ($data['id'] ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource_type: string, resource_id: int}
     */
    private function commitEggs(array $payload, Farm $farm, User $user): array
    {
        $response = $this->callController(
            FlockEggReportController::class,
            'store',
            $payload,
            $user,
            [$farm->id]
        );
        $data = $this->assertOk($response);

        return ['resource_type' => 'poultry_flock_egg_report', 'resource_id' => (int) ($data['id'] ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource_type: string, resource_id: int}
     */
    private function commitFeedUsage(array $payload, Farm $farm, User $user): array
    {
        if (empty($payload['poultry_feed_type_id'])) {
            throw new \RuntimeException('poultry_feed_type_id is required for feed usage');
        }
        $payload['usage_date'] = $payload['date'] ?? $payload['usage_date'] ?? null;
        unset($payload['date'], $payload['poultry_feed_type']);

        $response = $this->callController(
            FeedUsageController::class,
            'store',
            $payload,
            $user,
            [$farm->id]
        );
        $data = $this->assertOk($response);

        return ['resource_type' => 'poultry_feed_usage', 'resource_id' => (int) ($data['id'] ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource_type: string, resource_id: int}
     */
    private function commitExpenditure(array $payload, Farm $farm, Flock $flock, User $user): array
    {
        $response = $this->callController(
            FlockExpenditureController::class,
            'store',
            $payload,
            $user,
            [$farm->id, $flock->id]
        );
        $data = $this->assertOk($response);

        return ['resource_type' => 'flock_expenditure', 'resource_id' => (int) ($data['id'] ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource_type: string, resource_id: int}
     */
    private function commitFlockSale(array $payload, Farm $farm, Flock $flock, User $user): array
    {
        $response = $this->callController(
            FlockSaleController::class,
            'store',
            $payload,
            $user,
            [$farm->id, $flock->id]
        );
        $data = $this->assertOk($response);
        // Some controllers wrap sale under data.sale
        $id = $data['id'] ?? $data['sale']['id'] ?? null;

        return ['resource_type' => 'flock_sale', 'resource_id' => (int) $id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource_type: string, resource_id: int}
     */
    private function commitProductSale(array $payload, Farm $farm, Flock $flock, User $user): array
    {
        $payload['flock_id'] = $flock->id;
        $payload['type'] = strtolower((string) ($payload['type'] ?? ''));

        $response = $this->callController(
            SalesRecordController::class,
            'store',
            $payload,
            $user,
            [$farm->id]
        );
        $data = $this->assertOk($response);

        return ['resource_type' => 'sales_record', 'resource_id' => (int) ($data['id'] ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<mixed>  $routeParams
     */
    private function callController(string $controllerClass, string $method, array $payload, User $user, array $routeParams): JsonResponse
    {
        $request = Request::create('/', 'POST', $payload);
        $request->setUserResolver(static fn () => $user);
        $request->headers->set('Accept', 'application/json');

        /** @var object $controller */
        $controller = app($controllerClass);

        return $controller->{$method}($request, ...$routeParams);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertOk(JsonResponse $response): array
    {
        $status = $response->getStatusCode();
        $body = $response->getData(true);

        if ($status >= 200 && $status < 300 && ($body['success'] ?? false)) {
            $data = $body['data'] ?? [];

            return is_array($data) ? $data : [];
        }

        $message = $body['message'] ?? 'Create failed';
        if (! empty($body['errors']) && is_array($body['errors'])) {
            $flat = [];
            array_walk_recursive($body['errors'], function ($v) use (&$flat) {
                if (is_string($v)) {
                    $flat[] = $v;
                }
            });
            if ($flat !== []) {
                $message .= ': '.implode('; ', $flat);
            }
        }

        throw new \RuntimeException($message);
    }
}
