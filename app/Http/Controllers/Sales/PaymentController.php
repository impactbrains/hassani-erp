<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\CollectionService;
use App\Domain\Sales\CreditControl;
use App\Domain\Sales\PaymentVerificationService;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Wasooli (Khata collections) and verification of wallet, card and bank payments. */
class PaymentController extends Controller
{
    public function collections(Request $request, CreditControl $credit): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'sales.payments.receive', $store);
        $customer = $request->integer('customer_id') ? Contact::query()->customers()->find($request->integer('customer_id'), ['id', 'name', 'code', 'phone']) : null;

        return Inertia::render('sales/Collections', [
            'customer' => $customer ? $customer->toArray() + [
                'balance' => $credit->balance($customer->id),
                'open_invoices' => SalesInvoice::query()->where('customer_id', $customer->id)->where('status', 'posted')->whereIn('payment_status', ['unpaid', 'partial'])->orderBy('business_date')->get(['id', 'number', 'business_date', 'due_date', 'total', 'amount_paid', 'amount_credited']),
            ] : null,
            'recent' => Payment::query()->where('store_id', $store->id)->whereIn('kind', ['collection', 'delivery_collection'])->with('contact:id,name', 'method:id,name', 'receiver:id,name')->latest('id')->limit(30)->get(),
            'openShift' => Shift::query()->where('store_id', $store->id)->where('status', 'open')->where('opened_by', $request->user()->id)->value('id'),
        ]);
    }

    public function collect(Request $request, CollectionService $collections): RedirectResponse
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'sales.payments.receive', $store);
        $data = $request->validate(['customer_id' => ['required', 'integer'], 'payment_method_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'reference' => ['nullable', 'string', 'max:80'], 'shift_id' => ['nullable', 'integer'], 'invoice_ids' => ['array'], 'invoice_ids.*' => ['integer'], 'idempotency_key' => ['required', 'string', 'max:64'], 'notes' => ['nullable', 'string', 'max:300']]);
        $payment = $collections->receive($request->user(), $store, $data);

        return redirect()->route('sales.collections', ['customer_id' => $data['customer_id']])->with('success', "Received Rs {$payment->amount} ({$payment->number}).");
    }

    public function verification(Request $request): Response
    {
        $stores = $request->user()->storeIdsFor('sales.payments.verify');
        $status = $request->query('status', 'pending');

        return Inertia::render('sales/Verification', [
            'payments' => Payment::query()->whereIn('store_id', $stores)->where('verification_status', $status)->with('method:id,name,type', 'contact:id,name', 'receiver:id,name', 'verifier:id,name', 'store:id,name')
                ->when($request->query('method'), fn ($q, $m) => $q->where('payment_method_id', $m))
                ->latest('id')->paginate($this->perPage($request, 50))->withQueryString(),
            'filters' => ['status' => $status, 'method' => $request->query('method')],
        ]);
    }

    public function verify(Request $request, Payment $payment, PaymentVerificationService $verification): RedirectResponse
    {
        $this->allow($request, 'sales.payments.verify', $payment->store_id);
        $data = $request->validate(['decision' => ['required', 'in:verify,reject'], 'reason' => ['nullable', 'required_if:decision,reject', 'string', 'max:300']]);
        $data['decision'] === 'verify' ? $verification->verify($request->user(), $payment) : $verification->reject($request->user(), $payment, $data['reason']);

        return back()->with('success', $data['decision'] === 'verify' ? 'Payment verified.' : 'Payment rejected; the amount is back on the customer\'s Khata.');
    }
}
