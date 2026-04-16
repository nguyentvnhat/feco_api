<?php

namespace Modules\Order\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Province;
use Modules\Core\Models\Ward;
use Modules\Order\App\Http\Requests\StoreOrderRequest;
use Modules\Order\App\Http\Requests\UpdateOrderRequest;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderAddress;

/**
 * Ví dụ API: trả về địa chỉ theo format legacy (customer_*) từ shippingAddress.
 *
 * Store/Update: tạo/ghi Order rồi gọi OrderAddress::updateOrCreate + syncLegacyCustomerColumnsFromShippingAddress()
 * giống Admin\OrderController::store / update (cùng rule validation).
 */
class OrderController extends BaseApiController
{
    public function index(): JsonResponse
    {
        $userId = auth()->id();

        $orders = Order::query()
            ->where('seller_user_id', $userId)
            ->latest('id')
            ->get()
            ->map(function (Order $order) {
                return [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'order_date' => $order->order_date?->toIso8601String(),
                    'order_status' => $order->statusValue(),
                    'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
                    'order_channel' => $order->order_channel,
                    'subtotal_amount' => $order->subtotal_amount,
                    'discount_amount' => $order->discount_amount,
                    'net_amount' => $order->net_amount,
                    'has_invoice_file' => $order->invoice_file_path !== null,
                    'has_delivery_receipt_paths' => $order->delivery_receipt_paths !== null,
                ];
            })
            ->values();

        return $this->successResponse('api.order.index_success', [
            'orders' => $orders,
        ]);
    }

    public function create(): JsonResponse
    {
        $userId = auth()->id();
        $agentId = $userId
            ? DB::table('agents')->where('user_id', $userId)->value('id')
            : null;

        $products = collect();
        if ($agentId) {
            $products = DB::table('agent_products')
                ->join('products', 'products.id', '=', 'agent_products.product_id')
                ->where('agent_products.agent_id', $agentId)
                ->orderBy('products.name')
                ->get([
                    'products.id',
                    'products.sku',
                    'products.name',
                    'products.base_unit',
                ]);
        }

        $provinces = DB::table('provinces')
            ->orderBy('name')
            ->get(['code', 'label', 'name']);

        $wards = DB::table('wards')
            ->orderBy('province_code')
            ->orderBy('name')
            ->get(['province_code', 'code', 'label', 'name']);

        return $this->successResponse('api.order.create_success', [
            'products' => $products,
            'provinces' => $provinces,
            'wards' => $wards,
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['shippingAddress', 'pickupAddress', 'items', 'latestShipment']);

        return $this->successResponse('api.order.show_success', [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'order_date' => $order->order_date?->toIso8601String(),
            'order_status' => $order->statusValue(),
            'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
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
        ]);
    }

    /**
     * Store tối giản: header đơn + địa chỉ giao (không gồm dòng hàng — bổ sung theo domain).
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $generatedOrderNo = $this->generateUniqueOrderNoFromCurrentUserAgentCode();

        if (!$generatedOrderNo) {
            return $this->errorResponse('api.order.agent_code_not_found', 422, (object) []);
        }

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
                'order_no' => $generatedOrderNo,
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

        return $this->createdResponse('api.order.store_success', [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'order_status' => $order->statusValue(),
            'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
            ...$order->legacyCustomerAddressForApi(),
            'latest_shipment' => $order->latestShipment
                ? $order->latestShipment->toApiArray()
                : null,
        ]);
    }

    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $validated = $request->validated();

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

        return $this->successResponse('api.order.update_success', [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'order_status' => $order->statusValue(),
            'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
            ...$order->legacyCustomerAddressForApi(),
            'latest_shipment' => $order->latestShipment
                ? $order->latestShipment->toApiArray()
                : null,
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

    private function generateUniqueOrderNoFromCurrentUserAgentCode(): ?string
    {
        $userId = auth()->id();
        if (!$userId) {
            return null;
        }

        $agentCode = (string) DB::table('agents')
            ->where('user_id', $userId)
            ->value('code');

        $agentCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $agentCode) ?? '');
        if ($agentCode === '') {
            return null;
        }

        $prefix = substr($agentCode, 0, 2).substr($agentCode, -1);
        $dateSuffix = substr(now()->format('dmy'), -3);

        for ($i = 0; $i < 20; $i++) {
            $randomThreeDigits = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
            $orderNo = $prefix.$randomThreeDigits.$dateSuffix;
            $exists = Order::query()->where('order_no', $orderNo)->exists();

            if (!$exists) {
                return $orderNo;
            }
        }

        return null;
    }
}
