<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_opnsense_vpn_accounts', function (Blueprint $table) {
            // Stored on sync so the account portal can say "last connected"
            // without calling the firewall on every page load.
            $table->timestamp('last_handshake_at')->nullable()->after('tunnel_address');
            // Set by the owner from the ASN account portal; an admin renews
            // and the renewal clears it. Renewal is not automatic (see README).
            $table->timestamp('renew_requested_at')->nullable()->after('last_renewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_opnsense_vpn_accounts', function (Blueprint $table) {
            $table->dropColumn(['last_handshake_at', 'renew_requested_at']);
        });
    }
};
