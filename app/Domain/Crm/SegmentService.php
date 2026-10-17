<?php

namespace App\Domain\Crm;

use App\Domain\Accounting\Accounts;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\CustomerSegment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rule-based customer segments. Every rule is transparent (field, operator,
 * value) and evaluated live in SQL, e.g. "spent over Rs 50,000 in the last
 * 90 days AND last purchase more than 30 days ago" = a lapsing big buyer.
 */
final class SegmentService
{
    public const FIELDS = [
        'spend_90d' => 'Net spend in the last 90 days (Rs)',
        'orders_90d' => 'Purchases in the last 90 days',
        'days_since_purchase' => 'Days since last purchase',
        'khata_balance' => 'Khata balance (Rs)',
        'category' => 'Customer category',
        'area' => 'Area',
        'tag' => 'Has tag',
        'credit_status' => 'Credit status',
        'marketing_consent' => 'Agreed to marketing messages',
    ];

    public const OPERATORS = ['>', '>=', '<', '<=', '=', '!='];

    /** @param list<array{field: string, operator: string, value: mixed}> $rules */
    public function validate(array $rules): void
    {
        if ($rules === []) {
            throw new BusinessRuleException('Add at least one rule.', 'no_rules');
        }
        foreach ($rules as $rule) {
            if (! isset(self::FIELDS[$rule['field'] ?? '']) || ! in_array($rule['operator'] ?? '', self::OPERATORS, true)) {
                throw new BusinessRuleException('Each rule needs a known field and operator.', 'invalid_rule');
            }
        }
    }

    public function members(CustomerSegment $segment): Builder
    {
        return $this->query($segment->rules);
    }

    /** @param list<array{field: string, operator: string, value: mixed}> $rules */
    public function query(array $rules): Builder
    {
        $this->validate($rules);
        $since = now()->subDays(90)->toDateString();
        $ar = Accounts::id('accounts_receivable');
        $query = Contact::query()->customers()->where('contacts.is_active', true)
            ->leftJoin('customer_profiles', 'customer_profiles.contact_id', '=', 'contacts.id')
            ->select('contacts.*')
            ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('customer_id', 'contacts.id')->where('business_date', '>=', $since)->selectRaw('COALESCE(SUM(total - amount_credited), 0)'), 'spend_90d')
            ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('customer_id', 'contacts.id')->where('business_date', '>=', $since)->selectRaw('COUNT(*)'), 'orders_90d')
            ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('customer_id', 'contacts.id')->selectRaw('CURRENT_DATE - MAX(business_date)'), 'days_since_purchase')
            ->selectSub(fn ($q) => $q->from('journal_lines')->whereColumn('contact_id', 'contacts.id')->where('account_id', $ar)->selectRaw('COALESCE(SUM(debit - credit), 0)'), 'khata_balance');

        $outer = Contact::query()->fromSub($query, 'contacts')->select('contacts.*');
        foreach ($rules as $rule) {
            $value = $rule['value'];
            match ($rule['field']) {
                'category' => $outer->whereIn('contacts.id', fn ($q) => $q->select('contact_id')->from('customer_profiles')->where('category', $rule['operator'] === '!=' ? '!=' : '=', $value)),
                'credit_status' => $outer->whereIn('contacts.id', fn ($q) => $q->select('contact_id')->from('customer_profiles')->where('credit_status', $rule['operator'] === '!=' ? '!=' : '=', $value)),
                'marketing_consent' => $outer->whereIn('contacts.id', fn ($q) => $q->select('contact_id')->from('customer_profiles')->where('marketing_consent', (bool) $value)),
                'tag' => $outer->whereIn('contacts.id', fn ($q) => $q->select('contact_id')->from('contact_tag')->where('tag_id', (int) $value)),
                'area' => $outer->whereRaw('lower(coalesce(contacts.area, \'\')) '.($rule['operator'] === '!=' ? '<>' : '=').' lower(?)', [(string) $value]),
                default => $outer->where('contacts.'.$rule['field'], $rule['operator'], is_numeric($value) ? $value : 0),
            };
        }

        return $outer;
    }
}
