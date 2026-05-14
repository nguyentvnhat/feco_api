<?php

namespace Tests\Unit;

use Modules\Order\Support\OrderTierDiscountEngine;
use PHPUnit\Framework\TestCase;

class OrderTierDiscountEngineTest extends TestCase
{
    public function test_progressive_qty_70_splits_50_and_20_units(): void
    {
        $policyId = 1;
        $tiers = [
            ['id' => 10, 'min_value' => '1', 'max_value' => '50', 'reward_percent' => '10'],
            ['id' => 11, 'min_value' => '51', 'max_value' => '100', 'reward_percent' => '20'],
        ];

        $result = OrderTierDiscountEngine::computeProgressivePercent(
            $policyId,
            '0',
            '70',
            '1000.00',
            $tiers,
            '70',
        );

        $this->assertCount(2, $result['breakdowns']);
        $this->assertSame('50.0000', $result['breakdowns'][0]['applied_qty']);
        $this->assertSame('20.0000', $result['breakdowns'][1]['applied_qty']);
    }

    public function test_progressive_previous_40_plus_qty_70_splits_10_50_10(): void
    {
        $policyId = 2;
        $tiers = [
            ['id' => 1, 'min_value' => '1', 'max_value' => '50', 'reward_percent' => '10'],
            ['id' => 2, 'min_value' => '51', 'max_value' => '100', 'reward_percent' => '20'],
            ['id' => 3, 'min_value' => '101', 'max_value' => null, 'reward_percent' => '30'],
        ];

        $result = OrderTierDiscountEngine::computeProgressivePercent(
            $policyId,
            '40',
            '70',
            '1000.00',
            $tiers,
            '110',
        );

        $this->assertCount(3, $result['breakdowns']);
        $this->assertSame('10.0000', $result['breakdowns'][0]['applied_qty']);
        $this->assertSame('50.0000', $result['breakdowns'][1]['applied_qty']);
        $this->assertSame('10.0000', $result['breakdowns'][2]['applied_qty']);
    }

    public function test_flat_applies_end_tier_percent_to_full_subtotal(): void
    {
        $policyId = 3;
        $tiers = [
            ['id' => 1, 'min_value' => '1', 'max_value' => '50', 'reward_percent' => '10'],
            ['id' => 2, 'min_value' => '51', 'max_value' => '100', 'reward_percent' => '20'],
            ['id' => 3, 'min_value' => '101', 'max_value' => null, 'reward_percent' => '30'],
        ];

        $result = OrderTierDiscountEngine::computeFlatPercent(
            $policyId,
            '40',
            '70',
            '1000.00',
            $tiers,
            '110',
        );

        $this->assertCount(1, $result['breakdowns']);
        $this->assertSame('300.00', $result['total_discount_amount']);
        $this->assertSame('70.0000', $result['breakdowns'][0]['applied_qty']);
    }

    public function test_preview_and_store_totals_match_for_same_lines(): void
    {
        $policyId = 4;
        $tiers = [
            ['id' => 1, 'min_value' => '1', 'max_value' => '50', 'reward_percent' => '10'],
            ['id' => 2, 'min_value' => '51', 'max_value' => '100', 'reward_percent' => '20'],
        ];

        $a = OrderTierDiscountEngine::computeProgressivePercent(4, '0', '70', '1000.00', $tiers, '70');
        $b = OrderTierDiscountEngine::computeProgressivePercent(4, '0', '70', '1000.00', $tiers, '70');

        $this->assertSame($a['total_discount_amount'], $b['total_discount_amount']);
        $this->assertSame($a['net_amount'], $b['net_amount']);
    }
}
