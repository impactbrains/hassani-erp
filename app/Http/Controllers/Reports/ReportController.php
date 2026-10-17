<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reporting\ForecastService;
use App\Domain\Reporting\Report;
use App\Domain\Reporting\ReportExporter;
use App\Domain\Reporting\ReportFilters;
use App\Domain\Reporting\ReportRegistry;
use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use App\Models\ReportFavorite;
use App\Models\ReportRecent;
use App\Models\ReportSavedView;
use App\Models\Store;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Report centre. Each report shows what it measures (source, date basis,
 * definitions, limitations), headline cards with the comparison period,
 * a chart, plain-language insights and a sortable, paginated table; the
 * CSV export uses the same definition and filters.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $reports = ReportRegistry::forUser($user);
        abort_if($reports === [], 403, 'You do not have access to any reports.');
        $favorites = ReportFavorite::query()->where('user_id', $user->id)->pluck('report_key')->all();

        return Inertia::render('reports/Index', [
            'sections' => ReportRegistry::SECTIONS,
            'reports' => array_values(array_map(fn (Report $r) => $r->meta() + ['favorite' => in_array($r->key(), $favorites, true)], $reports)),
            'recent' => ReportRecent::query()->where('user_id', $user->id)->latest('viewed_at')->limit(6)->pluck('report_key'),
        ]);
    }

    public function show(Request $request, string $key): Response
    {
        $report = ReportRegistry::find($key);
        $user = $request->user();
        abort_unless($user->hasPermission($report->permission()) || $user->storeIdsFor($report->permission()) !== [], 403);

        $input = $request->query();
        $view = null;
        if ($request->filled('view')) {
            $view = ReportSavedView::query()->where('user_id', $user->id)->where('report_key', $key)->find($request->integer('view'));
            $input = $view ? array_merge($view->filters ?? [], array_diff_key($input, ['view' => 1])) : $input;
        } elseif (count($input) === 0 && ($default = ReportSavedView::query()->where('user_id', $user->id)->where('report_key', $key)->where('is_default', true)->first())) {
            $view = $default;
            $input = $default->filters ?? [];
        }
        $filters = ReportFilters::fromInput($input, $user, $report->permission());
        if (! $report->supportsComparison()) {
            $filters->compareFrom = $filters->compareTo = null;
        }
        $columns = $report->visibleColumns($filters, $user);
        $page = $report->sortedRows($filters)->paginate($filters->perPage, ['*'], 'page', $filters->page);
        $rows = collect($page->items())->map(fn ($row) => array_intersect_key((array) $row, $columns));
        $summary = $report->summary($filters);
        $hidden = array_diff_key($report->columns($filters), $columns);
        $summary = array_values(array_filter($summary, fn ($card) => ! isset($hidden[$card['key']]) && ($card['key'] !== 'gross_margin' || $user->hasPermission('catalog.costs.view'))));

        ReportRecent::query()->updateOrCreate(['user_id' => $user->id, 'report_key' => $key], ['viewed_at' => now()]);

        return Inertia::render('reports/Show', [
            'report' => $report->meta() + ['definition' => $report->definition(), 'filters' => $report->filters(), 'group_by' => $report->groupByOptions(), 'comparison' => $report->supportsComparison(), 'drill' => $report->drillDown()],
            'columns' => $columns,
            'rows' => $rows,
            'pagination' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
            'summary' => $summary,
            'chart' => $report->chart($filters),
            'insights' => $report->insights($filters, $summary),
            'filters' => $filters->toArray() + ['sort' => $filters->sort, 'direction' => $filters->direction],
            'stores' => Store::query()->whereIn('id', $user->hasPermission('reports.all_stores') ? $user->accessibleStoreIds() : $user->storeIdsFor($report->permission()))->get(['id', 'name']),
            'views' => ReportSavedView::query()->where('user_id', $user->id)->where('report_key', $key)->orderBy('name')->get(['id', 'name', 'is_default']),
            'activeView' => $view?->id,
            'favorite' => ReportFavorite::query()->where('user_id', $user->id)->where('report_key', $key)->exists(),
            'canExport' => $user->hasPermission('reports.export'),
            'canRunForecast' => $key === 'forecasts.demand' && $user->hasPermission('reports.forecasts.run'),
        ]);
    }

    public function export(Request $request, string $key, ReportExporter $exporter): RedirectResponse
    {
        $report = ReportRegistry::find($key);
        $filters = ReportFilters::fromInput($request->all(), $request->user(), $report->permission());
        $export = $exporter->request($request->user(), $report, $filters);

        return $export->status === 'completed'
            ? redirect()->route('reports.exports.download', $export)
            : redirect()->route('reports.exports')->with('success', 'The export is being prepared. It will appear here when ready.');
    }

    public function exports(Request $request): Response
    {
        return Inertia::render('reports/Exports', ['exports' => ReportExport::query()->where('user_id', $request->user()->id)->latest('id')->paginate(30)->through(fn ($e) => $e->toArray() + ['title' => rescue(fn () => ReportRegistry::find($e->report_key)->title(), $e->report_key, false)])]);
    }

    public function download(Request $request, ReportExport $export, ReportExporter $exporter)
    {
        return $exporter->download($request->user(), $export);
    }

    public function saveView(Request $request, string $key): RedirectResponse
    {
        ReportRegistry::find($key);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'filters' => ['required', 'array'], 'is_default' => ['boolean']]);
        $user = $request->user();
        if ($data['is_default'] ?? false) {
            ReportSavedView::query()->where('user_id', $user->id)->where('report_key', $key)->update(['is_default' => false]);
        }
        $filters = array_intersect_key($data['filters'], array_flip(['preset', 'from', 'to', 'compare', 'store_ids', 'group_by', 'search', 'sort', 'direction', 'per_page', 'options', ...ReportFilters::ENTITY_FILTERS]));
        $view = ReportSavedView::query()->updateOrCreate(['user_id' => $user->id, 'report_key' => $key, 'name' => $data['name']], ['filters' => $filters, 'is_default' => (bool) ($data['is_default'] ?? false)]);

        return redirect()->route('reports.show', ['key' => $key, 'view' => $view->id])->with('success', 'View saved.');
    }

    public function deleteView(Request $request, ReportSavedView $view): RedirectResponse
    {
        abort_unless($view->user_id === $request->user()->id, 403);
        $view->delete();

        return redirect()->route('reports.show', $view->report_key);
    }

    public function favorite(Request $request, string $key): RedirectResponse
    {
        ReportRegistry::find($key);
        $existing = ReportFavorite::query()->where('user_id', $request->user()->id)->where('report_key', $key)->first();
        $existing ? $existing->delete() : ReportFavorite::create(['user_id' => $request->user()->id, 'report_key' => $key]);

        return back();
    }

    public function runForecast(Request $request, ForecastService $forecasts): RedirectResponse
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'reports.forecasts.run', $store);
        $run = $forecasts->run($store, $request->user()->id);
        Audit::record('report.forecast_run', "Recalculated demand forecasts for {$store->name}", $run, storeId: $store->id);

        return back()->with('success', "Forecasts recalculated for {$run->series_count} products.");
    }
}
