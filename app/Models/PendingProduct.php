<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A product sold at the POS before it existed in the catalogue ("to be made"). */
class PendingProduct extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['first_sold_at' => 'datetime', 'last_sold_at' => 'datetime', 'resolved_at' => 'datetime', 'times_sold' => 'integer'];

    public function sales(): HasMany
    {
        return $this->hasMany(PendingProductSale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lastStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'last_store_id');
    }
}
