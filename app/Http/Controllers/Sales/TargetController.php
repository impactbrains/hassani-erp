<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sales targets per agent or store; actuals are in Reports → Targets vs actual. */
class TargetController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'sales.targets.manage');

        return Inertia::render('sales/Targets', ['targets' => SalesTarget::query()->with('salesAgent:id,name', 'store:id,name')->latest('period_start')->paginate(50)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow($request, 'sales.targets.manage');
        $data = $request->validate(['store_id' => ['nullable', 'integer', 'in:'.implode(',', $request->user()->accessibleStoreIds())], 'sales_agent_id' => ['nullable', 'integer'], 'metric' => ['required', 'in:net_sales,collections'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'target_value' => ['required', 'numeric', 'gt:0']]);
        SalesTarget::create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Target saved.');
    }

    public function destroy(Request $request, SalesTarget $target): RedirectResponse
    {
        $this->allow($request, 'sales.targets.manage');
        $target->delete();

        return back();
    }
}
