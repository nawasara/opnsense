<?php

namespace Nawasara\Opnsense\Livewire\Firewall;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;
use Nawasara\Opnsense\Models\OpnsenseAppliance;
use Nawasara\Opnsense\Services\FirewallBlocklistService;
use Nawasara\Opnsense\Services\OPNsenseClient;

class Index extends Component
{
    use WithSudo;

    public ?int $applianceId = null;
    public string $newIp = '';

    public function mount(): void
    {
        $this->authorize('opnsense.firewall.view');
        $this->applianceId = OpnsenseAppliance::orderBy('name')->value('id');
    }

    #[Computed]
    public function appliances()
    {
        return OpnsenseAppliance::orderBy('name')->get();
    }

    protected function instance(): ?string
    {
        return OpnsenseAppliance::find($this->applianceId)?->slug;
    }

    /** All aliases on the appliance (read-only overview). */
    #[Computed]
    public function aliases(): array
    {
        if (! $this->applianceId) {
            return [];
        }

        return app(OPNsenseClient::class)->aliasSearch($this->instance());
    }

    /** IPs currently in the Nawasara block list. */
    #[Computed]
    public function blockedIps(): array
    {
        if (! $this->applianceId) {
            return [];
        }

        return app(FirewallBlocklistService::class)->listIps($this->instance());
    }

    public function blockIp(): void
    {
        $this->authorize('opnsense.firewall.manage');
        $this->validate(['newIp' => 'required|ip'], [], ['newIp' => 'IP']);

        $ok = app(FirewallBlocklistService::class)->blockIp($this->newIp, $this->instance());

        if ($ok) {
            $this->dispatch('toast', type: 'success', message: "IP {$this->newIp} diblokir.");
            $this->newIp = '';
            unset($this->blockedIps);
        } else {
            $this->dispatch('toast', type: 'error', message: 'Gagal memblokir IP.');
        }
    }

    #[RequiresSudo(reason: 'membuka blokir IP di firewall')]
    public function unblockIp(string $ip): void
    {
        $this->authorize('opnsense.firewall.manage');

        $ok = app(FirewallBlocklistService::class)->unblockIp($ip, $this->instance());

        if ($ok) {
            $this->dispatch('toast', type: 'success', message: "IP {$ip} dibuka.");
            unset($this->blockedIps);
        } else {
            $this->dispatch('toast', type: 'error', message: 'Gagal membuka blokir.');
        }
    }

    public function render()
    {
        return view('nawasara-opnsense::livewire.pages.firewall.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
