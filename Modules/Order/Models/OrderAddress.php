<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAddress extends Model
{
    public const TYPE_SHIPPING = 'shipping';

    public const TYPE_PICKUP = 'pickup';

    protected $fillable = [
        'order_id',
        'type',
        'full_name',
        'phone',
        'address_line',
        'province_code',
        'province_name',
        'district_code',
        'district_name',
        'ward_code',
        'ward_name',
    ];

    /**
     * @return array<string, string>
     */
    public function toLegacyCustomerAttributes(): array
    {
        return [
            'customer_name' => $this->full_name,
            'customer_phone' => $this->phone,
            'customer_address' => $this->address_line,
            'customer_city' => $this->province_name,
            'customer_ward' => $this->ward_name,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
