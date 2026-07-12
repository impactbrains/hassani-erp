<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'previous_type',
        'track',
        'stage',
        'application_notes',
        'applied_at',
        'name',
        'email',
        'phone',
        'address',
        'city',
        'tax_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'contact_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(ContactRecord::class);
    }

    public function scopeCustomers($query)
    {
        return $query->where('type', 'customer');
    }

    public function scopeSuppliers($query)
    {
        return $query->where('type', 'supplier');
    }

    public function scopeEmployees($query)
    {
        return $query->where('type', 'employee');
    }

    public function scopeCandidates($query, ?string $track = null)
    {
        $query->where('type', 'candidate');

        return $track ? $query->where('track', $track) : $query;
    }

    /**
     * Starts (or restarts) this contact's candidate pipeline for a given
     * track. If they already have a "real" type (customer/supplier), that
     * gets preserved in previous_type so it can be restored on rejection.
     * Called both by internal "convert to candidate" actions and by the
     * public application forms creating a fresh Contact.
     */
    public function applyAsCandidate(string $track, ?string $notes = null): void
    {
        $this->update([
            'previous_type' => $this->type !== 'candidate' ? $this->type : $this->previous_type,
            'type' => 'candidate',
            'track' => $track,
            'stage' => 'applied',
            'application_notes' => $notes,
            'applied_at' => now(),
        ]);
    }

    /**
     * Advances the candidate to a new pipeline stage without changing
     * their type — used for applied → reviewing → interview transitions.
     */
    public function advanceStage(string $stage): void
    {
        $this->update(['stage' => $stage]);
    }

    /**
     * Hires the candidate — flips their type to the outcome matching
     * their track (employee or supplier). The employee 5-tab profile
     * becomes relevant/populated from this point on.
     */
    public function hire(): void
    {
        $this->update([
            'type' => $this->track === 'supplier' ? 'supplier' : 'employee',
            'stage' => 'hired',
        ]);
    }

    /**
     * Rejects the candidate — restores their previous type if they had
     * one (e.g. they were already a customer), or simply marks the
     * candidate record as rejected if this was a fresh public applicant
     * with no prior relationship to revert to.
     */
    public function reject(): void
    {
        $this->update([
            'type' => $this->previous_type ?? 'candidate',
            'stage' => 'rejected',
        ]);
    }

    /**
     * Total spent across all completed (paid) POS orders, net of any
     * refunds issued against this contact. Draft/cancelled orders and
     * refund documents themselves are excluded from the "orders" side —
     * refunds are subtracted separately so the net figure is accurate.
     */
    public function totalSpent(): float
    {
        $orderedTotal = (float) $this->documents()
            ->where('type', 'pos_order')
            ->where('status', 'paid')
            ->sum('total');

        $refundedTotal = (float) $this->documents()
            ->where('type', 'pos_refund')
            ->sum('total');

        return round($orderedTotal - $refundedTotal, 2);
    }

    /**
     * Count of completed (paid) POS orders placed by this contact.
     */
    public function orderCount(): int
    {
        return $this->documents()
            ->where('type', 'pos_order')
            ->where('status', 'paid')
            ->count();
    }

    public function employeeDetail(): HasOne
    {
        return $this->hasOne(EmployeeDetail::class);
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(EmployeeWorkSchedule::class)->orderBy('day');
    }

    public function resumeItems(): HasMany
    {
        return $this->hasMany(EmployeeResumeItem::class)->orderBy('sort_order');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'employee_skill')
            ->withPivot('level')
            ->withTimestamps();
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'employee_badge')
            ->withPivot('awarded_at')
            ->withTimestamps();
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(EmployeeDetail::class, 'manager_contact_id');
    }

    public function activityHistory(): \Illuminate\Support\Collection
    {
        $asSubject = \App\Models\ActivityLog::where('subject_type', self::class)
            ->where('subject_id', $this->id)
            ->with('user:id,name')
            ->get();

        $asUser = $this->user_id
            ? \App\Models\ActivityLog::where('user_id', $this->user_id)
                ->with('user:id,name')
                ->get()
            : collect();

        return $asSubject->concat($asUser)
            ->unique('id')
            ->sortByDesc('created_at')
            ->values();
    }
}