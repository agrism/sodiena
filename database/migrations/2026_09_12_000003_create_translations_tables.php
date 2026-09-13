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
        Schema::create('event_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('locale', 10)->index();
            $table->string('title');
            $table->string('slug')->index();
            $table->longText('description')->nullable();
            $table->text('short_description')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'locale']);
        });

        Schema::create('category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('locale', 10)->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'locale']);
        });

        // Backfill existing event data as 'lv' translation
        DB::statement("
            INSERT INTO event_translations (event_id, locale, title, slug, description, short_description, created_at, updated_at)
            SELECT id, 'lv', title, slug, description, short_description, created_at, updated_at
            FROM events
        ");

        // Backfill existing category data as 'lv' translation
        DB::statement("
            INSERT INTO category_translations (category_id, locale, name, description, created_at, updated_at)
            SELECT id, 'lv', name, description, created_at, updated_at
            FROM categories
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_translations');
        Schema::dropIfExists('event_translations');
    }
};
