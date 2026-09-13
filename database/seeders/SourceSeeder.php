<?php

namespace Database\Seeders;

use App\Models\Source;
use App\Services\Scrapers\Sources\AfiroApiScraper;
use App\Services\Scrapers\Sources\BilesuParadizeScraper;
use App\Services\Scrapers\Sources\KulturasDatiScraper;
use App\Services\Scrapers\Sources\LiveRigaScraper;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            [
                'name' => 'Afiro Pasākumu API (Latvija & Rīga)',
                'slug' => 'afiro-api',
                'url' => 'https://api.afiro.lv/events',
                'scraper_class' => AfiroApiScraper::class,
                'is_active' => true,
            ],
            [
                'name' => 'Kultūras Dati (Valsts Notikumu Reģistrs)',
                'slug' => 'kulturas-dati',
                'url' => 'https://www.kulturadati.lv/notikumi',
                'scraper_class' => KulturasDatiScraper::class,
                'is_active' => true,
            ],
            [
                'name' => 'Biļešu Paradīze',
                'slug' => 'bilesu-paradize',
                'url' => 'https://www.bilesuparadize.lv/lv/events',
                'scraper_class' => BilesuParadizeScraper::class,
                'is_active' => true,
            ],
            [
                'name' => 'Live Riga & Pasākumi',
                'slug' => 'live-riga',
                'url' => 'https://www.liveriga.com/lv/apmekle/pasakumi',
                'scraper_class' => LiveRigaScraper::class,
                'is_active' => true,
            ],
        ];

        foreach ($sources as $s) {
            Source::updateOrCreate(
                ['slug' => $s['slug']],
                $s
            );
        }
    }
}
