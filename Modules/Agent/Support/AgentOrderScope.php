<?php

namespace Modules\Agent\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Models\Order;

class AgentOrderScope
{
    /**
     * Map `agents` → `agent_profiles` (ưu tiên mã đại lý, giống admin).
     */
    public static function resolveAgentProfileId(object $agent): ?int
    {
        if (! Schema::hasTable('agent_profiles')) {
            return null;
        }

        $code = isset($agent->code) ? trim((string) $agent->code) : '';
        if ($code !== '' && Schema::hasColumn('agent_profiles', 'agent_code')) {
            $id = DB::table('agent_profiles')->where('agent_code', $code)->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        $userId = isset($agent->user_id) ? $agent->user_id : null;
        if ($userId && Schema::hasColumn('agent_profiles', 'user_id')) {
            $id = DB::table('agent_profiles')->where('user_id', $userId)->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }

    public static function sellerUserIdForAgent(object $agent): ?int
    {
        $userId = isset($agent->user_id) ? $agent->user_id : null;

        return $userId ? (int) $userId : null;
    }

    /**
     * Gom đơn theo agent_profile_id và/hoặc seller_user_id (không trùng khi cộng tổng).
     *
     * @param  Builder<Order>  $query
     */
    public static function apply(Builder $query, ?int $agentProfileId, ?int $sellerUserId): void
    {
        if ($agentProfileId === null && ($sellerUserId === null || $sellerUserId <= 0)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $subQuery) use ($agentProfileId, $sellerUserId) {
            $applied = false;

            if ($agentProfileId !== null && Schema::hasColumn('orders', 'agent_profile_id')) {
                $subQuery->where('agent_profile_id', $agentProfileId);
                $applied = true;
            }

            if ($sellerUserId !== null && $sellerUserId > 0 && Schema::hasColumn('orders', 'seller_user_id')) {
                if ($applied) {
                    $subQuery->orWhere('seller_user_id', $sellerUserId);
                } else {
                    $subQuery->where('seller_user_id', $sellerUserId);
                }
            }
        });
    }
}
