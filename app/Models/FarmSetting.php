<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'currency_code',
        'currency_symbol',
        'timezone',
        'date_format',
        'fiscal_year_start_month',
        'invoice_prefix',
        'invoice_next_number',
        'invoice_tax_enabled',
        'invoice_tax_rate',
        'invoice_payment_instructions',
        'invoice_footer_note',
        'schedule_reminder_days',
        'low_stock_alerts_enabled',
        'mortality_alert_percent',
    ];

    protected $casts = [
        'fiscal_year_start_month' => 'integer',
        'invoice_next_number' => 'integer',
        'invoice_tax_enabled' => 'boolean',
        'invoice_tax_rate' => 'decimal:2',
        'schedule_reminder_days' => 'integer',
        'low_stock_alerts_enabled' => 'boolean',
        'mortality_alert_percent' => 'decimal:2',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}
