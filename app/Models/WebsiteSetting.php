<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'ga4_measurement_id',
        'gtm_container_id',
        'custom_head_code',
        'custom_body_code',
        'favicon_media_id',
        'default_meta_title',
        'default_meta_description',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function favicon(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'favicon_media_id');
    }
}
