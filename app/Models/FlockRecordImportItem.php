<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlockRecordImportItem extends Model
{
    public const TYPE_DAILY = 'daily';
    public const TYPE_MORTALITY = 'mortality';
    public const TYPE_EGGS = 'eggs';
    public const TYPE_FEED_USAGE = 'feed_usage';
    public const TYPE_EXPENDITURE = 'expenditure';
    public const TYPE_FLOCK_SALE = 'flock_sale';
    public const TYPE_PRODUCT_SALE = 'product_sale';

    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_SKIPPED = 'skipped';

    public const RECORD_TYPES = [
        self::TYPE_DAILY,
        self::TYPE_MORTALITY,
        self::TYPE_EGGS,
        self::TYPE_FEED_USAGE,
        self::TYPE_EXPENDITURE,
        self::TYPE_FLOCK_SALE,
        self::TYPE_PRODUCT_SALE,
    ];

    public const CONFIRM_ORDER = [
        self::TYPE_DAILY,
        self::TYPE_MORTALITY,
        self::TYPE_EGGS,
        self::TYPE_FEED_USAGE,
        self::TYPE_EXPENDITURE,
        self::TYPE_FLOCK_SALE,
        self::TYPE_PRODUCT_SALE,
    ];

    protected $fillable = [
        'flock_record_import_id',
        'record_type',
        'row_index',
        'payload',
        'confidence',
        'validation_errors',
        'status',
        'created_resource_type',
        'created_resource_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'validation_errors' => 'array',
            'confidence' => 'float',
            'row_index' => 'integer',
            'created_resource_id' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(FlockRecordImport::class, 'flock_record_import_id');
    }
}
