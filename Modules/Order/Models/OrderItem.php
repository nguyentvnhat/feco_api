<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name_snapshot',
        'unit',
        'quantity',
        'quantity_in_base_unit',
        'unit_price',
        'line_amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'quantity_in_base_unit' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'line_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
