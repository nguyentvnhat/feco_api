<?php

namespace Modules\Order\Support;

/**
 * Chiết khấu đại lý chỉ áp dụng khi đại lý tự mua (đơn kênh agent_order gắn agent_profile).
 */
final class AgentSelfPurchaseDiscount
{
    public const CHANNEL_AGENT_ORDER = 'agent_order';

    public static function qualifies(?string $orderChannel, ?int $agentProfileId): bool
    {
        return $orderChannel === self::CHANNEL_AGENT_ORDER
            && $agentProfileId !== null
            && $agentProfileId > 0;
    }
}
