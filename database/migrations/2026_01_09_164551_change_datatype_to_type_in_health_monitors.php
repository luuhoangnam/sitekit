<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('health_monitors', function (Blueprint $table) {
        //    $table->enum('type', [
        //         'http',
        //         'https',
        //         'tcp',
        //         'ping',
        //         'heartbeat',
        //         'ssl_expiry',
        //     ])->change();
        $table->string('type')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_monitors', function (Blueprint $table) {
            //
        });
    }
};
