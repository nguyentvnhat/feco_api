<?php

namespace Modules\Agent\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Agent\App\Http\Requests\ListChildAgentsRequest;
use Modules\Agent\Models\Agent;

class ChildAgentController extends BaseApiController
{
    /**
     * Danh sách đại lý con: parent_agent_id = agent gắn với user đăng nhập.
     * Lọc theo agent_type_id (của bản ghi con) qua query ?agent_type_id= khi cần.
     */
    public function index(ListChildAgentsRequest $request): JsonResponse
    {
        $parent = Agent::query()->where('user_id', $request->user()->id)->first();
        if ($parent === null) {
            return $this->errorResponse('api.agent.current_agent_not_found', 422, (object) []);
        }

        $validated = $request->validated();

        $query = Agent::query()
            ->where('parent_agent_id', $parent->id);

        if (array_key_exists('agent_type_id', $validated) && $validated['agent_type_id'] !== null) {
            $query->where('agent_type_id', (int) $validated['agent_type_id']);
        }

        $agents = $query
            ->orderBy('name')
            ->get([
                'id',
                'code',
                'name',
                'email',
                'phone',
                'city',
                'ward',
                'region',
                'status',
                'user_id',
                'parent_agent_id',
                'agent_type_id',
                'created_at',
                'updated_at',
            ])
            ->map(function (Agent $agent) {
                $summary = $this->getOrderSummaryByAgent($agent);

                return array_merge($agent->toArray(), [
                    'order_sold_count' => $summary['order_sold_count'],
                    'total_revenue' => $this->formatVietnameseMoney($summary['total_revenue']),
                    'latest_order_at' => $summary['latest_order_at'],
                    'currency' => $this->vietnameseMoneyCurrency(),
                ]);
            })
            ->values();

        return $this->successResponse('api.agent.children_success', [
            'parent_agent' => [
                'id' => $parent->id,
                'code' => $parent->code,
                'name' => $parent->name,
                'agent_type_id' => $parent->agent_type_id,
            ],
            'agents' => $agents,
        ]);
    }

    /**
     * @return array{order_sold_count:int,total_revenue:float,latest_order_at:?string}
     */
    private function getOrderSummaryByAgent(Agent $agent): array
    {
        if (! Schema::hasTable('orders')) {
            return ['order_sold_count' => 0, 'total_revenue' => 0, 'latest_order_at' => null];
        }

        $agentProfileId = null;
        if (Schema::hasTable('agent_profiles')) {
            $profileQuery = DB::table('agent_profiles');

            if (Schema::hasColumn('agent_profiles', 'user_id') && $agent->user_id) {
                $agentProfileId = $profileQuery->where('user_id', $agent->user_id)->value('id');
            }

            if (! $agentProfileId && Schema::hasColumn('agent_profiles', 'agent_code') && $agent->code) {
                $agentProfileId = DB::table('agent_profiles')
                    ->where('agent_code', $agent->code)
                    ->value('id');
            }
        }

        $ordersQuery = DB::table('orders');
        if ($agentProfileId && Schema::hasColumn('orders', 'agent_profile_id')) {
            $ordersQuery->where('agent_profile_id', (int) $agentProfileId);
        } elseif (Schema::hasColumn('orders', 'seller_user_id') && $agent->user_id) {
            $ordersQuery->where('seller_user_id', $agent->user_id);
        } else {
            return ['order_sold_count' => 0, 'total_revenue' => 0, 'latest_order_at' => null];
        }

        $latestOrderAt = null;
        if (Schema::hasColumn('orders', 'order_date')) {
            $latestOrderAt = (clone $ordersQuery)->max('order_date');
        } elseif (Schema::hasColumn('orders', 'created_at')) {
            $latestOrderAt = (clone $ordersQuery)->max('created_at');
        }

        return [
            'order_sold_count' => (int) $ordersQuery->count(),
            'total_revenue' => (float) $ordersQuery->sum('net_amount'),
            'latest_order_at' => $latestOrderAt ? now()->parse((string) $latestOrderAt)->toIso8601String() : null,
        ];
    }
}
