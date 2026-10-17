<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An uploaded file. Product images use the public disk; employee documents,
 * attachments and receipts use the private disk and are only served through
 * authorised, audited downloads.
 */
class Media extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $appends = ['url'];

    protected $hidden = ['path'];

    public function getUrlAttribute(): ?string
    {
        return $this->disk === 'public' ? Storage::disk('public')->url($this->path) : null;
    }

    public function isPrivate(): bool
    {
        return $this->disk !== 'public';
    }
}
