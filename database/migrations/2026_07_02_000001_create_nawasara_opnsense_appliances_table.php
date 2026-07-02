<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_opnsense_appliances', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();       // Vault instance name
            $table->string('name', 128);
            $table->string('host', 255);
            $table->string('version', 32)->nullable();
            $table->string('status', 16)->default('unknown'); // online|unreachable|unknown
            $table->unsignedTinyInteger('cpu_pct')->nullable();
            $table->unsignedTinyInteger('mem_pct')->nullable();
            $table->unsignedTinyInteger('disk_pct')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->decimal('temperature_c', 5, 1)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_opnsense_appliances');
    }
};
