<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\CustomerService;
use App\Domain\Sales\SalesOrderService;
use App\Models\Contact;
use App\Models\ReasonCode;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Orders from the website/app. Every write needs an Idempotency-Key header;
 * retrying with the same key returns the same order instead of creating a
 * second one. Orders are confirmed straight away (stock reserved); payment
 * is cash on delivery or a wallet transfer verified by staff.
 */
class OrderController extends ApiController
{
    public function customer(Request $request, CustomerService $customers): JsonResponse
    {
        $this->ability($request, 'customers:write');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:500'], 'area' => ['nullable', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:80'], 'preferred_language' => ['nullable', 'in:en,ur'], 'marketing_consent' => ['boolean']]);
        $existing = Contact::query()->customers()->where('phone_normalized', Contact::normalizePhone($data['phone']))->first();
        $customer = $existing ?? $customers->save($this->systemUser($request), $data + ['category' => 'retail', 'confirm_not_duplicate' => true]);

        return response()->json(['data' => ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name, 'existing' => (bool) $existing]], $existing ? 200 : 201);
    }

    public function store(Request $request, SalesOrderService $orders): JsonResponse
    {
        $this->ability($request, 'orders:write');
        $key = (string) $request->header('Idempotency-Key');
        abort_if(strlen($key) < 8 || strlen($key) > 64, 422, 'Send an Idempotency-Key header (8–64 characters) with every order.');
        $client = $this->client($request);
        $data = $request->validate([
            'customer_id' => ['required', 'integer'], 'fulfillment_type' => ['required', 'in:pickup,delivery'], 'delivery_address' => ['nullable', 'required_if:fulfillment_type,delivery', 'string', 'max:500'],
            'delivery_area' => ['nullable', 'string', 'max:120'], 'delivery_phone' => ['nullable', 'string', 'max:30'], 'requested_delivery_at' => ['nullable', 'date'], 'payment_preference' => ['required', 'in:cod,wallet'],
            'notes' => ['nullable', 'string', 'max:1000'], 'external_reference' => ['nullable', 'string', 'max:80'],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.variant_id' => ['required', 'integer'], 'lines.*.variant_unit_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000'],
        ]);
        $existing = SalesOrder::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            return response()->json(['data' => $this->present($existing)]);
        }
        $order = DB::transaction(function () use ($orders, $client, $data, $key) {
            $store = \App\Models\Store::query()->findOrFail($client->store_id);
            $order = $orders->save(null, $store, $data + ['channel' => 'ecommerce', 'idempotency_key' => $key, 'api_client_id' => $client->id]);

            return $orders->confirm(null, $order);
        });

        return response()->json(['data' => $this->present($order->fresh())], 201);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $this->ability($request, 'orders:read');
        $order = SalesOrder::query()->where('number', $number)->where('api_client_id', $this->client($request)->id)->firstOrFail();

        return response()->json(['data' => $this->present($order)]);
    }

    public function cancel(Request $request, string $number, SalesOrderService $orders): JsonResponse
    {
        $this->ability($request, 'orders:write');
        $order = SalesOrder::query()->where('number', $number)->where('api_client_id', $this->client($request)->id)->firstOrFail();
        $reason = ReasonCode::query()->where('type', 'order_cancel')->where('code', 'customer_cancelled')->value('id') ?? ReasonCode::query()->where('type', 'order_cancel')->value('id');
        $orders->cancel(null, $order, $reason);

        return response()->json(['data' => $this->present($order->fresh())]);
    }

    /** @return array<string, mixed> */
    private function present(SalesOrder $order): array
    {
        $order->loadMissing('lines', 'deliveries:id,sales_order_id,status,rider_id,delivered_at');

        return [
            'number' => $order->number, 'status' => $order->status, 'customer_id' => $order->customer_id, 'fulfillment_type' => $order->fulfillment_type,
            'payment_preference' => $order->payment_preference, 'subtotal' => $order->subtotal, 'discount_total' => $order->discount_total, 'tax_total' => $order->tax_total,
            'delivery_charge' => $order->delivery_charge, 'total' => $order->total, 'external_reference' => $order->external_reference, 'created_at' => $order->created_at,
            'lines' => $order->lines->map(fn ($l) => ['variant_id' => $l->variant_id, 'variant_unit_id' => $l->variant_unit_id, 'description' => $l->description ?? null, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'line_total' => $l->line_total]),
            'delivery' => $order->deliveries->last()?->only(['status', 'delivered_at']),
        ];
    }

    /** Customers created through the API are recorded as created by the owner account that issued the key. */
    private function systemUser(Request $request): User
    {
        return User::query()->findOrFail($this->client($request)->created_by);
    }
}
