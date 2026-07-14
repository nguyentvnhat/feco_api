<?php

namespace Modules\Order\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderCommissionEligibility;

/**
 * Thưởng VPDD bán hàng trực tiếp: khi CL/TP (principal) bán hàng, Garage/NPP (partner) nhận cố định /thanh.
 */
class AgentPartnershipCommissionService
{
    public const POLICY_CODE = 'VP_PARTNER_SHARE_2_5M';

    public const PARTNERSHIP_TYPE = 'vp_dai_dien';

    /**
     * @return list<array{id:int,amount:string,beneficiary_user_id:int}>
     */
    public function syncForOrder(Order $order): array
    {
        if (! Schema::hasTable('agent_partnerships')
            || ! Schema::hasTable('commission_policies')
            || ! Schema::hasTable('commission_entries')) {
            return [];
        }

        if (OrderCommissionEligibility::shouldReverseCommission($order)) {
            $this->deleteEntriesForOrder((int) $order->id);

            return [];
        }

        if (! OrderCommissionEligibility::shouldCommitCommission($order)) {
            return [];
        }

        $principalProfileId = (int) ($order->agent_profile_id ?? 0);
        if ($principalProfileId <= 0) {
            return [];
        }

        if ((string) ($order->order_channel ?? '') !== 'agent_order') {
            return [];
        }

        $principalType = (string) (DB::table('agent_profiles')
            ->where('id', $principalProfileId)
            ->value('agent_type') ?? '');
        if (! in_array($principalType, ['strategic', 'city_distributor'], true)) {
            return [];
        }

        $policy = DB::table('commission_policies')
            ->where('policy_code', self::POLICY_CODE)
            ->where('is_active', 1)
            ->first();

        if ($policy === null) {
            return [];
        }

        $tier = DB::table('commission_policy_tiers')
            ->where('policy_id', $policy->id)
            ->orderBy('id')
            ->first();

        if ($tier === null || $tier->reward_amount === null || (float) $tier->reward_amount <= 0) {
            return [];
        }

        $order->loadMissing('items');
        $bars = $this->sumBars($order);
        if (bccomp($bars, '0', 4) <= 0) {
            return [];
        }

        $perBar = bcadd((string) $tier->reward_amount, '0', 4);
        $amount = number_format(round((float) bcmul($perBar, $bars, 8), 2), 2, '.', '');

        $partnerships = DB::table('agent_partnerships as ap')
            ->join('agent_profiles as partner', 'partner.id', '=', 'ap.partner_agent_profile_id')
            ->where('ap.principal_agent_profile_id', $principalProfileId)
            ->where('ap.partnership_type', self::PARTNERSHIP_TYPE)
            ->where('ap.status', 'active')
            ->where(function ($q): void {
                $q->whereNull('ap.ended_at')->orWhereDate('ap.ended_at', '>=', now()->toDateString());
            })
            ->select([
                'ap.id as partnership_id',
                'ap.partner_agent_profile_id',
                'partner.user_id as partner_user_id',
                'partner.agent_type as partner_agent_type',
            ])
            ->get();

        $created = [];
        $now = now();

        foreach ($partnerships as $row) {
            $beneficiaryUserId = (int) ($row->partner_user_id ?? 0);
            if ($beneficiaryUserId <= 0) {
                continue;
            }

            $businessKey = sprintf(
                'order:%d:policy:%d:partnership:%d',
                (int) $order->id,
                (int) $policy->id,
                (int) $row->partnership_id
            );

            $existing = DB::table('commission_entries')->where('business_key', $businessKey)->first();
            if ($existing !== null) {
                $created[] = [
                    'id' => (int) $existing->id,
                    'amount' => (string) $existing->amount,
                    'beneficiary_user_id' => (int) $existing->beneficiary_user_id,
                ];

                continue;
            }

            $entryId = $this->nextCommissionEntryId();
            $snapshot = json_encode([
                'partnership_id' => (int) $row->partnership_id,
                'partnership_type' => self::PARTNERSHIP_TYPE,
                'principal_agent_profile_id' => $principalProfileId,
                'partner_agent_profile_id' => (int) $row->partner_agent_profile_id,
                'partner_agent_type' => $row->partner_agent_type,
                'reward_amount_per_bar' => $perBar,
                'bars' => $bars,
                'policy_code' => self::POLICY_CODE,
            ], JSON_UNESCAPED_UNICODE);

            $payload = [
                'id' => $entryId,
                'commission_run_id' => null,
                'beneficiary_user_id' => $beneficiaryUserId,
                'source_order_id' => (int) $order->id,
                'policy_id' => (int) $policy->id,
                'entry_type' => (string) $policy->policy_type,
                'amount' => $amount,
                'rate_percent' => null,
                'basis_type' => 'partnership_order_box_count',
                'basis_value' => $bars,
                'settlement_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('commission_entries', 'business_key')) {
                $payload['business_key'] = $businessKey;
            }
            if (Schema::hasColumn('commission_entries', 'source_type')) {
                $payload['source_type'] = 'order';
            }
            if (Schema::hasColumn('commission_entries', 'source_id')) {
                $payload['source_id'] = (int) $order->id;
            }
            if (Schema::hasColumn('commission_entries', 'snapshot_json')) {
                $payload['snapshot_json'] = $snapshot;
            }

            try {
                DB::table('commission_entries')->insert($payload);
            } catch (\Throwable) {
                $again = DB::table('commission_entries')->where('business_key', $businessKey)->first();
                if ($again === null) {
                    throw new \RuntimeException('Failed to create partnership commission entry.');
                }
                $created[] = [
                    'id' => (int) $again->id,
                    'amount' => (string) $again->amount,
                    'beneficiary_user_id' => (int) $again->beneficiary_user_id,
                ];

                continue;
            }

            $created[] = [
                'id' => $entryId,
                'amount' => $amount,
                'beneficiary_user_id' => $beneficiaryUserId,
            ];
        }

        return $created;
    }

    private function sumBars(Order $order): string
    {
        $sum = '0';
        foreach ($order->items as $item) {
            $sum = bcadd($sum, bcadd((string) ($item->quantity_in_base_unit ?? 0), '0', 4), 4);
        }

        return $sum;
    }

    private function deleteEntriesForOrder(int $orderId): void
    {
        $policyId = DB::table('commission_policies')->where('policy_code', self::POLICY_CODE)->value('id');
        if ($policyId === null) {
            return;
        }

        DB::table('commission_entries')
            ->where('source_order_id', $orderId)
            ->where('policy_id', $policyId)
            ->delete();
    }

    private function nextCommissionEntryId(): int
    {
        $max = (int) (DB::table('commission_entries')->max('id') ?? 0);

        return $max + 1;
    }
}
