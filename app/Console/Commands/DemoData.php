<?php

namespace App\Console\Commands;

use App\Domain\Catalog\ProductService;
use App\Domain\Crm\CustomerService;
use App\Domain\Inventory\AdjustmentService;
use App\Domain\Inventory\CountService;
use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\ShiftService;
use App\Domain\Sales\CreditNoteService;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\PriceBook;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ReasonCode;
use App\Models\Register;
use App\Models\Role;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fills a separate TRAINING database with two days of made-up trading so
 * staff can practise and the screens can be previewed. It refuses to run
 * unless the database name ends in "_demo", so it can never put invented
 * sales into the real books.
 */
class DemoData extends Command
{
    protected $signature = 'hassani:demo-data';

    protected $description = 'Training only: fill a *_demo database with two days of sample trading';

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_demo')) {
            $this->error("Refusing to run on \"{$database}\". Demo data is only allowed in a database whose name ends with _demo.");

            return self::FAILURE;
        }
        $company = Company::query()->firstOrFail();
        CurrentCompany::set($company);
        $store = Store::query()->firstOrFail();
        mt_srand(42);

        $owner = $this->user('Ahmed Hassani', 'owner@demo.test', 'owner');
        $cashier = $this->user('Bilal Khan', 'cashier@demo.test', 'cashier', $store);
        Auth::setUser($owner);

        $tz = $store->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $lastHour = min(21, max(12, (int) CarbonImmutable::now($tz)->format('G')));
        // Prices and opening stock must exist before the first sample sale.
        Carbon::setTestNow(CarbonImmutable::now($store->timezone())->subDays(2)->setTime(8, 0)->utc());
        $variants = $this->products();
        $floor = $store->defaultSellingLocation()->id;
        app(AdjustmentService::class)->create($owner, $store, 'opening', collect($variants)->map(fn ($v) => ['variant_id' => $v['variant']->id, 'location_id' => $floor, 'quantity' => (string) $v['stock'], 'unit_cost' => $v['cost']])->values()->all(), null, 'Opening stock (training data)');

        $customers = collect([['Gulshan Kiryana Store', '0300-2211334', '50000'], ['Saima Bibi', '0321-8877665', '15000'], ['Al-Madina General Store', '0333-1122998', '80000']])
            ->map(fn ($c) => app(CustomerService::class)->save($owner, ['name' => $c[0], 'phone' => $c[1], 'area' => 'Gulshan-e-Iqbal', 'city' => 'Karachi', 'category' => 'wholesale', 'credit_limit' => $c[2], 'confirm_not_duplicate' => true]));

        $register = Register::query()->where('store_id', $store->id)->firstOrFail();
        $methods = PaymentMethod::query()->pluck('id', 'code');

        foreach ([[$today->subDay(), 21, 26, true], [$today, $lastHour, 31, false]] as [$day, $endHour, $count, $close]) {
            Carbon::setTestNow($day->setTime(9, 0)->utc());
            $shift = app(ShiftService::class)->open($cashier, $register, ['1000' => 5, '500' => 4, '100' => 10]);
            $first = null;
            for ($i = 0; $i < $count; $i++) {
                $hour = 9 + (int) floor(($endHour - 9) * $this->peak(mt_rand(0, 1000) / 1000));
                Carbon::setTestNow($day->setTime($hour, mt_rand(0, 59))->utc());
                $lines = collect($variants)->shuffle()->take(mt_rand(1, 4))->map(fn ($v) => ['variant_id' => $v['variant']->id, 'variant_unit_id' => $v['variant']->units->firstWhere('is_default_sale', true)->id, 'quantity' => (string) mt_rand(1, $v['max'])])->values()->all();
                $quote = app(CheckoutService::class)->quote($cashier, ['register_id' => $register->id, 'lines' => $lines]);
                $total = $quote['total'];
                $roll = mt_rand(1, 100);
                $customer = $roll > 88 ? $customers->random() : null;
                $payments = match (true) {
                    $customer !== null => [['payment_method_id' => $methods['cash'], 'amount' => (string) floor($total / 2)]],
                    $roll > 76 => [['payment_method_id' => $methods['easypaisa'], 'amount' => $total, 'reference' => 'EP'.mt_rand(10000000, 99999999)]],
                    $roll > 68 => [['payment_method_id' => $methods['jazzcash'], 'amount' => $total, 'reference' => 'JC'.mt_rand(10000000, 99999999)]],
                    $roll > 62 => [['payment_method_id' => $methods['card'], 'amount' => $total, 'reference' => (string) mt_rand(100000, 999999), 'terminal_label' => 'HBL POS']],
                    default => [['payment_method_id' => $methods['cash'], 'amount' => $total, 'tendered' => (string) (ceil($total / 500) * 500)]],
                };
                $invoice = app(CheckoutService::class)->checkout($cashier, ['idempotency_key' => (string) Str::uuid(), 'register_id' => $register->id, 'customer_id' => $customer?->id, 'lines' => $lines, 'payments' => $payments]);
                $first ??= $invoice;
            }
            if ($first) {
                Carbon::setTestNow($day->setTime(min($endHour, 20), 30)->utc());
                app(CreditNoteService::class)->create($cashier, $first, ['lines' => [['invoice_line_id' => $first->lines->first()->id, 'quantity' => '1', 'disposition' => 'restock']], 'reason_code_id' => ReasonCode::query()->where('type', 'return')->value('id'), 'settlement' => 'refund', 'payment_method_id' => $methods['cash'], 'shift_id' => $shift->id, 'approval_token' => null]);
            }
            if ($close) {
                Carbon::setTestNow($day->setTime(22, 10)->utc());
                $expected = app(\App\Domain\Pos\ShiftReport::class)->expectedCash($shift->fresh());
                $counted = (int) floor((float) $expected) - 120; // a small shortage to show the review flow
                app(ShiftService::class)->close($cashier, $shift->fresh(), $this->notes($counted), 'Busy evening');
            }
        }

        Carbon::setTestNow($today->setTime(min($lastHour, 20), 45)->utc());
        $count = app(CountService::class)->plan($owner, $store, 'products', ['variant_ids' => collect($variants)->take(4)->map(fn ($v) => $v['variant']->id)->values()->all()], true, false, null, 'Weekly spot check');
        app(CountService::class)->start($owner, $count);
        foreach ($count->fresh('lines')->lines as $index => $line) {
            app(CountService::class)->record($owner, $line, (string) ((float) $line->expected_quantity - ($index === 2 ? 2 : 0)));
        }
        app(CountService::class)->submit($owner, $count->fresh());
        app(CountService::class)->approveAndPost($owner, $count->fresh(), [$count->fresh('lines')->lines[2]->id => ReasonCode::query()->where('type', 'write_off')->where('code', 'missing')->value('id')]);
        Carbon::setTestNow();

        $this->info('Training data added. Sign in as owner@demo.test or cashier@demo.test with password "demo-password-1".');

        return self::SUCCESS;
    }

    private function user(string $name, string $email, string $role, ?Store $store = null): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => 'demo-password-1']);
        $user->company_id = CurrentCompany::require();
        $user->status = 'active';
        $user->pin_hash = Hash::make('2580');
        $user->save();
        UserRole::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'store_id' => $store?->id]);

        return $user;
    }

    /** @return list<array{variant: ProductVariant, cost: string, stock: int, max: int}> */
    private function products(): array
    {
        $pcs = Unit::query()->where('code', 'pcs')->value('id');
        $retail = PriceBook::query()->where('is_default', true)->value('id');
        $category = fn (string $name, ?string $ur) => ProductCategory::query()->firstOrCreate(['name' => $name], ['name_ur' => $ur, 'slug' => Str::slug($name)])->id;
        $items = [
            ['Dalda Cooking Oil 1L', 'ڈالڈا کوکنگ آئل', 'Cooking oil', 'تیل', '590', '540', 120, 3],
            ['Basmati Rice 5kg', 'باسمتی چاول', 'Rice & daal', 'چاول اور دال', '1850', '1690', 60, 2],
            ['Lux Soap 98g', 'لکس صابن', 'Personal care', 'ذاتی نگہداشت', '140', '118', 200, 4],
            ['Shan Biryani Masala', 'شان بریانی مصالحہ', 'Spices', 'مصالحے', '160', '132', 150, 3],
            ['National Salt 800g', 'نیشنل نمک', 'Spices', 'مصالحے', '70', '55', 180, 3],
            ['Nestle Milkpak 1L', 'نیسلے ملک پیک', 'Dairy', 'دودھ', '330', '298', 140, 4],
            ['Daal Chana 1kg', 'دال چنا', 'Rice & daal', 'چاول اور دال', '420', '370', 90, 2],
            ['Surf Excel 1kg', 'سرف ایکسل', 'Cleaning', 'صفائی', '650', '585', 80, 2],
        ];
        $out = [];
        foreach ($items as [$name, $ur, $cat, $catUr, $price, $cost, $stock, $max]) {
            $product = app(ProductService::class)->save(['name' => $name, 'name_ur' => $ur, 'type' => 'stocked', 'category_id' => $category($cat, $catUr), 'is_online' => true,
                'variants' => [['name' => $name, 'name_ur' => $ur, 'base_unit_id' => $pcs, 'units' => [['unit_id' => $pcs, 'factor' => '1', 'is_default_sale' => true]], 'prices' => [['price_book_id' => $retail, 'unit_id' => $pcs, 'price' => $price]], 'barcodes' => [['code' => (string) mt_rand(100000000000, 999999999999)]]]]]);
            $out[] = ['variant' => $product->variants->first()->load('units'), 'cost' => $cost, 'stock' => $stock, 'max' => $max];
        }
        // The two seeded Tapal prices start "now"; back-date them in this training copy only.
        \App\Models\Price::query()->whereHas('variant', fn ($q) => $q->whereIn('sku', ['TAPAL-FM-50', 'TAPAL-FM-100']))->update(['effective_from' => now()]);
        foreach (ProductVariant::query()->whereIn('sku', ['TAPAL-FM-50', 'TAPAL-FM-100'])->with('units')->get() as $tapal) {
            $out[] = ['variant' => $tapal, 'cost' => $tapal->sku === 'TAPAL-FM-50' ? '43' : '87', 'stock' => 240, 'max' => 6];
        }

        return $out;
    }

    /** Busier around lunch and evening. */
    private function peak(float $x): float
    {
        return min(0.999, max(0, $x < 0.5 ? 0.35 * sqrt($x * 2) : 0.35 + 0.65 * (($x - 0.5) * 2) ** 0.7));
    }

    /** @return array<string, int> */
    private function notes(int $amount): array
    {
        $out = [];
        foreach ([5000, 1000, 500, 100, 50, 20, 10] as $note) {
            $n = intdiv($amount, $note);
            if ($n > 0) {
                $out[(string) $note] = $n;
                $amount -= $n * $note;
            }
        }

        return $out;
    }
}
