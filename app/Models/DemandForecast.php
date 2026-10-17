<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandForecast extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'predicted' => 'decimal:3',
        'lower' => 'decimal:3',
        'upper' => 'decimal:3',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(ForecastSeries::class, 'forecast_series_id');
    }
}
