<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Pos\ReceiptData;
use App\Domain\Sales\CreditNoteService;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\ReasonCode;
use App\Models\SalesInvoice;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Posted receipts and invoices, and returns (credit notes) against them. */
class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $stores = $request->user()->storeIdsFor('sales.invoices.view');
        $q = trim((string) $request->query('q'));
        $invoices = SalesInvoice::query()->whereIn('store_id', $stores)->where('status', 'posted')->with('store:id,name', 'cashier:id,name')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'ilike', "%{$q}%")->orWhere('customer_name', 'ilike', "%{$q}%")))
            ->when($request->query('payment_status'), fn ($w, $s) => $w->where('payment_status', $s))
            ->when($request->query('kind'), fn ($w, $k) => $w->where('kind', $k))
            ->when($request->query('from'), fn ($w, $d) => $w->where('business_date', '>=', $d))
            ->when($request->query('to'), fn ($w, $d) => $w->where('business_date', '<=', $d))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('sales/Invoices', ['invoices' => $invoices, 'filters' => $request->only(['q', 'payment_status', 'kind', 'from', 'to'])]);
    }

    public function show(Request $request, SalesInvoice $invoice, ReceiptData $receipts): Response
    {
        $this->allow($request, 'sales.invoices.view', $invoice->store_id);
        $invoice->load(['lines.variant:id,sku', 'lines.variantUnit.unit:id,code', 'customer:id,name,code', 'cashier:id,name', 'store:id,name', 'creditNotes.reasonCode:id,label', 'salesOrder:id,number']);
        $user = $request->user();

        return Inertia::render('sales/InvoiceShow', [
            'invoice' => $invoice,
            'receipt' => $receipts->forInvoice($invoice),
            'showCost' => $user->hasPermission('catalog.costs.view'),
            'canReturn' => $user->hasPermission('pos.refund', $invoice->store_id) || $user->hasPermission('sales.credit_notes.create', $invoice->store_id),
            'returnReasons' => ReasonCode::query()->where('type', 'return')->where('is_active', true)->get(['id', 'label', 'label_ur']),
            'openShift' => Shift::query()->where('store_id', $invoice->store_id)->where('status', 'open')->where('opened_by', $user->id)->value('id'),
        ]);
    }

    public function creditNote(Request $request, SalesInvoice $invoice, CreditNoteService $notes, ReceiptData $receipts): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.disposition' => ['required', 'in:restock,damaged,inspect'],
            'reason_code_id' => ['required', 'integer'],
            'settlement' => ['required', 'in:refund,khata_credit'],
            'payment_method_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'approval_token' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $note = $notes->create($request->user(), $invoice, $data);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => $note->id, 'number' => $note->number, 'total' => $note->total, 'receipt' => $receipts->forCreditNote($note)]);
        }

        return back()->with('success', "Return {$note->number} recorded (Rs {$note->total}).");
    }

    public function creditNoteReceipt(Request $request, CreditNote $note, ReceiptData $receipts): JsonResponse
    {
        $this->allow($request, 'sales.invoices.view', $note->store_id);

        return response()->json($receipts->forCreditNote($note));
    }
}
