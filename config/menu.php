<?php

$prefix = 'nawasara-opnsense';

return [
    [
        // Workspace sendiri, terpisah dari "monitoring" (15 Nov 2026).
        // OPNsense kini berisi VPN dan firewall — pengelolaan akses, bukan
        // sekadar pemantauan — sehingga tempatnya di grup Keamanan.
        'workspace' => 'nawasara-opnsense',
        'label' => 'OPNsense',
        'icon' => 'lucide-shield',
        'group' => 'Keamanan',
        'url' => '',
        'permission' => 'opnsense.monitor.view',
        'submenu' => [
            [
                'label' => 'Ringkasan',
                'icon' => 'lucide-layout-dashboard',
                'url' => url($prefix.'/monitor'),
                'permission' => 'opnsense.monitor.view',
                'navigate' => true,
            ],
            [
                'label' => 'VPN',
                'icon' => 'lucide-key-round',
                'url' => url($prefix.'/vpn'),
                'permission' => 'opnsense.vpn.view',
                'navigate' => true,
            ],
            [
                'label' => 'Firewall',
                'icon' => 'lucide-brick-wall',
                'url' => url($prefix.'/firewall'),
                'permission' => 'opnsense.firewall.view',
                'navigate' => true,
            ],
        ],
    ],
];
