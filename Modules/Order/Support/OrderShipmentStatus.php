<?php

namespace Modules\Order\Support;

final class OrderShipmentStatus
{
    public static function label(?string $status): string
    {
        if ($status === null || $status === '') {
            return '-';
        }

        $key = 'order.shipment_status.'.$status;
        $trans = __($key);

        return $trans !== $key ? $trans : $status;
    }
}
