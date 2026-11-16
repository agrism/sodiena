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
        Schema::table('sources', function (Blueprint $table) {
            if (!Schema::hasColumn('sources', 'duplicates_prevented_count')) {
                $table->unsignedInteger('duplicates_prevented_count')->default(0)->after('last_error');
            }
        });

        Schema::table('scrape_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('scrape_logs', 'items_deduplicated')) {
                $table->unsignedInteger('items_deduplicated')->default(0)->after('items_updated');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            if (Schema::hasColumn('sources', 'duplicates_prevented_count')) {
                $table->dropColumn('duplicates_prevented_count');
            }
        });

        Schema::table('scrape_logs', function (Blueprint $table) {
            if (Schema::hasColumn('scrape_logs', 'items_deduplicated')) {
                $table->dropColumn('items_deduplicated');
            }
        });
    }
};
