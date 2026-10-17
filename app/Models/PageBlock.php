<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_page_id',
        'block_definition_id',
        'position',
        'content',
        'style',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'style' => 'array',
            'is_visible' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'website_page_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(BlockDefinition::class, 'block_definition_id');
    }
}
