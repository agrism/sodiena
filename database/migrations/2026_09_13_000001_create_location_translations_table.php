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
        Schema::create('location_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('locale', 10)->index();
            $table->string('name');
            $table->string('city')->nullable()->index();
            $table->string('region')->nullable()->index();
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'locale']);
        });

        // Backfill existing location data as 'lv' translation if locations table has data
        if (Schema::hasTable('locations')) {
            DB::statement("
                INSERT INTO location_translations (location_id, locale, name, city, region, address, created_at, updated_at)
                SELECT id, 'lv', name, city, region, address, created_at, updated_at
                FROM locations
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_translations');
    }
};
