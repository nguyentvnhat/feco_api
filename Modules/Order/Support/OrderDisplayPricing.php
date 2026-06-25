<?php

namespace Modules\Order\Support;

use Illuminate\Support\Facades\DB;
use Modules\Order\App\Services\ProductUnitConverter;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;

class OrderDisplayPricing
{
    public function __construct(
        private readonly ProductUnitConverter $converter
    ) {}

    /**
     * @return array{unit_price_per_bar: float, line_amount: float}
     */
    public function resolveItemBarPricing(OrderItem $item): array
    {
        $baseQty = (float) $item->quantity_in_base_unit;
        $saleQty = (float) $item->quantity;
        $storedUnitPrice = (float) $item->unit_price;
        $barsPerSale = ($saleQty > 0 && $baseQty > 0) ? ($baseQty / $saleQty) : 1.0;

        $unitPricePerBar = $storedUnitPrice;

        if ($item->product_id) {
            $product = DB::table('products')->where('id', $item->product_id)->first();
            if ($product) {
                $listBar = $this->converter->baseUnitListPrice($product);
                $saleBox = $listBar !== null ? $this->converter->saleUnitListPrice($product, $listBar) : null;

                if ($listBar !== null && abs($storedUnitPrice - $listBar) < 0.01) {
                    $unitPricePerBar = $storedUnitPrice;
                } elseif ($saleBox !== null && abs($storedUnitPrice - $saleBox) < 0.01 && $barsPerSale > 0) {
                    $unitPricePerBar = round($storedUnitPrice / $barsPerSale, 2);
                }
            }
        }

        return [
            'unit_price_per_bar' => $unitPricePerBar,
            'line_amount' => round($baseQty * $unitPricePerBar, 2),
        ];
    }

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
    public function paymentBreakdown(Order $order): array
    {
        $order->loadMissing('items');

        return OrderVatBreakdown::fromOrderWithItemPricing(
            $order,
            fn (OrderItem $item): array => $this->resolveItemBarPricing($item)
        );
    }

    public function netAmountBeforeVat(Order $order): float
    {
        return $this->paymentBreakdown($order)['vat_base'];
    }
}
