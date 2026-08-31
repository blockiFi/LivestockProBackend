<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Equipment;
use App\Models\FarmTaskInstance;
use App\Models\FarmTaskSchedule;
use App\Models\FeedComponent;
use App\Models\FeedingSchedule;
use App\Models\Flock;
use App\Models\FlockExpenditure;
use App\Models\FlockSale;
use App\Models\Notification;
use App\Models\PoultryFeedInventory;
use App\Models\PoultryFeedProduct;
use App\Models\PoultryFeedUsage;
use App\Models\PoultryHouse;
use App\Models\PoultryMedicationInventory;
use App\Models\PoultryVaccineInventory;
use App\Models\SalesRecord;
use App\Models\ScheduleImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminResourceController extends ApiController
{
    private const RESOURCES = [
        'flocks' => [Flock::class, ['farm:id,name', 'poultryType:id,name', 'poultryHouse:id,name', 'flockStage:id,name'], ['name', 'batch_number', 'status', 'breed']],
        'houses' => [PoultryHouse::class, ['farm:id,name', 'poultryType:id,name'], ['name', 'status', 'notes']],
        'feed-inventories' => [PoultryFeedInventory::class, ['farm:id,name', 'feedType:id,name', 'feedProduct:id,name'], ['batch_number', 'status', 'manufacturer']],
        'vaccine-inventories' => [PoultryVaccineInventory::class, ['farm:id,name', 'product:id,name'], ['batch_number', 'status']],
        'medication-inventories' => [PoultryMedicationInventory::class, ['farm:id,name', 'product:id,name'], ['batch_number', 'status']],
        'sales' => [SalesRecord::class, ['farm:id,name', 'flock:id,name'], ['customer_name', 'type', 'payment_status', 'invoice_number']],
        'equipment' => [Equipment::class, ['farm:id,name', 'category:id,name'], ['name', 'status', 'serial_number']],
        'tasks' => [FarmTaskInstance::class, ['farm:id,name', 'schedule:id,title'], ['status', 'title', 'description']],
        'notifications' => [Notification::class, ['user:id,name,email', 'farm:id,name'], ['type', 'title', 'body']],
        'ai-imports' => [ScheduleImport::class, ['farm:id,name', 'creator:id,name'], ['status', 'source_type', 'source_path']],
        'feeding-schedules' => [FeedingSchedule::class, ['farm:id,name', 'poultryType:id,name'], ['title', 'type', 'description']],
        'task-schedules' => [FarmTaskSchedule::class, ['farm:id,name'], ['title', 'section', 'priority', 'description']],
        'feed-products' => [PoultryFeedProduct::class, ['farm:id,name', 'feedType:id,name'], ['name', 'status', 'sku']],
        'feed-components' => [FeedComponent::class, ['farm:id,name'], ['name', 'status', 'description']],
        'feed-usages' => [PoultryFeedUsage::class, ['farm:id,name', 'feedType:id,name', 'flock:id,name'], ['usage_date', 'notes']],
        'flock-sales' => [FlockSale::class, ['farm:id,name', 'flock:id,name'], ['date', 'customer_name', 'payment_status']],
        'expenditures' => [FlockExpenditure::class, ['farm:id,name', 'flock:id,name'], ['category', 'source_type', 'description']],
    ];

    public function show(string $resource, int $id): JsonResponse
    {
        if (! isset(self::RESOURCES[$resource])) {
            return $this->sendNotFoundError('Resource not found');
        }

        [$modelClass, $with] = self::RESOURCES[$resource];

        $record = $modelClass::with($with)->find($id);
        if (! $record) {
            return $this->sendNotFoundError('Record not found');
        }

        return $this->sendResponse(
            $record,
            ucfirst(str_replace('-', ' ', $resource)) . ' record retrieved'
        );
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        if (! isset(self::RESOURCES[$resource])) {
            return $this->sendNotFoundError('Resource not found');
        }

        [$modelClass, $with, $searchFields] = self::RESOURCES[$resource];

        $query = $modelClass::with($with)->orderByDesc('created_at');

        if ($request->filled('farm_id')) {
            $query->where('farm_id', $request->farm_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search, $searchFields) {
                foreach ($searchFields as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $this->sendResponse(
            $query->paginate($request->integer('per_page', 25)),
            ucfirst(str_replace('-', ' ', $resource)) . ' retrieved'
        );
    }
}
