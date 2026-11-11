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
        $this->info("Scanning events for duplicates and consolidation (window: past 7 days to +{$days} days)..." . ($dryRun ? ' [DRY RUN]' : ''));

        $events = Event::with(['translations', 'location', 'categories'])
            ->whereNull('deleted_at')
            ->where('start_at', '>=', now()->subDays(7)->startOfDay())
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

        // 2. Start time check: if both have specific non-00:00 times, difference must be <= 45 minutes
        if ($a->start_at && $b->start_at) {
            $tA = $a->start_at->format('H:i');
            $tB = $b->start_at->format('H:i');
            if ($tA !== '00:00' && $tB !== '00:00' && $tA !== $tB) {
                $minA = $a->start_at->hour * 60 + $a->start_at->minute;
                $minB = $b->start_at->hour * 60 + $b->start_at->minute;
                if (abs($minA - $minB) > 45) {
                    return false;
                }
            }
        }

        $sameLocation = $a->location_id && $b->location_id && $a->location_id === $b->location_id;

        // 3. Matchup teams equivalence check
        $teamsA = EventIngestionService::extractMatchupTeams($a->title);
        $teamsB = EventIngestionService::extractMatchupTeams($b->title);

        if ($teamsA && $teamsB && EventIngestionService::areMatchupTeamsEquivalent($teamsA, $teamsB)) {
            if ($sameLocation) {
                return true;
            }
            $cityA = mb_strtolower(trim($a->location?->city ?? ''));
            $cityB = mb_strtolower(trim($b->location?->city ?? ''));
            if (!empty($cityA) && !empty($cityB) && ($cityA === $cityB || str_contains($cityA, $cityB) || str_contains($cityB, $cityA))) {
                return true;
            }
            if (empty($cityA) || empty($cityB)) {
                return true;
            }
        }

        // 4. One is generic league title and the other is a matchup title at same location
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

        // 5. Matchup teams in description check
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

        // 6. Direct high title similarity at same location
        if ($sameLocation) {
            $cleanA = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($a->title, 'UTF-8'));
            $cleanB = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($b->title, 'UTF-8'));
            similar_text($cleanA, $cleanB, $pct);
            if ($pct >= 80) {
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

        // Ticketing source preference
        $aIsTicketing = EventIngestionService::isTicketingSource($a->source_slug, $a->ticket_url);
        $bIsTicketing = EventIngestionService::isTicketingSource($b->source_slug, $b->ticket_url);
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
            $canonIsTicketing = EventIngestionService::isTicketingSource($canonical->source_slug, $canonical->ticket_url);
            $dupIsTicketing = EventIngestionService::isTicketingSource($duplicate->source_slug, $duplicate->ticket_url);

            if ((empty($canonical->ticket_url) || ($dupIsTicketing && !$canonIsTicketing)) && !empty($duplicate->ticket_url)) {
                $update['ticket_url'] = $duplicate->ticket_url;
                if ($duplicate->price_min !== null) {
                    $update['price_min'] = $duplicate->price_min;
                }
                if ($duplicate->price_max !== null) {
                    $update['price_max'] = $duplicate->price_max;
                }
            }

            // 3. Title: if canonical has league title or session metadata, prefer duplicate's cleaner title
            $canonIsLeague = EventIngestionService::isLeagueOrTournamentTitle($canonical->title);
            $dupIsLeague = EventIngestionService::isLeagueOrTournamentTitle($duplicate->title);
            $canonHasMeta = self::hasScrapedMetadata($canonical->title);
            $dupHasMeta = self::hasScrapedMetadata($duplicate->title);

            if ((($canonIsLeague && !$dupIsLeague) || ($canonHasMeta && !$dupHasMeta)) && !empty($duplicate->title)) {
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
