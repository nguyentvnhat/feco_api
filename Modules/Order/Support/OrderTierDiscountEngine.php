<?php

namespace Modules\Order\Support;

/**
 * Progressive / flat tier discount on quantity ladder (shared by preview + store).
 *
 * Progressive: split current-order quantity across tiers by absolute position
 * (monthly_qty_before + 1 .. monthly_qty_before + current_qty), allocate subtotal
 * proportionally by applied_qty / current_qty per slice (same as admin progressive).
 *
 * Flat: pick single tier containing the ending position; apply that tier's percent once
 * to the full order subtotal.
 */
final class OrderTierDiscountEngine
{
    private const BC = 8;

    private const TIER_MAX_INFINITY = '999999999999999.9999';

    /**
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
    public static function computeProgressivePercent(
        int $commissionPolicyId,
        string $monthlyQtyBefore,
        string $currentOrderQty,
        string $subtotal,
        array $tiers,
        string $monthlyQtyAfter,
    ): array {
        if (bccomp($currentOrderQty, '0', 4) <= 0 || $tiers === []) {
            return self::emptyResult($subtotal);
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

            $tierBasis = '0';
            if (bccomp($currentOrderQty, '0', 4) > 0) {
                $tierBasis = bcdiv(
                    bcmul(self::toBc($subtotal, '2'), $appliedQty, self::BC + 4),
                    $currentOrderQty,
                    self::BC
                );
            }

            $pct = self::toBc((string) $rewardPercent, '4');
            $discountLine = bcdiv(
                bcmul($tierBasis, $pct, self::BC + 4),
                '100',
                self::BC
            );

            $tierBasisRounded = self::moneyFormat2($tierBasis);
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
                ],
            ];
        }

        $totalDiscountRounded = self::moneyFormat2($totalDiscountSum);
        $netBc = bcsub(self::toBc($subtotal, '2'), self::toBc($totalDiscountRounded, '2'), self::BC);

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
    public static function computeFlatPercent(
        int $commissionPolicyId,
        string $monthlyQtyBefore,
        string $currentOrderQty,
        string $subtotal,
        array $tiers,
        string $monthlyQtyAfter,
    ): array {
        if (bccomp($currentOrderQty, '0', 4) <= 0 || $tiers === []) {
            return self::emptyResult($subtotal);
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
            return self::emptyResult($subtotal);
        }

        $pct = self::toBc((string) ($selected['reward_percent'] ?? '0'), '4');
        if (bccomp($pct, '0', 4) <= 0) {
            return self::emptyResult($subtotal);
        }

        $discountLine = bcdiv(
            bcmul(self::toBc($subtotal, '2'), $pct, self::BC + 4),
            '100',
            self::BC
        );
        $discountRounded = self::moneyFormat2($discountLine);
        $netBc = bcsub(self::toBc($subtotal, '2'), self::toBc($discountRounded, '2'), self::BC);

        $breakdowns = [[
            'commission_policy_id' => $commissionPolicyId,
            'commission_policy_tier_id' => (int) $selected['id'],
            'qty_from' => self::qtyFormat4($sliceStart),
            'qty_to' => self::qtyFormat4($sliceEnd),
            'applied_qty' => self::qtyFormat4($currentOrderQty),
            'reward_percent' => self::moneyFormat2($pct),
            'basis_amount' => self::moneyFormat2($subtotal),
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
            ],
        ]];

        return [
            'breakdowns' => $breakdowns,
            'total_discount_amount' => $discountRounded,
            'net_amount' => self::moneyFormat2($netBc),
        ];
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
