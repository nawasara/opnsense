<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Monitoring', 'url' => '#'], ['label' => 'OPNsense Firewall']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="Firewall — Blocklist & Alias"
            description="Kelola daftar blokir IP dinamis (integrasi secscan) dan lihat alias firewall.">
            @if ($this->appliances->count() > 1)
                <x-nawasara-ui::form.select
                    wire:model.live="applianceId"
                    :options="$this->appliances->pluck('name', 'id')->toArray()" />
            @endif
        </x-nawasara-ui::page-header>

        {{-- Blocklist panel --}}
        <x-nawasara-ui::page.card>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">Daftar Blokir IP</p>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">
                        Alias <span class="font-mono">nawasara_blocklist</span> + rule block otomatis.
                        Incident secscan bisa auto-tambah ke sini.
                    </p>
                </div>
                @can('opnsense.firewall.manage')
                    <form wire:submit="blockIp" class="flex items-end gap-2">
                        <div>
                            <x-nawasara-ui::form.input
                                wire:model="newIp"
                                placeholder="mis. 45.13.2.7"
                                class="w-40" />
                            @error('newIp') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <x-nawasara-ui::button type="submit" color="danger" wire:loading.attr="disabled">
                            <x-slot:icon><x-lucide-ban /></x-slot:icon>
                            Blokir
                        </x-nawasara-ui::button>
                    </form>
                @endcan
            </div>

            @if (empty($this->blockedIps))
                <x-nawasara-ui::empty-state inline variant="celebrate"
                    icon="lucide-shield-check"
                    title="Tidak ada IP diblokir"
                    description="Belum ada IP di daftar blokir." />
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->blockedIps as $ip)
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 px-3 py-1.5 text-sm font-mono text-red-700 dark:text-red-300">
                            {{ $ip }}
                            @can('opnsense.firewall.manage')
                                <button type="button" wire:click="unblockIp('{{ $ip }}')"
                                    title="Buka blokir"
                                    class="text-red-400 hover:text-red-600 dark:hover:text-red-200">
                                    <x-lucide-x class="size-3.5" />
                                </button>
                            @endcan
                        </span>
                    @endforeach
                </div>
            @endif
        </x-nawasara-ui::page.card>

        {{-- Alias overview (read-only) --}}
        <div class="mt-6">
            <x-nawasara-ui::page.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400 mb-4">Alias Firewall</p>
                @if (empty($this->aliases))
                    <x-nawasara-ui::empty-state inline
                        icon="lucide-database"
                        title="Belum ada data alias"
                        description="Alias akan muncul setelah appliance dipilih." />
                @else
                    <x-nawasara-ui::table :headers="['Nama', 'Tipe', 'Items', 'Deskripsi']">
                        <x-slot:table>
                            @foreach ($this->aliases as $alias)
                                <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                    <td class="px-4 py-3 font-mono text-sm text-neutral-800 dark:text-neutral-100">
                                        {{ $alias['name'] ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                                        {{ $alias['type'] ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                        {{ $alias['current_items'] ?? '0' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-neutral-500 dark:text-neutral-400">
                                        {{ $alias['description'] ?? '' }}
                                    </td>
                                </tr>
                            @endforeach
                        </x-slot:table>
                    </x-nawasara-ui::table>
                @endif
            </x-nawasara-ui::page.card>
        </div>
    </x-nawasara-ui::page.container>
</div>
