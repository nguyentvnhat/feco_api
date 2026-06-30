<?php

namespace Modules\Order\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;

/**
 * Mốc chốt commission: đơn đã từng qua {@see OrderStatus::READY_TO_SHIP}.
 * Status vận chuyển sau giữ entry; chỉ {@see OrderStatus::commissionReversalValues()} mới gỡ.
 */
final class OrderCommissionEligibility
{
    public static function shouldReverseCommission(Order $order): bool
    {
        return in_array((string) $order->statusValue(), OrderStatus::commissionReversalValues(), true);
    }

    public static function shouldCommitCommission(Order $order): bool
    {
        if (self::shouldReverseCommission($order)) {
            return false;
        }

        return self::everReachedReadyToShip($order);
    }

    public static function everReachedReadyToShip(Order $order): bool
    {
        if (in_array((string) $order->statusValue(), OrderStatus::soldLikeValues(), true)) {
            return true;
        }

        if (Schema::hasTable('order_status_histories')) {
            $reachedInHistory = DB::table('order_status_histories')
                ->where('order_id', (int) $order->id)
                ->where('to_status', OrderStatus::READY_TO_SHIP->value)
                ->exists();

            if ($reachedInHistory) {
                return true;
            }
        }

        if (Schema::hasTable('commission_entries')) {
            return DB::table('commission_entries')
                ->where('source_order_id', (int) $order->id)
                ->exists();
        }

        return false;
    }

    public static function requiresReadyToShipCommit(string $status): bool
    {
        return in_array($status, OrderStatus::shippingProgressionValues(), true);
    }

    public static function assertTransitionAllowed(Order $order, string $newStatus): void
    {
        if (! self::requiresReadyToShipCommit($newStatus)) {
            return;
        }

        if (self::everReachedReadyToShip($order)) {
            return;
        }

        throw ValidationException::withMessages([
            'order_status' => [__('api.order.shipping_requires_ready_to_ship')],
        ]);
    }
}
