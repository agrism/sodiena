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
        Schema::table('events', function (Blueprint $table) {
            // 1. Soften existing column constraints (DATETIME NOT NULL -> DATETIME NULL)
            $table->dateTime('start_at')->nullable()->change();
            $table->string('fingerprint', 64)->nullable()->change();

            // 2. Add new columns
            $table->string('canonical_fingerprint', 64)->nullable()->after('fingerprint');
            $table->foreignId('primary_content_source_id')->nullable()->after('source_id')->constrained('sources')->nullOnDelete();
            $table->dateTime('next_start_at')->nullable()->after('end_at');
            $table->dateTime('last_end_at')->nullable()->after('next_start_at');
            $table->unsignedInteger('occurrences_count')->default(0)->after('last_end_at');
            $table->json('raw_categories')->nullable()->default(null)->after('entertainment_type');
            $table->string('content_hash', 64)->nullable()->after('raw_categories');
            $table->foreignId('merged_into_id')->nullable()->after('status')->constrained('events')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_id');

            // 3. Add new indexes
            $table->index(['location_id', 'canonical_fingerprint'], 'idx_events_location_canonical_fp');
            $table->index('merged_into_id', 'idx_events_merged_into_id');
            $table->index(['status', 'next_start_at'], 'idx_events_status_next_start');
            $table->index(['updated_at', 'id'], 'idx_events_sync_cursor');
            $table->index('primary_content_source_id', 'idx_events_primary_content_source');
        });

        // MySQL 8.0 specific CHECK constraints
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE events
                    ADD CONSTRAINT check_events_status
                        CHECK (status IN ('published', 'draft', 'archived', 'cancelled', 'merged', 'deleted')),
                    ADD CONSTRAINT check_events_raw_categories_array
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
                DB::statement("ALTER TABLE events DROP CHECK check_events_status");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE events DROP CHECK check_events_raw_categories_array");
            } catch (\Throwable $e) {}
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['primary_content_source_id']);
            $table->dropForeign(['merged_into_id']);

            $table->dropIndex('idx_events_location_canonical_fp');
            $table->dropIndex('idx_events_merged_into_id');
            $table->dropIndex('idx_events_status_next_start');
            $table->dropIndex('idx_events_sync_cursor');
            $table->dropIndex('idx_events_primary_content_source');

            $table->dropColumn([
                'canonical_fingerprint',
                'primary_content_source_id',
                'next_start_at',
                'last_end_at',
                'occurrences_count',
                'raw_categories',
                'content_hash',
                'merged_into_id',
                'merged_at',
            ]);

            // Revert start_at and fingerprint back to NOT NULL if no null records exist
            if (!DB::table('events')->whereNull('start_at')->exists()) {
                $table->dateTime('start_at')->nullable(false)->change();
            }
            if (!DB::table('events')->whereNull('fingerprint')->exists()) {
                $table->string('fingerprint', 64)->nullable(false)->change();
            }
        });
    }
};
