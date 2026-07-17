<?php

namespace Modules\Agent\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Modules\Agent\App\Http\Requests\ListChildAgentsRequest;
use Modules\Agent\Models\Agent;
use Modules\Agent\Support\AgentOrderScope;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderDisplayPricing;

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
                'address',
                'city',
                'ward',
                'region',
                'status',
                'user_id',
                'parent_agent_id',
                'agent_type_id',
                'logo_path',
                'agent_image_paths',
                'created_at',
                'updated_at',
            ])
            ->map(function (Agent $agent) {
                $summary = $this->getOrderSummaryByAgent($agent);
                $logoUrl = $this->resolveAgentLogoUrl($agent);
                $imageUrls = $this->resolveAgentImageUrls($agent);

                return array_merge($agent->toArray(), [
                    'full_address' => $this->buildAgentFullAddress($agent),
                    'logo_path' => $logoUrl,
                    'logo_url' => $logoUrl,
                    'logo' => $logoUrl,
                    'agent_image_paths' => $imageUrls,
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

        $agentProfileId = AgentOrderScope::resolveAgentProfileId($agent);
        $sellerUserId = AgentOrderScope::sellerUserIdForAgent($agent);

        $ordersQuery = Order::query()->with('items');
        AgentOrderScope::apply($ordersQuery, $agentProfileId, $sellerUserId);

        $latestOrderAt = null;
        if (Schema::hasColumn('orders', 'order_date')) {
            $latestOrderAt = (clone $ordersQuery)->max('order_date');
        } elseif (Schema::hasColumn('orders', 'created_at')) {
            $latestOrderAt = (clone $ordersQuery)->max('created_at');
        }

        $orders = (clone $ordersQuery)->get();
        $pricing = app(OrderDisplayPricing::class);
        $totalRevenue = $orders->sum(fn (Order $order): float => $pricing->netAmountBeforeVat($order));

        return [
            'order_sold_count' => $orders->count(),
            'total_revenue' => (float) $totalRevenue,
            'latest_order_at' => $latestOrderAt ? now()->parse((string) $latestOrderAt)->toIso8601String() : null,
        ];
    }

    private function buildAgentFullAddress(Agent $agent): string
    {
        $address = trim((string) ($agent->address ?? ''));
        $ward = trim((string) ($agent->ward ?? ''));
        $city = trim((string) ($agent->city ?? ''));

        $parts = array_values(array_filter([$address, $ward, $city], fn (string $part) => $part !== ''));
        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return trim((string) ($agent->region ?? ''));
    }

    private function resolveAgentLogoUrl(Agent $agent): ?string
    {
        $logoPath = trim((string) ($agent->logo_path ?? ''));
        if ($logoPath !== '') {
            return $this->buildAbsoluteAssetUrl($logoPath);
        }

        $imagePaths = $this->parseAgentImagePaths($agent->agent_image_paths ?? null);
        if ($imagePaths === []) {
            return null;
        }

        return $this->buildAbsoluteAssetUrl($imagePaths[0]);
    }

    /**
     * @return string[]
     */
    private function resolveAgentImageUrls(Agent $agent): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $path): ?string => $this->buildAbsoluteAssetUrl($path),
                $this->parseAgentImagePaths($agent->agent_image_paths ?? null)
            ),
            fn (?string $path): bool => $path !== null
        ));
    }

    /**
     * @return string[]
     */
    private function parseAgentImagePaths(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            } else {
                $raw = [$raw];
            }
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn (mixed $path): string => trim((string) $path),
                $raw
            ),
            fn (string $path): bool => $path !== ''
        ));
    }

    private function buildAbsoluteAssetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $baseUrl = rtrim((string) (config('app.url_image') ?: config('app.url')), '/');
        if ($baseUrl === '') {
            return '/'.ltrim($path, '/');
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }
}
