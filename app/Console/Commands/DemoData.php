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
 * Fills a separate TRAINING database with made-up trading so staff can
 * practise and the screens can be previewed. By default that is two days;
 * pass --days=60 (or more) to build enough history to try the forecasts.
 * It refuses to run unless the database name ends in "_demo", so it can
 * never put invented sales into the real books.
 */
class DemoData extends Command
{
    protected $signature = 'hassani:demo-data {--days=2 : Days of trading history to create (60-90 gives the forecasts something to learn from)}';

    protected $description = 'Training only: fill a *_demo database with sample trading';

    /** Average sales per day before the weekday and growth adjustments. */
    private const BASE_SALES = 28;

    /** Busier at the weekend (Carbon dayOfWeek: 0 = Sunday ... 6 = Saturday). */
    private const WEEKDAY = [0 => 1.20, 1 => 0.90, 2 => 0.85, 3 => 0.90, 4 => 1.00, 5 => 1.15, 6 => 1.30];

    /** Gentle growth in daily sales, per day of history (0.003 = +0.3% a day). */
    private const GROWTH = 0.003;

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_demo')) {
            $this->error("Refusing to run on \"{$database}\". Demo data is only allowed in a database whose name ends with _demo.");

            return self::FAILURE;
        }
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 365) {
            $this->error('--days must be a whole number between 1 and 365.');

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
        Carbon::setTestNow($today->subDays($days)->setTime(8, 0)->utc());
        $variants = $this->products();
        $totalWeight = (float) array_sum(array_column($variants, 'weight'));
        $floor = $store->defaultSellingLocation()->id;
        app(AdjustmentService::class)->create($owner, $store, 'opening', collect($variants)->map(fn ($v) => ['variant_id' => $v['variant']->id, 'location_id' => $floor, 'quantity' => (string) $this->openingStock($v, $days, $totalWeight), 'unit_cost' => $v['cost']])->values()->all(), null, 'Opening stock (training data)');

        $customers = collect([['Gulshan Kiryana Store', '0300-2211334', '50000'], ['Saima Bibi', '0321-8877665', '15000'], ['Al-Madina General Store', '0333-1122998', '80000']])
            ->map(fn ($c) => app(CustomerService::class)->save($owner, ['name' => $c[0], 'phone' => $c[1], 'area' => 'Gulshan-e-Iqbal', 'city' => 'Karachi', 'category' => 'wholesale', 'credit_limit' => $c[2], 'confirm_not_duplicate' => true]));
        // Over a long history the half-paid sales would blow past these limits, so
        // keep a running tally and let a customer pay in full once they are near theirs.
        $limits = $customers->mapWithKeys(fn ($c) => [$c->id => (float) $c->credit_limit])->all();
        $owed = array_map(fn () => 0.0, $limits);

        $register = Register::query()->where('store_id', $store->id)->firstOrFail();
        $methods = PaymentMethod::query()->pluck('id', 'code');

        $bar = $this->output->createProgressBar($days);
        $bar->start();

        for ($daysAgo = $days - 1; $daysAgo >= 0; $daysAgo--) {
            $day = $today->subDays($daysAgo);
            $endHour = $daysAgo === 0 ? $lastHour : 21;
            // Weekday pattern x slow growth x a little day-to-day noise; today is only part of a day.
            $trend = 1 + self::GROWTH * ($days - 1 - $daysAgo);
            $count = max(3, (int) round(self::BASE_SALES * self::WEEKDAY[$day->dayOfWeek] * $trend * (0.9 + mt_rand(0, 200) / 1000) * ($endHour - 9) / 12));
            // Sale times in order, so invoice numbers run in the same order as the clock.
            $minutes = collect(range(1, $count))
                ->map(fn () => (int) floor(($endHour - 9) * 60 * $this->peak(mt_rand(0, 1000) / 1000)))
                ->sort()
                ->values();

            Carbon::setTestNow($day->setTime(9, 0)->utc());
            $shift = app(ShiftService::class)->open($cashier, $register, ['1000' => 5, '500' => 4, '100' => 10]);
            $returnable = null;
            foreach ($minutes as $minute) {
                Carbon::setTestNow($day->setTime(9, 0)->addMinutes($minute)->utc());
                $lines = collect($this->pick($variants, mt_rand(1, 4)))->map(fn ($v) => ['variant_id' => $v['variant']->id, 'variant_unit_id' => $v['variant']->units->firstWhere('is_default_sale', true)->id, 'quantity' => (string) mt_rand(1, $v['max'])])->values()->all();
                $quote = app(CheckoutService::class)->quote($cashier, ['register_id' => $register->id, 'lines' => $lines]);
                $total = $quote['total'];
                $roll = mt_rand(1, 100);
                $customer = $roll > 88 ? $customers->get(mt_rand(0, $customers->count() - 1)) : null;
                $credit = $customer !== null ? (float) $total - floor((float) $total / 2) : 0.0;
                $onCredit = $customer !== null && $owed[$customer->id] + $credit <= $limits[$customer->id];
                $payments = match (true) {
                    $onCredit => [['payment_method_id' => $methods['cash'], 'amount' => (string) floor($total / 2)]],
                    $roll > 76 => [['payment_method_id' => $methods['easypaisa'], 'amount' => $total, 'reference' => 'EP'.mt_rand(10000000, 99999999)]],
                    $roll > 68 => [['payment_method_id' => $methods['jazzcash'], 'amount' => $total, 'reference' => 'JC'.mt_rand(10000000, 99999999)]],
                    $roll > 62 => [['payment_method_id' => $methods['card'], 'amount' => $total, 'reference' => (string) mt_rand(100000, 999999), 'terminal_label' => 'HBL POS']],
                    default => [['payment_method_id' => $methods['cash'], 'amount' => $total, 'tendered' => (string) (ceil($total / 500) * 500)]],
                };
                $invoice = app(CheckoutService::class)->checkout($cashier, ['idempotency_key' => (string) Str::uuid(), 'register_id' => $register->id, 'customer_id' => $customer?->id, 'lines' => $lines, 'payments' => $payments]);
                if ($onCredit) {
                    $owed[$customer->id] += $credit;
                }
                // Only fully paid sales are used for the sample return.
                if ($customer === null) {
                    $returnable ??= $invoice;
                }
            }
            // A sample return every day for the last two days (as before), then now and then.
            if ($returnable && ($daysAgo <= 1 || mt_rand(1, 100) <= 35)) {
                Carbon::setTestNow($day->setTime(min($endHour, 20), 30)->utc());
                app(CreditNoteService::class)->create($cashier, $returnable, ['lines' => [['invoice_line_id' => $returnable->lines->first()->id, 'quantity' => '1', 'disposition' => 'restock']], 'reason_code_id' => ReasonCode::query()->where('type', 'return')->value('id'), 'settlement' => 'refund', 'payment_method_id' => $methods['cash'], 'shift_id' => $shift->id, 'approval_token' => null]);
            }
            // Every day is closed except today, which stays open for the cashier to practise on.
            if ($daysAgo > 0) {
                Carbon::setTestNow($day->setTime(22, 10)->utc());
                $expected = app(\App\Domain\Pos\ShiftReport::class)->expectedCash($shift->fresh());
                // Yesterday is always a bit short to show the review flow; older days only now and then.
                $shortage = $daysAgo === 1 ? 120 : (mt_rand(1, 100) <= 30 ? mt_rand(1, 4) * 50 : 0);
                $counted = (int) floor((float) $expected) - $shortage;
                app(ShiftService::class)->close($cashier, $shift->fresh(), $this->notes($counted), 'Busy evening');
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        Carbon::setTestNow($today->setTime(min($lastHour, 20), 45)->utc());
        $count = app(CountService::class)->plan($owner, $store, 'products', ['variant_ids' => collect($variants)->take(4)->map(fn ($v) => $v['variant']->id)->values()->all()], true, false, null, 'Weekly spot check');
        app(CountService::class)->start($owner, $count);
        foreach ($count->fresh('lines')->lines as $index => $line) {
            app(CountService::class)->record($owner, $line, (string) ((float) $line->expected_quantity - ($index === 2 ? 2 : 0)));
        }
        app(CountService::class)->submit($owner, $count->fresh());
        app(CountService::class)->approveAndPost($owner, $count->fresh(), [$count->fresh('lines')->lines[2]->id => ReasonCode::query()->where('type', 'write_off')->where('code', 'missing')->value('id')]);
        Carbon::setTestNow();

        $this->info("Training data added: {$days} day(s) of trading. Sign in as owner@demo.test or cashier@demo.test with password \"demo-password-1\".");

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

    /** @return list<array{variant: ProductVariant, cost: string, stock: int, max: int, weight: float}> */
    private function products(): array
    {
        $pcs = Unit::query()->where('code', 'pcs')->value('id');
        $retail = PriceBook::query()->where('is_default', true)->value('id');
        $category = fn (string $name, ?string $ur) => ProductCategory::query()->firstOrCreate(['name' => $name], ['name_ur' => $ur, 'slug' => Str::slug($name)])->id;
        // The last number is how popular the item is: higher sells more often, so the
        // forecasts see fast and slow movers rather than every product behaving the same.
        $items = [
            ['Dalda Cooking Oil 1L', 'ڈالڈا کوکنگ آئل', 'Cooking oil', 'تیل', '590', '540', 120, 3, 1.4],
            ['Basmati Rice 5kg', 'باسمتی چاول', 'Rice & daal', 'چاول اور دال', '1850', '1690', 60, 2, 0.8],
            ['Lux Soap 98g', 'لکس صابن', 'Personal care', 'ذاتی نگہداشت', '140', '118', 200, 4, 1.6],
            ['Shan Biryani Masala', 'شان بریانی مصالحہ', 'Spices', 'مصالحے', '160', '132', 150, 3, 1.0],
            ['National Salt 800g', 'نیشنل نمک', 'Spices', 'مصالحے', '70', '55', 180, 3, 1.2],
            ['Nestle Milkpak 1L', 'نیسلے ملک پیک', 'Dairy', 'دودھ', '330', '298', 140, 4, 2.0],
            ['Daal Chana 1kg', 'دال چنا', 'Rice & daal', 'چاول اور دال', '420', '370', 90, 2, 0.7],
            ['Surf Excel 1kg', 'سرف ایکسل', 'Cleaning', 'صفائی', '650', '585', 80, 2, 0.6],
        ];
        $out = [];
        foreach ($items as [$name, $ur, $cat, $catUr, $price, $cost, $stock, $max, $weight]) {
            $product = app(ProductService::class)->save(['name' => $name, 'name_ur' => $ur, 'type' => 'stocked', 'category_id' => $category($cat, $catUr), 'is_online' => true,
                'variants' => [['name' => $name, 'name_ur' => $ur, 'base_unit_id' => $pcs, 'units' => [['unit_id' => $pcs, 'factor' => '1', 'is_default_sale' => true]], 'prices' => [['price_book_id' => $retail, 'unit_id' => $pcs, 'price' => $price]], 'barcodes' => [['code' => (string) mt_rand(100000000000, 999999999999)]]]]]);
            $out[] = ['variant' => $product->variants->first()->load('units'), 'cost' => $cost, 'stock' => $stock, 'max' => $max, 'weight' => $weight];
        }
        // The two seeded Tapal prices start "now"; back-date them in this training copy only.
        \App\Models\Price::query()->whereHas('variant', fn ($q) => $q->whereIn('sku', ['TAPAL-FM-50', 'TAPAL-FM-100']))->update(['effective_from' => now()]);
        foreach (ProductVariant::query()->whereIn('sku', ['TAPAL-FM-50', 'TAPAL-FM-100'])->with('units')->get() as $tapal) {
            $out[] = ['variant' => $tapal, 'cost' => $tapal->sku === 'TAPAL-FM-50' ? '43' : '87', 'stock' => 240, 'max' => 6, 'weight' => $tapal->sku === 'TAPAL-FM-50' ? 1.8 : 1.0];
        }

        return $out;
    }

    /**
     * Opening stock sized to the length of the history, so nothing runs out part-way
     * through: the old fixed amounts only lasted a few days.
     */
    private function openingStock(array $v, int $days, float $totalWeight): int
    {
        $perDay = self::BASE_SALES * 1.05 * (1 + self::GROWTH * $days / 2);
        $expectedUnits = $days * $perDay * 2.5 * ($v['weight'] / $totalWeight) * (1 + $v['max']) / 2;

        return (int) ceil($v['stock'] + $expectedUnits * 1.4);
    }

    /**
     * Picks $n different variants, favouring the faster sellers
     * (weighted random choice without replacement).
     *
     * @return list<array{variant: ProductVariant, cost: string, stock: int, max: int, weight: float}>
     */
    private function pick(array $variants, int $n): array
    {
        $keys = [];
        foreach ($variants as $i => $v) {
            $keys[$i] = -log(mt_rand(1, 1000000) / 1000000) / $v['weight'];
        }
        asort($keys);

        return array_map(fn ($i) => $variants[$i], array_slice(array_keys($keys), 0, $n));
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