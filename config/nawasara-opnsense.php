<?php

return [
    // HTTP request timeout (detik) ke OPNsense API.
    'request_timeout' => env('OPNSENSE_REQUEST_TIMEOUT', 15),

    /*
    | Cache untuk data read-only yang dipakai UI. Livewire me-render komponen di
    | SETIAP interaksi, jadi tanpa cache satu dropdown bisa memanggil API firewall
    | berkali-kali dan memblokir request sampai timeout — di browser muncul
    | sebagai round-trip Livewire gagal (HTTP 419).
    */
    'cache' => [
        // Daftar server WireGuard (dropdown form akun VPN). Jarang berubah.
        'servers_ttl' => env('OPNSENSE_CACHE_SERVERS_TTL', 300),
    ],

    // Interval sync status appliance (menit). Status health/traffic relatif
    // sering berubah, tapi 2 menit cukup untuk dashboard tanpa membanjiri API.
    'sync_interval' => 2,

    'scheduler' => [
        // Matikan kalau deployment tanpa kredensial OPNsense — task cuma akan
        // gagal tiap run.
        'enabled' => env('NAWASARA_OPNSENSE_SCHEDULER', true),
    ],

    /*
    | Blocklist firewall (audit keamanan 15 Oktober 2026, R8).
    |
    | IP/CIDR yang TIDAK PERNAH boleh masuk blocklist, dipisah koma lewat env.
    | Bawaannya rentang IP publik kantor Kominfo Ponorogo. Rentang privat,
    | loopback, dan link-local selalu dilindungi tanpa perlu ditulis di sini
    | (lihat Support\BlockGuard), dan whitelist auto-block secscan
    | (SECSCAN_AUTOBLOCK_WHITELIST) ikut dipakai bila paketnya terpasang.
    */
    'firewall' => [
        'never_block' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'NAWASARA_OPNSENSE_NEVER_BLOCK',
            '103.109.206.0/24',
        ))))),
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
        // Endpoint host untuk .conf client (WAN address yg didial user).
        // Kosong = pakai host appliance (LAN). Set kalau WAN address beda.
        'endpoint_host' => env('NAWASARA_OPNSENSE_WG_ENDPOINT', ''),

        // Interval (menit) rekonsiliasi daftar akun dengan peer di firewall:
        // impor peer yang dibuat langsung di OPNsense, tandai yang sudah dihapus
        // di sana. Dibatasi 5..59 agar ekspresi cron tetap valid.
        'sync_interval' => env('NAWASARA_OPNSENSE_VPN_SYNC_INTERVAL', 30),
    ],
];
