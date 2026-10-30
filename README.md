# nawasara/opnsense

OPNsense firewall control plane for the Nawasara superapp: health/traffic/gateway monitoring, VPN self-service (WireGuard/OpenVPN with SSO accounts and expiry), and firewall management via the OPNsense REST API.

Same client pattern as `nawasara/proxmox`: HTTP Basic + Vault + self-signed TLS, no sidecar.

## Status

Phase A (Monitoring): scaffolded. Read-only dashboard: appliance status (CPU/RAM/disk/uptime/temperature) and gateway (WAN up/down + latency). Phase B (VPN) and Phase C (Firewall) are coming, see `docs/nawasara-opnsense/`.

## Setup

1. Credentials in Vault (group `opnsense`, multi-instance):
   - `host` (e.g. `https://10.10.10.1:64443`), `api_key`, `api_secret`, `verify_ssl`
   - Generate an API key in OPNsense: System > Access > Users, then API keys, then `+`
2. Seed permissions:
   ```
   php artisan db:seed --class="Nawasara\Opnsense\Database\Seeders\PermissionSeeder"
   ```
3. Sync runs automatically every `sync_interval` minutes (default 2), or via the Sync button in the UI.

## ASN account portal

`nawasara/asn-portal` lets a civil servant see their own VPN accounts, download the `.conf`, and ask for a renewal. Two columns exist for it:

- `last_handshake_at`: written by `SyncVpnAccountsJob` from `/wireguard/service/show`, so the portal can say "last connected" without calling the firewall on every page load. A peer that never connected reports 0 and is left null rather than stored as 1970 (`HandshakeIndex`).
- `renew_requested_at`: set by the owner from the portal, shown under "Berlaku s/d" on the VPN page, and cleared by an admin renewal. Renewal stays an admin action until auto-renewal is decided.

Accounts are found through `users.keycloak_id`, so only accounts with an owner picked from the Keycloak directory appear in the portal.

## Permissions

Read-only (auto-granted to developer): `opnsense.monitor.view`, `opnsense.vpn.view`, `opnsense.firewall.view`, `opnsense.sync.execute`.
Write-risk (assign explicitly): `opnsense.vpn.manage`, `opnsense.vpn.renew`, `opnsense.vpn.export`, `opnsense.firewall.manage`, `opnsense.firewall.apply`.

## Technical notes

- Endpoint casing changed in OPNsense 25.7 (camelCase became snake_case). The client uses snake_case (26.x). Check `getVersion()` before relying on a particular casing.
- CPU and traffic are only available via SSE stream in OPNsense, so sync uses a snapshot counter, not the stream.
- Firewall writes (Phase C) use staged config, so an `apply` is required, with a 60-second auto-rollback.
