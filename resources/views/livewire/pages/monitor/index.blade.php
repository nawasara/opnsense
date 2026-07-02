<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Monitoring', 'url' => '#'], ['label' => 'OPNsense']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="OPNsense Firewall"
            description="Status kesehatan, gateway, dan traffic appliance OPNsense."
            :count="$this->stats['appliances_total'] . ' appliance'">
            @can('opnsense.sync.execute')
                <x-nawasara-ui::icon-button
                    icon="refresh-cw"
                    tooltip="Sync sekarang"
                    wire:click="syncNow"
                    loadingTarget="syncNow" />
            @endcan
        </x-nawasara-ui::page-header>

        {{-- Stat cards --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <x-nawasara-ui::stat-card compact
                icon="lucide-server"
                label="Appliance Online"
                :value="$this->stats['appliances_online'] . ' / ' . $this->stats['appliances_total']"
                color="info" />
            <x-nawasara-ui::stat-card compact
                icon="lucide-activity"
                label="Gateway Up"
                :value="$this->stats['gateways_up'] . ' / ' . $this->stats['gateways_total']"
                color="success" />
        </div>

        {{-- Appliance table --}}
        <x-nawasara-ui::page.card>
            @if ($this->appliances->isEmpty())
                <x-nawasara-ui::empty-state
                    icon="lucide-shield-off"
                    title="Belum ada appliance"
                    description="Tambahkan kredensial OPNsense di Vault, lalu jalankan sync." />
            @else
                <x-nawasara-ui::table
                    :headers="['Appliance', 'Versi', 'Status', 'CPU', 'RAM', 'Disk', 'Uptime', 'Suhu']">
                    <x-slot:table>
                        @foreach ($this->appliances as $app)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $app->name }}</div>
                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 font-mono">{{ $app->host }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ $app->version ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <x-nawasara-ui::badge :color="$app->isOnline() ? 'success' : 'danger'">
                                        {{ $app->isOnline() ? 'Online' : ucfirst($app->status) }}
                                    </x-nawasara-ui::badge>
                                </td>
                                <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $app->cpu_pct !== null ? $app->cpu_pct . '%' : '—' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $app->mem_pct !== null ? $app->mem_pct . '%' : '—' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $app->disk_pct !== null ? $app->disk_pct . '%' : '—' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-neutral-500 dark:text-neutral-400 whitespace-nowrap">
                                    @if ($app->uptime_seconds)
                                        {{ \Carbon\CarbonInterval::seconds($app->uptime_seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ $app->temperature_c !== null ? $app->temperature_c . '°C' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:table>
                </x-nawasara-ui::table>
            @endif
        </x-nawasara-ui::page.card>

        {{-- Gateway panel --}}
        @if ($this->appliances->isNotEmpty())
            <div class="mt-6">
                <x-nawasara-ui::page.card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400 mb-4">Gateway (WAN)</p>
                    @php $gws = $this->appliances->flatMap->gateways; @endphp
                    @if ($gws->isEmpty())
                        <x-nawasara-ui::empty-state inline
                            icon="lucide-route"
                            title="Belum ada data gateway"
                            description="Gateway akan muncul setelah sync pertama." />
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach ($gws as $gw)
                                <div class="flex items-center justify-between rounded-lg border border-neutral-200 dark:border-neutral-700 px-4 py-3">
                                    <div>
                                        <div class="font-medium text-sm text-neutral-800 dark:text-neutral-100">{{ $gw->name }}</div>
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400 font-mono">{{ $gw->address ?? '—' }}</div>
                                    </div>
                                    <div class="text-right">
                                        <x-nawasara-ui::badge :color="$gw->statusColor()">
                                            {{ $gw->isUp() ? 'Up' : 'Down' }}
                                        </x-nawasara-ui::badge>
                                        @if ($gw->delay_ms !== null)
                                            <div class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                                                {{ $gw->delay_ms }}ms · loss {{ $gw->loss_pct ?? 0 }}%
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-nawasara-ui::page.card>
            </div>
        @endif
    </x-nawasara-ui::page.container>
</div>
