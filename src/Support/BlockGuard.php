<?php

namespace Nawasara\Opnsense\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Daftar IP yang tidak boleh masuk blocklist firewall OPNsense.
 *
 * Audit keamanan 15 Oktober 2026 (R8): blockIp() menerima IP apa pun. Satu
 * salah ketik, atau satu klik pada IP kantor (103.109.206.0/24) atau rentang
 * privat, mengunci staf atau server dalam dari jaringan sendiri. OPNsense ada
 * di jalur semua lalu lintas kantor, jadi kerugiannya lebih luas daripada
 * blokir Cloudflare.
 *
 * Daftarnya dari config `nawasara-opnsense.firewall.never_block`, ditambah
 * whitelist auto-block secscan bila paket itu terpasang, supaya keduanya
 * tidak perlu dirawat terpisah.
 */
class BlockGuard
{
    /**
     * Rentang yang tidak pernah boleh diblokir, apa pun isi config. Privat,
     * loopback, link-local, CGNAT, dan padanan IPv6-nya.
     */
    public const ALWAYS_PROTECTED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16',
        '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    /**
     * Mengembalikan alasan penolakan, atau null bila IP boleh diblokir.
     *
     * @param  list<string>  $extraCidrs  rentang tambahan (config)
     */
    public static function reasonNotToBlock(string $ip, array $extraCidrs = []): ?string
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'Alamat IP tidak valid.';
        }

        if (IpUtils::checkIp($ip, self::ALWAYS_PROTECTED)) {
            return 'IP privat, loopback, atau link-local tidak boleh diblokir.';
        }

        $extra = array_values(array_filter(array_map('trim', $extraCidrs), fn ($c) => $c !== ''));
        if ($extra !== [] && IpUtils::checkIp($ip, $extra)) {
            return 'IP termasuk daftar yang dilindungi (kantor / whitelist).';
        }

        return null;
    }

    /** @return list<string> */
    public static function configuredCidrs(): array
    {
        $own = (array) config('nawasara-opnsense.firewall.never_block', []);
        $secscan = (array) config('nawasara-secscan.autoblock.whitelist_cidrs', []);

        return array_values(array_unique(array_merge($own, $secscan)));
    }
}
