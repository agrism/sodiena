<?php

namespace App\Services\Language;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Models\Event;

class EventLanguageDetector
{
    /**
     * Master method to detect language for an Event model using the 3-level deterministic cascade:
     * 1. Level 1: Markus Cinema raw XML / array data (source = 'explicit')
     * 2. Level 2: Contextual text analysis of title, short_description, description (source = 'inferred')
     * 3. Level 3: Deterministic category, venue, and source rules (source = 'inferred')
     */
    public function detectForEvent(Event $event): ?EventLanguageData
    {
        // -------------------------------------------------------------
        // Level 1: Markus Cinema explicit data if available in raw_data
        // -------------------------------------------------------------
        $rawData = $event->raw_data;
        if (is_array($rawData)) {
            $cinema = $rawData['cinema'] ?? null;
            if (is_array($cinema) && (isset($cinema['spoken_language_raw']) || isset($cinema['subtitle_languages_raw']))) {
                $showData = [
                    'SpokenLanguage' => $cinema['spoken_language_raw'] ?? null,
                    'SubtitleLanguage1' => $cinema['subtitle_languages_raw'][0] ?? null,
                    'SubtitleLanguage2' => $cinema['subtitle_languages_raw'][1] ?? null,
                    'PresentationMethod' => $cinema['presentation_method'] ?? null,
                    'Genres' => $rawData['genres'] ?? null,
                    'Title' => $event->title,
                ];
                $eventMeta = [
                    'Synopsis' => $event->description,
                    'ShortSynopsis' => $event->short_description,
                    'Genres' => $rawData['genres'] ?? null,
                    'Title' => $event->title,
                ];

                $cinemaParsed = MarkusCinemaLanguageParser::parseShow($showData, $eventMeta);
                if ($cinemaParsed->mode !== EventLanguageMode::Unknown) {
                    return $cinemaParsed;
                }
            }
        }

        // -------------------------------------------------------------
        // Level 2: Contextual text analysis (title + descriptions)
        // -------------------------------------------------------------
        $textResult = EventLanguageTextAnalyzer::analyze(
            $event->title,
            $event->short_description,
            $event->description
        );

        if ($textResult !== null) {
            return $textResult;
        }

        // -------------------------------------------------------------
        // Level 3: Deterministic category, venue, and source rules
        // -------------------------------------------------------------
        return $this->applyLevel3Rules($event);
    }

    /**
     * Apply Level 3 category, venue, and source rules.
     */
    public function applyLevel3Rules(Event $event): ?EventLanguageData
    {
        if (!$event->relationLoaded('categories')) {
            $event->load('categories');
        }
        if (!$event->relationLoaded('location')) {
            $event->load('location');
        }
        if (!$event->relationLoaded('source')) {
            $event->load('source');
        }

        $catSlugs = $event->categories->pluck('slug')->all();
        $entertainmentType = (string) $event->entertainment_type;

        $sourceType = $event->source?->type;
        $sourceTypeStr = is_string($sourceType) ? $sourceType : $sourceType?->value;

        $locationName = mb_strtolower(trim($event->location?->name ?? ''), 'UTF-8');
        $placeType = $event->location?->place_type;

        $title = (string) $event->title;
        $combinedText = mb_strtolower($title . ' ' . (string) $event->short_description . ' ' . (string) $event->description, 'UTF-8');
        $titleLower = mb_strtolower($title, 'UTF-8');

        // -------------------------------------------------------------
        // 7.1. Language-free events (mode = 'language_free', spoken = [], subtitles = [])
        // -------------------------------------------------------------

        // 1. Sports
        if (in_array('sports', $catSlugs, true) || $sourceTypeStr === 'sports' || $entertainmentType === 'sports') {
            $sportsExceptionPattern = '/\b(?:lekcij\w*|seminār\w*|konferenc\w*|diskusij\w*|filma\w*|kinoseans\w*|tikšanās\s+ar)\b/ui';
            if (!preg_match($sportsExceptionPattern, $combinedText)) {
                return new EventLanguageData(
                    mode: EventLanguageMode::LanguageFree,
                    spokenLanguages: [],
                    subtitleLanguages: [],
                    source: EventLanguageSource::Inferred,
                );
            }
        }

        // 2. Exhibitions & museums
        if (in_array('izstades', $catSlugs, true) || $entertainmentType === 'exhibition') {
            $exhibitionExceptionPattern = '/\b(?:ekskursij\w*|gida\s+pavadīb\w*|lekcij\w*|sarun\w*|darbnīc\w*|meistarklas\w*|tikšanās\w*|diskusij\w*|lasījum\w*|prezentācij\w*|stāstījum\w*)\b/ui';
            if (!preg_match($exhibitionExceptionPattern, $combinedText)) {
                return new EventLanguageData(
                    mode: EventLanguageMode::LanguageFree,
                    spokenLanguages: [],
                    subtitleLanguages: [],
                    source: EventLanguageSource::Inferred,
                );
            }
        }

        // 3. Music & concerts
        if (in_array('muzika', $catSlugs, true) || $entertainmentType === 'concert') {
            $musicExceptionPattern = '/\b(?:oper[ae]\w*|operet\w*|mūzikl\w*|koncertlekcij\w*|koncertsarun\w*|dzej\w*|stāstu\s+vakar\w*|literār\w*|stand-?up|stāvizrād\w*|komēdij\w*)\b/ui';
            $hasMusicException = (bool) preg_match($musicExceptionPattern, $combinedText);

            // Special addition: if ballet in title without lecture/discussion, remains language_free
            if ($hasMusicException && preg_match('/\bbalet\w*\b/ui', $titleLower) && !preg_match('/\b(?:lekcij\w*|sarun\w*)\b/ui', $titleLower)) {
                $hasMusicException = false;
            }

            if (!$hasMusicException) {
                return new EventLanguageData(
                    mode: EventLanguageMode::LanguageFree,
                    spokenLanguages: [],
                    subtitleLanguages: [],
                    source: EventLanguageSource::Inferred,
                );
            }
        }

        // 4. Festivities, markets, balls, celebrations
        $isMarketOrBallTitle = (bool) preg_match('/\b(?:tirdziņ\w*|tirgus\w*|gadatirg\w*|balle\w*|zaļumballe\w*|diskotēk\w*|salūt\w*)\b/ui', $titleLower);
        if (in_array('svetki', $catSlugs, true) || ((in_array('citi', $catSlugs, true) || in_array('berniem', $catSlugs, true)) && $isMarketOrBallTitle)) {
            $festivityExceptionPattern = '/\b(?:teātra\s+festivāl\w*|kino\s+festivāl\w*|dzejas\s+dien\w*|literār\w*|stand-?up|stāvizrād\w*|izrād\w*|lekcij\w*|konferenc\w*)\b/ui';
            if (!preg_match($festivityExceptionPattern, $combinedText)) {
                return new EventLanguageData(
                    mode: EventLanguageMode::LanguageFree,
                    spokenLanguages: [],
                    subtitleLanguages: [],
                    source: EventLanguageSource::Inferred,
                );
            }
        }

        // 5. Dance, ballet, circus
        $hasDanceCat = in_array('teatris', $catSlugs, true) || in_array('berniem', $catSlugs, true) || in_array('citi', $catSlugs, true);
        $dancePattern = '/\b(?:balet\w*|laikmetīg[āa]s?\s+dej\w*|dej[au]\s+izrād\w*|deju\s+uzvedum\w*|cirka\s+izrād\w*|cirks|cirkā|pantomīm\w*)\b/ui';
        if ($hasDanceCat && preg_match($dancePattern, $combinedText)) {
            $danceExceptionPattern = '/\b(?:lekcij\w*|sarun\w*|diskusij\w*|stāvizrād\w*|ekskursij\w*)\b/ui';
            if (!preg_match($danceExceptionPattern, $combinedText)) {
                return new EventLanguageData(
                    mode: EventLanguageMode::LanguageFree,
                    spokenLanguages: [],
                    subtitleLanguages: [],
                    source: EventLanguageSource::Inferred,
                );
            }
        }

        // -------------------------------------------------------------
        // 7.2. Known repertoire theatres (mode = 'spoken')
        // -------------------------------------------------------------
        $isTheatreContext = in_array('teatris', $catSlugs, true) || in_array('berniem', $catSlugs, true);
        if ($isTheatreContext && !empty($locationName)) {
            // Multilingual theatres / institutions -> spoken: [] (do not guess one language)
            $multilingualTheatres = [
                'latvijas leļļu teātris',
                'leļļu teātris',
                'leļļu teātr',
                'daugavpils teātris',
                'daugavpils teātr',
                'latvijas nacionālā opera',
                'nacionālā opera',
                'operas un baleta',
            ];
            foreach ($multilingualTheatres as $venue) {
                if (str_contains($locationName, $venue)) {
                    return new EventLanguageData(
                        mode: EventLanguageMode::Spoken,
                        spokenLanguages: [],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Inferred,
                    );
                }
            }

            // Russian repertoire theatre -> spoken: ['ru']
            $russianTheatres = [
                'mihaila čehova',
                'čehova rīgas krievu teātris',
                'rīgas krievu teātris',
                'čehova teātris',
                'čehova teātr',
            ];
            foreach ($russianTheatres as $venue) {
                if (str_contains($locationName, $venue)) {
                    return new EventLanguageData(
                        mode: EventLanguageMode::Spoken,
                        spokenLanguages: ['ru'],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Inferred,
                    );
                }
            }

            // Latvian repertoire theatres -> spoken: ['lv']
            $latvianTheatres = [
                'dailes teātris',
                'dailes teātr',
                'latvijas nacionālais teātris',
                'nacionālais teātris',
                'nacionālais teātr',
                'jaunais rīgas teātris',
                'jaunais rīgas teātr',
                'valmieras drāmas teātris',
                'valmieras teātris',
                'valmieras teātr',
                'liepājas teātris',
                'liepājas teātr',
                'dirty deal teatro',
                'dirty deal',
                'ģertrūdes ielas teātris',
                'ģertrūdes ielas teātr',
                'kvadrifrons',
                'teātris tt',
                'goda teātris',
                'goda teātr',
                'ādolfa alunāna',
                'willa teātris',
                'willa teātr',
            ];
            foreach ($latvianTheatres as $venue) {
                if (str_contains($locationName, $venue)) {
                    return new EventLanguageData(
                        mode: EventLanguageMode::Spoken,
                        spokenLanguages: ['lv'],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Inferred,
                    );
                }
            }
        }

        // -------------------------------------------------------------
        // 7.3. Cinema without Markus XML and without text clues
        // -------------------------------------------------------------
        if (in_array('kino', $catSlugs, true) || $entertainmentType === 'kino' || $placeType === 'cinema') {
            return new EventLanguageData(
                mode: EventLanguageMode::Spoken,
                spokenLanguages: [],
                subtitleLanguages: [],
                source: EventLanguageSource::Inferred,
            );
        }

        // -------------------------------------------------------------
        // 7.4. Municipality, cultural center, and library events
        // -------------------------------------------------------------
        $isMunicipalityOrCulture = ($sourceTypeStr === 'municipality') || in_array($placeType, ['cultural_center', 'library'], true);
        $spokenCategories = ['teatris', 'seminari', 'berniem', 'citi'];
        if ($isMunicipalityOrCulture && !empty(array_intersect($catSlugs, $spokenCategories))) {
            // Safety check: if title contains Cyrillic characters, do NOT assume Latvian
            if (preg_match('/\p{Cyrillic}/u', $title)) {
                return new EventLanguageData(
                    mode: EventLanguageMode::Spoken,
                    spokenLanguages: [],
                    subtitleLanguages: [],
                    source: EventLanguageSource::Inferred,
                );
            }

            return new EventLanguageData(
                mode: EventLanguageMode::Spoken,
                spokenLanguages: ['lv'],
                subtitleLanguages: [],
                source: EventLanguageSource::Inferred,
            );
        }

        // -------------------------------------------------------------
        // 7.5. Other spoken categories without specific language
        // -------------------------------------------------------------
        if (in_array('teatris', $catSlugs, true) || in_array('seminari', $catSlugs, true) || in_array('berniem', $catSlugs, true)) {
            return new EventLanguageData(
                mode: EventLanguageMode::Spoken,
                spokenLanguages: [],
                subtitleLanguages: [],
                source: EventLanguageSource::Inferred,
            );
        }

        // -------------------------------------------------------------
        // 7.6. Other unclear categories ('citi')
        // -------------------------------------------------------------
        return null;
    }
}
