# nawasara/opnsense

OPNsense firewall control plane untuk Nawasara superapp — monitoring
health/traffic/gateway, VPN self-service (WireGuard/OpenVPN dengan akun ber-SSO
+ masa berlaku), dan manajemen firewall via OPNsense REST API.

Client pattern sama `nawasara/proxmox`: HTTP Basic + Vault + self-signed TLS,
tanpa sidecar.

## Status

**Fase A (Monitoring) — scaffolded.** Dashboard read-only: status appliance
(CPU/RAM/disk/uptime/suhu) + gateway (WAN up/down + latency). Fase B (VPN) & C
(Firewall) menyusul — lihat `docs/nawasara-opnsense/`.

## Setup

1. Kredensial di Vault (group `opnsense`, multi-instance):
   - `host` (mis. `https://10.10.10.1:64443`), `api_key`, `api_secret`, `verify_ssl`
   - Generate API key di OPNsense: System ‣ Access ‣ Users → API keys → `+`
2. Seed permission:
   ```
   php artisan db:seed --class="Nawasara\Opnsense\Database\Seeders\PermissionSeeder"
   ```
3. Sync jalan otomatis tiap `sync_interval` menit (default 2), atau tombol Sync di UI.

## Permissions

Read-only (auto-grant developer): `opnsense.monitor.view`, `opnsense.vpn.view`,
`opnsense.firewall.view`, `opnsense.sync.execute`.
Write-risk (assign eksplisit): `opnsense.vpn.manage`, `opnsense.vpn.renew`,
`opnsense.vpn.export`, `opnsense.firewall.manage`, `opnsense.firewall.apply`.

## Catatan teknis

- Endpoint casing berubah di OPNsense 25.7 (camelCase → snake_case). Client pakai
  snake_case (26.x). Cek `getVersion()` sebelum andalkan casing tertentu.
- CPU & traffic hanya via SSE stream di OPNsense — sync pakai snapshot counter, bukan stream.
- Firewall write (Fase C) staged config — wajib `apply`, auto-rollback 60 detik.
