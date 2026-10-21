<?php

namespace App\Domain\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Opening stock entered while creating a product.
 *
 * This is not a second way of changing stock. It builds a standard "opening" stock
 * adjustment for the store the user is working in, so the quantity lands in the stock
 * ledger as an Opening stock movement and posts to accounting (debit Inventory, credit
 * Opening balance equity), exactly like Inventory → Adjustments → Opening stock.
 *
 * It only runs when a product is first created. Editing a product never writes opening
 * stock, so saving twice cannot count the same stock twice.
 */
final class OpeningStock
{
    public function __construct(private readonly AdjustmentService $adjustments)
    {
    }

    /**
     * Picks the variants that have an opening quantity, keyed by their position in the form.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return array<int, array{quantity: string, unit_cost: string|null, batch_no: string|null, expiry_date: string|null}>
     */
    public static function requested(array $variants): array
    {
        $rows = [];
        foreach ($variants as $index => $variant) {
            $quantity = $variant['opening_quantity'] ?? null;
            if ($quantity === null || $quantity === '' || ! is_numeric($quantity) || ! Decimal::isPositive((string) $quantity)) {
                continue;
            }
            $cost = $variant['opening_cost'] ?? null;
            $rows[(int) $index] = [
                'quantity' => (string) $quantity,
                'unit_cost' => ($cost === null || $cost === '') ? null : (string) $cost,
                'batch_no' => filled($variant['opening_batch_no'] ?? null) ? trim((string) $variant['opening_batch_no']) : null,
                'expiry_date' => filled($variant['opening_expiry_date'] ?? null) ? (string) $variant['opening_expiry_date'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Checks everything that can be checked before the product is saved, so the person
     * gets a message next to the field instead of a half-saved product.
     *
     * @param  array<int, array<string, mixed>>  $requested
     */
    public function assertCanRecord(User $user, Store $store, string $productType, bool $tracksBatches, bool $tracksExpiry, array $requested): void
    {
        if ($requested === []) {
            return;
        }
        $first = 'variants.'.array_key_first($requested).'.opening_quantity';
        if ($productType !== 'stocked') {
            throw ValidationException::withMessages([$first => 'Only stock items can have opening stock. Change the kind to “Stock item”, or clear the quantity.']);
        }
        if (! $user->hasPermission('inventory.adjust', $store->id)) {
            throw ValidationException::withMessages([$first => 'You are not allowed to add stock. Ask someone with stock adjustment permission, or clear the quantity.']);
        }
        if ($this->locationFor($store) === null) {
            throw ValidationException::withMessages([$first => "{$store->name} has no selling location to put stock in. Add one in Settings → Stores first."]);
        }
        foreach ($requested as $index => $row) {
            if ($row['unit_cost'] === null) {
                throw ValidationException::withMessages(["variants.{$index}.opening_cost" => 'Enter what each unit cost you (0 if truly unknown) so profit and inventory value are right.']);
            }
            if (($tracksBatches || $tracksExpiry) && $row['batch_no'] === null && $row['expiry_date'] === null) {
                throw ValidationException::withMessages(["variants.{$index}.opening_batch_no" => 'This product tracks batches or expiry. Enter a batch number or an expiry date for the opening stock.']);
            }
        }
    }

    /**
     * Records the opening stock for a product that was just saved.
     * Call inside the same database transaction as the product save.
     *
     * @param  array<int, array{quantity: string, unit_cost: string|null, batch_no: string|null, expiry_date: string|null}>  $requested
     */
    public function record(User $user, Store $store, Product $product, array $requested): ?StockAdjustment
    {
        if ($requested === []) {
            return null;
        }
        $location = $this->locationFor($store);
        // A new product's variants were created in form order, so ids ascend with position.
        $variants = $product->variants()->with('units.unit')->orderBy('id')->get()->values();

        $lines = [];
        foreach ($requested as $index => $row) {
            /** @var ProductVariant|null $variant */
            $variant = $variants[$index] ?? null;
            if ($variant === null) {
                continue;
            }
            $baseUnit = $variant->units->firstWhere('is_base', true);
            try {
                if ($baseUnit !== null) {
                    Units::assertValidQuantity($row['quantity'], $baseUnit);
                }
            } catch (BusinessRuleException $e) {
                throw ValidationException::withMessages(["variants.{$index}.opening_quantity" => $e->getMessage()]);
            }
            $lines[] = [
                'variant_id' => $variant->id,
                'location_id' => $location->id,
                'quantity' => $row['quantity'],
                'unit_cost' => $row['unit_cost'],
                'batch_no' => $row['batch_no'],
                'expiry_date' => $row['expiry_date'],
            ];
        }

        try {
            return $this->adjustments->create($user, $store, 'opening', $lines, null, "Opening stock entered with new product {$product->name}");
        } catch (BusinessRuleException $e) {
            // Includes a closed accounting period. Roll the whole save back with a readable message.
            throw ValidationException::withMessages(['business' => $e->getMessage()]);
        }
    }

    /** Where a store's opening stock goes: its default selling location, else its first sellable one. */
    public function locationFor(Store $store): ?Location
    {
        return $store->defaultSellingLocation()
            ?? $store->locations()->where('is_sellable', true)->orderBy('id')->first();
    }
}
