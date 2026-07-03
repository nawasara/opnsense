<?php

$prefix = 'nawasara-opnsense';

return [
    [
        // Gabung ke workspace "monitoring" yang sudah ada (precedent:
        // database-monitor). WorkspaceManager merge submenu antar package
        // dengan workspace id sama.
        'workspace' => 'monitoring',
        'label' => 'Monitoring',
        'icon' => 'lucide-activity',
        'url' => '',
        'permission' => 'opnsense.monitor.view',
        'submenu' => [
            [
                'label' => 'OPNsense',
                'icon' => 'lucide-shield',
                'url' => url($prefix.'/monitor'),
                'permission' => 'opnsense.monitor.view',
                'navigate' => true,
            ],
            [
                'label' => 'VPN OPNsense',
                'icon' => 'lucide-key-round',
                'url' => url($prefix.'/vpn'),
                'permission' => 'opnsense.vpn.view',
                'navigate' => true,
            ],
        ],
    ],
];
