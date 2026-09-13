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
            $table->string('source_slug', 50)->nullable()->after('source_id')->index();
        });

        // Backfill existing records
        DB::statement("
            UPDATE events e 
            JOIN sources s ON e.source_id = s.id 
            SET e.source_slug = s.slug
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('source_slug');
        });
    }
};
