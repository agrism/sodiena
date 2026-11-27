<?php

namespace App\Services\IngestionV2;

use App\Console\Commands\ConsolidateCategoriesCommand;
use App\Enums\EventLanguageMode;
use App\Enums\EventLanguageSource;
use App\Enums\SourceType;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventTranslation;
use App\Models\OccurrenceSource;
use App\Models\Source;
use App\Services\EventLanguageService;
use App\DTO\EventLanguageData;
use App\Services\Language\EventLanguageDetector;
use App\Services\Scrapers\EventIngestionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CanonicalProjectionService
{
    /**
     * Known direct cinema slugs whose ticket URLs take absolute precedence.
     */
    public const DIRECT_CINEMA_SLUGS = [
        'apollo-kino',
        'forum-cinemas',
        'cinamon',
        'splendid-palace',
        'ksuns',
    ];

    public function __construct(
        protected ?EventLanguageDetector $languageDetector = null
    ) {
        $this->languageDetector = $languageDetector ?: app(EventLanguageDetector::class);
    }

    /**
     * Deterministically recalculate an EventOccurrence's fields strictly from its active sources.
     */
    public function projectOccurrence(EventOccurrence $occurrence): void
    {
        /** @var Collection<int, OccurrenceSource> $sources */
        $sources = $occurrence->activeSources()->with('source')->get();

        // 1. If no active sources remain
        if ($sources->isEmpty()) {
            if (in_array($occurrence->status, ['scheduled', 'postponed'], true)) {
                $occurrence->status = 'cancelled';
                $occurrence->cancelled_at = now();
                $occurrence->ticket_url = null;
                if ($occurrence->isDirty()) {
                    $occurrence->save();
                }
            }
            return;
        }

        // 2. If active sources exist and status was cancelled, restore to scheduled
        if ($occurrence->status === 'cancelled') {
            $occurrence->status = 'scheduled';
            $occurrence->cancelled_at = null;
        }

        // 3. Ticket URL & Prices
        // Sort sources descending by: direct cinema priority, ticketPriority, priority, last_seen_at
        $sortedByTicket = $sources->sort(function (OccurrenceSource $a, OccurrenceSource $b) {
            $aDirect = $this->isDirectCinemaSource($a->source?->slug);
            $bDirect = $this->isDirectCinemaSource($b->source?->slug);
            if ($aDirect !== $bDirect) {
                return $aDirect ? -1 : 1;
            }

            $aType = $a->source?->type instanceof SourceType ? $a->source->type : SourceType::OTHER;
            $bType = $b->source?->type instanceof SourceType ? $b->source->type : SourceType::OTHER;

            $aTicketPri = $aType->ticketPriority();
            $bTicketPri = $bType->ticketPriority();
            if ($aTicketPri !== $bTicketPri) {
                return $bTicketPri <=> $aTicketPri;
            }

            $aPri = $aType->priority();
            $bPri = $bType->priority();
            if ($aPri !== $bPri) {
                return $bPri <=> $aPri;
            }

            $aSeen = $a->last_seen_at ? $a->last_seen_at->getTimestamp() : 0;
            $bSeen = $b->last_seen_at ? $b->last_seen_at->getTimestamp() : 0;
            return $bSeen <=> $aSeen;
        });

        $bestTicketSource = $sortedByTicket->first(fn (OccurrenceSource $s) => !empty(trim((string) $s->ticket_url)));
        $occurrence->ticket_url = $bestTicketSource ? EventIngestionService::canonicalizeTicketUrl(trim((string) $bestTicketSource->ticket_url)) ?: trim((string) $bestTicketSource->ticket_url) : null;

        $bestPriceSource = $sortedByTicket->first(fn (OccurrenceSource $s) => $s->price_min !== null || $s->price_max !== null || $s->is_free !== null);
        if ($bestPriceSource) {
            $pMin = $bestPriceSource->price_min !== null ? (float) $bestPriceSource->price_min : null;
            $pMax = $bestPriceSource->price_max !== null ? (float) $bestPriceSource->price_max : null;

            if ($pMax !== null && $pMax <= 0) {
                $pMax = null;
            }
            if ($pMin !== null && ($pMin > 99999.99 || $pMin < 0)) {
                $pMin = null;
            }
            if ($pMax !== null && ($pMax > 99999.99 || $pMax < 0)) {
                $pMax = null;
            }
            if ($pMin !== null && $pMax !== null && $pMin > $pMax) {
                [$pMin, $pMax] = [$pMax, $pMin];
            }

            $occurrence->price_min = $pMin;
            $occurrence->price_max = $pMax;
            $occurrence->currency = $bestPriceSource->currency ?: 'EUR';
            $occurrence->is_free = ($pMin !== null && $pMin > 0) ? false : (bool) ($bestPriceSource->is_free ?? false);
        }

        // 4. Presentation format & Auditorium
        $sortedByPriority = $sources->sort(function (OccurrenceSource $a, OccurrenceSource $b) {
            $aType = $a->source?->type instanceof SourceType ? $a->source->type : SourceType::OTHER;
            $bType = $b->source?->type instanceof SourceType ? $b->source->type : SourceType::OTHER;
            $diff = $bType->priority() <=> $aType->priority();
            if ($diff !== 0) return $diff;
            return ($b->last_seen_at?->getTimestamp() ?? 0) <=> ($a->last_seen_at?->getTimestamp() ?? 0);
        });

        $bestFormatSource = $sortedByPriority->first(function (OccurrenceSource $s) {
            $raw = $s->raw_payload['presentation_method'] ?? $s->raw_payload['cinema']['presentation_method'] ?? null;
            return !empty($raw) && is_string($raw);
        });
        if ($bestFormatSource) {
            $occurrence->presentation_format = $this->normalizePresentationFormat(
                $bestFormatSource->raw_payload['presentation_method'] ?? $bestFormatSource->raw_payload['cinema']['presentation_method']
            );
        }

        $bestAuditoriumSource = $sortedByPriority->first(function (OccurrenceSource $s) {
            $raw = $s->raw_payload['cinema']['auditorium'] ?? $s->raw_payload['auditorium'] ?? null;
            return !empty($raw) && is_string($raw);
        });
        if ($bestAuditoriumSource) {
            $occurrence->auditorium = mb_substr(trim((string) ($bestAuditoriumSource->raw_payload['cinema']['auditorium'] ?? $bestAuditoriumSource->raw_payload['auditorium'])), 0, 120, 'UTF-8');
        }

        // 5. Languages at occurrence level
        if ($occurrence->language_source !== 'manual') {
            $explicitLangSource = $sources->first(function (OccurrenceSource $s) {
                $lang = $s->raw_payload['language'] ?? null;
                return is_array($lang) && ($lang['source'] ?? '') === 'explicit';
            });

            if ($explicitLangSource) {
                $langData = EventLanguageData::fromScraperArray($explicitLangSource->raw_payload['language']);
                if ($langData) {
                    $occurrence->spoken_languages = $langData->spokenLanguages;
                    $occurrence->subtitle_languages = $langData->subtitleLanguages;
                    $occurrence->language_source = 'explicit';
                }
            } else {
                // Null indicates inheritance from canonical Event
                $occurrence->spoken_languages = null;
                $occurrence->subtitle_languages = null;
                $occurrence->language_source = null;
            }
        }

        // 6. Recalculate occurrence_fingerprint
        $startKey = $occurrence->all_day
            ? $occurrence->start_at->format('Y-m-d')
            : $occurrence->start_at->format('Y-m-d H:i');
        $normFmt = mb_strtolower(trim((string) $occurrence->presentation_format), 'UTF-8');
        $normAud = mb_strtolower(trim((string) $occurrence->auditorium), 'UTF-8');
        $occurrence->occurrence_fingerprint = hash('sha256', "{$occurrence->event_id}|{$startKey}|{$normFmt}|{$normAud}");

        if ($occurrence->isDirty()) {
            $occurrence->save();
        }
    }

    /**
     * Deterministically recalculate an Event's canonical fields from its occurrences and active sources.
     */
    public function projectEvent(Event $event, bool $preserveExistingTranslations = false): void
    {
        /** @var Collection<int, EventOccurrence> $occurrences */
        $occurrences = $event->occurrences()
            ->whereNotIn('status', ['merged', 'deleted'])
            ->with(['activeSources.source', 'sources.source'])
            ->get();

        $activeOccurrences = $occurrences->filter(fn (EventOccurrence $o) => in_array($o->status, ['scheduled', 'postponed'], true));

        // 1. Collect all active sources across occurrences
        /** @var Collection<int, OccurrenceSource> $allActiveSources */
        $allActiveSources = $activeOccurrences->flatMap(fn (EventOccurrence $o) => $o->activeSources);
        if ($allActiveSources->isEmpty()) {
            $allActiveSources = $occurrences->flatMap(fn (EventOccurrence $o) => $o->sources);
        }

        $city = $event->location?->city;
        $isCinema = EventIngestionService::isCinemaEvent($event->title, $event->location?->name, $event->categories->pluck('name')->all());

        // 2. Atomic title + description winner selection
        if ($allActiveSources->isNotEmpty()) {
            $uniqueSourcesBySourceId = $allActiveSources->groupBy('source_id')->map(function (Collection $group) {
                // Sort within group by newest last_seen_at
                return $group->sortByDesc(fn (OccurrenceSource $s) => $s->last_seen_at?->getTimestamp() ?? 0)->first();
            });

            // Sort candidate winner sources:
            // 1. Highest priority()
            // 2. Non-empty, non-contaminated description
            // 3. Latvian language title preference over Cyrillic / English
            // 4. Newest last_seen_at
            $sortedWinners = $uniqueSourcesBySourceId->sort(function (OccurrenceSource $a, OccurrenceSource $b) use ($city) {
                $aType = $a->source?->type instanceof SourceType ? $a->source->type : SourceType::OTHER;
                $bType = $b->source?->type instanceof SourceType ? $b->source->type : SourceType::OTHER;

                $priDiff = $bType->priority() <=> $aType->priority();
                if ($priDiff !== 0) return $priDiff;

                $aDescClean = !empty($a->raw_description) && !EventIngestionService::isDescriptionContaminated($a->raw_description, $city);
                $bDescClean = !empty($b->raw_description) && !EventIngestionService::isDescriptionContaminated($b->raw_description, $city);
                if ($aDescClean !== $bDescClean) {
                    return $bDescClean ? 1 : -1;
                }

                $aIsLv = !preg_match('/\p{Cyrillic}/u', (string) $a->raw_title) && !preg_match('/^(the|a|an)\s+/i', (string) $a->raw_title);
                $bIsLv = !preg_match('/\p{Cyrillic}/u', (string) $b->raw_title) && !preg_match('/^(the|a|an)\s+/i', (string) $b->raw_title);
                if ($aIsLv !== $bIsLv) {
                    return $bIsLv ? 1 : -1;
                }

                return ($b->last_seen_at?->getTimestamp() ?? 0) <=> ($a->last_seen_at?->getTimestamp() ?? 0);
            });

            /** @var OccurrenceSource $winner */
            $winner = $sortedWinners->first();

            $event->primary_content_source_id = $winner->source_id;
            $newTitle = $this->cleanCanonicalTitle($winner->raw_title, $isCinema);
            $newDescription = $winner->raw_description;

            // Fallback: only if winner description is completely empty, take description from next source IF title stems match >= 60%
            if (empty(trim((string) $newDescription))) {
                $winnerStems = EventIngestionService::getLatvianWordStems($newTitle);
                $nextWithDesc = $sortedWinners->skip(1)->first(function (OccurrenceSource $cand) use ($winnerStems, $city) {
                    if (empty(trim((string) $cand->raw_description))) return false;
                    if (EventIngestionService::isDescriptionContaminated($cand->raw_description, $city)) return false;
                    $candStems = EventIngestionService::getLatvianWordStems((string) $cand->raw_title);
                    if (empty($winnerStems) || empty($candStems)) return false;
                    $overlap = count(array_intersect($winnerStems, $candStems)) / max(count($winnerStems), count($candStems));
                    return $overlap >= 0.60;
                });

                if ($nextWithDesc) {
                    $newDescription = $nextWithDesc->raw_description;
                }
            }

            if (!empty($newTitle)) {
                $newTitle = mb_substr(trim($newTitle), 0, 255, 'UTF-8');
                $event->title = $newTitle;
            }
            if ($newDescription !== null) {
                $event->description = $newDescription;
                $event->short_description = Str::limit(strip_tags($newDescription), 160);
            }

            // Sync Latvian translation in event_translations directly with new winner data
            if (!empty($newTitle)) {
                $existingLv = EventTranslation::where('event_id', $event->id)->where('locale', 'lv')->first();
                $slugToUse = ($preserveExistingTranslations && $existingLv?->slug)
                    ? $existingLv->slug
                    : ($existingLv?->slug ?: Str::slug($newTitle) . '-' . substr(md5($event->id . 'lv'), 0, 6));

                EventTranslation::updateOrCreate(
                    [
                        'event_id' => $event->id,
                        'locale' => 'lv',
                    ],
                    [
                        'title' => $newTitle,
                        'slug' => $slugToUse,
                        'description' => $newDescription,
                        'short_description' => $event->short_description ?: (mb_strlen((string) $newDescription) <= 220 ? $newDescription : Str::limit(strip_tags((string) $newDescription), 160)),
                    ]
                );
                $event->unsetRelation('translations');
            }

            // 3. Image (real image over placeholder)
            $bestImageSource = $allActiveSources->sort(function (OccurrenceSource $a, OccurrenceSource $b) {
                $aGen = EventIngestionService::isGenericPlaceholderImage($a->raw_image_url);
                $bGen = EventIngestionService::isGenericPlaceholderImage($b->raw_image_url);
                if ($aGen !== $bGen) {
                    return $aGen ? 1 : -1;
                }
                $aType = $a->source?->type instanceof SourceType ? $a->source->type : SourceType::OTHER;
                $bType = $b->source?->type instanceof SourceType ? $b->source->type : SourceType::OTHER;
                return $bType->priority() <=> $aType->priority();
            })->first(fn (OccurrenceSource $s) => !empty(trim((string) $s->raw_image_url)));

            if ($bestImageSource) {
                $newImageUrl = trim((string) $bestImageSource->raw_image_url);
                if ($newImageUrl !== $event->image_url) {
                    $event->image_url = $newImageUrl;
                    $event->internal_image_url = null;
                }
            }

            // 4. Raw categories (unique output array from active occurrence sources)
            $uniqueRawCategories = [];
            foreach ($allActiveSources as $src) {
                if (!empty($src->raw_categories) && is_array($src->raw_categories)) {
                    foreach ($src->raw_categories as $rc) {
                        $tr = trim((string) $rc);
                        if (!empty($tr)) {
                            $uniqueRawCategories[$tr] = true;
                        }
                    }
                }
            }
            $sortedCategories = array_keys($uniqueRawCategories);
            sort($sortedCategories, SORT_STRING | SORT_FLAG_CASE);
            $event->raw_categories = !empty($sortedCategories) ? $sortedCategories : null;

            $this->syncEventCategories($event, $sortedCategories);
        }

        // 5. Schedule Rollup & Transitional Mirroring
        $upcoming = $activeOccurrences->filter(function (EventOccurrence $o) {
            $end = $o->end_at ?: $o->start_at;
            return $end && $end >= now();
        });

        $event->occurrences_count = $upcoming->count();

        $nextStart = $upcoming->min('start_at') ?? $occurrences->whereNotIn('status', ['cancelled'])->min('start_at');
        $lastEnd = $upcoming->max(fn (EventOccurrence $o) => $o->end_at ?: $o->start_at) ?? $occurrences->whereNotIn('status', ['cancelled'])->max(fn (EventOccurrence $o) => $o->end_at ?: $o->start_at);

        $event->next_start_at = $nextStart;
        $event->last_end_at = $lastEnd;

        // Transitional mirroring to legacy start_at and end_at
        if ($nextStart) {
            $event->start_at = $nextStart;
        }
        if ($lastEnd) {
            $event->end_at = $lastEnd;
        }

        // 6. Nearest active occurrence ticket info and clean raw_data['ticket_links']
        $nearestUpcoming = $upcoming->sortBy('start_at')->first() ?? $activeOccurrences->sortBy('start_at')->first();
        if ($nearestUpcoming) {
            $event->ticket_url = $nearestUpcoming->ticket_url;
            $event->price_min = $nearestUpcoming->price_min;
            $event->price_max = $nearestUpcoming->price_max;
            $event->is_free = $nearestUpcoming->is_free;
            $event->currency = $nearestUpcoming->currency ?: 'EUR';
        }

        // Rebuild clean raw_data['ticket_links'] ONLY from current active occurrence sources
        $activeTicketLinks = [];
        foreach ($allActiveSources as $as) {
            $url = trim((string) $as->ticket_url);
            if (!empty($url) && !isset($activeTicketLinks[$url])) {
                $providerName = $as->provider_name ?? \App\Services\Tickets\TicketProviderResolver::resolve(
                    $url,
                    $as->source?->slug,
                    $as->source?->name
                );
                $activeTicketLinks[$url] = [
                    'url' => $url,
                    'title' => $providerName ?: 'Biļetes',
                    'provider_name' => $providerName,
                ];
            }
        }
        $rawData = is_array($event->raw_data) ? $event->raw_data : [];
        if (!empty($activeTicketLinks)) {
            $rawData['ticket_links'] = array_values($activeTicketLinks);
        } else {
            unset($rawData['ticket_links']);
        }
        $event->raw_data = !empty($rawData) ? $rawData : null;

        // 7. Event status rollup
        if (!in_array($event->status, ['draft', 'archived', 'merged', 'deleted'], true)) {
            if ($occurrences->isNotEmpty() && $occurrences->every(fn (EventOccurrence $o) => $o->status === 'cancelled')) {
                $event->status = 'cancelled';
            } elseif ($event->status === 'cancelled' && $activeOccurrences->isNotEmpty()) {
                $event->status = 'published';
            }
        }

        // 8. Language rollup
        if ($event->language_source !== EventLanguageSource::Manual) {
            $explicitOccs = $activeOccurrences->filter(fn (EventOccurrence $o) => $o->language_source === 'explicit');
            if ($explicitOccs->isNotEmpty()) {
                $spokenUnion = [];
                $subtitleUnion = [];
                foreach ($explicitOccs as $eo) {
                    if (is_array($eo->spoken_languages)) {
                        foreach ($eo->spoken_languages as $sl) $spokenUnion[$sl] = true;
                    }
                    if (is_array($eo->subtitle_languages)) {
                        foreach ($eo->subtitle_languages as $subl) $subtitleUnion[$subl] = true;
                    }
                }
                $event->spoken_languages = array_keys($spokenUnion);
                $event->subtitle_languages = array_keys($subtitleUnion);
                $event->language_mode = EventLanguageMode::Spoken;
                $event->language_source = EventLanguageSource::Explicit;
            } elseif (!$preserveExistingTranslations) {
                $detected = $this->languageDetector->detectForEvent($event);
                if ($detected) {
                    EventLanguageService::applyToEvent($event, $detected);
                }
            }
        }

        // 9. Canonical content_hash & smart updated_at saving
        $occurrencesSummary = $occurrences->map(function (EventOccurrence $o) {
            return [
                'id' => $o->id,
                'status' => $o->status,
                'start_at' => $o->start_at?->format('Y-m-d H:i:s'),
                'end_at' => $o->end_at?->format('Y-m-d H:i:s'),
                'ticket_url' => $o->ticket_url,
                'price_min' => $o->price_min,
                'price_max' => $o->price_max,
                'sources' => $o->activeSources->pluck('source_external_id')->sort()->values()->all(),
            ];
        })->sortBy('id')->values()->all();

        $hashPayload = [
            'title' => $event->title,
            'description' => $event->description,
            'location_id' => $event->location_id,
            'primary_content_source_id' => $event->primary_content_source_id,
            'image_url' => $event->image_url,
            'ticket_url' => $event->ticket_url,
            'price_min' => $event->price_min,
            'price_max' => $event->price_max,
            'is_free' => $event->is_free,
            'status' => $event->status,
            'language_mode' => $event->language_mode instanceof EventLanguageMode ? $event->language_mode->value : (string) $event->language_mode,
            'spoken_languages' => $event->spoken_languages,
            'subtitle_languages' => $event->subtitle_languages,
            'raw_categories' => $event->raw_categories,
            'occurrences' => $occurrencesSummary,
        ];

        $newContentHash = hash('sha256', (string) json_encode($hashPayload));

        if ($event->content_hash === $newContentHash && !$event->isDirty(['status', 'next_start_at', 'last_end_at', 'occurrences_count'])) {
            // Nothing changed structurally; do not touch updated_at
            return;
        }

        $event->content_hash = $newContentHash;
        $event->save();
    }

    /**
     * Clean cinema titles from technical formats and language suffixes.
     */
    public function cleanCanonicalTitle(?string $title, bool $isCinema = false): string
    {
        $raw = trim((string) $title);
        if (!$isCinema || empty($raw)) {
            return $raw;
        }

        $cleaned = $raw;

        do {
            $before = $cleaned;
            // 1. Strip trailing language notes in parentheses or standalone
            $cleaned = preg_replace('/\s*\(\s*(?:lv|en|ru|lat|eng|rus|dub|sub)\s*\)\s*$/ui', '', $cleaned);
            $cleaned = preg_replace('/\s*(?:\(|\s)*(?:latviešu|krievu|angļu)\s*valodā\)?\s*$/ui', '', $cleaned);
            $cleaned = preg_replace('/\s*(?:\(|\s)*(?:ar\s+subtitriem|ar\s+titriem|bez\s+subtitriem)[^)]*\)?\s*$/ui', '', $cleaned);

            // 2. Strip trailing formats: IMAX 2D, IMAX 3D, IMAX, 2D, 3D, LUX, LUXE, Dolby Atmos
            $cleaned = preg_replace('/\s*(?:\(|\s)+(?:IMAX\s*3D|IMAX\s*2D|IMAX|3D|2D|LUXE?|Dolby\s*Atmos)\)?\s*$/ui', '', $cleaned);

            // 3. Strip trailing delimiters (UTF-8 safe regex)
            $cleaned = preg_replace('/^[\s\-–—:;,.|«»„“”•]+|[\s\-–—:;,.|«»„“”•]+$/u', '', $cleaned);
        } while ($cleaned !== $before);

        return !empty($cleaned) ? $cleaned : $raw;
    }

    public function syncEventCategories(Event $event, array $rawCategoryNames): void
    {
        $categoryIds = [];
        $title = (string) $event->title;
        $desc = (string) $event->description;
        $venue = (string) ($event->location?->name ?? '');

        $smartSlug = ConsolidateCategoriesCommand::inferFromContentAndVenue(
            $title,
            $desc,
            $venue,
            $rawCategoryNames
        );

        if ($smartSlug && $smartSlug !== 'citi') {
            $cat = $this->resolveCanonicalCategory($smartSlug);
            if ($cat) $categoryIds[] = $cat->id;
        }

        foreach ($rawCategoryNames as $catName) {
            if (empty(trim((string) $catName))) continue;
            $cat = $this->resolveCanonicalCategory((string) $catName);
            if ($cat) $categoryIds[] = $cat->id;
        }

        if (empty($categoryIds)) {
            $cat = $this->resolveCanonicalCategory($title);
            if ($cat) $categoryIds[] = $cat->id;
        }

        // Strict cinema check
        $venueLower = mb_strtolower($venue, 'UTF-8');
        $titleLower = mb_strtolower($title, 'UTF-8');
        $descLower = mb_strtolower($desc, 'UTF-8');
        $fullText = $titleLower . ' ' . $venueLower . ' ' . $descLower;
        $cleanFullText = preg_replace('/biļete uz [^.\n]*filmu jāiegādājas[^\n.]*/ui', '', $fullText);

        $isStrictCinema = (
            $smartSlug === 'kino' ||
            str_contains($venueLower, 'k.suns') || str_contains($venueLower, 'k suns') || str_contains($venueLower, 'ksuns') ||
            str_contains($venueLower, 'k. suns') || str_contains($venueLower, 'k.  suns') ||
            str_contains($venueLower, 'forum cinema') || str_contains($venueLower, 'forumcinemas') ||
            str_contains($venueLower, 'kino rio') || str_contains($venueLower, 'kinorio') ||
            str_contains($venueLower, 'kinoteātr') || str_contains($venueLower, 'kinoteatr') ||
            str_contains($venueLower, 'apollo kino') || str_contains($venueLower, 'cinamon') ||
            str_contains($venueLower, 'kino bize') || str_contains($venueLower, 'kino lora') ||
            str_contains($venueLower, 'kino gaisma') || str_contains($venueLower, 'splendid palace') ||
            str_contains($titleLower, 'k.suns') || str_contains($titleLower, 'forum cinema') || str_contains($titleLower, 'kino rio') ||
            str_contains($titleLower, 'kino lora') ||
            str_contains($cleanFullText, 'filma') || str_contains($cleanFullText, 'filmas') || str_contains($cleanFullText, 'filmu') ||
            str_contains($cleanFullText, 'filmā') || str_contains($cleanFullText, 'kinoseans') || str_contains($cleanFullText, 'filmas seans') ||
            str_contains($cleanFullText, 'spēlfilma') || str_contains($cleanFullText, 'dokumentālā filma') ||
            str_contains($cleanFullText, 'animācijas filma') || str_contains($cleanFullText, 'kinofestivāl')
        );

        if ($isStrictCinema) {
            $teatrisCat = $this->resolveCanonicalCategory('teatris');
            $izstadesCat = $this->resolveCanonicalCategory('izstades');
            $sportsCat = $this->resolveCanonicalCategory('sports');
            $kinoCat = $this->resolveCanonicalCategory('kino');

            $invalidForCinema = array_filter([$teatrisCat?->id, $izstadesCat?->id, $sportsCat?->id]);
            if (!empty($invalidForCinema)) {
                $categoryIds = array_diff($categoryIds, $invalidForCinema);
            }
            if ($kinoCat) {
                $categoryIds = array_values(array_unique(array_merge([$kinoCat->id], $categoryIds)));
            }
        }

        if (empty($categoryIds)) {
            $citiCat = $this->resolveCanonicalCategory('citi');
            if ($citiCat) $categoryIds[] = $citiCat->id;
        }

        $event->categories()->sync(array_values(array_unique($categoryIds)));
    }

    public function resolveCanonicalCategory(string $rawNameOrKeyword): ?Category
    {
        $canonicalSlug = ConsolidateCategoriesCommand::mapToCanonicalSlug($rawNameOrKeyword);
        $category = Category::where('slug', $canonicalSlug)->first();

        if (!$category) {
            $data = ConsolidateCategoriesCommand::CANONICAL_CATEGORIES[$canonicalSlug] ?? ConsolidateCategoriesCommand::CANONICAL_CATEGORIES['citi'];
            $category = Category::firstOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'icon' => $data['icon'],
                    'color' => $data['color'],
                    'description' => $data['description'],
                    'order' => $data['order'],
                ]
            );

            foreach ($data['translations'] as $loc => $transName) {
                CategoryTranslation::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'locale' => $loc,
                    ],
                    [
                        'name' => $transName,
                    ]
                );
            }
        }

        return $category;
    }

    protected function isDirectCinemaSource(?string $slug): bool
    {
        if (empty($slug)) return false;
        return in_array($slug, self::DIRECT_CINEMA_SLUGS, true) || str_contains($slug, 'kino');
    }

    protected function normalizePresentationFormat(mixed $raw): ?string
    {
        if (empty($raw) || !is_string($raw)) return null;
        $raw = trim($raw);
        $upper = mb_strtoupper($raw, 'UTF-8');

        if (str_contains($upper, 'IMAX') && str_contains($upper, '3D')) return 'IMAX 3D';
        if (str_contains($upper, 'IMAX')) return 'IMAX 2D';
        if (str_contains($upper, '3D')) return '3D';
        if (str_contains($upper, 'LUX') || str_contains($upper, 'LUXE')) return 'LUX';
        if (str_contains($upper, 'ATMOS')) return 'Dolby Atmos';
        if (str_contains($upper, '2D')) return '2D';

        return $raw;
    }
}
