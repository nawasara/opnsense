<?php

namespace Nawasara\Opnsense\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nawasara\Opnsense\Models\OpnsenseAppliance;

/**
 * Manages a dynamic firewall block list on OPNsense: a host alias
 * (`nawasara_blocklist`) plus an automation rule that drops traffic from it.
 *
 * Both are auto-provisioned once (idempotent). IPs are then added/removed via
 * the lightweight alias_util endpoint. This is the safest firewall-write use
 * case (no rule ordering, no lockout risk) and the integration point for
 * nawasara/secscan — a malicious source IP can be dropped in one call.
 */
class FirewallBlocklistService
{
    public const ALIAS_NAME = 'nawasara_blocklist';
    public const RULE_DESC = 'Nawasara — drop blocklist (auto)';

    public function __construct(protected OPNsenseClient $client) {}

    /**
     * Ensure the alias + block rule exist on the appliance. Idempotent; cached
     * for a short time so we don't probe on every add. Returns true when the
     * blocklist is ready to use.
     */
    public function ensureProvisioned(?string $instance = null): bool
    {
        $cacheKey = 'opnsense:blocklist:ready:'.($instance ?? 'default');
        if (Cache::get($cacheKey)) {
            return true;
        }

        // 1. Alias.
        $aliasUuid = $this->client->aliasUuidByName(self::ALIAS_NAME, $instance);
        if ($aliasUuid === null) {
            $aliasUuid = $this->client->addAlias([
                'enabled' => '1',
                'name' => self::ALIAS_NAME,
                'type' => 'host',
                'content' => '',
                'description' => 'Nawasara dynamic block list (secscan / manual)',
            ], $instance);

            if ($aliasUuid === null) {
                Log::warning('[opnsense] failed to create blocklist alias');
                return false;
            }
            $this->client->reconfigureAlias($instance);
        }

        // 2. Automation block rule referencing the alias.
        if (! $this->blockRuleExists($instance)) {
            $uuid = $this->client->addFilterRule([
                'enabled' => '1',
                'action' => 'block',
                'direction' => 'in',
                'ipprotocol' => 'inet',
                'protocol' => 'any',
                'source_net' => self::ALIAS_NAME,   // source = the alias
                'destination_net' => 'any',
                'description' => self::RULE_DESC,
            ], $instance);

            if ($uuid !== null) {
                // Apply — relies on OPNsense's 60s auto-rollback safety; a block
                // rule sourced from an (initially empty) alias can't lock us out.
                $this->client->applyFilter($instance);
            } else {
                Log::warning('[opnsense] failed to create blocklist rule');
                // Alias still usable for manual reference; don't hard-fail.
            }
        }

        Cache::put($cacheKey, true, now()->addMinutes(30));

        return true;
    }

    /**
     * Block an IP: ensure provisioned, then add via the lightweight util
     * endpoint. Returns true on success.
     */
    public function blockIp(string $ip, ?string $instance = null): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $this->ensureProvisioned($instance);

        return $this->client->aliasUtilAdd(self::ALIAS_NAME, $ip, $instance);
    }

    /**
     * Unblock an IP.
     */
    public function unblockIp(string $ip, ?string $instance = null): bool
    {
        return $this->client->aliasUtilDelete(self::ALIAS_NAME, $ip, $instance);
    }

    /**
     * Current entries in the block list.
     *
     * @return array<int, string>
     */
    public function listIps(?string $instance = null): array
    {
        $rows = $this->client->aliasUtilList(self::ALIAS_NAME, $instance);

        // alias_util/list rows look like [{"ip": "1.2.3.4"}] or plain strings.
        return array_values(array_filter(array_map(function ($r) {
            if (is_string($r)) {
                return $r;
            }
            return $r['ip'] ?? $r['address'] ?? null;
        }, $rows)));
    }

    protected function blockRuleExists(?string $instance = null): bool
    {
        foreach ($this->client->filterSearchRule($instance) as $rule) {
            if (($rule['description'] ?? '') === self::RULE_DESC) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convenience entry point for other packages (e.g. nawasara/secscan): block
     * an IP on the first configured OPNsense appliance without them needing to
     * know instance names. No-op (returns false) when no appliance is set up.
     *
     * Usage from secscan:
     *   app(FirewallBlocklistService::class)->block('45.13.2.7');
     */
    public function block(string $ip): bool
    {
        $slug = OpnsenseAppliance::orderBy('id')->value('slug');

        return $this->blockIp($ip, $slug);
    }

    public function unblock(string $ip): bool
    {
        $slug = OpnsenseAppliance::orderBy('id')->value('slug');

        return $this->unblockIp($ip, $slug);
    }
}
