<?php

namespace App\Http\Controllers\Core;

use App\Domain\Catalog\CatalogSearch;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\ReasonCode;
use App\Models\Register;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Small JSON look-ups behind search boxes and drop-downs. Each checks the matching view permission. */
class LookupController extends Controller
{
    public function products(Request $request, CatalogSearch $catalog): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['pos.sell', 'catalog.products.view', 'sales.orders.manage', 'inventory.stock.view', 'purchasing.orders.manage', 'purchasing.receive', 'inventory.purchase_stock']), 403);
        $storeId = (int) ($request->integer('store_id') ?: $this->currentStore($request)->id);
        abort_unless(in_array($storeId, $request->user()->accessibleStoreIds(), true), 403);
        $customer = $request->integer('customer_id') ? Contact::query()->customers()->with('customerProfile')->find($request->integer('customer_id')) : null;
        $priceBookId = $request->integer('price_book_id') ?: $customer?->customerProfile?->price_book_id;
        $store = \App\Models\Store::query()->find($storeId);
        $pricing = $store ? new \App\Domain\Catalog\PriceContext($storeId, $customer, $store->businessDate()) : null;

        $purpose = in_array($request->query('purpose'), ['sale', 'purchase', 'any'], true) ? $request->query('purpose') : 'sale';
        // Buying screens show what the item cost last time, to people who buy stock or may see costs.
        $costs = $purpose === 'purchase' && $request->user()->hasAnyPermission(['catalog.costs.view', 'inventory.purchase_stock', 'purchasing.orders.manage', 'purchasing.receive'])
            ? ['supplier_id' => $request->integer('supplier_id') ?: null] : null;

        return response()->json($catalog->search($request->string('q'), $storeId, $priceBookId, min(50, $request->integer('limit', 20)), $request->integer('category_id') ?: null, false, $purpose, $costs, $pricing));
    }

    public function contacts(Request $request, string $type): JsonResponse
    {
        $permission = match ($type) {
            'customer' => 'crm.customers.view|pos.sell|sales.orders.manage|sales.payments.receive',
            'supplier' => 'purchasing.suppliers.view|purchasing.orders.manage|purchasing.receive|inventory.purchase_stock',
            'employee' => 'hr.employees.view|sales.deliveries.manage|sales.targets.manage|hr.attendance.manage|settings.users.manage',
            default => abort(404),
        };
        abort_unless($request->user()->hasAnyPermission(explode('|', $permission)), 403);
        $showPhone = $type !== 'customer' || $request->user()->hasAnyPermission(['crm.customers.contact_details', 'pos.sell', 'sales.orders.manage']);
        $rows = Contact::query()->ofType($type)->whereNull('merged_into_id')->where('is_active', true)
            ->search($request->string('q')->toString() ?: null)
            ->when($type === 'customer', fn ($q) => $q->with('customerProfile:id,contact_id,credit_limit,credit_status,price_book_id,category'))
            ->when($type === 'employee' && $request->filled('role_type'), fn ($q) => $q->whereHas('employeeDetail', fn ($d) => $d->where('role_type', $request->string('role_type'))))
            ->orderBy('name')->limit(20)->get(['id', 'code', 'name', 'name_ur', 'phone', 'area', 'type']);

        return response()->json($rows->map(fn (Contact $c) => [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'name_ur' => $c->name_ur, 'area' => $c->area,
            'phone' => $showPhone ? $c->phone : null,
            'credit_limit' => $c->customerProfile?->credit_limit, 'credit_status' => $c->customerProfile?->credit_status, 'category' => $c->customerProfile?->category,
        ]));
    }

    /** Option lists for forms; only lists the user's stores. */
    public function options(Request $request): JsonResponse
    {
        $want = explode(',', (string) $request->query('only'));
        $stores = $request->user()->accessibleStoreIds();
        $out = [];
        foreach ($want as $key) {
            $out[$key] = match ($key) {
                'locations' => Location::query()->whereIn('store_id', $stores)->where('is_active', true)->orderBy('store_id')->orderBy('name')->get(['id', 'store_id', 'code', 'name', 'type', 'is_sellable']),
                'payment_methods' => PaymentMethod::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'name_ur', 'type', 'requires_reference', 'requires_verification', 'terminal_labels', 'available_in_pos']),
                'categories' => \App\Models\ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_ur', 'parent_id']),
                'brands' => \App\Models\Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_ur']),
                'registers' => Register::query()->whereIn('store_id', $stores)->where('is_active', true)->get(['id', 'store_id', 'name']),
                'accounts' => $request->user()->hasAnyPermission(['accounting.view', 'purchasing.bills.manage', 'accounting.journals.manage']) ? Account::query()->where('is_postable', true)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'subtype']) : [],
                'users' => $request->user()->hasAnyPermission(['settings.users.view', 'reports.sales', 'inventory.counts.manage']) ? User::query()->inCurrentCompany()->where('status', 'active')->orderBy('name')->get(['id', 'name']) : [],
                default => str_starts_with($key, 'reasons:') ? ReasonCode::query()->where('type', substr($key, 8))->where('is_active', true)->orderBy('label')->get(['id', 'code', 'label', 'label_ur', 'requires_note']) : null,
            };
        }

        return response()->json(array_filter($out, fn ($v) => $v !== null));
    }
}
