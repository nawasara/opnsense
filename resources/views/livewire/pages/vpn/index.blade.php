<div>
    <x-slot name="breadcrumb">
        <livewire:nawasara-ui.shared-components.breadcrumb
            :items="[['label' => 'Monitoring', 'url' => '#'], ['label' => 'OPNsense VPN']]" />
    </x-slot>

    <x-nawasara-ui::page.container>
        <x-nawasara-ui::page-header
            title="VPN — WireGuard"
            description="Akun VPN melekat ke akun SSO dengan masa berlaku. Lewat expiry otomatis dinonaktifkan.">
            @can('opnsense.vpn.manage')
                {{-- Reconcile with the firewall: imports peers made directly in
                     OPNsense, flags ones deleted there. --}}
                <x-nawasara-ui::button color="neutral" variant="outline" wire:click="syncAccounts"
                    wire:loading.attr="disabled" wire:target="syncAccounts">
                    <x-slot:icon><x-lucide-refresh-cw /></x-slot:icon>
                    Sinkron
                </x-nawasara-ui::button>
                <x-nawasara-ui::button color="primary" wire:click="openForm"
                    x-on:click="$dispatch('open-modal', { id: 'vpn-provision' })">
                    <x-slot:icon><x-lucide-plus /></x-slot:icon>
                    Buat Akun VPN
                </x-nawasara-ui::button>
            @endcan
        </x-nawasara-ui::page-header>

        {{-- Toolbar: filter kiri, search kanan --}}
        <div class="space-y-2 mb-4">
            <div class="flex flex-col md:flex-row md:flex-nowrap md:items-center gap-2">
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <x-nawasara-ui::filter-panel
                        label="Filter"
                        :state="['filterStatus' => $filterStatus]"
                        :dimensions="['filterStatus' => 'Status']">
                        <x-nawasara-ui::filter-group
                            label="Status"
                            model="filterStatus"
                            :items="['active' => 'Aktif', 'expiring_soon' => 'Akan Kedaluwarsa', 'expired' => 'Kedaluwarsa', 'revoked' => 'Dicabut']"
                            icon="lucide-list-checks" />
                    </x-nawasara-ui::filter-panel>
                </div>
                <x-nawasara-ui::search-input model="search" placeholder="Cari nama akun / pemilik…" />
            </div>
            <div wire:ignore data-filter-chips></div>
            @if ($search !== '')
                <div class="flex flex-wrap items-center gap-2">
                    <x-nawasara-ui::filter-chip label="Cari: {{ $search }}" model="search" />
                </div>
            @endif
        </div>

        <x-nawasara-ui::page.card>
            @if ($this->accounts->isEmpty())
                <x-nawasara-ui::empty-state
                    icon="lucide-shield-off"
                    title="Belum ada akun VPN"
                    description="Buat akun VPN untuk user SSO — pilih pemilik, server, dan masa berlaku." />
            @else
                <x-nawasara-ui::table stickyLast
                    :headers="['Nama', 'Pemilik (SSO)', 'Server', 'Alamat', 'Status', 'Berlaku s/d', '']">
                    <x-slot:table>
                        @foreach ($this->accounts as $acc)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $acc->name }}</div>
                                    <div class="text-xs text-neutral-400 dark:text-neutral-500 uppercase">{{ $acc->type }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                    @if ($acc->user)
                                        <div>{{ $acc->user->name }}</div>
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $acc->user->email }}</div>
                                    @else
                                        <span class="text-amber-600 dark:text-amber-400 text-xs italic">user dihapus</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ $acc->appliance?->name ?? '—' }}
                                </td>
                                <td class="px-4 py-3 font-mono text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ $acc->tunnel_address ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <x-nawasara-ui::badge :color="$acc->statusColor()">
                                        {{ ['active' => 'Aktif', 'expiring_soon' => 'Akan Kedaluwarsa', 'expired' => 'Kedaluwarsa', 'revoked' => 'Dicabut'][$acc->status] ?? ucfirst($acc->status) }}
                                    </x-nawasara-ui::badge>
                                </td>
                                <td class="px-4 py-3 text-sm whitespace-nowrap">
                                    @if ($acc->expires_at)
                                        <x-nawasara-ui::badge :color="$acc->expiryColor()">
                                            {{ $acc->expires_at->format('d M Y') }}
                                        </x-nawasara-ui::badge>
                                        @php $days = $acc->daysUntilExpiry(); @endphp
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                                            {{ $days < 0 ? 'lewat ' . abs($days) . ' hari' : 'sisa ' . $days . ' hari' }}
                                        </div>
                                    @else
                                        <span class="text-neutral-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @php
                                        $items = [];
                                        if (auth()->user()->can('opnsense.vpn.export') && $acc->status !== 'revoked') {
                                            $items[] = ['type' => 'click', 'label' => 'Unduh Config', 'wire:click' => 'download('.$acc->id.')', 'icon' => 'lucide-download'];
                                        }
                                        if (auth()->user()->can('opnsense.vpn.renew') && $acc->status !== 'revoked') {
                                            $items[] = ['type' => 'click', 'label' => 'Perpanjang', 'wire:click' => 'renew('.$acc->id.')', 'icon' => 'lucide-refresh-cw'];
                                        }
                                        if (auth()->user()->can('opnsense.vpn.manage') && $acc->status !== 'revoked') {
                                            $items[] = ['type' => 'click', 'label' => 'Cabut', 'wire:click' => 'revoke('.$acc->id.')', 'icon' => 'lucide-ban', 'confirm' => 'Cabut akun VPN ini? Peer akan dihapus di OPNsense.'];
                                        }
                                    @endphp
                                    @if (! empty($items))
                                        <x-nawasara-ui::dropdown-menu-action :id="$acc->id" :items="$items" />
                                    @else
                                        <span class="text-neutral-400 dark:text-neutral-600 text-sm">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:table>
                </x-nawasara-ui::table>

                <div class="mt-4">
                    {{ $this->accounts->links() }}
                </div>
            @endif
        </x-nawasara-ui::page.card>
    </x-nawasara-ui::page.container>

    {{-- Provision modal --}}
    <x-nawasara-ui::modal id="vpn-provision" title="Buat Akun VPN" maxWidth="lg">
        {{-- Buttons live INSIDE the <form> (not the modal footer slot) so the
             submit button is guaranteed inside <form> in the DOM — the footer
             slot renders in a separate div, breaking submit for a button there. --}}
        <form wire:submit="provision" class="space-y-4">
            {{-- Pemilik — dicari di direktori Keycloak, bukan tabel users lokal.
                 Pemilihan pakai index (bukan id+nama lewat atribut) supaya nama
                 ber-apostrof tidak merusak parsing wire:click. --}}
            <div>
                <x-nawasara-ui::form.label value="Pemilik (Akun SSO)" />
                <div class="relative">
                    <x-nawasara-ui::form.input
                        type="text"
                        wire:model.live.debounce.300ms="formUserSearch"
                        placeholder="Ketik nama / NIP / email…"
                        autocomplete="off" />
                    @if ($this->userOptions->isNotEmpty() && ! $formUserId)
                        <div class="absolute z-20 mt-1 w-full bg-white dark:bg-neutral-800 border border-neutral-200 dark:border-neutral-700 rounded-lg shadow-lg max-h-56 overflow-y-auto">
                            @foreach ($this->userOptions as $idx => $u)
                                <button type="button"
                                    wire:key="vpn-owner-{{ $u['kc_id'] ?? $u['user_id'] ?? $idx }}"
                                    wire:click="selectUser({{ $idx }})"
                                    class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-700">
                                    <span class="min-w-0">
                                        <span class="block truncate text-neutral-800 dark:text-neutral-100">{{ $u['name'] }}</span>
                                        <span class="block truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $u['subtitle'] }}</span>
                                    </span>
                                    @unless ($u['user_id'])
                                        <x-nawasara-ui::badge color="info">Akun baru</x-nawasara-ui::badge>
                                    @endunless
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                @error('formUserId') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-nawasara-ui::form.select
                        label="Appliance"
                        wire:model.live="formApplianceId"
                        :options="$this->appliances->pluck('name', 'id')->toArray()"
                        placeholder="Pilih appliance" />
                </div>
                <div>
                    <x-nawasara-ui::form.select
                        label="Server WireGuard"
                        wire:model="formServerUuid"
                        :options="collect($this->serverOptions)->pluck('name', 'uuid')->toArray()"
                        placeholder="Pilih server" />
                    @error('formServerUuid') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-nawasara-ui::form.input
                    type="number"
                    label="Masa Berlaku (bulan)"
                    wire:model="formTtlMonths"
                    min="1" max="60" />
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                    Default 6 bulan. Nama akun otomatis dari pemilik SSO.
                </p>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200 dark:border-neutral-700">
                <x-nawasara-ui::button type="button" color="neutral" variant="outline"
                    x-on:click="$dispatch('close-modal', 'vpn-provision')">
                    Batal
                </x-nawasara-ui::button>
                <x-nawasara-ui::button type="submit" color="primary"
                    wire:loading.attr="disabled" wire:target="provision">
                    Buat & Provision
                </x-nawasara-ui::button>
            </div>
        </form>
    </x-nawasara-ui::modal>
</div>
