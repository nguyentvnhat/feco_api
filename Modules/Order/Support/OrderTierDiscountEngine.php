<?php

namespace Modules\Order\Support;

/**
 * Tier discount theo **số lượng** (min_value / max_value trên commission_policy_tiers).
 *
 * Progressive: chia số lượng đơn hiện tại theo nấc (có thể gồm nhiều dòng sản phẩm); mỗi nấc
 * discount = (tổng đơn giá×số lượng thuộc nấc đó) × reward_percent / 100.
 *
 * Flat: chọn một nấc theo vị trí kết thúc cộng dồn; discount = subtotal × reward_percent / 100.
 */
final class OrderTierDiscountEngine
{
    private const BC = 8;

    private const TIER_MAX_INFINITY = '999999999999999.9999';

    /**
     * @param  list<array{quantity_in_base_unit?:string|float|int|null, quantity?:string|float|int|null, unit_price?:string|float|int|null}>  $lines
     * @param  list<array{id:int,min_value:?string,max_value:?string,reward_percent:?string}>  $tiers
     * @return array{
     *     breakdowns: list<array{
     *         commission_policy_id:int,
     *         commission_policy_tier_id:int,
     *         qty_from:string,
     *         qty_to:string,
     *         applied_qty:string,
     *         reward_percent:string,
     *         basis_amount:string,
     *         discount_amount:string,
     *         snapshot_json: array<string, mixed>
     *     }>,
     *     total_discount_amount: string,
     *     net_amount: string
     * }
     */
    public static function computeProgressivePercentFromLines(
        int $commissionPolicyId,
        string $monthlyQtyBefore,
        string $currentOrderQty,
        string $subtotalBc,
        array $tiers,
        string $monthlyQtyAfter,
        array $lines,
    ): array {
        if (bccomp($currentOrderQty, '0', 4) <= 0 || $tiers === []) {
            return self::emptyResult($subtotalBc);
        }

        $sliceStart = self::bcAdd($monthlyQtyBefore, '1', 4);
        $sliceEnd = $monthlyQtyAfter;

        $breakdowns = [];
        $totalDiscountSum = '0';

        foreach ($tiers as $tier) {
            $tierMin = self::toBc($tier['min_value'] ?? '0', '4');
            $tierMax = isset($tier['max_value']) && $tier['max_value'] !== null && $tier['max_value'] !== ''
                ? self::toBc((string) $tier['max_value'], '4')
                : self::TIER_MAX_INFINITY;

            $rewardPercent = $tier['reward_percent'] ?? null;
            if ($rewardPercent === null || bccomp(self::toBc((string) $rewardPercent, '4'), '0', 4) <= 0) {
                continue;
            }

            $overlapStart = self::bcMax($sliceStart, $tierMin, 4);
            $overlapEnd = self::bcMin($sliceEnd, $tierMax, 4);

            if (bccomp($overlapStart, $overlapEnd, 4) > 0) {
                continue;
            }

            $appliedQty = self::bcAdd(self::bcSub($overlapEnd, $overlapStart, 4), '1', 4);

            $kStart = self::bcSub($overlapStart, $monthlyQtyBefore, 4);
            $kEnd = self::bcSub($overlapEnd, $monthlyQtyBefore, 4);
            $kStart = self::bcMax($kStart, '1', 4);
            $kEnd = self::bcMin($kEnd, $currentOrderQty, 4);
            if (bccomp($kStart, $kEnd, 4) > 0) {
                continue;
            }

            $tierBasisRaw = self::basisMoneyForOrderUnitInterval($lines, $kStart, $kEnd);
            $pct = self::toBc((string) $rewardPercent, '4');
            $discountLine = bcdiv(
                bcmul($tierBasisRaw, $pct, self::BC + 4),
                '100',
                self::BC
            );

            $tierBasisRounded = self::moneyFormat2($tierBasisRaw);
            $discountLineRounded = self::moneyFormat2($discountLine);
            $totalDiscountSum = bcadd($totalDiscountSum, $discountLineRounded, 2);

            $breakdowns[] = [
                'commission_policy_id' => $commissionPolicyId,
                'commission_policy_tier_id' => (int) $tier['id'],
                'qty_from' => self::qtyFormat4($overlapStart),
                'qty_to' => self::qtyFormat4($overlapEnd),
                'applied_qty' => self::qtyFormat4($appliedQty),
                'reward_percent' => self::moneyFormat2($pct),
                'basis_amount' => $tierBasisRounded,
                'discount_amount' => $discountLineRounded,
                'snapshot_json' => [
                    'monthly_qty_before' => self::qtyFormat4($monthlyQtyBefore),
                    'monthly_qty_after' => self::qtyFormat4($monthlyQtyAfter),
                    'slice_start' => self::qtyFormat4($sliceStart),
                    'slice_end' => self::qtyFormat4($sliceEnd),
                    'tier_min' => self::qtyFormat4($tierMin),
                    'tier_max' => isset($tier['max_value']) && $tier['max_value'] !== null && $tier['max_value'] !== ''
                        ? self::qtyFormat4($tierMax)
                        : null,
                    'calculation_method' => 'progressive',
                    'basis_rule' => 'unit_price_times_quantity_in_tier',
                ],
            ];
        }

        $totalDiscountRounded = self::moneyFormat2($totalDiscountSum);
        $netBc = bcsub(self::toBc($subtotalBc, '2'), self::toBc($totalDiscountRounded, '2'), self::BC);

        return [
            'breakdowns' => $breakdowns,
            'total_discount_amount' => $totalDiscountRounded,
            'net_amount' => self::moneyFormat2($netBc),
        ];
    }

    /**
     * @param  list<array{id:int,min_value:?string,max_value:?string,reward_percent:?string}>  $tiers
     * @return array{
     *     breakdowns: list<array<string, mixed>>,
     *     total_discount_amount: string,
     *     net_amount: string
     * }
     */
    public static function computeFlatPercentFromLines(
        int $commissionPolicyId,
        string $monthlyQtyBefore,
        string $currentOrderQty,
        string $subtotalBc,
        array $tiers,
        string $monthlyQtyAfter,
    ): array {
        if (bccomp($currentOrderQty, '0', 4) <= 0 || $tiers === []) {
            return self::emptyResult($subtotalBc);
        }

        $sliceStart = self::bcAdd($monthlyQtyBefore, '1', 4);
        $sliceEnd = $monthlyQtyAfter;

        $selected = null;
        foreach (array_reverse($tiers) as $tier) {
            $tierMin = self::toBc($tier['min_value'] ?? '0', '4');
            $tierMax = isset($tier['max_value']) && $tier['max_value'] !== null && $tier['max_value'] !== ''
                ? self::toBc((string) $tier['max_value'], '4')
                : self::TIER_MAX_INFINITY;
            if (bccomp($sliceEnd, $tierMin, 4) < 0 || bccomp($sliceEnd, $tierMax, 4) > 0) {
                continue;
            }
            $selected = $tier + ['_tier_min' => $tierMin, '_tier_max' => $tierMax];
            break;
        }

        if ($selected === null) {
            return self::emptyResult($subtotalBc);
        }

        $pct = self::toBc((string) ($selected['reward_percent'] ?? '0'), '4');
        if (bccomp($pct, '0', 4) <= 0) {
            return self::emptyResult($subtotalBc);
        }

        $discountLine = bcdiv(
            bcmul(self::toBc($subtotalBc, '2'), $pct, self::BC + 4),
            '100',
            self::BC
        );
        $discountRounded = self::moneyFormat2($discountLine);
        $netBc = bcsub(self::toBc($subtotalBc, '2'), self::toBc($discountRounded, '2'), self::BC);

        $breakdowns = [[
            'commission_policy_id' => $commissionPolicyId,
            'commission_policy_tier_id' => (int) $selected['id'],
            'qty_from' => self::qtyFormat4($sliceStart),
            'qty_to' => self::qtyFormat4($sliceEnd),
            'applied_qty' => self::qtyFormat4($currentOrderQty),
            'reward_percent' => self::moneyFormat2($pct),
            'basis_amount' => self::moneyFormat2($subtotalBc),
            'discount_amount' => $discountRounded,
            'snapshot_json' => [
                'monthly_qty_before' => self::qtyFormat4($monthlyQtyBefore),
                'monthly_qty_after' => self::qtyFormat4($monthlyQtyAfter),
                'slice_start' => self::qtyFormat4($sliceStart),
                'slice_end' => self::qtyFormat4($sliceEnd),
                'tier_min' => self::qtyFormat4($selected['_tier_min']),
                'tier_max' => isset($selected['max_value']) && $selected['max_value'] !== null && $selected['max_value'] !== ''
                    ? self::qtyFormat4($selected['_tier_max'])
                    : null,
                'calculation_method' => 'flat',
                'basis_rule' => 'order_subtotal',
            ],
        ]];

        return [
            'breakdowns' => $breakdowns,
            'total_discount_amount' => $discountRounded,
            'net_amount' => self::moneyFormat2($netBc),
        ];
    }

    /**
     * Tiền gốc (chưa chiết khấu) của các **đơn vị** thứ kStart..kEnd trong giỏ (đếm từ 1 theo thứ tự dòng sản phẩm).
     *
     * @param  list<array{quantity_in_base_unit?:string|float|int|null, quantity?:string|float|int|null, unit_price?:string|float|int|null}>  $lines
     */
    private static function basisMoneyForOrderUnitInterval(array $lines, string $kStart, string $kEnd): string
    {
        if (bccomp($kStart, $kEnd, 4) > 0) {
            return '0';
        }

        $cum = '0';
        $basis = '0';

        foreach ($lines as $line) {
            $q = self::toBc((string) ($line['quantity_in_base_unit'] ?? $line['quantity'] ?? '0'), '4');
            $p = self::toBc((string) ($line['unit_price'] ?? '0'), '2');
            if (bccomp($q, '0', 4) <= 0) {
                continue;
            }

            $lineStart = self::bcAdd($cum, '1', 4);
            $lineEnd = self::bcAdd($cum, $q, 4);

            $ovS = self::bcMax($kStart, $lineStart, 4);
            $ovE = self::bcMin($kEnd, $lineEnd, 4);

            if (bccomp($ovS, $ovE, 4) <= 0) {
                $units = self::bcAdd(self::bcSub($ovE, $ovS, 4), '1', 4);
                $lineBasis = bcmul($p, $units, self::BC + 4);
                $basis = bcadd($basis, $lineBasis, self::BC);
            }

            $cum = self::bcAdd($cum, $q, 4);
        }

        return $basis;
    }

    /**
     * @return array{breakdowns: list<array<string, mixed>>, total_discount_amount: string, net_amount: string}
     */
    private static function emptyResult(string $subtotal): array
    {
        return [
            'breakdowns' => [],
            'total_discount_amount' => self::moneyFormat2('0'),
            'net_amount' => self::moneyFormat2($subtotal),
        ];
    }

    private static function toBc(string|float|int|null $value, string $scale): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $normalized = is_string($value) ? trim($value) : (string) $value;

        return bcadd($normalized, '0', (int) $scale);
    }

    private static function bcAdd(string $a, string $b, int $scale): string
    {
        return bcadd($a, $b, $scale);
    }

    private static function bcSub(string $a, string $b, int $scale): string
    {
        return bcsub($a, $b, $scale);
    }

    private static function bcMax(string $a, string $b, int $scale): string
    {
        return bccomp($a, $b, $scale) >= 0 ? $a : $b;
    }

    private static function bcMin(string $a, string $b, int $scale): string
    {
        return bccomp($a, $b, $scale) <= 0 ? $a : $b;
    }

    private static function qtyFormat4(string $v): string
    {
        return bcadd($v, '0', 4);
    }

    private static function moneyFormat2(string $v): string
    {
        $normalized = bcadd($v, '0', 8);

        return number_format(round((float) $normalized, 2), 2, '.', '');
    }
}
