<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_opnsense_vpn_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appliance_id');
            $table->unsignedBigInteger('user_id')->nullable();  // pemilik SSO — nullOnDelete
            $table->string('type', 16)->default('wireguard');    // wireguard|openvpn
            $table->string('name', 128);                         // nama peer di OPNsense
            $table->string('opnsense_uuid', 64)->nullable();     // uuid peer/instance di appliance
            $table->string('server_uuid', 64)->nullable();       // server WG yang dipakai
            $table->string('public_key', 128)->nullable();
            // Private key disimpan terenkripsi (cast 'encrypted') — dibutuhkan untuk
            // re-generate .conf setelah pertama kali. OPNsense tak simpan privkey.
            $table->text('private_key')->nullable();
            $table->string('tunnel_address', 64)->nullable();    // 10.10.10.x/32
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 16)->default('active');     // active|expiring_soon|expired|revoked
            $table->unsignedBigInteger('provisioned_by')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('last_renewed_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('appliance_id')->references('id')->on('nawasara_opnsense_appliances')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'expires_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_opnsense_vpn_accounts');
    }
};
