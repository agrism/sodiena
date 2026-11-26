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
            $wasClearedByDubbingGuard = false;

            // Guard against historical cinema events with ambiguous dubbing descriptions (e.g. "Filma dublēta latviešu un krievu valodā")
            $isCinemaEvent = in_array($event->source_slug, ['apollo-kino', 'forum-cinemas'], true)
                || $event->entertainment_type === 'kino'
                || (is_array($rawData) && isset($rawData['cinema']))
                || ($event->relationLoaded('categories') ? $event->categories->pluck('slug')->contains('kino') : false);

            if ($isCinemaEvent && EventLanguageTextAnalyzer::hasAmbiguousDubbingSignal($event->title . ' ' . (string) $event->short_description . ' ' . (string) $event->description)) {
                $textResult = new EventLanguageData(
                    mode: EventLanguageMode::Spoken,
                    spokenLanguages: [],
                    subtitleLanguages: $textResult->subtitleLanguages ?? [],
                    source: EventLanguageSource::Inferred,
                );
                $wasClearedByDubbingGuard = true;
            }

            // 2. līmeņa papildināšana ar 3. līmeni (kad atrasti subtitri, bet nav runātās valodas)
            if ($textResult->mode === EventLanguageMode::Spoken
                && empty($textResult->spokenLanguages)
                && !empty($textResult->subtitleLanguages)
                && !$wasClearedByDubbingGuard
            ) {
                $level3 = $this->applyLevel3Rules($event);
                if ($level3 !== null && $level3->mode === EventLanguageMode::Spoken && !empty($level3->spokenLanguages)) {
                    return new EventLanguageData(
                        mode: EventLanguageMode::Spoken,
                        spokenLanguages: $level3->spokenLanguages,
                        subtitleLanguages: $textResult->subtitleLanguages,
                        source: EventLanguageSource::Inferred,
                    );
                }
            }

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
        if (empty($catSlugs)) {
            $catSlugs = ['citi'];
        }

        $entertainmentType = (string) $event->entertainment_type;

        $sourceType = $event->source?->type;
        $sourceTypeStr = is_string($sourceType) ? $sourceType : $sourceType?->value;

        $placeType = (string) ($event->location?->place_type ?? '');

        $locationName = (string) ($event->location?->name ?? '');
        $normalizedVenue = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $locationName)), 'UTF-8');
        if (in_array($normalizedVenue, ['', 'rīga', 'riga', 'latvija', 'latvia'], true) && is_array($event->raw_data)) {
            $rawV = $event->raw_data['venue'] ?? $event->raw_data['display_venue'] ?? $event->raw_data['place'] ?? null;
            if (is_string($rawV) && trim($rawV) !== '') {
                $normalizedVenue = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $rawV)), 'UTF-8');
            }
        }

        $title = (string) $event->title;
        $titleLower = mb_strtolower(trim($title), 'UTF-8');

        // Aizsardzība pret trokšņainu events.entertainment_type
        $hasSpokenCategory = !empty(array_intersect($catSlugs, ['teatris', 'kino', 'seminari', 'berniem']));
        $repertoireTheatre = self::matchRepertoireTheatre($normalizedVenue);
        $allowEntertainmentTypeFallback = !$hasSpokenCategory && $repertoireTheatre === null;

        // -------------------------------------------------------------
        // 3.2. Daudzkategoriju konfliktu izšķiršana
        // -------------------------------------------------------------
        $spokenCats = array_values(array_intersect($catSlugs, ['teatris', 'kino', 'seminari']));
        $langFreeCats = array_values(array_intersect($catSlugs, ['sports', 'izstades', 'muzika', 'svetki']));
        $hasCategoryConflict = !empty($spokenCats) && !empty($langFreeCats);

        $blockLanguageFree = false;

        // Papildu sargs kategorijai 'berniem':
        if (in_array('berniem', $catSlugs, true) && !empty($langFreeCats)) {
            $kidsSpokenPattern = '/\b(?:izrād\w*|pasak\w*|leļļu\w*|darbnīc\w*|meistarklas\w*|lasījum\w*|nodarbīb\w*|filma\w*|kino\w*|teātr\w*)\b/ui';
            if (preg_match($kidsSpokenPattern, $titleLower)) {
                $blockLanguageFree = true;
            }
        }

        if ($hasCategoryConflict) {
            // Šķīrējs 1: Spēcīgi spoken signāli (bloķē language_free)
            $hasStrongSpokenSignal = false;

            // 1a. Cinema place_type vai kino kategorija (izņemot, ja title satur balet vai koncert bez filma)
            $hasKinoCatOrPlace = ($placeType === 'cinema') || in_array('kino', $catSlugs, true);
            if ($hasKinoCatOrPlace) {
                $isBalletOrConcertKino = (bool) preg_match('/\b(?:balet\w*|koncert\w*)\b/ui', $titleLower)
                    && !preg_match('/\b(?:filma\w*|kinoseans\w*)\b/ui', $titleLower);
                if (!$isBalletOrConcertKino) {
                    $hasStrongSpokenSignal = true;
                }
            }

            // 1b. Atbilst zināmam repertuāra teātrim UN title nesatur koncert, izstād, balet
            if (!$hasStrongSpokenSignal) {
                if ($repertoireTheatre !== null) {
                    $isNonSpokenRepertoireEvent = (bool) preg_match('/\b(?:koncert\w*|izstād\w*|balet\w*)\b/ui', $titleLower);
                    if (!$isNonSpokenRepertoireEvent) {
                        $hasStrongSpokenSignal = true;
                    }
                }
            }

            // 1c. Title satur nepārprotamu runāta pasākuma pazīmi
            if (!$hasStrongSpokenSignal) {
                $spokenTitlePattern = '/\b(?:izrād\w*|teātr\w*|komēdij\w*|stand-?up|stāvizrād\w*|lekcij\w*|seminār\w*|konferenc\w*|meistarklas\w*|darbnīc\w*|ekskursij\w*|diskusij\w*|filma\w*|kinoseans\w*|tikšanās\w*|lasījum\w*|mūzikl\w*|oper[ae]\w*|operet\w*)\b/ui';
                if (preg_match($spokenTitlePattern, $titleLower)) {
                    $hasStrongSpokenSignal = true;
                }
            }

            if ($hasStrongSpokenSignal) {
                $blockLanguageFree = true;
            } else {
                // Šķīrējs 2: Spēcīgi language_free signāli
                $hasStrongLanguageFreeSignal = false;

                // 2a. Sports source vai place_type sports_facility (un nav sports izņēmumu title)
                $isSportsSourceOrPlace = ($sourceTypeStr === 'sports') || ($placeType === 'sports_facility');
                $sportsExceptionPattern = '/\b(?:lekcij\w*|seminār\w*|konferenc\w*|diskusij\w*|filma\w*|kinoseans\w*|tikšanās\s+ar|stāstu\s+vakar\w*)\b/ui';
                if ($isSportsSourceOrPlace && !preg_match($sportsExceptionPattern, $titleLower)) {
                    $hasStrongLanguageFreeSignal = true;
                }

                // 2b. Title satur nepārprotamu bezvalodas pasākuma pazīmi
                if (!$hasStrongLanguageFreeSignal) {
                    $langFreeTitlePattern = '/\b(?:koncert\w*|simfonisk\w*|orķestr\w*|filharmon\w*|izstād\w*|tirdziņ\w*|tirgus\w*|gadatirg\w*|balle\w*|zaļumballe\w*|diskotēk\w*|balet\w*|cirks|cirkā|cirka\s+izrād\w*|čempionāt\w*|turnīr\w*|sacensīb\w*|maraton\w*|skrējiens\w*)\b/ui';
                    if (preg_match($langFreeTitlePattern, $titleLower)) {
                        $hasStrongLanguageFreeSignal = true;
                    } elseif (in_array('sports', $catSlugs, true) && preg_match('/\bspēl[eē]\w*\b/ui', $titleLower)) {
                        $hasStrongLanguageFreeSignal = true;
                    }
                }

                // 2c. Entertainment type in ('concert', 'sports') un nav kino un place nav theatre/cinema
                if (!$hasStrongLanguageFreeSignal) {
                    $isLangFreeEntertainment = in_array($entertainmentType, ['concert', 'sports'], true);
                    $isKinoOrTheatreContext = in_array('kino', $catSlugs, true)
                        || in_array('teatris', $catSlugs, true)
                        || in_array($placeType, ['theatre', 'cinema'], true)
                        || $repertoireTheatre !== null;
                    if ($isLangFreeEntertainment && !$isKinoOrTheatreContext) {
                        $hasStrongLanguageFreeSignal = true;
                    }
                }

                if ($hasStrongLanguageFreeSignal) {
                    $blockLanguageFree = false;
                } else {
                    // Šķīrējs 3: Drošais noklusējums neatrisinātam konfliktam -> NEPIEŠĶIRT language_free
                    $blockLanguageFree = true;
                }
            }
        }

        // -------------------------------------------------------------
        // 3.1. Language-free noteikumi (Pārbauda TIKAI normalizētu title)
        // -------------------------------------------------------------
        if (!$blockLanguageFree) {
            // 1. Sports
            if (in_array('sports', $catSlugs, true) || $sourceTypeStr === 'sports' || ($allowEntertainmentTypeFallback && $entertainmentType === 'sports')) {
                $sportsExceptionPattern = '/\b(?:lekcij\w*|seminār\w*|konferenc\w*|diskusij\w*|filma\w*|kinoseans\w*|tikšanās\s+ar|stāstu\s+vakar\w*)\b/ui';
                if (!preg_match($sportsExceptionPattern, $titleLower)) {
                    return new EventLanguageData(
                        mode: EventLanguageMode::LanguageFree,
                        spokenLanguages: [],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Inferred,
                    );
                }
            }

            // 2. Izstādes (tikai title pārbaude!)
            if (in_array('izstades', $catSlugs, true) || ($allowEntertainmentTypeFallback && $entertainmentType === 'exhibition')) {
                $exhibitionExceptionPattern = '/\b(?:ekskursij\w*|gida\s+pavadīb\w*|ar\s+gidu|lekcij\w*|sarun\w*|darbnīc\w*|meistarklas\w*|tikšanās\w*|diskusij\w*|lasījum\w*|prezentācij\w*|stāstījum\w*|radoš\w*\s+darbnīc\w*|seminār\w*)\b/ui';
                if (!preg_match($exhibitionExceptionPattern, $titleLower)) {
                    return new EventLanguageData(
                        mode: EventLanguageMode::LanguageFree,
                        spokenLanguages: [],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Inferred,
                    );
                }
            }

            // 3. Mūzika & koncerti (tikai title pārbaude!)
            if (in_array('muzika', $catSlugs, true) || ($allowEntertainmentTypeFallback && $entertainmentType === 'concert')) {
                $musicExceptionPattern = '/\b(?:oper[ae]\w*|operet\w*|mūzikl\w*|koncertlekcij\w*|koncertsarun\w*|dzej\w*|stāstu\s+vakar\w*|literār\w*|stand-?up|stāvizrād\w*|komēdij\w*|izrād\w*|teātr\w*)\b/ui';
                $hasMusicException = (bool) preg_match($musicExceptionPattern, $titleLower);

                // Ja virsrakstā ir balets bez lekcijas/sarunas/diskusijas, tas paliek language_free
                if ($hasMusicException && preg_match('/\bbalet\w*\b/ui', $titleLower) && !preg_match('/\b(?:lekcij\w*|sarun\w*|diskusij\w*)\b/ui', $titleLower)) {
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

            // 4. Svētki, tirdziņi, balles (tikai title pārbaude!)
            $isMarketOrBallTitle = (bool) preg_match('/\b(?:tirdziņ\w*|tirgus\w*|gadatirg\w*|balle\w*|zaļumballe\w*|diskotēk\w*|salūt\w*)\b/ui', $titleLower);
            if (in_array('svetki', $catSlugs, true) || ((in_array('citi', $catSlugs, true) || in_array('berniem', $catSlugs, true)) && $isMarketOrBallTitle)) {
                $festivityExceptionPattern = '/\b(?:teātra\s+festivāl\w*|kino\s+festivāl\w*|dzejas\s+dien\w*|literār\w*|stand-?up|stāvizrād\w*|izrād\w*|lekcij\w*|konferenc\w*|seminār\w*|filma\w*)\b/ui';
                if (!preg_match($festivityExceptionPattern, $titleLower)) {
                    return new EventLanguageData(
                        mode: EventLanguageMode::LanguageFree,
                        spokenLanguages: [],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Inferred,
                    );
                }
            }

            // 5. Deja, balets, cirks (tikai title pārbaude!)
            $hasDanceCat = in_array('teatris', $catSlugs, true)
                || in_array('berniem', $catSlugs, true)
                || in_array('citi', $catSlugs, true)
                || in_array('muzika', $catSlugs, true)
                || in_array('svetki', $catSlugs, true);
            $dancePattern = '/\b(?:balet\w*|laikmetīg\w*\s+dej\w*|dej[au]s?\s+izrād\w*|deju\s+uzvedum\w*|cirka\s+izrād\w*|\bcirks\b|\bcirkā\b|pantomīm\w*|ledus\s+šov\w*)\b/ui';
            if ($hasDanceCat && preg_match($dancePattern, $titleLower)) {
                $danceExceptionPattern = '/\b(?:lekcij\w*|sarun\w*|diskusij\w*|stāvizrād\w*|ekskursij\w*)\b/ui';
                if (!preg_match($danceExceptionPattern, $titleLower)) {
                    return new EventLanguageData(
                        mode: EventLanguageMode::LanguageFree,
                        spokenLanguages: [],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Inferred,
                    );
                }
            }
        }

        // -------------------------------------------------------------
        // 3.3. Norises vietu atpazīšana un teātru / kino noteikumi
        // -------------------------------------------------------------

        // 5. Kino pārbaudes prioritāte pirms teātra vietas, ja nav konflikta ar teātri vai place_type ir cinema
        $isPureCinema = ($placeType === 'cinema') || (in_array('kino', $catSlugs, true) && !in_array('teatris', $catSlugs, true));
        if ($isPureCinema) {
            return new EventLanguageData(
                mode: EventLanguageMode::Spoken,
                spokenLanguages: [],
                subtitleLanguages: [],
                source: EventLanguageSource::Inferred,
            );
        }

        // Repertuāra teātru pārbaude
        $isTheatreContext = in_array('teatris', $catSlugs, true)
            || in_array('berniem', $catSlugs, true)
            || in_array('citi', $catSlugs, true)
            || $placeType === 'theatre';

        if ($isTheatreContext && !empty($normalizedVenue)) {
            if ($repertoireTheatre !== null) {
                // Daudzvalodu teātri -> spoken: []
                if ($repertoireTheatre['type'] === 'multilingual') {
                    return new EventLanguageData(
                        mode: EventLanguageMode::Spoken,
                        spokenLanguages: [],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Inferred,
                    );
                }

                // Krievu repertuāra teātris -> spoken: ['ru']
                if ($repertoireTheatre['type'] === 'russian') {
                    $isNonSpokenRussianEvent = (bool) preg_match('/\b(?:izstād\w*|balet\w*)\b/ui', $titleLower);
                    if (!$isNonSpokenRussianEvent) {
                        return new EventLanguageData(
                            mode: EventLanguageMode::Spoken,
                            spokenLanguages: ['ru'],
                            subtitleLanguages: [],
                            source: EventLanguageSource::Inferred,
                        );
                    }
                }

                // Latviešu repertuāra teātri -> spoken: ['lv']
                if ($repertoireTheatre['type'] === 'latvian') {
                    // Virsraksta aizsardzība: koncerts, balets, izstāde vai kirilica virsrakstā -> nepiešķirt ['lv']!
                    $isNonSpokenOrCyrillic = (bool) preg_match('/\b(?:koncert\w*|izstād\w*|balet\w*)\b/ui', $titleLower)
                        || (bool) preg_match('/\p{Cyrillic}/u', $title);
                    if (!$isNonSpokenOrCyrillic) {
                        return new EventLanguageData(
                            mode: EventLanguageMode::Spoken,
                            spokenLanguages: ['lv'],
                            subtitleLanguages: [],
                            source: EventLanguageSource::Inferred,
                        );
                    }
                }
            }
        }

        // Pārējie kino pasākumi
        if (in_array('kino', $catSlugs, true) || $entertainmentType === 'kino' || $placeType === 'cinema') {
            return new EventLanguageData(
                mode: EventLanguageMode::Spoken,
                spokenLanguages: [],
                subtitleLanguages: [],
                source: EventLanguageSource::Inferred,
            );
        }

        // Pašvaldību, kultūras namu un bibliotēku pasākumi
        $isMunicipalityOrCulture = ($sourceTypeStr === 'municipality') || in_array($placeType, ['cultural_center', 'library'], true);
        $spokenCategories = ['teatris', 'seminari', 'berniem', 'citi'];
        if ($isMunicipalityOrCulture && !empty(array_intersect($catSlugs, $spokenCategories))) {
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

        // Pārējās runātās kategorijas bez noteiktas valodas
        if (in_array('teatris', $catSlugs, true) || in_array('seminari', $catSlugs, true) || in_array('berniem', $catSlugs, true) || $hasCategoryConflict) {
            return new EventLanguageData(
                mode: EventLanguageMode::Spoken,
                spokenLanguages: [],
                subtitleLanguages: [],
                source: EventLanguageSource::Inferred,
            );
        }

        // Citas neskaidras kategorijas ('citi')
        return null;
    }

    /**
     * Match normalized venue name against known repertoire theatres.
     */
    public static function matchRepertoireTheatre(string $normalizedVenue): ?array
    {
        if (empty($normalizedVenue)) {
            return null;
        }

        // 1. Daudzvalodu teātri (spoken: [])
        $multilingualTheatres = [
            'leļļu teātr',
            'daugavpils teātr',
            'latvijas nacionālā opera',
            'nacionālā opera',
            'operas un baleta',
            'teātris joriks',
            'teātris "joriks"',
            'joriks',
        ];
        foreach ($multilingualTheatres as $pattern) {
            if (str_contains($normalizedVenue, $pattern)) {
                return ['type' => 'multilingual', 'pattern' => $pattern];
            }
        }

        // 2. Krievu repertuāra teātris (spoken: ['ru'])
        $russianTheatres = [
            'mihaila čehova',
            'm. čehova',
            'čehova rīgas krievu',
            'rīgas krievu teātr',
            'čehova teātr',
        ];
        foreach ($russianTheatres as $pattern) {
            if (str_contains($normalizedVenue, $pattern)) {
                return ['type' => 'russian', 'pattern' => $pattern];
            }
        }

        // 3. Latviešu repertuāra teātri (spoken: ['lv'])
        $latvianTheatres = [
            'dailes teātr',
            'latvijas nacionālais teātr',
            'nacionālais teātr',
            'jaunais rīgas teātr',
            'valmieras drāmas teātr',
            'valmieras teātr',
            'liepājas teātr',
            'dirty deal teatro',
            'dirty deal',
            'ģertrūdes ielas teātr',
            'kvadrifrons',
            'teātris tt',
            'goda teātr',
            'ādolfa alunāna',
            'alunāna jelgavas teātr',
            'willa teātr',
            'austrumu robež',
            'teātris skatuve',
            'rīgas improvizācijas teātr',
            'improvizācijas teātris spiediens',
        ];
        foreach ($latvianTheatres as $pattern) {
            if (str_contains($normalizedVenue, $pattern)) {
                return ['type' => 'latvian', 'pattern' => $pattern];
            }
        }

        if (str_contains($normalizedVenue, 'kabata') && str_contains($normalizedVenue, 'teātr')) {
            return ['type' => 'latvian', 'pattern' => 'teātris kabata'];
        }

        return null;
    }
}
