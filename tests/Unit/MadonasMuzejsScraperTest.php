<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use App\Services\Scrapers\EventIngestionService;
use App\Services\Scrapers\Sources\MadonasMuzejsScraper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MadonasMuzejsScraperTest extends TestCase
{
    use RefreshDatabase;

    public function test_madonas_muzejs_scraper_metadata(): void
    {
        $scraper = new MadonasMuzejsScraper();
        $this->assertEquals('madonas-muzejs', $scraper->getSlug());
        $this->assertStringContainsString('Madonas', $scraper->getName());
    }

    public function test_madonas_muzejs_event_ingestion_and_source_url_update(): void
    {
        $source = Source::create([
            'name' => 'Madonas novadpētniecības un mākslas muzejs',
            'slug' => 'madonas-muzejs',
            'url' => 'https://www.madonasmuzejs.lv/lv/izstāžu-un-pasākumu-kalendārs',
            'scraper_class' => MadonasMuzejsScraper::class,
            'is_active' => true,
        ]);

        $dto = new ScrapedEventDTO(
            title: 'Tev Nebūs Samierināt Monstrus',
            startAt: Carbon::parse('2026-09-12 13:00:00'),
            endAt: Carbon::parse('2026-11-22 18:00:00'),
            description: 'No 2026.gada 12.septembra Madonas novadpētniecības un mākslas muzejā būs skatāma latviešu trimdas mākslinieka Zigfrīda Sapieša izstāde.',
            shortDescription: 'Madonas muzejā būs skatāma Zigfrīda Sapieša izstāde.',
            venueName: 'Madonas novadpētniecības un mākslas muzejs',
            city: 'Madona',
            region: 'Vidzeme',
            address: 'Skolas iela 12, Madona',
            latitude: 56.8532,
            longitude: 26.2198,
            placeType: 'museum',
            categoryNames: ['Izstādes & Māksla', 'Kultūra & Tradīcijas'],
            entertainmentType: 'exhibition',
            isFree: true,
            imageUrl: 'https://www.madonasmuzejs.lv/f/images/original/4cd67756d130b5c8dd09521555de494b.jpg',
            sourceUrl: 'https://www.madonasmuzejs.lv/lv/aktualitātes/tev-nebūs-samierināt-monstrus',
            sourceExternalId: 'mm-tev-nebus-samierinat-monstrus',
            locale: 'lv',
        );

        $ingestionService = app(EventIngestionService::class);
        $result = $ingestionService->ingestDTO($dto, $source);

        $this->assertEquals('created', $result);

        $event = Event::where('source_external_id', 'mm-tev-nebus-samierinat-monstrus')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Tev Nebūs Samierināt Monstrus', $event->title);
        $this->assertEquals('https://www.madonasmuzejs.lv/lv/aktualitātes/tev-nebūs-samierināt-monstrus', $event->source_url);
        $this->assertEquals('madonasmuzejs.lv', $event->origin_host);
    }

    public function test_resolve_opening_hours_per_branch(): void
    {
        $scraper = new MadonasMuzejsScraper();

        $izstazuZales = $scraper->resolveOpeningHours('Madonas muzeja Izstāžu zāles');
        $this->assertArrayHasKey('Otrdiena', $izstazuZales);
        $this->assertArrayHasKey('Trešdiena', $izstazuZales);
        $this->assertEquals('10:00 – 18:00', $izstazuZales['Trešdiena']);
        $this->assertEquals('Slēgts', $izstazuZales['Pirmdiena']);

        $krajums = $scraper->resolveOpeningHours('Muzeja krājums');
        $this->assertArrayHasKey('Pirmdiena – Piektdiena', $krajums);
        $this->assertEquals('08:00 – 17:00', $krajums['Pirmdiena – Piektdiena']);

        $sarkani = $scraper->resolveOpeningHours('Etnogrāfijas krātuve Sarkaņos');
        $this->assertArrayHasKey('Otrdiena – Piektdiena', $sarkani);
        $this->assertStringContainsString('26579716', $sarkani['Sestdiena, Svētdiena']);

        $mednis = $scraper->resolveOpeningHours('Haralda Medņa Dziesmusvētku skola');
        $this->assertArrayHasKey('Trešdiena – Piektdiena', $mednis);
        $this->assertStringContainsString('28080668', $mednis['Otrdiena, Svētdiena']);
    }
}
