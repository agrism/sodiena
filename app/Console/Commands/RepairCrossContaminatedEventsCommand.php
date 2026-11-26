<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventTranslation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RepairCrossContaminatedEventsCommand extends Command
{
    protected $signature = 'events:repair-cross-contamination {--dry-run : Only show what would be repaired without applying changes}';
    protected $description = 'Repairs ticket links, descriptions, and merged_external_ids contaminated across cinema venues, cities, and sport categories for current and upcoming events';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Running in DRY-RUN mode. No changes will be written to the database.');
        }

        $today = Carbon::today('Europe/Riga');
        $this->info("Starting repair for current and upcoming events (start_at or end_at >= {$today->toDateString()})...");

        // Per AGENTS.md rule: No Past Events Modification!
        $query = Event::whereNull('deleted_at')
            ->where(function ($q) use ($today) {
                $q->where('start_at', '>=', $today)
                  ->orWhere('end_at', '>=', $today)
                  ->orWhereNull('start_at');
            })
            ->with(['location', 'translations']);

        $totalChecked = 0;
        $totalRepaired = 0;

        $query->chunk(100, function ($events) use ($dryRun, &$totalChecked, &$totalRepaired) {
            foreach ($events as $event) {
                $totalChecked++;
                $repaired = $this->repairEvent($event, $dryRun);
                if ($repaired) {
                    $totalRepaired++;
                }
            }
        });

        $this->info("Completed. Checked {$totalChecked} current/upcoming events. Repaired {$totalRepaired} contaminated events.");

        return Command::SUCCESS;
    }

    protected function repairEvent(Event $event, bool $dryRun): bool
    {
        $updates = [];
        $rawData = $event->raw_data ?? [];
        $rawModified = false;

        $venueName = mb_strtolower(trim($event->location?->name ?? ''), 'UTF-8');
        $sourceSlug = mb_strtolower(trim($event->source_slug ?? ''), 'UTF-8');

        $isApollo = $sourceSlug === 'apollo-kino' || str_contains($venueName, 'apollo');
        $isForum = $sourceSlug === 'forum-cinemas' || str_contains($venueName, 'forum');
        $isCinamon = str_contains($venueName, 'cinamon');

        // 1. Repair Apollo Kino events
        if ($isApollo) {
            // A) ticket_url must be on apollokino.lv
            if (!empty($event->ticket_url) && !str_contains(strtolower($event->ticket_url), 'apollokino.lv')) {
                $realApolloUrl = $this->findOriginalApolloUrl($event);
                if ($realApolloUrl && $realApolloUrl !== $event->ticket_url) {
                    $updates['ticket_url'] = $realApolloUrl;
                    $this->line("Event #{$event->id} ({$event->title}) Apollo ticket_url: {$event->ticket_url} -> {$realApolloUrl}");
                }
            }

            // B) Clean ticket_links in raw_data (remove foreign platforms like Bilesu Paradize, Bilesu Serviss, BezRindas)
            if (!empty($rawData['ticket_links']) && is_array($rawData['ticket_links'])) {
                $cleanLinks = [];
                $removedCount = 0;
                foreach ($rawData['ticket_links'] as $link) {
                    $u = strtolower(is_array($link) ? ($link['url'] ?? '') : (string) $link);
                    if (str_contains($u, 'apollokino.lv')) {
                        $cleanLinks[] = $link;
                    } else {
                        $removedCount++;
                    }
                }
                if ($removedCount > 0) {
                    $rawData['ticket_links'] = $cleanLinks;
                    $rawModified = true;
                    $this->line("Event #{$event->id} ({$event->title}): Removed {$removedCount} foreign ticket link(s).");
                }
            }

            // B.2) Clean cta.links in raw_data (remove foreign platforms like Bilesu Paradize, Bilesu Serviss, BezRindas)
            if (!empty($rawData['cta']['links']) && is_array($rawData['cta']['links'])) {
                $cleanCtaLinks = [];
                $removedCtaCount = 0;
                foreach ($rawData['cta']['links'] as $link) {
                    $u = strtolower(is_array($link) ? ($link['url'] ?? '') : (string) $link);
                    if (str_contains($u, 'apollokino.lv')) {
                        $cleanCtaLinks[] = $link;
                    } else {
                        $removedCtaCount++;
                    }
                }
                if ($removedCtaCount > 0) {
                    $rawData['cta']['links'] = $cleanCtaLinks;
                    $rawModified = true;
                    $this->line("Event #{$event->id} ({$event->title}): Removed {$removedCtaCount} foreign CTA link(s).");
                }
            }

            // C) Purge foreign source slugs from merged_external_ids
            if (!empty($rawData['merged_external_ids']) && is_array($rawData['merged_external_ids'])) {
                $foreignSlugs = [
                    'bilesu-serviss', 'bilesuserviss', 'bilesu-paradize', 'bilesuparadize',
                    'bezrindas', 'forum-cinemas', 'forumcinemas', 'kuldigas-novads',
                    'kultura-jelgava', 'jelgavas-valstspilseta', 'sigulda-tourism', 'sigulda-novads',
                ];
                $cleanedIds = [];
                $purgedSlugs = [];
                foreach ($rawData['merged_external_ids'] as $slug => $extId) {
                    if (in_array(strtolower($slug), $foreignSlugs, true)) {
                        $purgedSlugs[] = $slug;
                    } else {
                        $cleanedIds[$slug] = $extId;
                    }
                }
                if (!empty($purgedSlugs)) {
                    $rawData['merged_external_ids'] = $cleanedIds;
                    $rawModified = true;
                    $this->line("Event #{$event->id} ({$event->title}): Purged foreign merged source IDs: " . implode(', ', $purgedSlugs));
                }
            }

            // D) Clean contaminated description (e.g. mentions of Kino Lora, K.Suns, Sigulda card, Cēsis hall)
            if (!empty($event->description)) {
                $contaminationRegex = '/(?:kino\s*lora|k\.?\s*suns|koncertzāl[eē]\s*cēs|forum\s*cinemas|siguldas\s*novada\s*iedzīvotāj|iedzīvotāju\s*kart|uzrādot\s*siguldas)/ui';
                if (preg_match($contaminationRegex, $event->description)) {
                    $cleanDesc = $this->cleanApolloDescription($event);
                    if ($cleanDesc && $cleanDesc !== $event->description) {
                        $updates['description'] = $cleanDesc;
                        $this->line("Event #{$event->id} ({$event->title}): Cleaned contaminated Apollo description.");
                    }
                }
            }
        }

        // 2. Repair Forum Cinemas events
        if ($isForum) {
            // A) ticket_url must be on forumcinemas.lv
            if (!empty($event->ticket_url) && !str_contains(strtolower($event->ticket_url), 'forumcinemas.lv')) {
                $realForumUrl = $this->findOriginalForumUrl($event);
                if ($realForumUrl && $realForumUrl !== $event->ticket_url) {
                    $updates['ticket_url'] = $realForumUrl;
                    $this->line("Event #{$event->id} ({$event->title}) Forum Cinemas ticket_url: {$event->ticket_url} -> {$realForumUrl}");
                }
            }

            // B) Clean ticket_links
            if (!empty($rawData['ticket_links']) && is_array($rawData['ticket_links'])) {
                $cleanLinks = [];
                $removedCount = 0;
                foreach ($rawData['ticket_links'] as $link) {
                    $u = strtolower(is_array($link) ? ($link['url'] ?? '') : (string) $link);
                    if (str_contains($u, 'forumcinemas.lv')) {
                        $cleanLinks[] = $link;
                    } else {
                        $removedCount++;
                    }
                }
                if ($removedCount > 0) {
                    $rawData['ticket_links'] = $cleanLinks;
                    $rawModified = true;
                    $this->line("Event #{$event->id} ({$event->title}): Removed {$removedCount} foreign ticket link(s).");
                }
            }

            // B.2) Clean cta.links in raw_data
            if (!empty($rawData['cta']['links']) && is_array($rawData['cta']['links'])) {
                $cleanCtaLinks = [];
                $removedCtaCount = 0;
                foreach ($rawData['cta']['links'] as $link) {
                    $u = strtolower(is_array($link) ? ($link['url'] ?? '') : (string) $link);
                    if (str_contains($u, 'forumcinemas.lv')) {
                        $cleanCtaLinks[] = $link;
                    } else {
                        $removedCtaCount++;
                    }
                }
                if ($removedCtaCount > 0) {
                    $rawData['cta']['links'] = $cleanCtaLinks;
                    $rawModified = true;
                    $this->line("Event #{$event->id} ({$event->title}): Removed {$removedCtaCount} foreign CTA link(s).");
                }
            }

            // C) Purge foreign source slugs
            if (!empty($rawData['merged_external_ids']) && is_array($rawData['merged_external_ids'])) {
                $foreignSlugs = ['apollo-kino', 'apollokino', 'bilesu-serviss', 'bilesu-paradize', 'bezrindas'];
                $cleanedIds = [];
                $purgedSlugs = [];
                foreach ($rawData['merged_external_ids'] as $slug => $extId) {
                    if (in_array(strtolower($slug), $foreignSlugs, true)) {
                        $purgedSlugs[] = $slug;
                    } else {
                        $cleanedIds[$slug] = $extId;
                    }
                }
                if (!empty($purgedSlugs)) {
                    $rawData['merged_external_ids'] = $cleanedIds;
                    $rawModified = true;
                    $this->line("Event #{$event->id} ({$event->title}): Purged foreign merged source IDs: " . implode(', ', $purgedSlugs));
                }
            }
        }

        // 3. Other events: filter out mismatched cinema chain links from regional events
        if (!$isApollo && !$isForum && !$isCinamon) {
            if (!empty($rawData['ticket_links']) && is_array($rawData['ticket_links'])) {
                $cleanLinks = [];
                $removedCount = 0;
                foreach ($rawData['ticket_links'] as $link) {
                    $u = strtolower(is_array($link) ? ($link['url'] ?? '') : (string) $link);
                    if (str_contains($u, 'apollokino.lv') || str_contains($u, 'forumcinemas.lv')) {
                        // Regional hall / theater event had an Apollo or Forum link cross-contaminated into it
                        $removedCount++;
                    } else {
                        $cleanLinks[] = $link;
                    }
                }
                if ($removedCount > 0) {
                    $rawData['ticket_links'] = $cleanLinks;
                    $rawModified = true;
                    $this->line("Event #{$event->id} ({$event->title}): Removed {$removedCount} mismatched cinema link(s).");
                }
            }

            if (!empty($rawData['cta']['links']) && is_array($rawData['cta']['links'])) {
                $cleanCtaLinks = [];
                $removedCtaCount = 0;
                foreach ($rawData['cta']['links'] as $link) {
                    $u = strtolower(is_array($link) ? ($link['url'] ?? '') : (string) $link);
                    if (str_contains($u, 'apollokino.lv') || str_contains($u, 'forumcinemas.lv')) {
                        $removedCtaCount++;
                    } else {
                        $cleanCtaLinks[] = $link;
                    }
                }
                if ($removedCtaCount > 0) {
                    $rawData['cta']['links'] = $cleanCtaLinks;
                    $rawModified = true;
                    $this->line("Event #{$event->id} ({$event->title}): Removed {$removedCtaCount} mismatched cinema CTA link(s).");
                }
            }
        }

        // 4. Cross-City Contamination Repair for all events
        $eventCity = trim($event->location?->city ?? '');
        $citySourceMap = [
            'visitventspils' => 'Ventspils',
            'ventspils' => 'Ventspils',
            'jelgavas-valstspilseta' => 'Jelgava',
            'kultura-jelgava' => 'Jelgava',
            'sigulda-tourism' => 'Sigulda',
            'sigulda-novads' => 'Sigulda',
            'madona' => 'Madona',
            'kuldigas-novads' => 'Kuldīga',
            'visit-tukums' => 'Tukums',
            'bauskas-novads' => 'Bauska',
            'cesu-novads' => 'Cēsis',
            'valmieras-novads' => 'Valmiera',
            'liepaja' => 'Liepāja',
            'ogres-novads' => 'Ogre',
        ];

        if (!empty($eventCity) && !empty($rawData['merged_external_ids']) && is_array($rawData['merged_external_ids'])) {
            $purgedCitySlugs = [];
            $cleanedIds = [];
            foreach ($rawData['merged_external_ids'] as $slug => $extId) {
                if (isset($citySourceMap[$slug]) && mb_strtolower($citySourceMap[$slug], 'UTF-8') !== mb_strtolower($eventCity, 'UTF-8')) {
                    // Exception: Kuldīgas novads parishes
                    if ($citySourceMap[$slug] === 'Kuldīga' && in_array(mb_strtolower($eventCity, 'UTF-8'), ['turlava', 'laidi', 'raņķi', 'renda', 'alsunga', 'kabile', 'skrunda', 'padure', 'pelči', 'ēdole', 'snēpele', 'rudbārži', 'vārme', 'kurmāle', 'nīkrāce'], true)) {
                        $cleanedIds[$slug] = $extId;
                        continue;
                    }
                    $purgedCitySlugs[] = $slug;
                } else {
                    $cleanedIds[$slug] = $extId;
                }
            }

            if (!empty($purgedCitySlugs)) {
                $rawData['merged_external_ids'] = $cleanedIds;
                $rawModified = true;
                $this->line("Event #{$event->id} ({$event->title}) in {$eventCity}: Purged cross-city source(s): " . implode(', ', $purgedCitySlugs));
            }
        }

        // Clean cross-city location_raw
        if (!empty($eventCity) && !empty($rawData['location_raw'])) {
            $foreignCityPhrases = [
                'Jelgava' => ['ventspils', 'kuldīga', 'liepāja', 'daugavpils'],
                'Ventspils' => ['jelgava', 'kuldīga', 'liepāja', 'rīga'],
                'Rīga' => ['ventspils', 'jelgava', 'kuldīga', 'daugavpils'],
            ];
            if (isset($foreignCityPhrases[$eventCity])) {
                $locLower = mb_strtolower($rawData['location_raw'], 'UTF-8');
                foreach ($foreignCityPhrases[$eventCity] as $foreignCity) {
                    if (str_contains($locLower, $foreignCity)) {
                        if (!empty($rawData['original_place'])) {
                            $rawData['location_raw'] = $rawData['original_place'];
                        } else {
                            unset($rawData['location_raw']);
                        }
                        $rawModified = true;
                        $this->line("Event #{$event->id} ({$event->title}) in {$eventCity}: Cleaned cross-city location_raw containing '{$foreignCity}'.");
                        break;
                    }
                }
            }
        }

        // Clean cross-city description
        if (!empty($eventCity) && !empty($event->description)) {
            if ($eventCity === 'Jelgava' && str_contains(mb_strtolower($event->description, 'UTF-8'), 'ventspils')) {
                $cleanDesc = "Jaunā gada sagaidīšana Jelgavā, Hercoga Jēkaba laukumā.";
                if ($event->id === 5362 || str_contains($event->title, 'Jaunā gada')) {
                    $updates['description'] = $cleanDesc;
                    $this->line("Event #{$event->id} ({$event->title}) in Jelgava: Cleaned Ventspils contaminated description.");
                }
            }
        }

        // 5. Cross-Sport Contamination Repair
        $sport = \App\Services\Scrapers\EventIngestionService::extractSportDiscipline($event->title);
        if ($sport) {
            $otherSports = [
                'boks' => ['basketbol', 'volejbol', 'florbol', 'futbol', 'hokej'],
                'basketbols' => ['boks', 'hokej', 'volejbol'],
                'kerlings' => ['boks', 'basketbol', 'futbol'],
            ];
            if (isset($otherSports[$sport]) && !empty($rawData['merged_external_ids']) && is_array($rawData['merged_external_ids'])) {
                $purgedSportSlugs = [];
                $cleanedIds = [];
                foreach ($rawData['merged_external_ids'] as $slug => $extId) {
                    $extLower = mb_strtolower((string) $extId, 'UTF-8');
                    $isConflicting = false;
                    foreach ($otherSports[$sport] as $otherSport) {
                        if (str_contains($extLower, $otherSport)) {
                            $isConflicting = true;
                            break;
                        }
                    }
                    if ($isConflicting) {
                        $purgedSportSlugs[] = $slug;
                    } else {
                        $cleanedIds[$slug] = $extId;
                    }
                }
                if (!empty($purgedSportSlugs)) {
                    $rawData['merged_external_ids'] = $cleanedIds;
                    $rawModified = true;
                    $this->line("Event #{$event->id} ({$event->title}) [{$sport}]: Purged conflicting sport merged IDs: " . implode(', ', $purgedSportSlugs));
                }
            }

            // Clean description if it belongs to conflicting sport
            if (isset($otherSports[$sport]) && !empty($event->description)) {
                $descLower = mb_strtolower($event->description, 'UTF-8');
                foreach ($otherSports[$sport] as $otherSport) {
                    if (str_contains($descLower, $otherSport . 'a līg') || str_contains($descLower, $otherSport . 's')) {
                        $cleanDesc = $event->short_description && !str_contains(mb_strtolower($event->short_description, 'UTF-8'), $otherSport)
                            ? $event->short_description
                            : "{$event->title}. Norises vieta: " . ($event->location?->name ?: 'Sporta centrs');
                        $updates['description'] = $cleanDesc;
                        $this->line("Event #{$event->id} ({$event->title}): Cleaned conflicting '{$otherSport}' description from '{$sport}' event.");
                        break;
                    }
                }
            }
        }

        // 6. Repair Sports Events Contaminated with Theatre/Culture Data (e.g. Daugavpils basketball with Hermanis Paukšs play)
        $isSportsEvent = $event->source_slug === 'basket-lv'
            || $event->source_slug === 'lff-virsliga'
            || $event->source_slug === 'lhf-hokejs'
            || \App\Services\Scrapers\EventIngestionService::extractMatchupTeams($event->title) !== null;

        if ($isSportsEvent) {
            $theatreContaminationRegex = '/(?:luga|izrāde|drāma|paukšs|vampilovs|priede|šapošņikovs|režis|scenogrāf|kostīmi|aktieri|lomās:|dramaturģ|iestudējum|daugavpils\s*teātr|valmieras\s*teātr|bilesuparadize\.lv)/ui';

            $descContaminated = !empty($event->description) && preg_match($theatreContaminationRegex, $event->description);
            $ticketContaminated = !empty($event->ticket_url) && str_contains(strtolower($event->ticket_url), 'bilesuparadize.lv');
            $imageContaminated = !empty($event->image_url) && (str_contains($event->image_url, 'meli-bez-noteikumiem') || str_contains($event->image_url, 'bilesuparadize') || str_contains($event->image_url, 'daugavpils.lv/assets/upload/events'));

            if ($descContaminated || $ticketContaminated || $imageContaminated) {
                // A) Clean description
                if ($descContaminated) {
                    $cleanSportsDesc = !empty($event->short_description) && !preg_match($theatreContaminationRegex, $event->short_description)
                        ? $event->short_description
                        : "{$event->title}. Norises vieta: " . ($event->location?->name ?: 'Sporta zāle');
                    $updates['description'] = $cleanSportsDesc;
                    $this->line("Event #{$event->id} ({$event->title}): Cleaned theatre play description from sports event.");
                }

                // B) Remove theatre ticket_url & ticket_links
                if ($ticketContaminated) {
                    $updates['ticket_url'] = null;
                    $this->line("Event #{$event->id} ({$event->title}): Removed theatre ticket_url from sports event.");
                }
                if (!empty($rawData['ticket_links']) && is_array($rawData['ticket_links'])) {
                    $cleanLinks = [];
                    foreach ($rawData['ticket_links'] as $link) {
                        $u = strtolower(is_array($link) ? ($link['url'] ?? '') : (string) $link);
                        if (!str_contains($u, 'bilesuparadize.lv')) {
                            $cleanLinks[] = $link;
                        }
                    }
                    $rawData['ticket_links'] = $cleanLinks;
                    $rawModified = true;
                }

                // C) Purge theatre merged IDs and CTA links
                if (!empty($rawData['merged_external_ids']) && is_array($rawData['merged_external_ids'])) {
                    $cleanedIds = [];
                    foreach ($rawData['merged_external_ids'] as $slug => $extId) {
                        if (in_array($slug, ['basket-lv', 'lff-virsliga', 'lhf-hokejs'], true)) {
                            $cleanedIds[$slug] = $extId;
                        }
                    }
                    $rawData['merged_external_ids'] = $cleanedIds;
                    $rawModified = true;
                }
                if (!empty($rawData['cta']['links'])) {
                    $cleanCtaLinks = [];
                    foreach ($rawData['cta']['links'] as $link) {
                        $u = strtolower($link['url'] ?? '');
                        if (!str_contains($u, 'bilesuparadize.lv')) {
                            $cleanCtaLinks[] = $link;
                        }
                    }
                    $rawData['cta']['links'] = $cleanCtaLinks;
                    $rawModified = true;
                }

                // D) Reset organizer if it was a theatre
                if (!empty($rawData['organizer'])) {
                    $orgName = is_array($rawData['organizer']) ? ($rawData['organizer']['name'] ?? '') : (string) $rawData['organizer'];
                    if (str_contains(mb_strtolower($orgName, 'UTF-8'), 'teātr')) {
                        unset($rawData['organizer']);
                        $rawModified = true;
                    }
                }

                // E) Remove theatre category from event & fix foreign translations
                if (!$dryRun) {
                    $theatreCategory = \App\Models\Category::where('slug', 'teatris')->first();
                    if ($theatreCategory) {
                        $event->categories()->detach($theatreCategory->id);
                    }

                    $rawTitle = $event->getRawOriginal('title') ?: $event->title;
                    $foreignTitlesRegex = '/(?:lies without rules|охота на уток|meli bez noteikumiem|pīļu medības|zilā)/ui';
                    foreach ($event->translations as $trans) {
                        if (preg_match($foreignTitlesRegex, $trans->title) || preg_match($theatreContaminationRegex, $trans->description ?? '')) {
                            $trans->update([
                                'title' => $rawTitle,
                                'description' => $updates['description'] ?? $event->getRawOriginal('short_description') ?? $rawTitle,
                                'short_description' => $event->getRawOriginal('short_description') ?: $rawTitle,
                            ]);
                        }
                    }
                }
            }
        }

        if ($rawModified) {
            $updates['raw_data'] = $rawData;
        }

        if (empty($updates)) {
            return false;
        }

        if (!$dryRun) {
            $event->update($updates);

            // Sync Latvian translation if description changed
            if (isset($updates['description'])) {
                EventTranslation::updateOrCreate(
                    [
                        'event_id' => $event->id,
                        'locale' => 'lv',
                    ],
                    [
                        'title' => $event->title,
                        'slug' => Str::slug($event->title) . '-' . substr(md5($event->id . 'lv'), 0, 6),
                        'description' => $updates['description'],
                        'short_description' => $event->short_description ?: Str::limit(strip_tags($updates['description']), 160),
                    ]
                );
            }
        }

        return true;
    }

    protected function findOriginalApolloUrl(Event $event): ?string
    {
        $rawData = $event->raw_data ?? [];

        if (!empty($rawData['source_url']) && str_contains(strtolower($rawData['source_url']), 'apollokino.lv')) {
            return $rawData['source_url'];
        }

        if (!empty($event->source_url) && str_contains(strtolower($event->source_url), 'apollokino.lv')) {
            return $event->source_url;
        }

        if (!empty($rawData['merged_external_ids']['apollo-kino'])) {
            $extId = $rawData['merged_external_ids']['apollo-kino'];
            if (is_numeric($extId)) {
                return "https://www.apollokino.lv/websales/show/{$extId}";
            }
        }

        // Try finding Apollo show ID in raw_data JSON or ticket_links
        $json = json_encode($rawData);
        if (preg_match('/apollokino\.lv\/websales\/show\/(\d+)/i', $json, $m)) {
            return "https://www.apollokino.lv/websales/show/{$m[1]}";
        }

        return $event->source_url ?: "https://www.apollokino.lv";
    }

    protected function findOriginalForumUrl(Event $event): ?string
    {
        $rawData = $event->raw_data ?? [];

        if (!empty($rawData['source_url']) && str_contains(strtolower($rawData['source_url']), 'forumcinemas.lv')) {
            return $rawData['source_url'];
        }

        if (!empty($event->source_url) && str_contains(strtolower($event->source_url), 'forumcinemas.lv')) {
            return $event->source_url;
        }

        $json = json_encode($rawData);
        if (preg_match('/forumcinemas\.lv\/websales\/show\/(\d+)/i', $json, $m)) {
            return "https://www.forumcinemas.lv/websales/show/{$m[1]}";
        }

        return $event->source_url ?: "https://www.forumcinemas.lv";
    }

    protected function cleanApolloDescription(Event $event): ?string
    {
        // 1. If short_description does not have foreign notes, use it or expand it
        $short = trim((string) $event->short_description);
        $contaminationRegex = '/(?:kino\s*lora|k\.?\s*suns|koncertzāl[eē]\s*cēs|forum\s*cinemas|siguldas\s*novada\s*iedzīvotāj|iedzīvotāju\s*kart|uzrādot\s*siguldas)/ui';

        if (!empty($short) && !preg_match($contaminationRegex, $short) && mb_strlen($short, 'UTF-8') >= 20) {
            return $short;
        }

        // 2. Strip contaminated paragraphs / lines from description
        $paragraphs = preg_split('/\n{2,}|\r\n\r\n/u', $event->description);
        $cleanParagraphs = [];
        foreach ($paragraphs as $p) {
            if (!preg_match($contaminationRegex, $p)) {
                $cleanParagraphs[] = trim($p);
            }
        }

        $result = implode("\n\n", array_filter($cleanParagraphs));

        return !empty($result) ? $result : ($event->short_description ?: $event->title);
    }
}
