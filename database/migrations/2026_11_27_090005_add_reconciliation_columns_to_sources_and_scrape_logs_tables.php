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
        // 1. Add reconciliation columns to sources table
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('supports_reconciliation')->default(false)->after('is_active');
            $table->decimal('reconciliation_min_ratio', 4, 2)->default(0.60)->after('supports_reconciliation');
            $table->unsignedTinyInteger('reconciliation_grace_runs')->default(1)->after('reconciliation_min_ratio');
        });

        // 2. Add reconciliation columns to scrape_logs table
        Schema::table('scrape_logs', function (Blueprint $table) {
            $table->unsignedInteger('items_merged')->default(0)->after('items_deduplicated');
            $table->unsignedInteger('items_cancelled')->default(0)->after('items_merged');
            $table->string('reconciliation_status', 30)->nullable()->default(null)->after('items_cancelled');
            $table->json('summary_meta')->nullable()->default(null)->after('errors');
        });

        // MySQL 8.0 specific CHECK constraints
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE sources
                    ADD CONSTRAINT check_sources_reconciliation_min_ratio
                        CHECK (reconciliation_min_ratio >= 0.00 AND reconciliation_min_ratio <= 1.00),
                    ADD CONSTRAINT check_sources_reconciliation_grace_runs
                        CHECK (reconciliation_grace_runs >= 1)
            ");

            DB::statement("
                ALTER TABLE scrape_logs
                    ADD CONSTRAINT check_scrape_logs_reconciliation_status
                        CHECK (reconciliation_status IS NULL OR reconciliation_status IN ('completed', 'skipped_circuit_breaker', 'disabled', 'failed'))
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
                DB::statement("ALTER TABLE sources DROP CHECK check_sources_reconciliation_min_ratio");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE sources DROP CHECK check_sources_reconciliation_grace_runs");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE scrape_logs DROP CHECK check_scrape_logs_reconciliation_status");
            } catch (\Throwable $e) {}
        }

        Schema::table('scrape_logs', function (Blueprint $table) {
            $table->dropColumn([
                'items_merged',
                'items_cancelled',
                'reconciliation_status',
                'summary_meta',
            ]);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn([
                'supports_reconciliation',
                'reconciliation_min_ratio',
                'reconciliation_grace_runs',
            ]);
        });
    }
};
