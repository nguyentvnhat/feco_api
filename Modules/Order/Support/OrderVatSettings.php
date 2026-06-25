<?php

namespace Modules\Order\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderVatSettings
{
    public const SETTING_KEY = 'order_vat_rate_percent';

    public const DEFAULT_RATE_PERCENT = 8.0;

    public static function currentRatePercent(): float
    {
        if (! Schema::hasTable('system_settings')) {
            return self::DEFAULT_RATE_PERCENT;
        }

        $value = DB::table('system_settings')
            ->where('setting_key', self::SETTING_KEY)
            ->value('setting_value');

        if ($value === null || $value === '') {
            return self::DEFAULT_RATE_PERCENT;
        }

        $rate = (float) $value;

        if ($rate < 0 || $rate > 100) {
            return self::DEFAULT_RATE_PERCENT;
        }

        return round($rate, 2);
    }
}
