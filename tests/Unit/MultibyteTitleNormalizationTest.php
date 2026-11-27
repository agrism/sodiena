<?php

namespace Tests\Unit;

use App\Console\Commands\MigrateEventsToV2Command;
use App\Models\Event;
use App\Models\EventTranslation;
use App\Services\IngestionV2\CanonicalProjectionService;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class MultibyteTitleNormalizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test CanonicalProjectionService cleanCanonicalTitle with titles ending in Latvian characters.
     */
    public function test_clean_canonical_title_preserves_latvian_endings(): void
    {
        $service = new CanonicalProjectionService();

        $cases = [
            // Ending with 'ē' (\xC4\x93) - the exact failure case "Krancītis suņu izstādē"
            [
                'input' => 'Krancītis suņu izstādē',
                'expected' => 'Krancītis suņu izstādē',
                'last_char' => 'ē',
            ],
            [
                'input' => 'Krancītis suņu izstādē – 2D',
                'expected' => 'Krancītis suņu izstādē',
                'last_char' => 'ē',
            ],
            [
                'input' => 'Krancītis suņu izstādē — (latviešu valodā)',
                'expected' => 'Krancītis suņu izstādē',
                'last_char' => 'ē',
            ],
            [
                'input' => '„Krancītis suņu izstādē”',
                'expected' => 'Krancītis suņu izstādē',
                'last_char' => 'ē',
            ],
            [
                'input' => 'Krancītis suņu izstādē •',
                'expected' => 'Krancītis suņu izstādē',
                'last_char' => 'ē',
            ],
            // Ending with 'ā' (\xC4\x81)
            [
                'input' => 'Koncerts Rīgā – 3D',
                'expected' => 'Koncerts Rīgā',
                'last_char' => 'ā',
            ],
            [
                'input' => 'Mūzika Daugavpilī un Jelgavā (lv)',
                'expected' => 'Mūzika Daugavpilī un Jelgavā',
                'last_char' => 'ā',
            ],
            // Ending with 'ī' (\xC4\xAB)
            [
                'input' => 'Vakars pilī — (ar subtitriem)',
                'expected' => 'Vakars pilī',
                'last_char' => 'ī',
            ],
            [
                'input' => 'Tikšanās Rundāles pilī – LUXE',
                'expected' => 'Tikšanās Rundāles pilī',
                'last_char' => 'ī',
            ],
            // Ending with 'ū' (\xC5\xAB)
            [
                'input' => 'Pastaiga lietū – IMAX',
                'expected' => 'Pastaiga lietū',
                'last_char' => 'ū',
            ],
            [
                'input' => 'Kino seanss krēslā un lietū (angļu valodā)',
                'expected' => 'Kino seanss krēslā un lietū',
                'last_char' => 'ū',
            ],
            // Ending with 'š' (\xC5\xA1)
            [
                'input' => 'Ziemassvētku tirdziņš – Dolby Atmos',
                'expected' => 'Ziemassvētku tirdziņš',
                'last_char' => 'š',
            ],
            [
                'input' => 'Rudens gadatirgus un tirdziņš',
                'expected' => 'Rudens gadatirgus un tirdziņš',
                'last_char' => 'š',
            ],
            // Ending with 'ž' (\xC5\xBE)
            [
                'input' => 'Mākslas un dizaina birž – 2D',
                'expected' => 'Mākslas un dizaina birž',
                'last_char' => 'ž',
            ],
            [
                'input' => '«Goda sardze un parādes birž»',
                'expected' => 'Goda sardze un parādes birž',
                'last_char' => 'ž',
            ],
        ];

        foreach ($cases as $case) {
            $cleaned = $service->cleanCanonicalTitle($case['input'], true);

            $this->assertTrue(
                mb_check_encoding($cleaned, 'UTF-8'),
                "Output for '{$case['input']}' is not valid UTF-8."
            );
            $this->assertSame(
                $case['expected'],
                $cleaned,
                "Cleaned title mismatch for '{$case['input']}'."
            );
            $this->assertSame(
                $case['last_char'],
                mb_substr($cleaned, -1, 1, 'UTF-8'),
                "Last character mismatch for '{$case['input']}'."
            );
            // Verify there is no trailing truncated byte 0xC4
            $this->assertFalse(
                str_ends_with($cleaned, "\xC4"),
                "Output for '{$case['input']}' has trailing lone byte 0xC4!"
            );
        }
    }

    /**
     * Test MigrateEventsToV2Command cleanTitleForCinema method.
     */
    public function test_migrate_command_clean_title_for_cinema(): void
    {
        $command = new MigrateEventsToV2Command(new CanonicalProjectionService());
        $refMethod = new ReflectionMethod(MigrateEventsToV2Command::class, 'cleanTitleForCinema');
        $refMethod->setAccessible(true);

        $titles = [
            'Krancītis suņu izstādē – 2D' => 'Krancītis suņu izstādē',
            'Koncerts Rīgā — ' => 'Koncerts Rīgā',
            'Vakars pilī •' => 'Vakars pilī',
            'Pastaiga lietū (lv)' => 'Pastaiga lietū',
            'Ziemassvētku tirdziņš – ' => 'Ziemassvētku tirdziņš',
            'Mākslas birž (2D)' => 'Mākslas birž',
        ];

        foreach ($titles as $input => $expected) {
            $result = $refMethod->invoke($command, $input, true);

            $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
            $this->assertSame($expected, $result);
            $this->assertFalse(str_ends_with($result, "\xC4"));
        }
    }

    /**
     * Test ScrapedEventDTO getCanonicalEventTitle preserves Latvian characters without byte corruption.
     */
    public function test_scraped_event_dto_canonical_title_preserves_latvian_chars(): void
    {
        $dto = new ScrapedEventDTO(
            title: 'Krancītis suņu izstādē – 2D',
            startAt: Carbon::now(),
            sourceExternalId: 'test-123'
        );

        $canonicalTitle = $dto->getCanonicalEventTitle(true);

        $this->assertTrue(mb_check_encoding($canonicalTitle, 'UTF-8'));
        $this->assertSame('Krancītis suņu izstādē', $canonicalTitle);
        $this->assertSame('ē', mb_substr($canonicalTitle, -1, 1, 'UTF-8'));
        $this->assertFalse(str_ends_with($canonicalTitle, "\xC4"));
    }

    /**
     * Test Event model title setter safety and length truncation.
     */
    public function test_event_model_title_safeguards(): void
    {
        $event = new Event();

        // 1. Exact Latvian ending preservation
        $event->title = '  Krancītis suņu izstādē  ';
        $this->assertSame('Krancītis suņu izstādē', $event->title);
        $this->assertTrue(mb_check_encoding($event->title, 'UTF-8'));
        $this->assertFalse(str_ends_with($event->title, "\xC4"));

        // 2. Long title truncation at 255 chars with multibyte characters
        $longTitle = str_repeat('Krancītis suņu izstādē ', 15);
        $event->title = $longTitle;

        $this->assertTrue(mb_check_encoding($event->title, 'UTF-8'));
        $this->assertLessThanOrEqual(255, mb_strlen($event->title, 'UTF-8'));
        $this->assertFalse(str_ends_with($event->title, "\xC4"));
    }

    /**
     * Test EventTranslation model title setter safety and length truncation.
     */
    public function test_event_translation_model_title_safeguards(): void
    {
        $translation = new EventTranslation();

        $translation->title = '  Krancītis suņu izstādē  ';
        $this->assertSame('Krancītis suņu izstādē', $translation->title);
        $this->assertTrue(mb_check_encoding($translation->title, 'UTF-8'));
        $this->assertFalse(str_ends_with($translation->title, "\xC4"));

        $longTitle = str_repeat('Krancītis suņu izstādē ', 15);
        $translation->title = $longTitle;

        $this->assertTrue(mb_check_encoding($translation->title, 'UTF-8'));
        $this->assertLessThanOrEqual(255, mb_strlen($translation->title, 'UTF-8'));
        $this->assertFalse(str_ends_with($translation->title, "\xC4"));
    }

    /**
     * Test full database persistence for Latvian character endings and long multibyte truncation.
     */
    public function test_database_persistence_with_latvian_endings(): void
    {
        $titles = [
            'Krancītis suņu izstādē', // ē (\xC4\x93)
            'Koncerts Rīgā',          // ā (\xC4\x81)
            'Vakars pilī',            // ī (\xC4\xAB)
            'Pastaiga lietū',         // ū (\xC5\xAB)
            'Ziemassvētku tirdziņš',  // š (\xC5\xA1)
            'Mākslas birž',           // ž (\xC5\xBE)
        ];

        foreach ($titles as $idx => $title) {
            $event = Event::create([
                'title' => $title,
                'slug' => 'test-event-' . $idx,
                'status' => 'published',
            ]);

            $translation = EventTranslation::create([
                'event_id' => $event->id,
                'locale' => 'lv',
                'title' => $title,
                'slug' => 'test-event-lv-' . $idx,
            ]);

            // Verify raw database record
            $rawEvent = DB::table('events')->where('id', $event->id)->first();
            $this->assertNotNull($rawEvent);
            $this->assertSame($title, $rawEvent->title);
            $this->assertTrue(mb_check_encoding($rawEvent->title, 'UTF-8'));
            $this->assertFalse(str_ends_with($rawEvent->title, "\xC4"));

            $rawTranslation = DB::table('event_translations')->where('id', $translation->id)->first();
            $this->assertNotNull($rawTranslation);
            $this->assertSame($title, $rawTranslation->title);
            $this->assertTrue(mb_check_encoding($rawTranslation->title, 'UTF-8'));
            $this->assertFalse(str_ends_with($rawTranslation->title, "\xC4"));
        }

        // Test DB persistence of 300-char multibyte string truncation
        $longTitle = str_repeat('Krancītis suņu izstādē ', 15);
        $longEvent = Event::create([
            'title' => $longTitle,
            'slug' => 'long-event',
            'status' => 'published',
        ]);

        $rawLong = DB::table('events')->where('id', $longEvent->id)->first();
        $this->assertNotNull($rawLong);
        $this->assertLessThanOrEqual(255, mb_strlen($rawLong->title, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($rawLong->title, 'UTF-8'));
        $this->assertFalse(str_ends_with($rawLong->title, "\xC4"));
    }
}
