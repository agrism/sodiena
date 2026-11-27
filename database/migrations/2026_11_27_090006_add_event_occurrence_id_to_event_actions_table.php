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
        Schema::table('event_actions', function (Blueprint $table) {
            $table->foreignId('event_occurrence_id')->nullable()->after('event_id')->constrained('event_occurrences')->nullOnDelete();
            $table->index(['event_occurrence_id', 'created_at'], 'idx_event_actions_occurrence_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_actions', function (Blueprint $table) {
            $table->dropForeign(['event_occurrence_id']);
            $table->dropIndex('idx_event_actions_occurrence_created');
            $table->dropColumn('event_occurrence_id');
        });
    }
};
