<?php

use App\Http\Controllers\Accounting\AccountingController;
use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductImportController;
use App\Http\Controllers\Core\ApprovalController;
use App\Http\Controllers\Core\DashboardController;
use App\Http\Controllers\Core\LookupController;
use App\Http\Controllers\Crm\CustomerController;
use App\Http\Controllers\Crm\SegmentController;
use App\Http\Controllers\Hr\AttendanceController;
use App\Http\Controllers\Hr\EmployeeController;
use App\Http\Controllers\Hr\LeaveController;
use App\Http\Controllers\Hr\PayrollController;
use App\Http\Controllers\Hr\SelfServiceController;
use App\Http\Controllers\Inventory\AdjustmentController;
use App\Http\Controllers\Inventory\CountController;
use App\Http\Controllers\Inventory\RepackController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\TransferController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\ShiftController;
use App\Http\Controllers\Purchasing\PayablesController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\ReceiptController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Sales\DeliveryController;
use App\Http\Controllers\Sales\InvoiceController;
use App\Http\Controllers\Sales\OrderController;
use App\Http\Controllers\Sales\PaymentController;
use App\Http\Controllers\Sales\TargetController;
use App\Http\Controllers\Settings\ApiClientController;
use App\Http\Controllers\Settings\MasterDataController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\StoreController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;

/*
| Staff web app. There is no public registration: the first owner is
| created once by /setup, everyone else in Settings → Users.
| Route permissions are a first gate; services check again per store.
*/

Route::redirect('/', '/dashboard');
Route::post('/locale', [AccountController::class, 'updateLocale'])->name('locale.update');

Route::middleware('guest')->group(function () {
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::get('/login/two-factor', [AuthenticatedSessionController::class, 'twoFactor'])->name('two-factor.challenge');
    Route::post('/login/two-factor', [AuthenticatedSessionController::class, 'verifyTwoFactor'])->middleware('throttle:login');
    Route::get('/forgot-password', [AuthenticatedSessionController::class, 'forgot'])->name('password.request');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/password/change', [AccountController::class, 'forceChange'])->name('password.force');
    Route::put('/password/change', [AccountController::class, 'updatePassword'])->name('password.force.update');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/store/switch', [DashboardController::class, 'switchStore'])->name('store.switch');

    // My account
    Route::prefix('account')->name('account.')->controller(AccountController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::put('/', 'updateProfile')->name('update');
        Route::put('/password', 'updatePassword')->name('password');
        Route::put('/pin', 'updatePin')->name('pin')->middleware('throttle:pin');
        Route::post('/two-factor', 'startTwoFactor')->name('two-factor.start');
        Route::put('/two-factor', 'confirmTwoFactor')->name('two-factor.confirm')->middleware('throttle:pin');
        Route::delete('/two-factor', 'disableTwoFactor')->name('two-factor.disable');
        Route::delete('/sessions/{session}', 'revokeSession')->name('sessions.revoke');
        Route::delete('/sessions', 'revokeOtherSessions')->name('sessions.revoke-others');
    });
    Route::get('/me', [SelfServiceController::class, 'show'])->name('me');

    // Shared look-ups and approvals
    Route::prefix('lookup')->name('lookup.')->controller(LookupController::class)->group(function () {
        Route::get('/products', 'products')->name('products');
        Route::get('/contacts/{type}', 'contacts')->name('contacts');
        Route::get('/options', 'options')->name('options');
    });
    Route::post('/approvals', [ApprovalController::class, 'store'])->name('approvals.store')->middleware('throttle:pin');
    Route::get('/approvals/approvers', [ApprovalController::class, 'approvers'])->name('approvals.approvers');

    // POS
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::middleware('perm:pos.access')->controller(PosController::class)->group(function () {
            Route::get('/', 'screen')->name('screen');
            Route::post('/quote', 'quote')->name('quote');
            Route::post('/checkout', 'checkout')->name('checkout');
            Route::get('/recent', 'recent')->name('recent');
            Route::get('/held', 'held')->name('held');
            Route::post('/held', 'hold')->name('hold');
            Route::post('/held/{cart}/resume', 'resume')->name('resume');
            Route::delete('/held/{cart}', 'discard')->name('discard');
            Route::post('/void-log', 'voidLog')->name('void-log');
        });
        Route::get('/receipts/{invoice}', [PosController::class, 'receipt'])->name('receipt');
        Route::controller(ShiftController::class)->group(function () {
            Route::get('/shifts', 'index')->name('shifts.index');
            Route::get('/shifts/{shift}', 'show')->name('shifts.show');
            Route::post('/shifts', 'open')->name('shifts.open')->middleware('perm:pos.shift.open');
            Route::post('/shifts/join', [PosController::class, 'join'])->name('shifts.join')->middleware('perm:pos.shift.join');
            Route::get('/shifts/{shift}/x-report', 'xReport')->name('shifts.x');
            Route::post('/shifts/{shift}/cash', 'cash')->name('shifts.cash');
            Route::post('/shifts/{shift}/close', 'close')->name('shifts.close')->middleware('perm:pos.shift.close');
            Route::post('/shifts/{shift}/review', 'review')->name('shifts.review');
        });
    });

    // Sales
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index')->middleware('perm:sales.invoices.view,any');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoices/{invoice}/credit-notes', [InvoiceController::class, 'creditNote'])->name('invoices.credit-note');
        Route::get('/credit-notes/{note}/receipt', [InvoiceController::class, 'creditNoteReceipt'])->name('credit-notes.receipt');

        Route::middleware('perm:sales.orders.view,any')->group(function () {
            Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
            Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit');
            Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
            Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm'])->name('orders.confirm');
            Route::post('/orders/{order}/advance', [OrderController::class, 'advance'])->name('orders.advance');
            Route::post('/orders/{order}/dispatch', [OrderController::class, 'dispatch'])->name('orders.dispatch');
            Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        });

        Route::get('/riders', [DeliveryController::class, 'index'])->name('riders');
        Route::post('/deliveries/{delivery}/assign', [DeliveryController::class, 'assign'])->name('deliveries.assign');
        Route::post('/deliveries/{delivery}/update', [DeliveryController::class, 'update'])->name('deliveries.update');
        Route::post('/riders/handover', [DeliveryController::class, 'handover'])->name('riders.handover');

        Route::get('/collections', [PaymentController::class, 'collections'])->name('collections');
        Route::post('/collections', [PaymentController::class, 'collect'])->name('collections.store');
        Route::get('/payments/verify', [PaymentController::class, 'verification'])->name('payments.verify')->middleware('perm:sales.payments.verify,any');
        Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.decide');

        Route::get('/targets', [TargetController::class, 'index'])->name('targets');
        Route::post('/targets', [TargetController::class, 'store'])->name('targets.store');
        Route::delete('/targets/{target}', [TargetController::class, 'destroy'])->name('targets.destroy');
    });

    // Catalog
    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::put('/urdu-names', [ProductController::class, 'urduNames'])->name('products.urdu');
        Route::get('/labels', [ProductController::class, 'labels'])->name('labels');
        Route::get('/import', [ProductImportController::class, 'show'])->name('import');
        Route::post('/import', [ProductImportController::class, 'store'])->name('import.store');
        Route::get('/import/template', [ProductImportController::class, 'template'])->name('import.template');
        Route::get('/export', [ProductImportController::class, 'export'])->name('export');
    });

    // Inventory
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/stock', [StockController::class, 'index'])->name('stock');
        Route::get('/stock/{variant}', [StockController::class, 'variant'])->name('stock.variant');
        Route::get('/batches', [StockController::class, 'batches'])->name('batches');
        Route::post('/batches/{batch}/status', [StockController::class, 'batchStatus'])->name('batches.status');
        Route::get('/replenishment', [StockController::class, 'replenishment'])->name('replenishment');
        Route::post('/replenishment', [StockController::class, 'savePolicy'])->name('replenishment.save');

        Route::get('/adjustments', [AdjustmentController::class, 'index'])->name('adjustments.index');
        Route::get('/adjustments/create', [AdjustmentController::class, 'create'])->name('adjustments.create');
        Route::post('/adjustments', [AdjustmentController::class, 'store'])->name('adjustments.store');
        Route::get('/adjustments/{adjustment}', [AdjustmentController::class, 'show'])->name('adjustments.show');
        Route::post('/adjustments/{adjustment}/decide', [AdjustmentController::class, 'decide'])->name('adjustments.decide');

        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('/transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');
        Route::post('/transfers/{transfer}/action', [TransferController::class, 'action'])->name('transfers.action');

        Route::get('/counts', [CountController::class, 'index'])->name('counts.index');
        Route::post('/counts', [CountController::class, 'store'])->name('counts.store');
        Route::get('/counts/{count}', [CountController::class, 'show'])->name('counts.show');
        Route::post('/counts/{count}/action', [CountController::class, 'action'])->name('counts.action');
        Route::post('/counts/{count}/lines', [CountController::class, 'addLine'])->name('counts.lines');
        Route::put('/count-lines/{line}', [CountController::class, 'record'])->name('counts.record');

        Route::get('/unlisted', [\App\Http\Controllers\Inventory\UnlistedController::class, 'index'])->name('unlisted');
        Route::post('/unlisted/{pending}/dismiss', [\App\Http\Controllers\Inventory\UnlistedController::class, 'dismiss'])->name('unlisted.dismiss');
        Route::get('/repack', [RepackController::class, 'index'])->name('repack');
        Route::post('/repack', [RepackController::class, 'store'])->name('repack.store');
    });

    // Purchasing
    Route::prefix('purchasing')->name('purchasing.')->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

        Route::get('/orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/create', [PurchaseOrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [PurchaseOrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{order}/edit', [PurchaseOrderController::class, 'edit'])->name('orders.edit');
        Route::put('/orders/{order}', [PurchaseOrderController::class, 'update'])->name('orders.update');
        Route::post('/orders/{order}/action', [PurchaseOrderController::class, 'action'])->name('orders.action');

        Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
        Route::get('/receipts/create', [ReceiptController::class, 'create'])->name('receipts.create');
        Route::post('/receipts', [ReceiptController::class, 'store'])->name('receipts.store');
        Route::get('/receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');

        Route::get('/bills', [PayablesController::class, 'bills'])->name('bills');
        Route::get('/bills/create', [PayablesController::class, 'createBill'])->name('bills.create');
        Route::post('/bills', [PayablesController::class, 'storeBill'])->name('bills.store');
        Route::get('/bills/{bill}', [PayablesController::class, 'showBill'])->name('bills.show');
        Route::post('/payments', [PayablesController::class, 'pay'])->name('payments.store')->middleware('perm:purchasing.payments.make');
        Route::get('/returns/create', [PayablesController::class, 'createReturn'])->name('returns.create');
        Route::post('/returns', [PayablesController::class, 'storeReturn'])->name('returns.store');
    });

    // CRM
    Route::prefix('crm')->name('crm.')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::post('/customers/{customer}/merge', [CustomerController::class, 'merge'])->name('customers.merge');
        Route::post('/customers/{customer}/activity', [CustomerController::class, 'activity'])->name('customers.activity');
        Route::post('/follow-ups/{followUp}/done', [CustomerController::class, 'updateFollowUp'])->name('follow-ups.done');
        Route::put('/cases/{case}', [CustomerController::class, 'updateCase'])->name('cases.update');
        Route::get('/work', [CustomerController::class, 'work'])->name('work');
        Route::get('/segments', [SegmentController::class, 'index'])->name('segments');
        Route::post('/segments', [SegmentController::class, 'store'])->name('segments.store');
        Route::delete('/segments/{segment}', [SegmentController::class, 'destroy'])->name('segments.destroy');
        Route::get('/segments/{segment}/export', [SegmentController::class, 'export'])->name('segments.export');
    });

    // Accounting
    Route::prefix('accounting')->name('accounting.')->controller(AccountingController::class)->group(function () {
        Route::get('/accounts', 'accounts')->name('accounts.index');
        Route::post('/accounts', 'saveAccount')->name('accounts.store');
        Route::get('/accounts/{account}', 'account')->name('accounts.show');
        Route::put('/accounts/{account}', 'saveAccount')->name('accounts.update');
        Route::get('/journals', 'journals')->name('journals.index');
        Route::get('/journals/create', 'createJournal')->name('journals.create');
        Route::post('/journals', 'storeJournal')->name('journals.store');
        Route::get('/journals/{journal}', 'journal')->name('journals.show');
        Route::post('/journals/{journal}/reverse', 'reverse')->name('journals.reverse');
        Route::post('/expenses', 'storeExpense')->name('expenses.store');
        Route::get('/periods', 'periods')->name('periods');
        Route::post('/periods/{period}/toggle', 'togglePeriod')->name('periods.toggle');
        Route::get('/budgets', 'budgets')->name('budgets');
        Route::put('/budgets', 'saveBudgets')->name('budgets.save');
    });

    // HR & payroll
    Route::prefix('hr')->name('hr.')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::post('/employees/{employee}/transition', [EmployeeController::class, 'transition'])->name('employees.transition');
        Route::post('/employees/{employee}/salary', [EmployeeController::class, 'salary'])->name('employees.salary');
        Route::post('/employees/{employee}/login', [EmployeeController::class, 'linkLogin'])->name('employees.login');
        Route::post('/employees/{employee}/documents', [EmployeeController::class, 'uploadDocument'])->name('employees.documents');
        Route::get('/documents/{document}/link', [EmployeeController::class, 'documentLink'])->name('documents.link');
        Route::get('/documents/{document}/download', [EmployeeController::class, 'download'])->name('documents.download');

        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance');
        Route::post('/attendance/punch', [AttendanceController::class, 'punch'])->name('attendance.punch');
        Route::get('/roster', [AttendanceController::class, 'roster'])->name('roster');
        Route::post('/roster', [AttendanceController::class, 'assign'])->name('roster.assign');
        Route::post('/attendance/corrections', [AttendanceController::class, 'requestCorrection'])->name('attendance.corrections');
        Route::post('/attendance/corrections/{correction}', [AttendanceController::class, 'reviewCorrection'])->name('attendance.corrections.review');

        Route::get('/leave', [LeaveController::class, 'index'])->name('leave');
        Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
        Route::post('/leave/{leaveRequest}', [LeaveController::class, 'review'])->name('leave.review');
        Route::post('/holidays', [LeaveController::class, 'holiday'])->name('holidays.store');

        Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll', [PayrollController::class, 'prepare'])->name('payroll.prepare');
        Route::get('/payroll/{run}', [PayrollController::class, 'show'])->name('payroll.show');
        Route::post('/payroll/{run}/action', [PayrollController::class, 'action'])->name('payroll.action');
        Route::get('/payslips/{payslip}', [PayrollController::class, 'payslip'])->name('payslips.show');
        Route::post('/payroll-adjustments', [PayrollController::class, 'adjustment'])->name('payroll.adjustments');
        Route::post('/payroll-adjustments/{adjustment}', [PayrollController::class, 'reviewAdjustment'])->name('payroll.adjustments.review');
        Route::get('/advances', [PayrollController::class, 'advances'])->name('advances');
        Route::post('/advances', [PayrollController::class, 'requestAdvance'])->name('advances.store');
        Route::post('/advances/{advance}/action', [PayrollController::class, 'advanceAction'])->name('advances.action');
    });

    // Reports
    Route::prefix('reports')->name('reports.')->controller(ReportController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/exports', 'exports')->name('exports');
        Route::get('/exports/{export}/download', 'download')->name('exports.download');
        Route::post('/forecasts/run', 'runForecast')->name('forecasts.run');
        Route::delete('/views/{view}', 'deleteView')->name('views.destroy');
        Route::get('/{key}', 'show')->name('show')->where('key', '[a-z_]+\.[a-z_]+');
        Route::post('/{key}/export', 'export')->name('export')->middleware('perm:reports.export,any');
        Route::post('/{key}/views', 'saveView')->name('views.store');
        Route::post('/{key}/favorite', 'favorite')->name('favorite');
    });

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::put('/', [SettingsController::class, 'update'])->name('update');
        Route::put('/company', [SettingsController::class, 'company'])->name('company');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/action', [UserController::class, 'action'])->name('users.action');
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        Route::get('/security', [SecurityController::class, 'index'])->name('security');
        Route::get('/api', [ApiClientController::class, 'index'])->name('api.index');
        Route::post('/api', [ApiClientController::class, 'store'])->name('api.store');
        Route::delete('/api/{client}', [ApiClientController::class, 'destroy'])->name('api.destroy');
        Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
        Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');
        Route::put('/stores/{store}', [StoreController::class, 'update'])->name('stores.update');
        Route::get('/lists', [MasterDataController::class, 'index'])->name('lists');
        Route::post('/lists/{list}', [MasterDataController::class, 'save'])->name('lists.store');
        Route::put('/lists/{list}/{id}', [MasterDataController::class, 'save'])->name('lists.update');
        Route::delete('/lists/{list}/{id}', [MasterDataController::class, 'destroy'])->name('lists.destroy');
    });
});
