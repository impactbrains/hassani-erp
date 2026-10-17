<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A party the business deals with: customer, supplier, employee or job
 * candidate. Type-specific data lives in customer_profiles,
 * supplier_profiles and employee_details. A customer's Khata balance is
 * never stored here; it is read from the accounts-receivable ledger.
 */
class Contact extends Model
{
    use BelongsToCompany;

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_SUPPLIER = 'supplier';

    public const TYPE_EMPLOYEE = 'employee';

    public const TYPE_CANDIDATE = 'candidate';

    protected $guarded = ['id', 'company_id'];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
        'custom_field_answers' => 'array',
        'applied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Contact $contact) {
            $contact->phone_normalized = self::normalizePhone($contact->phone);
        });
    }

    /**
     * Normalises Pakistani mobile numbers so 0300-1234567, +92 300 1234567
     * and 923001234567 are recognised as the same customer.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0092')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '92') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CUSTOMER)->whereNull('merged_into_id');
    }

    public function scopeEmployees(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_EMPLOYEE);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $phone = self::normalizePhone($term);

        return $query->where(function (Builder $q) use ($term, $phone) {
            $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%'])
                ->orWhere('code', 'ilike', $term.'%');
            if ($phone !== null && strlen($phone) >= 4) {
                $q->orWhere('phone_normalized', 'like', '%'.$phone.'%');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function supplierProfile(): HasOne
    {
        return $this->hasOne(SupplierProfile::class);
    }

    public function employeeDetail(): HasOne
    {
        return $this->hasOne(EmployeeDetail::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(CrmInteraction::class)->latest('occurred_at');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'customer_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'customer_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'employee_skill')->withPivot('level')->withTimestamps();
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'employee_badge')->withPivot('awarded_at')->withTimestamps();
    }

    public function resumeItems(): HasMany
    {
        return $this->hasMany(EmployeeResumeItem::class)->orderBy('sort_order');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'merged_into_id');
    }

    // Recruitment (workspace app)
    public function applicationStage(): BelongsTo
    {
        return $this->belongsTo(ApplicationStage::class);
    }

    public function jobAts(): BelongsTo
    {
        return $this->belongsTo(JobAts::class, 'job_ats_id');
    }
}
