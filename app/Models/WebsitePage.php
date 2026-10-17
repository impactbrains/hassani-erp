<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsitePage extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'title',
        'slug',
        'is_homepage',
        'status',
        'meta_title',
        'meta_description',
        'og_image_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_homepage' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(PageBlock::class)->orderBy('position');
    }

    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'og_image_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
