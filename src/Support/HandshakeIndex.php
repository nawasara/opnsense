<?php

namespace Nawasara\Opnsense\Support;

/**
 * Turns the rows of GET /api/wireguard/service/show into
 * [public key => last handshake (unix seconds)].
 *
 * The row shape differs between OPNsense releases (`latest-handshake` vs
 * `latest_handshake`, `public-key` vs `pubkey`), and a peer that never
 * connected reports 0. Those are left out rather than stored as 1970.
 */
final class HandshakeIndex
{
    /** @return array<string, int> */
    public static function fromShowRows(array $rows): array
    {
        $index = [];

        foreach ($rows as $row) {
            if (! is_array($row) || (($row['type'] ?? 'peer') !== 'peer')) {
                continue;
            }

            $key = $row['public-key'] ?? $row['public_key'] ?? $row['pubkey'] ?? null;
            $at = $row['latest-handshake'] ?? $row['latest_handshake'] ?? null;

            if (! is_string($key) || $key === '' || ! is_numeric($at) || (int) $at <= 0) {
                continue;
            }

            $index[$key] = max((int) $at, $index[$key] ?? 0);
        }

        return $index;
    }
}
