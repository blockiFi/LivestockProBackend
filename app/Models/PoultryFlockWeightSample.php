<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoultryFlockWeightSample extends Model
{
    protected $fillable = [
        'weight_report_id',
        'sequence',
        'weight_g',
        'sex',
        'is_outlier',
        'excluded',
        'note',
    ];

    protected $casts = [
        'weight_g' => 'float',
        'sequence' => 'integer',
        'is_outlier' => 'boolean',
        'excluded' => 'boolean',
    ];

    public function report()
    {
        return $this->belongsTo(PoultryFlockWeightReport::class, 'weight_report_id');
    }
}
