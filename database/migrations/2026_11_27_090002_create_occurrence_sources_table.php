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
        Schema::create('occurrence_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->string('source_external_id', 255);
            $table->text('source_url')->nullable();
            $table->text('ticket_url')->nullable();
            $table->decimal('price_min', 8, 2)->nullable();
            $table->decimal('price_max', 8, 2)->nullable();
            $table->string('currency', 10)->default('EUR');
            $table->boolean('is_free')->nullable()->default(null);
            $table->string('raw_title', 255)->nullable();
            $table->longText('raw_description')->nullable();
            $table->text('raw_image_url')->nullable();
            $table->json('raw_categories')->nullable()->default(null);
            $table->json('raw_payload')->nullable()->default(null);
            $table->string('content_hash', 64)->nullable();
            $table->foreignId('last_scrape_log_id')->nullable()->constrained('scrape_logs')->nullOnDelete();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->boolean('is_missing')->default(false);
            $table->unsignedTinyInteger('missing_runs_count')->default(0);
            $table->timestamp('missing_since_at')->nullable()->default(null);
            $table->timestamps();

            // Indexes
            $table->unique(['source_id', 'source_external_id'], 'uq_occ_sources_ext');
            $table->index(['event_occurrence_id', 'is_missing'], 'idx_occ_sources_occurrence');
            $table->index(['source_id', 'is_missing', 'last_scrape_log_id'], 'idx_occ_sources_reconcile');
        });

        // MySQL 8.0 specific CHECK constraints
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE occurrence_sources
                    ADD CONSTRAINT check_occ_src_raw_categories_array
                        CHECK (raw_categories IS NULL OR JSON_TYPE(raw_categories) = 'ARRAY')
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
                DB::statement("ALTER TABLE occurrence_sources DROP CHECK check_occ_src_raw_categories_array");
            } catch (\Throwable $e) {}
        }

        Schema::dropIfExists('occurrence_sources');
    }
};
