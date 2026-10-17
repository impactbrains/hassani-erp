<?php

namespace App\Http\Controllers\Core;

use App\Domain\Reporting\DashboardService;
use App\Domain\Reporting\ReportFilters;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $dashboard): Response
    {
        $user = $request->user();
        $data = null;
        if ($user->hasPermission('dashboard.view')) {
            $filters = ReportFilters::fromInput($request->only(['preset', 'from', 'to', 'compare', 'store_ids']) + ['preset' => 'today'], $user, 'dashboard.view', 'today');
            $data = $dashboard->build($user, $filters);
            $data['filters'] = $filters->toArray();
        }

        return Inertia::render('Dashboard', ['dashboard' => $data]);
    }

    public function switchStore(Request $request): RedirectResponse
    {
        $id = $request->integer('store_id');
        abort_unless(in_array($id, $request->user()->accessibleStoreIds(), true), 403);
        $request->session()->put('store_id', $id);

        return back();
    }
}
