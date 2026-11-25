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
        Schema::create('event_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('action_type', 20); // 'ticket', 'website'
            $table->string('url', 1024);
            $table->string('target_domain', 100)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_id', 64)->nullable();
            $table->string('referer', 255)->nullable();
            $table->string('device', 10)->nullable(); // 'mobile', 'desktop', 'tablet'
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['event_id', 'action_type', 'created_at'], 'idx_event_action_created');
            $table->index(['action_type', 'created_at'], 'idx_action_created');
            $table->index(['target_domain', 'created_at'], 'idx_target_domain_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_actions');
    }
};
