<?php

namespace Database\Seeders;

use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndAdminSeeder::class,
            CategorySeeder::class,
            SourceSeeder::class,
        ]);

        // Ingest initial events from sources
        $ingestionService = app(EventIngestionService::class);
        foreach (Source::where('is_active', true)->get() as $source) {
            $ingestionService->ingest($source);
        }
    }
}
