<?php

namespace App\Console\Commands;

use App\Domain\Catalog\ProductService;
use App\Domain\Crm\CustomerService;
use App\Domain\Inventory\AdjustmentService;
use App\Domain\Inventory\CountService;
use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\ShiftReport;
use App\Domain\Pos\ShiftService;
use App\Domain\Sales\CreditNoteService;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Price;
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
 * practise and the screens can be previewed. By default that is 30 days;
 * pass --days=90 to build enough history to try the forecasts, and
 * --sales=100 for a busier shop. It refuses to run unless the database
 * name ends in "_demo", so it can never put invented sales into the real books.
 */
class DemoData extends Command
{
    protected $signature = 'hassani:demo-data
        {--days=30 : Days of trading history to create (60-90 gives the forecasts something to learn from)}
        {--sales=60 : Average sales per day before weekday and growth adjustments}';

    protected $description = 'Training only: fill a *_demo database with sample trading';

    /** Busier at the weekend (Carbon dayOfWeek: 0 = Sunday ... 6 = Saturday). */
    private const WEEKDAY = [0 => 1.20, 1 => 0.90, 2 => 0.85, 3 => 0.90, 4 => 1.00, 5 => 1.15, 6 => 1.30];

    /** Gentle growth in daily sales, per day of history (0.003 = +0.3% a day). */
    private const GROWTH = 0.003;

    /**
     * Everyone who gets a login. [name, email, role key, limited to the store?]
     * owner and cashier are required. Any other role key that does not exist in
     * your roles table is skipped with a warning, so edit the keys to match yours.
     */
    private const STAFF = [
        ['Ahmed Hassani', 'owner@demo.test', 'owner', false],
        ['Sara Hassani', 'manager@demo.test', 'store_manager', false],
        ['Imran Qureshi', 'accountant@demo.test', 'accountant', false],
        ['Nadeem Shah', 'inventory@demo.test', 'inventory_manager', true],
        ['Bilal Khan', 'cashier@demo.test', 'cashier', true],
        ['Hina Malik', 'cashier2@demo.test', 'cashier', true],
        ['Usman Ali', 'cashier3@demo.test', 'cashier', true],
        ['Kamran Siddiqui', 'agent1@demo.test', 'sales_agent', true],
        ['Faisal Raza', 'agent2@demo.test', 'sales_agent', true],
        ['Zeeshan Ahmed', 'agent3@demo.test', 'sales_agent', true],
        ['Rashid Mehmood', 'rider1@demo.test', 'rider', true],
        ['Tariq Hussain', 'rider2@demo.test', 'rider', true],
        ['Adnan Baloch', 'rider3@demo.test', 'rider', true],
        ['Farah Naz', 'hr@demo.test', 'hr_manager', false],
        ['Asif Jamal', 'warehouse1@demo.test', 'warehouse_worker', true],
        ['Kashif Noor', 'warehouse2@demo.test', 'warehouse_worker', true],
        ['Shumaila Iqbal', 'warehouse3@demo.test', 'warehouse_worker', true],
    ];

    /** [name, phone, category, credit limit, area] */
    private const CUSTOMERS = [
        ['Gulshan Kiryana Store', '0300-2211334', 'shopkeeper', '50000', 'Gulshan-e-Iqbal'],
        ['Al-Madina General Store', '0333-1122998', 'wholesale', '80000', 'Gulshan-e-Iqbal'],
        ['Saima Bibi', '0321-8877665', 'retail', '15000', 'Nazimabad'],
        ['Bismillah Karyana', '0302-5543210', 'shopkeeper', '40000', 'Nazimabad'],
        ['Raza Brothers Wholesale', '0345-7788991', 'wholesale', '150000', 'Saddar'],
        ['Madni Mart', '0311-4455667', 'shopkeeper', '35000', 'North Nazimabad'],
        ['Zainab Fatima', '0322-1234987', 'retail', '10000', 'Clifton'],
        ['Al-Noor Public School', '0333-9087612', 'institution', '120000', 'Korangi'],
        ['Karachi Grammar Canteen', '0300-6677889', 'institution', '90000', 'Clifton'],
        ['Hamdard Medical Centre', '0312-3344556', 'institution', '100000', 'Malir'],
        ['Mohammad Yousuf', '0346-5566778', 'retail', '8000', 'Landhi'],
        ['Rehmat Baker & Sweets', '0301-8899001', 'shopkeeper', '45000', 'Orangi Town'],
        ['Student Biryani Gulshan', '0333-4400112', 'institution', '60000', 'Gulshan-e-Iqbal'],
        ['Ayesha Siddiqa', '0323-2211009', 'retail', '12000', 'DHA'],
        ['Pak Traders', '0300-9988776', 'wholesale', '200000', 'Saddar'],
        ['Memon General Store', '0315-6677882', 'shopkeeper', '55000', 'Korangi'],
        ['Noor Mosque Committee', '0334-1020304', 'institution', '30000', 'Malir'],
        ['Tahir Mehmood', '0344-7788990', 'retail', '5000', 'Landhi'],
        ['Faisal Cash & Carry', '0305-3344998', 'wholesale', '175000', 'North Nazimabad'],
        ['Mehwish Khan', '0321-5566001', 'retail', '10000', 'Nazimabad'],
        ['Baloch Provision Store', '0302-1199880', 'shopkeeper', '38000', 'Lyari'],
        ['Sunrise Guest House', '0333-8800123', 'institution', '70000', 'Saddar'],
        ['Shaheen Departmental', '0312-9090123', 'shopkeeper', '65000', 'Gulshan-e-Iqbal'],
        ['Rukhsana Begum', '0345-1122334', 'retail', '7000', 'Orangi Town'],
    ];

    private int $baseSales = 60;

    public function handle(): int
    {
        $database = (string) config('database.connections.' . config('database.default') . '.database');
        if (! str_ends_with($database, '_demo')) {
            $this->error("Refusing to run on \"{$database}\". Demo data is only allowed in a database whose name ends with _demo.");

            return self::FAILURE;
        }
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 365) {
            $this->error('--days must be a whole number between 1 and 365.');

            return self::FAILURE;
        }
        $this->baseSales = (int) $this->option('sales');
        if ($this->baseSales < 1 || $this->baseSales > 500) {
            $this->error('--sales must be a whole number between 1 and 500.');

            return self::FAILURE;
        }
        $company = Company::query()->firstOrFail();
        CurrentCompany::set($company);
        $store = Store::query()->firstOrFail();
        mt_srand(42);

        // Staff: every role that exists gets its people; missing roles are reported, not fatal.
        $staff = [];
        $skipped = [];
        foreach (self::STAFF as [$name, $email, $role, $scoped]) {
            $user = $this->user($name, $email, $role, $scoped ? $store : null);
            if ($user) {
                $staff[$role][] = $user;
            } else {
                $skipped[$role] = true;
            }
        }
        $owner = $staff['owner'][0] ?? null;
        $cashiers = $staff['cashier'] ?? [];
        if (! $owner || ! $cashiers) {
            $this->error('The "owner" and "cashier" roles must exist before demo data can be created.');

            return self::FAILURE;
        }
        foreach (array_keys($skipped) as $role) {
            $this->warn("Role \"{$role}\" not found, so those demo users were skipped. Edit self::STAFF to match your role keys.");
        }
        Auth::setUser($owner);

        $tz = $store->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $lastHour = min(21, max(12, (int) CarbonImmutable::now($tz)->format('G')));
        // Prices and opening stock must exist before the first sample sale.
        Carbon::setTestNow($today->subDays($days)->setTime(8, 0)->utc());
        $variants = $this->products();
        $totalWeight = (float) array_sum(array_column($variants, 'weight'));
        $floor = $store->defaultSellingLocation()->id;
        app(AdjustmentService::class)->create($owner, $store, 'opening', collect($variants)->map(fn($v) => ['variant_id' => $v['variant']->id, 'location_id' => $floor, 'quantity' => (string) $this->openingStock($v, $days, $totalWeight), 'unit_cost' => $v['cost']])->values()->all(), null, 'Opening stock (training data)');

        $customers = collect(self::CUSTOMERS)->map(fn($c) => app(CustomerService::class)->save($owner, ['name' => $c[0], 'phone' => $c[1], 'area' => $c[4], 'city' => 'Karachi', 'category' => $c[2], 'credit_limit' => $c[3], 'confirm_not_duplicate' => true]));
        // Over a long history the part-paid sales would blow past these limits, so
        // keep a running tally and let a customer pay in full once they are near theirs.
        $limits = $customers->mapWithKeys(fn($c) => [$c->id => (float) $c->credit_limit])->all();
        $owed = array_map(fn() => 0.0, $limits);

        $register = Register::query()->where('store_id', $store->id)->firstOrFail();
        $methods = PaymentMethod::query()->pluck('id', 'code');
        $returnReason = ReasonCode::query()->where('type', 'return')->value('id');

        $bar = $this->output->createProgressBar($days);
        $bar->start();

        for ($daysAgo = $days - 1; $daysAgo >= 0; $daysAgo--) {
            $day = $today->subDays($daysAgo);
            $endHour = $daysAgo === 0 ? $lastHour : 21;
            // Cashiers take turns; today is always the first cashier (cashier@demo.test).
            $cashier = $cashiers[$daysAgo % count($cashiers)];
            // Weekday pattern x slow growth x a little day-to-day noise; today is only part of a day.
            $trend = 1 + self::GROWTH * ($days - 1 - $daysAgo);
            $count = max(3, (int) round($this->baseSales * self::WEEKDAY[$day->dayOfWeek] * $trend * (0.9 + mt_rand(0, 200) / 1000) * ($endHour - 9) / 12));
            // Sale times in order, so invoice numbers run in the same order as the clock.
            $minutes = collect(range(1, $count))
                ->map(fn() => (int) floor(($endHour - 9) * 60 * $this->peak(mt_rand(0, 1000) / 1000)))
                ->sort()
                ->values();

            Carbon::setTestNow($day->setTime(9, 0)->utc());
            $shift = app(ShiftService::class)->open($cashier, $register, ['1000' => 5, '500' => 4, '100' => 10]);
            $returnables = [];
            foreach ($minutes as $minute) {
                Carbon::setTestNow($day->setTime(9, 0)->addMinutes($minute)->utc());
                $lines = collect($this->pick($variants, mt_rand(1, 4)))->map(fn($v) => ['variant_id' => $v['variant']->id, 'variant_unit_id' => $v['variant']->units->firstWhere('is_default_sale', true)->id, 'quantity' => (string) mt_rand(1, $v['max'])])->values()->all();
                $quote = app(CheckoutService::class)->quote($cashier, ['register_id' => $register->id, 'lines' => $lines]);
                $total = $quote['total'];

                // About one sale in five is for a saved customer; they may buy part on credit.
                $customer = mt_rand(1, 100) > 80 ? $customers->get(mt_rand(0, $customers->count() - 1)) : null;
                $paidShare = [0.3, 0.5, 0.7][mt_rand(0, 2)];
                $paidNow = floor((float) $total * $paidShare);
                $credit = (float) $total - $paidNow;
                $onCredit = $customer !== null && $owed[$customer->id] + $credit <= $limits[$customer->id];

                $payRoll = mt_rand(1, 100);
                $half = floor((float) $total / 2);
                $payments = match (true) {
                    $onCredit => [['payment_method_id' => $methods['cash'], 'amount' => (string) $paidNow]],
                    // Split tender: half cash, the rest on a wallet.
                    $payRoll > 90 => [
                        ['payment_method_id' => $methods['cash'], 'amount' => (string) $half],
                        ['payment_method_id' => $methods['easypaisa'], 'amount' => (string) round((float) $total - $half, 2), 'reference' => 'EP' . mt_rand(10000000, 99999999)],
                    ],
                    $payRoll > 80 => [['payment_method_id' => $methods['easypaisa'], 'amount' => $total, 'reference' => 'EP' . mt_rand(10000000, 99999999)]],
                    $payRoll > 72 => [['payment_method_id' => $methods['jazzcash'], 'amount' => $total, 'reference' => 'JC' . mt_rand(10000000, 99999999)]],
                    $payRoll > 62 => [['payment_method_id' => $methods['card'], 'amount' => $total, 'reference' => (string) mt_rand(100000, 999999), 'terminal_label' => 'HBL POS']],
                    default => [['payment_method_id' => $methods['cash'], 'amount' => $total, 'tendered' => (string) (ceil($total / 500) * 500)]],
                };
                $payload = ['idempotency_key' => (string) Str::uuid(), 'register_id' => $register->id, 'customer_id' => $customer?->id, 'lines' => $lines, 'payments' => $payments];
                try {
                    $invoice = app(CheckoutService::class)->checkout($cashier, $payload);
                } catch (\Throwable $e) {
                    if (! str_contains(strtolower($e->getMessage()), 'approval')) {
                        throw $e;
                    }
                    // Remember what tripped it, then redo the sale as a plain cash walk-in sale.
                    $approvalFallbacks[] = ['day' => $day->toDateString(), 'customer' => $customer?->name, 'total' => $total, 'credit' => $credit, 'on_credit' => $onCredit, 'message' => $e->getMessage()];
                    $customer = null;
                    $onCredit = false;
                    $payload['idempotency_key'] = (string) Str::uuid();
                    $payload['customer_id'] = null;
                    $payload['payments'] = [['payment_method_id' => $methods['cash'], 'amount' => $total, 'tendered' => (string) (ceil($total / 500) * 500)]];
                    $invoice = app(CheckoutService::class)->checkout($cashier, $payload);
                }
                if ($onCredit) {
                    $owed[$customer->id] += $credit;
                }
                // Only fully paid walk-in sales are used for sample returns.
                if ($customer === null) {
                    $returnables[] = $invoice;
                }
            }
            // Sample returns: one a day for the last two days, then 0-3 on most other days.
            $returnCount = $daysAgo <= 1 ? 1 : (mt_rand(1, 100) <= 60 ? mt_rand(1, 3) : 0);
            $returnCount = min($returnCount, count($returnables));
            if ($returnCount > 0) {
                $picked = (array) array_rand($returnables, $returnCount);
                foreach (array_values($picked) as $n => $key) {
                    $returnable = $returnables[$key];
                    Carbon::setTestNow($day->setTime(min($endHour, 20), 30)->addMinutes($n * 3)->utc());
                    app(CreditNoteService::class)->create($cashier, $returnable, ['lines' => [['invoice_line_id' => $returnable->lines->first()->id, 'quantity' => '1', 'disposition' => 'restock']], 'reason_code_id' => $returnReason, 'settlement' => 'refund', 'payment_method_id' => $methods['cash'], 'shift_id' => $shift->id, 'approval_token' => null]);
                }
            }
            // Every day is closed except today, which stays open for the cashier to practise on.
            if ($daysAgo > 0) {
                Carbon::setTestNow($day->setTime(22, 10)->utc());
                $expected = app(ShiftReport::class)->expectedCash($shift->fresh());
                // Yesterday is always a bit short to show the review flow; other days are
                // sometimes short, sometimes over, mostly exact.
                $roll = mt_rand(1, 100);
                $variance = $daysAgo === 1 ? -120 : match (true) {
                    $roll <= 25 => -mt_rand(1, 4) * 50,
                    $roll <= 40 => mt_rand(1, 3) * 50,
                    default => 0,
                };
                $counted = (int) floor((float) $expected) + $variance;
                app(ShiftService::class)->close($cashier, $shift->fresh(), $this->notes($counted), 'Busy evening');

                // A stock count about once a week, rotating through the products.
                if ($daysAgo % 7 === 3) {
                    Carbon::setTestNow($day->setTime(22, 30)->utc());
                    $this->stockCount($owner, $store, $variants, intdiv($daysAgo, 7) * 6, mt_rand(1, 3));
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // Today's spot check, with one product counted short.
        Carbon::setTestNow($today->setTime(min($lastHour, 20), 45)->utc());
        $this->stockCount($owner, $store, $variants, 0, 2);
        Carbon::setTestNow();

        $this->info("Training data added: {$days} day(s) of trading, about {$this->baseSales} sales a day, " . count($variants) . ' products, ' . $customers->count() . ' customers, ' . collect($staff)->flatten()->count() . ' staff.');
        $this->info('Sign in with any email below and the password "demo-password-1" (PIN 2580):');
        foreach ($staff as $role => $users) {
            $this->line(sprintf('  %-12s %s', $role, collect($users)->pluck('email')->implode(', ')));
        }

        return self::SUCCESS;
    }

    /** Creates (or reuses) a demo user. Returns null when the role does not exist. */
    private function user(string $name, string $email, string $role, ?Store $store = null): ?User
    {
        $roleId = Role::query()->where('key', $role)->value('id');
        if (! $roleId) {
            return null;
        }
        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            return $existing;
        }
        $user = new User(['name' => $name, 'email' => $email, 'password' => 'demo-password-1']);
        $user->company_id = CurrentCompany::require();
        $user->status = 'active';
        $user->pin_hash = Hash::make('2580');
        $user->save();
        UserRole::create(['user_id' => $user->id, 'role_id' => $roleId, 'store_id' => $store?->id]);

        return $user;
    }

    /**
     * Plans, counts, submits and posts a six-product spot check. The third line
     * is counted short by $shortBy and written off as missing.
     */
    private function stockCount(User $owner, Store $store, array $variants, int $offset, int $shortBy): void
    {
        $total = count($variants);
        $ids = collect(range(0, 5))->map(fn($i) => $variants[($offset + $i) % $total]['variant']->id)->unique()->values()->all();
        $count = app(CountService::class)->plan($owner, $store, 'products', ['variant_ids' => $ids], true, false, null, 'Weekly spot check');
        app(CountService::class)->start($owner, $count);
        foreach ($count->fresh('lines')->lines as $index => $line) {
            app(CountService::class)->record($owner, $line, (string) ((float) $line->expected_quantity - ($index === 2 ? $shortBy : 0)));
        }
        app(CountService::class)->submit($owner, $count->fresh());
        $fresh = $count->fresh('lines');
        app(CountService::class)->approveAndPost($owner, $fresh, [$fresh->lines[2]->id => ReasonCode::query()->where('type', 'write_off')->where('code', 'missing')->value('id')]);
    }

    /** @return list<array{variant: ProductVariant, cost: string, stock: int, max: int, weight: float}> */
    private function products(): array
    {
        $pcs = Unit::query()->where('code', 'pcs')->value('id');
        $retail = PriceBook::query()->where('is_default', true)->value('id');
        $category = fn(string $name, ?string $ur) => ProductCategory::query()->firstOrCreate(['name' => $name], ['name_ur' => $ur, 'slug' => Str::slug($name)])->id;
        // The last number is how popular the item is: higher sells more often, so the
        // forecasts see fast and slow movers rather than every product behaving the same.
        $items = [
            ['Dalda Cooking Oil 1L', 'ڈالڈا کوکنگ آئل', 'Cooking oil', 'تیل', '590', '540', 120, 3, 1.4],
            ['Dalda Cooking Oil 5L', 'ڈالڈا کوکنگ آئل 5 لیٹر', 'Cooking oil', 'تیل', '2850', '2640', 40, 2, 0.5],
            ['Habib Cooking Oil 1L', 'حبیب کوکنگ آئل', 'Cooking oil', 'تیل', '610', '560', 100, 3, 1.0],
            ['Basmati Rice 5kg', 'باسمتی چاول', 'Rice & daal', 'چاول اور دال', '1850', '1690', 60, 2, 0.8],
            ['Super Kernel Basmati 1kg', 'سپر کرنل باسمتی', 'Rice & daal', 'چاول اور دال', '390', '350', 100, 4, 1.1],
            ['Daal Chana 1kg', 'دال چنا', 'Rice & daal', 'چاول اور دال', '420', '370', 90, 2, 0.7],
            ['Daal Masoor 1kg', 'دال مسور', 'Rice & daal', 'چاول اور دال', '380', '340', 90, 2, 0.7],
            ['Daal Moong 1kg', 'دال مونگ', 'Rice & daal', 'چاول اور دال', '440', '395', 80, 2, 0.6],
            ['Sugar 1kg', 'چینی', 'Staples', 'اشیائے خوردونوش', '155', '142', 300, 5, 2.2],
            ['Atta 10kg', 'آٹا', 'Staples', 'اشیائے خوردونوش', '1550', '1420', 50, 2, 0.9],
            ['Eggs (dozen)', 'انڈے درجن', 'Staples', 'اشیائے خوردونوش', '380', '340', 80, 3, 1.3],
            ['Lux Soap 98g', 'لکس صابن', 'Personal care', 'ذاتی نگہداشت', '140', '118', 200, 4, 1.6],
            ['Dettol Soap 100g', 'ڈیٹول صابن', 'Personal care', 'ذاتی نگہداشت', '150', '128', 160, 4, 1.2],
            ['Head & Shoulders 185ml', 'ہیڈ اینڈ شولڈرز', 'Personal care', 'ذاتی نگہداشت', '520', '465', 60, 2, 0.5],
            ['Sunsilk Shampoo 160ml', 'سن سلک شیمپو', 'Personal care', 'ذاتی نگہداشت', '430', '385', 70, 2, 0.6],
            ['Colgate Toothpaste 100g', 'کولگیٹ ٹوتھ پیسٹ', 'Personal care', 'ذاتی نگہداشت', '230', '200', 120, 3, 1.0],
            ['Shan Biryani Masala', 'شان بریانی مصالحہ', 'Spices', 'مصالحے', '160', '132', 150, 3, 1.0],
            ['Shan Chaat Masala 100g', 'شان چاٹ مصالحہ', 'Spices', 'مصالحے', '120', '98', 120, 3, 0.7],
            ['National Salt 800g', 'نیشنل نمک', 'Spices', 'مصالحے', '70', '55', 180, 3, 1.2],
            ['National Red Chilli 200g', 'نیشنل لال مرچ', 'Spices', 'مصالحے', '310', '270', 90, 2, 0.8],
            ['Nestle Milkpak 1L', 'نیسلے ملک پیک', 'Dairy', 'دودھ', '330', '298', 140, 4, 2.0],
            ['Olpers Milk 1L', 'اولپرز دودھ', 'Dairy', 'دودھ', '320', '290', 140, 4, 1.8],
            ['Nurpur Butter 200g', 'نورپور مکھن', 'Dairy', 'دودھ', '480', '430', 50, 2, 0.4],
            ['Surf Excel 1kg', 'سرف ایکسل', 'Cleaning', 'صفائی', '650', '585', 80, 2, 0.6],
            ['Ariel Powder 1kg', 'ایریل پاؤڈر', 'Cleaning', 'صفائی', '700', '630', 70, 2, 0.5],
            ['Harpic 500ml', 'ہارپک', 'Cleaning', 'صفائی', '380', '335', 60, 2, 0.4],
            ['Vim Dishwash Liquid 500ml', 'وم ڈش واش', 'Cleaning', 'صفائی', '310', '270', 70, 2, 0.5],
            ['Pepsi 1.5L', 'پیپسی', 'Drinks', 'مشروبات', '190', '165', 160, 4, 1.5],
            ['Coca-Cola 1.5L', 'کوکا کولا', 'Drinks', 'مشروبات', '190', '165', 160, 4, 1.5],
            ['Nestle Water 1.5L', 'نیسلے پانی', 'Drinks', 'مشروبات', '90', '72', 200, 6, 1.8],
            ['Lays Masala 70g', 'لیز مصالحہ', 'Snacks', 'اسنیکس', '70', '57', 200, 5, 1.4],
            ['Peek Freans Gluco Biscuits', 'پیک فرینز گلوکو', 'Snacks', 'اسنیکس', '50', '41', 220, 6, 1.3],
        ];
        $out = [];
        foreach ($items as $i => [$name, $ur, $cat, $catUr, $price, $cost, $stock, $max, $weight]) {
            $product = app(ProductService::class)->save([
                'name' => $name,
                'name_ur' => $ur,
                'type' => 'stocked',
                'category_id' => $category($cat, $catUr),
                'is_online' => true,
                'variants' => [['name' => $name, 'name_ur' => $ur, 'base_unit_id' => $pcs, 'units' => [['unit_id' => $pcs, 'factor' => '1', 'is_default_sale' => true]], 'prices' => [['price_book_id' => $retail, 'unit_id' => $pcs, 'price' => $price]], 'barcodes' => [['code' => (string) (600000000000 + $i)]]]]
            ]);
            $out[] = ['variant' => $product->variants->first()->load('units'), 'cost' => $cost, 'stock' => $stock, 'max' => $max, 'weight' => $weight];
        }
        // The two seeded Tapal prices start "now"; back-date them in this training copy only.
        Price::query()->whereHas('variant', fn($q) => $q->whereIn('sku', ['TAPAL-FM-50', 'TAPAL-FM-100']))->update(['effective_from' => now()]);
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
        $perDay = $this->baseSales * 1.05 * (1 + self::GROWTH * $days / 2);
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

        return array_map(fn($i) => $variants[$i], array_slice(array_keys($keys), 0, $n));
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
