<?php

namespace Tests\Unit;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Services\Language\MarkusCinemaLanguageParser;
use PHPUnit\Framework\TestCase;

class MarkusCinemaLanguageParserTest extends TestCase
{
    /**
     * 1. Forum Cinemas "Ķepu patruļa" (lv, without subtitles).
     * Even if Events.xml mentions dubbing in multiple languages with subtitles,
     * the show itself is the single source of truth.
     */
    public function test_forum_cinemas_kepu_patrula_dubbed_without_subtitles(): void
    {
        $show = [
            'SpokenLanguage' => [
                'ISOTwoLetterCode' => 'lv',
            ],
            'Genres' => 'Animācija, Piedzīvojumi',
            'Title' => 'Ķepu patruļa: Varenā filma',
        ];

        $event = [
            'Synopsis' => 'Filma dublēta latviešu un krievu valodā ar subtitriem latviešu valodā.',
        ];

        $result = MarkusCinemaLanguageParser::parseShow($show, $event);

        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['lv'], $result->spokenLanguages);
        $this->assertSame([], $result->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Explicit, $result->source);
    }

    /**
     * 2. Forum Cinemas "Rallijs" (ru show with lv subtitles).
     */
    public function test_forum_cinemas_rallijs_russian_with_latvian_subtitles(): void
    {
        $show = [
            'SpokenLanguage' => [
                'ISOTwoLetterCode' => 'ru',
            ],
            'SubtitleLanguage1' => 'lv',
            'Title' => 'Rallijs',
        ];

        $result = MarkusCinemaLanguageParser::parseShow($show);

        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['ru'], $result->spokenLanguages);
        $this->assertSame(['lv'], $result->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Explicit, $result->source);
    }

    /**
     * 3. Forum Cinemas "Uļa" (<Name>Latviešu / krievu</Name>, empty ISOTwoLetterCode, subtitles en, lv).
     */
    public function test_forum_cinemas_ula_bilingual_name_empty_iso(): void
    {
        $show = [
            'SpokenLanguage' => [
                'Name' => 'Latviešu / krievu',
                'ISOTwoLetterCode' => [],
            ],
            'SubtitleLanguage1' => 'en',
            'SubtitleLanguage2' => 'lv',
            'Title' => 'Uļa',
        ];

        $result = MarkusCinemaLanguageParser::parseShow($show);

        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['lv', 'ru'], $result->spokenLanguages);
        $this->assertSame(['en', 'lv'], $result->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Explicit, $result->source);
    }

    /**
     * 4. Forum Cinemas "Ceļš nekad nebeidzas" (<Name>Dažādas val.</Name>, empty ISOTwoLetterCode, subtitles lv, en).
     */
    public function test_forum_cinemas_cels_nekad_nebeidzas_various_languages(): void
    {
        $show = [
            'SpokenLanguage' => [
                'Name' => 'Dažādas val.',
                'ISOTwoLetterCode' => '',
            ],
            'SubtitleLanguage1' => 'lv',
            'SubtitleLanguage2' => 'en',
            'Title' => 'Ceļš nekad nebeidzas',
        ];

        $result = MarkusCinemaLanguageParser::parseShow($show);

        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame([], $result->spokenLanguages);
        $this->assertSame(['en', 'lv'], $result->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Explicit, $result->source);
    }

    /**
     * 5. Apollo Kino "Godzilla Minus Zero" (empty SpokenLanguage, SubtitleLanguage1: lv, Synopsis: "Filma japāņu valodā ar subtitriem latviešu valodā.").
     */
    public function test_apollo_kino_godzilla_minus_zero_empty_show_spoken_fallback_synopsis(): void
    {
        $show = [
            'SpokenLanguage' => [
                'Name' => [],
                'NameInLanguage' => [],
                'ISOTwoLetterCode' => [],
            ],
            'SubtitleLanguage1' => 'lv',
            'Title' => 'Godzilla Minus Zero',
        ];

        $event = [
            'Synopsis' => 'Filma japāņu valodā ar subtitriem latviešu valodā.',
        ];

        $result = MarkusCinemaLanguageParser::parseShow($show, $event);

        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['ja'], $result->spokenLanguages);
        $this->assertSame(['lv'], $result->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Explicit, $result->source);
    }

    /**
     * 6. Apollo Kino "Uļa" (spoken: lv, subtitles: lv, en).
     * Subtitles containing the spoken language are preserved.
     */
    public function test_apollo_kino_ula_latvian_spoken_latvian_and_english_subtitles(): void
    {
        $show = [
            'SpokenLanguage' => [
                'Name' => 'Latviešu',
            ],
            'SubtitleLanguage1' => 'lv',
            'SubtitleLanguage2' => 'en',
            'Title' => 'Uļa',
        ];

        $result = MarkusCinemaLanguageParser::parseShow($show);

        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['lv'], $result->spokenLanguages);
        $this->assertSame(['en', 'lv'], $result->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Explicit, $result->source);
    }

    /**
     * 7. Apollo Kino "Parīzes Operas balets — Gulbju ezers" (empty language block, Genres: Balets).
     */
    public function test_apollo_kino_ballet_recording_language_free(): void
    {
        $show = [
            'SpokenLanguage' => [],
            'Genres' => 'Balets',
            'Title' => 'Parīzes Operas balets — Gulbju ezers',
        ];

        $result = MarkusCinemaLanguageParser::parseShow($show);

        $this->assertSame(EventLanguageMode::LanguageFree, $result->mode);
        $this->assertSame([], $result->spokenLanguages);
        $this->assertSame([], $result->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Explicit, $result->source);
    }

    /**
     * 8. Fallback check on PresentationMethodAndLanguage.
     */
    public function test_presentation_method_fallback(): void
    {
        $show = [
            'PresentationMethodAndLanguage' => '2D, Franču',
            'Title' => 'Franču kino vakars',
        ];

        $result = MarkusCinemaLanguageParser::parseShow($show);

        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['fr'], $result->spokenLanguages);
        $this->assertSame([], $result->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Explicit, $result->source);
    }
}
