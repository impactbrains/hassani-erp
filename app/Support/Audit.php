<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail. Secrets and identity numbers are redacted so the
 * log never becomes a second copy of sensitive data.
 */
final class Audit
{
    private const REDACTED_KEYS = [
        'password', 'pin', 'pin_hash', 'token', 'token_hash', 'secret', 'two_factor_secret',
        'cnic', 'bank_account', 'bank_details', 'remember_token',
    ];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $context
     */
    public static function record(
        string $event,
        string $description,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $context = [],
        ?int $storeId = null,
    ): ?AuditLog {
        $companyId = CurrentCompany::id() ?? ($subject?->getAttribute('company_id'));
        if ($companyId === null) {
            return null;
        }

        $log = new AuditLog([
            'company_id' => $companyId,
            'user_id' => Auth::id(),
            'store_id' => $storeId ?? ($subject?->getAttribute('store_id')),
            'event' => $event,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 255),
            'old_values' => $old === [] ? null : self::redact($old),
            'new_values' => $new === [] ? null : self::redact($new),
            'context' => $context === [] ? null : self::redact($context),
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
        ]);

        CurrentCompany::runAs((int) $companyId, fn () => $log->save());

        return $log;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSecret($key)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }

    private static function isSecret(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::REDACTED_KEYS as $secret) {
            if ($key === $secret || str_ends_with($key, '_'.$secret)) {
                return true;
            }
        }

        return false;
    }
}
