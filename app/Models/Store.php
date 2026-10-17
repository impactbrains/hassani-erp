<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A branch / shop of a company. Stock, shifts and documents belong to a store. */
class Store extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function registers(): HasMany
    {
        return $this->hasMany(Register::class);
    }

    public function timezone(): string
    {
        // Works even when the store was loaded with only a few columns (e.g. store:id,name).
        $attributes = $this->getAttributes();
        $own = array_key_exists('timezone', $attributes) ? $attributes['timezone'] : ($this->exists ? static::query()->whereKey($this->getKey())->value('timezone') : null);

        return $own ?: (\App\Support\Tenancy\CurrentCompany::get()?->timezone ?? 'Asia/Karachi');
    }

    /** The calendar date at the store right now; documents are dated with it. */
    public function businessDate(?\DateTimeInterface $at = null): string
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone($this->timezone())->toDateString();
    }

    public function defaultSellingLocation(): ?Location
    {
        return $this->locations()->where('is_default_selling', true)->first();
    }

    public function defaultReceivingLocation(): ?Location
    {
        return $this->locations()->where('is_default_receiving', true)->first();
    }
}
