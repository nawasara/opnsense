<?php

namespace Nawasara\Opnsense\Jobs;

use Illuminate\Support\Facades\Log;
use Nawasara\Opnsense\Models\OpnsenseAppliance;
use Nawasara\Opnsense\Models\OpnsenseGateway;
use Nawasara\Opnsense\Services\OPNsenseClient;
use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Vault\Facades\Vault;

/**
 * Pull health/traffic/gateway snapshot from every configured OPNsense
 * appliance and upsert into the local cache the dashboard reads from.
 *
 * Per-appliance try/catch: an unreachable appliance is marked
 * `status=unreachable` without failing the sync for the others.
 */
class SyncOpnsenseStatusJob extends AbstractSyncJob
{
    public int $timeout = 60;

    protected function service(): string
    {
        return 'opnsense';
    }

    protected function action(): string
    {
        return 'sync_status';
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

        // No multi-instance rows yet? Fall back to the default (null) instance.
        if (empty($instances)) {
            $instances = [null];
        }

        $synced = 0;
        $failed = 0;

        foreach ($instances as $instance) {
            $slug = $instance ?: 'default';

            try {
                if (! $client->isConfigured($instance)) {
                    continue;
                }

                $this->syncAppliance($client, $instance, $slug);
                $synced++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning("[opnsense] sync failed for {$slug}: ".$e->getMessage());

                OpnsenseAppliance::where('slug', $slug)->update([
                    'status' => 'unreachable',
                    'last_synced_at' => now(),
                ]);
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    protected function syncAppliance(OPNsenseClient $client, ?string $instance, string $slug): void
    {
        $host = (string) Vault::get('opnsense', 'host', $instance);
        $version = $client->getVersion($instance);

        $resources = $client->systemResources($instance) ?? [];
        $time = $client->systemTime($instance) ?? [];
        $disk = $client->systemDisk($instance) ?? [];
        $temps = $client->systemTemperature($instance);

        $appliance = OpnsenseAppliance::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $slug,
                'host' => $host,
                'version' => $version,
                'status' => 'online',
                'mem_pct' => $this->memPct($resources),
                'disk_pct' => $this->diskPct($disk),
                'uptime_seconds' => $this->uptime($time),
                'temperature_c' => $this->maxTemp($temps),
                'last_synced_at' => now(),
                // CPU is only an SSE stream on OPNsense — leave null for the
                // snapshot sync; a dedicated poll can fill it later if needed.
                'cpu_pct' => null,
            ]
        );

        $this->syncGateways($client, $instance, $appliance);
    }

    protected function syncGateways(OPNsenseClient $client, ?string $instance, OpnsenseAppliance $appliance): void
    {
        $status = $client->gatewayStatus($instance);
        $items = $status['items'] ?? $status['gateways'] ?? [];

        if (! is_array($items)) {
            return;
        }

        $seen = [];
        foreach ($items as $gw) {
            $name = $gw['name'] ?? null;
            if (! $name) {
                continue;
            }
            $seen[] = $name;

            OpnsenseGateway::updateOrCreate(
                ['appliance_id' => $appliance->id, 'name' => $name],
                [
                    'address' => $gw['address'] ?? null,
                    // Prefer status_translated ("Online"/"Offline") over the raw
                    // status field, which is "none"/"~" when no monitor IP is set.
                    'status' => $this->gwStatus($gw['status_translated'] ?? $gw['status'] ?? ''),
                    'delay_ms' => $this->numeric($gw['delay'] ?? null),
                    'loss_pct' => $this->numeric($gw['loss'] ?? null),
                    'stddev_ms' => $this->numeric($gw['stddev'] ?? null),
                ]
            );
        }

        // Prune gateways that disappeared from the appliance.
        if (! empty($seen)) {
            $appliance->gateways()->whereNotIn('name', $seen)->delete();
        }
    }

    // ── Field extraction helpers (defensive — OPNsense JSON shape varies) ─────

    protected function memPct(array $resources): ?int
    {
        $total = (float) ($resources['memory']['total'] ?? $resources['mem']['total'] ?? 0);
        $used = (float) ($resources['memory']['used'] ?? $resources['mem']['used'] ?? 0);

        return $total > 0 ? (int) round($used / $total * 100) : null;
    }

    protected function diskPct(array $disk): ?int
    {
        $devices = $disk['devices'] ?? $disk['disks'] ?? [];
        if (! is_array($devices)) {
            return null;
        }

        $max = null;
        foreach ($devices as $d) {
            $pct = $d['used_pct'] ?? $d['capacity'] ?? null;
            if ($pct === null) {
                continue;
            }
            $val = (int) rtrim((string) $pct, '%');
            $max = $max === null ? $val : max($max, $val);
        }

        return $max;
    }

    /**
     * OPNsense system_time.uptime is a human string like "10 days, 22:58:09"
     * (NOT integer seconds). Parse it to seconds; return null if unparseable.
     */
    protected function uptime(array $time): ?int
    {
        $up = $time['uptime'] ?? null;
        if ($up === null || $up === '') {
            return null;
        }

        // Numeric already? (defensive — some versions may differ)
        if (is_numeric($up)) {
            return (int) $up;
        }

        $seconds = 0;
        if (preg_match('/(\d+)\s*days?/i', (string) $up, $m)) {
            $seconds += (int) $m[1] * 86400;
        }
        if (preg_match('/(\d{1,2}):(\d{2}):(\d{2})/', (string) $up, $m)) {
            $seconds += (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }

        return $seconds > 0 ? $seconds : null;
    }

    protected function maxTemp(array $temps): ?float
    {
        $max = null;
        foreach ($temps as $t) {
            $c = $t['temperature'] ?? $t['temp'] ?? null;
            if ($c === null) {
                continue;
            }
            $val = (float) $c;
            $max = $max === null ? $val : max($max, $val);
        }

        return $max;
    }

    protected function gwStatus(string $raw): string
    {
        $raw = strtolower($raw);
        if (str_contains($raw, 'down') || str_contains($raw, 'offline')) {
            return 'down';
        }
        // "online", "none" (no monitor set), "~", "" all treated as up.
        return 'online';
    }

    protected function numeric(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        // OPNsense returns things like "12.3 ms" / "0.0 %".
        $clean = preg_replace('/[^0-9.]/', '', (string) $v);

        return $clean === '' ? null : (float) $clean;
    }
}
