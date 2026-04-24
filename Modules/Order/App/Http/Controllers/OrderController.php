<?php

namespace Modules\Order\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\App\Http\Requests\StoreOrderRequest;
use Modules\Order\App\Http\Requests\UpdateOrderRequest;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderAddress;
use Modules\Order\Models\OrderItem;

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
            ->where('orders.order_status', OrderStatus::DELIVERED->value)
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

        return $this->successResponse('api.order.index_success', [
            'orders' => $this->buildOrdersListPayload($userId, $limit),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = auth()->id();
        $keyword = (string) ($validated['q'] ?? '');
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : null;

        return $this->successResponse('api.order.index_success', [
            'orders' => $this->buildOrdersListPayload($userId, $limit, $keyword),
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
            $products = $this->catalogProductsByAgentId($agentId)
                ->map(function ($product) {
                    return [
                        'id' => (int) $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'base_unit' => $product->base_unit,
                        'unit_price' => $this->formatVietnameseMoney($product->unit_price),
                        'currency' => $this->vietnameseMoneyCurrency(),
                    ];
                })
                ->values();
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
     * Store: header đơn + địa chỉ giao + dòng hàng products[] và tự tính tiền.
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
        $requestedProducts = collect($validated['products'] ?? [])
            ->map(fn ($row) => [
                'product_id' => (int) ($row['product_id'] ?? 0),
                'quantity' => (float) ($row['quantity'] ?? 0),
            ])
            ->filter(fn ($row) => $row['product_id'] > 0 && $row['quantity'] > 0)
            ->values();

        if ($requestedProducts->isEmpty()) {
            return $this->validationErrorResponse([
                'products' => ['Đơn hàng phải có ít nhất 1 sản phẩm hợp lệ.'],
            ]);
        }

        $catalogAgentId = auth()->id()
            ? (int) (DB::table('agents')->where('user_id', auth()->id())->value('id') ?? 0)
            : 0;
        $catalogProducts = $this->catalogProductsByAgentId($catalogAgentId > 0 ? $catalogAgentId : null)
            ->keyBy('id');
        $orderItemsPayload = [];
        $subtotal = 0.0;
        foreach ($requestedProducts as $row) {
            $product = $catalogProducts->get($row['product_id']);
            if (! $product) {
                return $this->validationErrorResponse([
                    'products' => ["Sản phẩm #{$row['product_id']} không thuộc danh mục được phép đặt."],
                ]);
            }

            $quantity = (float) $row['quantity'];
            $unitPrice = (float) ($product->unit_price ?? 0);
            $lineAmount = round($unitPrice * $quantity, 2);
            $subtotal += $lineAmount;

            $orderItemsPayload[] = [
                'product_id' => (int) $product->id,
                'product_name_snapshot' => (string) ($product->name ?? ''),
                'unit' => (string) ($product->base_unit ?? ''),
                'quantity' => $quantity,
                'quantity_in_base_unit' => $quantity,
                'unit_price' => $unitPrice,
                'line_amount' => $lineAmount,
            ];
        }

        if ($subtotal <= 0) {
            return $this->validationErrorResponse([
                'products' => ['Đơn hàng 0đ không hợp lệ. Vui lòng kiểm tra giá và số lượng sản phẩm.'],
            ]);
        }

        $discountAmount = 0.0;
        $netAmount = $subtotal - $discountAmount;

        $payload = collect($validated)
            ->except([
                'customer_province_code',
                'customer_district_code',
                'customer_district_name',
                'customer_ward_code',
                'products',
            ])
            ->merge([
                'order_no' => $generatedOrderNo,
                'order_date' => $orderDate,
                'order_month' => $orderDate->format('Y-m'),
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discountAmount,
                'net_amount' => $netAmount,
            ])
            ->all();

        $order = DB::transaction(function () use ($payload, $orderItemsPayload, $addressAttrs) {
            $order = Order::query()->create($payload);
            $items = collect($orderItemsPayload)
                ->map(fn ($item) => new OrderItem($item))
                ->all();
            $order->items()->saveMany($items);
            OrderAddress::query()->updateOrCreate(
                [
                    'order_id' => $order->id,
                    'type' => OrderAddress::TYPE_SHIPPING,
                ],
                $addressAttrs
            );
            $this->upsertDefaultPickupAddressFromShipping($order, $addressAttrs);
            $order->syncLegacyCustomerColumnsFromShippingAddress();

            return $order->fresh(['shippingAddress', 'latestShipment']);
        });

        return $this->createdResponse('api.order.store_success', [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'order_status' => $order->statusValue(),
            'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
            'subtotal_amount' => $this->formatVietnameseMoney($order->subtotal_amount),
            'discount_amount' => $this->formatVietnameseMoney($order->discount_amount),
            'net_amount' => $this->formatVietnameseMoney($order->net_amount),
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
            ? DB::table('provinces')->where('code', $provinceCode)->value('name')
            : null;
        $wardName = ($wardCode && $provinceCode)
            ? DB::table('wards')
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

    /**
     * @param  array<string, mixed>  $shippingAttrs
     */
    private function upsertDefaultPickupAddressFromShipping(Order $order, array $shippingAttrs): void
    {
        OrderAddress::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'type' => OrderAddress::TYPE_PICKUP,
            ],
            [
                'full_name' => $shippingAttrs['full_name'] ?? null,
                'phone' => $shippingAttrs['phone'] ?? null,
                'address_line' => $shippingAttrs['address_line'] ?? null,
                'province_code' => $shippingAttrs['province_code'] ?? null,
                'province_name' => $shippingAttrs['province_name'] ?? null,
                'district_code' => $shippingAttrs['district_code'] ?? null,
                'district_name' => $shippingAttrs['district_name'] ?? null,
                'ward_code' => $shippingAttrs['ward_code'] ?? null,
                'ward_name' => $shippingAttrs['ward_name'] ?? null,
            ]
        );
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

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildOrdersListPayload(?int $userId, ?int $limit = null, ?string $keyword = null)
    {
        $query = Order::query()
            ->with(['shippingAddress', 'items'])
            ->where('seller_user_id', $userId)
            ->latest('id');

        $normalizedKeyword = trim((string) $keyword);
        if ($normalizedKeyword !== '') {
            $orderNoKeyword = ltrim($normalizedKeyword, '#');
            $query->where(function ($subQuery) use ($normalizedKeyword, $orderNoKeyword) {
                $subQuery->where('order_no', 'like', '%'.$orderNoKeyword.'%');
                if (Schema::hasColumn('orders', 'customer_name')) {
                    $subQuery->orWhere('customer_name', 'like', '%'.$normalizedKeyword.'%');
                }
            });
        }

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

        return $ordersCollection
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
    }

    private function catalogProductsByAgentId(?int $agentId)
    {
        $query = DB::table('products')
            ->join('agent_products', 'agent_products.product_id', '=', 'products.id')
            ->where('agent_products.agent_id', $agentId)
            ->orderBy('products.name')
            ->select([
                'products.id',
                'products.sku',
                'products.name',
                'products.base_unit',
                'agent_products.product_id as ap_product_id',
            ]);

        if (Schema::hasColumn('agent_products', 'unit_price')) {
            $query->addSelect('agent_products.unit_price as ap_unit_price');
        }
        if (Schema::hasColumn('agent_products', 'price')) {
            $query->addSelect('agent_products.price as ap_price');
        }
        if (Schema::hasColumn('products', 'unit_price')) {
            $query->addSelect('products.unit_price as product_unit_price');
        }
        if (Schema::hasColumn('products', 'price')) {
            $query->addSelect('products.price as product_price');
        }
        if (Schema::hasColumn('products', 'list_price')) {
            $query->addSelect('products.list_price as product_list_price');
        }

        return $query->get()->map(function ($row) {
            $unitPrice = $this->resolveProductUnitPrice($row);
            $row->unit_price = $unitPrice;

            return $row;
        });
    }

    private function resolveProductUnitPrice(object $row): float
    {
        $candidates = [
            $row->ap_unit_price ?? null,
            $row->ap_price ?? null,
            $row->product_unit_price ?? null,
            $row->product_price ?? null,
            $row->product_list_price ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return 0.0;
    }

}
