<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomerAccountTransaction extends Model
{
    public const TYPE_TOP_UP = 'top_up';

    public const TYPE_SALE_PAYMENT = 'sale_payment';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_REFUND = 'refund';

    public const TYPE_REVERSAL = 'reversal';

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    public const TYPES = [
        self::TYPE_TOP_UP,
        self::TYPE_SALE_PAYMENT,
        self::TYPE_ADJUSTMENT,
        self::TYPE_REFUND,
        self::TYPE_REVERSAL,
    ];

    public const PAYMENT_METHODS = [
        'cash',
        'bank_transfer',
        'pos',
        'other',
        'customer_account',
    ];

    protected $fillable = [
        'uuid',
        'farm_id',
        'customer_account_id',
        'customer_id',
        'type',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'payment_method',
        'reference',
        'description',
        'notes',
        'sales_record_id',
        'reverses_transaction_id',
        'idempotency_key',
        'created_by',
        'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transaction) {
            if (blank($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }
            if ($transaction->occurred_at === null) {
                $transaction->occurred_at = now();
            }
        });
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesRecord(): BelongsTo
    {
        return $this->belongsTo(SalesRecord::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }
}
