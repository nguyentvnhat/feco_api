<?php

namespace Modules\Order\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    public function statuses(): JsonResponse
    {
        $statuses = collect(OrderStatus::values())
            ->map(fn (string $status) => [
                'value' => $status,
                'label' => OrderStatus::orderLabelStatusForValue($status),
            ])
            ->values();

        return $this->successResponse('api.order.statuses_success', [
            'statuses' => $statuses,
        ]);
    }

    public function historyCommission(): JsonResponse
    {
        $userId = auth()->id();
        if (! $userId) {
            return $this->successResponse('api.order.history_commission_success', [
                'orders' => [],
            ]);
        }

        if (! Schema::hasTable('commission_entries') || ! Schema::hasTable('commission_policies')) {
            return $this->successResponse('api.order.history_commission_success', [
                'orders' => [],
            ]);
        }

        $rows = DB::table('orders')
            ->join('commission_entries', 'commission_entries.source_order_id', '=', 'orders.id')
            ->leftJoin('commission_policies', 'commission_policies.id', '=', 'commission_entries.policy_id')
            ->where('orders.seller_user_id', $userId)
            ->orderByDesc('orders.id')
            ->orderByDesc('commission_entries.id')
            ->get([
                'orders.id as order_id',
                'orders.order_no',
                'orders.order_date',
                'orders.order_status',
                'orders.net_amount',
                'commission_entries.id as commission_entry_id',
                'commission_entries.entry_type',
                'commission_entries.amount as commission_amount',
                'commission_entries.rate_percent',
                'commission_entries.basis_type',
                'commission_entries.basis_value',
                'commission_entries.settlement_status',
                'commission_policies.policy_code',
                'commission_policies.policy_name',
            ]);

        $orders = $rows
            ->groupBy('order_id')
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'id' => (int) $first->order_id,
                    'order_no' => $first->order_no,
                    'order_date' => $first->order_date,
                    'order_status' => (string) $first->order_status,
                    'order_label_status' => OrderStatus::orderLabelStatusForValue((string) $first->order_status),
                    'net_amount' => $this->formatVietnameseMoney($first->net_amount),
                    'currency' => $this->vietnameseMoneyCurrency(),
                    'commissions' => collect($items)->map(function ($row) {
                        return [
                            'id' => (int) $row->commission_entry_id,
                            'entry_type' => $row->entry_type,
                            'policy_code' => $row->policy_code,
                            'policy_name' => $row->policy_name,
                            'amount' => $this->formatVietnameseMoney($row->commission_amount),
                            'rate_percent' => $row->rate_percent !== null ? (float) $row->rate_percent : null,
                            'basis_type' => $row->basis_type,
                            'basis_value' => $this->formatVietnameseMoney($row->basis_value),//(float) $row->basis_value,
                            'settlement_status' => $row->settlement_status,
                            'settlement_status_label_vi' => $this->commissionSettlementStatusLabelVi((string) $row->settlement_status),
                            'currency' => $this->vietnameseMoneyCurrency(),
                        ];
                    })->values(),
                ];
            })
            ->values();

        return $this->successResponse('api.order.history_commission_success', [
            'orders' => $orders,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $limitParam = $request->query('limit');
        $limit = is_numeric($limitParam) ? (int) $limitParam : null;
        if ($limit !== null) {
            $limit = max(1, min($limit, 100));
        }

        $query = Order::query()
            ->with(['shippingAddress', 'items'])
            ->where('seller_user_id', $userId)
            ->latest('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $ordersCollection = $query->get();
        $productImagePathsById = DB::table('products')
            ->whereIn(
                'id',
                $ordersCollection
                    ->pluck('items')
                    ->flatten()
                    ->pluck('product_id')
                    ->filter()
                    ->unique()
                    ->values()
            )
            ->pluck('image_path', 'id');

        $orders = $ordersCollection
            ->map(function (Order $order) use ($productImagePathsById) {
                $customer = $order->legacyCustomerAddressForApi();

                return [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'order_date' => $order->order_date?->toIso8601String(),
                    'order_status' => $order->statusValue(),
                    'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
                    'order_channel' => $order->order_channel,
                    'subtotal_amount' => $this->formatVietnameseMoney($order->subtotal_amount),
                    'discount_amount' => $this->formatVietnameseMoney($order->discount_amount),
                    'net_amount' => $this->formatVietnameseMoney($order->net_amount),
                    'currency' => $this->vietnameseMoneyCurrency(),
                    'customer' => $customer,
                    'shipping' => $order->shippingAddress,
                    'has_invoice_file' => $order->invoice_file_path !== null,
                    'has_delivery_receipt_paths' => $order->delivery_receipt_paths !== null,
                    'products' => $order->items->map(function ($item) use ($productImagePathsById) {
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'product_name' => $item->product_name_snapshot,
                            'image_path' => $this->buildAbsoluteImageUrl($productImagePathsById->get($item->product_id)),
                            'unit' => $item->unit,
                            'quantity' => (float) $item->quantity,
                            'quantity_in_base_unit' => (float) $item->quantity_in_base_unit,
                            'unit_price' => $this->formatVietnameseMoney($item->unit_price),
                            'line_amount' => $this->formatVietnameseMoney($item->line_amount),
                            'currency' => $this->vietnameseMoneyCurrency(),
                        ];
                    })->values(),
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
        $productImagePathsById = DB::table('products')
            ->whereIn('id', $order->items->pluck('product_id')->filter()->unique()->values())
            ->pluck('image_path', 'id');

        return $this->successResponse('api.order.show_success', [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'order_date' => $order->order_date?->toIso8601String(),
            'order_status' => $order->statusValue(),
            'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
            'order_channel' => $order->order_channel,
            'subtotal_amount' => $this->formatVietnameseMoney($order->subtotal_amount),
            'discount_amount' => $this->formatVietnameseMoney($order->discount_amount),
            'net_amount' => $this->formatVietnameseMoney($order->net_amount),
            'currency' => $this->vietnameseMoneyCurrency(),
            ...$order->legacyCustomerAddressForApi(),
            'shipping' => $order->shippingAddress,
            'pickup' => $order->pickupAddress,
            'has_invoice_file' => $order->invoice_file_path !== null,
            'has_delivery_receipt_paths' => $order->delivery_receipt_paths !== null,
            'products' => $order->items->map(function ($item) use ($productImagePathsById) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name_snapshot,
                    'image_path' => $this->buildAbsoluteImageUrl($productImagePathsById->get($item->product_id)),
                    'unit' => $item->unit,
                    'quantity' => (float) $item->quantity,
                    'quantity_in_base_unit' => (float) $item->quantity_in_base_unit,
                    'unit_price' => $this->formatVietnameseMoney($item->unit_price),
                    'line_amount' => $this->formatVietnameseMoney($item->line_amount),
                    'currency' => $this->vietnameseMoneyCurrency(),
                ];
            })->values(),
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

    private function commissionSettlementStatusLabelVi(string $status): string
    {
        return match ($status) {
            'pending' => 'Chờ duyệt',
            'approved' => 'Đã duyệt',
            'paid' => 'Đã thanh toán',
            'rejected' => 'Từ chối',
            default => $status,
        };
    }

    private function buildAbsoluteImageUrl(?string $imagePath): ?string
    {
        if (! $imagePath) {
            return null;
        }

        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            return $imagePath;
        }

        $baseUrl = rtrim((string) (config('app.url_image') ?: config('app.url')), '/');
        if ($baseUrl === '') {
            return '/'.ltrim($imagePath, '/');
        }

        return $baseUrl.'/'.ltrim($imagePath, '/');
    }

}
