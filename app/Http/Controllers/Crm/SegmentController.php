<?php

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\SegmentService;
use App\Domain\Reporting\CsvWriter;
use App\Http\Controllers\Controller;
use App\Models\CustomerSegment;
use App\Models\Tag;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SegmentController extends Controller
{
    public function index(Request $request, SegmentService $segments): Response
    {
        $this->allow($request, 'crm.customers.view');
        $selected = $request->integer('segment') ? CustomerSegment::query()->find($request->integer('segment')) : null;

        return Inertia::render('crm/Segments', [
            'segments' => CustomerSegment::query()->orderBy('name')->get(),
            'fields' => SegmentService::FIELDS, 'operators' => SegmentService::OPERATORS,
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'selected' => $selected,
            'members' => $selected ? $segments->members($selected)->orderByDesc('spend_90d')->paginate(50)->withQueryString() : null,
            'canManage' => $request->user()->hasPermission('crm.segments.manage'),
        ]);
    }

    public function store(Request $request, SegmentService $segments): RedirectResponse
    {
        $this->allow($request, 'crm.segments.manage');
        $data = $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'rules' => ['required', 'array', 'min:1'], 'rules.*.field' => ['required', 'string'], 'rules.*.operator' => ['required', 'string'], 'rules.*.value' => ['present']]);
        $segments->validate($data['rules']);
        $segment = CustomerSegment::query()->updateOrCreate(['id' => $data['id'] ?? null], ['name' => $data['name'], 'description' => $data['description'] ?? null, 'rules' => $data['rules'], 'is_active' => true, 'created_by' => $request->user()->id]);

        return redirect()->route('crm.segments', ['segment' => $segment->id])->with('success', 'Segment saved.');
    }

    public function destroy(Request $request, CustomerSegment $segment): RedirectResponse
    {
        $this->allow($request, 'crm.segments.manage');
        $segment->delete();

        return redirect()->route('crm.segments');
    }

    public function export(Request $request, CustomerSegment $segment, SegmentService $segments): StreamedResponse
    {
        $this->allow($request, 'crm.customers.export');
        $withContact = $request->user()->hasPermission('crm.customers.contact_details');
        Audit::record('crm.segment_exported', "Exported segment {$segment->name}", $segment);

        return response()->streamDownload(function () use ($segment, $segments, $withContact) {
            $out = new CsvWriter(fopen('php://output', 'w'));
            $out->header(array_merge(['code', 'name', 'area', 'spend_90d', 'orders_90d', 'days_since_purchase', 'khata_balance'], $withContact ? ['phone'] : []));
            $segments->members($segment)->orderBy('name')->chunk(500, function ($rows) use ($out, $withContact) {
                foreach ($rows as $c) {
                    $out->header(array_merge([(string) $c->code, $c->name, (string) $c->area, (string) $c->spend_90d, (string) $c->orders_90d, (string) $c->days_since_purchase, (string) $c->khata_balance], $withContact ? [(string) $c->phone] : []));
                }
            });
        }, 'segment-'.\Illuminate\Support\Str::slug($segment->name).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
