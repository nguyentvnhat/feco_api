<?php

namespace Modules\Order\Support;

use Modules\Order\Models\Order;

class OrderVatBreakdown
{
    /**
     * @return array{
     *     pre_tax_subtotal: float,
     *     discount_amount: float,
     *     discount_percent: float|null,
     *     vat_base: float,
     *     vat_rate_percent: float,
     *     vat_amount: float,
     *     total_with_vat: float
     * }
     */
    public static function compute(float $subtotal, float $discount, float $vatRatePercent): array
    {
        $subtotal = round($subtotal, 2);
        $discount = round($discount, 2);
        $rate = round(max(0.0, min(100.0, $vatRatePercent)), 2);
        $vatBase = round(max(0.0, $subtotal - $discount), 2);
        $vatAmount = round($vatBase * $rate / 100, 2);
        $totalWithVat = round($vatBase + $vatAmount, 2);

        $discountPercent = null;
        if ($subtotal > 0.00001 && $discount > 0.00001) {
            $discountPercent = round($discount / $subtotal * 100, 2);
            if (abs($discountPercent - round($discountPercent)) < 0.01) {
                $discountPercent = (float) (int) round($discountPercent);
            }
        }

        return [
            'pre_tax_subtotal' => $subtotal,
            'discount_amount' => $discount,
            'discount_percent' => $discountPercent,
            'vat_base' => $vatBase,
            'vat_rate_percent' => $rate,
            'vat_amount' => $vatAmount,
            'total_with_vat' => $totalWithVat,
        ];
    }

    public static function resolveRateForOrder(?Order $order): float
    {
        if ($order !== null && $order->vat_rate_percent !== null && $order->vat_rate_percent !== '') {
            return round((float) $order->vat_rate_percent, 2);
        }

        return OrderVatSettings::currentRatePercent();
    }

    /**
     * @return array{vat_rate_percent: float, vat_amount: float, total_with_vat: float}
     */
    public static function persistFields(float $subtotal, float $discount, ?Order $order = null): array
    {
        $rate = self::resolveRateForOrder($order);
        $computed = self::compute($subtotal, $discount, $rate);

        return [
            'vat_rate_percent' => $computed['vat_rate_percent'],
            'vat_amount' => $computed['vat_amount'],
            'total_with_vat' => $computed['total_with_vat'],
        ];
    }

    public static function fromOrder(Order $order): array
    {
        $rate = self::resolveRateForOrder($order);
        $computed = self::compute(
            (float) $order->subtotal_amount,
            (float) $order->discount_amount,
            $rate
        );

        if ($order->vat_amount !== null && $order->vat_amount !== '') {
            $computed['vat_amount'] = round((float) $order->vat_amount, 2);
        }
        if ($order->total_with_vat !== null && $order->total_with_vat !== '') {
            $computed['total_with_vat'] = round((float) $order->total_with_vat, 2);
        }

        return $computed;
    }

    /**
     * @param  callable(\Modules\Order\Models\OrderItem): array{line_amount: float}  $itemLinePricing
     * @return array{
     *     pre_tax_subtotal: float,
     *     discount_amount: float,
     *     discount_percent: float|null,
     *     vat_base: float,
     *     vat_rate_percent: float,
     *     vat_amount: float,
     *     total_with_vat: float
     * }
     */
    public static function fromOrderWithItemPricing(Order $order, callable $itemLinePricing): array
    {
        $recalculatedSubtotal = round($order->items->sum(function ($item) use ($itemLinePricing) {
            return $itemLinePricing($item)['line_amount'];
        }), 2);

        $storedSubtotal = round((float) $order->subtotal_amount, 2);
        $storedDiscount = round((float) $order->discount_amount, 2);

        if (abs($recalculatedSubtotal - $storedSubtotal) <= 0.01) {
            return self::fromOrder($order);
        }

        $discount = $storedDiscount;
        if ($storedSubtotal > 0.00001 && $storedDiscount > 0.00001) {
            $discount = round($storedDiscount * ($recalculatedSubtotal / $storedSubtotal), 2);
        }

        return self::compute(
            $recalculatedSubtotal,
            $discount,
            self::resolveRateForOrder($order)
        );
    }
}
