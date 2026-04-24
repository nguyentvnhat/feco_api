<?php

namespace Modules\Auth\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SettingController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('system_settings')) {
            return $this->successResponse('api.setting.index_success', [
                'settings' => [],
            ]);
        }

        $settingKeys = [
            'app_name',
            'business_info',
            'support_phone',
            'default_language',
            'commission_lock_day',
            'default_shipping_provider',
            'app_logo',
        ];
        $keyFilter = trim((string) $request->query('key', ''));

        $settings = DB::table('system_settings')
            ->whereIn('setting_key', $settingKeys)
            ->when($keyFilter !== '', function ($query) use ($keyFilter) {
                $query->where('setting_key', 'like', '%'.$keyFilter.'%');
            })
            ->orderBy('setting_key')
            ->get([
                'id',
                'setting_key',
                'setting_value',
                'value_type',
                'description',
                'is_public',
                'updated_at',
            ])
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'key' => (string) $item->setting_key,
                    'value' => $this->normalizeSettingValue($item->setting_value, (string) $item->value_type),
                    'value_type' => (string) $item->value_type,
                    'description' => $item->description,
                    'is_public' => (bool) $item->is_public,
                    'updated_at' => $item->updated_at,
                ];
            })
            ->values();

        return $this->successResponse('api.setting.index_success', [
            'settings' => $settings,
            'filters' => [
                'key' => $keyFilter,
            ],
        ]);
    }

    private function normalizeSettingValue(mixed $value, string $valueType): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($valueType === 'string' && is_string($value)) {
            $decoded = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $normalizedSpaces = str_replace("\xc2\xa0", ' ', $decoded);

            return trim($normalizedSpaces);
        }

        return $value;
    }
}
