<?php

namespace Modules\Auth\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AuthController extends BaseApiController
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $login = $validated['login'];
        $password = $validated['password'];

        $user = User::query()
            ->where('phone', $login)
            ->orWhere('email', $login)
            ->first();

        if (!$user || !Hash::check($password, (string) $user->password)) {
            return $this->errorResponse('api.auth.invalid_credentials', 401, (object) []);
        }

        if (Schema::hasColumn('users', 'status') && (string) ($user->status ?? '') !== 'active') {
            return $this->errorResponse('api.auth.account_not_activated', 403, (object) []);
        }

        $agent = null;
        $agentCommissionPolicy = collect();
        if (Schema::hasTable('agents')) {
            $query = DB::table('agents')->where('agents.user_id', $user->id);
            $selects = [
                'agents.id',
                'agents.code',
                'agents.name',
                'agents.business_name',
                'agents.status',
                'agents.agent_type_id',
            ];

            if (Schema::hasColumn('agents', 'full_address')) {
                $selects[] = 'agents.full_address';
            }

            if (Schema::hasTable('agent_types')) {
                $query->leftJoin('agent_types', 'agent_types.id', '=', 'agents.agent_type_id');
                $selects[] = 'agent_types.code as agent_type_code';
                $selects[] = 'agent_types.name as agent_type_name';
            }

            $agent = $query->select($selects)->first();

            if ($agent && Schema::hasTable('agent_commission_policy')) {
                $agentCommissionPolicy = DB::table('agent_commission_policy')
                    ->where('agent_id', $agent->id)
                    ->orderBy('commission_policy_id')
                    ->get([
                        'id',
                        'commission_policy_id',
                    ]);
            }
        }

        if ($agent && $agentCommissionPolicy->isEmpty()) {
            return $this->errorResponse('api.auth.agent_policy_not_configured', 403, (object) []);
        }

        $agentOrderSummary = $agent
            ? $this->getOrderSummaryByAgent((int) $agent->id, (string) ($agent->code ?? ''), $user->id)
            : ['order_sold_count' => 0, 'total_revenue' => 0];

        $token = $user->createToken('api')->plainTextToken;

        return $this->successResponse('api.auth.login_success', [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
            ],
            'agent' => $agent ? [
                'id' => $agent->id,
                'code' => $agent->code,
                'name' => $agent->name,
                'business_name' => $agent->business_name,
                'full_address' => $agent->full_address ?? null,
                'status' => $agent->status,
                'agent_type' => [
                    'id' => $agent->agent_type_id,
                    'code' => $agent->agent_type_code ?? null,
                    'name' => $agent->agent_type_name ?? null,
                ],
                'order_sold_count' => $agentOrderSummary['order_sold_count'],
                'total_revenue' => $this->formatVietnameseMoney($agentOrderSummary['total_revenue']),
                'currency' => $this->vietnameseMoneyCurrency(),
                'agent_commission_policy' => $agentCommissionPolicy->values(),
            ] : null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return $this->successResponse('api.auth.logout_success', (object) []);
    }

    /**
     * @return array{order_sold_count:int,total_revenue:float}
     */
    private function getOrderSummaryByAgent(int $agentId, string $agentCode, int $userId): array
    {
        if (! Schema::hasTable('orders')) {
            return ['order_sold_count' => 0, 'total_revenue' => 0];
        }

        $agentProfileId = null;
        if (Schema::hasTable('agent_profiles')) {
            $profileQuery = DB::table('agent_profiles');

            if (Schema::hasColumn('agent_profiles', 'user_id')) {
                $agentProfileId = $profileQuery->where('user_id', $userId)->value('id');
            }

            if (! $agentProfileId && Schema::hasColumn('agent_profiles', 'agent_code') && $agentCode !== '') {
                $agentProfileId = DB::table('agent_profiles')
                    ->where('agent_code', $agentCode)
                    ->value('id');
            }
        }

        $ordersQuery = DB::table('orders');
        if ($agentProfileId && Schema::hasColumn('orders', 'agent_profile_id')) {
            $ordersQuery->where('agent_profile_id', (int) $agentProfileId);
        } elseif (Schema::hasColumn('orders', 'seller_user_id')) {
            $ordersQuery->where('seller_user_id', $userId);
        } else {
            return ['order_sold_count' => 0, 'total_revenue' => 0];
        }

        return [
            'order_sold_count' => (int) $ordersQuery->count(),
            'total_revenue' => (float) $ordersQuery->sum('net_amount'),
        ];
    }
}

