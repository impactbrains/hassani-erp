<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\UserPermissionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosDocumentController;
use App\Http\Controllers\PosRefundController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\TaskController;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/dashboard', function () {
        return Inertia::render('dashboard/Index');
    })->name('dashboard');
    // --- Public careers pages (no login required) ---
    Route::prefix('careers')->name('careers.')->group(function () {
        Route::get('/', [CareersController::class, 'index'])->name('index');

        Route::get('/apply', [CareersController::class, 'showJobApplication'])->name('apply');
        Route::post('/apply', [CareersController::class, 'submitJobApplication'])
            ->middleware('throttle:5,60')
            ->name('apply.store');

        Route::get('/supplier-apply', [CareersController::class, 'showSupplierApplication'])->name('supplier-apply');
        Route::post('/supplier-apply', [CareersController::class, 'submitSupplierApplication'])
            ->middleware('throttle:5,60')
            ->name('supplier-apply.store');
    });
    Route::middleware(['app.access:todo'])->prefix('todo')->name('todo.')->group(function () {
        Route::get('/', [TaskController::class, 'index'])->name('index');
    
        // Full creation UI (assign to anyone) — admin only.
        Route::middleware(['admin'])->group(function () {
            Route::get('/create', [TaskController::class, 'create'])->name('create');
            Route::post('/', [TaskController::class, 'store'])->name('store');
        });
    
        // Self-service creation — any staff member with todo access,
        // hard-locked to assigning only themselves inside the controller.
        Route::get('/create-own', [TaskController::class, 'createOwn'])->name('create-own');
        Route::post('/own', [TaskController::class, 'storeSelf'])->name('store-own');
    
        // Edit/update/destroy: reachable by any staff member at the route
        // level — the actual "admin OR creator" check happens inside each
        // controller method, since it depends on the specific task.
        Route::get('/{task}/edit', [TaskController::class, 'edit'])->name('edit');
        Route::put('/{task}', [TaskController::class, 'update'])->name('update');
        Route::delete('/{task}', [TaskController::class, 'destroy'])->name('destroy');
    
        // Status updates — any staff member who is admin, an assignee, or
        // the creator (enforced inside updateStatus()).
        Route::patch('/{task}/status', [TaskController::class, 'updateStatus'])->name('status');
    });

    // --- POS ---
   Route::middleware(['app.access:pos'])->prefix('pos')->name('pos.')->group(function () {
    Route::get('/', [PosController::class, 'index'])->name('index');
    Route::get('/search', [PosController::class, 'search'])->name('search');
    Route::post('/scan-barcode', [PosController::class, 'scanBarcode'])->name('scan-barcode');
    Route::get('/contacts/search', [PosController::class, 'searchContacts'])->name('contacts.search');
    Route::post('/contacts', [PosController::class, 'storeContact'])->name('contacts.store');

    Route::post('/drafts', [PosController::class, 'startDraft'])->name('drafts.store');
    Route::get('/drafts/{document}/refresh', [PosController::class, 'refreshDraft'])->name('drafts.refresh');
    Route::delete('/drafts/{document}', [PosController::class, 'discardDraft'])->name('drafts.destroy');

    Route::post('/drafts/{document}/lines', [PosController::class, 'addLine'])->name('lines.store');
    Route::put('/drafts/{document}/lines/{line}', [PosController::class, 'updateLine'])->name('lines.update');
    Route::delete('/drafts/{document}/lines/{line}', [PosController::class, 'removeLine'])->name('lines.destroy');

    Route::post('/drafts/{document}/lines/{line}/discount', [PosController::class, 'applyLineDiscount'])->name('lines.discount');
    Route::post('/drafts/{document}/discount', [PosController::class, 'applyOrderDiscount'])->name('discount');
    Route::post('/drafts/{document}/contact', [PosController::class, 'assignContact'])->name('contact');

    Route::get('/checkout/{document}', [PosController::class, 'showCheckout'])->name('checkout.show');
    Route::post('/drafts/{document}/checkout', [PosController::class, 'checkout'])->name('checkout');

    Route::get('/history', [PosDocumentController::class, 'index'])->name('history');
    Route::get('/history/{document}', [PosDocumentController::class, 'show'])->name('history.show');
    Route::get('/history/{document}/receipt', [PosDocumentController::class, 'receipt'])->name('history.receipt');
    Route::get('/history/{document}/invoice', [PosDocumentController::class, 'invoice'])->name('history.invoice');
    Route::get('/history/{document}/refund', [PosRefundController::class, 'create'])->name('refund.create');
    Route::post('/history/{document}/refund', [PosRefundController::class, 'store'])->name('refund.store');
    Route::post('/drafts/{document}/lines/custom', [PosController::class, 'addCustomLine'])->name('lines.custom');
});

    // --- Inventory ---
Route::middleware(['app.access:inventory'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('/', [InventoryController::class, 'index'])->name('index');

    Route::get('/record-movement', [InventoryController::class, 'createMovement'])->name('record-movement');
    Route::post('/record-movement', [InventoryController::class, 'storeMovement'])->name('store-movement');
    Route::get('/variants/search', [InventoryController::class, 'searchVariants'])->name('variants.search');

    Route::get('/history', [InventoryController::class, 'history'])->name('history');

    Route::prefix('locations')->name('locations.')->group(function () {
        Route::get('/', [InventoryController::class, 'locationsIndex'])->name('index');
        Route::post('/', [InventoryController::class, 'storeLocation'])->name('store');
        Route::put('/{location}', [InventoryController::class, 'updateLocation'])->name('update');
        Route::delete('/{location}', [InventoryController::class, 'destroyLocation'])->name('destroy');
        Route::post('/{location}/set-default', [InventoryController::class, 'setDefaultLocation'])->name('set-default');
    });
});

    // --- Invoicing ---
    Route::middleware(['app.access:invoicing'])->prefix('invoicing')->name('invoicing.')->group(function () {
        Route::get('/', function () {
            return Inertia::render('invoicing/Index');
        })->name('index');
    });

    // --- Contacts ---
   Route::middleware(['app.access:contacts'])->prefix('contacts')->name('contacts.')->group(function () {
    Route::get('/', [ContactController::class, 'index'])->name('index');
    Route::get('/history', [ContactController::class, 'history'])->name('history');

    Route::get('/employees', [ContactController::class, 'employees'])->name('employees');
    Route::get('/suppliers-view', [ContactController::class, 'suppliers'])->name('suppliers-view');
    
    Route::get('/create', [ContactController::class, 'create'])->name('create');
    Route::post('/', [ContactController::class, 'store'])->name('store');
    Route::get('/{contact}', [ContactController::class, 'show'])->name('show');
    Route::get('/{contact}/edit', [ContactController::class, 'edit'])->name('edit');
    Route::put('/{contact}', [ContactController::class, 'update'])->name('update');
    Route::delete('/{contact}', [ContactController::class, 'destroy'])->name('destroy');

    Route::post('/{contact}/apply-as-candidate', [ContactController::class, 'applyAsCandidate'])->name('apply-as-candidate');
    Route::post('/{contact}/advance-stage', [ContactController::class, 'advanceCandidateStage'])->name('advance-stage');
    Route::post('/{contact}/hire', [ContactController::class, 'hireCandidate'])->name('hire');
    Route::post('/{contact}/reject', [ContactController::class, 'rejectCandidate'])->name('reject');

    Route::post('/{contact}/records', [ContactController::class, 'storeRecord'])->name('records.store');
    Route::put('/{contact}/records/{record}', [ContactController::class, 'updateRecord'])->name('records.update');
    Route::delete('/{contact}/records/{record}', [ContactController::class, 'destroyRecord'])->name('records.destroy');
});

// --- Employee HR profile (separate nested group — do NOT merge into the block above) ---
Route::middleware(['app.access:contacts'])->prefix('contacts/{contact}/employee')->name('contacts.employee.')->group(function () {
    Route::get('/', [EmployeeController::class, 'show'])->name('show');

    Route::put('/work', [EmployeeController::class, 'updateWork'])->name('work.update');
    Route::put('/schedule', [EmployeeController::class, 'updateSchedule'])->name('schedule.update');
    Route::put('/personal', [EmployeeController::class, 'updatePersonal'])->name('personal.update');
    Route::put('/resume-file', [EmployeeController::class, 'updateResumeFile'])->name('resume-file.update');

    Route::post('/resume-items', [EmployeeController::class, 'storeResumeItem'])->name('resume-items.store');
    Route::put('/resume-items/{resumeItem}', [EmployeeController::class, 'updateResumeItem'])->name('resume-items.update');
    Route::delete('/resume-items/{resumeItem}', [EmployeeController::class, 'destroyResumeItem'])->name('resume-items.destroy');

    Route::post('/skills', [EmployeeController::class, 'attachSkill'])->name('skills.attach');
    Route::post('/skills/create', [EmployeeController::class, 'storeSkill'])->name('skills.store');
    Route::delete('/skills/{skill}', [EmployeeController::class, 'detachSkill'])->name('skills.detach');

    Route::post('/badges', [EmployeeController::class, 'attachBadge'])->name('badges.attach');
    Route::delete('/badges/{badge}', [EmployeeController::class, 'detachBadge'])->name('badges.detach');

    Route::get('/history', [EmployeeController::class, 'historyIndex'])->name('history');
});

    Route::middleware(['admin'])->prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserPermissionController::class, 'index'])->name('index');
        Route::get('/{user}/edit', [UserPermissionController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserPermissionController::class, 'update'])->name('update');
    });

    // --- Products ---
    Route::middleware(['app.access:products'])->prefix('products')->name('products.')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('/create', [ProductController::class, 'create'])->name('create');
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::get('/{product}/edit', [ProductController::class, 'edit'])->name('edit');
        Route::put('/{product}', [ProductController::class, 'update'])->name('update');
        Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
        Route::post('/lookup-barcode', [ProductController::class, 'lookupBarcode'])->name('lookup-barcode');
    });

    // Inline "create new" endpoints used by SelectWithCreate — flat route
    // names (categories.store, units.store, taxes.store), no products.
    // prefix, since Form.vue's SelectWithCreate calls these by these
    // exact unprefixed names today.
    Route::middleware(['app.access:products'])->group(function () {
        Route::post('/units', [UnitController::class, 'store'])->name('units.store');
        Route::post('/categories', [ProductCategoryController::class, 'store'])->name('categories.store');
        Route::post('/taxes', [TaxController::class, 'store'])->name('taxes.store');
    });

    // Dedicated management pages for categories/units/taxes — separate
    // namespace (products.manage.*) so they don't collide with the flat
    // inline-create route names above.
    Route::middleware(['app.access:products'])->prefix('products/manage')->name('products.manage.')->group(function () {
        Route::get('categories', [ProductCategoryController::class, 'index'])->name('categories.index');
        Route::put('categories/{category}', [ProductCategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [ProductCategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('units', [UnitController::class, 'index'])->name('units.index');
        Route::put('units/{unit}', [UnitController::class, 'update'])->name('units.update');
        Route::delete('units/{unit}', [UnitController::class, 'destroy'])->name('units.destroy');

        Route::get('taxes', [TaxController::class, 'index'])->name('taxes.index');
        Route::put('taxes/{tax}', [TaxController::class, 'update'])->name('taxes.update');
        Route::delete('taxes/{tax}', [TaxController::class, 'destroy'])->name('taxes.destroy');
        Route::post('taxes/{tax}/set-default', [TaxController::class, 'setDefault'])->name('taxes.set-default');
    });

    // --- Media library (used by MediaPicker) ---
    Route::middleware(['app.access:products'])->prefix('media')->name('media.')->group(function () {
        Route::get('/', [MediaController::class, 'index'])->name('index');
        Route::post('/', [MediaController::class, 'store'])->name('store');
        Route::post('/url', [MediaController::class, 'storeUrl'])->name('store-url');
    });

    Route::middleware(['app.access:barcode'])->prefix('barcode')->name('barcode.')->group(function () {
        Route::get('/', [BarcodeController::class, 'index'])->name('index');
        Route::post('/{variant}/generate', [BarcodeController::class, 'generate'])->name('generate');
        Route::post('/{variant}/clear', [BarcodeController::class, 'clear'])->name('clear');
        Route::get('/{variant}/print', [BarcodeController::class, 'printLabel'])->name('print');
 
        Route::get('/generate-batch', [BarcodeController::class, 'createBatch'])->name('generate-batch');
        Route::post('/generate-batch', [BarcodeController::class, 'storeBatch'])->name('store-batch');
 
        Route::get('/pool', [BarcodeController::class, 'pool'])->name('pool');
        Route::post('/pool/{generatedBarcode}/mark-used', [BarcodeController::class, 'markUsed'])->name('pool.mark-used');
        Route::post('/pool/{generatedBarcode}/mark-unused', [BarcodeController::class, 'markUnused'])->name('pool.mark-unused');
        Route::put('/pool/{generatedBarcode}', [BarcodeController::class, 'updateLabel'])->name('pool.update');
        Route::delete('/pool/{generatedBarcode}', [BarcodeController::class, 'destroy'])->name('pool.destroy');
        Route::delete('/pool/bulk', [BarcodeController::class, 'bulkDestroy'])->name('pool.bulk-destroy');
        Route::post('/pool/bulk-print', [BarcodeController::class, 'bulkPrint'])->name('pool.bulk-print');
    });
   
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';