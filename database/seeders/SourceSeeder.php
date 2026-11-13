<?php

namespace Database\Seeders;

use App\Enums\SourceType;
use App\Models\Source;
use App\Services\Scrapers\Sources\AfiroApiScraper;
use App\Services\Scrapers\Sources\AizkrauklesNovadsScraper;
use App\Services\Scrapers\Sources\AluksneTourismScraper;
use App\Services\Scrapers\Sources\AulaLvScraper;
use App\Services\Scrapers\Sources\BasketLvScraper;
use App\Services\Scrapers\Sources\BezRindasScraper;
use App\Services\Scrapers\Sources\BilesuParadizeScraper;
use App\Services\Scrapers\Sources\BilesuServissScraper;
use App\Services\Scrapers\Sources\DaugavpilsScraper;
use App\Services\Scrapers\Sources\DzejasDienasScraper;
use App\Services\Scrapers\Sources\EkaseLvScraper;
use App\Services\Scrapers\Sources\EstLatblScraper;
use App\Services\Scrapers\Sources\FientaScraper;
use App\Services\Scrapers\Sources\FloorballLvScraper;
use App\Services\Scrapers\Sources\HandballLvScraper;
use App\Services\Scrapers\Sources\JurmalasMuzejsScraper;
use App\Services\Scrapers\Sources\KulturasDatiScraper;
use App\Services\Scrapers\Sources\LatgalesGorsScraper;
use App\Services\Scrapers\Sources\LffTelpuFutbolsScraper;
use App\Services\Scrapers\Sources\LffVirsligaScraper;
use App\Services\Scrapers\Sources\LhfHokejsScraper;
use App\Services\Scrapers\Sources\LiveRigaScraper;
use App\Services\Scrapers\Sources\MadonasMuzejsScraper;
use App\Services\Scrapers\Sources\OgresMuzejsScraper;
use App\Services\Scrapers\Sources\OgresNovadsScraper;
use App\Services\Scrapers\Sources\OkulturaScraper;
use App\Services\Scrapers\Sources\PassportixScraper;
use App\Services\Scrapers\Sources\SiguldaTourismScraper;
use App\Services\Scrapers\Sources\TicketBestLvScraper;
use App\Services\Scrapers\Sources\VisitOgreScraper;
use App\Services\Scrapers\Sources\VolejbolsLvScraper;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            [
                'name' => 'BezRindas.lv',
                'slug' => 'bezrindas',
                'url' => 'https://www.bezrindas.lv/lv/visi-pasakumi',
                'scraper_class' => BezRindasScraper::class,
                'type' => SourceType::TICKETING->value,
                'is_active' => true,
            ],
            [
                'name' => 'Afiro Pasākumu API',
                'slug' => 'afiro-api',
                'url' => 'https://api.afiro.lv/events',
                'scraper_class' => AfiroApiScraper::class,
                'type' => SourceType::AGGREGATOR->value,
                'is_active' => true,
            ],
            [
                'name' => 'Kultūras Dati',
                'slug' => 'kulturas-dati',
                'url' => 'https://www.kulturadati.lv/notikumi',
                'scraper_class' => KulturasDatiScraper::class,
                'type' => SourceType::AGGREGATOR->value,
                'is_active' => true,
            ],
            [
                'name' => 'Biļešu Paradīze',
                'slug' => 'bilesu-paradize',
                'url' => 'https://www.bilesuparadize.lv/lv/events',
                'scraper_class' => BilesuParadizeScraper::class,
                'type' => SourceType::TICKETING->value,
                'is_active' => true,
            ],
            [
                'name' => 'Biļešu Serviss',
                'slug' => 'bilesu-serviss',
                'url' => 'https://www.bilesuserviss.lv/biletes/visi',
                'scraper_class' => BilesuServissScraper::class,
                'type' => SourceType::TICKETING->value,
                'is_active' => true,
            ],
            [
                'name' => 'Live Riga',
                'slug' => 'live-riga',
                'url' => 'https://www.liveriga.com/lv/apmekle/pasakumi',
                'scraper_class' => LiveRigaScraper::class,
                'type' => SourceType::MUNICIPALITY->value,
                'is_active' => true,
            ],
            [
                'name' => 'Jūrmalas muzejs',
                'slug' => 'jurmalas-muzejs',
                'url' => 'https://www.jurmalasmuzejs.lv/lv/notikumu-kalendars',
                'scraper_class' => JurmalasMuzejsScraper::class,
                'type' => SourceType::VENUE->value,
                'is_active' => true,
            ],
            [
                'name' => 'Latgales vēstniecība GORS',
                'slug' => 'latgales-gors',
                'url' => 'https://www.latgalesgors.lv/lv/notikumi',
                'scraper_class' => LatgalesGorsScraper::class,
                'type' => SourceType::VENUE->value,
                'is_active' => true,
            ],
            [
                'name' => 'Dzejas dienas',
                'slug' => 'dzejas-dienas',
                'url' => 'https://www.dzejasdienas.com/programma/',
                'scraper_class' => DzejasDienasScraper::class,
                'type' => SourceType::THEMATIC->value,
                'is_active' => true,
            ],
            [
                'name' => 'Madonas novadpētniecības un mākslas muzejs',
                'slug' => 'madonas-muzejs',
                'url' => 'https://www.madonasmuzejs.lv/lv/izstāžu-un-pasākumu-kalendārs',
                'scraper_class' => MadonasMuzejsScraper::class,
                'type' => SourceType::VENUE->value,
                'is_active' => true,
            ],
            [
                'name' => 'Aizkraukles novada pašvaldība',
                'slug' => 'aizkraukles-novads',
                'url' => 'https://www.aizkraukle.lv/lv/notikumu-kalendars',
                'scraper_class' => AizkrauklesNovadsScraper::class,
                'type' => SourceType::MUNICIPALITY->value,
                'is_active' => true,
            ],
            [
                'name' => 'Daugavpils valstspilsēta',
                'slug' => 'daugavpils-dome',
                'url' => 'https://www.daugavpils.lv/afisa/',
                'scraper_class' => DaugavpilsScraper::class,
                'type' => SourceType::MUNICIPALITY->value,
                'is_active' => true,
            ],
            [
                'name' => 'Passportix',
                'slug' => 'passportix',
                'url' => 'https://www.passportix.eu/pasakumi',
                'scraper_class' => PassportixScraper::class,
                'type' => SourceType::TICKETING->value,
                'is_active' => true,
            ],
            [
                'name' => 'TicketBest',
                'slug' => 'ticketbest',
                'url' => 'https://ticketbest.lv/',
                'scraper_class' => TicketBestLvScraper::class,
                'type' => SourceType::TICKETING->value,
                'is_active' => true,
            ],
            [
                'name' => 'eKase',
                'slug' => 'ekase',
                'url' => 'https://ekase.lv/lv/events/program',
                'scraper_class' => EkaseLvScraper::class,
                'type' => SourceType::TICKETING->value,
                'is_active' => true,
            ],
            [
                'name' => 'Fienta',
                'slug' => 'fienta',
                'url' => 'https://fienta.com/lv',
                'scraper_class' => FientaScraper::class,
                'type' => SourceType::TICKETING->value,
                'is_active' => true,
            ],
            [
                'name' => 'Aula',
                'slug' => 'aula',
                'url' => 'https://www.aula.lv/visi',
                'scraper_class' => AulaLvScraper::class,
                'type' => SourceType::TICKETING->value,
                'is_active' => true,
            ],
            [
                'name' => 'Siguldas novada tūrisms',
                'slug' => 'sigulda-tourism',
                'url' => 'https://tourism.sigulda.lv/events/',
                'scraper_class' => SiguldaTourismScraper::class,
                'type' => SourceType::MUNICIPALITY->value,
                'is_active' => true,
            ],
            [
                'name' => 'Visit Alūksne',
                'slug' => 'aluksne-tourism',
                'url' => 'https://visitaluksne.lv/pasakumi-un-turisma-aktualitates/',
                'scraper_class' => AluksneTourismScraper::class,
                'type' => SourceType::MUNICIPALITY->value,
                'is_active' => true,
            ],
            [
                'name' => 'Optibet Latvijas - Igaunijas Basketbola Līga',
                'slug' => 'estlatbl',
                'url' => 'https://www.estlatbl.com/lv/game-center',
                'scraper_class' => EstLatblScraper::class,
                'type' => SourceType::SPORTS->value,
                'is_active' => true,
            ],
            [
                'name' => 'Tonybet Virslīga',
                'slug' => 'lff-virsliga',
                'url' => 'https://lff.lv/sacensibas/viriesi/virsliga/?tab=content_1_1',
                'scraper_class' => LffVirsligaScraper::class,
                'type' => SourceType::SPORTS->value,
                'is_active' => true,
            ],
            [
                'name' => 'Optibet Telpu futbola Virslīga',
                'slug' => 'lff-telpu-futbols',
                'url' => 'https://lff.lv/sacensibas/telpu-futbols/virsliga/?tab=content_1_2',
                'scraper_class' => LffTelpuFutbolsScraper::class,
                'type' => SourceType::SPORTS->value,
                'is_active' => true,
            ],
            [
                'name' => 'Latvijas Handbola Federācija',
                'slug' => 'handball-lv',
                'url' => 'https://handball.lv/league/1278/calendar',
                'scraper_class' => HandballLvScraper::class,
                'type' => SourceType::SPORTS->value,
                'is_active' => true,
            ],
            [
                'name' => 'Latvijas Florbola Savienība',
                'slug' => 'floorball-lv',
                'url' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
                'scraper_class' => FloorballLvScraper::class,
                'type' => SourceType::SPORTS->value,
                'is_active' => true,
            ],
            [
                'name' => 'Latvijas Hokeja Federācija',
                'slug' => 'lhf-hokejs',
                'url' => 'https://www.lhf.lv/lv/turniri/optibet-hokeja-liga/speles',
                'scraper_class' => LhfHokejsScraper::class,
                'type' => SourceType::SPORTS->value,
                'is_active' => true,
            ],
            [
                'name' => 'Latvijas Volejbola Federācija',
                'slug' => 'volejbols-lv',
                'url' => 'https://volejbols.lv/kalendars/',
                'scraper_class' => VolejbolsLvScraper::class,
                'type' => SourceType::SPORTS->value,
                'is_active' => true,
            ],
            [
                'name' => 'Latvijas Basketbola Savienība',
                'slug' => 'basket-lv',
                'url' => 'https://nbl.basket.lv/kalendars/',
                'scraper_class' => BasketLvScraper::class,
                'type' => SourceType::SPORTS->value,
                'is_active' => true,
            ],
            [
                'name' => 'Visit Ogre',
                'slug' => 'visitogre',
                'url' => 'https://www.visitogre.lv/lv/pasakumi',
                'scraper_class' => VisitOgreScraper::class,
                'type' => SourceType::MUNICIPALITY->value,
                'is_active' => true,
            ],
            [
                'name' => 'Ogres novada pašvaldība',
                'slug' => 'ogres-novads',
                'url' => 'https://www.ogresnovads.lv/lv/notikumu-kalendars',
                'scraper_class' => OgresNovadsScraper::class,
                'type' => SourceType::MUNICIPALITY->value,
                'is_active' => true,
            ],
            [
                'name' => 'Ogres novada Kultūras centrs',
                'slug' => 'okultura',
                'url' => 'https://www.okultura.lv/lv/pasakumi',
                'scraper_class' => OkulturaScraper::class,
                'type' => SourceType::VENUE->value,
                'is_active' => true,
            ],
            [
                'name' => 'Ogres Vēstures un mākslas muzejs',
                'slug' => 'ogres-muzejs',
                'url' => 'https://ogresmuzejs.lv/piedavajums',
                'scraper_class' => OgresMuzejsScraper::class,
                'type' => SourceType::VENUE->value,
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
