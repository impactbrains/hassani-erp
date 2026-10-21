<?php

namespace App\Domain\Pos;

use App\Exceptions\BusinessRuleException;
use App\Models\PosWorkspace;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the cashier's open cart tabs on the server, so a refresh returns to the same
 * terminal, shift and carts.
 *
 * Rule for two screens: every save carries the version the screen last saw. If another
 * screen saved in between, the save is refused with `workspace_conflict` and the newer
 * carts, and the cashier chooses "Load those" or "Keep these" (a forced save). Nothing is
 * ever merged silently, and approval tokens are never stored.
 */
final class PosWorkspaceService
{
    public const MAX_TABS = 10;

    public function forUser(User $user, int $storeId): ?PosWorkspace
    {
        return PosWorkspace::query()->where('store_id', $storeId)->where('user_id', $user->id)->first();
    }

    /**
     * @param  array<string, mixed>  $state  {carts: list<array>, active_id: string}
     * @return array{version: int}
     */
    public function save(User $user, int $storeId, array $state, ?int $baseVersion, ?int $registerId, bool $force = false): array
    {
        $state = $this->clean($state);

        return DB::transaction(function () use ($user, $storeId, $state, $baseVersion, $registerId, $force) {
            $existing = PosWorkspace::query()->where('store_id', $storeId)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing === null) {
                $created = PosWorkspace::create(['store_id' => $storeId, 'user_id' => $user->id, 'register_id' => $registerId, 'state' => $state, 'version' => 1]);

                return ['version' => $created->version];
            }
            // A screen that saw no saved carts may save over an empty row (e.g. one made by joining a shift),
            // but never over carts another screen has already saved.
            $stale = $baseVersion === null ? ((array) ($existing->state['carts'] ?? [])) !== [] : $existing->version !== $baseVersion;
            if (! $force && $stale) {
                throw new BusinessRuleException('These carts were changed on another screen.', 'workspace_conflict', ['version' => $existing->version, 'state' => $existing->state]);
            }
            $existing->update(['state' => $state, 'register_id' => $registerId ?? $existing->register_id, 'version' => $existing->version + 1]);

            return ['version' => $existing->version];
        });
    }

    /** Remembers the shift a cashier joined, so a refresh does not drop them back to "no shift". */
    public function rememberShift(User $user, int $storeId, Shift $shift): void
    {
        $existing = PosWorkspace::query()->where('store_id', $storeId)->where('user_id', $user->id)->first();
        if ($existing) {
            $existing->update(['shift_id' => $shift->id, 'register_id' => $shift->register_id]);

            return;
        }
        PosWorkspace::create(['store_id' => $storeId, 'user_id' => $user->id, 'shift_id' => $shift->id, 'register_id' => $shift->register_id, 'state' => ['carts' => [], 'active_id' => null], 'version' => 1]);
    }

    /**
     * Keeps only what a cart needs to come back: lines, customer, discount, removed lines and
     * the sale's idempotency key. Quotes, errors and approval tokens are dropped on purpose.
     *
     * @param  array<string, mixed>  $state
     * @return array{carts: list<array<string, mixed>>, active_id: ?string}
     */
    private function clean(array $state): array
    {
        $carts = [];
        foreach (array_slice((array) ($state['carts'] ?? []), 0, self::MAX_TABS) as $cart) {
            if (! is_array($cart) || empty($cart['id'])) {
                continue;
            }
            $lines = array_slice((array) ($cart['lines'] ?? []), 0, 300);
            foreach ($lines as &$line) {
                unset($line['price_token']);
            }
            unset($line);
            $carts[] = [
                'id' => (string) $cart['id'],
                'number' => (int) ($cart['number'] ?? 1),
                'lines' => array_values($lines),
                'removed' => array_slice((array) ($cart['removed'] ?? []), 0, 200),
                'customer' => $cart['customer'] ?? null,
                'cartDiscount' => $cart['cartDiscount'] ?? null,
                'idempotencyKey' => (string) ($cart['idempotencyKey'] ?? ''),
            ];
        }

        return ['carts' => $carts, 'active_id' => isset($state['active_id']) ? (string) $state['active_id'] : null];
    }
}
