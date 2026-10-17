<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlockDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'category',
        'description',
        'thumbnail_id',
        'content_schema',
        'default_content',
        'default_style',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'content_schema' => 'array',
            'default_content' => 'array',
            'default_style' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'thumbnail_id');
    }

    public function pageBlocks(): HasMany
    {
        return $this->hasMany(PageBlock::class);
    }
}
