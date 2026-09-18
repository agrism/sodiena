<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EventTranslationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_detect_text_language(): void
    {
        $service = app(EventIngestionService::class);

        $this->assertEquals('ru', $service->detectTextLanguage('Концертное представление актёров Лиепайского театра «Какое счастливое совпадение»'));
        $this->assertEquals('ru', $service->detectTextLanguage('Счастливые. Во время школьных каникул девятилетняя Элизабета едет к семье'));
        $this->assertEquals('en', $service->detectTextLanguage('During the school holidays, nine-year-old Elizabeth goes to stay with her family in Venezuela.'));
        $this->assertEquals('lv', $service->detectTextLanguage('Liepājas teātra aktieru koncertuzvedums "Kāda laimīga sagadīšanās"'));
        $this->assertEquals('lv', $service->detectTextLanguage('Skolas brīvlaikā deviņgadīgā Elizabete dodas pie ģimenes uz lietusmežiem.'));
    }

    public function test_find_sibling_and_inherit_translations_across_locations_and_languages(): void
    {
        $service = app(EventIngestionService::class);

        $source = Source::create([
            'name' => 'Afiro API',
            'slug' => 'afiro-api',
            'url' => 'https://api.afiro.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\AfiroApiScraper::class,
            'is_active' => true,
        ]);

        $loc1 = Location::create([
            'name' => 'Apollo Kino Plaza',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
        ]);

        $loc2 = Location::create([
            'name' => 'Kino Auseklis',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
        ]);

        // Event 1 has full translations from Afiro API (LV, EN, RU)
        $masterEvent = Event::create([
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'location_id' => $loc1->id,
            'title' => 'Laimīgie',
            'slug' => 'laimigie-master-123456',
            'description' => 'Skolas brīvlaikā deviņgadīgā Elizabete dodas pie ģimenes.',
            'short_description' => 'Skolas brīvlaikā deviņgadīgā Elizabete dodas pie ģimenes.',
            'start_at' => now()->addDays(2),
            'ticket_url' => 'https://www.apollokino.lv/event/304176/laimigie',
            'status' => 'published',
            'published_at' => now(),
        ]);

        EventTranslation::create([
            'event_id' => $masterEvent->id,
            'locale' => 'lv',
            'title' => 'Laimīgie',
            'slug' => 'laimigie-lv-123456',
            'description' => 'Skolas brīvlaikā deviņgadīgā Elizabete dodas pie ģimenes.',
        ]);

        EventTranslation::create([
            'event_id' => $masterEvent->id,
            'locale' => 'en',
            'title' => 'The Lucky Ones',
            'slug' => 'the-lucky-ones-en-123456',
            'description' => 'During school holidays, nine-year-old Elizabeth visits her family.',
        ]);

        EventTranslation::create([
            'event_id' => $masterEvent->id,
            'locale' => 'ru',
            'title' => 'Счастливые',
            'slug' => 'scastlivye-ru-123456',
            'description' => 'Во время школьных каникул девятилетняя Элизабета едет к семье.',
        ]);

        // Event 2 is in Talsi with only LV translation from Biļešu Serviss
        $localEvent = Event::create([
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'location_id' => $loc2->id,
            'title' => 'Laimīgie',
            'slug' => 'laimigie-talsi-654321',
            'description' => 'Skolas brīvlaikā deviņgadīgā Elizabete dodas pie ģimenes.',
            'short_description' => 'Skolas brīvlaikā deviņgadīgā Elizabete dodas pie ģimenes.',
            'start_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now(),
        ]);

        EventTranslation::create([
            'event_id' => $localEvent->id,
            'locale' => 'lv',
            'title' => 'Laimīgie',
            'slug' => 'laimigie-lv-654321',
            'description' => 'Skolas brīvlaikā deviņgadīgā Elizabete dodas pie ģimenes.',
        ]);

        // Event 3 is a Russian imported event with only RU title/slug
        $russianEvent = Event::create([
            'source_id' => $source->id,
            'source_slug' => $source->slug,
            'location_id' => $loc2->id,
            'title' => 'Счастливые',
            'slug' => 'scastlivye-2PK9I7',
            'description' => 'Во время школьных каникул девятилетняя Элизабета едет к семье.',
            'short_description' => 'Во время школьных каникул девятилетняя Элизабета едет к семье.',
            'start_at' => now()->addDays(4),
            'ticket_url' => 'https://www.apollokino.lv/event/304176/laimigie',
            'status' => 'published',
            'published_at' => now(),
        ]);

        EventTranslation::create([
            'event_id' => $russianEvent->id,
            'locale' => 'lv', // initially saved as lv erroneously by single-language scraper
            'title' => 'Счастливые',
            'slug' => 'scastlivye-2PK9I7',
            'description' => 'Во время школьных каникул девятилетняя Элизабета едет к семье.',
        ]);

        // Run sync command
        Artisan::call('events:sync-translations');

        // Verify localEvent inherited EN and RU
        $this->assertDatabaseHas('event_translations', [
            'event_id' => $localEvent->id,
            'locale' => 'en',
            'title' => 'The Lucky Ones',
        ]);
        $this->assertDatabaseHas('event_translations', [
            'event_id' => $localEvent->id,
            'locale' => 'ru',
            'title' => 'Счастливые',
        ]);

        // Verify russianEvent had its RU text preserved into 'ru' locale and replaced in 'lv' with real Latvian text
        $this->assertDatabaseHas('event_translations', [
            'event_id' => $russianEvent->id,
            'locale' => 'ru',
            'title' => 'Счастливые',
        ]);
        $this->assertDatabaseHas('event_translations', [
            'event_id' => $russianEvent->id,
            'locale' => 'lv',
            'title' => 'Laimīgie',
        ]);
        $this->assertDatabaseHas('event_translations', [
            'event_id' => $russianEvent->id,
            'locale' => 'en',
            'title' => 'The Lucky Ones',
        ]);

        $russianEvent->refresh();
        $this->assertEquals('Laimīgie', $russianEvent->title);
    }
}
