<?php

namespace Nawasara\Opnsense\Livewire\Monitor;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Nawasara\Opnsense\Jobs\SyncOpnsenseStatusJob;
use Nawasara\Opnsense\Models\OpnsenseAppliance;

class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('opnsense.monitor.view');
    }

    #[Computed]
    public function appliances()
    {
        return OpnsenseAppliance::with('gateways')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        $appliances = $this->appliances;
        $gateways = $appliances->flatMap->gateways;

        return [
            'appliances_total' => $appliances->count(),
            'appliances_online' => $appliances->where('status', 'online')->count(),
            'gateways_total' => $gateways->count(),
            'gateways_up' => $gateways->where('status', 'online')->count(),
        ];
    }

    public function syncNow(): void
    {
        $this->authorize('opnsense.sync.execute');
        SyncOpnsenseStatusJob::dispatch(triggerSource: 'manual');
        $this->dispatch('toast', type: 'success', message: 'Sync dijalankan — refresh sebentar lagi.');
    }

    public function render()
    {
        return view('nawasara-opnsense::livewire.pages.monitor.index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
