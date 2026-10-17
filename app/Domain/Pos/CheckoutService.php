<?php

namespace App\Domain\Pos;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Catalog\PriceResolver;
use App\Domain\Catalog\TaxResolver;
use App\Domain\Inventory\Units;
use App\Domain\Sales\CartPricer;
use App\Domain\Sales\CreditControl;
use App\Domain\Sales\SaleDraft;
use App\Domain\Sales\SaleLine;
use App\Domain\Sales\SalePayment;
use App\Domain\Sales\SaleRecorder;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\CostLayer;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\SalesInvoice;
use App\Models\Shift;
use App\Models\User;
use App\Models\VariantCost;
use App\Models\VariantUnit;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * POS checkout. The browser cart is only a proposal: this service re-prices
 * every line from the price book, checks discounts, overrides, MRP, margin
 * floor and Khata limits against the cashier's permissions and manager
 * approvals, then posts the sale through SaleRecorder.
 *
 * Retrying the same checkout (same idempotency key) returns the receipt
 * that was already posted instead of selling twice.
 */
final class CheckoutService
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly TaxResolver $taxes,
        private readonly CartPricer $pricer,
        private readonly ApprovalService $approvals,
        private readonly CreditControl $credit,
        private readonly SaleRecorder $recorder,
        private readonly \App\Domain\Catalog\UnlistedItems $unlisted,
    ) {}

    /** @param array<string, mixed> $input validated CheckoutRequest data */
    public function checkout(User $user, array $input): SalesInvoice
    {
        if ($existing = $this->existing($input['idempotency_key'])) {
            return $existing;
        }

        try {
            return DB::transaction(fn() => $this->post($user, $input));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent retry with the same key won the race; return its receipt.
            if ($existing = $this->existing($input['idempotency_key'])) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Prices a cart without posting it, so the POS shows the server's
     * numbers (and any approvals needed) before payment.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function quote(User $user, array $input): array
    {
        $register = $this->register($user, (int) $input['register_id']);
        $customer = $this->customer($input['customer_id'] ?? null);
        $date = $register->store->businessDate();
        [$lines] = $this->buildLines($user, $register, $customer, $input['lines'], $date, dryRun: true);
        $pricing = $this->pricer->price(
            array_map(fn(SaleLine $line, int $i) => $line->forPricer($i), $lines, array_keys($lines)),
            $this->cartDiscount($input),
            Settings::get('pricing.rounding_step', $register->store_id),
        );

        return $pricing + [
            'unit_prices' => array_map(fn(SaleLine $l) => $l->unitPrice, $lines),
            'list_prices' => array_map(fn(SaleLine $l) => $l->listPrice, $lines),
            'discount_needs_approval' => $this->discountNeedsApproval($user, $register->store_id, $pricing['max_discount_percent']),
        ];
    }

    /** @param array<string, mixed> $input */
    private function post(User $user, array $input): SalesInvoice
    {
        $register = $this->register($user, (int) $input['register_id']);
        $shift = $this->openShift($user, $register);
        $store = $register->store;
        $date = $store->businessDate();
        $customer = $this->customer($input['customer_id'] ?? null);

        [$lines, $approvalIds] = $this->buildLines($user, $register, $customer, $input['lines'], $date, dryRun: false);
        $cartDiscount = $this->cartDiscount($input);
        $roundingStep = Settings::get('pricing.rounding_step', $store->id);
        $pricing = $this->pricer->price(array_map(fn(SaleLine $l, int $i) => $l->forPricer($i), $lines, array_keys($lines)), $cartDiscount, $roundingStep);

        if (Decimal::isPositive($pricing['discount_total'])) {
            if (! $user->hasPermission('pos.discount', $store->id)) {
                throw new BusinessRuleException('You are not allowed to give discounts.', 'discount_not_permitted');
            }
            if ($this->discountNeedsApproval($user, $store->id, $pricing['max_discount_percent'])) {
                $approval = $this->approvals->consume($input['discount_approval_token'] ?? null, 'discount', $store->id, $user);
                if (Decimal::lt($approval->context['max_discount_percent'] ?? '0', $pricing['max_discount_percent'])) {
                    throw new BusinessRuleException('The discount is bigger than the one the manager approved. Ask for approval again.', 'approval_invalid', ['action' => 'discount']);
                }
                $approvalIds[] = $approval->id;
            }
        }
        $this->assertMarginFloor($user, $store->id, $lines, $pricing, $input);

        if (isset($input['expected_total']) && ! Decimal::eq($input['expected_total'], $pricing['total'])) {
            throw new BusinessRuleException(
                'The bill total changed to Rs ' . $pricing['total'] . ' (prices or stock were updated). Check the cart and take payment again.',
                'total_changed',
                ['total' => $pricing['total']],
            );
        }

        $payments = $this->payments($input['payments'] ?? []);
        $paid = Decimal::sum(array_map(fn(SalePayment $p) => $p->amount, $payments));
        $creditAmount = Decimal::money(Decimal::sub($pricing['total'], $paid));
        $creditApproval = null;
        if (Decimal::isPositive($creditAmount)) {
            if (! $user->hasPermission('pos.sell_on_credit', $store->id)) {
                throw new BusinessRuleException('Take full payment: you are not allowed to sell on Khata.', 'credit_not_permitted');
            }
            if ($customer === null) {
                throw new BusinessRuleException('Choose the customer whose Khata this goes on.', 'payment_incomplete');
            }
            $creditApproval = $this->credit->authorize($customer, $creditAmount, $store->id, $user, $input['credit_approval_token'] ?? null, $date);
        }

        $termsDays = $customer?->customerProfile?->payment_terms_days ?: (int) Settings::get('sales.default_credit_days');
        $invoice = $this->recorder->record(new SaleDraft(
            store: $store,
            kind: 'pos',
            channel: 'counter',
            user: $user,
            lines: $lines,
            payments: $payments,
            businessDate: $date,
            customer: $customer,
            priceBookId: $customer?->customerProfile?->price_book_id,
            cartDiscount: $cartDiscount,
            cartDiscountReasonId: $input['cart_discount_reason_id'] ?? null,
            register: $register,
            shift: $shift,
            salesAgentId: $customer?->customerProfile?->sales_agent_id,
            dueDate: CarbonImmutable::parse($date)->addDays($termsDays)->toDateString(),
            idempotencyKey: $input['idempotency_key'],
            notes: $input['notes'] ?? null,
            roundingStep: $roundingStep,
        ));

        $this->auditExceptions($user, $invoice, $input, $approvalIds, $creditApproval?->id);
        $this->unlisted->recordSales($invoice, collect($input['lines'])->filter(fn($l) => ! empty($l['description']) && ! empty($l['barcode']))->mapWithKeys(fn($l) => ['*' . trim($l['description']) => $l['barcode']])->all());

        return $invoice;
    }

    /**
     * @param  list<array<string, mixed>>  $rawLines
     * @return array{0: list<SaleLine>, 1: list<int>}
     */
    private function buildLines(User $user, Register $register, ?Contact $customer, array $rawLines, string $date, bool $dryRun): array
    {
        if ($rawLines === []) {
            throw new BusinessRuleException('The cart is empty.', 'empty_cart');
        }
        $priceBookId = $customer?->customerProfile?->price_book_id;
        $enforceMrp = (bool) Settings::get('pricing.enforce_mrp');
        $lines = [];
        $approvalIds = [];

        foreach ($rawLines as $raw) {
            $variant = ProductVariant::query()->with(['product', 'units.unit'])->where('is_active', true)->find($raw['variant_id']);
            if ($variant === null || ! $variant->product?->is_active) {
                throw new BusinessRuleException('A product in the cart is no longer sold. Remove it and scan again.', 'product_inactive', ['variant_id' => $raw['variant_id']]);
            }
            /** @var VariantUnit|null $unit */
            $unit = $variant->units->firstWhere('id', (int) $raw['variant_unit_id']);
            if ($unit === null || ! $unit->can_sell) {
                throw new BusinessRuleException("{$variant->displayName()} cannot be sold in that pack size.", 'unit_not_sellable');
            }
            $quantity = Decimal::qty($raw['quantity']);
            $baseQuantity = Units::toBase($quantity, $unit);

            if ($variant->product->is_open_item) {
                $lines[] = $this->unlistedLine($variant, $unit, $quantity, $baseQuantity, $raw, $date);

                continue;
            }

            $resolved = $this->prices->resolve($variant, $unit, $priceBookId, $quantity);
            $listPrice = $resolved['price'] ?? null;
            $unitPrice = isset($raw['unit_price']) && $raw['unit_price'] !== null ? Decimal::money($raw['unit_price']) : $listPrice;
            $approvalId = null;

            if ($listPrice === null) {
                // No price set: only an approved manual price can sell it.
                if ($unitPrice === null) {
                    throw new BusinessRuleException("{$variant->displayName()} has no price. Set a price in Products, or ask a manager to approve a manual price.", 'price_missing', ['variant_id' => $variant->id]);
                }
                $listPrice = $unitPrice;
                $approvalId = $this->overrideApproval($user, $register->store_id, $raw, $dryRun);
            } elseif (! Decimal::eq($unitPrice, $listPrice)) {
                $approvalId = $this->overrideApproval($user, $register->store_id, $raw, $dryRun);
            }
            if ($enforceMrp && $variant->mrp !== null && Decimal::gt($unitPrice, Decimal::mul($variant->mrp, $unit->factor))) {
                throw new BusinessRuleException("{$variant->displayName()} cannot be sold above its printed price (MRP Rs " . Decimal::money(Decimal::mul($variant->mrp, $unit->factor)) . ').', 'above_mrp');
            }
            if ($approvalId) {
                $approvalIds[] = $approvalId;
            }

            $tax = $this->taxes->for($variant->product, $date);
            $lines[] = new SaleLine(
                variant: $variant,
                unit: $unit,
                quantity: $quantity,
                baseQuantity: $baseQuantity,
                listPrice: $listPrice,
                unitPrice: $unitPrice,
                taxRate: $tax['rate'],
                taxInclusive: $tax['inclusive'],
                discount: $this->lineDiscount($raw),
                discountReasonId: $raw['discount_reason_id'] ?? null,
                approvalId: $approvalId,
            );
        }

        return [$lines, $approvalIds];
    }

    /**
     * A product not in the list yet: the cashier types its name and price.
     * Not stock tracked; logged as a product to create (see UnlistedItems).
     *
     * @param  array<string, mixed>  $raw
     */
    private function unlistedLine(ProductVariant $variant, VariantUnit $unit, string $quantity, string $baseQuantity, array $raw, string $date): SaleLine
    {
        $name = trim((string) ($raw['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new BusinessRuleException('Type the name of the unlisted item (up to 120 letters).', 'unlisted_name_required');
        }
        $price = isset($raw['unit_price']) && $raw['unit_price'] !== null && $raw['unit_price'] !== '' ? Decimal::money($raw['unit_price']) : null;
        if ($price === null || ! Decimal::isPositive($price)) {
            throw new BusinessRuleException("Type the price of {$name}.", 'unlisted_price_required');
        }
        $tax = $this->taxes->for($variant->product, $date);

        return new SaleLine(
            variant: $variant,
            unit: $unit,
            quantity: $quantity,
            baseQuantity: $baseQuantity,
            listPrice: $price,
            unitPrice: $price,
            taxRate: $tax['rate'],
            taxInclusive: $tax['inclusive'],
            discount: $this->lineDiscount($raw),
            discountReasonId: $raw['discount_reason_id'] ?? null,
            description: '*' . $name,
        );
    }

    /** @param array<string, mixed> $raw */
    private function overrideApproval(User $user, int $storeId, array $raw, bool $dryRun): ?int
    {
        if ($this->approvals->canSelfApprove($user, 'price_override', $storeId) || $dryRun) {
            return null;
        }
        $approval = $this->approvals->consume($raw['price_approval_token'] ?? null, 'price_override', $storeId, $user);

        return $approval->id;
    }

    private function discountNeedsApproval(User $user, int $storeId, string $maxPercent): bool
    {
        if ($this->approvals->canSelfApprove($user, 'discount', $storeId)) {
            return false;
        }

        return Decimal::gt($maxPercent, (string) Settings::get('pricing.max_discount_percent', $storeId));
    }

    /**
     * Refuses discounts or overrides that sell below cost plus the minimum
     * margin, unless a manager approved the discount. Unknown costs are
     * skipped rather than treated as zero.
     *
     * @param  list<SaleLine>  $lines
     * @param  array<string, mixed>  $pricing
     * @param  array<string, mixed>  $input
     */
    private function assertMarginFloor(User $user, int $storeId, array $lines, array $pricing, array $input): void
    {
        $floor = (string) Settings::get('pricing.margin_floor_percent');
        if ($this->approvals->canSelfApprove($user, 'discount', $storeId)) {
            return;
        }
        foreach ($lines as $index => $line) {
            if (! $line->variant->product->isStocked()) {
                continue;
            }
            $unitCost = $this->knownUnitCost($storeId, $line->variant->id);
            if ($unitCost === null) {
                continue;
            }
            $cost = Decimal::mul($unitCost, $line->baseQuantity);
            $minimumRevenue = Decimal::add($cost, Decimal::percentOf($cost, $floor));
            if (
                Decimal::lt($pricing['lines'][$index]['revenue_amount'], $minimumRevenue)
                && (Decimal::isPositive($pricing['lines'][$index]['line_discount']) || Decimal::isPositive($pricing['lines'][$index]['cart_discount_share']) || $line->priceOverridden())
                && empty($input['discount_approval_token']) && $line->approvalId === null
            ) {
                throw new BusinessRuleException(
                    "{$line->variant->displayName()} would sell below cost plus the minimum margin. A manager must approve this discount.",
                    'approval_required',
                    ['action' => 'discount', 'reason' => 'margin_floor'],
                );
            }
        }
    }

    private function knownUnitCost(int $storeId, int $variantId): ?string
    {
        $cost = VariantCost::query()->where('store_id', $storeId)->where('variant_id', $variantId)->first();
        if ($cost && Decimal::isPositive($cost->average_cost)) {
            return $cost->average_cost;
        }

        return CostLayer::query()->where('store_id', $storeId)->where('variant_id', $variantId)->where('unit_cost', '>', 0)->latest('received_at')->value('unit_cost');
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<SalePayment>
     */
    private function payments(array $raw): array
    {
        $payments = [];
        foreach ($raw as $row) {
            $method = PaymentMethod::query()->where('is_active', true)->where('available_in_pos', true)->find($row['payment_method_id']);
            if ($method === null) {
                throw new BusinessRuleException('That payment method is not available at the POS.', 'payment_method_unavailable');
            }
            $amount = Decimal::money($row['amount']);
            if (! Decimal::isPositive($amount)) {
                continue;
            }
            if ($method->requires_reference && empty($row['reference'])) {
                throw new BusinessRuleException("Enter the {$method->name} transaction reference.", 'reference_required', field: 'reference');
            }
            $tendered = null;
            $change = '0.00';
            if ($method->type === 'cash' && isset($row['tendered']) && $row['tendered'] !== '') {
                $tendered = Decimal::money($row['tendered']);
                if (Decimal::lt($tendered, $amount)) {
                    throw new BusinessRuleException('Cash received is less than the cash amount.', 'tendered_too_low', field: 'tendered');
                }
                $change = Decimal::money(Decimal::sub($tendered, $amount));
            }
            $payments[] = new SalePayment($method, $amount, $tendered, $change, $row['reference'] ?? null, $row['terminal_label'] ?? null);
        }

        return $payments;
    }

    /** @param array<string, mixed> $raw @return array{type: string, value: string}|null */
    private function lineDiscount(array $raw): ?array
    {
        $discount = $raw['discount'] ?? null;

        return is_array($discount) && isset($discount['value']) && $discount['value'] !== '' ? ['type' => $discount['type'] ?? 'amount', 'value' => (string) $discount['value']] : null;
    }

    /** @param array<string, mixed> $input @return array{type: string, value: string}|null */
    private function cartDiscount(array $input): ?array
    {
        $discount = $input['cart_discount'] ?? null;

        return is_array($discount) && isset($discount['value']) && $discount['value'] !== '' ? ['type' => $discount['type'] ?? 'amount', 'value' => (string) $discount['value']] : null;
    }

    private function register(User $user, int $registerId): Register
    {
        $register = Register::query()->with('store')->where('is_active', true)->find($registerId);
        if ($register === null || ! $user->hasPermission('pos.sell', $register->store_id)) {
            throw new BusinessRuleException('You cannot sell on this POS counter.', 'register_not_permitted');
        }

        return $register;
    }

    private function openShift(User $user, Register $register): Shift
    {
        $shift = Shift::query()->where('register_id', $register->id)->where('status', 'open')->lockForUpdate()->first();
        if ($shift === null) {
            throw new BusinessRuleException('Open a shift on this POS counter before selling.', 'shift_not_open');
        }
        if ($shift->opened_by !== $user->id && ! $user->hasPermission('pos.shift.join', $register->store_id)) {
            throw new BusinessRuleException('This POS counter\'s shift belongs to another cashier. Ask a manager to join it, or close it and open your own shift.', 'shift_other_cashier');
        }

        return $shift;
    }

    private function customer(mixed $customerId): ?Contact
    {
        if (! $customerId) {
            return null;
        }
        $customer = Contact::query()->customers()->with('customerProfile')->where('is_active', true)->find($customerId);
        if ($customer === null) {
            throw new BusinessRuleException('That customer could not be found.', 'customer_not_found');
        }

        return $customer;
    }

    private function existing(string $key): ?SalesInvoice
    {
        return SalesInvoice::query()->where('idempotency_key', $key)->with(['lines.variant.product', 'lines.variantUnit.unit', 'customer'])->first();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $approvalIds
     */
    private function auditExceptions(User $user, SalesInvoice $invoice, array $input, array $approvalIds, ?int $creditApprovalId): void
    {
        foreach ($input['removed_lines'] ?? [] as $removed) {
            Audit::record('pos.line_removed', 'Removed ' . ($removed['description'] ?? 'item') . ' x' . ($removed['quantity'] ?? '?') . ' before payment', $invoice, new: $removed, storeId: $invoice->store_id);
        }
        if ($invoice->lines->contains(fn($line) => $line->price_overridden)) {
            Audit::record('pos.price_override', "Price override on {$invoice->number}", $invoice, new: ['approvals' => $approvalIds], storeId: $invoice->store_id);
        }
        if (Decimal::isPositive($invoice->discount_total)) {
            Audit::record('pos.discount', "Discount Rs {$invoice->discount_total} on {$invoice->number}", $invoice, new: ['approvals' => $approvalIds], storeId: $invoice->store_id);
        }
        if ($creditApprovalId) {
            Audit::record('pos.credit_override', "Khata limit exception on {$invoice->number}", $invoice, new: ['approval_id' => $creditApprovalId], storeId: $invoice->store_id);
        }
    }
}
