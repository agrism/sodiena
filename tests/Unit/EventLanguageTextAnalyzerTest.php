<?php

namespace Tests\Unit;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Services\Language\EventLanguageTextAnalyzer;
use PHPUnit\Framework\TestCase;

class EventLanguageTextAnalyzerTest extends TestCase
{
    /**
     * Multilingual spoken and subtitles extraction.
     */
    public function test_multilingual_spoken_and_subtitles(): void
    {
        $text = 'Filma angļu, itāļu, nīderlandiešu un franču valodā ar subtitriem latviešu un krievu valodā.';
        $result = EventLanguageTextAnalyzer::analyze('Kino seanss', null, $text);

        $this->assertNotNull($result);
        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['en', 'fr', 'it', 'nl'], $result->spokenLanguages);
        $this->assertSame(['lv', 'ru'], $result->subtitleLanguages);
        $this->assertSame(EventLanguageSource::Inferred, $result->source);
    }

    /**
     * Typo tolerance: "subtitirem" -> subtitles.
     */
    public function test_typo_tolerance_subtitirem(): void
    {
        $text = 'Filma latviešu valodā ar subtitirem angļu valodā.';
        $result = EventLanguageTextAnalyzer::analyze('Kino seanss', null, $text);

        $this->assertNotNull($result);
        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['lv'], $result->spokenLanguages);
        $this->assertSame(['en'], $result->subtitleLanguages);
    }

    /**
     * Subtitle negation: "bez subtitriem" prevents subtitle extraction.
     */
    public function test_subtitle_negation_bez_subtitriem(): void
    {
        $text = 'Filma angļu valodā bez subtitriem, savukārt komiķi runās latviešu valodā.';
        $result = EventLanguageTextAnalyzer::analyze('Vakara programma', null, $text);

        $this->assertNotNull($result);
        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['en', 'lv'], $result->spokenLanguages);
        $this->assertSame([], $result->subtitleLanguages);
    }

    /**
     * Dutch alias "holandiešu" mapped to 'nl'.
     */
    public function test_holandiesu_alias_mapped_to_nl(): void
    {
        $text = 'Koncerts angļu un holandiešu valodā ar subtitriem latviešu valodā.';
        $result = EventLanguageTextAnalyzer::analyze('Mūzikas vakars', null, $text);

        $this->assertNotNull($result);
        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['en', 'nl'], $result->spokenLanguages);
        $this->assertSame(['lv'], $result->subtitleLanguages);
    }

    /**
     * Russian spoken with Latvian and English surtitles/titri.
     */
    public function test_russian_spoken_with_latvian_and_english_titri(): void
    {
        $text = 'Izrāde krievu valodā ar latviešu un angļu titriem';
        $result = EventLanguageTextAnalyzer::analyze('Teātra izrāde', null, $text);

        $this->assertNotNull($result);
        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['ru'], $result->spokenLanguages);
        $this->assertSame(['en', 'lv'], $result->subtitleLanguages);
    }

    /**
     * English prepositional phrase: "in English".
     */
    public function test_english_in_english(): void
    {
        $text = 'Stand-up comedy in English';
        $result = EventLanguageTextAnalyzer::analyze($text);

        $this->assertNotNull($result);
        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['en'], $result->spokenLanguages);
        $this->assertSame([], $result->subtitleLanguages);
    }

    /**
     * Russian prepositional phrase: "на русском языке".
     */
    public function test_russian_na_russkom(): void
    {
        $text = 'Спектакль на русском языке';
        $result = EventLanguageTextAnalyzer::analyze($text);

        $this->assertNotNull($result);
        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['ru'], $result->spokenLanguages);
        $this->assertSame([], $result->subtitleLanguages);
    }

    /**
     * Non-verbal event: "kustību izrāde bez vārdiem" -> language_free.
     */
    public function test_non_verbal_event(): void
    {
        $text = 'Kustību izrāde bez vārdiem';
        $result = EventLanguageTextAnalyzer::analyze($text);

        $this->assertNotNull($result);
        $this->assertSame(EventLanguageMode::LanguageFree, $result->mode);
        $this->assertSame([], $result->spokenLanguages);
        $this->assertSame([], $result->subtitleLanguages);
    }

    /**
     * False positive protection: metaphors must return null.
     */
    public function test_metaphor_protection_returns_null(): void
    {
        $this->assertNull(EventLanguageTextAnalyzer::analyze('Koncerts, kurā mūzikas valoda vieno paaudzes'));
        $this->assertNull(EventLanguageTextAnalyzer::analyze('Dejas valoda un ķermeņa valoda'));
        $this->assertNull(EventLanguageTextAnalyzer::analyze('Dzimtās valodas diena bibliotēkā'));
    }

    /**
     * Structured label: "Pasākuma valoda: latviešu, angļu".
     */
    public function test_structured_language_label(): void
    {
        $text = 'Seminārs visiem interesentiem. Pasākuma valoda: latviešu, angļu.';
        $result = EventLanguageTextAnalyzer::analyze('Seminārs', null, $text);

        $this->assertNotNull($result);
        $this->assertSame(EventLanguageMode::Spoken, $result->mode);
        $this->assertSame(['en', 'lv'], $result->spokenLanguages);
    }

    /**
     * Parenthetical tag in title: "(LV/EN)" vs non-language tags like "(3D)".
     */
    public function test_title_tag_parsing(): void
    {
        $result = EventLanguageTextAnalyzer::analyze('Lieliska filma (LV/EN)', null, 'Apraksts bez valodas norādēm');
        $this->assertNotNull($result);
        $this->assertSame(['en', 'lv'], $result->spokenLanguages);

        $nonLangResult = EventLanguageTextAnalyzer::analyze('Kosmosa filma (3D)', null, 'Parasta filma');
        $this->assertNull($nonLangResult);
    }
}
