<?php

namespace Database\Seeders;

use App\Models\Source;
use App\Services\Scrapers\Sources\AfiroApiScraper;
use App\Services\Scrapers\Sources\BilesuParadizeScraper;
use App\Services\Scrapers\Sources\DzejasDienasScraper;
use App\Services\Scrapers\Sources\JurmalasMuzejsScraper;
use App\Services\Scrapers\Sources\KulturasDatiScraper;
use App\Services\Scrapers\Sources\LatgalesGorsScraper;
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
            [
                'name' => 'Jūrmalas muzejs',
                'slug' => 'jurmalas-muzejs',
                'url' => 'https://www.jurmalasmuzejs.lv/lv/notikumu-kalendars',
                'scraper_class' => JurmalasMuzejsScraper::class,
                'is_active' => true,
            ],
            [
                'name' => 'Latgales vēstniecība GORS',
                'slug' => 'latgales-gors',
                'url' => 'https://www.latgalesgors.lv/lv/notikumi',
                'scraper_class' => LatgalesGorsScraper::class,
                'is_active' => true,
            ],
            [
                'name' => 'Dzejas dienas',
                'slug' => 'dzejas-dienas',
                'url' => 'https://www.dzejasdienas.com/programma/',
                'scraper_class' => DzejasDienasScraper::class,
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
