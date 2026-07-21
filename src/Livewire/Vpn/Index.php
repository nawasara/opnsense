<?php

namespace Nawasara\Opnsense\Livewire\Vpn;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;
use Nawasara\Opnsense\Jobs\SyncVpnAccountsJob;
use Nawasara\Opnsense\Models\OpnsenseAppliance;
use Nawasara\Opnsense\Models\OpnsenseVpnAccount;
use Nawasara\Opnsense\Services\OPNsenseClient;
use Nawasara\Opnsense\Services\WireguardConfigBuilder;

class Index extends Component
{
    use WithPagination;
    use WithSudo;

    public string $search = '';
    public string $filterStatus = '';

    // Provision form
    public ?int $formUserId = null;
    public string $formUserSearch = '';
    public ?int $formApplianceId = null;
    public ?string $formServerUuid = null;
    public string $formName = '';
    public int $formTtlMonths = 6;

    public function mount(): void
    {
        $this->authorize('opnsense.vpn.view');
        $this->formTtlMonths = (int) config('nawasara-opnsense.vpn.default_ttl_months', 6);
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedFilterStatus(): void { $this->resetPage(); }

    #[Computed]
    public function accounts()
    {
        return OpnsenseVpnAccount::with(['user', 'appliance'])
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%"));
            }))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    /** SSO users matching the form search — for the provision dropdown. */
    #[Computed]
    public function userOptions()
    {
        if (strlen($this->formUserSearch) < 2) {
            return collect();
        }

        return User::where('auth_type', 'sso')
            ->where(fn ($q) => $q->where('name', 'like', "%{$this->formUserSearch}%")
                ->orWhere('email', 'like', "%{$this->formUserSearch}%")
                ->orWhere('username', 'like', "%{$this->formUserSearch}%"))
            ->limit(10)
            ->get(['id', 'name', 'email']);
    }

    #[Computed]
    public function appliances()
    {
        return OpnsenseAppliance::orderBy('name')->get();
    }

    /**
     * WireGuard servers on the selected appliance (for the server dropdown).
     *
     * The result is cached in OPNsenseClient — this runs on every Livewire
     * render, and an uncached/blocking call to the firewall made the round-trip
     * fail (surfacing as HTTP 419 in the browser). A failure here must never
     * take the whole page down: log it and return an empty list so the form
     * still renders with a "no server" state.
     */
    #[Computed]
    public function serverOptions()
    {
        if (! $this->formApplianceId) {
            return [];
        }
        $appliance = OpnsenseAppliance::find($this->formApplianceId);
        if (! $appliance) {
            return [];
        }

        try {
            return app(OPNsenseClient::class)->wireguardServers($appliance->slug);
        } catch (\Throwable $e) {
            Log::warning('[opnsense] gagal memuat daftar server WireGuard: '.$e->getMessage(), [
                'appliance' => $appliance->slug,
            ]);

            return [];
        }
    }

    /**
     * Pull the peer list from the firewall now: import peers created directly in
     * OPNsense and flag records whose peer is gone. Queued so a slow firewall
     * never blocks the request.
     */
    public function syncAccounts(): void
    {
        $this->authorize('opnsense.vpn.manage');

        SyncVpnAccountsJob::dispatch(triggerSource: 'manual');

        $this->dispatch('toast', type: 'success',
            message: 'Sinkronisasi akun VPN berjalan di latar belakang — muat ulang sebentar lagi.');
    }

    public function openForm(): void
    {
        $this->authorize('opnsense.vpn.manage');
        $this->reset(['formUserId', 'formUserSearch', 'formServerUuid', 'formName']);
        $this->formTtlMonths = (int) config('nawasara-opnsense.vpn.default_ttl_months', 6);
        $this->formApplianceId = $this->appliances->first()?->id;
        $this->dispatch('modal-open:vpn-provision');
    }

    public function selectUser(int $userId, string $label): void
    {
        $this->formUserId = $userId;
        $this->formUserSearch = $label;
    }

    /**
     * Provision a WireGuard peer bound to an SSO user with a validity window.
     * Flow: generate keypair → add_client (pubkey only) → reconfigure → record.
     */
    public function provision(): void
    {
        $this->authorize('opnsense.vpn.manage');

        $this->validate([
            'formUserId' => 'required|integer|exists:users,id',
            'formApplianceId' => 'required|integer|exists:nawasara_opnsense_appliances,id',
            'formServerUuid' => 'required|string',
            'formTtlMonths' => 'required|integer|min:1|max:60',
        ], [], [
            'formUserId' => 'pemilik SSO',
            'formServerUuid' => 'server WireGuard',
        ]);

        $appliance = OpnsenseAppliance::findOrFail($this->formApplianceId);
        $client = app(OPNsenseClient::class);
        $instance = $appliance->slug;

        // Peer name derived from the SSO owner (username preferred, else email
        // local-part), sanitised to WireGuard's allowed charset. Uniqueness is
        // ensured by a short suffix if the owner already has a peer.
        $owner = \App\Models\User::find($this->formUserId);
        $this->formName = $this->makePeerName($owner);

        // Every step below talks to the firewall. A transport failure (timeout,
        // TLS, 5xx) would otherwise bubble up and break the Livewire round-trip
        // — the browser shows a bare error instead of a usable message.
        try {
            // 1. Generate keypair (OPNsense won't store the private key for us).
            $keys = $client->wireguardKeyPair($instance);
            if ($keys === null) {
                $this->dispatch('toast', type: 'error', message: 'Gagal generate keypair di OPNsense.');
                return;
            }

            // 2. Allocate a tunnel address — next free /32 in the server network.
            $tunnelAddress = $this->nextTunnelAddress($client, $instance);

            // 3. Register the peer (public key only).
            $uuid = $client->addWireguardClient([
                'name' => $this->formName,
                'pubkey' => $keys['pubkey'],
                'tunneladdress' => $tunnelAddress,
                'servers' => $this->formServerUuid,
            ], $instance);

            if ($uuid === null) {
                $this->dispatch('toast', type: 'error', message: 'Gagal menambah peer di OPNsense.');
                return;
            }

            // 4. Apply.
            $client->reconfigureWireguard($instance);
        } catch (\Throwable $e) {
            // Covers both transport failures and unexpected API payload shapes;
            // don't claim a connection problem when it may be neither.
            Log::error('[opnsense] provisioning VPN gagal: '.$e->getMessage(), [
                'appliance' => $instance,
                'user_id' => $this->formUserId,
                'exception' => $e::class,
                'at' => basename($e->getFile()).':'.$e->getLine(),
            ]);
            $this->dispatch('toast', type: 'error',
                message: 'Gagal membuat akun VPN: '.$e->getMessage());

            return;
        }

        // 5. Record locally with encrypted private key + expiry.
        OpnsenseVpnAccount::create([
            'appliance_id' => $appliance->id,
            'user_id' => $this->formUserId,
            'type' => 'wireguard',
            'name' => $this->formName,
            'opnsense_uuid' => $uuid,
            'server_uuid' => $this->formServerUuid,
            'public_key' => $keys['pubkey'],
            'private_key' => $keys['privkey'],
            'tunnel_address' => $tunnelAddress,
            'expires_at' => now()->addMonths($this->formTtlMonths),
            'status' => OpnsenseVpnAccount::STATUS_ACTIVE,
            'provisioned_by' => auth()->id(),
            'provisioned_at' => now(),
        ]);

        // The modal listens for the id in the event NAME ($wire.on('modal-close:<id>')),
        // not as a payload. Dispatching 'close-modal' from Livewire hits neither
        // listener — the .window variant only fires for Alpine's $dispatch — so the
        // modal stayed open on top of the refreshed table, looking like the save failed.
        $this->dispatch('modal-close:vpn-provision');
        $this->dispatch('toast', type: 'success', message: 'Akun VPN dibuat. Unduh config untuk user.');
        unset($this->accounts);

        // Clear the form so the next "Buat Akun VPN" starts blank rather than
        // re-showing the peer that was just created.
        $this->formUserId = null;
        $this->formUserSearch = '';
        $this->formName = '';
        $this->formServerUuid = null;
        $this->formTtlMonths = (int) config('nawasara-opnsense.vpn.default_ttl_months', 6);
    }

    public function renew(int $id): void
    {
        $this->authorize('opnsense.vpn.renew');

        $account = OpnsenseVpnAccount::with('appliance')->findOrFail($id);
        $client = app(OPNsenseClient::class);
        $instance = $account->appliance?->slug;

        // Re-enable at OPNsense if it was disabled on expiry.
        if ($account->opnsense_uuid) {
            $client->toggleWireguardClient($account->opnsense_uuid, true, $instance);
            $client->reconfigureWireguard($instance);
        }

        $ttl = (int) config('nawasara-opnsense.vpn.default_ttl_months', 6);
        $account->update([
            'expires_at' => now()->addMonths($ttl),
            'status' => OpnsenseVpnAccount::STATUS_ACTIVE,
            'last_renewed_at' => now(),
        ]);

        $this->dispatch('toast', type: 'success', message: 'Masa berlaku diperpanjang '.$ttl.' bulan.');
        unset($this->accounts);
    }

    #[RequiresSudo(reason: 'mencabut akun VPN')]
    public function revoke(int $id): void
    {
        $this->authorize('opnsense.vpn.manage');

        $account = OpnsenseVpnAccount::with('appliance')->findOrFail($id);
        $client = app(OPNsenseClient::class);
        $instance = $account->appliance?->slug;

        if ($account->opnsense_uuid) {
            $client->delWireguardClient($account->opnsense_uuid, $instance);
            $client->reconfigureWireguard($instance);
        }

        $account->update([
            'status' => OpnsenseVpnAccount::STATUS_REVOKED,
            'revoked_by' => auth()->id(),
            'revoked_at' => now(),
        ]);

        $this->dispatch('toast', type: 'success', message: 'Akun VPN dicabut.');
        unset($this->accounts);
    }

    /**
     * Stream the client .conf. Gated by export permission + audited; never
     * written to disk. Contains the private key.
     */
    public function download(int $id)
    {
        $this->authorize('opnsense.vpn.export');

        $account = OpnsenseVpnAccount::with('appliance')->findOrFail($id);
        $conf = app(WireguardConfigBuilder::class)->build($account);

        if ($conf === null) {
            $this->dispatch('toast', type: 'error', message: 'Gagal membuat config (server/private key tidak lengkap).');
            return;
        }

        $filename = 'wg-'.preg_replace('/[^A-Za-z0-9_.-]/', '', $account->name).'.conf';

        return Response::streamDownload(function () use ($conf) {
            echo $conf;
        }, $filename, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Pick the next free /32 tunnel address in the server network. Best-effort:
     * scans existing peers and increments the host octet.
     */
    protected function nextTunnelAddress(OPNsenseClient $client, ?string $instance): string
    {
        $used = [];
        foreach ($client->wireguardShow($instance) as $row) {
            if (($row['type'] ?? '') !== 'peer') {
                continue;
            }
            // allowed-ips may be a single CIDR string or a list of them,
            // depending on the OPNsense version/endpoint. Normalise to a list.
            foreach ($this->toCidrList($row['allowed-ips'] ?? null) as $cidr) {
                $used[] = trim(explode('/', $cidr)[0]);
            }
        }

        $server = $client->wireguardServer($this->formServerUuid, $instance);
        // tunneladdress has the same string-or-list shape; take the first entry.
        $tunnel = $this->toCidrList($server['tunneladdress'] ?? null)[0] ?? '10.10.10.1/24';
        $base = explode('/', $tunnel)[0];               // 10.10.10.1
        $prefix = implode('.', array_slice(explode('.', $base), 0, 3)); // 10.10.10

        for ($i = 2; $i < 255; $i++) {
            $candidate = "{$prefix}.{$i}";
            if (! in_array($candidate, $used, true)) {
                return "{$candidate}/32";
            }
        }

        return "{$prefix}.254/32";
    }

    /**
     * Normalise an OPNsense address field to a list of CIDR strings.
     *
     * The API is inconsistent across versions and endpoints: an address can come
     * back as a single string, a comma-separated string, a plain list, or a
     * keyed map ("10.0.0.2/32" => ['selected' => 1]). Passing the raw value to
     * explode() blew up with "explode(): Argument #2 must be of type string,
     * array given" and aborted provisioning.
     *
     * @return list<string>
     */
    protected function toCidrList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $entry) {
                // Keyed map: the CIDR is the key. Plain list: it's the value.
                $candidate = is_string($key) && ! is_numeric($key) ? $key : $entry;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $out[] = trim($candidate);
                }
            }

            return array_values($out);
        }

        return [];
    }

    /**
     * Derive a WireGuard peer name from the SSO owner. Prefer username, else the
     * email local-part; sanitise to [A-Za-z0-9_.-]; append a numeric suffix when
     * the owner already has account(s) so names stay unique.
     */
    protected function makePeerName(?\App\Models\User $owner): string
    {
        $raw = $owner?->username ?: (\Illuminate\Support\Str::before((string) ($owner?->email ?? ''), '@'));
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '', str_replace(' ', '-', (string) $raw)) ?: 'user';
        $base = substr($base, 0, 56);

        $count = OpnsenseVpnAccount::where('user_id', $owner?->id)->count();

        return $count === 0 ? $base : "{$base}-".($count + 1);
    }

    public function render()
    {
        return view('nawasara-opnsense::livewire.pages.vpn.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
