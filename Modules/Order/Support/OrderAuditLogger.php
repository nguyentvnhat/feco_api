<?php

namespace Modules\Order\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;

/**
 * Ghi audit_logs (cùng bảng admin) khi thao tác đơn từ API mobile.
 */
final class OrderAuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function log(
        string $action,
        Order $order,
        ?array $oldValues,
        ?array $newValues,
        ?Request $request = null,
    ): void {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => Order::class,
            'entity_id' => $order->id,
            'old_values' => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Order $order): array
    {
        $order->loadMissing(['items' => fn ($q) => $q->orderBy('id'), 'shippingAddress']);

        $shipping = $order->shippingAddress;

        return [
            'order_no' => $order->order_no,
            'order_date' => $order->order_date?->format('c'),
            'order_month' => $order->order_month,
            'agent_profile_id' => $order->agent_profile_id,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $shipping ? [
                'full_name' => $shipping->full_name,
                'phone' => $shipping->phone,
                'address_line' => $shipping->address_line,
                'province_name' => $shipping->province_name,
                'ward_name' => $shipping->ward_name,
            ] : null,
            'order_channel' => $order->order_channel,
            'order_status' => $order->statusValue(),
            'subtotal_amount' => (string) $order->subtotal_amount,
            'discount_amount' => (string) $order->discount_amount,
            'net_amount' => (string) $order->net_amount,
            'monthly_qty_before' => $order->monthly_qty_before !== null ? (string) $order->monthly_qty_before : null,
            'monthly_qty_after' => $order->monthly_qty_after !== null ? (string) $order->monthly_qty_after : null,
            'items' => $order->items->map(static function (OrderItem $item): array {
                return [
                    'product_id' => $item->product_id,
                    'product_name_snapshot' => $item->product_name_snapshot,
                    'unit' => $item->unit,
                    'quantity' => (string) $item->quantity,
                    'quantity_in_base_unit' => (string) $item->quantity_in_base_unit,
                    'unit_price' => (string) $item->unit_price,
                    'line_amount' => (string) $item->line_amount,
                ];
            })->values()->all(),
        ];
    }
}
