<?php

namespace Modules\Order\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Core\Models\Province;
use Modules\Core\Models\Ward;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderAddress;

/**
 * Ví dụ API: trả về địa chỉ theo format legacy (customer_*) từ shippingAddress.
 *
 * Store/Update: tạo/ghi Order rồi gọi OrderAddress::updateOrCreate + syncLegacyCustomerColumnsFromShippingAddress()
 * giống Admin\OrderController::store / update (cùng rule validation).
 */
class OrderController extends Controller
{
    public function show(Order $order): JsonResponse
    {
        $order->load(['shippingAddress', 'pickupAddress', 'items', 'latestShipment']);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'order_date' => $order->order_date?->toIso8601String(),
                'order_status' => $order->statusValue(),
                'order_channel' => $order->order_channel,
                'subtotal_amount' => $order->subtotal_amount,
                'discount_amount' => $order->discount_amount,
                'net_amount' => $order->net_amount,
                ...$order->legacyCustomerAddressForApi(),
                'shipping' => $order->shippingAddress,
                'pickup' => $order->pickupAddress,
                'latest_shipment' => $order->latestShipment
                    ? $order->latestShipment->toApiArray()
                    : null,
            ],
        ]);
    }

    /**
     * Store tối giản: header đơn + địa chỉ giao (không gồm dòng hàng — bổ sung theo domain).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_no' => ['required', 'string', 'max:50', Rule::unique('orders', 'order_no')],
            'order_date' => ['required', 'date'],
            'seller_user_id' => ['required', 'integer', 'exists:users,id'],
            'agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
            'order_channel' => ['required', 'in:agent_order,direct_sale,internal_sale'],
            'order_status' => ['required', Rule::in(OrderStatus::values())],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'customer_address' => ['nullable', 'string', 'max:512'],
            'customer_province_code' => ['required', 'string', 'max:32', 'exists:provinces,code'],
            'customer_district_code' => ['nullable', 'string', 'max:32'],
            'customer_district_name' => ['nullable', 'string', 'max:255'],
            'customer_ward_code' => [
                'required',
                'string',
                'max:32',
                Rule::exists('wards', 'code')->where(function ($q) use ($request) {
                    $q->where('province_code', (string) $request->input('customer_province_code'));
                }),
            ],
        ]);

        $orderDate = now()->parse($validated['order_date']);
        $addressAttrs = $this->shippingAddressAttributesFromRequest($validated);
        $payload = collect($validated)
            ->except([
                'customer_province_code',
                'customer_district_code',
                'customer_district_name',
                'customer_ward_code',
            ])
            ->merge([
                'order_date' => $orderDate,
                'order_month' => $orderDate->format('Y-m'),
                'subtotal_amount' => 0,
                'discount_amount' => 0,
                'net_amount' => 0,
            ])
            ->all();

        $order = DB::transaction(function () use ($payload, $validated, $addressAttrs) {
            $order = Order::query()->create($payload);
            OrderAddress::query()->updateOrCreate(
                [
                    'order_id' => $order->id,
                    'type' => OrderAddress::TYPE_SHIPPING,
                ],
                $addressAttrs
            );
            $order->syncLegacyCustomerColumnsFromShippingAddress();

            return $order->fresh(['shippingAddress', 'latestShipment']);
        });

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                ...$order->legacyCustomerAddressForApi(),
                'latest_shipment' => $order->latestShipment
                    ? $order->latestShipment->toApiArray()
                    : null,
            ],
        ], 201);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'order_date' => ['required', 'date'],
            'seller_user_id' => ['required', 'integer', 'exists:users,id'],
            'agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
            'order_channel' => ['required', 'in:agent_order,direct_sale,internal_sale'],
            'order_status' => ['required', Rule::in(OrderStatus::values())],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'customer_address' => ['nullable', 'string', 'max:512'],
            'customer_province_code' => ['required', 'string', 'max:32', 'exists:provinces,code'],
            'customer_district_code' => ['nullable', 'string', 'max:32'],
            'customer_district_name' => ['nullable', 'string', 'max:255'],
            'customer_ward_code' => [
                'required',
                'string',
                'max:32',
                Rule::exists('wards', 'code')->where(function ($q) use ($request) {
                    $q->where('province_code', (string) $request->input('customer_province_code'));
                }),
            ],
        ]);

        $orderDate = now()->parse($validated['order_date']);
        $addressAttrs = $this->shippingAddressAttributesFromRequest($validated);
        $payload = collect($validated)
            ->except([
                'customer_province_code',
                'customer_district_code',
                'customer_district_name',
                'customer_ward_code',
            ])
            ->merge([
                'order_date' => $orderDate,
                'order_month' => $orderDate->format('Y-m'),
            ])
            ->all();

        DB::transaction(function () use ($order, $payload, $addressAttrs) {
            $order->update($payload);
            OrderAddress::query()->updateOrCreate(
                [
                    'order_id' => $order->id,
                    'type' => OrderAddress::TYPE_SHIPPING,
                ],
                $addressAttrs
            );
            $order->syncLegacyCustomerColumnsFromShippingAddress();
        });

        $order->refresh()->load(['shippingAddress', 'latestShipment']);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                ...$order->legacyCustomerAddressForApi(),
                'latest_shipment' => $order->latestShipment
                    ? $order->latestShipment->toApiArray()
                    : null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function shippingAddressAttributesFromRequest(array $validated): array
    {
        $provinceCode = $validated['customer_province_code'] ?? null;
        $wardCode = $validated['customer_ward_code'] ?? null;
        $provinceName = $provinceCode
            ? Province::query()->where('code', $provinceCode)->value('name')
            : null;
        $wardName = ($wardCode && $provinceCode)
            ? Ward::query()
                ->where('code', $wardCode)
                ->where('province_code', $provinceCode)
                ->value('name')
            : null;

        return [
            'full_name' => $validated['customer_name'],
            'phone' => $validated['customer_phone'],
            'address_line' => $validated['customer_address'] ?? null,
            'province_code' => $provinceCode,
            'province_name' => $provinceName,
            'district_code' => $validated['customer_district_code'] ?? null,
            'district_name' => $validated['customer_district_name'] ?? null,
            'ward_code' => $wardCode,
            'ward_name' => $wardName,
        ];
    }
}
