<?php

namespace Modules\Order\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Support\AgentSelfPurchaseDiscount;
use Modules\Order\Support\OrderTierDiscountEngine;
use Modules\Order\Support\OrderVatBreakdown;

/**
 * Single pricing path for mobile order preview and order persistence (tier discount).
 */
class OrderPricingService
{
    private const DISCOUNT_POLICY_TYPES = ['discount'];

    /**
     * @param  list<array{
     *     product_id:int,
     *     product_name:string,
     *     unit:string,
     *     quantity:float|int|string,
     *     quantity_in_base_unit:float|int|string,
     *     unit_price:float|int|string,
     *     line_amount:float|int|string
     * }>  $lines
     * @return array<string, mixed>
     */
    public function buildPricingPayload(
        int $agentProfileId,
        string $orderDateYmd,
        string $orderMonthYm,
        array $lines,
        ?int $excludeOrderId = null,
        ?string $orderChannel = AgentSelfPurchaseDiscount::CHANNEL_AGENT_ORDER,
    ): array {
        $subtotal = $this->sumLineSubtotal($lines);
        $subtotalBc = $this->toBc((string) $subtotal, '2');

        $currentOrderQty = $this->sumLineQuantityBase($lines);

        $applyDiscount = AgentSelfPurchaseDiscount::qualifies($orderChannel, $agentProfileId);
        $policy = $applyDiscount ? $this->resolveActiveDiscountPolicyRow($agentProfileId, $orderDateYmd) : null;
        $eligibleStatuses = OrderStatus::soldLikeValues();

        $isMonthly = false;
        $monthlyQtyBefore = '0';

        if ($policy !== null) {
            $conditions = $this->decodeConditions($policy);
            $eligibleStatuses = $this->eligibleOrderStatuses($conditions);
            $isMonthly = $this->isMonthlyPolicy($policy);
            if ($isMonthly) {
                $monthlyQtyBefore = $this->sumMonthlyAccumulatedQuantity(
                    $agentProfileId,
                    $orderMonthYm,
                    $eligibleStatuses,
                    $excludeOrderId
                );
            }
        }

        $monthlyQtyAfter = bcadd($monthlyQtyBefore, $currentOrderQty, 4);

        if ($policy === null || bccomp($subtotalBc, '0', 2) <= 0 || bccomp($currentOrderQty, '0', 4) <= 0) {
            return $this->emptyPricingResponse(
                $lines,
                $subtotalBc,
                $monthlyQtyBefore,
                $monthlyQtyAfter,
                $eligibleStatuses,
                $orderMonthYm,
                $isMonthly,
                null
            );
        }

        $tiers = $this->loadPolicyTiers((int) $policy->id);
        if ($tiers === []) {
            return $this->emptyPricingResponse(
                $lines,
                $subtotalBc,
                $monthlyQtyBefore,
                $monthlyQtyAfter,
                $eligibleStatuses,
                $orderMonthYm,
                $isMonthly,
                $policy
            );
        }

        $conditions = $this->decodeConditions($policy);
        $calculationMethod = $conditions['calculation_method'] ?? 'progressive';
        if (! in_array($calculationMethod, ['progressive', 'flat'], true)) {
            $calculationMethod = 'progressive';
        }

        $engineResult = $calculationMethod === 'flat'
            ? OrderTierDiscountEngine::computeFlatPercentFromLines(
                (int) $policy->id,
                $monthlyQtyBefore,
                $currentOrderQty,
                $subtotalBc,
                $tiers,
                $monthlyQtyAfter
            )
            : OrderTierDiscountEngine::computeProgressivePercentFromLines(
                (int) $policy->id,
                $monthlyQtyBefore,
                $currentOrderQty,
                $subtotalBc,
                $tiers,
                $monthlyQtyAfter,
                $lines
            );

        $policyPayload = $this->formatPolicyPayload($policy, $calculationMethod, $isMonthly);

        return [
            'policy' => $policyPayload,
            'monthly_context' => [
                'is_monthly' => $isMonthly,
                'order_month' => $orderMonthYm,
                'calculation_base' => (string) ($policy->calculation_base ?? 'quantity'),
                'previous_month_quantity' => $this->qtyDisplay($monthlyQtyBefore),
                'monthly_qty_before' => $this->qtyDisplay($monthlyQtyBefore),
                'monthly_qty_after' => $this->qtyDisplay($monthlyQtyAfter),
                'eligible_order_statuses' => $eligibleStatuses,
            ],
            'items' => $this->formatLineItems($lines),
            'applied_tiers' => $this->formatAppliedTiersForApi($engineResult['breakdowns']),
            'breakdown_rows' => $this->formatBreakdownRowsForDb($engineResult['breakdowns']),
            'summary' => $this->summaryWithVat(
                (float) $subtotalBc,
                (float) $engineResult['total_discount_amount'],
                (float) $engineResult['net_amount'],
            ),
            'applied_discount_policy_id' => (int) $policy->id,
            'monthly_qty_before' => $this->qtyDisplay($monthlyQtyBefore),
            'monthly_qty_after' => $this->qtyDisplay($monthlyQtyAfter),
            'discount_snapshot_json' => $this->buildDiscountSnapshotJson(
                $policy,
                $calculationMethod,
                $monthlyQtyBefore,
                $monthlyQtyAfter,
                $engineResult
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $breakdownRows  from buildPricingPayload()['breakdown_rows']
     */
    public function persistDiscountBreakdowns(int $orderId, array $breakdownRows): void
    {
        if (! Schema::hasTable('order_discount_breakdowns') || $breakdownRows === []) {
            return;
        }

        $now = now();
        foreach ($breakdownRows as $row) {
            DB::table('order_discount_breakdowns')->insert([
                'order_id' => $orderId,
                'commission_policy_id' => $row['commission_policy_id'],
                'commission_policy_tier_id' => $row['commission_policy_tier_id'],
                'qty_from' => $row['qty_from'],
                'qty_to' => $row['qty_to'],
                'applied_qty' => $row['applied_qty'],
                'reward_percent' => $row['reward_percent'],
                'basis_amount' => $row['basis_amount'],
                'discount_amount' => $row['discount_amount'],
                'snapshot_json' => json_encode($row['snapshot_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function sumMonthlyAccumulatedQuantity(
        int $agentProfileId,
        string $orderMonthYm,
        array $eligibleStatuses,
        ?int $excludeOrderId,
    ): string {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('order_items')) {
            return '0';
        }

        $q = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.agent_profile_id', $agentProfileId)
            ->where('o.order_month', $orderMonthYm)
            ->whereIn('o.order_status', $eligibleStatuses);

        if (Schema::hasColumn('orders', 'order_channel')) {
            $q->where('o.order_channel', AgentSelfPurchaseDiscount::CHANNEL_AGENT_ORDER);
        }

        if ($excludeOrderId !== null) {
            $q->where('o.id', '<', $excludeOrderId);
        }

        $sum = $q->sum('oi.quantity_in_base_unit');

        return $this->toBc((string) ($sum ?? '0'), '4');
    }

    private function resolveActiveDiscountPolicyRow(int $agentProfileId, string $orderDateYmd): ?object
    {
        if (! Schema::hasTable('agent_commission_policy') || ! Schema::hasTable('commission_policies')) {
            return null;
        }

        $agentId = null;
        if (Schema::hasTable('agent_profiles') && Schema::hasColumn('agent_profiles', 'user_id')) {
            $userId = DB::table('agent_profiles')->where('id', $agentProfileId)->value('user_id');
            if ($userId && Schema::hasTable('agents')) {
                $agentId = DB::table('agents')->where('user_id', $userId)->value('id');
            }
        }

        $q = DB::table('agent_commission_policy as acp')
            ->join('commission_policies as cp', 'cp.id', '=', 'acp.commission_policy_id')
            ->whereIn('cp.policy_type', self::DISCOUNT_POLICY_TYPES)
            ->where('cp.target_subject', 'agent')
            ->where('cp.is_active', 1)
            ->whereIn('cp.calculation_base', ['quantity', 'box_count'])
            ->where('cp.reward_type', 'percent');

        if (Schema::hasColumn('agent_commission_policy', 'agent_profile_id')) {
            $q->where(function ($w) use ($agentProfileId, $agentId): void {
                $w->where('acp.agent_profile_id', $agentProfileId);
                if ($agentId !== null && Schema::hasColumn('agent_commission_policy', 'agent_id')) {
                    $w->orWhere(function ($w2) use ($agentId): void {
                        $w2->whereNull('acp.agent_profile_id')
                            ->where('acp.agent_id', $agentId);
                    });
                }
            });
        } else {
            return null;
        }

        if (Schema::hasColumn('agent_commission_policy', 'effective_from')
            && Schema::hasColumn('agent_commission_policy', 'effective_to')) {
            $q->where(function ($w) use ($orderDateYmd): void {
                $w->whereNull('acp.effective_from')
                    ->orWhereDate('acp.effective_from', '<=', $orderDateYmd);
            })->where(function ($w) use ($orderDateYmd): void {
                $w->whereNull('acp.effective_to')
                    ->orWhereDate('acp.effective_to', '>=', $orderDateYmd);
            });
        }

        if (Schema::hasColumn('agent_commission_policy', 'is_active')) {
            $q->where('acp.is_active', 1);
        }

        // Cùng thứ tự với AgentDiscountCalculatorService (admin): priority tăng dần, id tăng dần.
        if (Schema::hasColumn('commission_policies', 'priority')) {
            $q->orderBy('cp.priority')->orderBy('cp.id');
        } else {
            $q->orderBy('cp.id');
        }

        return $q->select('cp.*')->first();
    }

    /**
     * @return list<array{id:int,min_value:?string,max_value:?string,reward_percent:?string}>
     */
    private function loadPolicyTiers(int $policyId): array
    {
        if (! Schema::hasTable('commission_policy_tiers')) {
            return [];
        }

        return DB::table('commission_policy_tiers')
            ->where('policy_id', $policyId)
            ->orderByRaw('min_value IS NULL, min_value ASC')
            ->orderBy('id')
            ->get(['id', 'min_value', 'max_value', 'reward_percent'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'min_value' => $row->min_value !== null ? (string) $row->min_value : null,
                'max_value' => $row->max_value !== null ? (string) $row->max_value : null,
                'reward_percent' => $row->reward_percent !== null ? (string) $row->reward_percent : null,
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function sumLineSubtotal(array $lines): float
    {
        $sum = '0';
        foreach ($lines as $line) {
            $sum = bcadd($sum, $this->toBc((string) ($line['line_amount'] ?? '0'), '2'), 2);
        }

        return (float) $sum;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function sumLineQuantityBase(array $lines): string
    {
        $sum = '0';
        foreach ($lines as $line) {
            $qty = $line['quantity_in_base_unit'] ?? $line['quantity'] ?? '0';
            $sum = bcadd($sum, $this->toBc((string) $qty, '4'), 4);
        }

        return $sum;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function formatLineItems(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[] = [
                'product_id' => (int) $line['product_id'],
                'product_name' => (string) ($line['product_name'] ?? ''),
                'unit' => (string) ($line['unit'] ?? ''),
                'quantity' => (float) $line['quantity'],
                'quantity_in_base_unit' => (float) ($line['quantity_in_base_unit'] ?? $line['quantity']),
                'unit_price' => (float) $line['unit_price'],
                'line_amount' => (float) $line['line_amount'],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $breakdowns
     * @return list<array<string, mixed>>
     */
    private function formatAppliedTiersForApi(array $breakdowns): array
    {
        return array_map(function (array $row) {
            return [
                'commission_policy_id' => $row['commission_policy_id'],
                'commission_policy_tier_id' => $row['commission_policy_tier_id'],
                'qty_from' => $row['qty_from'],
                'qty_to' => $row['qty_to'],
                'applied_qty' => $row['applied_qty'],
                'reward_percent' => $row['reward_percent'],
                'basis_amount' => (float) $row['basis_amount'],
                'discount_amount' => (float) $row['discount_amount'],
            ];
        }, $breakdowns);
    }

    /**
     * @param  list<array<string, mixed>>  $breakdowns
     * @return list<array<string, mixed>>
     */
    private function formatBreakdownRowsForDb(array $breakdowns): array
    {
        return array_map(function (array $row) {
            return [
                'commission_policy_id' => $row['commission_policy_id'],
                'commission_policy_tier_id' => $row['commission_policy_tier_id'],
                'qty_from' => $row['qty_from'],
                'qty_to' => $row['qty_to'],
                'applied_qty' => $row['applied_qty'],
                'reward_percent' => $row['reward_percent'],
                'basis_amount' => $row['basis_amount'],
                'discount_amount' => $row['discount_amount'],
                'snapshot_json' => $row['snapshot_json'] ?? [],
            ];
        }, $breakdowns);
    }

    private function buildDiscountSnapshotJson(
        object $policy,
        string $calculationMethod,
        string $ladderBefore,
        string $ladderAfter,
        array $engineResult,
    ): array {
        return [
            'policy_id' => (int) $policy->id,
            'policy_code' => (string) ($policy->policy_code ?? ''),
            'calculation_method' => $calculationMethod,
            'calculation_base' => (string) ($policy->calculation_base ?? 'quantity'),
            'monthly_qty_before' => $this->qtyDisplay($ladderBefore),
            'monthly_qty_after' => $this->qtyDisplay($ladderAfter),
            'total_discount_amount' => $engineResult['total_discount_amount'],
            'net_amount' => $engineResult['net_amount'],
            'breakdowns' => $engineResult['breakdowns'],
        ];
    }

    private function formatPolicyPayload(object $policy, string $calculationMethod, bool $isMonthly): array
    {
        $periodType = null;
        if (Schema::hasColumn('commission_policies', 'period_type')) {
            $periodType = $policy->period_type ?? null;
        }

        return [
            'id' => (int) $policy->id,
            'policy_code' => (string) ($policy->policy_code ?? ''),
            'policy_name' => (string) ($policy->policy_name ?? ''),
            'policy_type' => (string) ($policy->policy_type ?? ''),
            'target_subject' => (string) ($policy->target_subject ?? ''),
            'calculation_base' => (string) ($policy->calculation_base ?? ''),
            'reward_type' => (string) ($policy->reward_type ?? ''),
            'period_type' => $periodType,
            'calculation_method' => $calculationMethod,
            'is_monthly_accumulation' => $isMonthly,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConditions(?object $policy): array
    {
        if ($policy === null || ! isset($policy->conditions_json) || $policy->conditions_json === null) {
            return [];
        }

        $raw = $policy->conditions_json;
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return list<string>
     */
    private function eligibleOrderStatuses(array $conditions): array
    {
        $defaults = OrderStatus::monthlyQuantityAccumulationValues();
        $statuses = $conditions['eligible_order_statuses'] ?? null;
        if (! is_array($statuses) || $statuses === []) {
            return $defaults;
        }

        $normalized = array_values(array_filter(array_map(static fn ($s) => is_string($s) ? $s : null, $statuses)));
        $allowed = array_flip($defaults);
        $filtered = array_values(array_filter($normalized, static fn (string $s) => isset($allowed[$s])));

        return $filtered !== [] ? $filtered : $defaults;
    }

    private function isMonthlyPolicy(object $policy): bool
    {
        if (! Schema::hasColumn('commission_policies', 'period_type')) {
            return true;
        }

        $periodType = trim((string) ($policy->period_type ?? ''));

        return $periodType === '' || $periodType === 'monthly';
    }

    private function qtyDisplay(string $qty): string
    {
        return bcadd($qty, '0', 4);
    }

    /**
     * @return array{
     *     subtotal_amount: float,
     *     discount_amount: float,
     *     net_amount: float,
     *     vat_rate_percent: float,
     *     vat_amount: float,
     *     total_with_vat: float
     * }
     */
    private function summaryWithVat(float $subtotal, float $discount, float $net): array
    {
        return array_merge([
            'subtotal_amount' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'net_amount' => round($net, 2),
        ], OrderVatBreakdown::persistFields($subtotal, $discount, null));
    }

    private function toBc(string|float|int|null $value, string $scale): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $normalized = is_string($value) ? trim($value) : (string) $value;

        return bcadd($normalized, '0', (int) $scale);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function emptyPricingResponse(
        array $lines,
        string $subtotalBc,
        string $monthlyQtyBefore,
        string $monthlyQtyAfter,
        array $eligibleStatuses,
        string $orderMonthYm,
        bool $isMonthly,
        ?object $policy = null,
    ): array {
        $policyPayload = null;
        if ($policy !== null) {
            $conditions = $this->decodeConditions($policy);
            $calculationMethod = $conditions['calculation_method'] ?? 'progressive';
            if (! in_array($calculationMethod, ['progressive', 'flat'], true)) {
                $calculationMethod = 'progressive';
            }
            $policyPayload = $this->formatPolicyPayload($policy, $calculationMethod, $isMonthly);
        }

        return [
            'policy' => $policyPayload,
            'monthly_context' => [
                'is_monthly' => $isMonthly,
                'order_month' => $orderMonthYm,
                'calculation_base' => (string) ($policy?->calculation_base ?? 'quantity'),
                'previous_month_quantity' => $this->qtyDisplay($monthlyQtyBefore),
                'monthly_qty_before' => $this->qtyDisplay($monthlyQtyBefore),
                'monthly_qty_after' => $this->qtyDisplay($monthlyQtyAfter),
                'eligible_order_statuses' => $eligibleStatuses,
            ],
            'items' => $this->formatLineItems($lines),
            'applied_tiers' => [],
            'breakdown_rows' => [],
            'summary' => $this->summaryWithVat((float) $subtotalBc, 0.0, (float) $subtotalBc),
            'applied_discount_policy_id' => null,
            'monthly_qty_before' => $this->qtyDisplay($monthlyQtyBefore),
            'monthly_qty_after' => $this->qtyDisplay($monthlyQtyAfter),
            'discount_snapshot_json' => null,
        ];
    }
}
