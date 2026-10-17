<?php

namespace App\Domain\Crm;

use App\Domain\Accounting\JournalPoster;
use App\Domain\Sales\CreditControl;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\ContactMerge;
use App\Models\CrmCase;
use App\Models\CrmFollowUp;
use App\Models\CrmInteraction;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer records: create/update with duplicate detection, credit terms,
 * consent, and controlled merging of duplicates.
 */
final class CustomerService
{
    public function __construct(private readonly CreditControl $credit, private readonly JournalPoster $journals)
    {
    }

    /** Possible duplicates by phone number or very similar name. */
    public function duplicates(?string $name, ?string $phone, ?int $ignoreId = null): Collection
    {
        $normalized = Contact::normalizePhone($phone);

        return Contact::query()->customers()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(function ($q) use ($name, $normalized) {
                if ($normalized) {
                    $q->orWhere('phone_normalized', $normalized);
                }
                if ($name && mb_strlen(trim($name)) >= 3) {
                    $q->orWhereRaw('similarity(lower(name), lower(?)) > 0.55', [trim($name)]);
                }
            })
            ->limit(5)
            ->get(['id', 'code', 'name', 'phone', 'area']);
    }

    /** @param array<string, mixed> $data */
    public function save(User $user, array $data, ?Contact $customer = null): Contact
    {
        $canManageCredit = $user->hasPermission('crm.credit.manage');
        if (! $customer && empty($data['confirm_not_duplicate']) && ($dupes = $this->duplicates($data['name'] ?? null, $data['phone'] ?? null))->isNotEmpty()) {
            throw new BusinessRuleException('A customer with this phone number or a very similar name already exists.', 'possible_duplicate', ['matches' => $dupes->toArray()]);
        }

        return DB::transaction(function () use ($user, $data, $customer, $canManageCredit) {
            $isNew = $customer === null;
            $customer ??= new Contact(['type' => Contact::TYPE_CUSTOMER, 'created_by' => $user->id]);
            $before = $isNew ? [] : $customer->only(['name', 'phone', 'address', 'is_active']);
            $customer->fill(collect($data)->only(['name', 'name_ur', 'phone', 'alt_phone', 'email', 'address', 'area', 'city', 'delivery_instructions', 'tax_number', 'preferred_language', 'store_id', 'is_active'])->all());
            if ($isNew) {
                $customer->code = Sequences::next('customer');
            }
            $customer->save();

            $profile = $customer->customerProfile ?? new CustomerProfile(['contact_id' => $customer->id]);
            $profile->fill(collect($data)->only(['category', 'price_book_id', 'sales_agent_id'])->all());
            if (array_key_exists('marketing_consent', $data)) {
                $consent = (bool) $data['marketing_consent'];
                if ($consent !== (bool) $profile->marketing_consent) {
                    $profile->consent_recorded_at = now();
                }
                $profile->marketing_consent = $consent;
                $profile->consent_channels = $data['consent_channels'] ?? $profile->consent_channels;
            }
            $creditFields = collect($data)->only(['credit_limit', 'payment_terms_days', 'credit_status', 'credit_status_reason'])->filter(fn ($v) => $v !== null);
            if ($creditFields->isNotEmpty()) {
                if (! $canManageCredit) {
                    if ($isNew && $creditFields->except(['payment_terms_days'])->every(fn ($v, $k) => ($k === 'credit_limit' && Decimal::isZero((string) $v)) || ($k === 'credit_status' && $v === 'active'))) {
                        // defaults are fine
                    } else {
                        throw new BusinessRuleException('You are not allowed to change credit limits or blocks.', 'credit_not_permitted');
                    }
                }
                $oldCredit = $profile->only(['credit_limit', 'payment_terms_days', 'credit_status']);
                $profile->fill($creditFields->map(fn ($v, $k) => $k === 'credit_limit' ? Decimal::money((string) $v) : $v)->all());
                if ($profile->isDirty(['credit_limit', 'credit_status', 'payment_terms_days']) && ! $isNew) {
                    Audit::record('crm.credit_changed', "Changed Khata terms of {$customer->name}", $customer, $oldCredit, $profile->only(['credit_limit', 'payment_terms_days', 'credit_status']));
                }
            }
            $profile->save();
            if (array_key_exists('tag_ids', $data)) {
                $customer->tags()->sync($data['tag_ids'] ?? []);
            }
            Audit::record($isNew ? 'crm.customer_created' : 'crm.customer_updated', ($isNew ? 'Created' : 'Updated')." customer {$customer->name}", $customer, $before, $customer->only(array_keys($before ?: ['name' => 1])));

            return $customer->load('customerProfile', 'tags');
        });
    }

    /**
     * Merges a duplicate into the surviving record: documents and history
     * move over, the Khata balance is transferred with a journal (posted
     * ledger lines are never edited), and the duplicate is closed.
     */
    public function merge(User $user, Contact $survivor, Contact $duplicate): ContactMerge
    {
        if (! $user->hasPermission('crm.customers.merge')) {
            throw new BusinessRuleException('You are not allowed to merge customers.', 'not_permitted');
        }
        if ($survivor->id === $duplicate->id || $survivor->type !== 'customer' || $duplicate->type !== 'customer' || $duplicate->merged_into_id) {
            throw new BusinessRuleException('Choose two different, active customer records.', 'invalid_merge');
        }

        return DB::transaction(function () use ($user, $survivor, $duplicate) {
            $moved = [
                'sales_invoices' => SalesInvoice::query()->where('customer_id', $duplicate->id)->pluck('id')->all(),
                'sales_orders' => SalesOrder::query()->where('customer_id', $duplicate->id)->pluck('id')->all(),
                'payments' => Payment::query()->where('contact_id', $duplicate->id)->pluck('id')->all(),
            ];
            SalesInvoice::query()->where('customer_id', $duplicate->id)->update(['customer_id' => $survivor->id]);
            SalesOrder::query()->where('customer_id', $duplicate->id)->update(['customer_id' => $survivor->id]);
            Payment::query()->where('contact_id', $duplicate->id)->update(['contact_id' => $survivor->id]);
            foreach ([CrmInteraction::class, CrmCase::class, CrmFollowUp::class, PaymentPromise::class] as $model) {
                $model::query()->where('contact_id', $duplicate->id)->update(['contact_id' => $survivor->id]);
            }
            $survivor->tags()->syncWithoutDetaching($duplicate->tags()->pluck('tags.id')->all());

            $balance = $this->credit->balance($duplicate->id);
            $merge = ContactMerge::create([
                'survivor_id' => $survivor->id,
                'merged_id' => $duplicate->id,
                'merged_snapshot' => $duplicate->only(['name', 'phone', 'address', 'code']) + ['khata_balance' => $balance],
                'moved_records' => $moved,
                'merged_by' => $user->id,
            ]);
            $this->journals->post('khata_merge', $merge, now()->toDateString(), "Khata of {$duplicate->name} moved to {$survivor->name}", [
                ['account' => 'accounts_receivable', 'debit' => $balance, 'contact_id' => $survivor->id],
                ['account' => 'accounts_receivable', 'credit' => $balance, 'contact_id' => $duplicate->id],
            ]);
            $duplicate->update(['merged_into_id' => $survivor->id, 'is_active' => false]);
            Audit::record('crm.customers_merged', "Merged {$duplicate->name} into {$survivor->name}", $survivor, new: ['merged_id' => $duplicate->id, 'balance_moved' => $balance]);

            return $merge;
        });
    }
}
