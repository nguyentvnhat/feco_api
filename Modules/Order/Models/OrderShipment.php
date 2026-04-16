<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Support\OrderShipmentStatus;
use Modules\Order\Support\ShippingFeePayment;

class OrderShipment extends Model
{
    protected $fillable = [
        'order_id',
        'provider',
        'tracking_code',
        'shipping_fee_payment',
        'status',
        'raw_response',
    ];

    protected $casts = [
        'shipping_fee_payment' => 'integer',
        'raw_response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeRaw = false): array
    {
        $out = [
            'id' => $this->id,
            'provider' => $this->provider,
            'tracking_code' => $this->tracking_code,
            'shipping_fee_payment' => $this->shipping_fee_payment,
            'shipping_fee_payment_name' => ShippingFeePayment::getShippingFeePaymentName((int) $this->shipping_fee_payment),
            'shipping_fee_payment_label' => ShippingFeePayment::label((int) $this->shipping_fee_payment),
            'status' => $this->status,
            'status_label' => OrderShipmentStatus::label($this->status),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
        if ($includeRaw) {
            $out['raw_response'] = $this->raw_response;
        }

        return $out;
    }
}
