<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sources')
            ->where('slug', 'handball-lv')
            ->update([
                'url' => 'https://handball.lv/calendar',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('sources')
            ->where('slug', 'handball-lv')
            ->update([
                'url' => 'https://handball.lv/league/1278/calendar',
                'updated_at' => now(),
            ]);
    }
};

