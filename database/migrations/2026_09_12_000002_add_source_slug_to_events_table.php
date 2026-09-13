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
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                UPDATE events e 
                JOIN sources s ON e.source_id = s.id 
                SET e.source_slug = s.slug
            ");
        } else {
            DB::statement("
                UPDATE events 
                SET source_slug = (SELECT slug FROM sources WHERE sources.id = events.source_id)
            ");
        }
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
