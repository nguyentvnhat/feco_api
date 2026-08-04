<?php

namespace Modules\Agent\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Modules\Agent\App\Http\Requests\CheckAgentCodeRequest;
use Modules\Agent\Models\Agent;

class AgentCodeController extends BaseApiController
{
    /**
     * Kiểm tra mã đại lý có tồn tại trên bảng agents hay không.
     */
    public function check(CheckAgentCodeRequest $request): JsonResponse
    {
        $code = (string) $request->validated('code');

        $agent = Agent::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->first([
                'id',
                'code',
                'name',
                'status',
                'agent_type_id',
                'phone',
            ]);

        if ($agent === null) {
            return $this->successResponse('api.agent.code_not_exists', [
                'exists' => false,
                'code' => $code,
                'name' => null,
                'agent' => null,
            ]);
        }

        return $this->successResponse('api.agent.code_exists', [
            'exists' => true,
            'code' => $agent->code,
            'name' => $agent->name,
            'agent' => [
                'id' => $agent->id,
                'code' => $agent->code,
                'name' => $agent->name,
                'status' => $agent->status,
                'agent_type_id' => $agent->agent_type_id,
                'phone' => $agent->phone,
            ],
        ]);
    }
}
