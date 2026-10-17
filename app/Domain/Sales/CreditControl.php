<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\Accounts;
use App\Domain\Approvals\ApprovalService;
use App\Exceptions\BusinessRuleException;
use App\Models\Approval;
use App\Models\Contact;
use App\Models\JournalLine;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;

/**
 * Khata (customer credit) rules. The balance always comes from the
 * accounts-receivable ledger, so CRM, POS, reports and the customer
 * statement can never disagree.
 */
final class CreditControl
{
    public function __construct(private readonly ApprovalService $approvals)
    {
    }

    /** Positive = the customer owes us; negative = we owe the customer. */
    public function balance(int $contactId): string
    {
        $sum = JournalLine::query()
            ->where('account_id', Accounts::id('accounts_receivable'))
            ->where('contact_id', $contactId)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as balance')
            ->value('balance');

        return Decimal::money($sum ?? '0');
    }

    public function oldestOverdueDays(int $contactId, string $today): int
    {
        $oldestDue = SalesInvoice::query()
            ->where('customer_id', $contactId)
            ->where('status', 'posted')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->min('due_date');

        return $oldestDue ? (int) CarbonImmutable::parse($oldestDue)->diffInDays(CarbonImmutable::parse($today)) : 0;
    }

    /**
     * Checks that a customer may take $newCredit more on Khata. Returns the
     * consumed manager approval when an exception was needed.
     */
    public function authorize(Contact $customer, string $newCredit, int $storeId, User $user, ?string $approvalToken, string $today): ?Approval
    {
        if (! Decimal::isPositive($newCredit)) {
            return null;
        }
        if ($customer->type !== Contact::TYPE_CUSTOMER) {
            throw new BusinessRuleException('Only customers can buy on Khata.', 'credit_not_customer');
        }
        $profile = $customer->customerProfile;
        if ($profile === null || $profile->credit_status === 'blocked') {
            throw new BusinessRuleException("{$customer->name}'s Khata is blocked. Take payment now or ask a manager to unblock the account in CRM.", 'credit_blocked');
        }

        $reasons = [];
        $balance = $this->balance($customer->id);
        $limit = $profile->credit_limit ?? '0';
        $after = Decimal::add($balance, $newCredit);
        if (Decimal::gt($after, $limit)) {
            $reasons[] = 'Khata would be Rs '.Decimal::money($after).' against a limit of Rs '.Decimal::money($limit);
        }
        if ($profile->credit_status === 'on_hold') {
            $reasons[] = 'the account is on hold'.($profile->credit_status_reason ? ' ('.$profile->credit_status_reason.')' : '');
        }
        $blockAfter = (int) Settings::get('sales.block_when_overdue_days');
        $overdueDays = $this->oldestOverdueDays($customer->id, $today);
        if ($blockAfter > 0 && $overdueDays > $blockAfter) {
            $reasons[] = "a balance is {$overdueDays} days overdue";
        }

        if ($reasons === []) {
            return null;
        }
        if ($this->approvals->canSelfApprove($user, 'credit_limit', $storeId)) {
            return null;
        }
        if ($approvalToken === null) {
            throw new BusinessRuleException(
                'Manager approval needed: '.implode('; ', $reasons).'.',
                'approval_required',
                ['action' => 'credit_limit', 'reasons' => $reasons, 'balance' => $balance, 'limit' => Decimal::money($limit)],
            );
        }

        return $this->approvals->consume($approvalToken, 'credit_limit', $storeId, $user);
    }
}
