<?php

namespace Modules\Order\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Modules\Agent\Support\AgentOrderScope;
use Modules\Order\App\Mail\AgentOrderCancelledDirectEmployeeMail;
use Modules\Order\App\Mail\AgentOrderCreatedDirectEmployeeMail;
use Modules\Order\App\Http\Requests\PreviewOrderRequest;
use Modules\Order\App\Http\Requests\StoreOrderRequest;
use Modules\Order\App\Http\Requests\UpdateOrderRequest;
use Modules\Order\App\Services\AgentMonthlyBonusService;
use Modules\Order\App\Services\OrderPricingService;
use Modules\Order\App\Services\ProductUnitConverter;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderAddress;
use Modules\Order\Models\OrderItem;
use Modules\Order\Support\AgentSelfPurchaseDiscount;
use Modules\Order\Support\OrderAuditLogger;
use Modules\Order\Support\OrderCommissionEligibility;
use Modules\Order\Support\OrderDisplayPricing;
use Modules\Order\Support\OrderVatBreakdown;

/**
 * Ví dụ API: trả về địa chỉ theo format legacy (customer_*) từ shippingAddress.
 *
 * Store/Update: tạo/ghi Order rồi gọi OrderAddress::updateOrCreate + syncLegacyCustomerColumnsFromShippingAddress()
 * giống Admin\OrderController::store / update (cùng rule validation).
 */
class OrderController extends BaseApiController
{
    public function __construct(
        private readonly OrderPricingService $orderPricingService,
        private readonly AgentMonthlyBonusService $agentMonthlyBonusService,
    ) {
    }

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

    public function historyCommission(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('api.auth.invalid_credentials', 401, (object) []);
        }

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'paid', 'rejected'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'all' => ['nullable', Rule::in([0, 1, '0', '1', true, false, 'true', 'false'])],
        ]);

        if (! Schema::hasTable('agent_profiles')) {
            return $this->errorResponse('api.order.history_commission_no_agent_profile', 422, (object) []);
        }

        $agentProfile = DB::table('agent_profiles')
            ->where('user_id', $user->id)
            ->first(['id', 'user_id']);

        if ($agentProfile === null) {
            return $this->errorResponse('api.order.history_commission_no_agent_profile', 422, (object) []);
        }

        $beneficiaryUserId = (int) $agentProfile->user_id;
        $allPeriods = $this->parseBooleanQueryValue($validated['all'] ?? false);
        $periodMonth = $allPeriods ? null : (string) ($validated['month'] ?? now()->format('Y-m'));
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 20;
        $statusFilter = isset($validated['status']) ? (string) $validated['status'] : null;

        $emptyPayload = [
            'period_month' => $periodMonth,
            'summary' => $this->formatCommissionHistorySummary(collect()),
            'entries' => [],
        ];

        if (! Schema::hasTable('commission_entries')) {
            return $this->successResponse('api.order.history_commission_success', $emptyPayload);
        }

        $baseQuery = DB::table('commission_entries as ce')
            ->leftJoin('orders as o', 'o.id', '=', 'ce.source_order_id')
            ->where('ce.beneficiary_user_id', $beneficiaryUserId)
            ->where('ce.entry_type', '!=', 'discount');

        if ($periodMonth !== null && $periodMonth !== '') {
            $this->applyCommissionHistoryMonthFilter($baseQuery, $periodMonth);
        }

        if ($statusFilter !== null) {
            $baseQuery->where('ce.settlement_status', $statusFilter);
        }

        $summaryRows = (clone $baseQuery)->get([
            'ce.amount',
            'ce.settlement_status',
        ]);

        $entryRows = (clone $baseQuery)
            ->orderByDesc('ce.id')
            ->limit($limit)
            ->get([
                'ce.id',
                'ce.source_order_id as order_id',
                'o.order_no',
                'ce.amount',
                'ce.rate_percent',
                'ce.basis_type',
                'ce.basis_value',
                'ce.settlement_status',
                'ce.created_at',
            ]);

        $entries = $entryRows->map(fn ($row) => [
            'id' => (int) $row->id,
            'order_id' => $row->order_id !== null ? (int) $row->order_id : null,
            'order_no' => $row->order_no !== null ? (string) $row->order_no : null,
            'amount' => $this->formatVietnameseMoney($row->amount),
            'rate_percent' => $row->rate_percent !== null ? (float) $row->rate_percent : null,
            'basis_type' => $row->basis_type !== null ? (string) $row->basis_type : null,
            'basis_value' => $this->formatVietnameseMoney($row->basis_value),
            'settlement_status' => (string) $row->settlement_status,
            'settlement_status_label_vi' => $this->commissionSettlementStatusLabelVi((string) $row->settlement_status),
            'created_at' => $row->created_at !== null
                ? now()->parse($row->created_at)->toIso8601String()
                : null,
        ])->values()->all();

        return $this->successResponse('api.order.history_commission_success', [
            'period_month' => $periodMonth,
            'summary' => $this->formatCommissionHistorySummary($summaryRows),
            'entries' => $entries,
        ]);
    }

    public function historyDiscount(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('api.auth.invalid_credentials', 401, (object) []);
        }

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'all' => ['nullable', Rule::in([0, 1, '0', '1', true, false, 'true', 'false'])],
        ]);

        if (! Schema::hasTable('agent_profiles')) {
            return $this->errorResponse('api.order.history_discount_no_agent_profile', 422, (object) []);
        }

        $agentProfile = DB::table('agent_profiles')
            ->where('user_id', $user->id)
            ->first(['id', 'user_id']);

        if ($agentProfile === null) {
            return $this->errorResponse('api.order.history_discount_no_agent_profile', 422, (object) []);
        }

        $allPeriods = $this->parseBooleanQueryValue($validated['all'] ?? false);
        $periodMonth = $allPeriods ? null : (string) ($validated['month'] ?? now()->format('Y-m'));
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 20;

        $emptyPayload = [
            'period_month' => $periodMonth,
            'summary' => $this->formatDiscountHistorySummary(0, 0),
            'entries' => [],
        ];

        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'agent_profile_id')) {
            return $this->successResponse('api.order.history_discount_success', $emptyPayload);
        }

        $ordersQuery = Order::query()
            ->with('items')
            ->where('agent_profile_id', (int) $agentProfile->id)
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        if ($periodMonth !== null && $periodMonth !== '') {
            if (Schema::hasColumn('orders', 'order_month')) {
                $ordersQuery->where('order_month', $periodMonth);
            } elseif (Schema::hasColumn('orders', 'order_date')) {
                $ordersQuery->whereRaw("DATE_FORMAT(order_date, '%Y-%m') = ?", [$periodMonth]);
            }
        }

        $pricing = app(OrderDisplayPricing::class);
        $discountRows = [];

        foreach ($ordersQuery->get() as $order) {
            $discount = $pricing->paymentBreakdown($order)['discount_amount'];
            if ($discount <= 0.009) {
                continue;
            }

            $discountRows[] = [
                'order' => $order,
                'discount' => $discount,
            ];
        }

        $totalDiscount = array_sum(array_column($discountRows, 'discount'));

        $entries = collect($discountRows)
            ->take($limit)
            ->map(function (array $row) {
                /** @var Order $order */
                $order = $row['order'];
                $discount = (float) $row['discount'];

                return [
                    'id' => (int) $order->id,
                    'order_id' => (int) $order->id,
                    'order_no' => (string) $order->order_no,
                    'amount' => $this->formatVietnameseMoney($discount),
                    'rate_percent' => null,
                    'basis_type' => null,
                    'basis_value' => null,
                    'settlement_status' => $order->statusValue(),
                    'settlement_status_label_vi' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
                    'created_at' => $order->order_date?->toIso8601String()
                        ?? $order->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return $this->successResponse('api.order.history_discount_success', [
            'period_month' => $periodMonth,
            'summary' => $this->formatDiscountHistorySummary($totalDiscount, count($discountRows)),
            'entries' => $entries,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $limit = isset($validated['limit']) ? (int) $validated['limit'] : null;
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;

        return $this->successResponse('api.order.index_success', $this->buildOrdersListPayload($userId, $limit, null, $page));
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $userId = auth()->id();
        $keyword = (string) ($validated['q'] ?? '');
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : null;
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;

        return $this->successResponse('api.order.index_success', $this->buildOrdersListPayload($userId, $limit, $keyword, $page));
    }

    public function childAgentOrders(Request $request, int $childAgent): JsonResponse
    {
        $userId = auth()->id();
        $parentAgentId = DB::table('agents')->where('user_id', $userId)->value('id');
        if ($parentAgentId === null) {
            return $this->errorResponse('api.agent.current_agent_not_found', 422, (object) []);
        }

        $child = DB::table('agents')
            ->where('id', $childAgent)
            ->where('parent_agent_id', (int) $parentAgentId)
            ->first(['id', 'user_id', 'code']);

        if ($child === null) {
            return $this->errorResponse('api.errors.not_found', 404, (object) []);
        }

        $agentProfileId = AgentOrderScope::resolveAgentProfileId($child);
        $sellerUserId = AgentOrderScope::sellerUserIdForAgent($child);
        if ($agentProfileId === null && $sellerUserId === null) {
            return $this->successResponse('api.order.index_success', [
                'orders' => [],
                'meta' => [
                    'page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                    'has_more' => false,
                ],
            ]);
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $keyword = trim((string) ($validated['q'] ?? ''));
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : null;
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;

        return $this->successResponse(
            'api.order.index_success',
            $this->buildOrdersListPayload(null, $limit, $keyword !== '' ? $keyword : null, $page, $agentProfileId, $sellerUserId)
        );
    }

    public function create(): JsonResponse
    {
        $userId = auth()->id();
        $agentId = $userId
            ? DB::table('agents')->where('user_id', $userId)->value('id')
            : null;

        $products = collect();
        if ($agentId) {
            $converter = app(ProductUnitConverter::class);
            $products = $this->catalogProductsByAgentId($agentId)
                ->map(function ($product) use ($converter) {
                    $baseListPrice = $converter->baseUnitListPrice($product);
                    $saleListPrice = $converter->saleUnitListPrice($product, $baseListPrice);

                    return [
                        'id' => (int) $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'sale_unit' => $converter->resolveSaleUnit($product),
                        'unit_price' => $saleListPrice !== null
                            ? $this->formatVietnameseMoney($saleListPrice)
                            : $this->formatVietnameseMoney($product->unit_price),
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

        return $this->successResponse('api.order.show_success', array_merge(
            [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'order_date' => $order->order_date?->toIso8601String(),
                'order_status' => $order->statusValue(),
                'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
                'order_channel' => $order->order_channel,
                'applied_tiers' => $this->formatAppliedTiersFromOrderDiscountSnapshot($order),
                ...$order->legacyCustomerAddressForApi(),
                'shipping' => $order->shippingAddress,
                'pickup' => $order->pickupAddress,
                'has_invoice_file' => $order->invoice_file_path !== null,
                'has_delivery_receipt_paths' => $order->delivery_receipt_paths !== null,
                'products' => $order->items->map(function ($item) use ($productImagePathsById) {
                    return $this->formatOrderItemForApi(
                        $item,
                        $productImagePathsById->get($item->product_id)
                    );
                })->values(),
                'latest_shipment' => $order->latestShipment
                    ? $order->latestShipment->toApiArray()
                    : null,
            ],
            $this->formatOrderAmountsForApi($order)
        ));
    }

    public function cloneTemplate(int $order): JsonResponse
    {
        $query = Order::query()
            ->with(['shippingAddress', 'items']);
        $this->applyAuthenticatedAgentOrdersScope($query, auth()->id());

        /** @var Order|null $sourceOrder */
        $sourceOrder = $query->whereKey($order)->first();
        if (! $sourceOrder) {
            return $this->errorResponse('api.errors.not_found', 404, (object) []);
        }

        $shipping = $sourceOrder->shippingAddress;
        $customerName = trim((string) ($shipping?->full_name ?? $sourceOrder->customer_name ?? ''));
        $customerPhone = trim((string) ($shipping?->phone ?? $sourceOrder->customer_phone ?? ''));
        $addressLine = trim((string) ($shipping?->address_line ?? $sourceOrder->customer_address ?? ''));
        $provinceCode = trim((string) ($shipping?->province_code ?? ''));
        $wardCode = trim((string) ($shipping?->ward_code ?? ''));

        $products = $sourceOrder->items
            ->map(function (OrderItem $item) {
                return [
                    'product_id' => (int) $item->product_id,
                    'quantity' => (float) $item->quantity,
                ];
            })
            ->filter(fn (array $row) => $row['product_id'] > 0 && $row['quantity'] > 0)
            ->values()
            ->all();

        if ($products === []) {
            return $this->validationErrorResponse([
                'products' => ['Đơn nguồn không có sản phẩm hợp lệ để đặt lại.'],
            ]);
        }

        return $this->successResponse('api.order.clone_template_success', [
            'source_order' => [
                'id' => $sourceOrder->id,
                'order_no' => $sourceOrder->order_no,
            ],
            'clone_payload' => [
                'order_channel' => (string) ($sourceOrder->order_channel ?? 'agent_order'),
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_address' => $addressLine !== '' ? $addressLine : null,
                'customer_province_code' => $provinceCode,
                'customer_ward_code' => $wardCode,
                'products' => $products,
            ],
        ]);
    }

    public function preview(PreviewOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('api.auth.invalid_credentials', 401, (object) []);
        }

        $agentProfileId = (int) (DB::table('agent_profiles')->where('user_id', $user->id)->value('id') ?? 0);
        if ($agentProfileId <= 0) {
            return $this->errorResponse('api.order.preview_no_agent_profile', 422, (object) []);
        }

        $catalogAgentId = (int) (DB::table('agents')->where('user_id', $user->id)->value('id') ?? 0);
        if ($catalogAgentId <= 0) {
            return $this->errorResponse('api.agent.current_agent_not_found', 422, (object) []);
        }

        $validated = $request->validated();
        $requestedProducts = collect($validated['products'] ?? [])
            ->map(fn ($row) => [
                'product_id' => (int) ($row['product_id'] ?? 0),
                'quantity' => (float) ($row['quantity'] ?? 0),
            ])
            ->filter(fn ($row) => $row['product_id'] > 0 && $row['quantity'] > 0)
            ->values();

        $catalogProducts = $this->catalogProductsByAgentId($catalogAgentId)->keyBy('id');
        $built = $this->buildOrderLinesFromCatalog($catalogProducts, $requestedProducts);
        if (isset($built['errors'])) {
            return $this->validationErrorResponse($built['errors']);
        }

        if ($built['subtotal'] <= 0) {
            return $this->validationErrorResponse([
                'products' => ['Đơn hàng 0đ không hợp lệ. Vui lòng kiểm tra giá và số lượng sản phẩm.'],
            ]);
        }

        try {
            $orderDate = now();
            $pricing = $this->orderPricingService->buildPricingPayload(
                $agentProfileId,
                $orderDate->toDateString(),
                $orderDate->format('Y-m'),
                $built['lines'],
                null,
                AgentSelfPurchaseDiscount::CHANNEL_AGENT_ORDER,
            );

            return $this->successResponse('api.order.preview_success', $this->formatOrderPricingApiData($pricing));
        } catch (\Throwable $e) {
            Log::error('api.order.preview_failed', [
                'user_id' => $user->id,
                'agent_profile_id' => $agentProfileId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('api.errors.unexpected', 500, (object) []);
        }
    }

    /**
     * Store: header đơn + địa chỉ giao + dòng hàng products[] và tự tính tiền.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $generatedOrderNo = $this->generateUniqueOrderNoFromCurrentUserAgentCode();

            if (! $generatedOrderNo) {
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

            $built = $this->buildOrderLinesFromCatalog($catalogProducts, $requestedProducts);
            if (isset($built['errors'])) {
                return $this->validationErrorResponse($built['errors']);
            }

            if ($built['subtotal'] <= 0) {
                return $this->validationErrorResponse([
                    'products' => ['Đơn hàng 0đ không hợp lệ. Vui lòng kiểm tra giá và số lượng sản phẩm.'],
                ]);
            }

            $resolvedAgentProfileId = auth()->id()
                ? (int) (DB::table('agent_profiles')->where('user_id', auth()->id())->value('id') ?? 0)
                : 0;
            if (($validated['order_channel'] ?? '') === 'agent_order' && $resolvedAgentProfileId > 0) {
                $validated['agent_profile_id'] = $resolvedAgentProfileId;
            }

            $pricing = null;
            $orderChannel = (string) ($validated['order_channel'] ?? '');
            if (AgentSelfPurchaseDiscount::qualifies($orderChannel, $resolvedAgentProfileId > 0 ? $resolvedAgentProfileId : null)) {
                $pricing = $this->orderPricingService->buildPricingPayload(
                    $resolvedAgentProfileId,
                    $orderDate->toDateString(),
                    $orderDate->format('Y-m'),
                    $built['lines'],
                    null,
                    $orderChannel,
                );
            }

            $subtotal = $pricing !== null
                ? (float) $pricing['summary']['subtotal_amount']
                : (float) $built['subtotal'];
            $discountAmount = $pricing !== null
                ? (float) $pricing['summary']['discount_amount']
                : 0.0;
            $netAmount = $pricing !== null
                ? (float) $pricing['summary']['net_amount']
                : $subtotal;

            $orderItemsPayload = array_map(static function (array $line) {
                return [
                    'product_id' => (int) $line['product_id'],
                    'product_name_snapshot' => (string) ($line['product_name'] ?? ''),
                    'unit' => (string) ($line['unit'] ?? ''),
                    'quantity' => (float) $line['quantity'],
                    'quantity_in_base_unit' => (float) ($line['quantity_in_base_unit'] ?? $line['quantity']),
                    'unit_price' => (float) $line['unit_price'],
                    'line_amount' => (float) $line['line_amount'],
                ];
            }, $built['lines']);

            $mergeAmounts = array_merge([
                'order_no' => $generatedOrderNo,
                'order_date' => $orderDate,
                'order_month' => $orderDate->format('Y-m'),
                'created_source' => 'api',
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discountAmount,
                'net_amount' => $netAmount,
            ], $pricing !== null
                ? [
                    'vat_rate_percent' => (float) $pricing['summary']['vat_rate_percent'],
                    'vat_amount' => (float) $pricing['summary']['vat_amount'],
                    'total_with_vat' => (float) $pricing['summary']['total_with_vat'],
                ]
                : OrderVatBreakdown::persistFields($subtotal, $discountAmount, null));

            if ($pricing !== null) {
                $mergeAmounts['applied_discount_policy_id'] = $pricing['applied_discount_policy_id'];
                $mergeAmounts['monthly_qty_before'] = $pricing['monthly_qty_before'];
                $mergeAmounts['monthly_qty_after'] = $pricing['monthly_qty_after'];
                if ($pricing['discount_snapshot_json'] !== null) {
                    $mergeAmounts['discount_snapshot_json'] = $pricing['discount_snapshot_json'];
                }
            }

            $payload = collect($validated)
                ->except([
                    'customer_province_code',
                    'customer_district_code',
                    'customer_district_name',
                    'customer_ward_code',
                    'products',
                ])
                ->merge($mergeAmounts)
                ->all();

            $order = DB::transaction(function () use ($payload, $orderItemsPayload, $addressAttrs, $pricing) {
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

                if ($pricing !== null
                    && ($pricing['breakdown_rows'] ?? []) !== []
                    && Schema::hasTable('order_discount_breakdowns')) {
                    $this->orderPricingService->persistDiscountBreakdowns($order->id, $pricing['breakdown_rows']);
                }

                return $order->fresh(['shippingAddress', 'latestShipment', 'items']);
            });
            $this->notifyDirectEmployeeAboutNewAgentOrder($order);
            $this->agentMonthlyBonusService->syncForOrder($order->fresh(['items']));

            return $this->createdResponse('api.order.store_success', array_merge(
                [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'order_status' => $order->statusValue(),
                    'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
                    ...$order->legacyCustomerAddressForApi(),
                    'latest_shipment' => $order->latestShipment
                        ? $order->latestShipment->toApiArray()
                        : null,
                ],
                $this->formatOrderAmountsForApi($order->loadMissing('items'))
            ));
        } catch (\Throwable $exception) {
            Log::error('api.order.store_failed', [
                'user_id' => auth()->id(),
                'error' => $exception->getMessage(),
            ]);

            return $this->errorResponse('api.errors.unexpected', 500, (object) []);
        }
    }

    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $validated = $request->validated();
        $previousStatus = (string) $order->statusValue();
        $newStatus = (string) ($validated['order_status'] ?? $previousStatus);

        OrderCommissionEligibility::assertTransitionAllowed($order, $newStatus);

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

        $order->refresh()->load(['shippingAddress', 'latestShipment', 'items']);

        if ($previousStatus !== $newStatus) {
            $this->agentMonthlyBonusService->syncForOrder($order->fresh(['items']));
        }

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

    public function destroy(int $order): JsonResponse
    {
        $query = Order::query()->whereKey($order);
        $this->applyAuthenticatedAgentOrdersScope($query, auth()->id());
        $targetOrder = $query->first();

        if (! $targetOrder) {
            return $this->errorResponse('api.errors.not_found', 404, (object) []);
        }

        DB::transaction(function () use ($targetOrder): void {
            $targetOrder->statusHistories()->delete();
            $targetOrder->internalNotes()->delete();
            $targetOrder->shipments()->delete();
            $targetOrder->addresses()->delete();
            $targetOrder->items()->delete();
            $targetOrder->delete();
        });

        return $this->successResponse('api.order.destroy_success', (object) []);
    }

    public function cancel(int $order): JsonResponse
    {
        $query = Order::query()->whereKey($order);
        $this->applyAuthenticatedAgentOrdersScope($query, auth()->id());
        $targetOrder = $query->first();

        if (! $targetOrder) {
            return $this->errorResponse('api.errors.not_found', 404, (object) []);
        }

        $currentStatus = (string) $targetOrder->statusValue();
        if (in_array($currentStatus, [OrderStatus::CANCELLED->value, OrderStatus::RETURNED->value], true)) {
            return $this->successResponse('api.order.cancel_already_done', (object) []);
        }

        if ($currentStatus !== OrderStatus::NEW->value) {
            return $this->errorResponse('api.order.cancel_not_allowed', 422, (object) [], [
                'order_no' => (string) $targetOrder->order_no,
            ]);
        }

        try {
            $beforeSnapshot = OrderAuditLogger::snapshot($targetOrder);

            DB::transaction(function () use ($targetOrder, $currentStatus): void {
                $targetOrder->update(['order_status' => OrderStatus::CANCELLED->value]);

                if (Schema::hasTable('order_status_histories')) {
                    $targetOrder->statusHistories()->create([
                        'from_status' => $currentStatus,
                        'to_status' => OrderStatus::CANCELLED->value,
                        'source_type' => 'manual',
                        'source_ref_id' => null,
                        'changed_by_user_id' => auth()->id(),
                        'note' => 'Agent cancelled order from mobile app.',
                    ]);
                }
            });

            $targetOrder->refresh();
            OrderAuditLogger::log(
                'order.updated',
                $targetOrder,
                $beforeSnapshot,
                OrderAuditLogger::snapshot($targetOrder->fresh(['items'])),
                request(),
            );
        } catch (\Throwable $e) {
            Log::error('api.order.cancel_failed', [
                'order_id' => $targetOrder->id,
                'order_no' => $targetOrder->order_no,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('api.errors.unexpected', 500, (object) []);
        }
        $targetOrder->refresh();
        $this->agentMonthlyBonusService->syncForOrder($targetOrder->fresh(['items']));
        $this->notifyDirectEmployeeAboutCancelledAgentOrder($targetOrder);

        return $this->successResponse('api.order.cancel_success', (object) []);
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

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function applyCommissionHistoryMonthFilter($query, string $periodMonth): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'order_month')) {
            $query->where(function ($w) use ($periodMonth): void {
                $w->where(function ($w2) use ($periodMonth): void {
                    $w2->whereNotNull('ce.source_order_id')
                        ->where('o.order_month', $periodMonth);
                })->orWhere(function ($w2) use ($periodMonth): void {
                    $w2->whereNull('ce.source_order_id')
                        ->whereRaw("DATE_FORMAT(ce.created_at, '%Y-%m') = ?", [$periodMonth]);
                });
            });

            return;
        }

        $query->whereRaw("DATE_FORMAT(ce.created_at, '%Y-%m') = ?", [$periodMonth]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object{amount: mixed, settlement_status: mixed}>  $rows
     * @return array{
     *     total_commission: string,
     *     pending_commission: string,
     *     approved_commission: string,
     *     paid_commission: string,
     *     entry_count: int
     * }
     */
    private function formatCommissionHistorySummary(Collection $rows): array
    {
        $sumByStatus = [
            'pending' => '0',
            'approved' => '0',
            'paid' => '0',
        ];
        $total = '0';

        foreach ($rows as $row) {
            $amount = $this->toMoneyBcString($row->amount ?? '0');
            $total = bcadd($total, $amount, 2);
            $status = (string) ($row->settlement_status ?? '');
            if (isset($sumByStatus[$status])) {
                $sumByStatus[$status] = bcadd($sumByStatus[$status], $amount, 2);
            }
        }

        return [
            'total_commission' => $this->formatVietnameseMoney($total),
            'pending_commission' => $this->formatVietnameseMoney($sumByStatus['pending']),
            'approved_commission' => $this->formatVietnameseMoney($sumByStatus['approved']),
            'paid_commission' => $this->formatVietnameseMoney($sumByStatus['paid']),
            'entry_count' => $rows->count(),
        ];
    }

    /**
     * @return array{
     *     total_commission: string,
     *     pending_commission: string,
     *     approved_commission: string,
     *     paid_commission: string,
     *     entry_count: int
     * }
     */
    private function formatDiscountHistorySummary(float $totalDiscount, int $entryCount): array
    {
        $formatted = $this->formatVietnameseMoney($totalDiscount);

        return [
            'total_commission' => $formatted,
            'pending_commission' => $this->formatVietnameseMoney(0),
            'approved_commission' => $this->formatVietnameseMoney(0),
            'paid_commission' => $formatted,
            'entry_count' => $entryCount,
        ];
    }

    private function toMoneyBcString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $normalized = is_string($value) ? trim($value) : (string) $value;

        return bcadd($normalized, '0', 2);
    }

    private function parseBooleanQueryValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true'], true);
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
     * Đơn thuộc đại lý đang đăng nhập: ưu tiên {@see Order::agent_profile_id}
     * (gồm đơn tạo từ admin), fallback {@see Order::seller_user_id} nếu không có hồ sơ đại lý.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Order>  $query
     */
    private function applyAuthenticatedAgentOrdersScope($query, ?int $userId): void
    {
        if ($userId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $agentProfileId = null;
        if (Schema::hasTable('agent_profiles') && Schema::hasColumn('agent_profiles', 'user_id')) {
            $agentProfileId = DB::table('agent_profiles')->where('user_id', $userId)->value('id');
        }

        if ($agentProfileId !== null && Schema::hasColumn('orders', 'agent_profile_id')) {
            $query->where('agent_profile_id', (int) $agentProfileId);

            return;
        }

        $query->where('seller_user_id', $userId);
    }

    private function resolveAgentProfileIdForAgentRecord(object $agent): ?int
    {
        return AgentOrderScope::resolveAgentProfileId($agent);
    }

    /**
     * @return array{orders: \Illuminate\Support\Collection<int, array<string, mixed>>, meta?: array{page: int, per_page: int, total: int, has_more: bool}}
     */
    private function buildOrdersListPayload(
        ?int $userId,
        ?int $limit = null,
        ?string $keyword = null,
        int $page = 1,
        ?int $agentProfileId = null,
        ?int $sellerUserId = null,
    )
    {
        $query = Order::query()
            ->with(['shippingAddress', 'items'])
            ->latest('id');

        if ($agentProfileId !== null || ($sellerUserId !== null && $sellerUserId > 0)) {
            AgentOrderScope::apply($query, $agentProfileId, $sellerUserId);
        } else {
            $this->applyAuthenticatedAgentOrdersScope($query, $userId);
        }

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

        $meta = null;
        if ($limit !== null) {
            $page = max(1, $page);
            $total = (clone $query)->count();
            $query->offset(($page - 1) * $limit)->limit($limit);
            $meta = [
                'page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'has_more' => ($page * $limit) < $total,
            ];
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

                return array_merge(
                    [
                        'id' => $order->id,
                        'order_no' => $order->order_no,
                        'order_date' => $order->order_date?->toIso8601String(),
                        'order_status' => $order->statusValue(),
                        'order_label_status' => OrderStatus::orderLabelStatusForValue($order->statusValue()),
                        'order_channel' => $order->order_channel,
                        'customer' => $customer,
                        'shipping' => $order->shippingAddress,
                        'has_invoice_file' => $order->invoice_file_path !== null,
                        'has_delivery_receipt_paths' => $order->delivery_receipt_paths !== null,
                        'products' => $order->items->map(function ($item) use ($productImagePathsById) {
                            return $this->formatOrderItemForApi(
                                $item,
                                $productImagePathsById->get($item->product_id)
                            );
                        })->values(),
                    ],
                    $this->formatOrderAmountsForApi($order)
                );
            })
            ->values();

        $payload = ['orders' => $orders];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return $payload;
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, object>  $catalogProducts
     * @param  \Illuminate\Support\Collection<int, array{product_id:int, quantity:float}>  $requestedProducts
     * @return array{lines: list<array<string, mixed>>, subtotal: float}|array{errors: array<string, list<string>>}
     */
    private function buildOrderLinesFromCatalog(Collection $catalogProducts, Collection $requestedProducts): array
    {
        $converter = app(ProductUnitConverter::class);
        $lines = [];
        $subtotal = 0.0;
        foreach ($requestedProducts as $row) {
            $product = $catalogProducts->get($row['product_id']);
            if (! $product) {
                return [
                    'errors' => [
                        'products' => ["Sản phẩm #{$row['product_id']} không thuộc danh mục được phép đặt."],
                    ],
                ];
            }

            $quantity = (float) $row['quantity'];

            try {
                $quantities = $converter->buildOrderLineQuantities($product, $quantity);
            } catch (\RuntimeException $e) {
                return [
                    'errors' => [
                        'products' => ["Sản phẩm #{$row['product_id']}: ".$e->getMessage()],
                    ],
                ];
            }

            $unitPricePerBar = $this->resolveProductBaseListPrice($product);
            if ($unitPricePerBar === null) {
                $saleUnitPrice = $this->resolveProductUnitPrice($product, $converter);
                $barsPerSale = $quantities['quantity'] > 0
                    ? (float) $quantities['quantity_in_base_unit'] / (float) $quantities['quantity']
                    : 1.0;
                $unitPricePerBar = $barsPerSale > 0 ? round($saleUnitPrice / $barsPerSale, 2) : $saleUnitPrice;
            }

            $lineAmount = round($unitPricePerBar * (float) $quantities['quantity_in_base_unit'], 2);
            $subtotal += $lineAmount;

            $lines[] = [
                'product_id' => (int) $product->id,
                'product_name' => (string) ($product->name ?? ''),
                'unit' => $quantities['unit'],
                'quantity' => $quantities['quantity'],
                'quantity_in_base_unit' => $quantities['quantity_in_base_unit'],
                'unit_price' => $unitPricePerBar,
                'line_amount' => $lineAmount,
            ];
        }

        return ['lines' => $lines, 'subtotal' => $subtotal];
    }

    /**
     * Chi tiết chiết khấu theo nấc (đơn agent có policy), đồng bộ shape với preview `applied_tiers`.
     *
     * @return list<array<string, mixed>>
     */
    private function formatAppliedTiersFromOrderDiscountSnapshot(Order $order): array
    {
        $snapshot = $order->discount_snapshot_json;
        if (! is_array($snapshot) || ! isset($snapshot['breakdowns']) || ! is_array($snapshot['breakdowns'])) {
            return [];
        }

        return collect($snapshot['breakdowns'])->map(function (array $row) {
            return [
                'commission_policy_id' => (int) ($row['commission_policy_id'] ?? 0),
                'commission_policy_tier_id' => (int) ($row['commission_policy_tier_id'] ?? 0),
                'qty_from' => (string) ($row['qty_from'] ?? ''),
                'qty_to' => (string) ($row['qty_to'] ?? ''),
                'applied_qty' => (string) ($row['applied_qty'] ?? ''),
                'reward_percent' => (string) ($row['reward_percent'] ?? ''),
                'basis_amount' => $this->formatVietnameseMoney($row['basis_amount'] ?? 0),
                'discount_amount' => $this->formatVietnameseMoney($row['discount_amount'] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array<string, mixed>
     */
    private function formatOrderPricingApiData(array $pricing): array
    {
        return [
            'policy' => $pricing['policy'],
            'monthly_context' => $pricing['monthly_context'],
            'items' => collect($pricing['items'] ?? [])->map(function (array $row) {
                return $this->formatPricingLineForApi($row);
            })->values()->all(),
            'applied_tiers' => collect($pricing['applied_tiers'] ?? [])->map(function (array $t) {
                return [
                    'commission_policy_id' => $t['commission_policy_id'],
                    'commission_policy_tier_id' => $t['commission_policy_tier_id'],
                    'qty_from' => $t['qty_from'],
                    'qty_to' => $t['qty_to'],
                    'applied_qty' => $t['applied_qty'],
                    'reward_percent' => $t['reward_percent'],
                    'basis_amount' => $this->formatVietnameseMoney($t['basis_amount']),
                    'discount_amount' => $this->formatVietnameseMoney($t['discount_amount']),
                ];
            })->values()->all(),
            'summary' => [
                'subtotal_amount' => $this->formatVietnameseMoney($pricing['summary']['subtotal_amount']),
                'discount_amount' => $this->formatVietnameseMoney($pricing['summary']['discount_amount']),
                'net_amount' => $this->formatVietnameseMoney($pricing['summary']['net_amount']),
                'vat_base' => $this->formatVietnameseMoney($pricing['summary']['net_amount']),
                'vat_rate_percent' => (float) ($pricing['summary']['vat_rate_percent'] ?? 0),
                'vat_amount' => $this->formatVietnameseMoney($pricing['summary']['vat_amount'] ?? 0),
                'total_with_vat' => $this->formatVietnameseMoney($pricing['summary']['total_with_vat'] ?? $pricing['summary']['net_amount']),
                'currency' => $this->vietnameseMoneyCurrency(),
            ],
        ];
    }

    private function catalogProductsByAgentId(?int $agentId)
    {
        $query = DB::table('products')
            ->join('agent_products', 'agent_products.product_id', '=', 'products.id')
            ->join('product_categories as pc', 'pc.id', '=', 'products.product_category_id')
            ->where('agent_products.agent_id', $agentId)
            ->where('pc.code', 'AUTO_FECO_X3')
            ->where('products.is_active', 1)
            ->orderBy('products.name')
            ->select([
                'products.id',
                'products.sku',
                'products.name',
                'products.base_unit',
                'agent_products.product_id as ap_product_id',
            ]);

        if (Schema::hasColumn('products', 'sale_unit')) {
            $query->addSelect('products.sale_unit');
        }

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
            $converter = app(ProductUnitConverter::class);
            $unitPrice = $this->resolveProductUnitPrice($row, $converter);
            $row->unit_price = $unitPrice;
            $row->list_price_base_unit = $this->resolveProductBaseListPrice($row);
            $row->list_price_sale_unit = $converter->saleUnitListPrice(
                $row,
                $row->list_price_base_unit
            );

            return $row;
        });
    }

    private function resolveProductBaseListPrice(object $row): ?float
    {
        foreach ([
            $row->product_list_price ?? null,
            $row->product_unit_price ?? null,
            $row->product_price ?? null,
        ] as $candidate) {
            if (is_numeric($candidate)) {
                return round((float) $candidate, 2);
            }
        }

        return null;
    }

    private function resolveProductUnitPrice(object $row, ProductUnitConverter $converter): float
    {
        foreach ([
            $row->ap_unit_price ?? null,
            $row->ap_price ?? null,
        ] as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        $baseListPrice = $this->resolveProductBaseListPrice($row);
        if ($baseListPrice === null) {
            return 0.0;
        }

        return (float) ($converter->saleUnitListPrice($row, $baseListPrice) ?? 0.0);
    }

    private function notifyDirectEmployeeAboutNewAgentOrder(Order $order): void
    {
        $authUserId = auth()->id();
        if (! $authUserId || ! Schema::hasTable('agents')) {
            return;
        }

        $agentRow = DB::table('agents')
            ->where('user_id', $authUserId)
            ->first(['id', 'code', 'name', 'direct_employee_name']);

        if (! $agentRow) {
            return;
        }

        $directEmployeeName = trim((string) ($agentRow->direct_employee_name ?? ''));
        if ($directEmployeeName === '') {
            return;
        }

        $recipient = $this->resolveDirectEmployeeNotificationRecipient($directEmployeeName);
        if ($recipient === null) {
            Log::info('api.order.store_direct_employee_recipient_not_found', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'agent_id' => $agentRow->id,
                'agent_code' => $agentRow->code,
                'direct_employee_name' => $directEmployeeName,
            ]);

            return;
        }

        try {
            Mail::to($recipient['email'])->send(new AgentOrderCreatedDirectEmployeeMail(
                orderNo: (string) $order->order_no,
                orderDate: $order->order_date?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
                orderStatusLabel: OrderStatus::orderLabelStatusForValue($order->statusValue()),
                customerName: trim((string) ($order->customer_name ?? '')) ?: '—',
                netAmountFormatted: $this->formatVietnameseMoney($order->net_amount),
                currency: $this->vietnameseMoneyCurrency(),
                agentCode: trim((string) ($agentRow->code ?? '')) ?: '—',
                agentName: trim((string) ($agentRow->name ?? '')) ?: '—',
                directEmployeeName: $recipient['name'],
            ));
        } catch (\Throwable $mailException) {
            Log::warning('api.order.store_direct_employee_mail_failed', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'agent_id' => $agentRow->id,
                'agent_code' => $agentRow->code,
                'direct_employee_email' => $recipient['email'],
                'exception_message' => $mailException->getMessage(),
                'exception_class' => $mailException::class,
            ]);
        }
    }

    private function notifyDirectEmployeeAboutCancelledAgentOrder(Order $order): void
    {
        $authUserId = auth()->id();
        if (! $authUserId || ! Schema::hasTable('agents')) {
            return;
        }

        $agentRow = DB::table('agents')
            ->where('user_id', $authUserId)
            ->first(['id', 'code', 'name', 'direct_employee_name']);

        if (! $agentRow) {
            return;
        }

        $directEmployeeName = trim((string) ($agentRow->direct_employee_name ?? ''));
        if ($directEmployeeName === '') {
            return;
        }

        $recipient = $this->resolveDirectEmployeeNotificationRecipient($directEmployeeName);
        if ($recipient === null) {
            Log::info('api.order.cancel_direct_employee_recipient_not_found', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'agent_id' => $agentRow->id,
                'agent_code' => $agentRow->code,
                'direct_employee_name' => $directEmployeeName,
            ]);

            return;
        }

        try {
            Mail::to($recipient['email'])->send(new AgentOrderCancelledDirectEmployeeMail(
                orderNo: (string) $order->order_no,
                cancelledAt: now()->format('d/m/Y H:i'),
                customerName: trim((string) ($order->customer_name ?? '')) ?: '—',
                netAmountFormatted: $this->formatVietnameseMoney($order->net_amount),
                currency: $this->vietnameseMoneyCurrency(),
                agentCode: trim((string) ($agentRow->code ?? '')) ?: '—',
                agentName: trim((string) ($agentRow->name ?? '')) ?: '—',
                directEmployeeName: $recipient['name'],
            ));
        } catch (\Throwable $mailException) {
            Log::warning('api.order.cancel_direct_employee_mail_failed', [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'agent_id' => $agentRow->id,
                'agent_code' => $agentRow->code,
                'direct_employee_email' => $recipient['email'],
                'exception_message' => $mailException->getMessage(),
                'exception_class' => $mailException::class,
            ]);
        }
    }

    /**
     * @return array{name: string, email: string}|null
     */
    private function resolveDirectEmployeeNotificationRecipient(string $directEmployeeName): ?array
    {
        if (! Schema::hasTable('employees')) {
            return null;
        }

        $query = DB::table('employees')
            ->whereRaw('LOWER(TRIM(full_name)) = ?', [strtolower(trim($directEmployeeName))]);

        if (Schema::hasColumn('employees', 'is_active')) {
            $query->where('is_active', 1);
        }

        $select = ['full_name'];
        if (Schema::hasColumn('employees', 'user_id')) {
            $select[] = 'user_id';
        }
        if (Schema::hasColumn('employees', 'email')) {
            $select[] = 'email';
        }

        $employee = $query->first($select);
        if (! $employee) {
            return null;
        }

        $email = '';
        if (isset($employee->user_id) && $employee->user_id !== null && Schema::hasTable('users')) {
            $email = trim((string) (DB::table('users')->where('id', (int) $employee->user_id)->value('email') ?? ''));
        }

        if ($email === '' && isset($employee->email)) {
            $email = trim((string) $employee->email);
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return [
            'name' => trim((string) ($employee->full_name ?? $directEmployeeName)) ?: $directEmployeeName,
            'email' => $email,
        ];
    }

    /**
     * @return array{unit_price_per_bar: float, line_amount: float}
     */
    private function resolveOrderItemBarPricing(OrderItem $item): array
    {
        $converter = app(ProductUnitConverter::class);
        $baseQty = (float) $item->quantity_in_base_unit;
        $saleQty = (float) $item->quantity;
        $storedUnitPrice = (float) $item->unit_price;
        $barsPerSale = ($saleQty > 0 && $baseQty > 0) ? ($baseQty / $saleQty) : 1.0;

        $unitPricePerBar = $storedUnitPrice;

        if ($item->product_id) {
            $product = DB::table('products')->where('id', $item->product_id)->first();
            if ($product) {
                $listBar = $converter->baseUnitListPrice($product);
                $saleBox = $listBar !== null ? $converter->saleUnitListPrice($product, $listBar) : null;

                if ($listBar !== null && abs($storedUnitPrice - $listBar) < 0.01) {
                    $unitPricePerBar = $storedUnitPrice;
                } elseif ($saleBox !== null && abs($storedUnitPrice - $saleBox) < 0.01 && $barsPerSale > 0) {
                    $unitPricePerBar = round($storedUnitPrice / $barsPerSale, 2);
                }
            }
        }

        return [
            'unit_price_per_bar' => $unitPricePerBar,
            'line_amount' => round($baseQty * $unitPricePerBar, 2),
        ];
    }

    /**
     * @return array{
     *     pre_tax_subtotal: float,
     *     discount_amount: float,
     *     discount_percent: float|null,
     *     vat_base: float,
     *     vat_rate_percent: float,
     *     vat_amount: float,
     *     total_with_vat: float
     * }
     */
    private function resolveOrderPaymentBreakdown(Order $order): array
    {
        $order->loadMissing('items');

        return OrderVatBreakdown::fromOrderWithItemPricing(
            $order,
            fn (OrderItem $item): array => $this->resolveOrderItemBarPricing($item)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrderAmountsForApi(Order $order): array
    {
        $breakdown = $this->resolveOrderPaymentBreakdown($order);

        return [
            'subtotal_amount' => $this->formatVietnameseMoney($breakdown['pre_tax_subtotal']),
            'discount_amount' => $this->formatVietnameseMoney($breakdown['discount_amount']),
            'net_amount' => $this->formatVietnameseMoney($breakdown['vat_base']),
            'vat_base' => $this->formatVietnameseMoney($breakdown['vat_base']),
            'vat_rate_percent' => $breakdown['vat_rate_percent'],
            'vat_amount' => $this->formatVietnameseMoney($breakdown['vat_amount']),
            'total_with_vat' => $this->formatVietnameseMoney($breakdown['total_with_vat']),
            'currency' => $this->vietnameseMoneyCurrency(),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function formatPricingLineForApi(array $line): array
    {
        $saleQty = (float) ($line['quantity'] ?? 0);
        $baseQty = (float) ($line['quantity_in_base_unit'] ?? $saleQty);
        $barsPerSale = ($saleQty > 0 && $baseQty > 0) ? ($baseQty / $saleQty) : 1.0;
        $barPrice = (float) ($line['unit_price'] ?? 0);
        $boxPrice = round($barPrice * $barsPerSale, 2);
        $lineAmount = round($saleQty * $boxPrice, 2);

        return [
            'product_id' => (int) ($line['product_id'] ?? 0),
            'product_name' => (string) ($line['product_name'] ?? ''),
            'unit' => (string) ($line['unit'] ?? 'box'),
            'quantity' => $saleQty,
            'unit_price' => $this->formatVietnameseMoney($boxPrice),
            'line_amount' => $this->formatVietnameseMoney($lineAmount),
            'currency' => $this->vietnameseMoneyCurrency(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrderItemForApi(OrderItem $item, mixed $imagePath = null): array
    {
        $barPricing = $this->resolveOrderItemBarPricing($item);
        $saleQty = (float) $item->quantity;
        $baseQty = (float) $item->quantity_in_base_unit;
        $barsPerSale = ($saleQty > 0 && $baseQty > 0) ? ($baseQty / $saleQty) : 1.0;
        $boxPrice = round($barPricing['unit_price_per_bar'] * $barsPerSale, 2);
        $lineAmount = round($saleQty * $boxPrice, 2);

        $row = [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product_name_snapshot,
            'unit' => $item->unit,
            'quantity' => $saleQty,
            'unit_price' => $this->formatVietnameseMoney($boxPrice),
            'line_amount' => $this->formatVietnameseMoney($lineAmount),
            'currency' => $this->vietnameseMoneyCurrency(),
        ];

        if ($imagePath !== null) {
            $row['image_path'] = $this->buildAbsoluteImageUrl(is_string($imagePath) ? $imagePath : null);
        }

        return $row;
    }

}
