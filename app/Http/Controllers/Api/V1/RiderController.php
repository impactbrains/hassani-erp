<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sales\DeliveryService;
use App\Models\Delivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rider app: my deliveries, mark delivered (with cash collected) or failed, and my cash in hand. */
class RiderController extends ApiController
{
    public function deliveries(Request $request): JsonResponse
    {
        $rider = $this->rider($request);

        return response()->json(['data' => Delivery::query()->where('rider_id', $rider->id)->whereIn('status', ['assigned', 'out_for_delivery'])
            ->with(['salesOrder:id,number,delivery_address,delivery_area,delivery_phone,customer_id,notes', 'salesOrder.customer:id,name,name_ur', 'invoice:id,number,total,amount_paid', 'invoice.lines:id,sales_invoice_id,description,description_ur,quantity,line_total'])
            ->orderBy('assigned_at')->get()]);
    }

    public function update(Request $request, Delivery $delivery, DeliveryService $deliveries): JsonResponse
    {
        $rider = $this->rider($request);
        abort_unless($delivery->rider_id === $rider->id, 404);
        $data = $request->validate(['outcome' => ['required', 'in:delivered,failed'], 'collected' => ['nullable', 'numeric', 'min:0'], 'reason' => ['nullable', 'required_if:outcome,failed', 'string', 'max:300'], 'note' => ['nullable', 'string', 'max:300']]);
        $user = $this->staff($request);
        $delivery = $data['outcome'] === 'delivered'
            ? $deliveries->markDelivered($user, $delivery, (string) ($data['collected'] ?? '0'), $data['note'] ?? null, $request->header('Idempotency-Key'))
            : $deliveries->markFailed($user, $delivery, $data['reason']);

        return response()->json(['data' => $delivery->only(['id', 'number', 'status', 'collected_amount', 'delivered_at'])]);
    }

    public function cash(Request $request, DeliveryService $deliveries): JsonResponse
    {
        return response()->json(['data' => ['cash_with_rider' => $deliveries->cashWithRider($this->rider($request)->id)]]);
    }

    private function rider(Request $request): \App\Models\Contact
    {
        $user = $this->staff($request);
        abort_unless($user->tokenCan('rider') && $user->hasPermission('sales.deliveries.rider'), 403);

        return $user->employee ?? abort(403, 'This login is not linked to an employee (rider) record.');
    }
}
