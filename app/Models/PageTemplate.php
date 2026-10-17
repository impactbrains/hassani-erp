<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PageTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'description',
        'thumbnail_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'thumbnail_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(PageTemplateBlock::class)->orderBy('position');
    }
}

