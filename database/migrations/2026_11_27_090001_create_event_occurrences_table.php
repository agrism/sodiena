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
        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('status', 30)->default('scheduled');
            $table->boolean('is_free')->default(false);
            $table->decimal('price_min', 8, 2)->nullable();
            $table->decimal('price_max', 8, 2)->nullable();
            $table->string('currency', 10)->default('EUR');
            $table->text('ticket_url')->nullable();
            $table->string('presentation_format', 50)->nullable();
            $table->string('auditorium', 120)->nullable();
            $table->json('spoken_languages')->nullable()->default(null);
            $table->json('subtitle_languages')->nullable()->default(null);
            $table->string('language_source', 20)->nullable()->default(null);
            $table->string('occurrence_fingerprint', 64);
            $table->foreignId('merged_into_id')->nullable()->constrained('event_occurrences')->nullOnDelete();
            $table->unsignedBigInteger('legacy_event_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes
            $table->index(['event_id', 'status', 'start_at'], 'idx_eo_event_status_start');
            $table->index(['status', 'start_at'], 'idx_eo_status_start');
            $table->index('occurrence_fingerprint', 'idx_eo_fingerprint');
            $table->index('merged_into_id', 'idx_eo_merged_into');
            $table->index('deleted_at', 'idx_eo_deleted_at');
            $table->unique('legacy_event_id', 'uq_eo_legacy_event_id');
        });

        // MySQL 8.0 specific CHECK constraints
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE event_occurrences
                    ADD CONSTRAINT check_eo_status
                        CHECK (status IN ('scheduled', 'postponed', 'cancelled', 'completed', 'merged', 'deleted')),
                    ADD CONSTRAINT check_eo_language_source
                        CHECK (language_source IS NULL OR language_source IN ('manual', 'explicit', 'inferred', 'unknown')),
                    ADD CONSTRAINT check_eo_spoken_languages_array
                        CHECK (spoken_languages IS NULL OR JSON_TYPE(spoken_languages) = 'ARRAY'),
                    ADD CONSTRAINT check_eo_subtitle_languages_array
                        CHECK (subtitle_languages IS NULL OR JSON_TYPE(subtitle_languages) = 'ARRAY')
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
                DB::statement("ALTER TABLE event_occurrences DROP CHECK check_eo_status");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE event_occurrences DROP CHECK check_eo_language_source");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE event_occurrences DROP CHECK check_eo_spoken_languages_array");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE event_occurrences DROP CHECK check_eo_subtitle_languages_array");
            } catch (\Throwable $e) {}
        }

        Schema::dropIfExists('event_occurrences');
    }
};
