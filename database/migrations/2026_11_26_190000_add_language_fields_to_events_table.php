<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        Schema::table('events', function (Blueprint $table) use ($isMysql) {
            $table->string('language_mode', 20)->default('unknown')->after('entertainment_type');

            if ($isMysql) {
                $table->json('spoken_languages')->default(new Expression('(JSON_ARRAY())'))->after('language_mode');
                $table->json('subtitle_languages')->default(new Expression('(JSON_ARRAY())'))->after('spoken_languages');
            } else {
                $table->json('spoken_languages')->default('[]')->after('language_mode');
                $table->json('subtitle_languages')->default('[]')->after('spoken_languages');
            }

            $table->string('language_source', 20)->default('unknown')->after('subtitle_languages');

            // B-Tree indexes
            $table->index('language_mode', 'idx_events_language_mode');
            $table->index('language_source', 'idx_events_language_source');
            $table->index(['status', 'language_mode'], 'idx_events_status_language_mode');
        });

        // MySQL 8.0 specific CHECK constraints and Multi-Valued JSON indexes
        if ($isMysql) {
            DB::statement("
                ALTER TABLE events
                    ADD CONSTRAINT check_events_language_mode
                        CHECK (language_mode IN ('spoken', 'language_free', 'unknown')),
                    ADD CONSTRAINT check_events_language_source
                        CHECK (language_source IN ('manual', 'explicit', 'inferred', 'unknown')),
                    ADD CONSTRAINT check_events_spoken_languages_array
                        CHECK (JSON_TYPE(spoken_languages) = 'ARRAY'),
                    ADD CONSTRAINT check_events_subtitle_languages_array
                        CHECK (JSON_TYPE(subtitle_languages) = 'ARRAY')
            ");

            DB::statement("
                CREATE INDEX idx_events_spoken_languages_mv
                    ON events ((CAST(spoken_languages AS CHAR(10) ARRAY)))
            ");

            DB::statement("
                CREATE INDEX idx_events_subtitle_languages_mv
                    ON events ((CAST(subtitle_languages AS CHAR(10) ARRAY)))
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            // Safely drop CHECK constraints (using DROP CHECK for MySQL 8.0)
            try {
                DB::statement("ALTER TABLE events DROP CHECK check_events_language_mode");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE events DROP CHECK check_events_language_source");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE events DROP CHECK check_events_spoken_languages_array");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE events DROP CHECK check_events_subtitle_languages_array");
            } catch (\Throwable $e) {}

            // Safely drop multi-valued indexes
            try {
                DB::statement("ALTER TABLE events DROP INDEX idx_events_spoken_languages_mv");
            } catch (\Throwable $e) {}

            try {
                DB::statement("ALTER TABLE events DROP INDEX idx_events_subtitle_languages_mv");
            } catch (\Throwable $e) {}
        }

        Schema::table('events', function (Blueprint $table) use ($isMysql) {
            $table->dropIndex('idx_events_language_mode');
            $table->dropIndex('idx_events_language_source');
            $table->dropIndex('idx_events_status_language_mode');

            $table->dropColumn([
                'language_mode',
                'spoken_languages',
                'subtitle_languages',
                'language_source',
            ]);
        });
    }
};
