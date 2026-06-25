<?php

namespace Modules\Order\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Order\Enums\OrderStatus;

class Order extends Model
{
    protected $fillable = [
        'order_no',
        'order_date',
        'order_month',
        'seller_user_id',
        'agent_profile_id',
        'customer_name',
        'customer_phone',
        'customer_address',
        'customer_city',
        'customer_ward',
        'order_channel',
        'order_status',
        'subtotal_amount',
        'discount_amount',
        'applied_discount_policy_id',
        'monthly_qty_before',
        'monthly_qty_after',
        'discount_snapshot_json',
        'net_amount',
        'vat_rate_percent',
        'vat_amount',
        'total_with_vat',
        'notes',
        'invoice_file_path',
        'delivery_receipt_paths',
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'order_status' => OrderStatus::class,
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'vat_rate_percent' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_with_vat' => 'decimal:2',
        'monthly_qty_before' => 'decimal:4',
        'monthly_qty_after' => 'decimal:4',
        'delivery_receipt_paths' => 'array',
        'discount_snapshot_json' => 'array',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(OrderInternalNote::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', OrderAddress::TYPE_SHIPPING);
    }

    public function pickupAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', OrderAddress::TYPE_PICKUP);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(OrderShipment::class);
    }

    public function latestShipment(): HasOne
    {
        return $this->hasOne(OrderShipment::class)->latestOfMany();
    }

    public function syncLegacyCustomerColumnsFromShippingAddress(): self
    {
        $this->loadMissing('shippingAddress');
        $addr = $this->shippingAddress;
        if ($addr === null) {
            return $this;
        }

        $this->forceFill([
            'customer_name' => $addr->full_name,
            'customer_phone' => $addr->phone,
            'customer_address' => $addr->address_line,
            'customer_city' => $addr->province_name,
            'customer_ward' => $addr->ward_name,
        ])->saveQuietly();

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function legacyCustomerAddressForApi(): array
    {
        $this->loadMissing('shippingAddress');
        if ($this->shippingAddress !== null) {
            return $this->shippingAddress->toLegacyCustomerAttributes();
        }

        return [
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_address' => $this->customer_address,
            'customer_city' => $this->customer_city,
            'customer_ward' => $this->customer_ward,
        ];
    }

    public function statusValue(): string
    {
        $s = $this->order_status;

        return $s instanceof OrderStatus ? $s->value : (string) $s;
    }

    public function hasDeliveryReceipts(): bool
    {
        $paths = $this->delivery_receipt_paths;
        if (! is_array($paths)) {
            return false;
        }
        foreach ($paths as $p) {
            if (is_string($p) && $p !== '') {
                return true;
            }
        }

        return false;
    }
}
