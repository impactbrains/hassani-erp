<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Catalog\UnlistedItems;
use App\Http\Controllers\Controller;
use App\Models\PendingProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** "Products to create": items sold at the POS that are not in the product list yet. */
class UnlistedController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'catalog.products.view');
        $status = $request->query('status', 'pending');
        $recent = DB::table('pending_product_sales')->where('sold_at', '>=', now()->subDays(30))->groupBy('pending_product_id')->selectRaw('pending_product_id, COUNT(*) AS n');
        $items = PendingProduct::query()->where('status', $status)
            ->leftJoinSub($recent, 'r', 'r.pending_product_id', '=', 'pending_products.id')
            ->select('pending_products.*', DB::raw('COALESCE(r.n, 0) AS sales_30_days'))
            ->with('lastStore:id,name', 'product:id,name')
            ->orderByDesc('sales_30_days')->orderByDesc('last_sold_at')->paginate(50)->withQueryString();
        $items->getCollection()->each(fn ($p) => $p->setAttribute('frequency', UnlistedItems::frequency((int) $p->sales_30_days)));

        return Inertia::render('inventory/Unlisted', [
            'items' => $items,
            'filters' => ['status' => $status],
            'counts' => PendingProduct::query()->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
            'canCreate' => $request->user()->hasPermission('catalog.products.manage'),
        ]);
    }

    public function dismiss(Request $request, PendingProduct $pending, UnlistedItems $unlisted): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        $unlisted->resolve($pending, 'dismissed', $request->user()->id);

        return back()->with('success', "“{$pending->name}” removed from the list.");
    }
}
