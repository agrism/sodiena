<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_redirects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('old_event_id')->nullable();
            $table->string('locale', 10)->default('lv');
            $table->string('old_slug', 255);
            $table->foreignId('target_event_id')->constrained('events')->cascadeOnDelete();
            $table->string('reason', 30)->default('merged');
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->unique(['locale', 'old_slug'], 'uq_event_redirects_locale_slug');
            $table->index('old_event_id', 'idx_event_redirects_old_event_id');
            $table->index('target_event_id', 'idx_event_redirects_target');
        });

        // MySQL 8.0 specific CHECK constraints
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE event_redirects
                    ADD CONSTRAINT check_event_redirects_reason
                        CHECK (reason IN ('migrated', 'merged', 'slug_changed'))
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            try {
                DB::statement("ALTER TABLE event_redirects DROP CHECK check_event_redirects_reason");
            } catch (\Throwable $e) {}
        }

        Schema::dropIfExists('event_redirects');
    }
};
