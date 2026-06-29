<?php

namespace Modules\Order\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cộng dồn sản lượng / doanh thu tháng cho thưởng vượt mốc (đại lý chiến lược & cấp thành phố).
 */
final class AgentHierarchyRollup
{
    /** @var list<string> */
    private const DOWNSTREAM_ROLLUP_AGENT_TYPES = ['strategic', 'city_distributor'];

    public static function usesDownstreamRollup(?string $agentType): bool
    {
        return in_array((string) $agentType, self::DOWNSTREAM_ROLLUP_AGENT_TYPES, true);
    }

    /**
     * @return list<int>
     */
    public function profileIdsForMonthlyAggregation(int $rootProfileId): array
    {
        $agentType = Schema::hasTable('agent_profiles')
            ? DB::table('agent_profiles')->where('id', $rootProfileId)->value('agent_type')
            : null;

        $ids = [$rootProfileId];

        if (! self::usesDownstreamRollup($agentType) || ! Schema::hasTable('agent_hierarchies')) {
            return $ids;
        }

        $descendants = DB::table('agent_hierarchies')
            ->where('ancestor_agent_profile_id', $rootProfileId)
            ->where('depth', '>', 0)
            ->pluck('descendant_agent_profile_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($ids, $descendants)));
    }

    /**
     * @return list<int>
     */
    public function bonusBeneficiaryProfileIds(int $sourceAgentProfileId): array
    {
        if ($sourceAgentProfileId <= 0) {
            return [];
        }

        $beneficiaries = [];

        if ($this->profileHasActiveBonusPolicy($sourceAgentProfileId)) {
            $beneficiaries[] = $sourceAgentProfileId;
        }

        if (! Schema::hasTable('agent_hierarchies')) {
            return $beneficiaries;
        }

        $ancestors = DB::table('agent_hierarchies as ah')
            ->join('agent_profiles as ap', 'ap.id', '=', 'ah.ancestor_agent_profile_id')
            ->where('ah.descendant_agent_profile_id', $sourceAgentProfileId)
            ->where('ah.depth', '>', 0)
            ->whereIn('ap.agent_type', self::DOWNSTREAM_ROLLUP_AGENT_TYPES)
            ->pluck('ah.ancestor_agent_profile_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($ancestors as $ancestorId) {
            if ($this->profileHasActiveBonusPolicy($ancestorId)) {
                $beneficiaries[] = $ancestorId;
            }
        }

        return array_values(array_unique($beneficiaries));
    }

    private function profileHasActiveBonusPolicy(int $agentProfileId): bool
    {
        if (! Schema::hasTable('agent_commission_policy') || ! Schema::hasTable('commission_policies')) {
            return false;
        }

        return DB::table('agent_commission_policy as acp')
            ->join('commission_policies as cp', 'cp.id', '=', 'acp.commission_policy_id')
            ->where('acp.agent_profile_id', $agentProfileId)
            ->where('cp.policy_type', 'bonus')
            ->where('cp.target_subject', 'agent')
            ->where('cp.is_active', 1)
            ->when(
                Schema::hasColumn('agent_commission_policy', 'is_active'),
                fn ($q) => $q->where('acp.is_active', 1)
            )
            ->exists();
    }
}
