<?php

namespace Nawasara\Opnsense\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Nawasara\Vault\Facades\Vault;

/**
 * OPNsense REST API client.
 *
 * Auth: HTTP Basic — Authorization: Basic base64(api_key:api_secret).
 * Base URL pattern: {host}/api/<module>/<controller>/<command>/[params].
 * GET = read (search/get/status), POST = write (add/set/del/toggle/apply/reconfigure).
 * Response: JSON. Self-signed TLS by default → verify_ssl opt-in.
 *
 * Multi-appliance: credentials keyed per Vault instance. Pass $instance to
 * every method; defaults to the first configured instance when omitted.
 *
 * ⚠️ Endpoint casing changed in OPNsense 25.7 (camelCase → snake_case). This
 * client writes snake_case paths (26.x convention). If a target appliance is
 * < 25.7, an EndpointMap layer would translate — verify the appliance version
 * (getVersion) before relying on a specific casing.
 */
class OPNsenseClient
{
    /**
     * Whether at least one appliance is configured in Vault.
     */
    public function isConfigured(?string $instance = null): bool
    {
        return ! empty(Vault::get('opnsense', 'host', $instance))
            && ! empty(Vault::get('opnsense', 'api_key', $instance))
            && ! empty(Vault::get('opnsense', 'api_secret', $instance));
    }

    /**
     * List configured appliance instances (Vault instance names).
     *
     * @return array<int, string>
     */
    public function instances(): array
    {
        return Vault::instances('opnsense');
    }

    /**
     * Build a configured HTTP client with HTTP Basic auth for one appliance.
     */
    protected function api(?string $instance = null): PendingRequest
    {
        $host = rtrim((string) Vault::get('opnsense', 'host', $instance), '/');
        $key = (string) Vault::get('opnsense', 'api_key', $instance);
        $secret = (string) Vault::get('opnsense', 'api_secret', $instance);

        // Default self-signed: verify only when explicitly enabled.
        $verifyRaw = strtolower((string) Vault::get('opnsense', 'verify_ssl', $instance));
        $verify = in_array($verifyRaw, ['true', '1', 'yes'], true);

        $req = Http::baseUrl($host.'/api')
            ->withBasicAuth($key, $secret)
            ->timeout((int) config('nawasara-opnsense.request_timeout', 15))
            ->acceptJson();

        if (! $verify) {
            $req = $req->withoutVerifying();
        }

        return $req;
    }

    /**
     * GET /api/core/firmware/status → firmware/version info.
     * Used by testConnection + version drift detection.
     */
    public function getFirmwareStatus(?string $instance = null): ?array
    {
        $r = $this->api($instance)->get('/core/firmware/status');

        return $r->successful() ? (array) $r->json() : null;
    }

    /**
     * Extract a human version string from firmware status (best-effort — the
     * exact key varies by version, so probe several).
     */
    public function getVersion(?string $instance = null): ?string
    {
        $status = $this->getFirmwareStatus($instance);
        if ($status === null) {
            return null;
        }

        return $status['product_version']
            ?? $status['product']['product_version']
            ?? $status['os_version']
            ?? null;
    }

    /**
     * Vault test-connection contract. MUST return key `success` (NOT `ok`) —
     * the Vault UI shows a red toast on `ok` even with a "Connected" message.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(?string $instance = null): array
    {
        try {
            if (! $this->isConfigured($instance)) {
                return ['success' => false, 'message' => 'Kredensial belum lengkap (host/key/secret).'];
            }

            $r = $this->api($instance)->get('/core/firmware/status');

            if ($r->status() === 401) {
                return ['success' => false, 'message' => 'Gagal: API key/secret salah (401).'];
            }

            if (! $r->successful()) {
                return ['success' => false, 'message' => 'Gagal: HTTP '.$r->status()];
            }

            $version = $this->getVersion($instance) ?? 'unknown';

            return ['success' => true, 'message' => "Connected — OPNsense {$version}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Gagal: '.$e->getMessage()];
        }
    }

    // ── Fase A — Monitoring reads ───────────────────────────────────────────

    /**
     * GET /api/diagnostics/system/system_resources → RAM total/used, etc.
     */
    public function systemResources(?string $instance = null): ?array
    {
        $r = $this->api($instance)->get('/diagnostics/system/system_resources');

        return $r->successful() ? (array) $r->json() : null;
    }

    /**
     * GET /api/diagnostics/system/system_time → uptime, load average.
     */
    public function systemTime(?string $instance = null): ?array
    {
        $r = $this->api($instance)->get('/diagnostics/system/system_time');

        return $r->successful() ? (array) $r->json() : null;
    }

    /**
     * GET /api/diagnostics/system/system_disk → disk usage per mount.
     */
    public function systemDisk(?string $instance = null): ?array
    {
        $r = $this->api($instance)->get('/diagnostics/system/system_disk');

        return $r->successful() ? (array) $r->json() : null;
    }

    /**
     * GET /api/diagnostics/system/system_temperature → sensor array.
     */
    public function systemTemperature(?string $instance = null): array
    {
        $r = $this->api($instance)->get('/diagnostics/system/system_temperature');

        return $r->successful() ? (array) $r->json() : [];
    }

    /**
     * GET /api/interfaces/overview/interfaces_info → per-interface status +
     * traffic counters (bytes/packets in/out).
     */
    public function interfacesInfo(?string $instance = null): array
    {
        $r = $this->api($instance)->get('/interfaces/overview/interfaces_info');

        return $r->successful() ? (array) $r->json() : [];
    }

    /**
     * GET /api/routes/gateway/status → WAN up/down + delay/loss/stddev (dpinger).
     */
    public function gatewayStatus(?string $instance = null): array
    {
        $r = $this->api($instance)->get('/routes/gateway/status');

        return $r->successful() ? (array) $r->json() : [];
    }

    /**
     * GET /api/core/service/search → all services with a `running` flag.
     */
    public function serviceSearch(?string $instance = null): array
    {
        $r = $this->api($instance)->get('/core/service/search');

        return $r->successful() ? (array) $r->json() : [];
    }
}
