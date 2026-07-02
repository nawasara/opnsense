<?php

return [
    // HTTP request timeout (detik) ke OPNsense API.
    'request_timeout' => 15,

    // Interval sync status appliance (menit). Status health/traffic relatif
    // sering berubah, tapi 2 menit cukup untuk dashboard tanpa membanjiri API.
    'sync_interval' => 2,

    'scheduler' => [
        // Matikan kalau deployment tanpa kredensial OPNsense — task cuma akan
        // gagal tiap run.
        'enabled' => env('NAWASARA_OPNSENSE_SCHEDULER', true),
    ],

    // Threshold warna untuk dashboard health (persen).
    'thresholds' => [
        'cpu_warn' => 70,
        'cpu_crit' => 90,
        'mem_warn' => 80,
        'mem_crit' => 95,
        'disk_warn' => 80,
        'disk_crit' => 90,
    ],

    // VPN account lifecycle (Fase B/B+).
    'vpn' => [
        // Masa berlaku default akun VPN (bulan). Lewat ini → auto-disable.
        'default_ttl_months' => 6,
        // Tandai "expiring_soon" saat sisa < N hari (badge kuning + alert).
        'warn_before_days' => 14,
        // Setelah expired + N hari grace → boleh hard delete.
        'grace_period_days' => 30,
    ],
];
