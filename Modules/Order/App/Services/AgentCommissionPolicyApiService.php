<?php

namespace Modules\Order\App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Enums\OrderStatus;

/**
 * Payload chính sách hoa hồng/chiết khấu cho API đại lý (/auth/me).
 * Gồm tiers + tiến độ tháng hiện tại (đồng bộ logic admin agent show).
 */
class AgentCommissionPolicyApiService
{
    /**
     * @param  Collection<int, object>  $assignmentRows  từ agent_commission_policy (+ join commission_policies)
     * @return Collection<int, array<string, mixed>>
     */
    public function enrichAssignments(Collection $assignmentRows, ?int $agentProfileId): Collection
    {
        if ($assignmentRows->isEmpty()) {
            return collect();
        }

        $policyIds = $assignmentRows
            ->map(fn ($row) => (int) ($row->commission_policy_id ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $tiersByPolicy = $this->loadTiersByPolicyId($policyIds);
        $policyMeta = $this->loadPolicyMetaById($policyIds);
        $orderMonth = now()->format('Y-m');
        $soldStatuses = OrderStatus::soldLikeValues();

        return $assignmentRows->map(function ($row) use ($tiersByPolicy, $policyMeta, $agentProfileId, $orderMonth, $soldStatuses) {
            $policyId = (int) ($row->commission_policy_id ?? 0);
            $meta = $policyMeta->get($policyId);
            $tiers = $tiersByPolicy->get($policyId, collect());
            $calculationBase = (string) ($meta->calculation_base ?? $row->calculation_base ?? 'revenue');
            $conditions = $this->decodeConditions($meta ?? $row);
            $fixedPerUnit = (bool) data_get($conditions, 'agent_order_discount.fixed_amount_per_unit', false)
                || ((string) ($meta->reward_type ?? $row->reward_type ?? '') === 'fixed_amount');
                $calculationMethod = $fixedPerUnit
                ? 'fixed_amount_per_unit'
                : (string) ($conditions['calculation_method'] ?? 'progressive');
            if (! $fixedPerUnit && ! in_array($calculationMethod, ['progressive', 'flat'], true)) {
                $calculationMethod = 'progressive';
            }

            $isMonthly = ! $fixedPerUnit && $this->isMonthlyPolicy($meta);

            $currentValue = null;
            if ($agentProfileId !== null && $agentProfileId > 0) {
                $currentValue = $this->sumOrderBasisForAgentPolicy(
                    $agentProfileId,
                    $calculationBase,
                    $meta?->product_category_id !== null ? (int) $meta->product_category_id : null,
                    $soldStatuses,
                    $orderMonth
                );
            }

            $progress = $currentValue !== null
                ? $this->interpretPolicyTiersProgress((float) $currentValue, $tiers, $calculationBase)
                : null;

            $rawDescription = $row->description ?? $meta?->description;
            $description = $this->absolutizeDescriptionHtml(
                is_string($rawDescription) ? $rawDescription : null
            );
            $targetSubject = (string) ($row->target_subject ?? $meta?->target_subject ?? 'agent');

            return [
                'id' => (int) $row->id,
                'commission_policy_id' => $policyId,
                'policy_code' => $row->policy_code ?? $meta?->policy_code,
                'policy_name' => $row->policy_name ?? $meta?->policy_name,
                'policy_type' => $row->policy_type ?? $meta?->policy_type,
                'target_subject' => $targetSubject,
                'target_subject_label' => $this->targetSubjectLabel($targetSubject, $conditions),
                'calculation_base' => $calculationBase,
                'reward_type' => $row->reward_type ?? $meta?->reward_type,
                'period_type' => $meta?->period_type ?? null,
                'calculation_method' => $calculationMethod,
                'is_monthly_accumulation' => $isMonthly,
                'description' => $description,
                'description_images' => $this->extractDescriptionImages($description),
                'conditions' => $conditions !== [] ? $conditions : (object) [],
                'is_active' => isset($row->is_active) ? (bool) $row->is_active : true,
                'tiers' => $tiers->map(fn ($tier) => [
                    'id' => (int) $tier->id,
                    'min_value' => $tier->min_value !== null ? (float) $tier->min_value : null,
                    'max_value' => $tier->max_value !== null ? (float) $tier->max_value : null,
                    'reward_percent' => $tier->reward_percent !== null ? (float) $tier->reward_percent : null,
                    'reward_amount' => $tier->reward_amount !== null ? (float) $tier->reward_amount : null,
                ])->values()->all(),
                'progress' => $progress,
            ];
        })->values();
    }

    /**
     * @param  list<int>  $policyIds
     * @return Collection<int, Collection<int, object>>
     */
    private function loadTiersByPolicyId(array $policyIds): Collection
    {
        if ($policyIds === [] || ! Schema::hasTable('commission_policy_tiers')) {
            return collect();
        }

        return DB::table('commission_policy_tiers')
            ->whereIn('policy_id', $policyIds)
            ->orderByRaw('COALESCE(min_value, 0) ASC')
            ->orderBy('id')
            ->get(['id', 'policy_id', 'min_value', 'max_value', 'reward_percent', 'reward_amount'])
            ->groupBy(fn ($row) => (int) $row->policy_id);
    }

    /**
     * @param  list<int>  $policyIds
     * @return Collection<int, object>
     */
    private function loadPolicyMetaById(array $policyIds): Collection
    {
        if ($policyIds === [] || ! Schema::hasTable('commission_policies')) {
            return collect();
        }

        $selects = [
            'id',
            'policy_code',
            'policy_name',
            'policy_type',
            'target_subject',
            'calculation_base',
            'reward_type',
            'description',
            'product_category_id',
        ];
        if (Schema::hasColumn('commission_policies', 'period_type')) {
            $selects[] = 'period_type';
        }
        if (Schema::hasColumn('commission_policies', 'conditions_json')) {
            $selects[] = 'conditions_json';
        }

        return DB::table('commission_policies')
            ->whereIn('id', $policyIds)
            ->get($selects)
            ->keyBy(fn ($row) => (int) $row->id);
    }

    /**
     * @param  array<int, string>  $soldStatuses
     */
    private function sumOrderBasisForAgentPolicy(
        int $agentProfileId,
        string $calculationBase,
        ?int $productCategoryId,
        array $soldStatuses,
        string $orderMonthYm,
    ): float {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('order_items')) {
            return 0.0;
        }

        $sumExpr = match ($calculationBase) {
            'revenue' => 'COALESCE(SUM(oi.line_amount), 0)',
            'quantity' => 'COALESCE(SUM(oi.quantity), 0)',
            'box_count' => 'COALESCE(SUM(oi.quantity_in_base_unit), 0)',
            default => 'COALESCE(SUM(oi.line_amount), 0)',
        };

        $q = DB::table('orders as o')
            ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('o.agent_profile_id', $agentProfileId)
            ->whereIn('o.order_status', $soldStatuses);

        if (Schema::hasColumn('orders', 'order_month')) {
            $q->where('o.order_month', $orderMonthYm);
        }

        if ($productCategoryId !== null) {
            $q->where('p.product_category_id', $productCategoryId);
        }

        return (float) $q->selectRaw($sumExpr.' as agg')->value('agg');
    }

    /**
     * @param  Collection<int, object>  $tiers
     * @return array<string, mixed>
     */
    private function interpretPolicyTiersProgress(float $value, Collection $tiers, string $calculationBase): array
    {
        if ($tiers->isEmpty()) {
            return [
                'current_value' => $value,
                'current_tier_id' => null,
                'current_tier_label' => null,
                'reward_label' => null,
                'next_threshold' => null,
                'remaining_to_next' => null,
                'progress_percent' => null,
                'basis_unit' => $this->basisUnit($calculationBase),
            ];
        }

        $sorted = $tiers->sortBy(fn ($t) => (float) ($t->min_value ?? 0))->values();

        $currentTier = null;
        foreach ($sorted as $t) {
            $min = (float) ($t->min_value ?? 0);
            $max = $t->max_value !== null ? (float) $t->max_value : null;
            if ($value >= $min && ($max === null || $value <= $max)) {
                $currentTier = $t;
                break;
            }
        }

        $rewardLabel = null;
        if ($currentTier !== null) {
            if ($currentTier->reward_percent !== null && (float) $currentTier->reward_percent > 0) {
                $p = (float) $currentTier->reward_percent;
                $rewardLabel = ((abs($p - round($p)) < 0.00001) ? (string) (int) round($p) : rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.')).'%';
            } elseif ($currentTier->reward_amount !== null && (float) $currentTier->reward_amount > 0) {
                $rewardLabel = number_format((float) $currentTier->reward_amount, 0, ',', '.').' đ';
            }
        }

        $nextThreshold = null;
        foreach ($sorted as $t) {
            $m = (float) ($t->min_value ?? 0);
            if ($m > $value) {
                $nextThreshold = $m;
                break;
            }
        }

        if ($currentTier !== null && $currentTier->max_value === null) {
            $nextThreshold = null;
        }

        $segmentStart = 0.0;
        if ($currentTier !== null) {
            $segmentStart = (float) ($currentTier->min_value ?? 0);
        } else {
            foreach ($sorted as $t) {
                if ($t->max_value !== null) {
                    $mx = (float) $t->max_value;
                    if ($value > $mx) {
                        $segmentStart = max($segmentStart, $mx);
                    }
                }
            }
        }

        $progressPercent = null;
        if ($nextThreshold !== null) {
            $den = $nextThreshold - $segmentStart;
            $progressPercent = $den > 0
                ? min(100.0, max(0.0, (($value - $segmentStart) / $den) * 100.0))
                : 100.0;
        } elseif ($currentTier !== null) {
            $progressPercent = 100.0;
        }

        $remainingToNext = null;
        if ($nextThreshold !== null) {
            $remaining = max(0.0, $nextThreshold - $value);
            if ($remaining > 0) {
                $remainingToNext = $remaining;
            }
        }

        return [
            'current_value' => $value,
            'current_tier_id' => $currentTier !== null ? (int) $currentTier->id : null,
            'current_tier_label' => $this->formatTierLabel($currentTier, $calculationBase),
            'reward_label' => $rewardLabel,
            'next_threshold' => $nextThreshold,
            'remaining_to_next' => $remainingToNext,
            'progress_percent' => $progressPercent,
            'basis_unit' => $this->basisUnit($calculationBase),
        ];
    }

    private function formatTierLabel(?object $tier, string $calculationBase): ?string
    {
        if ($tier === null) {
            return null;
        }

        $decimals = match ($calculationBase) {
            'quantity', 'box_count' => 2,
            default => 0,
        };
        $fmt = static function ($v) use ($decimals): string {
            $formatted = number_format((float) $v, $decimals, ',', '.');
            if ($decimals > 0) {
                $formatted = rtrim(rtrim($formatted, '0'), ',');
            }

            return $formatted;
        };

        $min = $fmt($tier->min_value ?? 0);
        $max = $tier->max_value !== null && $tier->max_value !== ''
            ? $fmt($tier->max_value)
            : '∞';
        $range = $min.' → '.$max;

        if ($tier->reward_percent !== null && (float) $tier->reward_percent > 0) {
            $percent = (float) $tier->reward_percent;

            return $range.' ('.((abs($percent - round($percent)) < 0.00001)
                ? (string) (int) round($percent)
                : rtrim(rtrim(number_format($percent, 2, ',', '.'), '0'), ',')).'%)';
        }

        if ($tier->reward_amount !== null && (float) $tier->reward_amount > 0) {
            return $range.' ('.number_format((float) $tier->reward_amount, 0, ',', '.').' đ)';
        }

        return $range;
    }

    private function isMonthlyPolicy(?object $policy): bool
    {
        if ($policy === null || ! Schema::hasColumn('commission_policies', 'period_type')) {
            return true;
        }

        $periodType = trim((string) ($policy->period_type ?? ''));

        return $periodType === '' || $periodType === 'monthly';
    }

    private function basisUnit(string $calculationBase): string
    {
        return match ($calculationBase) {
            'quantity' => 'quantity',
            'box_count' => 'box_count',
            default => 'revenue',
        };
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
     * Đổi src tương đối trong HTML mô tả thành URL tuyệt đối (APP_URL_IMAGE / APP_URL).
     */
    private function absolutizeDescriptionHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $trimmed = trim($html);
        if ($trimmed === '') {
            return $trimmed;
        }

        return (string) preg_replace_callback(
            '/(<img\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)\2/i',
            function (array $matches): string {
                $absolute = $this->toAbsoluteAssetUrl($matches[3]);

                return $matches[1].$matches[2].($absolute ?? $matches[3]).$matches[2];
            },
            $trimmed
        );
    }

    /**
     * Trích URL ảnh từ HTML mô tả (đã absolute nếu có thể).
     *
     * @return list<string>
     */
    private function extractDescriptionImages(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        if (! preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])([^"\']+)\1/i', $html, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[2] as $src) {
            $absolute = $this->toAbsoluteAssetUrl((string) $src);
            if ($absolute === null || $absolute === '') {
                continue;
            }
            $urls[$absolute] = $absolute;
        }

        return array_values($urls);
    }

    private function toAbsoluteAssetUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $baseUrl = rtrim((string) (config('app.url_image') ?: config('app.url')), '/');
        $relative = '/'.ltrim($path, '/');

        if ($baseUrl === '') {
            return $relative;
        }

        return $baseUrl.$relative;
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    private function targetSubjectLabel(string $targetSubject, array $conditions): string
    {
        if ($targetSubject === 'both'
            || data_get($conditions, 'agent_downline_bonus.eligible_subjects') === ['agent', 'employee']
            || (is_array(data_get($conditions, 'agent_downline_bonus.eligible_subjects'))
                && count(array_intersect(
                    (array) data_get($conditions, 'agent_downline_bonus.eligible_subjects', []),
                    ['agent', 'employee']
                )) === 2)
        ) {
            $types = (array) data_get($conditions, 'agent_downline_bonus.eligible_agent_types', []);
            if (in_array('ptth_partner', $types, true) || in_array('distributor', $types, true)) {
                return 'Nhà Phân Phối / Đối tác Đồng hành PTTH / Nhân sự';
            }

            return 'Đại lý & Nhân sự';
        }

        return match ($targetSubject) {
            'employee' => 'Nhân sự',
            default => 'Đại lý',
        };
    }
}
