<?php

namespace Modules\Order\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Support\AgentHierarchyRollup;

/**
 * Thưởng vượt mốc (policy_type = bonus): % trên doanh thu trước thuế (net_amount) của từng đơn,
 * khi sản lượng tháng (có thể gồm tuyến dưới) đạt bậc tier.
 */
class AgentMonthlyBonusService
{
    public function __construct(
        private readonly AgentHierarchyRollup $hierarchyRollup,
    ) {}

    public function syncForOrder(Order $order): void
    {
        if (! Schema::hasTable('commission_entries')) {
            return;
        }

        $order->loadMissing('items');

        if (! in_array((string) $order->statusValue(), OrderStatus::soldLikeValues(), true)) {
            $this->deleteBonusEntriesForOrder((int) $order->id);

            return;
        }

        $sourceProfileId = (int) ($order->agent_profile_id ?? 0);
        if ($sourceProfileId <= 0) {
            $this->deleteBonusEntriesForOrder((int) $order->id);

            return;
        }

        $beneficiaries = $this->hierarchyRollup->bonusBeneficiaryProfileIds($sourceProfileId);
        $touchedBeneficiaryUserIds = [];

        foreach ($beneficiaries as $beneficiaryProfileId) {
            $userId = (int) (DB::table('agent_profiles')->where('id', $beneficiaryProfileId)->value('user_id') ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $policy = $this->resolveActiveBonusPolicyRow($beneficiaryProfileId, $order->order_date?->toDateString() ?? now()->toDateString());
            if ($policy === null) {
                continue;
            }

            $touchedBeneficiaryUserIds[] = $userId;
            $this->upsertBonusEntryForOrder($order, $beneficiaryProfileId, $userId, $policy);
        }

        DB::table('commission_entries')
            ->where('source_order_id', $order->id)
            ->where('entry_type', 'bonus')
            ->when($touchedBeneficiaryUserIds !== [], fn ($q) => $q->whereNotIn('beneficiary_user_id', $touchedBeneficiaryUserIds))
            ->delete();
    }

    private function upsertBonusEntryForOrder(Order $order, int $beneficiaryProfileId, int $beneficiaryUserId, object $policy): void
    {
        $orderMonth = is_string($order->order_month) && strlen((string) $order->order_month) >= 7
            ? (string) $order->order_month
            : ($order->order_date?->format('Y-m') ?? now()->format('Y-m'));

        $rollupProfileIds = $this->hierarchyRollup->profileIdsForMonthlyAggregation($beneficiaryProfileId);
        $monthlyQty = $this->sumMonthlyQuantity($rollupProfileIds, $orderMonth, null);

        $tiers = DB::table('commission_policy_tiers')
            ->where('policy_id', (int) $policy->id)
            ->orderByRaw('COALESCE(min_value, 0) ASC')
            ->orderBy('id')
            ->get();

        $tier = $this->findApplicableTier($tiers, $monthlyQty);
        if ($tier === null) {
            DB::table('commission_entries')
                ->where('beneficiary_user_id', $beneficiaryUserId)
                ->where('source_order_id', $order->id)
                ->where('policy_id', (int) $policy->id)
                ->where('entry_type', 'bonus')
                ->delete();

            return;
        }

        $ratePercent = $tier->reward_percent !== null ? (float) $tier->reward_percent : null;
        $orderPreTaxRevenue = round((float) ($order->net_amount ?? 0), 2);
        $bonusAmount = $ratePercent !== null && $orderPreTaxRevenue > 0
            ? round($orderPreTaxRevenue * $ratePercent / 100, 2)
            : 0.0;

        $payload = [
            'commission_run_id' => null,
            'amount' => $bonusAmount,
            'rate_percent' => $ratePercent,
            'basis_type' => 'revenue',
            'basis_value' => $orderPreTaxRevenue,
            'settlement_status' => 'pending',
            'updated_at' => now(),
        ];

        $existing = DB::table('commission_entries')
            ->where('beneficiary_user_id', $beneficiaryUserId)
            ->where('source_order_id', $order->id)
            ->where('policy_id', (int) $policy->id)
            ->where('entry_type', 'bonus')
            ->first();

        if ($existing) {
            DB::table('commission_entries')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('commission_entries')->insert(array_merge($payload, [
                'id' => $this->nextCommissionEntryId(),
                'beneficiary_user_id' => $beneficiaryUserId,
                'source_order_id' => $order->id,
                'policy_id' => (int) $policy->id,
                'entry_type' => 'bonus',
                'created_at' => now(),
            ]));
        }
    }

    /**
     * @param  list<int>  $profileIds
     */
    public function sumMonthlyQuantity(array $profileIds, string $orderMonthYm, ?int $excludeOrderId = null): float
    {
        if ($profileIds === [] || ! Schema::hasTable('orders') || ! Schema::hasTable('order_items')) {
            return 0.0;
        }

        $q = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.agent_profile_id', $profileIds)
            ->where('o.order_month', $orderMonthYm)
            ->whereIn('o.order_status', OrderStatus::monthlyQuantityAccumulationValues());

        if ($excludeOrderId !== null) {
            $q->where('o.id', '<', $excludeOrderId);
        }

        return (float) ($q->sum('oi.quantity_in_base_unit') ?? 0);
    }

    private function findApplicableTier($tiers, float $monthlyQty): ?object
    {
        foreach ($tiers as $tier) {
            $min = (float) ($tier->min_value ?? 0);
            $max = $tier->max_value !== null ? (float) $tier->max_value : null;
            if ($monthlyQty >= $min && ($max === null || $monthlyQty <= $max)) {
                return $tier;
            }
        }

        return null;
    }

    private function resolveActiveBonusPolicyRow(int $agentProfileId, string $orderDateYmd): ?object
    {
        if (! Schema::hasTable('agent_commission_policy') || ! Schema::hasTable('commission_policies')) {
            return null;
        }

        $q = DB::table('agent_commission_policy as acp')
            ->join('commission_policies as cp', 'cp.id', '=', 'acp.commission_policy_id')
            ->where('acp.agent_profile_id', $agentProfileId)
            ->where('cp.policy_type', 'bonus')
            ->where('cp.target_subject', 'agent')
            ->where('cp.is_active', 1);

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

        if (Schema::hasColumn('commission_policies', 'priority')) {
            $q->orderBy('cp.priority')->orderBy('cp.id');
        } else {
            $q->orderBy('cp.id');
        }

        return $q->select('cp.*')->first();
    }

    private function deleteBonusEntriesForOrder(int $orderId): void
    {
        DB::table('commission_entries')
            ->where('source_order_id', $orderId)
            ->where('entry_type', 'bonus')
            ->delete();
    }

    private function nextCommissionEntryId(): int
    {
        return ((int) DB::table('commission_entries')->max('id')) + 1;
    }
}
