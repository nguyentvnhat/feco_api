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
     * @return list<string>
     */
    public static function soldLikeValues(): array
    {
        return [
            self::TPL_CONFIRMED->value,
            self::DELIVERED->value,
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
