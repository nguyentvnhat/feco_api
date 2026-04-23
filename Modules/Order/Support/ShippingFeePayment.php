<?php

namespace Modules\Order\Support;

final class ShippingFeePayment
{
    public const PAID_BY_SENDER = 1;

    public const PAID_BY_RECEIVER = 2;

    public static function getShippingFeePaymentName(int $value): string
    {
        return match ($value) {
            self::PAID_BY_SENDER => 'PaidBySender',
            self::PAID_BY_RECEIVER => 'PaidByReceiver',
            default => 'Unknown',
        };
    }

    public static function label(int $value): string
    {
        return match ($value) {
            self::PAID_BY_SENDER => __('order.shipping_fee_payment.labels.paid_by_sender'),
            self::PAID_BY_RECEIVER => __('order.shipping_fee_payment.labels.paid_by_receiver'),
            default => __('order.shipping_fee_payment.labels.unknown'),
        };
    }

    /**
     * @return list<int>
     */
    public static function validValues(): array
    {
        return [self::PAID_BY_SENDER, self::PAID_BY_RECEIVER];
    }
}
