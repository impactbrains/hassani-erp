<?php

namespace App\Domain\Pos;

use App\Exceptions\BusinessRuleException;
use App\Models\HeldCart;
use App\Models\Register;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Hold / resume carts. Resuming locks the held cart so two POS counters can never
 * pick up the same cart, and expired carts cannot be resumed.
 */
final class HeldCartService
{
    /** @param array<string, mixed> $cart */
    public function hold(User $user, Register $register, array $cart, ?string $label, ?int $customerId, string $estimatedTotal): HeldCart
    {
        if (! $user->hasPermission('pos.hold', $register->store_id)) {
            throw new BusinessRuleException('You are not allowed to hold carts.', 'hold_not_permitted');
        }
        if (empty($cart['lines'])) {
            throw new BusinessRuleException('There is nothing in the cart to hold.', 'empty_cart');
        }

        $held = HeldCart::create([
            'store_id' => $register->store_id,
            'register_id' => $register->id,
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'label' => $label ?: 'Cart '.now()->setTimezone($register->store->timezone())->format('H:i'),
            'cart' => $cart,
            'estimated_total' => Decimal::money($estimatedTotal),
            'status' => 'held',
            'expires_at' => now()->addHours((int) Settings::get('pos.held_cart_hours', $register->store_id)),
        ]);
        Audit::record('pos.cart_held', "Held cart {$held->label}", $held);

        return $held;
    }

    public function resume(User $user, int $heldCartId, int $storeId): HeldCart
    {
        return DB::transaction(function () use ($user, $heldCartId, $storeId) {
            $held = HeldCart::query()->where('store_id', $storeId)->lockForUpdate()->find($heldCartId);
            if ($held === null || $held->status !== 'held') {
                throw new BusinessRuleException('This cart was already resumed or discarded on another POS.', 'cart_unavailable');
            }
            if ($held->expires_at->isPast()) {
                $held->update(['status' => 'discarded']);
                throw new BusinessRuleException('This held cart has expired. Scan the items again.', 'cart_expired');
            }
            $held->update(['status' => 'resumed', 'claimed_by' => $user->id, 'claimed_at' => now()]);
            Audit::record('pos.cart_resumed', "Resumed cart {$held->label}", $held);

            return $held;
        });
    }

    public function discard(User $user, int $heldCartId, int $storeId, ?string $reason): void
    {
        DB::transaction(function () use ($user, $heldCartId, $storeId, $reason) {
            $held = HeldCart::query()->where('store_id', $storeId)->lockForUpdate()->findOrFail($heldCartId);
            if ($held->status !== 'held') {
                return;
            }
            $held->update(['status' => 'discarded', 'claimed_by' => $user->id, 'claimed_at' => now()]);
            Audit::record('pos.cart_discarded', "Discarded held cart {$held->label}", $held, new: ['reason' => $reason, 'estimated_total' => $held->estimated_total]);
        });
    }
}
