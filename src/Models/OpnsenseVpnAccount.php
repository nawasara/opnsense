<?php

namespace Nawasara\Opnsense\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A VPN account provisioned through Nawasara. Bound to an SSO user and given a
 * validity window (default 6 months). Lifecycle mirrors nawasara/api ApiToken:
 * active → expiring_soon → expired (disabled at OPNsense) → revoked/deleted.
 */
class OpnsenseVpnAccount extends Model
{
    protected $table = 'nawasara_opnsense_vpn_accounts';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRING = 'expiring_soon';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'appliance_id', 'user_id', 'type', 'name', 'opnsense_uuid', 'server_uuid',
        'public_key', 'private_key', 'tunnel_address', 'expires_at', 'status',
        'provisioned_by', 'provisioned_at', 'last_renewed_at', 'last_handshake_at', 'renew_requested_at', 'revoked_by', 'revoked_at',
    ];

    protected $casts = [
        // Encrypted at rest — OPNsense doesn't store the private key, so we hold
        // it (encrypted) to regenerate the client .conf on demand.
        'private_key' => 'encrypted',
        'expires_at' => 'datetime',
        'provisioned_at' => 'datetime',
        'last_renewed_at' => 'datetime',
        'last_handshake_at' => 'datetime',
        'renew_requested_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function appliance(): BelongsTo
    {
        return $this->belongsTo(OpnsenseAppliance::class, 'appliance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeExpiring(Builder $q): Builder
    {
        $days = (int) config('nawasara-opnsense.vpn.warn_before_days', 14);

        return $q->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    public function scopeExpired(Builder $q): Builder
    {
        return $q->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    // ── State ────────────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function isValid(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Days until expiry (negative = already expired, null = no expiry set).
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) floor(now()->diffInDays($this->expires_at, false));
    }

    /**
     * Badge colour for the "Berlaku s/d" column.
     */
    public function expiryColor(): string
    {
        $days = $this->daysUntilExpiry();
        if ($days === null) {
            return 'neutral';
        }
        if ($days < 0) {
            return 'danger';
        }
        if ($days <= (int) config('nawasara-opnsense.vpn.warn_before_days', 14)) {
            return 'warning';
        }

        return 'success';
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'success',
            self::STATUS_EXPIRING => 'warning',
            self::STATUS_EXPIRED => 'danger',
            self::STATUS_REVOKED => 'neutral',
            default => 'neutral',
        };
    }
}
