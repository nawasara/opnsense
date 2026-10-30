<?php

namespace Nawasara\Opnsense\Tests;

use Nawasara\Opnsense\Support\HandshakeIndex;
use PHPUnit\Framework\TestCase;

class HandshakeIndexTest extends TestCase
{
    public function test_reads_both_row_spellings_and_skips_never_connected(): void
    {
        $index = HandshakeIndex::fromShowRows([
            ['type' => 'interface', 'public-key' => 'SERVER', 'latest-handshake' => 1_761_000_000],
            ['type' => 'peer', 'public-key' => 'A', 'latest-handshake' => 1_761_000_100],
            ['type' => 'peer', 'pubkey' => 'B', 'latest_handshake' => '1761000200'],
            ['type' => 'peer', 'public-key' => 'C', 'latest-handshake' => 0],
            ['type' => 'peer', 'latest-handshake' => 1_761_000_300],
            'garbage',
        ]);

        $this->assertSame(['A' => 1_761_000_100, 'B' => 1_761_000_200], $index);
    }

    public function test_keeps_latest_when_a_key_appears_twice(): void
    {
        $index = HandshakeIndex::fromShowRows([
            ['type' => 'peer', 'public-key' => 'A', 'latest-handshake' => 200],
            ['type' => 'peer', 'public-key' => 'A', 'latest-handshake' => 100],
        ]);

        $this->assertSame(['A' => 200], $index);
    }
}
