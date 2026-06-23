<?php

namespace Modules\Order\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProductUnitConverter
{
    public function resolveSaleUnit(object $product): string
    {
        if (Schema::hasColumn('products', 'sale_unit')) {
            $saleUnit = trim((string) ($product->sale_unit ?? ''));
            if ($saleUnit !== '') {
                return $saleUnit;
            }
        }

        $productId = (int) ($product->id ?? 0);
        $baseUnit = $this->resolveBaseUnit($product);

        // App đặt theo hộp; nếu DB chưa có cột sale_unit, suy ra từ quy đổi box → thanh.
        if ($productId > 0 && Schema::hasTable('product_unit_conversions')) {
            $hasBoxToBase = DB::table('product_unit_conversions')
                ->where('product_id', $productId)
                ->where('from_unit', 'box')
                ->where('to_unit', $baseUnit)
                ->exists();

            if ($hasBoxToBase) {
                return 'box';
            }
        }

        return $baseUnit;
    }

    public function resolveBaseUnit(object $product): string
    {
        return trim((string) ($product->base_unit ?? 'bar')) ?: 'bar';
    }

    public function toBaseUnitQuantity(int $productId, string $baseUnit, string $fromUnit, float $quantity): float
    {
        if ($fromUnit === $baseUnit) {
            return round($quantity, 4);
        }

        if (! Schema::hasTable('product_unit_conversions')) {
            throw new RuntimeException(
                "Missing product_unit_conversions table for product_id={$productId}."
            );
        }

        $multiplier = DB::table('product_unit_conversions')
            ->where('product_id', $productId)
            ->where('from_unit', $fromUnit)
            ->where('to_unit', $baseUnit)
            ->value('multiplier');

        if ($multiplier === null) {
            throw new RuntimeException(
                "Missing unit conversion for product_id={$productId} from {$fromUnit} to {$baseUnit}."
            );
        }

        return round($quantity * (float) $multiplier, 4);
    }

    /**
     * @return array{unit: string, quantity: float, quantity_in_base_unit: float}
     */
    public function buildOrderLineQuantities(object $product, float $saleQuantity): array
    {
        $saleUnit = $this->resolveSaleUnit($product);
        $baseUnit = $this->resolveBaseUnit($product);
        $productId = (int) ($product->id ?? 0);

        return [
            'unit' => $saleUnit,
            'quantity' => $saleQuantity,
            'quantity_in_base_unit' => $this->toBaseUnitQuantity($productId, $baseUnit, $saleUnit, $saleQuantity),
        ];
    }
}
