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

    public function test_inherit_missing_description_for_existing_translation_records(): void
    {
        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://www.bilesuparadize.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        $loc = Location::create([
            'name' => 'Rīgas Doms',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
        ]);

        // Event A (Afiro) with full LV, EN, RU translations
        $afiroEvent = Event::create([
            'source_id' => $source->id,
            'source_slug' => 'afiro-api',
            'location_id' => $loc->id,
            'title' => 'Concerto Piccolo',
            'slug' => 'concerto-piccolo-ofW3Z0',
            'description' => 'Piccolo CONCERTO PICCOLO Ērģeļmūzika ~ 20 min.',
            'short_description' => 'Piccolo CONCERTO PICCOLO Ērģeļmūzika ~ 20 min.',
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now(),
        ]);

        EventTranslation::create([
            'event_id' => $afiroEvent->id,
            'locale' => 'lv',
            'title' => 'Concerto Piccolo',
            'slug' => 'concerto-piccolo-lv-ofW3Z0',
            'description' => 'Piccolo CONCERTO PICCOLO Ērģeļmūzika ~ 20 min.',
        ]);

        EventTranslation::create([
            'event_id' => $afiroEvent->id,
            'locale' => 'en',
            'title' => 'Concerto Piccolo',
            'slug' => 'concerto-piccolo-en-ofW3Z0',
            'description' => 'Piccolo CONCERTO PICCOLO Organ music ~ 20 min.',
        ]);

        EventTranslation::create([
            'event_id' => $afiroEvent->id,
            'locale' => 'ru',
            'title' => 'Concerto Piccolo',
            'slug' => 'concerto-piccolo-ru-ofW3Z0',
            'description' => 'Piccolo CONCERTO PICCOLO Органный концерт ~ 20 мин.',
        ]);

        // Event B (Bilesu Paradize) with null description on main event and null description on LV translation record
        $bpEvent = Event::create([
            'source_id' => $source->id,
            'source_slug' => 'bilesu-paradize',
            'location_id' => $loc->id,
            'title' => 'CONCERTO PICCOLO',
            'slug' => 'concerto-piccolo-RV6KJZ',
            'description' => null,
            'short_description' => null,
            'start_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now(),
        ]);

        EventTranslation::create([
            'event_id' => $bpEvent->id,
            'locale' => 'lv',
            'title' => 'CONCERTO PICCOLO',
            'slug' => 'concerto-piccolo-RV6KJZ',
            'description' => null,
            'short_description' => null,
        ]);

        // Run sync command
        Artisan::call('events:sync-translations');

        $bpEvent->refresh();
        $lvTrans = $bpEvent->translations()->where('locale', 'lv')->first();
        $enTrans = $bpEvent->translations()->where('locale', 'en')->first();
        $ruTrans = $bpEvent->translations()->where('locale', 'ru')->first();

        $this->assertNotNull($lvTrans->description);
        $this->assertEquals('Piccolo CONCERTO PICCOLO Ērģeļmūzika ~ 20 min.', $lvTrans->description);
        $this->assertEquals('Piccolo CONCERTO PICCOLO Ērģeļmūzika ~ 20 min.', $bpEvent->description);

        $this->assertNotNull($enTrans);
        $this->assertEquals('Piccolo CONCERTO PICCOLO Organ music ~ 20 min.', $enTrans->description);

        $this->assertNotNull($ruTrans);
        $this->assertEquals('Piccolo CONCERTO PICCOLO Органный концерт ~ 20 мин.', $ruTrans->description);
    }

    public function test_prioritize_sibling_with_full_description_over_empty_sessions(): void
    {
        $service = app(EventIngestionService::class);

        $source = Source::create([
            'name' => 'Biļešu Paradīze',
            'slug' => 'bilesu-paradize',
            'url' => 'https://www.bilesuparadize.lv',
            'scraper_class' => \App\Services\Scrapers\Sources\BilesuParadizeScraper::class,
            'is_active' => true,
        ]);

        $loc = Location::create([
            'name' => 'Latvijas Leļļu teātris',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
        ]);

        // Session 1 (Afiro) - has rich LV, EN, RU
        $afiroSession = Event::create([
            'source_id' => $source->id,
            'source_slug' => 'afiro-api',
            'location_id' => $loc->id,
            'title' => 'Čuči,Spilventiņ!',
            'slug' => 'cucispilventin-9QmQdP',
            'description' => 'Pirmo reizi Latvijas Leļļu teātrī...',
            'start_at' => now()->addDays(5),
            'status' => 'published',
            'published_at' => now(),
        ]);
        EventTranslation::create([
            'event_id' => $afiroSession->id,
            'locale' => 'lv',
            'title' => 'Čuči,Spilventiņ!',
            'slug' => 'cucispilventin-lv-9QmQdP',
            'description' => 'Pirmo reizi Latvijas Leļļu teātrī...',
        ]);
        EventTranslation::create([
            'event_id' => $afiroSession->id,
            'locale' => 'en',
            'title' => 'Sleep, Little Pillow!',
            'slug' => 'sleep-little-pillow-en-9QmQdP',
            'description' => 'For the first time at the Latvian Puppet Theatre...',
        ]);

        // Session 2 (Bilesu Paradize) - earlier ID than session 3, but missing LV description
        $emptySession = Event::create([
            'source_id' => $source->id,
            'source_slug' => 'bilesu-paradize',
            'location_id' => $loc->id,
            'title' => 'Čuči,Spilventiņ!',
            'slug' => 'cucispilventin-zKReGB',
            'description' => null,
            'start_at' => now()->addDays(6),
            'status' => 'published',
            'published_at' => now(),
        ]);
        EventTranslation::create([
            'event_id' => $emptySession->id,
            'locale' => 'lv',
            'title' => 'Čuči,Spilventiņ!',
            'slug' => 'cucispilventin-lv-zKReGB',
            'description' => null,
        ]);
        EventTranslation::create([
            'event_id' => $emptySession->id,
            'locale' => 'en',
            'title' => 'Sleep, Little Pillow!',
            'slug' => 'sleep-little-pillow-en-zKReGB',
            'description' => 'For the first time at the Latvian Puppet Theatre...',
        ]);

        // Target Session 3 (Bilesu Paradize) - also missing LV description
        $targetSession = Event::create([
            'source_id' => $source->id,
            'source_slug' => 'bilesu-paradize',
            'location_id' => $loc->id,
            'title' => 'Čuči,Spilventiņ!',
            'slug' => 'cucispilventin-aS1TUI',
            'description' => null,
            'start_at' => now()->addDays(7),
            'status' => 'published',
            'published_at' => now(),
        ]);
        EventTranslation::create([
            'event_id' => $targetSession->id,
            'locale' => 'lv',
            'title' => 'Čuči,Spilventiņ!',
            'slug' => 'cucispilventin-lv-aS1TUI',
            'description' => null,
        ]);

        $sibling = $service->findSiblingWithTranslations($targetSession);
        $this->assertNotNull($sibling);
        $this->assertEquals($afiroSession->id, $sibling->id);

        $service->inheritSiblingTranslations($targetSession);
        $targetSession->refresh();

        $this->assertEquals('Pirmo reizi Latvijas Leļļu teātrī...', $targetSession->description);
        $this->assertEquals('Pirmo reizi Latvijas Leļļu teātrī...', $targetSession->translations()->where('locale', 'lv')->first()?->description);
    }
}
