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
        // Add provisioning_phase to servers table
        Schema::table('servers', function (Blueprint $table) {
            $table->string('provisioning_phase', 20)->default('pending')->after('status');
        });

        Schema::create('server_provisioning_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('server_id')->constrained()->onDelete('cascade');

            // Step identification
            $table->string('step_type', 50); // provision_nginx, provision_php, etc.
            $table->string('step_name', 100); // Human-readable name
            $table->string('category', 50); // web_server, php, database, cache, etc.
            $table->integer('order')->default(0); // Display order

            // Status tracking
            $table->string('status', 20)->default('pending'); // pending, queued, in_progress, completed, failed, skipped

            // Configuration
            $table->boolean('is_required')->default(true);
            $table->boolean('is_default')->default(true);
            $table->json('configuration')->nullable(); // version, options, etc.

            // Execution tracking
            #$table->foreignId('agent_job_id')->nullable()->constrained('agent_jobs')->nullOnDelete();
            $table->text('output')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('exit_code')->nullable();

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['server_id', 'status']);
            $table->index(['server_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_provisioning_steps');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('provisioning_phase');
        });
    }
};
