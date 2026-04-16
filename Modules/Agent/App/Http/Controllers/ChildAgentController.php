<?php

namespace Modules\Agent\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
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
            ]);

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
}
