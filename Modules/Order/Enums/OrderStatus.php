<?php

namespace Modules\Order\Enums;

use Illuminate\Support\Facades\Lang;

enum OrderStatus: string
{
    case NEW = 'new';

    case PROCESSING = 'processing';

    case CANCELLED = 'cancelled';

    case READY_TO_SHIP = 'ready_to_ship';

    case SHIPPED = 'shipped';

    case TPL_CONFIRMED = 'tpl_confirmed';

    case TPL_TRANSIT = 'tpl_transit';

    case DELIVERING = 'delivering';

    case DELIVERED = 'delivered';

    case DELAY = 'delay';

    case ON_RETURN = 'on_return';

    case RETURN_RECEIVED = 'return_received';

    case PARTIAL_RETURNED = 'partial_returned';

    case RETURNED = 'returned';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function legacyMap(): array
    {
        return [
            'draft' => self::NEW->value,
            'submitted' => self::PROCESSING->value,
            'confirmed' => self::TPL_CONFIRMED->value,
            'completed' => self::DELIVERED->value,
            'cancelled' => self::CANCELLED->value,
        ];
    }

    /**
     * Đơn đã xác nhận bán — dùng khi ghi bonus / commission theo đơn.
     *
     * @return list<string>
     */
    public static function soldLikeValues(): array
    {
        return [
            self::READY_TO_SHIP->value,
        ];
    }

    /**
     * Đơn được tính vào sản lượng tích lũy tháng (chiết khấu tier / thưởng mốc).
     *
     * @return list<string>
     */
    public static function monthlyQuantityAccumulationValues(): array
    {
        return [
            self::READY_TO_SHIP->value,
            self::SHIPPED->value,
            self::TPL_CONFIRMED->value,
            self::TPL_TRANSIT->value,
            self::DELIVERING->value,
            self::DELIVERED->value,
            self::DELAY->value,
        ];
    }

    /**
     * Hoàn / hủy — gỡ commission_entries đã chốt từ ready_to_ship.
     *
     * @return list<string>
     */
    public static function commissionReversalValues(): array
    {
        return [
            self::CANCELLED->value,
            self::ON_RETURN->value,
            self::RETURN_RECEIVED->value,
            self::PARTIAL_RETURNED->value,
            self::RETURNED->value,
        ];
    }

    /**
     * Nhóm vận chuyển + hoàn tất — bắt buộc đã qua {@see self::READY_TO_SHIP}.
     *
     * @return list<string>
     */
    public static function shippingProgressionValues(): array
    {
        return [
            self::SHIPPED->value,
            self::TPL_CONFIRMED->value,
            self::TPL_TRANSIT->value,
            self::DELIVERING->value,
            self::DELIVERED->value,
            self::DELAY->value,
        ];
    }

    /**
     * Nhãn tiếng Việt cho API (luôn đọc từ locale `vi`, giống admin: __('order.status.{value})').
     */
    public function orderLabelStatus(): string
    {
        return self::orderLabelStatusForValue($this->value);
    }

    /**
     * @param  string|null  $value  Giá trị lưu DB / statusValue()
     */
    public static function orderLabelStatusForValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $key = 'order.status.'.$value;
        $label = Lang::get($key, [], 'vi');

        return $label !== $key ? $label : $value;
    }
}
