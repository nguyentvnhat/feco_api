<?php

namespace Tests\Unit;

use Modules\Order\Support\OrderTierDiscountEngine;
use PHPUnit\Framework\TestCase;

class OrderTierDiscountEngineTest extends TestCase
{
    /** @return list<array{quantity_in_base_unit:string, unit_price:string, quantity:string}> */
    private function singleLine(string $qty, string $unitPrice): array
    {
        return [
            [
                'quantity_in_base_unit' => $qty,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
            ],
        ];
    }

    public function test_progressive_qty_70_splits_50_and_20_units_by_unit_price(): void
    {
        $policyId = 1;
        $tiers = [
            ['id' => 10, 'min_value' => '1', 'max_value' => '50', 'reward_percent' => '10'],
            ['id' => 11, 'min_value' => '51', 'max_value' => '100', 'reward_percent' => '20'],
        ];
        $lines = $this->singleLine('70', '10');
        $subtotal = '700.00';

        $result = OrderTierDiscountEngine::computeProgressivePercentFromLines(
            $policyId,
            '0',
            '70',
            $subtotal,
            $tiers,
            '70',
            $lines,
        );

        $this->assertCount(2, $result['breakdowns']);
        $this->assertSame('50.0000', $result['breakdowns'][0]['applied_qty']);
        $this->assertSame('20.0000', $result['breakdowns'][1]['applied_qty']);
        $this->assertSame('500.00', $result['breakdowns'][0]['basis_amount']);
        $this->assertSame('200.00', $result['breakdowns'][1]['basis_amount']);
        $this->assertSame('50.00', $result['breakdowns'][0]['discount_amount']);
        $this->assertSame('40.00', $result['breakdowns'][1]['discount_amount']);
        $this->assertSame('90.00', $result['total_discount_amount']);
    }

    public function test_progressive_previous_40_plus_qty_70_splits_10_50_10(): void
    {
        $policyId = 2;
        $tiers = [
            ['id' => 1, 'min_value' => '1', 'max_value' => '50', 'reward_percent' => '10'],
            ['id' => 2, 'min_value' => '51', 'max_value' => '100', 'reward_percent' => '20'],
            ['id' => 3, 'min_value' => '101', 'max_value' => null, 'reward_percent' => '30'],
        ];
        $lines = $this->singleLine('70', '10');
        $subtotal = '700.00';

        $result = OrderTierDiscountEngine::computeProgressivePercentFromLines(
            $policyId,
            '40',
            '70',
            $subtotal,
            $tiers,
            '110',
            $lines,
        );

        $this->assertCount(3, $result['breakdowns']);
        $this->assertSame('10.0000', $result['breakdowns'][0]['applied_qty']);
        $this->assertSame('50.0000', $result['breakdowns'][1]['applied_qty']);
        $this->assertSame('10.0000', $result['breakdowns'][2]['applied_qty']);
    }

    public function test_progressive_strategic_policy_100_units_splits_95_and_5(): void
    {
        $policyId = 1101;
        $tiers = [
            ['id' => 11001, 'min_value' => '0', 'max_value' => '95', 'reward_percent' => '25'],
            ['id' => 11002, 'min_value' => '100', 'max_value' => '145', 'reward_percent' => '30'],
        ];
        $lines = $this->singleLine('100', '100000');
        $subtotal = '10000000.00';

        $result = OrderTierDiscountEngine::computeProgressivePercentFromLines(
            $policyId,
            '0',
            '100',
            $subtotal,
            $tiers,
            '100',
            $lines,
        );

        $this->assertCount(2, $result['breakdowns']);
        $this->assertSame('95.0000', $result['breakdowns'][0]['applied_qty']);
        $this->assertSame('25.00', $result['breakdowns'][0]['reward_percent']);
        $this->assertSame('5.0000', $result['breakdowns'][1]['applied_qty']);
        $this->assertSame('30.00', $result['breakdowns'][1]['reward_percent']);
        $this->assertSame('2375000.00', $result['breakdowns'][0]['discount_amount']);
        $this->assertSame('150000.00', $result['breakdowns'][1]['discount_amount']);
        $this->assertSame('2525000.00', $result['total_discount_amount']);
    }

    public function test_progressive_strategic_after_95_applies_next_tier_to_full_order_qty(): void
    {
        $policyId = 1101;
        $tiers = [
            ['id' => 11001, 'min_value' => '0', 'max_value' => '95', 'reward_percent' => '25'],
            ['id' => 11002, 'min_value' => '100', 'max_value' => '145', 'reward_percent' => '30'],
        ];
        $lines = $this->singleLine('10', '3850000');
        $subtotal = '38500000.00';

        $result = OrderTierDiscountEngine::computeProgressivePercentFromLines(
            $policyId,
            '95',
            '10',
            $subtotal,
            $tiers,
            '105',
            $lines,
        );

        $this->assertCount(1, $result['breakdowns']);
        $this->assertSame('10.0000', $result['breakdowns'][0]['applied_qty']);
        $this->assertSame('30.00', $result['breakdowns'][0]['reward_percent']);
        $this->assertSame('11550000.00', $result['total_discount_amount']);
    }

    public function test_flat_applies_end_tier_percent_to_full_subtotal(): void
    {
        $policyId = 3;
        $tiers = [
            ['id' => 1, 'min_value' => '1', 'max_value' => '50', 'reward_percent' => '10'],
            ['id' => 2, 'min_value' => '51', 'max_value' => '100', 'reward_percent' => '20'],
            ['id' => 3, 'min_value' => '101', 'max_value' => null, 'reward_percent' => '30'],
        ];

        $result = OrderTierDiscountEngine::computeFlatPercentFromLines(
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
        $lines = $this->singleLine('70', '10');
        $subtotal = '700.00';

        $a = OrderTierDiscountEngine::computeProgressivePercentFromLines(4, '0', '70', $subtotal, $tiers, '70', $lines);
        $b = OrderTierDiscountEngine::computeProgressivePercentFromLines(4, '0', '70', $subtotal, $tiers, '70', $lines);

        $this->assertSame($a['total_discount_amount'], $b['total_discount_amount']);
        $this->assertSame($a['net_amount'], $b['net_amount']);
    }
}
