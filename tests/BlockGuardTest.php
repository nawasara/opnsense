<?php

namespace Nawasara\Opnsense\Tests;

use Nawasara\Opnsense\Support\BlockGuard;
use PHPUnit\Framework\TestCase;

/**
 * Whitelist blocklist firewall (audit keamanan 15 Oktober 2026, R8).
 *
 * Memblokir IP kantor atau rentang privat di OPNsense memutus staf dan server
 * dalam dari jaringannya sendiri.
 */
class BlockGuardTest extends TestCase
{
    public function test_ip_kantor_dari_config_ditolak(): void
    {
        $this->assertNotNull(BlockGuard::reasonNotToBlock('103.109.206.45', ['103.109.206.0/24']));
        $this->assertNull(BlockGuard::reasonNotToBlock('103.109.207.45', ['103.109.206.0/24']));
    }

    public function test_rentang_privat_selalu_ditolak_tanpa_config(): void
    {
        foreach (['10.1.1.30', '192.168.1.1', '172.20.0.5', '127.0.0.1', '169.254.1.1', '100.64.3.3', '::1', 'fd00::1', 'fe80::1'] as $ip) {
            $this->assertNotNull(BlockGuard::reasonNotToBlock($ip), $ip);
        }
    }

    public function test_ip_publik_biasa_boleh_diblokir(): void
    {
        $this->assertNull(BlockGuard::reasonNotToBlock('45.12.1.9'));
        $this->assertNull(BlockGuard::reasonNotToBlock('2a01:4f8::1'));
    }

    public function test_input_tidak_valid_dan_cidr_kosong(): void
    {
        $this->assertNotNull(BlockGuard::reasonNotToBlock('1.2.3'));
        $this->assertNull(BlockGuard::reasonNotToBlock('45.12.1.9', ['', '  ']));
    }
}
