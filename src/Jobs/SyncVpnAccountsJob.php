<?php

namespace Nawasara\Opnsense\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Nawasara\Opnsense\Models\OpnsenseAppliance;
use Nawasara\Opnsense\Models\OpnsenseVpnAccount;
use Nawasara\Opnsense\Services\OPNsenseClient;
use Nawasara\Opnsense\Support\HandshakeIndex;
use Nawasara\Sync\Jobs\AbstractSyncJob;

/**
 * Reconcile WireGuard peers on the firewall with the accounts Nawasara knows
 * about. Nawasara only ever recorded peers it provisioned itself, so peers
 * created directly in OPNsense were invisible here, and records whose peer had
 * been deleted on the firewall still looked active.
 *
 * Two directions, both non-destructive:
 *   - peer on firewall, no local record  → import as an unmanaged account
 *     (no private key, so no .conf download — it was never ours to keep)
 *   - local record, peer gone upstream   → mark revoked, so the list is honest
 */
class SyncVpnAccountsJob extends AbstractSyncJob
{
    public int $timeout = 120;

    protected function service(): string
    {
        return 'opnsense';
    }

    protected function action(): string
    {
        return 'sync_vpn_accounts';
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
        $instances = $client->instances();
        if (empty($instances)) {
            $instances = [null];
        }

        $imported = 0;
        $orphaned = 0;
        $checked = 0;
        $failed = 0;

        foreach ($instances as $instance) {
            $slug = $instance ?: 'default';

            try {
                if (! $client->isConfigured($instance)) {
                    continue;
                }

                $appliance = OpnsenseAppliance::where('slug', $slug)->first();
                if (! $appliance) {
                    continue;
                }

                $peers = $client->wireguardClients($instance);
                $checked += count($peers);

                $seenUuids = [];

                foreach ($peers as $peer) {
                    $uuid = (string) ($peer['uuid'] ?? '');
                    if ($uuid === '') {
                        continue;
                    }
                    $seenUuids[] = $uuid;

                    $existing = OpnsenseVpnAccount::where('opnsense_uuid', $uuid)->first();
                    if ($existing) {
                        // Keep the local name/address in step with the firewall.
                        $existing->fill([
                            'name' => (string) ($peer['name'] ?? $existing->name),
                            'tunnel_address' => (string) ($peer['tunneladdress'] ?? $existing->tunnel_address),
                            'public_key' => (string) ($peer['pubkey'] ?? $existing->public_key),
                        ]);
                        if ($existing->isDirty()) {
                            $existing->save();
                        }

                        continue;
                    }

                    // Peer created outside Nawasara: record it so operators see
                    // the full picture. No owner and no private key — the .conf
                    // can't be rebuilt, which the UI reflects.
                    OpnsenseVpnAccount::create([
                        'appliance_id' => $appliance->id,
                        'user_id' => null,
                        'type' => 'wireguard',
                        'name' => (string) ($peer['name'] ?? 'unmanaged-'.substr($uuid, 0, 8)),
                        'opnsense_uuid' => $uuid,
                        'server_uuid' => (string) ($peer['servers'] ?? ''),
                        'public_key' => (string) ($peer['pubkey'] ?? ''),
                        'private_key' => null,
                        'tunnel_address' => (string) ($peer['tunneladdress'] ?? ''),
                        'expires_at' => null, // unmanaged: no TTL policy applied
                        'status' => OpnsenseVpnAccount::STATUS_ACTIVE,
                        'provisioned_by' => null,
                        'provisioned_at' => null,
                    ]);
                    $imported++;
                }

                $handshakes = HandshakeIndex::fromShowRows($client->wireguardShow($instance));
                foreach ($handshakes as $publicKey => $at) {
                    OpnsenseVpnAccount::where('appliance_id', $appliance->id)
                        ->where('public_key', $publicKey)
                        ->update(['last_handshake_at' => Carbon::createFromTimestamp($at)]);
                }

                // Records pointing at peers that no longer exist upstream.
                $stale = OpnsenseVpnAccount::where('appliance_id', $appliance->id)
                    ->whereNotNull('opnsense_uuid')
                    ->where('status', '!=', OpnsenseVpnAccount::STATUS_REVOKED)
                    ->when($seenUuids, fn ($q) => $q->whereNotIn('opnsense_uuid', $seenUuids))
                    ->get();

                foreach ($stale as $acct) {
                    $acct->update([
                        'status' => OpnsenseVpnAccount::STATUS_REVOKED,
                        'revoked_at' => now(),
                    ]);
                    $orphaned++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning("[opnsense] sync akun VPN gagal untuk {$slug}: ".$e->getMessage());
            }
        }

        return [
            'checked' => $checked,
            'imported' => $imported,
            'orphaned' => $orphaned,
            'failed' => $failed,
        ];
    }
}
