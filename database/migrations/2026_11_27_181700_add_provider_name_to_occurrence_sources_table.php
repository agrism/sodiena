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
        if (Schema::hasTable('occurrence_sources')) {
            Schema::table('occurrence_sources', function (Blueprint $table) {
                if (!Schema::hasColumn('occurrence_sources', 'provider_name')) {
                    $table->string('provider_name', 120)->nullable()->after('ticket_url');
                    $table->index('provider_name');
                }
            });
        }

        if (Schema::hasTable('ticket_offers')) {
            Schema::table('ticket_offers', function (Blueprint $table) {
                if (!Schema::hasColumn('ticket_offers', 'provider_name')) {
                    $table->string('provider_name', 120)->nullable()->after('url');
                    $table->index('provider_name');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('occurrence_sources')) {
            Schema::table('occurrence_sources', function (Blueprint $table) {
                if (Schema::hasColumn('occurrence_sources', 'provider_name')) {
                    $table->dropIndex(['provider_name']);
                    $table->dropColumn('provider_name');
                }
            });
        }

        if (Schema::hasTable('ticket_offers')) {
            Schema::table('ticket_offers', function (Blueprint $table) {
                if (Schema::hasColumn('ticket_offers', 'provider_name')) {
                    $table->dropIndex(['provider_name']);
                    $table->dropColumn('provider_name');
                }
            });
        }
    }
};
