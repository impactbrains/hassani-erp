<?php

namespace App\Console\Commands;

use App\Domain\Accounting\JournalPoster;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Catalog\PriceRuleService;
use App\Domain\Catalog\ProductImporter;
use App\Domain\Crm\CustomerService;
use App\Domain\Hr\AdvanceService;
use App\Domain\Hr\AttendanceService;
use App\Domain\Hr\EmployeeService;
use App\Domain\Hr\LeaveService;
use App\Domain\Hr\PayrollService;
use App\Domain\Identity\UserAccountService;
use App\Domain\Inventory\AdjustmentService;
use App\Domain\Inventory\CountService;
use App\Domain\Inventory\TransferService;
use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\ShiftReport;
use App\Domain\Pos\ShiftService;
use App\Domain\Purchasing\PayablesService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\ReceivingService;
use App\Domain\Sales\CollectionService;
use App\Domain\Sales\CreditNoteService;
use App\Domain\Sales\PaymentVerificationService;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Holiday;
use App\Models\JournalLine;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\PriceBook;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ReasonCode;
use App\Models\Register;
use App\Models\ReplenishmentPolicy;
use App\Models\Role;
use App\Models\SalaryStructure;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\Store;
use App\Models\SupplierBill;
use App\Models\SupplierProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Models\WorkShift;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Training / demo history for a shop: a product list, suppliers, customers and staff, then one
 * simulated day after another (shifts, sales, purchases, returns, collections, counts, attendance,
 * payroll). Everything goes through the same services the screens use, with the clock moved back
 * one day at a time, so stock, accounts, Khata and payroll all agree. Nothing is inserted straight
 * into a table, except settings-like lists (holidays, reorder levels) that have no service.
 *
 * Only runs on a database whose name ends in _demo.
 *
 *   php artisan migrate --seed
 *   php artisan hassani:demo-data                 (180 days, about 85 sales a day: allow 15-30 minutes)
 *   php artisan hassani:demo-data --days=90 --sales=40
 *   php artisan hassani:demo-data --fresh         (rebuilds the _demo database first)
 *
 * Logins (password demo-password-1, approval PIN 2580): owner@demo.test, manager@demo.test,
 * cashier@demo.test, cashier2@demo.test, cashier3@demo.test, inventory@demo.test, accountant@demo.test,
 * hr@demo.test, agent@demo.test, rider@demo.test, rider2@demo.test, warehouse@demo.test, warehouse2@demo.test.
 */
class DemoData extends Command
{
    protected $signature = 'hassani:demo-data
        {--days=180 : Days of history to create}
        {--sales=85 : Average sales per day at the start (grows over the period)}
        {--csv= : Product CSV (default: kiryana-products-import-v2.csv in database/seeders, the project folder or storage/app)}
        {--seed=2026 : Random seed: the same number gives the same history}
        {--fresh : Rebuild the _demo database first (migrate:fresh --seed)}';

    protected $description = 'Fill a *_demo database with months of realistic shop history for training (refuses any other database)';

    /** Sales grow by this share over the whole period. */
    private const GROWTH = 0.25;

    private const PASSWORD = 'demo-password-1';

    private const PIN = '2580';

    private const TZ = 'Asia/Karachi';

    /** Share of sales (weight) per category, spread over its products. */
    private const CATEGORY_WEIGHT = [
        'Flour & Grains' => 3.0, 'Sugar & Salt' => 3.2, 'Cooking Oil & Ghee' => 2.8, 'Dairy & Eggs' => 3.2, 'Bread & Bakery' => 2.4,
        'Tea & Whiteners' => 2.6, 'Soft Drinks & Juices' => 2.6, 'Water' => 1.6, 'Biscuits & Cakes' => 2.4, 'Snacks & Chips' => 2.2,
        'Spices' => 1.8, 'Masala Mixes' => 1.6, 'Pulses & Lentils' => 1.9, 'Laundry & Cleaning' => 1.6, 'Soaps & Bath' => 1.3,
        'Hair & Oral Care' => 1.2, 'Pickles Sauces & Jams' => 0.9, 'Chocolates & Candy' => 1.5, 'Feminine & Baby Care' => 0.8,
        'Household Essentials' => 0.8, 'Noodles & Instant Food' => 1.0, 'Dry Fruits' => 0.5, 'Tissue & Paper' => 0.6, 'Tea' => 1.0,
    ];

    /** Category => supplier index (see SUPPLIERS). */
    private const CATEGORY_SUPPLIER = [
        'Flour & Grains' => 0, 'Pulses & Lentils' => 0, 'Sugar & Salt' => 0, 'Dry Fruits' => 0,
        'Cooking Oil & Ghee' => 1,
        'Spices' => 2, 'Masala Mixes' => 2, 'Pickles Sauces & Jams' => 2,
        'Dairy & Eggs' => 3,
        'Soft Drinks & Juices' => 4, 'Water' => 4,
        'Biscuits & Cakes' => 5, 'Chocolates & Candy' => 5, 'Snacks & Chips' => 5, 'Noodles & Instant Food' => 5,
        'Bread & Bakery' => 6,
        'Tea & Whiteners' => 7, 'Tea' => 7,
        'Laundry & Cleaning' => 8, 'Soaps & Bath' => 8, 'Hair & Oral Care' => 8, 'Feminine & Baby Care' => 8, 'Household Essentials' => 8, 'Tissue & Paper' => 8,
    ];

    /** name, name_ur, phone, area, lead days, payment terms days, order weekdays (1 = Monday ... 7 = Sunday), pays on delivery */
    private const SUPPLIERS = [
        ['Al-Rehman Flour & Grain Traders', 'الرحمان فلور اینڈ گرین ٹریڈرز', '0321-2210045', 'Jodia Bazaar', 2, 14, [1, 4], false],
        ['Karachi Oil & Ghee Agency', 'کراچی آئل اینڈ گھی ایجنسی', '0333-4410982', 'Jodia Bazaar', 2, 21, [2, 5], false],
        ['Sultan Masala House', 'سلطان مصالحہ ہاؤس', '0345-8820731', 'Empress Market', 2, 14, [3], false],
        ['Cold Chain Dairy Supplies', 'کولڈ چین ڈیری سپلائیز', '0300-2209918', 'Korangi Industrial Area', 1, 3, [1, 2, 3, 4, 5, 6], false],
        ['Metro Beverages Distributor', 'میٹرو بیوریجز ڈسٹری بیوٹر', '0312-7730021', 'SITE Area', 1, 7, [2, 5], false],
        ['Sweet Treats Confectionery Distributors', 'سویٹ ٹریٹس کنفیکشنری', '0322-5540617', 'Saddar', 2, 14, [1, 3, 6], false],
        ['FreshBake Bakery Supply', 'فریش بیک بیکری سپلائی', '0334-9981204', 'Nazimabad', 1, 0, [1, 2, 3, 4, 5, 6, 7], true],
        ['Tea & Whitener Wholesalers', 'ٹی اینڈ وائٹنر ہول سیلرز', '0301-6650882', 'Jodia Bazaar', 2, 14, [3, 6], false],
        ['Clean Home FMCG Distribution', 'کلین ہوم ایف ایم سی جی', '0315-3340679', 'Gulshan-e-Iqbal', 2, 21, [2, 6], false],
    ];

    /** [date, name, urdu, type, shop closed] for 2026 and 2027 (religious dates follow the moon: approximate). */
    private const HOLIDAYS = [
        ['2026-02-05', 'Kashmir Solidarity Day', 'یوم یکجہتی کشمیر', 'public', false],
        ['2026-03-20', 'Eid ul Fitr', 'عید الفطر', 'religious', true],
        ['2026-03-21', 'Eid ul Fitr (2nd day)', 'عید الفطر', 'religious', true],
        ['2026-03-23', 'Pakistan Day', 'یوم پاکستان', 'public', false],
        ['2026-05-01', 'Labour Day', 'یوم مزدور', 'public', false],
        ['2026-05-27', 'Eid ul Adha', 'عید الاضحی', 'religious', true],
        ['2026-05-28', 'Eid ul Adha (2nd day)', 'عید الاضحی', 'religious', true],
        ['2026-05-29', 'Eid ul Adha (3rd day)', 'عید الاضحی', 'religious', false],
        ['2026-06-26', 'Ashura (10 Muharram)', 'عاشورہ', 'religious', true],
        ['2026-08-14', 'Independence Day', 'یوم آزادی', 'public', false],
        ['2026-08-26', 'Eid Milad un Nabi', 'عید میلاد النبی', 'religious', false],
        ['2026-11-09', 'Iqbal Day', 'یوم اقبال', 'public', false],
        ['2026-12-25', 'Quaid-e-Azam Day', 'یوم قائد اعظم', 'public', false],
        ['2027-03-10', 'Eid ul Fitr', 'عید الفطر', 'religious', true],
        ['2027-03-11', 'Eid ul Fitr (2nd day)', 'عید الفطر', 'religious', true],
        ['2027-05-17', 'Eid ul Adha', 'عید الاضحی', 'religious', true],
        ['2027-05-18', 'Eid ul Adha (2nd day)', 'عید الاضحی', 'religious', true],
        ['2027-06-15', 'Ashura (10 Muharram)', 'عاشورہ', 'religious', true],
    ];

    /** Dates before which shoppers stock up (Eid) and the start of Ramadan. */
    private const EID_DATES = ['2026-03-20', '2026-05-27', '2027-03-10', '2027-05-17'];

    private const RAMADAN_RANGES = [['2026-02-19', '2026-03-19'], ['2027-02-09', '2027-03-09']];

    private const MALE = ['Ahmed', 'Ali', 'Bilal', 'Hamza', 'Hassan', 'Imran', 'Irfan', 'Junaid', 'Khalid', 'Faisal', 'Farhan', 'Fahad', 'Naveed', 'Omar', 'Rashid', 'Saad', 'Salman', 'Shahid', 'Tariq', 'Usman', 'Waqas', 'Yasir', 'Zeeshan', 'Asif', 'Adnan', 'Danish', 'Haider', 'Jawad', 'Kashif', 'Nadeem', 'Rizwan', 'Sohail', 'Tahir', 'Waseem'];

    private const FEMALE = ['Ayesha', 'Fatima', 'Hina', 'Iqra', 'Kiran', 'Mariam', 'Nida', 'Rabia', 'Sana', 'Sadia', 'Uzma', 'Zainab', 'Bushra', 'Farah', 'Huma', 'Nazia', 'Rukhsana', 'Samina', 'Shazia'];

    private const SURNAMES = ['Khan', 'Ahmed', 'Ali', 'Hussain', 'Malik', 'Sheikh', 'Qureshi', 'Siddiqui', 'Ansari', 'Memon', 'Baig', 'Chaudhry', 'Farooqui', 'Hashmi', 'Rizvi', 'Zaidi', 'Butt', 'Mirza', 'Pathan', 'Shah', 'Iqbal', 'Raza', 'Akhtar', 'Javed', 'Nawaz'];

    private const AREAS = ['Gulshan-e-Iqbal', 'Nazimabad', 'North Nazimabad', 'Gulistan-e-Jauhar', 'Korangi', 'Landhi', 'Malir', 'Saddar', 'PECHS', 'Federal B Area', 'Liaquatabad', 'Orangi Town', 'Lyari', 'Shah Faisal Colony', 'Johar Mor', 'Gulberg', 'Azizabad', 'Defence'];

    private const SHOPS = ['Madina General Store', 'Al-Noor Kiryana', 'Bismillah Mart', 'Faisal Departmental Store', 'Karachi Fresh Mart', 'Rehmat Karyana', 'New Star General Store', 'Zam Zam Traders', 'Sultan Kiryana House', 'Gul Bahar Store', 'Makkah Super Store', 'Taj Mart', 'Ali Brothers Karyana', 'Hira Departmental', 'Shaheen General Store', 'Noor Mart', 'Usmania Store', 'Baloch Kiryana', 'Pak Madina Mart', 'Al-Habib Traders', 'Metro Karyana', 'Rizwan Cash & Carry'];

    // ---- run state ----------------------------------------------------------------------------

    private int $days = 180;

    private int $baseSales = 85;

    private CarbonImmutable $start;

    private Company $company;

    private Store $store;

    private Register $register;

    private Location $floor;

    private Location $godown;

    private User $owner;

    /** @var array<string, User> logins by key (manager, cashier, cashier2, ...) */
    private array $users = [];

    /** @var array<string, Contact> employee records by the same keys */
    private array $employees = [];

    /** @var array<string, array<string, mixed>> staff definitions by key */
    private array $staffDefs = [];

    /** @var array<string, int> role ids by key */
    private array $roles = [];

    /** @var array<string, PaymentMethod> */
    private array $methods = [];

    /** @var array<string, array<string, int>> reason code ids by type then code */
    private array $reasons = [];

    /** @var array<int, array<string, mixed>> sellable catalogue by variant id */
    private array $catalog = [];

    /** @var list<int> */
    private array $catalogIds = [];

    /** @var list<float> cumulative selling weights, same order as catalogIds */
    private array $cumulative = [];

    /** @var array<int, float> sellable base units on hand, refreshed each morning and kept up to date during the day */
    private array $stock = [];

    /** @var array<int, float> base units on order and not yet delivered */
    private array $incoming = [];

    /** @var array<int, float> recent daily demand in base units (moving average) */
    private array $rate = [];

    /** @var array<int, array<string, mixed>> supplier contacts and settings by index */
    private array $suppliers = [];

    /** @var list<array<string, mixed>> */
    private array $customers = [];

    /** @var array<string, list<array<string, mixed>>> purchase orders arriving on a date */
    private array $deliveries = [];

    /** @var array<string, array<string, mixed>> */
    private array $csv = [];

    private float $costIndex = 1.0;

    /** @var array<string, array{count: int, samples: list<string>}> */
    private array $problems = [];

    /** @var array<string, int|float> */
    private array $totals = ['days' => 0, 'closed' => 0, 'sales' => 0, 'revenue' => 0.0, 'returns' => 0, 'collections' => 0, 'purchases' => 0, 'orders' => 0, 'payments' => 0, 'writeoffs' => 0, 'counts' => 0, 'transfers' => 0, 'payrolls' => 0];

    /** @var array<string, array<string, int|float>> */
    private array $months = [];

    // ---- entry point --------------------------------------------------------------------------

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (! str_ends_with($database, '_demo')) {
            $this->error("Refusing to run: the database is \"{$database}\", and its name does not end in _demo.");
            $this->line('Set DB_DATABASE to a name such as hassani_demo, then run php artisan migrate --seed first.');

            return self::FAILURE;
        }
        $this->days = max(3, min(730, (int) $this->option('days')));
        $this->baseSales = max(3, min(600, (int) $this->option('sales')));
        mt_srand((int) $this->option('seed'));
        $csv = $this->locateCsv();
        if ($csv === null) {
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->info('Rebuilding the demo database...');
            Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        }
        $company = Company::query()->orderBy('id')->first();
        if ($company === null) {
            Artisan::call('db:seed', ['--force' => true]);
            $company = Company::query()->orderBy('id')->first();
        }
        if ($company === null) {
            $this->error('There is no company yet. Run php artisan migrate --seed first.');

            return self::FAILURE;
        }
        $this->company = $company;
        CurrentCompany::set($company);
        if (User::query()->exists()) {
            $this->error('This demo database already has users. Rebuild it with  php artisan hassani:demo-data --fresh  (or migrate:fresh --seed).');

            return self::FAILURE;
        }

        $started = microtime(true);
        $this->start = CarbonImmutable::now(self::TZ)->startOfDay()->subDays($this->days);
        $this->info("Creating {$this->days} days of history from {$this->start->toDateString()} ({$this->baseSales} sales a day to begin with).");

        try {
            $this->setup($csv);
            $this->simulate();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
        $this->finish($started);

        return self::SUCCESS;
    }

    // ---- setup --------------------------------------------------------------------------------

    private function setup(string $csv): void
    {
        $this->at($this->start->subDay()->toDateString(), '07:30');
        $this->store = Store::query()->orderBy('id')->firstOrFail();
        $this->register = Register::query()->where('store_id', $this->store->id)->orderBy('id')->firstOrFail();
        $this->floor = $this->store->defaultSellingLocation();
        $this->godown = Location::query()->where('store_id', $this->store->id)->where('type', 'godown')->firstOrFail();
        $this->roles = Role::query()->pluck('id', 'key')->all();
        $this->methods = PaymentMethod::query()->get()->keyBy('code')->all();
        foreach (ReasonCode::query()->get() as $reason) {
            $this->reasons[$reason->type][$reason->code] = $reason->id;
        }

        $this->task('Owner, staff and logins', fn () => $this->createStaff());
        $this->as($this->owner);
        Settings::set('pricing.rounding_step', '1');
        $this->task('Holidays', fn () => $this->createHolidays());
        $this->task('Products from the CSV', fn () => $this->importProducts($csv));
        $this->task('Suppliers', fn () => $this->createSuppliers());
        $this->task('Customers (Khata and wholesale shopkeepers)', fn () => $this->createCustomers());
        $this->task('Price rules', fn () => $this->createPriceRules());
        $this->task('Owner capital and the first stock', fn () => $this->openingBalances());
    }

    /** Runs a set-up step. A failure here stops the run, because everything after depends on it. */
    private function task(string $label, callable $step): void
    {
        $this->output->write("  {$label} ... ");
        $step();
        $this->output->writeln('<info>done</info>');
    }

    private function createStaff(): void
    {
        $owner = new User(['name' => 'Imran Hassani', 'email' => 'owner@demo.test', 'password' => self::PASSWORD, 'locale' => 'en']);
        $owner->company_id = $this->company->id;
        $owner->status = User::STATUS_ACTIVE;
        $owner->pin_hash = Hash::make(self::PIN);
        $owner->save();
        UserRole::create(['user_id' => $owner->id, 'role_id' => $this->roles['owner'], 'store_id' => null, 'reason' => 'Demo owner']);
        $this->owner = $owner;
        $this->as($owner);

        // key => [name, email, role, role_type, job title, salary, shift, global role?, phone]
        $this->staffDefs = [
            'manager' => ['Tariq Mehmood', 'manager@demo.test', 'store_manager', 'manager', 'Store manager', 90000, 'Morning', false],
            'cashier' => ['Bilal Ahmed', 'cashier@demo.test', 'cashier', 'cashier', 'Cashier', 42000, 'Morning', false],
            'cashier2' => ['Sana Iqbal', 'cashier2@demo.test', 'cashier', 'cashier', 'Cashier', 42000, 'Evening', false],
            'cashier3' => ['Usman Ghani', 'cashier3@demo.test', 'cashier', 'cashier', 'Cashier (relief)', 40000, 'Rotating', false],
            'inventory' => ['Farhan Siddiqui', 'inventory@demo.test', 'inventory_manager', 'supervisor', 'Inventory manager', 62000, 'Morning', false],
            'accountant' => ['Nadia Hussain', 'accountant@demo.test', 'accountant', 'admin', 'Accountant', 68000, 'Morning', true],
            'hr' => ['Saima Rashid', 'hr@demo.test', 'hr_manager', 'admin', 'HR officer', 60000, 'Morning', true],
            'agent' => ['Kamran Ali', 'agent@demo.test', 'sales_agent', 'sales_agent', 'Sales agent (wholesale)', 48000, 'Morning', false],
            'rider' => ['Shahid Mehmood', 'rider@demo.test', 'rider', 'rider', 'Delivery rider', 34000, 'Evening', false],
            'rider2' => ['Waqar Hussain', 'rider2@demo.test', 'rider', 'rider', 'Delivery rider', 34000, 'Morning', false],
            'warehouse' => ['Ghulam Abbas', 'warehouse@demo.test', 'warehouse_worker', 'warehouse', 'Godown keeper', 33000, 'Morning', false],
            'warehouse2' => ['Imtiaz Ali', 'warehouse2@demo.test', 'warehouse_worker', 'warehouse', 'Godown helper', 30000, 'Evening', false],
        ];
        $employees = app(EmployeeService::class);
        $accounts = app(UserAccountService::class);
        $department = ['manager' => 'Administration', 'cashier' => 'Sales floor', 'supervisor' => 'Warehouse', 'admin' => 'Accounts', 'sales_agent' => 'Sales floor', 'rider' => 'Delivery', 'warehouse' => 'Warehouse'];
        foreach ($this->staffDefs as $key => [$name, $email, $role, $roleType, $title, $salary, $shift, $global]) {
            $joined = $this->start->subDays(mt_rand(150, 1500));
            $employee = $employees->save($this->owner, [
                'name' => $name, 'phone' => $this->phone(), 'store_id' => $this->store->id, 'role_type' => $roleType, 'job_title' => $title,
                'employment_type' => 'permanent', 'joining_date' => $joined->toDateString(), 'gender' => in_array($name, ['Sana Iqbal', 'Nadia Hussain', 'Saima Rashid'], true) ? 'female' : 'male',
                'department_id' => \App\Models\Department::query()->where('name', $department[$roleType])->value('id'),
            ]);
            $employees->transition($this->owner, $employee, 'confirm', $joined->addDays(90)->toDateString(), 'Confirmed after probation');
            SalaryStructure::create([
                'contact_id' => $employee->id, 'effective_from' => $joined->toDateString(), 'pay_basis' => 'monthly', 'base_amount' => $salary,
                'overtime_hourly_rate' => round($salary / 240 * 1.5, 2), 'components' => in_array($roleType, ['rider', 'sales_agent'], true) ? [['code' => 'FUEL', 'name' => 'Fuel allowance', 'type' => 'allowance', 'amount' => 4000]] : [],
                'eobi_employee' => 370, 'eobi_employer' => 1850, 'created_by' => $this->owner->id, 'approved_by' => $this->owner->id,
            ]);
            $created = $accounts->create($this->owner, ['name' => $name, 'email' => $email, 'employee_id' => $employee->id, 'temporary_password' => self::PASSWORD], [
                ['role_id' => $this->roles[$role], 'store_id' => $global ? null : $this->store->id],
            ]);
            $user = $created['user'];
            $user->forceFill(['must_change_password' => false, 'pin_hash' => Hash::make(self::PIN)])->save();
            $this->users[$key] = $user;
            $this->employees[$key] = $employee;
        }
    }

    private function createHolidays(): void
    {
        $first = $this->start->subDays(1)->toDateString();
        foreach (self::HOLIDAYS as [$date, $name, $urdu, $type, $closed]) {
            if ($date >= $first) {
                Holiday::query()->firstOrCreate(['date' => $date, 'store_id' => null], ['name' => $name, 'name_ur' => $urdu, 'type' => $closed ? 'store_closure' : $type, 'is_paid' => true, 'created_by' => $this->owner->id]);
            }
        }
    }

    private function locateCsv(): ?string
    {
        $given = (string) $this->option('csv');
        $name = 'kiryana-products-import-v2.csv';
        $candidates = $given !== '' ? [$given] : [base_path($name), database_path('seeders/'.$name), database_path($name), storage_path('app/'.$name), storage_path('app/private/'.$name)];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        $this->error('The product CSV was not found. Tried:');
        foreach ($candidates as $path) {
            $this->line('  '.$path);
        }
        $this->line('Copy kiryana-products-import-v2.csv into the project folder, or pass  --csv=C:\\path\\to\\file.csv');

        return null;
    }

    private function importProducts(string $path): void
    {
        $result = app(ProductImporter::class)->import($path, $this->owner, $this->store, true);
        if ($result['errors'] !== []) {
            $this->newLine();
            foreach (array_slice($result['errors'], 0, 5) as $error) {
                $this->warn('    '.$error);
            }
        }
        // The CSV is the price source of truth: keep its numbers to plan purchases.
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), (array) fgetcsv($handle));
        while (($cells = fgetcsv($handle)) !== false) {
            if (count($cells) < count($header)) {
                continue;
            }
            $row = array_combine($header, array_slice($cells, 0, count($header)));
            if (! empty($row['sku'])) {
                $this->csv[$row['sku']] = $row;
            }
        }
        fclose($handle);

        // The two Tapal products from the seeder start "now"; back-date their prices to the start of the history.
        Price::query()->where('effective_from', '>', $this->start->subDay()->endOfDay())->update(['effective_from' => $this->start->subDays(30)]);

        // Perishables are tracked by batch and expiry.
        $perishable = ProductCategory::query()->whereIn('name', ['Dairy & Eggs', 'Bread & Bakery'])->pluck('id');
        foreach (Product::query()->whereIn('category_id', $perishable)->get() as $product) {
            $shelf = match (true) {
                (bool) preg_match('/bread|bun|naan|paratha|rusk/i', $product->name) && ! preg_match('/rusk/i', $product->name) => 4,
                (bool) preg_match('/cake|rusk|cookie/i', $product->name) => 45,
                (bool) preg_match('/egg/i', $product->name) => 14,
                (bool) preg_match('/yogh?urt|dahi|lassi|raita|cream|paneer/i', $product->name) => 10,
                (bool) preg_match('/milk/i', $product->name) => 75,
                default => 120,
            };
            $product->update(['track_batches' => true, 'track_expiry' => true, 'shelf_life_days' => $shelf]);
        }

        $this->buildCatalog();
        $this->setReplenishmentPolicies();
    }

    private function buildCatalog(): void
    {
        $variants = ProductVariant::query()->with(['product.category', 'units.unit'])->where('is_active', true)->get();
        $categoryCount = [];
        foreach ($variants as $variant) {
            $category = $variant->product->category?->name ?? 'Other';
            $categoryCount[$category] = ($categoryCount[$category] ?? 0) + 1;
        }
        $categoryTotal = array_sum(array_map(fn ($c) => self::CATEGORY_WEIGHT[$c] ?? 1.0, array_keys($categoryCount)));
        foreach ($variants as $variant) {
            $base = $variant->units->firstWhere('is_base', true);
            if ($base === null || ! $variant->product->isStocked()) {
                continue;
            }
            $pack = $variant->units->first(fn ($u) => ! $u->is_base && $u->can_sell && $u->can_purchase);
            $row = $this->csv[$variant->sku] ?? null;
            $category = $variant->product->category?->name ?? 'Other';
            $price = $row ? (float) $row['in_store_price'] : (float) ($variant->prices()->value('price') ?? 0);
            $cost = $row && $row['purchase_price'] !== '' ? (float) $row['purchase_price'] : ($variant->sku === 'TAPAL-FM-50' ? 43.0 : ($variant->sku === 'TAPAL-FM-100' ? 87.0 : (float) $variant->purchase_price));
            if ($price <= 0 || $cost <= 0) {
                continue;
            }
            $share = (self::CATEGORY_WEIGHT[$category] ?? 1.0) / $categoryTotal / $categoryCount[$category];
            $weight = $share * exp($this->gauss() * 0.85) / sqrt(max($price, 30) / 100);
            $this->catalog[$variant->id] = [
                'id' => $variant->id, 'sku' => $variant->sku, 'name' => $variant->name, 'category' => $category,
                'unit' => $base->unit->code, 'fraction' => (bool) $base->unit->allows_fraction,
                'base_vu' => $base->id, 'pack_vu' => $pack?->id, 'pack' => $pack ? (float) $pack->factor : 1.0, 'pack_code' => $pack?->unit->code,
                'price' => $price, 'cost' => $cost, 'weight' => $weight,
                'supplier' => self::CATEGORY_SUPPLIER[$category] ?? 8,
                'tracked' => (bool) $variant->product->track_expiry, 'shelf' => (int) ($variant->product->shelf_life_days ?: 120),
                'daily' => 0.0,
            ];
        }
        $this->catalogIds = array_keys($this->catalog);
        $weightTotal = array_sum(array_column($this->catalog, 'weight'));
        $running = 0.0;
        foreach ($this->catalogIds as $id) {
            $running += $this->catalog[$id]['weight'];
            $this->cumulative[] = $running;
            // Expected base units a day: lines a day x this product's share x a typical quantity (wholesale buys more).
            $typical = $this->catalog[$id]['fraction'] ? 1.9 : 1.6;
            $this->catalog[$id]['daily'] = $this->baseSales * 4.6 * ($this->catalog[$id]['weight'] / $weightTotal) * $typical * 1.35;
            $this->rate[$id] = $this->catalog[$id]['daily'];
            $this->stock[$id] = 0.0;
            $this->incoming[$id] = 0.0;
        }
    }

    private function setReplenishmentPolicies(): void
    {
        foreach ($this->catalog as $v) {
            $supplier = $this->suppliers[$v['supplier']]['id'] ?? null;
            ReplenishmentPolicy::query()->updateOrCreate(['store_id' => $this->store->id, 'variant_id' => $v['id']], [
                'min_quantity' => round($v['daily'] * 3, 3), 'max_quantity' => round($v['daily'] * 14, 3), 'reorder_point' => round($v['daily'] * 5, 3),
                'safety_stock' => round($v['daily'] * 1.5, 3), 'preferred_supplier_id' => $supplier, 'lead_time_days' => self::SUPPLIERS[$v['supplier']][4],
                'order_multiple' => $v['pack'], 'is_active' => true,
            ]);
        }
    }

    private function createSuppliers(): void
    {
        foreach (self::SUPPLIERS as $i => [$name, $urdu, $phone, $area, $lead, $terms, $weekdays, $cod]) {
            $supplier = new Contact(['type' => 'supplier', 'created_by' => $this->owner->id]);
            $supplier->fill(['name' => $name, 'name_ur' => $urdu, 'phone' => $phone, 'area' => $area, 'city' => 'Karachi', 'address' => $area.', Karachi', 'is_active' => true]);
            $supplier->code = Sequences::next('supplier');
            $supplier->save();
            SupplierProfile::query()->updateOrCreate(['contact_id' => $supplier->id], ['lead_time_days' => $lead, 'payment_terms_days' => $terms, 'min_order_value' => $cod ? 0 : 25000]);
            $this->suppliers[$i] = ['id' => $supplier->id, 'contact' => $supplier, 'lead' => $lead, 'terms' => $terms, 'days' => $weekdays, 'cod' => $cod];
        }
        $this->setReplenishmentPolicies();
    }

    private function createCustomers(): void
    {
        $service = app(CustomerService::class);
        $wholesaleBook = PriceBook::query()->where('code', 'WHOLESALE')->value('id');
        $agent = $this->employees['agent']->id;
        $make = function (string $name, string $category, int $limit, int $terms, ?int $book = null, ?int $agentId = null) use ($service): void {
            $customer = $service->save($this->owner, [
                'name' => $name, 'phone' => $this->phone(), 'area' => self::AREAS[array_rand(self::AREAS)], 'city' => 'Karachi', 'category' => $category,
                'price_book_id' => $book, 'sales_agent_id' => $agentId, 'credit_limit' => (string) $limit, 'payment_terms_days' => $terms, 'confirm_not_duplicate' => true,
                'marketing_consent' => $this->chance(0.4),
            ]);
            $this->customers[] = ['id' => $customer->id, 'name' => $name, 'kind' => $category === 'retail' ? ($limit > 0 ? 'khata' : 'regular') : 'shop', 'limit' => $limit, 'owed' => 0.0, 'terms' => $terms, 'lastPaid' => null, 'payer' => $this->chance(0.5) ? 'cash' : 'bank_transfer'];
        };
        for ($i = 0; $i < 34; $i++) {      // Khata families
            $make($this->personName(), 'retail', [3000, 5000, 8000, 10000, 15000, 25000][array_rand([0, 1, 2, 3, 4, 5])], [15, 30, 30, 30][array_rand([0, 1, 2, 3])]);
        }
        for ($i = 0; $i < 14; $i++) {      // cash regulars with a name on file
            $make($this->personName(), 'retail', 0, 0);
        }
        foreach (array_slice(self::SHOPS, 0, 19) as $i => $shop) {   // wholesale shopkeepers
            $make($shop, $i % 7 === 0 ? 'wholesale' : 'shopkeeper', [100000, 150000, 200000, 300000, 400000][array_rand([0, 1, 2, 3, 4])], [14, 21, 30][array_rand([0, 1, 2])], $wholesaleBook, $agent);
        }
        foreach (['Sunrise Public School (canteen)', 'Burger Point Cafe', 'Al-Shifa Medical Centre (mess)'] as $institution) {
            $make($institution, 'institution', 150000, 30, null, $agent);
        }
    }

    private function createPriceRules(): void
    {
        $rules = app(PriceRuleService::class);
        // 6 or more of one item at the counter: the wholesale price.
        $rules->save(['scope' => 'all', 'name' => 'Buy 6 or more: wholesale price', 'action' => 'wholesale', 'conditions' => [['type' => 'quantity', 'op' => 'gte', 'value' => '6']]]);

        // A dated promotion: 4% off soft drinks for a fortnight in the middle of the history.
        $drinks = ProductCategory::query()->where('name', 'Soft Drinks & Juices')->value('id');
        $from = $this->start->addDays((int) ($this->days * 0.45));
        if ($drinks) {
            $rules->save(['scope' => 'category', 'category_id' => $drinks, 'name' => 'Summer drinks offer', 'action' => 'percent_off', 'value' => '4', 'conditions' => [['type' => 'date', 'from' => $from->toDateString(), 'to' => $from->addDays(14)->toDateString()]]]);
        }
        // Eid week: cooking oil a little cheaper in the run-up (only when an Eid falls in the history).
        $oil = ProductCategory::query()->where('name', 'Cooking Oil & Ghee')->value('id');
        foreach (self::EID_DATES as $eid) {
            $day = CarbonImmutable::parse($eid);
            if ($oil && $day->gt($this->start->addDays(10)) && $day->lt($this->start->addDays($this->days))) {
                $rules->save(['scope' => 'category', 'category_id' => $oil, 'name' => 'Eid shopping offer', 'action' => 'percent_off', 'value' => '2', 'conditions' => [['type' => 'date', 'from' => $day->subDays(8)->toDateString(), 'to' => $day->subDays(1)->toDateString()]]]);
            }
        }
        // A fixed carton price on six popular items that come in cartons.
        $done = 0;
        $byWeight = $this->catalog;
        uasort($byWeight, fn ($a, $b) => $b['weight'] <=> $a['weight']);
        foreach ($byWeight as $v) {
            if ($done >= 6) {
                break;
            }
            $row = $this->csv[$v['sku']] ?? null;
            if ($v['pack_vu'] === null || $v['pack_code'] !== 'ctn' || ! $row || $row['pack_in_store_price'] === '') {
                continue;
            }
            $variant = ProductVariant::query()->with('units')->find($v['id']);
            $ctn = $variant->units->firstWhere('id', $v['pack_vu']);
            $fixed = floor((float) $row['pack_in_store_price'] * 0.985);
            $rules->save(['scope' => 'variant', 'variant_id' => $v['id'], 'name' => 'Carton price', 'action' => 'fixed', 'value' => (string) $fixed, 'unit_id' => $ctn->unit_id,
                'conditions' => [['type' => 'pack', 'unit_ids' => [$ctn->unit_id]]]], null, $variant);
            $done++;
        }
    }

    /** The owner puts money in, then the first stock is bought: part paid now, part on credit. */
    private function openingBalances(): void
    {
        $plan = [];
        $total = 0.0;
        $paidNow = 0.0;
        foreach ($this->catalog as $id => $v) {
            $units = max($v['daily'] * 14, $v['pack'] > 1 ? $v['pack'] * 0.5 : 6);
            $packs = $v['pack'] > 1 ? max(1, (int) ceil($units / $v['pack'])) : max(1, (int) ceil($units));
            $plan[$v['supplier']][$id] = $packs;
            $total += $packs * ($v['pack'] > 1 ? $v['pack'] : 1) * $v['cost'];
        }
        $paidShare = [0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 1.0, 4 => 0.0, 5 => 0.0, 6 => 1.0, 7 => 1.0, 8 => 0.0];
        foreach ($plan as $supplier => $lines) {
            foreach ($lines as $id => $packs) {
                $paidNow += $paidShare[$supplier] * $packs * ($this->catalog[$id]['pack'] > 1 ? $this->catalog[$id]['pack'] : 1) * $this->catalog[$id]['cost'];
            }
        }
        $capital = (int) (ceil(($paidNow * 1.1 + 1500000) / 500000) * 500000);
        $this->as($this->owner);
        $this->journal('capital', 'Owner capital introduced', [['account' => 'bank', 'debit' => $this->money($capital)], ['account' => 'owner_capital', 'credit' => $this->money($capital + 200000)]], extra: [['account' => 'cash_on_hand', 'debit' => '200000.00']]);

        $inventory = $this->users['inventory'];
        foreach ($plan as $supplier => $lines) {
            $this->at($this->start->subDay()->toDateString(), '10:'.str_pad((string) (10 + $supplier * 4), 2, '0', STR_PAD_LEFT));
            $this->purchase($supplier, $lines, $inventory, immediate: true, settlement: $paidShare[$supplier] > 0 ? 'paid_now' : 'on_credit', method: $this->methods['bank_transfer'], note: 'Opening stock of the shop');
        }
        $this->refreshStock($this->start->subDay()->toDateString());
    }

    // ---- the simulation -----------------------------------------------------------------------

    private function simulate(): void
    {
        $bar = $this->output->createProgressBar($this->days);
        $bar->setFormat(' %current%/%max% days [%bar%] %percent:3s%% %elapsed:6s% / ~%estimated:-6s%  %message%');
        $bar->setMessage('');
        $bar->start();
        for ($i = 0; $i < $this->days; $i++) {
            $date = $this->start->addDays($i);
            $key = $date->format('Y-m');
            $this->months[$key] ??= ['sales' => 0, 'revenue' => 0.0];
            $this->day($date, $i);
            $bar->setMessage($date->toDateString().'  sales '.$this->totals['sales']);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
    }

    private function day(CarbonImmutable $date, int $index): void
    {
        $d = $date->toDateString();
        $this->totals['days']++;
        $this->at($d, '08:00');
        $this->as($this->owner);

        // First of the month: pay last month's salaries, then the monthly bills.
        if ((int) $date->format('j') === 1 && $index > 0) {
            $this->attempt('payroll', fn () => $this->payroll($date->subMonth()->startOfMonth()));
        }
        $this->attempt('refresh stock', fn () => $this->refreshStock($d));

        $closed = $this->isClosed($d);
        if (! $closed) {
            $this->attempt('expired stock', fn () => $this->writeOffExpired($d));
            $this->attempt('deliveries', fn () => $this->receiveDeliveries($d));
            $this->attempt('buying', fn () => $this->buy($d));
        }
        $this->attempt('hr plans', fn () => $this->hrPlans($date, $index));

        if ($closed) {
            $this->totals['closed']++;
        } else {
            $present = $this->attendance($d, $index);
            $this->trade($date, $index, $present);
        }

        $this->at($d, '23:30');
        $this->as($this->owner);
        $dow = (int) $date->format('N');
        if (! $closed && $dow === 1) {
            $this->attempt('supplier payments', fn () => $this->paySuppliers($d));
        }
        if (! $closed && $dow === 6) {
            $this->attempt('banking', fn () => $this->banking($d));
            $this->attempt('verify payments', fn () => $this->verifyPayments($d));
            $this->attempt('godown to shop', fn () => $this->restockShop($d));
        }
        if ((int) $date->format('j') === (int) min(2 + (crc32($date->format('Y-m')) % 5), 6)) {
            $this->attempt('monthly bills', fn () => $this->monthlyBills($d));
        }
        if ((int) $date->format('j') === 27 || ($index === $this->days - 1 && $this->days > 20)) {
            $this->attempt('stock count', fn () => $this->stockCount($d, $index));
        }
        $this->attempt('damages', fn () => $this->damages($d));
        $this->updateRates();
    }

    private function isClosed(string $date): bool
    {
        foreach (self::HOLIDAYS as [$day, , , , $closed]) {
            if ($closed && $day === $date) {
                return true;
            }
        }

        return false;
    }

    private function volumeFactor(CarbonImmutable $date): float
    {
        $d = $date->toDateString();
        $factor = [1 => 0.92, 2 => 0.9, 3 => 0.95, 4 => 1.0, 5 => 1.18, 6 => 1.28, 7 => 1.08][(int) $date->format('N')];
        $dom = (int) $date->format('j');
        $factor *= $dom <= 7 ? 1.22 : ($dom >= 25 ? 0.88 : 1.0);   // salary week and the lean end of the month
        foreach (self::RAMADAN_RANGES as [$from, $to]) {
            if ($d >= $from && $d <= $to) {
                $factor *= 1.12;
            }
        }
        foreach (self::EID_DATES as $eid) {
            $gap = (int) CarbonImmutable::parse($d)->diffInDays(CarbonImmutable::parse($eid), false);
            if ($gap > 0 && $gap <= 7) {
                $factor *= 1.5;
            } elseif ($gap === -2 || $gap === -3) {
                $factor *= 0.65;
            }
        }
        foreach (self::HOLIDAYS as [$day, , , , $closed]) {
            if ($day === $d && ! $closed) {
                $factor *= 0.8;
            }
        }

        return $factor;
    }

    // ---- staff: attendance, leave, advances, bonuses, payroll ---------------------------------

    /** @return list<string> keys of the staff who are in today */
    private function attendance(string $date, int $index): array
    {
        $hr = $this->users['hr'];
        $this->at($date, '08:30');
        $this->as($hr);
        $attendance = app(AttendanceService::class);
        $shifts = WorkShift::query()->pluck('id', 'name');
        $week = (int) CarbonImmutable::parse($date)->format('W');
        $dow = (int) CarbonImmutable::parse($date)->format('N');
        $present = [];
        $keys = array_keys($this->employees);
        foreach ($keys as $n => $key) {
            // Everyone has one weekly day off, spread over the week so that tills are always covered.
            $off = ($n % 6) + 1;
            if ($dow === $off && ! in_array($key, ['cashier', 'cashier2'], true)) {
                continue;
            }
            if (in_array($key, ['cashier', 'cashier2'], true) && $dow === ($key === 'cashier' ? 7 : 3)) {
                continue;
            }
            $shiftName = $this->staffDefs[$key][6] === 'Rotating' ? ($week % 2 === 0 ? 'Morning' : 'Evening') : $this->staffDefs[$key][6];
            $employee = $this->employees[$key];
            $absent = $this->chance(0.025);
            $ok = $this->attempt('roster', fn () => $attendance->assignShift($hr, $employee, $shifts[$shiftName], $date));
            if ($ok === null) {
                continue;   // on approved leave
            }
            if ($absent) {
                continue;   // no punch: marked absent below
            }
            $start = $shiftName === 'Morning' ? 9 * 60 : 15 * 60;
            $late = $this->chance(0.12) ? mt_rand(20, 70) : mt_rand(-12, 8);
            $in = CarbonImmutable::parse($date, self::TZ)->addMinutes($start + $late);
            $out = $in->addMinutes(8 * 60 + 30 + mt_rand(-20, 70) + ($this->chance(0.15) ? mt_rand(40, 120) : 0));
            $this->attempt('clock in', function () use ($attendance, $hr, $employee, $in, $out) {
                $attendance->clockIn($hr, $employee, $in);
                $attendance->clockOut($hr, $employee, $out);
            });
            $present[] = $key.'|'.$shiftName;
        }
        $attendance->markAbsences($date);

        return $present;
    }

    private function hrPlans(CarbonImmutable $date, int $index): void
    {
        $hr = $this->users['hr'];
        $dom = (int) $date->format('j');
        $this->as($hr);
        // Leave: a request a few days ahead, approved by the owner. The roster skips those days later.
        if ($this->chance(0.07)) {
            $key = array_rand(array_diff_key($this->employees, ['cashier' => 1, 'cashier2' => 1]));
            $from = $date->addDays(mt_rand(2, 6));
            $type = LeaveType::query()->where('code', $this->chance(0.55) ? 'CASUAL' : ($this->chance(0.7) ? 'SICK' : 'ANNUAL'))->first();
            $leave = $this->attempt('leave request', fn () => app(LeaveService::class)->request($hr, $this->employees[$key], $type->id, $from->toDateString(), $from->addDays(mt_rand(0, 2))->toDateString(), false, ['Family function', 'Not well', 'Travelling to hometown', 'Personal work'][array_rand([0, 1, 2, 3])]));
            if ($leave) {
                $this->as($this->owner);
                $this->attempt('leave review', fn () => app(LeaveService::class)->review($this->owner, $leave, ! $this->chance(0.1), null));
                $this->as($hr);
            }
        }
        // Peshgi (salary advance): the HR officer asks, the owner approves and pays.
        if ($dom === 12 && $this->chance(0.7)) {
            $key = array_rand(array_diff_key($this->employees, ['manager' => 1]));
            $advances = app(AdvanceService::class);
            $advance = $this->attempt('peshgi request', fn () => $advances->request($hr, $this->employees[$key], (string) [10000, 15000, 20000, 25000, 30000][array_rand([0, 1, 2, 3, 4])], mt_rand(2, 5), $date->addMonth()->startOfMonth()->toDateString(), ['Medical expense', 'School fees', 'Family wedding', 'House repair'][array_rand([0, 1, 2, 3])]));
            if ($advance) {
                $this->as($this->owner);
                $this->attempt('peshgi', function () use ($advances, $advance) {
                    $advances->review($this->owner, $advance, true);
                    $advances->disburse($this->owner, $advance->fresh(), $this->methods['cash']->id);
                });
            }
        }
        // Eid bonus: half a month's basic salary, paid with that month's salary.
        foreach (self::EID_DATES as $eid) {
            if ($date->toDateString() === CarbonImmutable::parse($eid)->subDays(12)->toDateString()) {
                $this->bonuses($hr, $date, CarbonImmutable::parse($eid)->startOfMonth());
            }
        }
        $this->as($this->owner);
    }

    private function bonuses(User $hr, CarbonImmutable $date, CarbonImmutable $month): void
    {
        $advances = app(AdvanceService::class);
        foreach ($this->employees as $key => $employee) {
            $this->attempt('bonus', function () use ($advances, $hr, $employee, $key, $month) {
                $adjustment = $advances->requestAdjustment($hr, ['contact_id' => $employee->id, 'type' => 'bonus', 'category' => 'eid_bonus', 'amount' => (string) ($this->staffDefs[$key][5] / 2), 'payroll_month' => $month->toDateString(), 'reason' => 'Eid bonus']);
                $advances->reviewAdjustment($this->owner, $adjustment, true);
            });
        }
    }

    private function payroll(CarbonImmutable $month): void
    {
        $payroll = app(PayrollService::class);
        $run = $payroll->prepare($this->users['hr'], $month->toDateString());
        $payroll->approve($this->owner, $run);
        $payroll->post($this->owner, $run->fresh());
        $this->ensureBank((float) $run->fresh()->net_total + 50000);
        $payroll->markPaid($this->owner, $run->fresh(), $this->methods['bank_transfer']->id, 'IBFT payroll '.$month->format('M Y'));
        $this->totals['payrolls']++;
    }

    // ---- trading day --------------------------------------------------------------------------

    /** @param list<string> $present "key|Shift" of the staff who are in */
    private function trade(CarbonImmutable $date, int $index, array $present): void
    {
        $d = $date->toDateString();
        $progress = $index / max(1, $this->days);
        $count = (int) round($this->baseSales * (1 + self::GROWTH * $progress) * $this->volumeFactor($date) * (0.9 + mt_rand(0, 20) / 100));
        $count = max(2, $count);

        // Who runs the till: someone on the morning roster, someone on the evening roster, else the manager.
        $till = ['Morning' => [], 'Evening' => []];
        foreach ($present as $entry) {
            [$key, $shift] = explode('|', $entry);
            if (in_array($key, ['cashier', 'cashier2', 'cashier3'], true)) {
                $till[$shift][] = $key;
            }
        }
        $morning = $this->users[$till['Morning'][0] ?? 'manager'];
        $evening = $this->users[$till['Evening'][0] ?? ($till['Morning'][1] ?? 'manager')];

        // Sale times: busier at lunch and in the evening (09:10 - 22:30); the shifts change over at 16:00.
        $minutes = [];
        for ($n = 0; $n < $count; $n++) {
            $minutes[] = (int) (9 * 60 + 10 + $this->peak(mt_rand(0, 9999) / 10000) * (13 * 60 + 20));
        }
        sort($minutes);
        $morningSales = array_values(array_filter($minutes, fn ($m) => $m < 16 * 60));
        $eveningSales = array_values(array_filter($minutes, fn ($m) => $m >= 16 * 60));

        $invoices = [];
        $this->runShift($d, $morning, $morningSales, '09:05', '16:00', $invoices, false);
        $this->runShift($d, $evening, $eveningSales, '16:00', '22:35', $invoices, true);
    }

    /**
     * One cashier's shift: open with a float, sell, take returns and collections, drop and pay out, close with a count.
     *
     * @param  list<int>  $times  minutes after midnight
     * @param  list<array<string, mixed>>  $invoices  this day's sales, for returns
     */
    private function runShift(string $date, User $cashier, array $times, string $open, string $close, array &$invoices, bool $collects): void
    {
        $this->at($date, $open);
        $this->as($cashier);
        $shifts = app(ShiftService::class);
        $shift = $this->attempt('open shift', fn () => $shifts->open($cashier, $this->register, ['1000' => 3, '500' => 2, '100' => 10]));
        if ($shift === null) {
            return;
        }
        // Things that happen between sales: [minute, kind].
        $events = [];
        for ($n = mt_rand(1, 3); $n > 0; $n--) {
            $events[] = [mt_rand(strtotime('10:00') ? 10 * 60 : 600, 21 * 60), 'payout'];
        }
        if ($collects) {
            for ($n = mt_rand(3, 8); $n > 0; $n--) {
                $events[] = [mt_rand(16 * 60 + 20, 21 * 60 + 30), 'collection'];
            }
        }
        for ($n = (int) (count($times) * 0.015 + (mt_rand(0, 99) < 60 ? 1 : 0)); $n > 0; $n--) {
            $events[] = [mt_rand(10 * 60, 21 * 60), 'return'];
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0]);

        foreach ($times as $i => $minute) {
            while ($events !== [] && $events[0][0] <= $minute) {
                [$at, $kind] = array_shift($events);
                $this->event($date, $at, $kind, $cashier, $shift, $invoices);
            }
            $this->at($date, sprintf('%02d:%02d:%02d', intdiv($minute, 60), $minute % 60, mt_rand(0, 59)));
            $this->as($cashier);
            $invoice = $this->attempt('sale', fn () => $this->sell($cashier, $shift));
            if ($invoice) {
                $invoices[] = ['invoice' => $invoice, 'shift' => $shift];
            }
            if ($i % 30 === 29) {
                $this->attempt('cash drop', fn () => $this->dropCash($cashier, $shift, 30000));
            }
        }
        foreach ($events as [$at, $kind]) {
            $this->event($date, min($at, 22 * 60 + 20), $kind, $cashier, $shift, $invoices);
        }

        $this->at($date, $close);
        $this->as($cashier);
        $this->attempt('cash drop', fn () => $this->dropCash($cashier, $shift, 10000));
        $this->attempt('close shift', fn () => $this->closeShift($cashier, $shift));
    }

    private function event(string $date, int $minute, string $kind, User $cashier, Shift $shift, array &$invoices): void
    {
        $this->at($date, sprintf('%02d:%02d:30', intdiv($minute, 60), $minute % 60));
        $this->as($cashier);
        match ($kind) {
            'payout' => $this->attempt('payout', fn () => $this->payout($cashier, $shift)),
            'collection' => $this->attempt('collection', fn () => $this->collect($cashier, $shift)),
            'return' => $this->attempt('return', fn () => $this->giveReturn($cashier, $shift, $invoices)),
            default => null,
        };
    }

    // ---- selling ------------------------------------------------------------------------------

    private function sell(User $cashier, Shift $shift): ?\App\Models\SalesInvoice
    {
        $roll = mt_rand(1, 100);
        $kind = match (true) {
            $roll <= 62 => 'walkin',
            $roll <= 72 => 'regular',
            $roll <= 91 => 'khata',
            default => 'shop',
        };
        $customer = $kind === 'walkin' ? null : $this->chooseCustomer($kind);
        if ($customer === null && $kind !== 'walkin') {
            $kind = 'walkin';
        }
        $lines = $this->cart($kind);
        if ($lines === []) {
            return null;
        }
        $checkout = app(CheckoutService::class);
        $base = ['register_id' => $this->register->id, 'customer_id' => $customer['id'] ?? null, 'lines' => array_map(fn ($l) => ['variant_id' => $l['variant_id'], 'variant_unit_id' => $l['variant_unit_id'], 'quantity' => $l['quantity']], $lines)];
        $total = (float) $checkout->quote($cashier, $base)['total'];
        if ($total <= 0) {
            return null;
        }

        $credit = 0.0;
        if ($customer && $customer['limit'] > 0 && ! $this->chance($kind === 'shop' ? 0.2 : 0.15)) {
            // Khata: the customer pays part now and the rest goes on the account.
            $credit = round($total * (mt_rand(40, 100) / 100), 0);
            if ($kind === 'khata') {
                $credit = min($credit, max(0, $customer['limit'] * 0.9 - $customer['owed']));
            }
        }
        $payments = $this->payments($total - $credit);
        $input = $base + ['idempotency_key' => (string) Str::uuid(), 'payments' => $payments];
        if ($credit > 0 && $customer['owed'] + $credit > $customer['limit']) {
            // Over the limit: a manager PIN is needed (most of the time the customer pays in full instead).
            if ($this->chance(0.35)) {
                $input['credit_approval_token'] = $this->approve($cashier, $this->users['manager'], 'credit_limit', $this->store->id);
            } else {
                $credit = 0.0;
                $input['payments'] = $this->payments($total);
            }
        }
        try {
            $invoice = $checkout->checkout($cashier, $input);
        } catch (BusinessRuleException $e) {
            if ($e->errorCode === 'approval_required' && $credit > 0) {
                $input['payments'] = $this->payments($total);
                $invoice = $checkout->checkout($cashier, ['idempotency_key' => (string) Str::uuid()] + $input);
                $credit = 0.0;
            } else {
                throw $e;
            }
        }
        foreach ($lines as $l) {
            $this->stock[$l['variant_id']] = ($this->stock[$l['variant_id']] ?? 0) - $l['base'];
            $this->sold[$l['variant_id']] = ($this->sold[$l['variant_id']] ?? 0) + $l['base'];
        }
        if ($customer) {
            $key = array_search($customer['id'], array_column($this->customers, 'id'), true);
            $this->customers[$key]['owed'] += (float) $invoice->credit_extended;
        }
        $this->totals['sales']++;
        $this->totals['revenue'] += (float) $invoice->total;
        $month = CarbonImmutable::now(self::TZ)->format('Y-m');
        $this->months[$month]['sales']++;
        $this->months[$month]['revenue'] += (float) $invoice->total;

        return $invoice;
    }

    /** @var array<int, float> base units sold today, folded into the moving demand average each night */
    private array $sold = [];

    /** @return array<string, mixed>|null */
    private function chooseCustomer(string $kind): ?array
    {
        $want = $kind === 'khata' ? ['khata'] : ($kind === 'shop' ? ['shop'] : ['regular']);
        $pool = array_values(array_filter($this->customers, fn ($c) => in_array($c['kind'], $want, true)));
        if ($pool === []) {
            return null;
        }

        return $pool[array_rand($pool)];
    }

    /** @return list<array{variant_id: int, variant_unit_id: int, quantity: string, base: float}> */
    private function cart(string $kind): array
    {
        $lines = match ($kind) {
            'shop' => mt_rand(5, 16),
            'khata' => mt_rand(3, 9),
            'regular' => mt_rand(2, 7),
            default => [1, 1, 2, 2, 2, 3, 3, 4, 4, 5, 6, 8][array_rand([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11])],
        };
        $out = [];
        $used = [];
        for ($n = 0, $tries = 0; $n < $lines && $tries < $lines * 6; $tries++) {
            $v = $this->catalog[$this->weightedPick()];
            if (isset($used[$v['id']]) || ($this->stock[$v['id']] ?? 0) < 1) {
                continue;
            }
            $unit = $v['base_vu'];
            if ($kind === 'shop') {
                $usePack = $v['pack_vu'] !== null && $this->chance(0.55);
                $qty = $usePack ? mt_rand(1, 3) : ($v['fraction'] ? mt_rand(10, 60) / 2 : mt_rand(6, 30));
                $unit = $usePack ? $v['pack_vu'] : $unit;
            } else {
                $qty = $v['fraction'] ? [0.5, 0.5, 1, 1, 1.5, 2, 2, 3, 5][array_rand([0, 1, 2, 3, 4, 5, 6, 7, 8])] : ($v['price'] < 120 ? [1, 1, 1, 2, 2, 3, 4, 6][array_rand([0, 1, 2, 3, 4, 5, 6, 7])] : [1, 1, 1, 1, 2, 2, 3][array_rand([0, 1, 2, 3, 4, 5, 6])]);
            }
            $factor = $unit === $v['base_vu'] ? 1.0 : $v['pack'];
            $base = $qty * $factor;
            if ($base > ($this->stock[$v['id']] ?? 0)) {
                if ($unit !== $v['base_vu'] || ($this->stock[$v['id']] ?? 0) < 1) {
                    continue;
                }
                $qty = $base = $v['fraction'] ? floor(($this->stock[$v['id']] ?? 0) * 2) / 2 : floor($this->stock[$v['id']]);
                if ($qty <= 0) {
                    continue;
                }
            }
            $used[$v['id']] = true;
            $out[] = ['variant_id' => $v['id'], 'variant_unit_id' => $unit, 'quantity' => $this->qty($qty), 'base' => $base];
            $n++;
        }

        return $out;
    }

    private function weightedPick(): int
    {
        $x = mt_rand() / mt_getrandmax() * $this->cumulative[count($this->cumulative) - 1];
        $low = 0;
        $high = count($this->cumulative) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->cumulative[$mid] < $x) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $this->catalogIds[$low];
    }

    /** @return list<array<string, mixed>> */
    private function payments(float $amount): array
    {
        $amount = round($amount, 0);
        if ($amount <= 0) {
            return [];
        }
        $roll = mt_rand(1, 100);
        $cash = fn (float $a) => ['payment_method_id' => $this->methods['cash']->id, 'amount' => $this->money($a), 'tendered' => $this->money($this->tender($a))];
        $wallet = fn (string $code, float $a) => ['payment_method_id' => $this->methods[$code]->id, 'amount' => $this->money($a), 'reference' => strtoupper(substr($code, 0, 2)).mt_rand(10000000, 99999999)];
        if ($amount < 200 || $roll <= 68) {
            return [$cash($amount)];
        }
        if ($roll <= 82) {
            return [$wallet('easypaisa', $amount)];
        }
        if ($roll <= 90) {
            return [$wallet('jazzcash', $amount)];
        }
        if ($roll <= 96) {
            return [$wallet('card', $amount)];
        }
        $part = round($amount * mt_rand(30, 70) / 100, 0);

        return [$cash($part), $wallet('easypaisa', $amount - $part)];
    }

    private function tender(float $amount): float
    {
        return $this->chance(0.45) ? $amount : (float) (ceil($amount / ($amount > 2000 ? 500 : ($amount > 400 ? 100 : 50))) * ($amount > 2000 ? 500 : ($amount > 400 ? 100 : 50)));
    }

    private function dropCash(User $cashier, Shift $shift, int $above): void
    {
        $expected = (float) app(ShiftReport::class)->expectedCash($shift);
        if ($expected <= $above) {
            return;
        }
        $amount = floor(($expected - 8000) / 1000) * 1000;
        if ($amount >= 1000) {
            app(ShiftService::class)->recordCashMovement($cashier, $shift, ['type' => 'drop', 'amount' => $this->money($amount), 'note' => 'To the safe']);
        }
    }

    private function payout(User $cashier, Shift $shift): void
    {
        [$code, $recipient, $low, $high] = [['staff_meals', 'Tea and lunch for staff', 150, 450], ['transport', 'Rickshaw / loader', 200, 500], ['packaging', 'Shopping bags', 300, 500], ['repairs', 'Weighing scale repair', 200, 500], ['utilities', 'Water tanker', 300, 500], ['misc', 'Cleaning supplies', 150, 400]][array_rand([0, 1, 2, 3, 4, 5])];
        app(ShiftService::class)->recordCashMovement($cashier, $shift, [
            'type' => 'payout', 'amount' => (string) (round(mt_rand($low, $high) / 10) * 10), 'reason_code_id' => $this->reasons['payout'][$code], 'recipient' => $recipient, 'note' => $recipient,
        ]);
    }

    private function closeShift(User $cashier, Shift $shift): void
    {
        $expected = (float) app(ShiftReport::class)->expectedCash($shift);
        $roll = mt_rand(1, 100);
        $variance = $roll <= 72 ? 0 : ($roll <= 90 ? [-10, -20, -50, 10, 20, 50, -5, 5][array_rand([0, 1, 2, 3, 4, 5, 6, 7])] : ($roll <= 98 ? [-100, -150, -200, 100, 120][array_rand([0, 1, 2, 3, 4])] : -500));
        $counted = max(0, (int) round($expected) + $variance);
        $note = $variance === 0 ? null : ($variance < 0 ? 'Short at the count; change given wrongly to a customer' : 'Over at the count; a customer left without change');
        $closed = app(ShiftService::class)->close($cashier, $shift, $this->notes($counted), $note);
        if ($closed->variance_status === 'pending_review' && $this->chance(0.85)) {
            $this->as($this->users['manager']);
            app(ShiftService::class)->reviewVariance($this->users['manager'], $closed, 'Checked with the cashier and the CCTV; recorded as a counting difference.');
            $this->as($cashier);
        }
    }

    private function giveReturn(User $cashier, Shift $shift, array &$invoices): void
    {
        if ($invoices === []) {
            return;
        }
        $pick = $invoices[array_rand($invoices)];
        $invoice = $pick['invoice']->fresh('lines');
        $line = $invoice->lines->first(fn ($l) => ! str_starts_with((string) $l->description, '*') && (float) $l->quantity >= 1 && $l->variant_id !== null);
        if ($line === null) {
            return;
        }
        $damaged = $this->chance(0.4);
        $credit = $invoice->customer_id !== null && (float) $invoice->credit_extended > 0 && $this->chance(0.6);
        app(CreditNoteService::class)->create($cashier, $invoice, [
            'lines' => [['invoice_line_id' => $line->id, 'quantity' => '1', 'disposition' => $damaged ? 'damaged' : 'restock']],
            'reason_code_id' => $this->reasons['return'][$damaged ? ['damaged_item', 'expired_item'][array_rand([0, 1])] : ['changed_mind', 'wrong_item', 'quality'][array_rand([0, 1, 2])]],
            'settlement' => $credit ? 'khata_credit' : 'refund', 'payment_method_id' => $this->methods['cash']->id, 'shift_id' => $shift->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->totals['returns']++;
        if ($credit) {
            $key = array_search($invoice->customer_id, array_column($this->customers, 'id'), true);
            if ($key !== false) {
                $this->customers[$key]['owed'] = max(0, $this->customers[$key]['owed'] - (float) $line->unit_price);
            }
        }
    }

    private function collect(User $cashier, Shift $shift): void
    {
        $candidates = [];
        foreach ($this->customers as $key => $c) {
            if ($c['owed'] >= 500 && $this->chance(min(0.55, 0.05 + 0.5 * $c['owed'] / max(1, $c['limit'])))) {
                $candidates[] = $key;
            }
        }
        if ($candidates === []) {
            return;
        }
        $key = $candidates[array_rand($candidates)];
        $c = &$this->customers[$key];
        $amount = $this->chance(0.6) ? $c['owed'] : round($c['owed'] * mt_rand(30, 80) / 100 / 100) * 100;
        $amount = max(100, min($amount, $c['owed']));
        $method = $c['kind'] === 'shop' ? ($this->chance(0.6) ? 'bank_transfer' : 'cash') : ['cash', 'cash', 'cash', 'easypaisa', 'jazzcash'][array_rand([0, 1, 2, 3, 4])];
        $data = ['customer_id' => $c['id'], 'payment_method_id' => $this->methods[$method]->id, 'amount' => $this->money($amount), 'idempotency_key' => (string) Str::uuid(), 'notes' => 'Khata payment'];
        if ($this->methods[$method]->requires_reference) {
            $data['reference'] = strtoupper(substr($method, 0, 2)).mt_rand(10000000, 99999999);
        }
        if ($method === 'cash') {
            $data['shift_id'] = $shift->id;
        }
        app(CollectionService::class)->receive($cashier, $this->store, $data);
        $c['owed'] -= $amount;
        $this->totals['collections']++;
    }

    // ---- purchasing and stock -----------------------------------------------------------------

    private function refreshStock(string $date): void
    {
        $levels = StockLevel::query()
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->leftJoin('stock_batches', 'stock_batches.id', '=', 'stock_levels.batch_id')
            ->where('stock_levels.store_id', $this->store->id)->where('locations.is_sellable', true)
            ->where(fn ($q) => $q->whereNull('stock_levels.batch_id')->orWhere(fn ($b) => $b->where('stock_batches.status', 'available')->where(fn ($e) => $e->whereNull('stock_batches.expiry_date')->orWhere('stock_batches.expiry_date', '>=', $date))))
            ->groupBy('stock_levels.variant_id')->selectRaw('stock_levels.variant_id as id, SUM(stock_levels.on_hand) as qty')->pluck('qty', 'id');
        foreach ($this->catalogIds as $id) {
            $this->stock[$id] = (float) ($levels[$id] ?? 0);
        }
    }

    private function updateRates(): void
    {
        foreach ($this->catalogIds as $id) {
            $this->rate[$id] = 0.93 * $this->rate[$id] + 0.07 * (($this->sold[$id] ?? 0) * 1.0 + 0.0);
        }
        $this->sold = [];
    }

    /** Looks at what is running low and buys it: by purchase order (arrives in a day or two) or on the spot. */
    private function buy(string $date): void
    {
        $dow = (int) CarbonImmutable::parse($date)->format('N');
        $needs = [];
        foreach ($this->catalog as $id => $v) {
            $perishable = $v['tracked'] && $v['shelf'] <= 14;
            $reorderDays = $perishable ? 1.6 : 5.5;
            $coverDays = $perishable ? 3.0 : 13;
            $expected = max($this->rate[$id], $v['daily'] * 0.4);
            $position = ($this->stock[$id] ?? 0) + ($this->incoming[$id] ?? 0);
            if ($position > $expected * $reorderDays) {
                continue;
            }
            $wanted = $expected * $coverDays - $position;
            $packs = $v['pack'] > 1 ? (int) ceil($wanted / $v['pack']) : (int) ceil($wanted);
            if ($packs < 1) {
                continue;
            }
            $urgent = ($this->stock[$id] ?? 0) < $expected * 1.2;
            $needs[$v['supplier']][$id] = ['packs' => $packs, 'urgent' => $urgent];
        }
        $inventory = $this->users['inventory'];
        foreach ($needs as $supplier => $lines) {
            $info = $this->suppliers[$supplier];
            $urgent = count(array_filter($lines, fn ($l) => $l['urgent'])) >= 3;
            if (! in_array($dow, $info['days'], true) && ! $urgent) {
                continue;
            }
            $packs = array_map(fn ($l) => $l['packs'], $lines);
            $value = 0.0;
            foreach ($packs as $id => $n) {
                $value += $n * ($this->catalog[$id]['pack'] > 1 ? $this->catalog[$id]['pack'] : 1) * $this->catalog[$id]['cost'];
            }
            if ($value < ($info['cod'] ? 3000 : 20000) && ! $urgent) {
                continue;
            }
            $this->at($date, sprintf('10:%02d', mt_rand(0, 40)));
            $this->as($inventory);
            if ($info['cod'] || $this->chance(0.4)) {
                $this->attempt('purchase', fn () => $this->purchase($supplier, $packs, $inventory, true, $info['cod'] || $this->chance(0.25) ? 'paid_now' : 'on_credit', $info['cod'] ? $this->methods['cash'] : $this->methods['bank_transfer'], null));
            } else {
                $this->attempt('purchase order', fn () => $this->placeOrder($supplier, $packs, $inventory, $date));
            }
        }
    }

    /**
     * Buys stock on the spot (the Purchase stock screen): the goods and the supplier's bill in one step, paid now or on credit.
     *
     * @param  array<int, int>  $packs  variant id => number of packs (or pieces for items without a pack)
     */
    private function purchase(int $supplier, array $packs, User $user, bool $immediate, string $settlement, ?PaymentMethod $method, ?string $note, ?array $order = null): void
    {
        $this->as($user);
        $date = CarbonImmutable::now(self::TZ)->toDateString();
        $lines = [];
        $goods = 0.0;
        foreach ($packs as $id => $n) {
            $v = $this->catalog[$id];
            $price = round($v['cost'] * $this->costIndex * ($v['pack'] > 1 ? $v['pack'] : 1), 2);
            $line = ['variant_id' => $id, 'variant_unit_id' => $v['pack'] > 1 ? $v['pack_vu'] : $v['base_vu'], 'quantity_received' => (string) $n, 'unit_price' => $this->money($price)];
            if ($v['tracked']) {
                $shelf = max(2, $v['shelf'] - mt_rand(0, (int) max(1, $v['shelf'] * 0.2)));
                $line['batch_no'] = 'B'.CarbonImmutable::parse($date)->format('ymd').'-'.$v['id'];
                $line['expiry_date'] = CarbonImmutable::parse($date)->addDays($shelf)->toDateString();
            }
            $lines[] = $line;
            $goods += $n * $price;
        }
        $data = ['supplier_id' => $this->suppliers[$supplier]['id'], 'supplier_reference' => 'INV-'.mt_rand(100000, 999999), 'notes' => $note, 'lines' => $lines, 'settlement' => $settlement];
        if ($goods > 60000 && ! $this->suppliers[$supplier]['cod']) {
            $data['landed_costs'] = [['type' => 'freight', 'amount' => $this->money(round($goods * 0.011 / 50) * 50), 'description' => 'Carriage inward', 'allocation_method' => 'value']];
        }
        if ($settlement === 'paid_now') {
            $data['payment_method_id'] = $method->id;
            if ($method->requires_reference) {
                $data['payment_reference'] = 'IBFT'.mt_rand(100000000, 999999999);
                $this->ensureBank($goods + 20000);
            }
        }
        $receipt = app(ReceivingService::class)->receive($user, $this->store, $data);
        if ($receipt->status === 'pending_approval') {
            $this->as($this->owner);
            app(ReceivingService::class)->approve($this->owner, $receipt);
            $this->as($user);
        }
        foreach ($packs as $id => $n) {
            $this->stock[$id] = ($this->stock[$id] ?? 0) + $n * ($this->catalog[$id]['pack'] > 1 ? $this->catalog[$id]['pack'] : 1);
        }
        $this->totals['purchases']++;
    }

    /** @param array<int, int> $packs */
    private function placeOrder(int $supplier, array $packs, User $user, string $date): void
    {
        $info = $this->suppliers[$supplier];
        $lines = [];
        foreach ($packs as $id => $n) {
            $v = $this->catalog[$id];
            $lines[] = ['variant_id' => $id, 'variant_unit_id' => $v['pack'] > 1 ? $v['pack_vu'] : $v['base_vu'], 'quantity' => (string) $n, 'unit_price' => $this->money(round($v['cost'] * $this->costIndex * ($v['pack'] > 1 ? $v['pack'] : 1), 2))];
        }
        $service = app(PurchaseOrderService::class);
        $arrives = CarbonImmutable::parse($date)->addDays(max(1, $info['lead'] + mt_rand(0, 1)));
        $order = $service->save($user, $this->store, ['supplier_id' => $info['id'], 'expected_date' => $arrives->toDateString(), 'notes' => 'Reorder from low stock', 'source' => 'reorder', 'lines' => $lines]);
        $order = $service->submit($user, $order);
        if ($order->status !== 'approved') {
            $this->as($this->owner);
            $order = $service->approve($this->owner, $order);
            $this->as($user);
        }
        $service->markSent($user, $order);
        foreach ($packs as $id => $n) {
            $this->incoming[$id] = ($this->incoming[$id] ?? 0) + $n * ($this->catalog[$id]['pack'] > 1 ? $this->catalog[$id]['pack'] : 1);
        }
        $this->deliveries[$arrives->toDateString()][] = ['order' => $order->id, 'supplier' => $supplier, 'packs' => $packs];
        $this->totals['orders']++;
    }

    private function receiveDeliveries(string $date): void
    {
        foreach ($this->deliveries[$date] ?? [] as $delivery) {
            $this->at($date, sprintf('11:%02d', mt_rand(0, 50)));
            $user = $this->users['inventory'];
            $this->as($user);
            $this->attempt('delivery', function () use ($delivery, $user, $date) {
                $order = \App\Models\PurchaseOrder::query()->with('lines')->findOrFail($delivery['order']);
                $lines = [];
                $goods = 0.0;
                $short = false;
                foreach ($order->lines as $poLine) {
                    $v = $this->catalog[$poLine->variant_id];
                    $qty = (float) $poLine->quantity;
                    $got = $this->chance(0.06) ? max(1, floor($qty * 0.6)) : $qty;
                    $short = $short || $got < $qty;
                    $line = ['variant_id' => $poLine->variant_id, 'variant_unit_id' => $poLine->variant_unit_id, 'purchase_order_line_id' => $poLine->id, 'quantity_received' => (string) $got, 'unit_price' => $poLine->unit_price];
                    if ($this->chance(0.015)) {
                        $line['quantity_damaged'] = '1';
                        $line['discrepancy_note'] = 'One piece damaged in transit';
                    }
                    if ($v['tracked']) {
                        $line['batch_no'] = 'B'.CarbonImmutable::parse($date)->format('ymd').'-'.$v['id'];
                        $line['expiry_date'] = CarbonImmutable::parse($date)->addDays(max(2, $v['shelf'] - mt_rand(0, (int) max(1, $v['shelf'] * 0.2))))->toDateString();
                    }
                    $lines[] = $line;
                    $goods += $got * (float) $poLine->unit_price;
                }
                $settlement = $this->chance(0.15) ? 'paid_now' : 'on_credit';
                $data = ['supplier_id' => $this->suppliers[$delivery['supplier']]['id'], 'purchase_order_id' => $order->id, 'supplier_reference' => 'DC-'.mt_rand(100000, 999999), 'lines' => $lines, 'settlement' => $settlement];
                if ($goods > 40000) {
                    $data['landed_costs'] = [['type' => 'freight', 'amount' => $this->money(round($goods * 0.012 / 50) * 50), 'description' => 'Carriage inward', 'allocation_method' => 'value']];
                }
                if ($settlement === 'paid_now') {
                    $this->ensureBank($goods + 20000);
                    $data['payment_method_id'] = $this->methods['bank_transfer']->id;
                    $data['payment_reference'] = 'IBFT'.mt_rand(100000000, 999999999);
                }
                $receipt = app(ReceivingService::class)->receive($user, $this->store, $data);
                if ($receipt->status === 'pending_approval') {
                    $this->as($this->owner);
                    app(ReceivingService::class)->approve($this->owner, $receipt);
                    $this->as($user);
                }
                if ($short) {
                    $this->attempt('close order', fn () => app(PurchaseOrderService::class)->close($user, $order->fresh(), 'Supplier could not send the balance'));
                }
                foreach ($delivery['packs'] as $id => $n) {
                    $units = $n * ($this->catalog[$id]['pack'] > 1 ? $this->catalog[$id]['pack'] : 1);
                    $this->incoming[$id] = max(0, ($this->incoming[$id] ?? 0) - $units);
                }
                $this->totals['purchases']++;
            });
        }
        unset($this->deliveries[$date]);
        $this->refreshStock($date);
    }

    /** Settles what is due to suppliers (Monday): bank transfer for big amounts, cash for small ones. */
    private function paySuppliers(string $date): void
    {
        $accountant = $this->users['accountant'];
        $this->as($accountant);
        $this->at($date, '12:00');
        foreach ($this->suppliers as $supplier) {
            $due = (float) SupplierBill::query()->where('supplier_id', $supplier['id'])->where('status', 'posted')->whereIn('payment_status', ['unpaid', 'partial'])
                ->where('due_date', '<=', $date)->selectRaw('COALESCE(SUM(total - amount_paid - amount_credited), 0) as due')->value('due');
            if ($due < 500) {
                continue;
            }
            $amount = $this->chance(0.8) ? $due : max(500, floor($due * mt_rand(50, 85) / 100 / 500) * 500);
            $cash = $amount < 15000;
            $this->attempt('supplier payment', function () use ($accountant, $supplier, $amount, $cash) {
                if (! $cash) {
                    $this->ensureBank($amount + 50000);
                }
                app(PayablesService::class)->paySupplier($accountant, $this->store, [
                    'supplier_id' => $supplier['id'], 'payment_method_id' => $this->methods[$cash ? 'cash' : 'bank_transfer']->id, 'amount' => $this->money($amount),
                    'reference' => $cash ? null : 'IBFT'.mt_rand(100000000, 999999999), 'notes' => 'Weekly supplier payment', 'idempotency_key' => (string) Str::uuid(),
                ]);
                $this->totals['payments']++;
            });
        }
        $this->costIndex *= 1 + mt_rand(-10, 25) / 1000;   // supplier prices drift a little from week to week
    }

    /** Expired batches come off the shelf. */
    private function writeOffExpired(string $date): void
    {
        $rows = StockLevel::query()
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_levels.batch_id')
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->where('stock_levels.store_id', $this->store->id)->where('stock_levels.on_hand', '>', 0)->where('stock_batches.expiry_date', '<', $date)->where('locations.type', '!=', 'transit')
            ->get(['stock_levels.variant_id', 'stock_levels.location_id', 'stock_levels.batch_id', 'stock_levels.on_hand']);
        if ($rows->isEmpty()) {
            return;
        }
        $this->at($date, '08:40');
        $this->writeOff($this->users['inventory'], $rows->map(fn ($r) => ['variant_id' => $r->variant_id, 'location_id' => $r->location_id, 'batch_id' => $r->batch_id, 'quantity' => (string) $r->on_hand])->all(), 'expired', 'Past its expiry date, taken off the shelf');
    }

    /** A few broken, leaked or pest-hit items a month. */
    private function damages(string $date): void
    {
        if (! $this->chance(0.12)) {
            return;
        }
        $this->at($date, '18:00');
        $lines = [];
        for ($n = mt_rand(1, 3); $n > 0; $n--) {
            $v = $this->catalog[$this->weightedPick()];
            if ($v['tracked'] || ($this->stock[$v['id']] ?? 0) < 3) {
                continue;
            }
            $qty = $v['fraction'] ? '1' : (string) mt_rand(1, 3);
            $lines[] = ['variant_id' => $v['id'], 'location_id' => $this->godown->id, 'quantity' => $qty];
            $this->stock[$v['id']] -= (float) $qty;
        }
        if ($lines !== []) {
            [$code, $note] = [['damaged', 'Dropped while stacking'], ['leaked', 'Leaking pack found on the shelf'], ['pest', 'Pest-damaged bag found in the godown']][array_rand([0, 1, 2])];
            $this->writeOff($this->users['inventory'], $lines, $code, $note);
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeOff(User $user, array $lines, string $reason, string $note): void
    {
        $this->as($user);
        $service = app(AdjustmentService::class);
        $adjustment = $service->create($user, $this->store, 'write_off', $lines, $this->reasons['write_off'][$reason], $note);
        for ($step = 0; $step < 2 && $adjustment->status === 'pending_approval'; $step++) {
            $this->as($this->owner);
            $adjustment = $service->approve($this->owner, $adjustment);
        }
        $this->as($user);
        $this->totals['writeoffs']++;
    }

    /** Moves a week's stock of the fastest sellers from the godown to the shop floor. */
    private function restockShop(string $date): void
    {
        $user = $this->users['warehouse'];
        $this->as($user);
        $this->at($date, '19:30');
        $service = app(TransferService::class);
        $fast = $this->catalog;
        uasort($fast, fn ($a, $b) => $b['daily'] <=> $a['daily']);
        $lines = [];
        $godown = StockLevel::query()->where('store_id', $this->store->id)->where('location_id', $this->godown->id)->whereNull('batch_id')->pluck('on_hand', 'variant_id');
        foreach (array_slice($fast, 0, 80, true) as $id => $v) {
            if ($v['tracked']) {
                continue;
            }
            $move = floor(min((float) ($godown[$id] ?? 0), $v['daily'] * 7));
            if ($move >= 2) {
                $lines[] = ['variant_id' => $id, 'quantity' => (string) $move];
            }
        }
        if ($lines === []) {
            return;
        }
        $manager = $this->users['inventory'];
        $transfer = $service->create($user, $this->godown->id, $this->floor->id, $lines, 'Weekly refill of the shop floor');
        $this->as($manager);
        $transfer = $service->approve($manager, $transfer);
        $transfer = $service->dispatch($manager, $transfer);
        $received = [];
        foreach ($transfer->lines as $line) {
            $received[$line->id] = ['quantity' => (string) ($line->quantity_dispatched ?? $line->quantity)];
        }
        $service->receive($user, $transfer, $received);
        $this->totals['transfers']++;
    }

    /** A blind count by the godown keeper, a second count by someone else where it differs a lot, then posted. */
    private function stockCount(string $date, int $index): void
    {
        $this->at($date, '23:00');
        $keeper = $this->users['warehouse'];
        $inventory = $this->users['inventory'];
        $service = app(CountService::class);
        $this->as($inventory);
        $categories = ProductCategory::query()->inRandomOrder()->limit(3)->pluck('id')->all();
        $first = $this->totals['counts'] === 0;
        $count = $service->plan($inventory, $this->store, $first ? 'full' : 'category', $first ? [] : ['category_ids' => $categories], true, false, $keeper->id, $first ? 'Full count of the shop and godown' : 'Monthly cycle count');
        $count = $service->start($inventory, $count);
        $this->as($keeper);
        foreach ($count->lines()->get() as $line) {
            $expected = (float) $line->expected_quantity;
            $found = $expected;
            if ($this->chance(0.12) && $expected > 0) {
                $found = max(0, $expected + [-1, -1, -2, 1, -3][array_rand([0, 1, 2, 3, 4])]);
            }
            $service->record($keeper, $line, $this->qty($found));
        }
        $service->submit($keeper, $count->fresh());
        $this->as($inventory);
        foreach ($service->linesNeedingSecondCount($count->fresh()) as $line) {
            $service->record($inventory, $line->fresh(), $this->qty((float) $line->counted_quantity), true);
        }
        $service->approveAndPost($this->owner, $count->fresh());
        $this->totals['counts']++;
    }

    // ---- money: capital, banking, bills -------------------------------------------------------

    /** @param list<array<string, mixed>> $lines */
    private function journal(string $kind, string $description, array $lines, array $extra = []): void
    {
        $lines = array_merge($lines, $extra);
        app(JournalPoster::class)->post('manual', null, CarbonImmutable::now(self::TZ)->toDateString(), $description, $lines, $this->store->id, 'manual');
    }

    private function balance(string $key): float
    {
        return (float) JournalLine::query()->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', $key)
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as balance')->value('balance');
    }

    /** The owner puts in more money when the bank balance would not cover a payment. */
    private function ensureBank(float $needed): void
    {
        $bank = $this->balance('bank');
        if ($bank >= $needed) {
            return;
        }
        $top = (int) (ceil(($needed - $bank + 500000) / 250000) * 250000);
        $this->journal('capital', 'Owner capital: top-up', [['account' => 'bank', 'debit' => $this->money($top)], ['account' => 'owner_capital', 'credit' => $this->money($top)]]);
    }

    /** Saturday: cash from the safe goes to the bank, and wallet and card takings arrive in the bank. */
    private function banking(string $date): void
    {
        $this->at($date, '23:45');
        $cash = $this->balance('cash_on_hand');
        $deposit = floor(($cash - 250000) / 10000) * 10000;
        if ($deposit > 0) {
            $this->journal('deposit', 'Cash deposited at the bank', [['account' => 'bank', 'debit' => $this->money($deposit)], ['account' => 'cash_on_hand', 'credit' => $this->money($deposit)]]);
        }
        foreach (['wallet_easypaisa' => 'Easypaisa', 'wallet_jazzcash' => 'JazzCash', 'card_clearing' => 'Card settlement'] as $key => $label) {
            $amount = floor($this->balance($key));
            if ($amount > 1000) {
                $this->journal('settlement', "{$label} paid out to the bank", [['account' => 'bank', 'debit' => $this->money($amount)], ['account' => $key, 'credit' => $this->money($amount)]]);
            }
        }
    }

    private function verifyPayments(string $date): void
    {
        $accountant = $this->users['accountant'];
        $this->as($accountant);
        $service = app(PaymentVerificationService::class);
        $cutoff = CarbonImmutable::parse($date)->subDays(3)->toDateString();
        Payment::query()->where('verification_status', 'pending')->where('business_date', '<=', $cutoff)->orderBy('id')->limit(1500)->get()->each(function (Payment $payment) use ($service, $accountant) {
            $this->attempt('verify payment', fn () => $service->verify($accountant, $payment));
        });
    }

    /** Rent, electricity, internet and the other bills, paid from the bank. */
    private function monthlyBills(string $date): void
    {
        $month = (int) CarbonImmutable::parse($date)->format('n');
        $scale = max(0.5, $this->baseSales / 85);
        $summer = in_array($month, [5, 6, 7, 8, 9], true) ? 1.35 : 0.85;
        $bills = [
            ['rent', 'Shop and godown rent', 210000 * min(1.0, 0.6 + $scale * 0.4)],
            ['utilities', 'K-Electric bill', round((95000 * $summer + mt_rand(-8000, 8000)) * min(1.0, 0.6 + $scale * 0.4), -2)],
            ['utilities', 'Sui Southern gas and water tanker', mt_rand(9000, 16000)],
            ['communication', 'Internet, landline and mobile load', 11500],
            ['transport', 'Generator fuel and delivery van fuel', mt_rand(28000, 46000)],
            ['packaging', 'Shopping bags and packing tape', mt_rand(14000, 26000)],
            ['bank_charges', 'Bank charges and POS fees', mt_rand(3500, 9000)],
        ];
        if ($this->chance(0.35)) {
            $bills[] = ['repairs', ['Cold storage repair', 'Weighing scale calibration', 'Shelving repair', 'Shop shutter repair'][array_rand([0, 1, 2, 3])], mt_rand(6000, 35000)];
        }
        $this->at($date, '14:00');
        foreach ($bills as [$account, $description, $amount]) {
            $this->ensureBank((float) $amount + 25000);
            $this->journal('expense', 'Expense: '.$description, [['account' => $account, 'debit' => $this->money($amount), 'memo' => $description], ['account' => 'bank', 'credit' => $this->money($amount)]]);
        }
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** Runs one piece of the simulation. A business rule that refuses it is counted and the run carries on. */
    private function attempt(string $label, callable $work): mixed
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            $this->problems[$label] ??= ['count' => 0, 'samples' => []];
            $this->problems[$label]['count']++;
            $message = $e::class === BusinessRuleException::class ? $e->getMessage() : class_basename($e).': '.$e->getMessage();
            if (count($this->problems[$label]['samples']) < 3 && ! in_array($message, $this->problems[$label]['samples'], true)) {
                $this->problems[$label]['samples'][] = mb_substr($message, 0, 220);
            }
            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->warn("[{$label}] ".$message);
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return null;
        }
    }

    private function at(string $date, string $time): void
    {
        $moment = CarbonImmutable::parse("{$date} {$time}", self::TZ);
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    private function as(User $user): void
    {
        Auth::setUser($user);
    }

    /**
     * Create a one-time manager approval for a demo operation.
     *
     * ApprovalService binds the token to the requesting user. The requester
     * must therefore be the same user who later consumes the token, while
     * the owner or manager acts as the approving manager.
     *
     * The demo PIN is always 2580.
     */
    private function approve(User $requester, User $approver, string $action, int $storeId): string
    {
        return app(ApprovalService::class)->grant($requester, $approver, self::PIN, $action, $storeId)['token'];
    }

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    private function gauss(): float
    {
        return sqrt(-2 * log(max(1e-9, mt_rand() / mt_getrandmax()))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
    }

    private function money(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    private function phone(): string
    {
        return '03'.['00', '01', '02', '03', '12', '21', '33', '45'][array_rand([0, 1, 2, 3, 4, 5, 6, 7])].'-'.mt_rand(1000000, 9999999);
    }

    private function personName(): string
    {
        $female = $this->chance(0.4);
        $first = $female ? self::FEMALE[array_rand(self::FEMALE)] : self::MALE[array_rand(self::MALE)];

        return $first.' '.self::SURNAMES[array_rand(self::SURNAMES)];
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
        foreach ([5000, 1000, 500, 100, 50, 20, 10, 5, 2, 1] as $note) {
            $n = intdiv($amount, $note);
            if ($n > 0) {
                $out[(string) $note] = $n;
                $amount -= $n * $note;
            }
        }

        return $out;
    }

    private function finish(float $started): void
    {
        $minutes = round((microtime(true) - $started) / 60, 1);
        $this->info("Done in {$minutes} minutes.");
        $this->table(['Month', 'Sales', 'Revenue (Rs)'], array_map(fn ($m, $row) => [$m, $row['sales'], number_format($row['revenue'])], array_keys($this->months), $this->months));
        $t = $this->totals;
        $this->line(sprintf(
            '%d trading days (%d closed), %d sales worth Rs %s, %d returns, %d Khata collections, %d purchases, %d purchase orders, %d supplier payments, %d write-offs, %d stock counts, %d transfers, %d payroll runs.',
            $t['days'] - $t['closed'], $t['closed'], $t['sales'], number_format((float) $t['revenue']), $t['returns'], $t['collections'], $t['purchases'], $t['orders'], $t['payments'], $t['writeoffs'], $t['counts'], $t['transfers'], $t['payrolls'],
        ));
        if ($this->problems !== []) {
            $this->newLine();
            $this->warn('Some steps were refused by the business rules and skipped (normal in small numbers):');
            foreach ($this->problems as $label => $info) {
                $this->line("  {$label}: {$info['count']}x  e.g. ".implode(' | ', $info['samples']));
            }
        }
        $this->newLine();
        $this->info('Logins (password '.self::PASSWORD.', approval PIN '.self::PIN.'):');
        $roles = ['owner' => 'Owner / Admin'] + array_map(fn ($d) => $d[4], $this->staffDefs);
        $rows = [['owner@demo.test', 'Owner / Admin']];
        foreach ($this->staffDefs as $def) {
            $rows[] = [$def[1], $def[4]];
        }
        $this->table(['E-mail', 'Role'], $rows);
        $this->line('To use it, put DB_DATABASE=hassani_demo in .env and start the app. Put it back to hassani_erp afterwards.');
    }
}