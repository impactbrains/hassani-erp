<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForecastSeries extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'mae' => 'decimal:3',
        'wape' => 'decimal:4',
        'bias' => 'decimal:3',
        'method_scores' => 'array',
        'drivers' => 'array',
        'warnings' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }

    public function points(): HasMany
    {
        return $this->hasMany(DemandForecast::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
