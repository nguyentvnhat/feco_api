<?php

namespace Modules\Agent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agent extends Model
{
    protected $table = 'agents';

    protected $fillable = [
        'code',
        'name',
        'business_name',
        'email',
        'phone',
        'address',
        'tax_code',
        'contract_code',
        'city',
        'ward',
        'region',
        'status',
        'notes',
        'logo_path',
        'user_id',
        'parent_agent_id',
        'agent_type_id',
    ];

    public function parentAgent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_agent_id');
    }
}
