<?php

namespace App\Services;

use App\DTO\EventLanguageData;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Models\Event;

class EventLanguageService
{
    /**
     * Map of common ISO 639-2/B/T 3-letter language codes to ISO 639-1 2-letter codes.
     */
    private const ISO_639_2_TO_1 = [
        'lav' => 'lv',
        'lat' => 'lv',
        'eng' => 'en',
        'rus' => 'ru',
        'deu' => 'de',
        'ger' => 'de',
        'fra' => 'fr',
        'fre' => 'fr',
        'ita' => 'it',
        'spa' => 'es',
        'ukr' => 'uk',
        'lit' => 'lt',
        'est' => 'et',
        'pol' => 'pl',
        'fin' => 'fi',
        'swe' => 'sv',
        'nor' => 'no',
        'dan' => 'da',
        'nld' => 'nl',
        'dut' => 'nl',
        'por' => 'pt',
        'ces' => 'cs',
        'cze' => 'cs',
        'jpn' => 'ja',
        'kor' => 'ko',
        'zho' => 'zh',
        'chi' => 'zh',
    ];

    /**
     * Normalize an array of language codes to canonical ISO 639-1 (lowercase, sorted, deduplicated).
     *
     * @param array|null $codes
     * @return array<int, string>
     */
    public static function normalizeCodes(?array $codes): array
    {
        if ($codes === null) {
            return [];
        }

        $normalized = [];

        foreach ($codes as $rawCode) {
            if (!is_string($rawCode)) {
                continue;
            }

            $code = trim(mb_strtolower($rawCode, 'UTF-8'));
            if ($code === '') {
                continue;
            }

            // Strip region suffix (e.g. en-US -> en, lv_LV -> lv, ru-RU -> ru)
            if (preg_match('/^([a-z0-9]+)[-_]/', $code, $matches)) {
                $code = $matches[1];
            }

            // Map 3-letter code if present
            if (isset(self::ISO_639_2_TO_1[$code])) {
                $code = self::ISO_639_2_TO_1[$code];
            }

            // Strict ISO 639-1 2-letter validation: only lowercase a-z, exactly 2 letters
            // This filters out "none", "unknown", numbers, etc.
            if (preg_match('/^[a-z]{2}$/', $code)) {
                $normalized[] = $code;
            }
        }

        $unique = array_values(array_unique($normalized));
        sort($unique, SORT_STRING);

        return $unique;
    }

    /**
     * Enforce semantic consistency on an EventLanguageData DTO.
     */
    public static function normalizePayload(EventLanguageData $data): EventLanguageData
    {
        $spoken = self::normalizeCodes($data->spokenLanguages);
        $subtitles = self::normalizeCodes($data->subtitleLanguages);
        $mode = $data->mode ?? EventLanguageMode::Unknown;
        $source = $data->source;

        // Rule 1: If spoken or subtitle languages are present and mode is null/unknown -> mode = Spoken
        if ((!empty($spoken) || !empty($subtitles)) && $mode === EventLanguageMode::Unknown) {
            $mode = EventLanguageMode::Spoken;
        }

        // Rule 2: If mode is LanguageFree -> spokenLanguages and subtitleLanguages must be empty
        if ($mode === EventLanguageMode::LanguageFree) {
            $spoken = [];
            $subtitles = [];
        }

        // Rule 3: If mode is Unknown and arrays are empty -> source degrades to Unknown (unless Manual)
        if ($mode === EventLanguageMode::Unknown && empty($spoken) && empty($subtitles)) {
            if ($source !== EventLanguageSource::Manual) {
                $source = EventLanguageSource::Unknown;
            }
        }

        return new EventLanguageData(
            mode: $mode,
            spokenLanguages: $spoken,
            subtitleLanguages: $subtitles,
            source: $source,
        );
    }

    /**
     * Apply language data to an Event using the strict Data Fusion priority matrix:
     * Manual (40) > Explicit (30) > Inferred (20) > Unknown (10).
     *
     * Returns true only if at least one of the 4 columns was meaningfully modified.
     */
    public static function applyToEvent(Event $event, EventLanguageData $incoming): bool
    {
        $incoming = self::normalizePayload($incoming);

        // Unknown source never overwrites or deletes existing data
        if ($incoming->source === EventLanguageSource::Unknown) {
            return false;
        }

        $currentSource = $event->language_source instanceof EventLanguageSource
            ? $event->language_source
            : (EventLanguageSource::tryFrom((string) $event->language_source) ?? EventLanguageSource::Unknown);

        $currentMode = $event->language_mode instanceof EventLanguageMode
            ? $event->language_mode
            : (EventLanguageMode::tryFrom((string) $event->language_mode) ?? EventLanguageMode::Unknown);

        $currentSpoken = self::normalizeCodes($event->spoken_languages ?? []);
        $currentSubtitles = self::normalizeCodes($event->subtitle_languages ?? []);

        $targetMode = $currentMode;
        $targetSpoken = $currentSpoken;
        $targetSubtitles = $currentSubtitles;
        $targetSource = $currentSource;

        // Data Fusion Priority Matrix
        if ($currentSource === EventLanguageSource::Manual) {
            if ($incoming->source === EventLanguageSource::Manual) {
                // Overwrite fully
                $targetMode = $incoming->mode ?? EventLanguageMode::Unknown;
                $targetSpoken = $incoming->spokenLanguages ?? [];
                $targetSubtitles = $incoming->subtitleLanguages ?? [];
                $targetSource = EventLanguageSource::Manual;
            } else {
                // Ignore lower-priority sources
                return false;
            }
        } elseif ($currentSource === EventLanguageSource::Explicit) {
            if ($incoming->source->isAtLeast(EventLanguageSource::Explicit)) {
                // Overwrite fully (Manual or Explicit)
                $targetMode = $incoming->mode ?? EventLanguageMode::Unknown;
                $targetSpoken = $incoming->spokenLanguages ?? [];
                $targetSubtitles = $incoming->subtitleLanguages ?? [];
                $targetSource = $incoming->source;
            } else {
                // Ignore inferred or unknown
                return false;
            }
        } elseif ($currentSource === EventLanguageSource::Inferred) {
            if ($incoming->source->isHigherThan(EventLanguageSource::Inferred)) {
                // Upgrade to Manual or Explicit and overwrite fully
                $targetMode = $incoming->mode ?? EventLanguageMode::Unknown;
                $targetSpoken = $incoming->spokenLanguages ?? [];
                $targetSubtitles = $incoming->subtitleLanguages ?? [];
                $targetSource = $incoming->source;
            } elseif ($incoming->source === EventLanguageSource::Inferred) {
                // Enrich-only: fill missing fields without overwriting existing non-empty values
                $targetMode = ($currentMode === EventLanguageMode::Unknown && $incoming->mode !== EventLanguageMode::Unknown)
                    ? ($incoming->mode ?? EventLanguageMode::Unknown)
                    : $currentMode;

                $targetSpoken = empty($currentSpoken)
                    ? ($incoming->spokenLanguages ?? [])
                    : $currentSpoken;

                $targetSubtitles = empty($currentSubtitles)
                    ? ($incoming->subtitleLanguages ?? [])
                    : $currentSubtitles;

                $targetSource = EventLanguageSource::Inferred;

                // Re-enforce semantic consistency on enriched values
                if ($targetMode === EventLanguageMode::LanguageFree) {
                    $targetSpoken = [];
                    $targetSubtitles = [];
                } elseif ($targetMode === EventLanguageMode::Unknown && (!empty($targetSpoken) || !empty($targetSubtitles))) {
                    $targetMode = EventLanguageMode::Spoken;
                }
            } else {
                return false;
            }
        } else {
            // currentSource === Unknown: overwrite with incoming
            $targetMode = $incoming->mode ?? EventLanguageMode::Unknown;
            $targetSpoken = $incoming->spokenLanguages ?? [];
            $targetSubtitles = $incoming->subtitleLanguages ?? [];
            $targetSource = $incoming->source;
        }

        // Deterministic change comparison
        $isChanged = ($currentMode !== $targetMode)
            || ($currentSpoken !== $targetSpoken)
            || ($currentSubtitles !== $targetSubtitles)
            || ($currentSource !== $targetSource);

        if (!$isChanged) {
            return false;
        }

        $event->language_mode = $targetMode;
        $event->spoken_languages = $targetSpoken;
        $event->subtitle_languages = $targetSubtitles;
        $event->language_source = $targetSource;

        if ($event->exists) {
            $event->save();
        }

        return true;
    }

    /**
     * Merge language data from a duplicate event into canonical during consolidation.
     */
    public static function mergeFromDuplicate(Event $target, Event $duplicate): bool
    {
        $duplicateData = EventLanguageData::fromEvent($duplicate);

        // If duplicate has Unknown source, do nothing
        if ($duplicateData->source === EventLanguageSource::Unknown) {
            return false;
        }

        // If both target and duplicate are Manual, target retains its value
        $targetSource = $target->language_source instanceof EventLanguageSource
            ? $target->language_source
            : (EventLanguageSource::tryFrom((string) $target->language_source) ?? EventLanguageSource::Unknown);

        if ($duplicateData->source === EventLanguageSource::Manual && $targetSource === EventLanguageSource::Manual) {
            return false;
        }

        return self::applyToEvent($target, $duplicateData);
    }

    /**
     * Export canonical language structure for external consumers (e.g. API / Piedzivo.lv).
     *
     * Note: 'manual' source is exported as 'explicit' so downstream systems know it is
     * high-confidence confirmed data, while retaining their right to apply internal manual overrides.
     */
    public static function toPortableArray(Event $event): array
    {
        $mode = $event->language_mode instanceof EventLanguageMode
            ? $event->language_mode
            : (EventLanguageMode::tryFrom((string) $event->language_mode) ?? EventLanguageMode::Unknown);

        $source = $event->language_source instanceof EventLanguageSource
            ? $event->language_source
            : (EventLanguageSource::tryFrom((string) $event->language_source) ?? EventLanguageSource::Unknown);

        return [
            'mode' => $mode->value,
            'spoken' => self::normalizeCodes($event->spoken_languages ?? []),
            'subtitles' => self::normalizeCodes($event->subtitle_languages ?? []),
            'source' => ($source === EventLanguageSource::Manual)
                ? EventLanguageSource::Explicit->value
                : $source->value,
        ];
    }
}
