<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Equipment extends Model
{
    use SoftDeletes;

    protected $table = 'equipment';

    public const STATUSES = [
        'available',
        'in_use',
        'assigned',
        'under_maintenance',
        'damaged',
        'inactive',
        'lost_missing',
        'retired',
        'disposed',
    ];

    public const CONDITIONS = [
        'excellent',
        'good',
        'fair',
        'poor',
        'damaged',
        'unserviceable',
    ];

    protected $fillable = [
        'farm_id',
        'category_id',
        'asset_id',
        'name',
        'equipment_type',
        'brand',
        'model',
        'serial_number',
        'description',
        'quantity',
        'unit',
        'purchase_date',
        'purchase_price',
        'supplier',
        'invoice_reference',
        'purchase_order_number',
        'payment_status',
        'warranty_period_months',
        'warranty_expires_at',
        'farm_section',
        'location',
        'department',
        'poultry_house_id',
        'assigned_to_user_id',
        'assigned_at',
        'status',
        'condition',
        'placed_in_service_date',
        'expected_useful_life_months',
        'current_usage_value',
        'usage_metric',
        'last_inspection_date',
        'next_inspection_date',
        'maintenance_interval_days',
        'next_maintenance_date',
        'last_maintenance_date',
        'qr_code_path',
        'total_maintenance_cost',
        'total_repair_cost',
        'total_other_cost',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'warranty_expires_at' => 'date',
        'placed_in_service_date' => 'date',
        'last_inspection_date' => 'date',
        'next_inspection_date' => 'date',
        'next_maintenance_date' => 'date',
        'last_maintenance_date' => 'date',
        'assigned_at' => 'datetime',
        'purchase_price' => 'decimal:2',
        'current_usage_value' => 'decimal:2',
        'total_maintenance_cost' => 'decimal:2',
        'total_repair_cost' => 'decimal:2',
        'total_other_cost' => 'decimal:2',
        'quantity' => 'integer',
    ];

    protected $appends = ['total_cost', 'warranty_active'];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'category_id');
    }

    public function poultryHouse(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EquipmentAssignment::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(EquipmentTransfer::class);
    }

    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(EquipmentMaintenanceLog::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(EquipmentInspection::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EquipmentDocument::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(EquipmentUsageLog::class);
    }

    public function retirement(): HasOne
    {
        return $this->hasOne(EquipmentRetirement::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(EquipmentActivityLog::class);
    }

    public function getTotalCostAttribute(): float
    {
        return (float) ($this->purchase_price ?? 0)
            + (float) ($this->total_maintenance_cost ?? 0)
            + (float) ($this->total_repair_cost ?? 0)
            + (float) ($this->total_other_cost ?? 0);
    }

    public function getWarrantyActiveAttribute(): bool
    {
        if (!$this->warranty_expires_at) {
            return false;
        }

        return $this->warranty_expires_at->isFuture() || $this->warranty_expires_at->isToday();
    }

    public function scopeActiveAssets($query)
    {
        return $query->whereNotIn('status', ['retired', 'disposed', 'lost_missing']);
    }
}
