<?php

namespace Tests;

use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\MovementTypes;
use App\Domain\Inventory\StockChange;
use App\Domain\Inventory\StockContext;
use App\Domain\Inventory\StockLedger;
use App\Domain\Setup\CompanyProvisioner;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\PriceBook;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected Company $company;

    protected Store $store;

    /** Provisions a company with default configuration and makes it current. */
    protected function setUpCompany(string $name = 'Test Mart'): Company
    {
        $this->company = app(CompanyProvisioner::class)->provision(['name' => $name, 'city' => 'Karachi', 'store_code' => 'T1']);
        CurrentCompany::set($this->company);
        $this->store = Store::query()->firstOrFail();

        return $this->company;
    }

    /** Creates an active user with a role (optionally limited to one store). */
    protected function userWithRole(string $roleKey, ?Store $store = null, string $pin = '1234'): User
    {
        $user = new User(['name' => Str::title($roleKey).' '.Str::random(4), 'email' => Str::random(8).'@example.test', 'password' => 'secret-password-1']);
        $user->company_id = CurrentCompany::require();
        $user->status = 'active';
        $user->pin_hash = Hash::make($pin);
        $user->save();
        UserRole::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'store_id' => $store?->id]);

        return $user;
    }

    /** A stocked product with one SKU in pieces (and optional packs), priced in the retail book. */
    protected function makeProduct(string $name = 'Lux Soap', string $price = '100', array $packs = [], array $productOverrides = []): ProductVariant
    {
        $pcs = Unit::query()->where('code', 'pcs')->firstOrFail();
        $retail = PriceBook::query()->where('code', 'RETAIL')->firstOrFail();
        $units = [['unit_id' => $pcs->id, 'factor' => '1', 'is_default_sale' => true]];
        $prices = [['price_book_id' => $retail->id, 'unit_id' => $pcs->id, 'price' => $price]];
        foreach ($packs as $code => [$factor, $packPrice]) {
            $unit = Unit::query()->where('code', $code)->firstOrFail();
            $units[] = ['unit_id' => $unit->id, 'factor' => (string) $factor];
            if ($packPrice !== null) {
                $prices[] = ['price_book_id' => $retail->id, 'unit_id' => $unit->id, 'price' => $packPrice];
            }
        }
        $product = app(ProductService::class)->save($productOverrides + [
            'name' => $name,
            'type' => 'stocked',
            'variants' => [['sku' => Str::upper(Str::slug($name)).'-'.Str::random(3), 'name' => $name, 'base_unit_id' => $pcs->id, 'units' => $units, 'prices' => $prices]],
        ]);

        return $product->variants->first()->load('product', 'units.unit');
    }

    /** Puts stock on a location through the real ledger (opening stock). */
    protected function receive(ProductVariant $variant, string $quantity, string $unitCost, ?int $locationId = null, ?int $batchId = null): void
    {
        $locationId ??= $this->store->defaultSellingLocation()->id;
        DB::transaction(fn () => app(StockLedger::class)->post([
            new StockChange($this->store->id, $locationId, $variant->id, $quantity, MovementTypes::RECEIPT, $batchId, $unitCost),
        ], new StockContext('test', null, null, now()->toDateString())));
    }

    protected function assertLedgerBalanced(): void
    {
        $totals = JournalLine::query()->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
        $this->assertTrue(Decimal::eq($totals->d, $totals->c), "Ledger out of balance: debit {$totals->d}, credit {$totals->c}");
    }

    /** Signed balance (debit - credit) of a system account, optionally for one contact. */
    protected function accountBalance(string $systemKey, ?int $contactId = null): string
    {
        $row = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.system_key', $systemKey)
            ->when($contactId, fn ($q) => $q->where('journal_lines.contact_id', $contactId))
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as balance')
            ->first();

        return Decimal::money($row->balance);
    }
}
