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
        Schema::table('scrape_logs', function (Blueprint $table) {
            $table->index('started_at');
            $table->index('status');
            $table->index(['source_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scrape_logs', function (Blueprint $table) {
            $table->dropIndex(['started_at']);
            $table->dropIndex(['status']);
            $table->dropIndex(['source_id', 'started_at']);
        });
    }
};

