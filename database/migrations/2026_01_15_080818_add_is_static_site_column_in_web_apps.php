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
        Schema::table('web_apps', function (Blueprint $table) {
            if(!Schema::hasColumn('web_apps', 'is_static_site'))
            {
                $table->boolean('is_static_site')->default(0)->nullable()->comment('0 non static 1 for static');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_apps', function (Blueprint $table) {
            if(Schema::hasColumn('web_apps', 'is_static_site'))
            {
                $table->dropColumn('is_static_site');
            }
        });
    }
};
