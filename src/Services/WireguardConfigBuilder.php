<?php

namespace Nawasara\Opnsense\Services;

use Nawasara\Opnsense\Models\OpnsenseVpnAccount;

/**
 * Builds a WireGuard client .conf (and a QR payload) for a provisioned account.
 *
 * OPNsense has no API to export a peer's .conf (GUI-only), so Nawasara assembles
 * it from: the account's stored private key + tunnel address, plus the server's
 * public key + endpoint (host:port) fetched live from the appliance.
 */
class WireguardConfigBuilder
{
    public function __construct(protected OPNsenseClient $client) {}

    /**
     * Build the .conf text for an account. Returns null if the server can't be
     * resolved or the account is missing its private key.
     */
    public function build(OpnsenseVpnAccount $account): ?string
    {
        if (empty($account->private_key) || empty($account->server_uuid)) {
            return null;
        }

        $instance = $account->appliance?->slug;
        $server = $this->client->wireguardServer($account->server_uuid, $instance);
        if ($server === null) {
            return null;
        }

        $serverPubkey = $this->scalar($server['pubkey'] ?? '');
        $serverPort = $this->scalar($server['port'] ?? '51820') ?: '51820';
        // Endpoint host: prefer the appliance's public/reachable address. The
        // appliance host is an internal LAN IP; the WAN/endpoint the client dials
        // may differ — expose it via config or the server 'endpoint' field later.
        $endpointHost = $this->endpointHost($account);

        $dns = $this->scalar($server['dns'] ?? '');
        // Peers get a /32 tunnel address; AllowedIPs defaults to the server's
        // tunnel network (split tunnel) or 0.0.0.0/0 (full tunnel) — keep it to
        // the server tunnel network unless configured otherwise.
        $allowedIps = $this->allowedIps($server);

        $lines = [
            '[Interface]',
            'PrivateKey = '.$account->private_key,
            'Address = '.($account->tunnel_address ?: ''),
        ];
        if ($dns !== '') {
            $lines[] = 'DNS = '.$dns;
        }
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = '.$serverPubkey;
        $lines[] = 'Endpoint = '.$endpointHost.':'.$serverPort;
        $lines[] = 'AllowedIPs = '.$allowedIps;
        $lines[] = 'PersistentKeepalive = 25';

        return implode("\n", $lines)."\n";
    }

    /**
     * Resolve the endpoint host the client dials. Falls back to the appliance
     * host (minus scheme/port) — override via config
     * `nawasara-opnsense.vpn.endpoint_host` when the WAN address differs.
     */
    protected function endpointHost(OpnsenseVpnAccount $account): string
    {
        $configured = config('nawasara-opnsense.vpn.endpoint_host');
        if (! empty($configured)) {
            return $configured;
        }

        $host = (string) ($account->appliance?->host ?? '');
        $host = preg_replace('#^https?://#', '', $host);
        $host = preg_replace('#:\d+$#', '', (string) $host);

        return $host ?: '';
    }

    /**
     * Default AllowedIPs. Use the server tunnel network for split tunnel; a
     * consumer wanting full tunnel can edit the downloaded .conf.
     */
    protected function allowedIps(array $server): string
    {
        $tunnel = $this->scalar($server['tunneladdress'] ?? '');
        if ($tunnel !== '' && str_contains($tunnel, '/')) {
            return $tunnel;
        }

        return '0.0.0.0/0';
    }

    /**
     * OPNsense get_* endpoints return select-type fields as objects keyed by
     * value, e.g. {"10.10.10.1/24": {"value": "...", "selected": 1}}. Flatten
     * such a field to the selected (or first) scalar value.
     */
    protected function scalar(mixed $field): string
    {
        if (is_string($field)) {
            return $field;
        }
        if (is_array($field)) {
            // Pick the entry marked selected=1, else the first key.
            foreach ($field as $key => $meta) {
                if (is_array($meta) && ($meta['selected'] ?? 0)) {
                    return (string) $key;
                }
            }
            $firstKey = array_key_first($field);

            return $firstKey !== null ? (string) $firstKey : '';
        }

        return (string) $field;
    }
}
