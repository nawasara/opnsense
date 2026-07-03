<?php

namespace Nawasara\Opnsense\Jobs;

use Illuminate\Support\Facades\Log;
use Nawasara\Opnsense\Models\OpnsenseVpnAccount;
use Nawasara\Opnsense\Services\OPNsenseClient;
use Nawasara\Sync\Jobs\AbstractSyncJob;

/**
 * Daily lifecycle sweep for VPN accounts:
 *  - expired (past expires_at, still active) → disable peer at OPNsense + mark expired
 *  - expiring soon (< warn_before_days)      → mark expiring_soon (badge/alert)
 *  - long-expired (> grace_period_days)      → hard delete peer + record (optional)
 *
 * Disabling (not deleting) on expiry keeps the account renewable without a new key.
 */
class ExpireVpnAccountsJob extends AbstractSyncJob
{
    public int $timeout = 120;

    protected function service(): string
    {
        return 'opnsense';
    }

    protected function action(): string
    {
        return 'expire_vpn_accounts';
    }

    protected function targetType(): ?string
    {
        return null;
    }

    protected function targetId(): ?string
    {
        return null;
    }

    protected function execute(): array
    {
        $client = app(OPNsenseClient::class);
        $disabled = 0;
        $flagged = 0;
        $deleted = 0;

        // 1. Expired + still active → disable at OPNsense, mark expired.
        $expired = OpnsenseVpnAccount::with('appliance')
            ->where('status', OpnsenseVpnAccount::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $account) {
            $this->disableAtOpnsense($client, $account);
            $account->update(['status' => OpnsenseVpnAccount::STATUS_EXPIRED]);
            $disabled++;
        }

        // 2. Expiring soon → flag (no OPNsense change).
        $warnDays = (int) config('nawasara-opnsense.vpn.warn_before_days', 14);
        $flagged = OpnsenseVpnAccount::where('status', OpnsenseVpnAccount::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($warnDays)])
            ->update(['status' => OpnsenseVpnAccount::STATUS_EXPIRING]);

        // 3. Long-expired beyond grace → hard delete peer + record.
        $graceDays = (int) config('nawasara-opnsense.vpn.grace_period_days', 30);
        $stale = OpnsenseVpnAccount::with('appliance')
            ->where('status', OpnsenseVpnAccount::STATUS_EXPIRED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->subDays($graceDays))
            ->get();

        foreach ($stale as $account) {
            $this->deleteAtOpnsense($client, $account);
            $account->delete();
            $deleted++;
        }

        return ['disabled' => $disabled, 'flagged' => $flagged, 'deleted' => $deleted];
    }

    protected function disableAtOpnsense(OPNsenseClient $client, OpnsenseVpnAccount $account): void
    {
        if (! $account->opnsense_uuid || $account->type !== 'wireguard') {
            return;
        }

        try {
            $instance = $account->appliance?->slug;
            $client->toggleWireguardClient($account->opnsense_uuid, false, $instance);
            $client->reconfigureWireguard($instance);
        } catch (\Throwable $e) {
            Log::warning("[opnsense] disable vpn account {$account->id} failed: ".$e->getMessage());
        }
    }

    protected function deleteAtOpnsense(OPNsenseClient $client, OpnsenseVpnAccount $account): void
    {
        if (! $account->opnsense_uuid || $account->type !== 'wireguard') {
            return;
        }

        try {
            $instance = $account->appliance?->slug;
            $client->delWireguardClient($account->opnsense_uuid, $instance);
            $client->reconfigureWireguard($instance);
        } catch (\Throwable $e) {
            Log::warning("[opnsense] delete vpn account {$account->id} failed: ".$e->getMessage());
        }
    }
}
