<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\DeliveryService;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\RiderHandover;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Rider board: assign deliveries, record delivered/failed/returned, and take rider cash in (handover). */
class DeliveryController extends Controller
{
    public function index(Request $request, DeliveryService $deliveries): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'sales.deliveries.manage', $store);
        $active = Delivery::query()->where('store_id', $store->id)->where(fn ($q) => $q->whereIn('status', ['pending', 'assigned', 'out_for_delivery'])->orWhere('updated_at', '>=', now()->subDay()))
            ->with(['rider:id,name,phone', 'salesOrder:id,number,delivery_address,delivery_area,delivery_phone,customer_id', 'salesOrder.customer:id,name', 'invoice:id,number,total,amount_paid'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'assigned' THEN 1 WHEN 'out_for_delivery' THEN 2 ELSE 3 END")->latest('id')->limit(300)->get();
        $riders = Contact::query()->employees()->where('is_active', true)->whereHas('employeeDetail', fn ($q) => $q->where('role_type', 'rider')->whereIn('status', ['active', 'probation', 'onboarding']))->orderBy('name')->get(['id', 'name', 'phone'])
            ->map(fn ($r) => $r->only(['id', 'name', 'phone']) + ['cash_with_rider' => $deliveries->cashWithRider($r->id), 'active' => $active->where('rider_id', $r->id)->whereIn('status', ['assigned', 'out_for_delivery'])->count()]);

        return Inertia::render('sales/Riders', [
            'deliveries' => $active,
            'riders' => $riders,
            'handovers' => RiderHandover::query()->where('store_id', $store->id)->with('rider:id,name', 'receiver:id,name')->latest('id')->limit(20)->get(),
            'openShift' => Shift::query()->where('store_id', $store->id)->where('status', 'open')->where('opened_by', $request->user()->id)->value('id'),
        ]);
    }

    public function assign(Request $request, Delivery $delivery, DeliveryService $deliveries): RedirectResponse
    {
        $this->allow($request, 'sales.deliveries.manage', $delivery->store_id);
        $deliveries->assign($request->user(), $delivery, $request->validate(['rider_id' => ['required', 'integer']])['rider_id']);

        return back();
    }

    public function update(Request $request, Delivery $delivery, DeliveryService $deliveries): RedirectResponse
    {
        $this->allow($request, 'sales.deliveries.manage', $delivery->store_id);
        $data = $request->validate(['outcome' => ['required', 'in:delivered,failed,returned'], 'collected' => ['nullable', 'numeric', 'min:0'], 'reason' => ['nullable', 'required_unless:outcome,delivered', 'string', 'max:300'], 'note' => ['nullable', 'string', 'max:300']]);
        match ($data['outcome']) {
            'delivered' => $deliveries->markDelivered($request->user(), $delivery, (string) ($data['collected'] ?? '0'), $data['note'] ?? null),
            'failed' => $deliveries->markFailed($request->user(), $delivery, $data['reason']),
            'returned' => $deliveries->markReturned($request->user(), $delivery, $data['reason']),
        };

        return back()->with('success', "Delivery {$delivery->number} updated.");
    }

    public function handover(Request $request, DeliveryService $deliveries): RedirectResponse
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'sales.riders.handover', $store);
        $data = $request->validate(['rider_id' => ['required', 'integer'], 'received' => ['required', 'numeric', 'min:0'], 'shift_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:300']]);
        $handover = $deliveries->handover($request->user(), $store->id, $data['rider_id'], (string) $data['received'], $data['shift_id'] ?? null, $data['note'] ?? null);

        return back()->with('success', "Received Rs {$handover->received_amount} from rider".(\App\Support\Decimal::isZero($handover->variance) ? '.' : " (difference Rs {$handover->variance})."));
    }
}
