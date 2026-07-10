<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Location;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosDocumentController extends Controller
{
    /**
     * POS order history / reporting tab. Scoped to type=pos_order only —
     * this is deliberately POS-specific for now. A future cross-module
     * Reporting app can query the same Document model directly without
     * needing changes here.
     */
    /**
     * POS order + refund history / reporting tab. Scoped to type IN
     * (pos_order, pos_refund) — deliberately POS-specific for now. A
     * future cross-module Reporting app can query the same Document
     * model directly without needing changes here.
     */
    public function index(Request $request): Response
    {
        $documents = Document::with(['contact', 'location', 'user', 'lines'])
            ->whereIn('type', ['pos_order', 'pos_refund'])
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->location_id, fn ($q, $locationId) => $q->where('location_id', $locationId))
            ->when($request->search, fn ($q, $search) => $q->where(function ($sq) use ($search) {
                $sq->where('number', 'like', "%{$search}%")
                    ->orWhereHas('contact', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            }))
            ->when($request->from, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->to, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $documents->getCollection()->transform(function (Document $document) {
            $document->line_count = $document->lines->count();
            unset($document->lines);
            return $document;
        });

        return Inertia::render('pos/History', [
            'documents' => $documents,
            'locations' => Location::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['type', 'status', 'location_id', 'search', 'from', 'to']),
            'statusCounts' => Document::whereIn('type', ['pos_order', 'pos_refund'])
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
        ]);
    }

    /**
     * Full detail view of a single order — used for reviewing a past sale,
     * and as the basis for reprinting a receipt or invoice later.
     */
    public function show(Document $document): Response
    {
        abort_unless(in_array($document->type, ['pos_order', 'pos_refund']), 404);

        $document->load([
            'contact',
            'location',
            'user',
            'lines.variant.product',
            'lines.tax',
            'payments',
        ]);

        return Inertia::render('pos/DocumentShow', [
            'document' => $document,
        ]);
    }

    /**
     * Narrow, thermal-receipt-styled page — no dashboard chrome, triggers
     * window.print() on load. Opened in a new tab from checkout or from
     * the "reprint" action on a past order.
     */
    public function receipt(Document $document): Response
    {
        abort_unless($document->type === 'pos_order', 404);

        $document->load(['contact', 'location', 'user', 'lines.variant.product', 'payments']);

        return Inertia::render('pos/print/Receipt', [
            'document' => $document,
        ]);
    }

    /**
     * Renders and streams a PDF invoice for the given order via dompdf,
     * from a Blade view (not the Vue/Inertia stack, since dompdf renders
     * plain server-side HTML/CSS).
     */
    public function invoice(Document $document)
    {
        abort_unless($document->type === 'pos_order', 404);

        $document->load(['contact', 'location', 'user', 'lines.variant.product', 'lines.tax', 'payments']);

        $pdf = app('dompdf.wrapper')->loadView('pdf.pos-invoice', [
            'document' => $document,
        ])->setPaper('a4');

        return $pdf->stream("invoice-{$document->number}.pdf");
    }
}