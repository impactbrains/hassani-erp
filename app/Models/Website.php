<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Website extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'subdomain',
        'custom_domain',
        'is_published',
        'published_at',
        'homepage_id',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WebsitePage::class);
    }

    public function homepage(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'homepage_id');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(WebsiteSetting::class);
    }

    /**
     * The public-facing URL for this site — prefers a custom domain,
     * falls back to the platform subdomain.
     */
    public function publicUrl(): ?string
    {
        if ($this->custom_domain) {
            return "https://{$this->custom_domain}";
        }

        if ($this->subdomain) {
            return "https://{$this->subdomain}." . config('app.website_base_domain', 'example.com');
        }

        return null;
    }
}
