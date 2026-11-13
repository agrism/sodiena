<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventTranslation;
use App\Services\Scrapers\EventIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsolidateEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:consolidate 
                            {--dry-run : Only show what would be consolidated without making changes}
                            {--days=30 : How many days forward to scan for duplicates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and consolidate duplicate sports matches and generic league event entries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $days = (int) $this->option('days');
        $today = now()->startOfDay();
        $this->info("Scanning events for duplicates and consolidation (window: today to +{$days} days)..." . ($dryRun ? ' [DRY RUN]' : ''));

        $events = Event::with(['translations', 'location', 'categories', 'source'])
            ->whereNull('deleted_at')
            ->where(function ($q) use ($today) {
                $q->where('start_at', '>=', $today)
                  ->orWhere(function ($sub) use ($today) {
                      $sub->whereNotNull('end_at')->where('end_at', '>=', $today);
                  });
            })
            ->where('start_at', '<=', now()->addDays($days)->endOfDay())
            ->orderBy('start_at')
            ->get();

        $this->info("Found {$events->count()} candidate events to evaluate.");

        $groupedByDate = $events->groupBy(fn ($e) => $e->start_at ? $e->start_at->toDateString() : 'none');

        $mergedCount = 0;
        $deletedIds = [];

        foreach ($groupedByDate as $date => $dayEvents) {
            if ($date === 'none' || $dayEvents->count() < 2) {
                continue;
            }

            $list = $dayEvents->values()->all();

            for ($i = 0; $i < count($list); $i++) {
                $eventA = $list[$i];
                if (in_array($eventA->id, $deletedIds, true)) {
                    continue;
                }

                for ($j = $i + 1; $j < count($list); $j++) {
                    $eventB = $list[$j];
                    if (in_array($eventB->id, $deletedIds, true)) {
                        continue;
                    }

                    if ($this->shouldMerge($eventA, $eventB)) {
                        [$canonical, $duplicate] = $this->pickCanonical($eventA, $eventB);

                        $this->line("  🔄 Merging [{$duplicate->id}] \"{$duplicate->title}\" into canonical [{$canonical->id}] \"{$canonical->title}\" on {$date}");

                        if (!$dryRun) {
                            $this->consolidate($canonical, $duplicate);
                        }

                        $deletedIds[] = $duplicate->id;
                        $mergedCount++;

                        if ($eventA->id === $duplicate->id) {
                            break;
                        }
                    }
                }
            }
        }

        $this->info("Consolidation scan complete. Consolidated: {$mergedCount} duplicate events." . ($dryRun ? ' (dry run)' : ''));

        return Command::SUCCESS;
    }

    /**
     * Determine if two events on the same date represent the same real-world event.
     */
    protected function shouldMerge(Event $a, Event $b): bool
    {
        // 1. Same source with different external IDs are never duplicates
        if ($a->source_id && $b->source_id && $a->source_id === $b->source_id && $a->source_external_id && $b->source_external_id && $a->source_external_id !== $b->source_external_id) {
            return false;
        }

        // 1.5 Package modifier guard (e.g. VIP lounge vs general admission)
        $cleanTitleA = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($a->title, 'UTF-8'));
        $cleanTitleB = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($b->title, 'UTF-8'));
        $distinguishingModifiers = ['vip', 'premium', 'biznesa', 'enbl', 'standart'];
        foreach ($distinguishingModifiers as $mod) {
            if (str_contains($cleanTitleA, $mod) !== str_contains($cleanTitleB, $mod)) {
                return false;
            }
        }

        // 2. City mismatch guard: If both candidate and dto have known cities and they differ, they are distinct events
        $cityA = mb_strtolower(trim($a->location?->city ?? ''), 'UTF-8');
        $cityB = mb_strtolower(trim($b->location?->city ?? ''), 'UTF-8');
        if (!empty($cityA) && !empty($cityB) && $cityA !== $cityB && !str_contains($cityA, $cityB) && !str_contains($cityB, $cityA)) {
            return false;
        }

        $sameLocation = $a->location_id && $b->location_id && $a->location_id === $b->location_id;
        $venueA = trim($a->location?->name ?? '');
        $venueB = trim($b->location?->name ?? '');
        $venuesEquivalent = empty($venueA) || empty($venueB) || $sameLocation || EventIngestionService::areVenuesEquivalent($venueA, $venueB);

        $ticketingEquivalent = (!empty($a->ticket_url) && !empty($b->ticket_url) && EventIngestionService::areTicketingUrlsEquivalent($a->ticket_url, $b->ticket_url));

        if (!$sameLocation && !$venuesEquivalent && !$ticketingEquivalent) {
            return false;
        }

        // 3. Start time check: if both have specific non-00:00 times, difference must be <= 45 minutes (allow 75m for same location with >= 90% title similarity)
        if ($a->start_at && $b->start_at) {
            $tA = $a->start_at->format('H:i');
            $tB = $b->start_at->format('H:i');
            if ($tA !== '00:00' && $tB !== '00:00' && $tA !== $tB) {
                $minA = $a->start_at->hour * 60 + $a->start_at->minute;
                $minB = $b->start_at->hour * 60 + $b->start_at->minute;
                $diff = abs($minA - $minB);
                if ($diff > 45) {
                    if ($diff > 75 || !$sameLocation) {
                        return false;
                    }
                    similar_text(mb_strtolower($a->title), mb_strtolower($b->title), $timeSim);
                    if ($timeSim < 90) {
                        return false;
                    }
                }
            }
        }

        // 4. Ticketing URL / Platform equivalence check
        if (!empty($a->ticket_url) && !empty($b->ticket_url)) {
            if (EventIngestionService::areTicketingUrlsEquivalent($a->ticket_url, $b->ticket_url)) {
                return true;
            }
        }

        // 5. Cinema session equivalence check (same cinema venue within 15 min)
        $aIsCinema = EventIngestionService::isCinemaEvent($a->title, $a->location?->name, $a->categories->pluck('name')->all());
        $bIsCinema = EventIngestionService::isCinemaEvent($b->title, $b->location?->name, $b->categories->pluck('name')->all());
        if ($aIsCinema && $bIsCinema) {
            $aVenue = mb_strtolower(trim($a->location?->name ?? ''), 'UTF-8');
            $bVenue = mb_strtolower(trim($b->location?->name ?? ''), 'UTF-8');
            $sameVenue = ($sameLocation || (!empty($aVenue) && !empty($bVenue) && ($aVenue === $bVenue || str_contains($aVenue, $bVenue) || str_contains($bVenue, $aVenue))));
            if ($sameVenue && $a->start_at && $b->start_at) {
                $minA = $a->start_at->hour * 60 + $a->start_at->minute;
                $minB = $b->start_at->hour * 60 + $b->start_at->minute;
                if (abs($minA - $minB) <= 15) {
                    if (EventIngestionService::areTicketingUrlsEquivalent($a->ticket_url, $b->ticket_url) || EventIngestionService::doCinemaTitlesMatch($a, $b)) {
                        return true;
                    }
                }
            }
        }

        // 6. Matchup teams equivalence check
        $teamsA = EventIngestionService::extractMatchupTeams($a->title);
        $teamsB = EventIngestionService::extractMatchupTeams($b->title);

        if ($teamsA && $teamsB && EventIngestionService::areMatchupTeamsEquivalent($teamsA, $teamsB)) {
            if ($sameLocation || empty($cityA) || empty($cityB) || $cityA === $cityB) {
                return true;
            }
        }

        // 7. One is generic league title and the other is a matchup title at same location
        $aIsLeague = EventIngestionService::isLeagueOrTournamentTitle($a->title);
        $bIsLeague = EventIngestionService::isLeagueOrTournamentTitle($b->title);

        if (($aIsLeague || $bIsLeague) && ($teamsA || $teamsB) && $sameLocation) {
            $matchupTeams = $teamsA ?: $teamsB;
            $allWords = array_merge($matchupTeams[0], $matchupTeams[1]);
            $leagueText = mb_strtolower(($aIsLeague ? $a->title . ' ' . $a->description : $b->title . ' ' . $b->description) ?? '', 'UTF-8');

            foreach ($allWords as $w) {
                if (mb_strlen($w, 'UTF-8') >= 3 && str_contains($leagueText, $w)) {
                    return true;
                }
            }

            if ($a->start_at && $b->start_at && $a->start_at->format('H:i') === $b->start_at->format('H:i') && $a->start_at->format('H:i') !== '00:00') {
                return true;
            }
        }

        // 8. Matchup teams in description check
        if ($teamsA && !empty($b->description) && $sameLocation) {
            $bDesc = mb_strtolower($b->description, 'UTF-8');
            $has1 = false;
            foreach ($teamsA[0] as $w) {
                if (mb_strlen($w, 'UTF-8') >= 3 && str_contains($bDesc, $w)) { $has1 = true; break; }
            }
            $has2 = false;
            foreach ($teamsA[1] as $w) {
                if (mb_strlen($w, 'UTF-8') >= 3 && str_contains($bDesc, $w)) { $has2 = true; break; }
            }
            if ($has1 && $has2) {
                return true;
            }
        }

        if ($teamsB && !empty($a->description) && $sameLocation) {
            $aDesc = mb_strtolower($a->description, 'UTF-8');
            $has1 = false;
            foreach ($teamsB[0] as $w) {
                if (mb_strlen($w, 'UTF-8') >= 3 && str_contains($aDesc, $w)) { $has1 = true; break; }
            }
            $has2 = false;
            foreach ($teamsB[1] as $w) {
                if (mb_strlen($w, 'UTF-8') >= 3 && str_contains($aDesc, $w)) { $has2 = true; break; }
            }
            if ($has1 && $has2) {
                return true;
            }
        }

        // 9. Cross-language / Translations title similarity
        $allTitlesA = array_filter(array_merge([$a->title], $a->translations ? $a->translations->pluck('title')->all() : []));
        $allTitlesB = array_filter(array_merge([$b->title], $b->translations ? $b->translations->pluck('title')->all() : []));
        foreach ($allTitlesA as $tA) {
            $cleanA = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($tA, 'UTF-8'));
            foreach ($allTitlesB as $tB) {
                $cleanB = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($tB, 'UTF-8'));
                similar_text($cleanA, $cleanB, $simPct);
                if ($simPct >= 75 && ($sameLocation || $venuesEquivalent)) {
                    return true;
                }
            }
        }

        // 10. Delimited sub-title / Quoted artwork title equivalence (e.g. "Jūtu impērija")
        $segA = EventIngestionService::extractTitleSegments($a->title);
        $segB = EventIngestionService::extractTitleSegments($b->title);
        foreach ($segA as $sA) {
            if (mb_strlen($sA, 'UTF-8') < 5) continue;
            foreach ($segB as $sB) {
                $matched = false;
                if ($sA === $sB) {
                    $matched = true;
                } else {
                    similar_text($sA, $sB, $segSim);
                    if ($segSim >= 85) {
                        $matched = true;
                    } else {
                        $wordsA = count(explode(' ', trim($sA)));
                        $wordsB = count(explode(' ', trim($sB)));
                        if ($wordsA >= 2 && $wordsB >= 2 && (mb_strlen($sA, 'UTF-8') >= 8 || mb_strlen($sB, 'UTF-8') >= 8)) {
                            if (str_contains($sA, $sB) || str_contains($sB, $sA)) {
                                $matched = true;
                            }
                        }
                    }
                }
                if ($matched && ($sameLocation || $venuesEquivalent)) {
                    return true;
                }
            }
        }

        // 11. Direct high title similarity
        $cleanA = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($a->title, 'UTF-8'));
        $cleanB = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($b->title, 'UTF-8'));
        similar_text($cleanA, $cleanB, $pct);
        if ($pct >= 75 && ($sameLocation || $venuesEquivalent)) {
            return true;
        }

        // 12. Stemmed word overlap (Latvian declension-aware)
        $stemsA = EventIngestionService::getLatvianWordStems($a->title);
        $stemsB = EventIngestionService::getLatvianWordStems($b->title);
        $commonStems = array_intersect($stemsA, $stemsB);
        if (count($commonStems) >= 2 && ($sameLocation || $venuesEquivalent)) {
            $overlap = (count($commonStems) / min(count($stemsA), count($stemsB))) * 100;
            $minThreshold = (count($commonStems) >= 3) ? 50 : 65;
            if ($overlap >= $minThreshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pick canonical event (primary to keep) vs duplicate event (to merge and remove).
     */
    protected function pickCanonical(Event $a, Event $b): array
    {
        $scoreA = 0;
        $scoreB = 0;

        // SourceType priority score (e.g. Venue = 90, Sports = 85, Ticketing = 80, Aggregator = 50)
        $priA = $a->source?->type?->priority() ?? 40;
        $priB = $b->source?->type?->priority() ?? 40;
        $scoreA += (int) round($priA / 10);
        $scoreB += (int) round($priB / 10);

        // Ticketing source preference
        $aIsTicketing = EventIngestionService::isTicketingSource($a->source_slug, $a->ticket_url, $a->source?->type);
        $bIsTicketing = EventIngestionService::isTicketingSource($b->source_slug, $b->ticket_url, $b->source?->type);
        if ($aIsTicketing) $scoreA += 10;
        if ($bIsTicketing) $scoreB += 10;

        // Has direct ticket URL
        if (!empty($a->ticket_url)) $scoreA += 5;
        if (!empty($b->ticket_url)) $scoreB += 5;

        // Image quality: real poster preferred over generic placeholder
        $aGenericImg = EventIngestionService::isGenericPlaceholderImage($a->image_url);
        $bGenericImg = EventIngestionService::isGenericPlaceholderImage($b->image_url);
        if (!$aGenericImg && !empty($a->image_url)) $scoreA += 5;
        if (!$bGenericImg && !empty($b->image_url)) $scoreB += 5;

        // Specific matchup title preferred over generic tournament title
        $aIsLeague = EventIngestionService::isLeagueOrTournamentTitle($a->title);
        $bIsLeague = EventIngestionService::isLeagueOrTournamentTitle($b->title);
        if (!$aIsLeague) $scoreA += 4;
        if (!$bIsLeague) $scoreB += 4;

        // Clean title preferred over titles contaminated with scraped session timestamps
        $aMeta = self::hasScrapedMetadata($a->title);
        $bMeta = self::hasScrapedMetadata($b->title);
        if (!$aMeta && $bMeta) $scoreA += 3;
        if ($aMeta && !$bMeta) $scoreB += 3;

        // Price availability
        if ($a->price_min !== null || $a->price_max !== null) $scoreA += 3;
        if ($b->price_min !== null || $b->price_max !== null) $scoreB += 3;

        // Cyrillic & English title/slug penalties: strongly prefer Latvian title and slug
        $aHasCyrillic = (bool) preg_match('/\p{Cyrillic}/u', $a->title);
        $bHasCyrillic = (bool) preg_match('/\p{Cyrillic}/u', $b->title);
        if ($aHasCyrillic && !$bHasCyrillic) $scoreB += 25;
        if (!$aHasCyrillic && $bHasCyrillic) $scoreA += 25;

        $aHasEnglish = (bool) preg_match('/^(the|a|an)\s+/i', $a->title) || (bool) preg_match('/^(the|a|an)-/i', $a->slug ?? '');
        $bHasEnglish = (bool) preg_match('/^(the|a|an)\s+/i', $b->title) || (bool) preg_match('/^(the|a|an)-/i', $b->slug ?? '');
        if ($aHasEnglish && !$bHasEnglish) $scoreB += 15;
        if (!$aHasEnglish && $bHasEnglish) $scoreA += 15;

        // Description length
        if (mb_strlen($a->description ?? '') > mb_strlen($b->description ?? '')) {
            $scoreA += 1;
        } elseif (mb_strlen($b->description ?? '') > mb_strlen($a->description ?? '')) {
            $scoreB += 1;
        }

        // If scores differ, pick higher score
        if ($scoreA > $scoreB) {
            return [$a, $b];
        }
        if ($scoreB > $scoreA) {
            return [$b, $a];
        }

        // Tiebreaker: keep the earlier created record (smaller ID)
        return $a->id <= $b->id ? [$a, $b] : [$b, $a];
    }

    /**
     * Consolidate duplicate event fields into canonical event and soft-delete duplicate.
     */
    protected function consolidate(Event $canonical, Event $duplicate): void
    {
        DB::transaction(function () use ($canonical, $duplicate) {
            $update = [];

            // 1. Image
            $canonGenericImg = EventIngestionService::isGenericPlaceholderImage($canonical->image_url);
            $dupGenericImg = EventIngestionService::isGenericPlaceholderImage($duplicate->image_url);
            if ($canonGenericImg && !$dupGenericImg && !empty($duplicate->image_url)) {
                $update['image_url'] = $duplicate->image_url;
                $update['internal_image_url'] = null;
            }

            // 2. Ticket URL & Prices
            $canonSourceType = $canonical->source?->type;
            $dupSourceType = $duplicate->source?->type;
            $canonIsTicketing = EventIngestionService::isTicketingSource($canonical->source_slug, $canonical->ticket_url, $canonSourceType);
            $dupIsTicketing = EventIngestionService::isTicketingSource($duplicate->source_slug, $duplicate->ticket_url, $dupSourceType);

            $dupTicketPri = $dupSourceType?->ticketPriority() ?? 20;
            $canonTicketPri = $canonSourceType?->ticketPriority() ?? 20;

            if ((empty($canonical->ticket_url) || $dupTicketPri > $canonTicketPri || ($dupIsTicketing && !$canonIsTicketing)) && !empty($duplicate->ticket_url)) {
                $update['ticket_url'] = EventIngestionService::canonicalizeTicketUrl($duplicate->ticket_url) ?: $duplicate->ticket_url;
                if ($duplicate->price_min !== null) {
                    $update['price_min'] = $duplicate->price_min;
                }
                if ($duplicate->price_max !== null) {
                    $update['price_max'] = $duplicate->price_max;
                }
            } elseif (!empty($canonical->ticket_url)) {
                $cleanCanon = EventIngestionService::canonicalizeTicketUrl($canonical->ticket_url);
                if ($cleanCanon && $cleanCanon !== $canonical->ticket_url) {
                    $update['ticket_url'] = $cleanCanon;
                }
            }

            // Location: prefer source with higher locationPriority()
            $aLocPri = $canonSourceType?->locationPriority() ?? 20;
            $bLocPri = $dupSourceType?->locationPriority() ?? 20;
            if ($bLocPri > $aLocPri && $duplicate->location_id) {
                $update['location_id'] = $duplicate->location_id;
            }

            // 3. Title: if canonical has league title or session metadata, or duplicate is Latvian while canonical is foreign, prefer duplicate's cleaner title
            $canonIsLeague = EventIngestionService::isLeagueOrTournamentTitle($canonical->title);
            $dupIsLeague = EventIngestionService::isLeagueOrTournamentTitle($duplicate->title);
            $canonHasMeta = self::hasScrapedMetadata($canonical->title);
            $dupHasMeta = self::hasScrapedMetadata($duplicate->title);
            $canonHasCyrillic = (bool) preg_match('/\p{Cyrillic}/u', $canonical->title);
            $dupHadCyrillic = (bool) preg_match('/\p{Cyrillic}/u', $duplicate->title);
            $canonHasEnglish = (bool) preg_match('/^(the|a|an)\s+/i', $canonical->title);
            $dupHasEnglish = (bool) preg_match('/^(the|a|an)\s+/i', $duplicate->title);

            if (($canonHasCyrillic && !$dupHadCyrillic) || ($canonHasEnglish && !$dupHasEnglish)) {
                $update['title'] = trim($duplicate->title, '"\' ');
            } elseif ((($canonIsLeague && !$dupIsLeague) || ($canonHasMeta && !$dupHasMeta)) && !empty($duplicate->title)) {
                $update['title'] = trim($duplicate->title, '"\' ');
            }

            // 4. Description: merge TV/broadcast lines or extra details
            $mergedDesc = EventIngestionService::mergeDescriptions($canonical->description, $duplicate->description);
            if ($mergedDesc !== $canonical->description) {
                $update['description'] = $mergedDesc;
            }

            // 5. Short description
            if (empty($canonical->short_description) && !empty($duplicate->short_description)) {
                $update['short_description'] = $duplicate->short_description;
            }

            // 6. Location
            if (empty($canonical->location_id) && !empty($duplicate->location_id)) {
                $update['location_id'] = $duplicate->location_id;
            }

            // 7. Categories
            if ($duplicate->categories->isNotEmpty()) {
                $canonical->categories()->syncWithoutDetaching($duplicate->categories->pluck('id')->all());
            }

            // 8. Raw data
            $update['raw_data'] = array_merge($duplicate->raw_data ?? [], $canonical->raw_data ?? []);

            // 9. Slug & Fingerprint updates
            $effectiveTitle = $update['title'] ?? $canonical->title;
            $canonSlug = $canonical->slug ?? '';
            $expectedSlugPrefix = Str::slug($effectiveTitle);

            if (empty($canonSlug) || preg_match('/\p{Cyrillic}/u', $canonSlug) || preg_match('/^(the|a|an)-/i', $canonSlug) || !str_starts_with($canonSlug, $expectedSlugPrefix)) {
                $suffix = preg_match('/-([a-zA-Z0-9]{6})$/', $canonSlug, $m) ? $m[1] : Str::random(6);
                $update['slug'] = $expectedSlugPrefix . '-' . $suffix;
            }

            $normTitle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $effectiveTitle));
            $date = ($canonical->start_at && $canonical->start_at->format('H:i') !== '00:00')
                ? $canonical->start_at->format('Y-m-d H:i')
                : ($canonical->start_at ? $canonical->start_at->format('Y-m-d') : '');
            $city = mb_strtolower(trim($canonical->location?->city ?? $duplicate->location?->city ?? ''));
            $update['fingerprint'] = hash('sha256', "{$normTitle}|{$date}|{$city}");

            if (!empty($update)) {
                $canonical->update($update);
            }

            // 9. Synchronize Latvian translation
            $effectiveTitle = $update['title'] ?? $canonical->title;
            $effectiveDesc = $update['description'] ?? $canonical->description;
            $effectiveShortDesc = $update['short_description'] ?? $canonical->short_description;

            EventTranslation::updateOrCreate(
                [
                    'event_id' => $canonical->id,
                    'locale' => 'lv',
                ],
                [
                    'title' => $effectiveTitle,
                    'slug' => Str::slug($effectiveTitle) . '-' . substr(md5($canonical->id . 'lv'), 0, 6),
                    'description' => $effectiveDesc,
                    'short_description' => $effectiveShortDesc ?: (mb_strlen($effectiveDesc ?? '') <= 220 ? $effectiveDesc : Str::limit(strip_tags($effectiveDesc ?? ''), 160)),
                ]
            );

            // 10. Copy any missing non-LV translations from duplicate
            foreach ($duplicate->translations as $dupTrans) {
                if ($dupTrans->locale === 'lv') continue;
                $existing = $canonical->translations->firstWhere('locale', $dupTrans->locale);
                if (!$existing && !empty($dupTrans->description)) {
                    EventTranslation::create([
                        'event_id' => $canonical->id,
                        'locale' => $dupTrans->locale,
                        'title' => $dupTrans->title,
                        'slug' => Str::slug($dupTrans->title) . '-' . substr(md5($canonical->id . $dupTrans->locale), 0, 6),
                        'description' => $dupTrans->description,
                        'short_description' => $dupTrans->short_description,
                    ]);
                }
            }

            // 11. Soft delete duplicate
            $duplicate->delete();
        });
    }

    public static function hasScrapedMetadata(string $title): bool
    {
        return (bool) preg_match('/[|•–-]\s*\d{1,2}[.\/]\d{1,2}/u', $title)
            || (bool) preg_match('/\(\s*\d{1,2}[.:]\d{2}\s*\)/u', $title);
    }
}
