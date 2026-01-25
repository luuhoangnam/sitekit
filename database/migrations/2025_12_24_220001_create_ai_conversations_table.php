<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable(); // Auto-generated from first message
            $table->string('context_type')->nullable(); // server, webapp, database, etc.
            $table->uuid('context_id')->nullable(); // ID of the related resource
            $table->json('messages')->nullable();  // Array of {role, content, timestamp}
            $table->string('provider')->nullable(); // Which AI provider was used
            $table->string('model')->nullable(); // Which model was used
            $table->integer('total_tokens')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'user_id']);
            $table->index(['context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
