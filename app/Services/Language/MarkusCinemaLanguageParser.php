<?php

namespace App\Services\Language;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Services\EventLanguageService;

class MarkusCinemaLanguageParser
{
    /**
     * Parse Markus Cinema <Show> XML/array data and optional <Event> metadata.
     */
    public static function parseShow(array $show, ?array $event = null): EventLanguageData
    {
        $spokenEl = $show['SpokenLanguage'] ?? $show['spoken_language_raw'] ?? $show['spoken_language'] ?? null;

        $iso = '';
        $name = '';
        $nameInLang = '';

        if (is_array($spokenEl)) {
            if (!empty($spokenEl['ISOTwoLetterCode']) && is_string($spokenEl['ISOTwoLetterCode'])) {
                $iso = trim($spokenEl['ISOTwoLetterCode']);
            }
            if (!empty($spokenEl['Name']) && is_string($spokenEl['Name'])) {
                $name = trim($spokenEl['Name']);
            }
            if (!empty($spokenEl['NameInLanguage']) && is_string($spokenEl['NameInLanguage'])) {
                $nameInLang = trim($spokenEl['NameInLanguage']);
            }
        } elseif (is_string($spokenEl)) {
            $trimmed = trim($spokenEl);
            if (preg_match('/^[a-z]{2}$/i', $trimmed)) {
                $iso = $trimmed;
            } else {
                $name = $trimmed;
            }
        }

        // Check if spoken language indicates multiple / various languages without a specific code
        $combinedSpokenName = trim($name . ' ' . $nameInLang);
        $isVarious = (bool) preg_match('/\b(?:dažād\w*|various|multiple)\b/ui', $combinedSpokenName);

        $spoken = [];
        if ($isVarious) {
            $spoken = [];
        } elseif ($iso !== '' && preg_match('/^[a-z]{2}$/i', $iso)) {
            $spoken = EventLanguageService::normalizeCodes([$iso]);
        } elseif ($name !== '' || $nameInLang !== '') {
            $spoken = LanguageDictionary::resolveMultiple(trim($name . ' ' . $nameInLang));
        }

        // Case 7 fallback: PresentationMethodAndLanguage
        // e.g. "2D, Latviešu", "Premium 2D, Franču"
        if (empty($spoken) && !$isVarious) {
            $pres = $show['PresentationMethodAndLanguage'] ?? $show['presentation_method'] ?? null;
            if (is_string($pres) && str_contains($pres, ',')) {
                $parts = explode(',', $pres);
                $fromPres = LanguageDictionary::resolveMultiple(trim($parts[1] ?? ''));
                if (!empty($fromPres)) {
                    $spoken = $fromPres;
                }
            }
        }

        // Case 5 fallback: Check <Event> synopsis ONLY if <Show> spoken is empty and NOT "dažādas"
        if (empty($spoken) && !$isVarious && !empty($event)) {
            $synopsis = $event['Synopsis'] ?? $event['synopsis'] ?? $event['ShortSynopsis'] ?? $event['shortSynopsis'] ?? null;
            if (is_string($synopsis) && trim($synopsis) !== '') {
                // Only use synopsis spoken language if there is no ambiguous dubbing signal
                if (!EventLanguageTextAnalyzer::hasAmbiguousDubbingSignal($synopsis)) {
                    $textDetected = EventLanguageTextAnalyzer::analyze('', null, $synopsis);
                    if ($textDetected && !empty($textDetected->spokenLanguages)) {
                        $spoken = $textDetected->spokenLanguages;
                    }
                }
            }
        }

        // Subtitles extraction: ONLY from <Show> (critical rule: never overwrite or merge from Event synopsis)
        $rawSubs = [];
        foreach (['SubtitleLanguage1', 'SubtitleLanguage2', 'SubtitleLanguage3'] as $subKey) {
            if (isset($show[$subKey])) {
                $rawSubs[] = $show[$subKey];
            }
        }
        if (!empty($show['subtitles']) && is_array($show['subtitles'])) {
            $rawSubs = array_merge($rawSubs, $show['subtitles']);
        }
        if (!empty($show['subtitle_languages_raw']) && is_array($show['subtitle_languages_raw'])) {
            $rawSubs = array_merge($rawSubs, $show['subtitle_languages_raw']);
        }

        $subtitles = LanguageDictionary::resolveMultiple($rawSubs);

        // Case 6: Ballet recordings / live broadcasts in cinema
        if (empty($spoken) && empty($subtitles)) {
            $genres = (string) ($show['Genres'] ?? '') . ' ' . (string) ($event['Genres'] ?? $event['genres'] ?? '');
            $title = (string) ($show['Title'] ?? '') . ' ' . (string) ($event['Title'] ?? $event['title'] ?? '');
            if (preg_match('/\b(?:balets|ballet)\b/ui', $genres . ' ' . $title)) {
                // Ensure no lecture/tour exceptions apply
                if (!preg_match('/\b(?:lekcij\w*|sarun\w*|diskusij\w*|stāvizrād\w*)\b/ui', $title)) {
                    return new EventLanguageData(
                        mode: EventLanguageMode::LanguageFree,
                        spokenLanguages: [],
                        subtitleLanguages: [],
                        source: EventLanguageSource::Explicit,
                    );
                }
            }
        }

        return new EventLanguageData(
            mode: EventLanguageMode::Spoken,
            spokenLanguages: $spoken,
            subtitleLanguages: $subtitles,
            source: EventLanguageSource::Explicit,
        );
    }
}
