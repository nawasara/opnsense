<?php

namespace Nawasara\Opnsense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpnsenseGateway extends Model
{
    protected $table = 'nawasara_opnsense_gateways';

    protected $fillable = [
        'appliance_id', 'name', 'address', 'status',
        'delay_ms', 'loss_pct', 'stddev_ms',
    ];

    protected $casts = [
        'delay_ms' => 'float',
        'loss_pct' => 'float',
        'stddev_ms' => 'float',
    ];

    public function appliance(): BelongsTo
    {
        return $this->belongsTo(OpnsenseAppliance::class, 'appliance_id');
    }

    public function isUp(): bool
    {
        return $this->status === 'online';
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'online' => 'success',
            'down' => 'danger',
            default => 'neutral',
        };
    }
}
