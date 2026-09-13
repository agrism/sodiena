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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->default('calendar');
            $table->string('color')->default('emerald');
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('city')->index();
            $table->string('region')->index();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('place_type')->default('venue');
            $table->timestamps();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('url');
            $table->string('scraper_class');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_scraped_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            $table->longText('description')->nullable();
            $table->text('short_description')->nullable();
            $table->dateTime('start_at')->index();
            $table->dateTime('end_at')->nullable()->index();
            $table->boolean('all_day')->default(false);
            $table->boolean('is_free')->default(false)->index();
            $table->decimal('price_min', 8, 2)->nullable();
            $table->decimal('price_max', 8, 2)->nullable();
            $table->string('currency', 10)->default('EUR');
            $table->text('ticket_url')->nullable();
            $table->text('image_url')->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_external_id')->nullable()->index();
            $table->string('fingerprint', 64)->index();
            $table->string('entertainment_type')->nullable()->index();
            $table->string('status', 30)->default('published')->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->index(['status', 'start_at']);
        });

        Schema::create('category_event', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['event_id', 'category_id']);
        });

        Schema::create('scrape_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->string('status', 30)->default('running');
            $table->integer('items_found')->default(0);
            $table->integer('items_created')->default(0);
            $table->integer('items_updated')->default(0);
            $table->decimal('duration_seconds', 8, 2)->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrape_logs');
        Schema::dropIfExists('category_event');
        Schema::dropIfExists('events');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('categories');
    }
};
