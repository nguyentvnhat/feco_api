<?php

namespace Tests\Unit;

use Modules\Order\Support\AgentSelfPurchaseDiscount;
use PHPUnit\Framework\TestCase;

class AgentSelfPurchaseDiscountTest extends TestCase
{
    public function test_qualifies_when_agent_order_with_profile(): void
    {
        $this->assertTrue(AgentSelfPurchaseDiscount::qualifies('agent_order', 6));
    }

    public function test_does_not_qualify_for_direct_sale(): void
    {
        $this->assertFalse(AgentSelfPurchaseDiscount::qualifies('direct_sale', 6));
    }

    public function test_does_not_qualify_without_agent_profile(): void
    {
        $this->assertFalse(AgentSelfPurchaseDiscount::qualifies('agent_order', null));
        $this->assertFalse(AgentSelfPurchaseDiscount::qualifies('agent_order', 0));
    }
}
