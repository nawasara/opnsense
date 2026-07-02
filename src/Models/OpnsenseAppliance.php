<?php

namespace Nawasara\Opnsense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpnsenseAppliance extends Model
{
    protected $table = 'nawasara_opnsense_appliances';

    protected $fillable = [
        'slug', 'name', 'host', 'version', 'status',
        'cpu_pct', 'mem_pct', 'disk_pct', 'uptime_seconds',
        'temperature_c', 'last_synced_at',
    ];

    protected $casts = [
        'cpu_pct' => 'integer',
        'mem_pct' => 'integer',
        'disk_pct' => 'integer',
        'uptime_seconds' => 'integer',
        'temperature_c' => 'float',
        'last_synced_at' => 'datetime',
    ];

    public function gateways(): HasMany
    {
        return $this->hasMany(OpnsenseGateway::class, 'appliance_id');
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    /**
     * Health colour cue based on config thresholds. Returns 'success' |
     * 'warning' | 'danger' | 'neutral' for badge/bar styling.
     */
    public function healthColor(): string
    {
        if ($this->status !== 'online') {
            return 'neutral';
        }

        $t = config('nawasara-opnsense.thresholds');
        $cpu = $this->cpu_pct ?? 0;
        $mem = $this->mem_pct ?? 0;
        $disk = $this->disk_pct ?? 0;

        if ($cpu >= $t['cpu_crit'] || $mem >= $t['mem_crit'] || $disk >= $t['disk_crit']) {
            return 'danger';
        }
        if ($cpu >= $t['cpu_warn'] || $mem >= $t['mem_warn'] || $disk >= $t['disk_warn']) {
            return 'warning';
        }

        return 'success';
    }
}
