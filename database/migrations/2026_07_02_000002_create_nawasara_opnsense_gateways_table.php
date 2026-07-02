<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_opnsense_gateways', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appliance_id');
            $table->string('name', 128);
            $table->string('address', 64)->nullable();
            $table->string('status', 16)->default('unknown'); // online|down|unknown
            $table->decimal('delay_ms', 8, 2)->nullable();
            $table->decimal('loss_pct', 5, 2)->nullable();
            $table->decimal('stddev_ms', 8, 2)->nullable();
            $table->timestamps();

            $table->foreign('appliance_id')->references('id')->on('nawasara_opnsense_appliances')->cascadeOnDelete();
            $table->unique(['appliance_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_opnsense_gateways');
    }
};
