<?php

namespace App\Services\Scrapers;

use App\Console\Commands\ConsolidateCategoriesCommand;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Event;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\LocationTranslation;
use App\Models\ScrapeLog;
use App\Models\Source;
use App\Services\Scrapers\Contracts\EventScraperInterface;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventIngestionService
{
    public function ingest(Source $source): ScrapeLog
    {
        $startedAt = now();
        $startTime = microtime(true);
        $scrapedCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $failedCount = 0;
        $errors = [];

        try {
            /** @var EventScraperInterface $scraper */
            $scraper = app()->make($source->scraper_class);
            $dtoCollection = $scraper->scrape($source);
            $scrapedCount = $dtoCollection->count();

            foreach ($dtoCollection as $dto) {
                try {
                    $result = $this->ingestDTO($dto, $source);
                    if ($result === 'created') {
                        $createdCount++;
                    } elseif ($result === 'updated') {
                        $updatedCount++;
                    }
                } catch (\Throwable $e) {
                    $failedCount++;
                    $errors[] = "Error on '{$dto->title}': " . $e->getMessage();
                    Log::error("Failed to ingest event '{$dto->title}' from {$source->name}", [
                        'exception' => $e,
                    ]);
                }
            }

            $source->update([
                'last_scraped_at' => now(),
                'last_status' => empty($errors) ? 'success' : 'partial',
            ]);
        } catch (\Throwable $e) {
            $failedCount++;
            $errors[] = "Scraper execution failed: " . $e->getMessage();
            Log::error("Source scrape failed entirely for {$source->name}", ['exception' => $e]);

            $source->update([
                'last_scraped_at' => now(),
                'last_status' => 'failed',
            ]);
        }

        $duration = microtime(true) - $startTime;

        $cleanErrors = null;
        if (!empty($errors)) {
            $cleanErrors = array_map(function ($err) {
                return mb_convert_encoding($err, 'UTF-8', 'UTF-8');
            }, array_slice($errors, 0, 15));
        }

        return ScrapeLog::create([
            'source_id' => $source->id,
            'items_found' => $scrapedCount,
            'items_created' => $createdCount,
            'items_updated' => $updatedCount,
            'duration_seconds' => round($duration, 2),
            'status' => empty($errors) ? 'success' : ($createdCount > 0 || $updatedCount > 0 ? 'partial' : 'failed'),
            'errors' => $cleanErrors,
            'started_at' => $startedAt,
            'completed_at' => now(),
        ]);
    }

    public function ingestDTO(ScrapedEventDTO $dto, Source $source): string
    {
        return DB::transaction(function () use ($dto, $source) {
            $locale = $dto->locale ?: 'lv';

            // 1. Resolve Location and Location Translations
            $locationId = $this->resolveOrCreateLocation($dto, $locale);

            // 2. Resolve canonical Categories (strictly the 9 master categories)
            $categoryIds = [];

            // Check if venue or title specifically indicates theatre, cinema, music, etc.
            $smartSlug = ConsolidateCategoriesCommand::inferFromContentAndVenue(
                $dto->title,
                $dto->description,
                $dto->venueName,
                $dto->categoryNames
            );

            if ($smartSlug && $smartSlug !== 'citi') {
                $cat = $this->resolveCanonicalCategory($smartSlug);
                if ($cat) {
                    $categoryIds[] = $cat->id;
                }
            }

            foreach ($dto->categoryNames as $catName) {
                if (empty(trim($catName))) continue;
                $cat = $this->resolveCanonicalCategory($catName);
                if ($cat) {
                    $categoryIds[] = $cat->id;
                }
            }

            if (empty($categoryIds)) {
                $cat = $this->resolveCanonicalCategory($dto->title . ' ' . ($dto->entertainmentType ?? ''));
                if ($cat) {
                    $categoryIds[] = $cat->id;
                }
            }

            // Strict Cinema Enforcement: K.Suns, Kino Rio, Forum Cinemas, and Cinema venues or film text must NEVER have Teatris
            $venueLower = mb_strtolower($dto->venueName ?? '', 'UTF-8');
            $titleLower = mb_strtolower($dto->title ?? '', 'UTF-8');
            $descLower = mb_strtolower($dto->description ?? '', 'UTF-8');
            $fullText = $titleLower . ' ' . $venueLower . ' ' . $descLower;

            // Sanitize out copy-pasted ticketing disclaimers for cinema check
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
                $kinoCat = $this->resolveCanonicalCategory('kino');
                if ($teatrisCat) {
                    $categoryIds = array_diff($categoryIds, [$teatrisCat->id]);
                }
                if ($kinoCat && !in_array($kinoCat->id, $categoryIds, true)) {
                    $categoryIds[] = $kinoCat->id;
                }
            }

            $sportsCat = $this->resolveCanonicalCategory('sports');
            $teatrisCat = $this->resolveCanonicalCategory('teatris');
            $muzikaCat = $this->resolveCanonicalCategory('muzika');
            $izstadesCat = $this->resolveCanonicalCategory('izstades');
            $seminariCat = $this->resolveCanonicalCategory('seminari');

            // Guard: Real Theatre plays are never sports, and never exhibitions unless explicit exhibition
            $isRealTheatre = (
                $smartSlug === 'teatris' ||
                str_contains($titleLower, 'izrāde') || str_contains($titleLower, 'luga') ||
                str_contains($titleLower, 'iestudējum') || str_contains($titleLower, 'komēdij') ||
                str_contains($titleLower, 'stand-up') || str_contains($titleLower, 'standup') ||
                (str_contains($venueLower, 'teātr') && !str_contains($venueLower, 'kino'))
            );
            if ($isRealTheatre) {
                if ($sportsCat) {
                    $categoryIds = array_diff($categoryIds, [$sportsCat->id]);
                }
                if ($teatrisCat && !in_array($teatrisCat->id, $categoryIds, true)) {
                    $categoryIds[] = $teatrisCat->id;
                }
                $hasExplicitExhibition = str_contains($titleLower, 'izstād') || str_contains($titleLower, 'ekspozīcij') || str_contains($titleLower, 'glezn') || str_contains($titleLower, 'fotoizstād');
                if (!$hasExplicitExhibition && $izstadesCat) {
                    $categoryIds = array_diff($categoryIds, [$izstadesCat->id]);
                }
            }

            // Guard: Music concerts, bands, orchestras, choirs are never sports, and never exhibitions unless explicit exhibition
            $isRealMusic = (
                $smartSlug === 'muzika' ||
                str_contains($titleLower, 'koncerts') || str_contains($titleLower, 'grupa ') ||
                str_contains($titleLower, 'grupas ') || str_contains($titleLower, 'grupu ') ||
                str_contains($titleLower, 'orķestr') || str_contains($titleLower, 'koris') ||
                str_contains($titleLower, 'dziesm')
            ) && !preg_match('/\b(?:vs|pret|čempionāts|kauss|virslīga|fiba|turnīrs)\b/ui', $titleLower);
            if ($isRealMusic) {
                if ($sportsCat) {
                    $categoryIds = array_diff($categoryIds, [$sportsCat->id]);
                }
                if ($muzikaCat && !in_array($muzikaCat->id, $categoryIds, true)) {
                    $categoryIds[] = $muzikaCat->id;
                }
                $hasExplicitExhibition = str_contains($titleLower, 'izstād') || str_contains($titleLower, 'ekspozīcij') || str_contains($titleLower, 'glezn') || str_contains($titleLower, 'fotoizstād');
                if (!$hasExplicitExhibition && $izstadesCat) {
                    $categoryIds = array_diff($categoryIds, [$izstadesCat->id]);
                }
            }

            // Guard: Excursions, guided tours, park walks are Izstādes, never sports
            $isExcursion = (
                str_contains($titleLower, 'ekskursij') ||
                str_contains($titleLower, 'gida pavadīb') ||
                str_contains($titleLower, 'kūrorta parks') ||
                str_contains($venueLower, 'kūrorta parks')
            );
            if ($isExcursion && $sportsCat) {
                $categoryIds = array_diff($categoryIds, [$sportsCat->id]);
                if ($izstadesCat && !in_array($izstadesCat->id, $categoryIds, true)) {
                    $categoryIds[] = $izstadesCat->id;
                }
            }

            // Guard: Randiņš tumsā / Piedzīvojums tumsā and Tumsas muzejs / Sensorium are not Sports (user: lai ir izrāde, semināri)
            if (
                str_contains($titleLower, 'randiņ') ||
                str_contains($titleLower, 'piedzīvojums tumsā') ||
                str_contains($titleLower, 'piedzivojums tumsa') ||
                str_contains($titleLower, 'tumsā') ||
                str_contains($titleLower, 'tumsa') ||
                str_contains($venueLower, 'tumsas muzejs') ||
                str_contains($venueLower, 'sensorium')
            ) {
                if ($sportsCat) {
                    $categoryIds = array_diff($categoryIds, [$sportsCat->id]);
                }
                if ($teatrisCat && !in_array($teatrisCat->id, $categoryIds, true)) {
                    $categoryIds[] = $teatrisCat->id;
                }
                if ($seminariCat && !in_array($seminariCat->id, $categoryIds, true)) {
                    $categoryIds[] = $seminariCat->id;
                }
            }

            // Guard: Educational Open Days, University presentations, Culinary schools are not sports (they are Seminari)
            if (
                str_contains($titleLower, 'atvērto durvju') ||
                str_contains($titleLower, 'atvērtās durvis') ||
                str_contains($titleLower, 'open day') ||
                str_contains($titleLower, 'bakalaura') ||
                str_contains($titleLower, 'kulinārijas skola') ||
                (str_contains($titleLower, 'studij') && !str_contains($titleLower, 'deju studij') && !str_contains($titleLower, 'fitnesa studij'))
            ) {
                if ($sportsCat) {
                    $categoryIds = array_diff($categoryIds, [$sportsCat->id]);
                }
                if ($seminariCat && !in_array($seminariCat->id, $categoryIds, true)) {
                    $categoryIds[] = $seminariCat->id;
                }
            }

            // Guard: Exhibitions, museums, lectures, historical events are never sports (they are Izstādes or Semināri)
            $isRealExhibitionOrLecture = (
                str_contains($titleLower, 'izstād') || str_contains($titleLower, 'ekspozīcij') ||
                str_contains($titleLower, 'muzej') || str_contains($titleLower, 'galerij') ||
                str_contains($titleLower, 'lekcij') || str_contains($venueLower, 'muzej') ||
                str_contains($venueLower, 'galerij')
            ) && !preg_match('/\b(?:turnīr|čempionāt|kauss|mačs|futbol|basketbol|hokej|florbol|handbol|padel|virslīg)\b/ui', $titleLower);

            if ($isRealExhibitionOrLecture && $sportsCat) {
                $categoryIds = array_diff($categoryIds, [$sportsCat->id]);
                if (str_contains($titleLower, 'lekcij') && $seminariCat && !in_array($seminariCat->id, $categoryIds, true)) {
                    $categoryIds[] = $seminariCat->id;
                }
                if ($izstadesCat && !in_array($izstadesCat->id, $categoryIds, true)) {
                    $categoryIds[] = $izstadesCat->id;
                }
            }

            // Guard: Real sports events should never be tagged as 'izstādes' or 'teātris'
            $isRealSports = (
                $smartSlug === 'sports' ||
                preg_match('/\b(?:turnīr|čempionāt|kauss|mačs|futbol|basketbol|hokej|florbol|volejbol|handbol|padel|virslīg|basketball|handball|tournament|championship)\b/ui', $titleLower)
            ) && !$isRealTheatre && !$isStrictCinema && !$isRealExhibitionOrLecture;

            if ($isRealSports) {
                if ($izstadesCat) {
                    $categoryIds = array_diff($categoryIds, [$izstadesCat->id]);
                }
                if ($teatrisCat) {
                    $categoryIds = array_diff($categoryIds, [$teatrisCat->id]);
                }
                if ($sportsCat && !in_array($sportsCat->id, $categoryIds, true)) {
                    $categoryIds[] = $sportsCat->id;
                }
            }

            // Guard: National holidays, Lāčplēša diena, torchlight processions are Svētki, never Sports or Izstādes (unless actual race/exhibition)
            $holidayRegex = '/(?:\b|(?<=[^a-zāčēģīķļņšūž]))((?:valsts\s+svētk|valsts\s+svetk)[a-zāčēģīķļņšūž]*|(?:lāčplēša|lacplesa)\s+dien[a-zāčēģīķļņšūž]*|(?:lāpu\s+gājien|lapu\s+gajien)[a-zāčēģīķļņšūž]*|proklamēšan[a-zāčēģīķļņšūž]*|proklamesan[a-zāčēģīķļņšūž]*|neatkarības\s+atjaunošan[a-zāčēģīķļņšūž]*|baltā\s+galdaut[a-zāčēģīķļņšūž]*|brīvības\s+cīnītāj[a-zāčēģīķļņšūž]*|barikāžu\s+atcer[a-zāčēģīķļņšūž]*|barikāžu\s+aizstāvj[a-zāčēģīķļņšūž]*|18\.\s*novembr[a-zāčēģīķļņšūž]*)(?:\b|(?=[^a-zāčēģīķļņšūž]))/ui';
            $isCelebrationHoliday = (
                $smartSlug === 'svetki' ||
                preg_match($holidayRegex, $titleLower)
            ) && !preg_match('/\b(?:skrējiens?|maratons?|kross?|turnīrs?|čempionāts?|kauss|mačs|futbol|basketbol|hokej|florbol|orientēšan[a-zāčēģīķļņšūž]*)\b/ui', $titleLower);

            if ($isCelebrationHoliday) {
                $svetkiCat = $this->resolveCanonicalCategory('svetki');
                if ($sportsCat) {
                    $categoryIds = array_diff($categoryIds, [$sportsCat->id]);
                }
                if ($izstadesCat && !str_contains($titleLower, 'izstād') && !str_contains($titleLower, 'ekspozīcij')) {
                    $categoryIds = array_diff($categoryIds, [$izstadesCat->id]);
                }
                if ($svetkiCat && !in_array($svetkiCat->id, $categoryIds, true)) {
                    $categoryIds[] = $svetkiCat->id;
                }
            }

            // If specific categories exist, remove 'citi' fallback
            $citiCat = $this->resolveCanonicalCategory('citi');
            if ($citiCat && count($categoryIds) > 1 && in_array($citiCat->id, $categoryIds, true)) {
                $categoryIds = array_diff($categoryIds, [$citiCat->id]);
            }

            $categoryIds = array_values(array_unique($categoryIds));

            $fingerprint = $dto->getFingerprint();

            // 3. Deduplication search:
            // Check by external source ID first
            $existingEvent = null;
            if ($dto->sourceExternalId) {
                $existingEvent = Event::withTrashed()
                    ->where('source_id', $source->id)
                    ->where('source_external_id', $dto->sourceExternalId)
                    ->first();
            }

            if (!$existingEvent) {
                $existingEvent = Event::withTrashed()->where('fingerprint', $fingerprint)->first();
            }

            // Match by unique ticketing URL (e.g. Biļešu Paradīze, Biļešu Serviss, Aula, BezRindas)
            if (!$existingEvent && !empty($dto->ticketUrl) && self::isTicketingSource($source->slug, $dto->ticketUrl)) {
                $cleanTicketUrl = strtok($dto->ticketUrl, '?');
                $candidateByTicket = Event::withTrashed()
                    ->where(function ($q) use ($dto, $cleanTicketUrl) {
                        $q->where('ticket_url', $dto->ticketUrl)
                          ->orWhere('ticket_url', $cleanTicketUrl);
                    })
                    ->first();

                if ($candidateByTicket) {
                    $isDifferentSessionSameDay = false;
                    if ($candidateByTicket->start_at && $dto->startAt && $candidateByTicket->start_at->toDateString() === $dto->startAt->toDateString()) {
                        $candMinutes = $candidateByTicket->start_at->hour * 60 + $candidateByTicket->start_at->minute;
                        $dtoMinutes = $dto->startAt->hour * 60 + $dto->startAt->minute;
                        if (abs($candMinutes - $dtoMinutes) >= 45) {
                            $isDifferentSessionSameDay = true;
                        }
                    }

                    if (!$isDifferentSessionSameDay) {
                        $existingEvent = $candidateByTicket;
                    }
                }
            }

            // Fuzzy similarity check for same date and similar title / stems / descriptions
            if (!$existingEvent && $dto->startAt) {
                $candidatesQuery = Event::whereDate('start_at', $dto->startAt->toDateString());
                if ($dto->endAt) {
                    $candidatesQuery->orWhereDate('end_at', $dto->endAt->toDateString())
                        ->orWhere(function ($q) use ($dto) {
                            $q->where('start_at', '<=', $dto->endAt)
                              ->where('end_at', '>=', $dto->startAt);
                        });
                }
                $sameDayEvents = $candidatesQuery->with('location')->get();
                $cleanDto = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($dto->title, 'UTF-8'));
                $dtoStems = $this->getLatvianWordStems($dto->title);
                $dtoWords = array_values(array_filter(explode(' ', $cleanDto), fn ($w) => mb_strlen($w, 'UTF-8') > 2 && !in_array($w, ['un', 'par', 'ar', 'pie', 'uz', 'no', 'vai'])));

                foreach ($sameDayEvents as $candidate) {
                    // 1. Never fuzzy-match two distinct events from the SAME source with different external IDs
                    if ($candidate->source_id === $source->id && $dto->sourceExternalId && $candidate->source_external_id && $candidate->source_external_id !== $dto->sourceExternalId) {
                        continue;
                    }

                    // Sports matchup equivalence: on the same date, two equivalent matchups from different sources are always the same game
                    $candTeams = self::extractMatchupTeams($candidate->title);
                    $dtoTeams = self::extractMatchupTeams($dto->title);
                    if ($candTeams && $dtoTeams && self::areMatchupTeamsEquivalent($candTeams, $dtoTeams)) {
                        $existingEvent = $candidate;
                        break;
                    }

                    // 2. City mismatch guard: If both candidate and dto have known cities and they differ, they are distinct events
                    if (!empty($dto->city) && $candidate->location && !empty($candidate->location->city)) {
                        $candCity = mb_strtolower(trim($candidate->location->city), 'UTF-8');
                        $dtoCity = mb_strtolower(trim($dto->city), 'UTF-8');
                        if ($candCity !== $dtoCity && !str_contains($candCity, $dtoCity) && !str_contains($dtoCity, $candCity)) {
                            continue;
                        }
                    }

                    $isCandidateMultiDay = $candidate->end_at && $candidate->start_at && $candidate->start_at->toDateString() !== $candidate->end_at->toDateString();
                    $isDtoMultiDay = $dto->endAt && $dto->startAt && $dto->startAt->toDateString() !== $dto->endAt->toDateString();

                    // If both are single-day sessions with specific start times that differ by >= 45 min, they are distinct sessions
                    if (!$isCandidateMultiDay && !$isDtoMultiDay && $candidate->start_at && $dto->startAt) {
                        $candTime = $candidate->start_at->format('H:i');
                        $dtoTime = $dto->startAt->format('H:i');
                        if ($candTime !== '00:00' && $dtoTime !== '00:00' && $candTime !== $dtoTime) {
                            $candMinutes = $candidate->start_at->hour * 60 + $candidate->start_at->minute;
                            $dtoMinutes = $dto->startAt->hour * 60 + $dto->startAt->minute;
                            if (abs($candMinutes - $dtoMinutes) >= 45) {
                                continue;
                            }
                        }
                    }

                    // 0. Sports matchup equivalence & Venue-time league consolidation
                    $candTeams = self::extractMatchupTeams($candidate->title);
                    $dtoTeams = self::extractMatchupTeams($dto->title);

                    if ($candTeams && $dtoTeams && self::areMatchupTeamsEquivalent($candTeams, $dtoTeams)) {
                        $existingEvent = $candidate;
                        break;
                    }

                    // Check if one title is a matchup and the other is a generic tournament/league title at the same venue
                    $candIsLeague = self::isLeagueOrTournamentTitle($candidate->title);
                    $dtoIsLeague = self::isLeagueOrTournamentTitle($dto->title);

                    if (($candIsLeague || $dtoIsLeague) && ($candTeams || $dtoTeams)) {
                        $sameLocation = $locationId && $candidate->location_id && $locationId === $candidate->location_id;
                        $matchupTeams = $dtoTeams ?: $candTeams;
                        $allTeamsWords = array_merge($matchupTeams[0], $matchupTeams[1]);

                        $leagueText = mb_strtolower(($candIsLeague ? $candidate->title . ' ' . $candidate->description : $dto->title . ' ' . $dto->description) ?? '', 'UTF-8');
                        $hasTeamMention = false;
                        foreach ($allTeamsWords as $teamWord) {
                            if (mb_strlen($teamWord, 'UTF-8') >= 3 && str_contains($leagueText, $teamWord)) {
                                $hasTeamMention = true;
                                break;
                            }
                        }

                        $sameExactTime = ($candidate->start_at && $dto->startAt && $candidate->start_at->format('H:i') === $dto->startAt->format('H:i') && $candidate->start_at->format('H:i') !== '00:00');

                        if ($sameLocation && ($hasTeamMention || $sameExactTime)) {
                            $existingEvent = $candidate;
                            break;
                        }
                    }

                    // Check if matchup teams appear in candidate or dto description
                    if ($dtoTeams && !empty($candidate->description)) {
                        $candDescLower = mb_strtolower($candidate->description, 'UTF-8');
                        $hasTeam1 = false;
                        foreach ($dtoTeams[0] as $w) {
                            if (mb_strlen($w, 'UTF-8') >= 3 && str_contains($candDescLower, $w)) {
                                $hasTeam1 = true;
                                break;
                            }
                        }
                        $hasTeam2 = false;
                        foreach ($dtoTeams[1] as $w) {
                            if (mb_strlen($w, 'UTF-8') >= 3 && str_contains($candDescLower, $w)) {
                                $hasTeam2 = true;
                                break;
                            }
                        }
                        if ($hasTeam1 && $hasTeam2) {
                            $sameLocation = $locationId && $candidate->location_id && $locationId === $candidate->location_id;
                            $sameExactTime = ($candidate->start_at && $dto->startAt && $candidate->start_at->format('H:i') === $dto->startAt->format('H:i') && $candidate->start_at->format('H:i') !== '00:00');
                            if ($sameLocation || $sameExactTime) {
                                $existingEvent = $candidate;
                                break;
                            }
                        }
                    }

                    if ($candTeams && !empty($dto->description)) {
                        $dtoDescLower = mb_strtolower($dto->description, 'UTF-8');
                        $hasTeam1 = false;
                        foreach ($candTeams[0] as $w) {
                            if (mb_strlen($w, 'UTF-8') >= 3 && str_contains($dtoDescLower, $w)) {
                                $hasTeam1 = true;
                                break;
                            }
                        }
                        $hasTeam2 = false;
                        foreach ($candTeams[1] as $w) {
                            if (mb_strlen($w, 'UTF-8') >= 3 && str_contains($dtoDescLower, $w)) {
                                $hasTeam2 = true;
                                break;
                            }
                        }
                        if ($hasTeam1 && $hasTeam2) {
                            $sameLocation = $locationId && $candidate->location_id && $locationId === $candidate->location_id;
                            $sameExactTime = ($candidate->start_at && $dto->startAt && $candidate->start_at->format('H:i') === $dto->startAt->format('H:i') && $candidate->start_at->format('H:i') !== '00:00');
                            if ($sameLocation || $sameExactTime) {
                                $existingEvent = $candidate;
                                break;
                            }
                        }
                    }

                    $cleanCand = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($candidate->title, 'UTF-8'));

                    // 1. Number differentiator guard (e.g. 5 vs 10 spēļu abonements, 1. daļa vs 2. daļa)
                    preg_match_all('/\b\d+\b/', $cleanCand, $candNums);
                    preg_match_all('/\b\d+\b/', $cleanDto, $dtoNums);
                    $candNumDiff = array_diff($candNums[0] ?? [], $dtoNums[0] ?? []);
                    $dtoNumDiff = array_diff($dtoNums[0] ?? [], $candNums[0] ?? []);
                    $candNonYear = array_filter($candNumDiff, fn ($n) => (int)$n < 2000 || (int)$n > 2100);
                    $dtoNonYear = array_filter($dtoNumDiff, fn ($n) => (int)$n < 2000 || (int)$n > 2100);
                    if (!empty($candNonYear) || !empty($dtoNonYear)) {
                        continue;
                    }

                    // 2. Package modifier guard (e.g. 'enbl', 'biznesa', 'vip')
                    $distinguishingModifiers = ['enbl', 'biznesa', 'vip', 'premium', 'standart'];
                    $hasModifierMismatch = false;
                    foreach ($distinguishingModifiers as $mod) {
                        if (str_contains($cleanCand, $mod) !== str_contains($cleanDto, $mod)) {
                            $hasModifierMismatch = true;
                            break;
                        }
                    }
                    if ($hasModifierMismatch) {
                        continue;
                    }

                    // 3. Direct string similarity
                    similar_text($cleanCand, $cleanDto, $percent);
                    if ($percent >= 75) {
                        $existingEvent = $candidate;
                        break;
                    }

                    // 4. Substring check
                    if ((mb_strlen($cleanDto, 'UTF-8') > 12 && str_contains($cleanCand, $cleanDto)) || (mb_strlen($cleanCand, 'UTF-8') > 12 && str_contains($cleanDto, $cleanCand))) {
                        $existingEvent = $candidate;
                        break;
                    }

                    // 5. Exact word token overlap on same day
                    if (!empty($dtoWords)) {
                        $candWords = array_values(array_filter(explode(' ', $cleanCand), fn ($w) => mb_strlen($w, 'UTF-8') > 2 && !in_array($w, ['un', 'par', 'ar', 'pie', 'uz', 'no', 'vai'])));
                        $common = array_intersect($dtoWords, $candWords);
                        if (count($common) >= 2 && !empty($candWords)) {
                            $overlap = (count($common) / min(count($dtoWords), count($candWords))) * 100;
                            if ($overlap >= 60) {
                                $existingEvent = $candidate;
                                break;
                            }
                        }
                    }

                    // 6. Stemmed word overlap (Latvian declension-aware)
                    $candStems = $this->getLatvianWordStems($candidate->title);
                    $commonStems = array_intersect($dtoStems, $candStems);
                    if (count($commonStems) >= 2) {
                        $stemOverlap = (count($commonStems) / min(count($dtoStems), count($candStems))) * 100;
                        if ($stemOverlap >= 65) {
                            $existingEvent = $candidate;
                            break;
                        }
                    }

                    // 5. Cross-field title in candidate description check
                    $candDescLower = mb_strtolower($candidate->description ?? '', 'UTF-8');
                    $cleanDtoTitleNoPunct = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($dto->title, 'UTF-8')));
                    if (mb_strlen($cleanDtoTitleNoPunct, 'UTF-8') >= 8 && str_contains($candDescLower, $cleanDtoTitleNoPunct)) {
                        $existingEvent = $candidate;
                        break;
                    }

                    // Check key stemmed phrase in description
                    if (count($dtoStems) >= 2) {
                        $allDtoStemsInDesc = true;
                        foreach ($dtoStems as $stem) {
                            if (!str_contains($candDescLower, $stem)) {
                                $allDtoStemsInDesc = false;
                                break;
                            }
                        }
                        if ($allDtoStemsInDesc) {
                            $existingEvent = $candidate;
                            break;
                        }
                    }

                    // 6. Same date range (start_at + end_at) with at least 1 significant stem matching
                    if ($dto->endAt && $candidate->end_at && $dto->endAt->toDateString() === $candidate->end_at->toDateString()) {
                        if (count($commonStems) >= 1) {
                            $existingEvent = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($existingEvent) {
                if ($existingEvent->trashed()) {
                    $isUpcoming = ($dto->startAt && $dto->startAt >= now()->startOfDay())
                        || ($dto->endAt && $dto->endAt >= now()->startOfDay())
                        || ($existingEvent->start_at && $existingEvent->start_at >= now()->startOfDay())
                        || ($existingEvent->end_at && $existingEvent->end_at >= now()->startOfDay());

                    if ($isUpcoming) {
                        $existingEvent->restore();
                    }
                }

                // Intelligent field fusion
                $existingIsGeneric = self::isGenericPlaceholderImage($existingEvent->image_url);
                $dtoIsGeneric = self::isGenericPlaceholderImage($dto->imageUrl);
                $dtoIsTicketing = self::isTicketingSource($source->slug, $dto->ticketUrl);
                $existingIsTicketing = self::isTicketingSource($existingEvent->source_slug, $existingEvent->ticket_url);

                if (!$existingIsGeneric && $dtoIsGeneric) {
                    $finalImageUrl = $existingEvent->image_url;
                } elseif ($existingIsGeneric && !$dtoIsGeneric) {
                    $finalImageUrl = $dto->imageUrl;
                } elseif (!$existingIsGeneric && !$dtoIsGeneric) {
                    if ($dtoIsTicketing && !$existingIsTicketing) {
                        $finalImageUrl = $dto->imageUrl;
                    } else {
                        $finalImageUrl = $existingEvent->image_url ?: $dto->imageUrl;
                    }
                } else {
                    $finalImageUrl = $dto->imageUrl ?: $existingEvent->image_url;
                    if (self::isGenericPlaceholderImage($finalImageUrl) && str_contains((string)$finalImageUrl, 'aplis-default-og-img.jpg')) {
                        $finalImageUrl = null;
                    }
                }

                $finalTicketUrl = $existingEvent->ticket_url;
                if ($dto->ticketUrl) {
                    if (empty($finalTicketUrl)) {
                        $finalTicketUrl = $dto->ticketUrl;
                    } elseif ($dtoIsTicketing && !$existingIsTicketing) {
                        $finalTicketUrl = $dto->ticketUrl;
                    } elseif ($existingEvent->source_id === $source->id) {
                        $finalTicketUrl = $dto->ticketUrl;
                    }
                }

                $pMin = $existingEvent->price_min;
                $pMax = $existingEvent->price_max;
                if ($dto->priceMin !== null || $dto->priceMax !== null) {
                    if ($pMin === null && $pMax === null) {
                        $pMin = $dto->priceMin;
                        $pMax = $dto->priceMax;
                    } elseif ($dtoIsTicketing && !$existingIsTicketing) {
                        $pMin = $dto->priceMin ?? $pMin;
                        $pMax = $dto->priceMax ?? $pMax;
                    } elseif ($existingEvent->source_id === $source->id) {
                        $pMin = $dto->priceMin !== null ? $dto->priceMin : $pMin;
                        $pMax = $dto->priceMax !== null ? $dto->priceMax : $pMax;
                    }
                }
                if ($pMax !== null && (float)$pMax <= 0) {
                    $pMax = null;
                }
                if ($pMin !== null && ((float)$pMin > 99999.99 || (float)$pMin < 0)) {
                    $pMin = null;
                }
                if ($pMax !== null && ((float)$pMax > 99999.99 || (float)$pMax < 0)) {
                    $pMax = null;
                }
                if ($pMin !== null && $pMax !== null && (float)$pMin > (float)$pMax) {
                    [$pMin, $pMax] = [$pMax, $pMin];
                }

                $candIsLeague = self::isLeagueOrTournamentTitle($existingEvent->title);
                $dtoIsLeague = self::isLeagueOrTournamentTitle($dto->title);

                $finalTitle = $existingEvent->title;
                if ($candIsLeague && !$dtoIsLeague && !empty($dto->title)) {
                    $finalTitle = $dto->title;
                } elseif (!$candIsLeague && empty($finalTitle) && !empty($dto->title)) {
                    $finalTitle = $dto->title;
                } elseif ($existingEvent->source_id === $source->id && !empty($dto->title)) {
                    $finalTitle = $dto->title;
                }

                $finalDescription = self::mergeDescriptions($existingEvent->description, $dto->description);
                $finalShortDesc = $existingEvent->short_description ?: $dto->shortDescription;

                $finalSourceUrl = $existingEvent->source_url;
                if (empty($finalSourceUrl) || ($dtoIsTicketing && !$existingIsTicketing)) {
                    $finalSourceUrl = $dto->sourceUrl ?: $finalSourceUrl;
                }

                // Update existing event details
                $updateData = [
                    'source_id' => $existingEvent->source_id ?: $source->id,
                    'source_slug' => $existingEvent->source_slug ?: $source->slug,
                    'source_url' => $finalSourceUrl,
                    'location_id' => $locationId ?: $existingEvent->location_id,
                    'start_at' => $dto->startAt ?: $existingEvent->start_at,
                    'end_at' => $dto->endAt ?: $existingEvent->end_at,
                    'image_url' => $finalImageUrl,
                    'ticket_url' => $finalTicketUrl,
                    'is_free' => ($pMin !== null && (float)$pMin > 0) ? false : ($dto->isFree && $existingEvent->is_free !== false),
                    'price_min' => $pMin,
                    'price_max' => $pMax,
                    'entertainment_type' => $dto->entertainmentType ?: (($existingEvent->entertainment_type && !in_array($existingEvent->entertainment_type, ['chill', 'exhibition'], true)) ? $existingEvent->entertainment_type : $this->guessEntertainmentType($finalTitle ?: $dto->title, $dto->categoryNames)),
                    'raw_data' => array_merge($existingEvent->raw_data ?? [], $dto->rawData),
                ];

                if ($finalImageUrl !== $existingEvent->image_url) {
                    $updateData['internal_image_url'] = null;
                }

                if ($locale === 'lv' || empty($existingEvent->title)) {
                    $updateData['title'] = $finalTitle ?: $existingEvent->title;
                    $updateData['description'] = $finalDescription ?: $existingEvent->description;
                    $updateData['short_description'] = $finalShortDesc ?: $existingEvent->short_description;
                }

                $existingEvent->update($updateData);

                // Save or update translation in event_translations table
                EventTranslation::updateOrCreate(
                    [
                        'event_id' => $existingEvent->id,
                        'locale' => $locale,
                    ],
                    [
                        'title' => $finalTitle ?: $dto->title,
                        'slug' => Str::slug($finalTitle ?: $dto->title) . '-' . substr(md5($existingEvent->id . $locale), 0, 6),
                        'description' => $finalDescription ?: $dto->description,
                        'short_description' => $finalShortDesc ?: ($dto->shortDescription ?: (mb_strlen($finalDescription ?? '') <= 220 ? $finalDescription : Str::limit(strip_tags($finalDescription ?? ''), 160))),
                    ]
                );

                if (!empty($categoryIds)) {
                    if ($existingEvent->source_id === $source->id) {
                        $existingEvent->categories()->sync($categoryIds);
                    } else {
                        $existingEvent->categories()->syncWithoutDetaching($categoryIds);
                    }
                }

                $this->inheritSiblingTranslations($existingEvent);

                return 'updated';
            }

            $newPMin = $dto->priceMin;
            $newPMax = $dto->priceMax;
            if ($newPMax !== null && (float)$newPMax <= 0) {
                $newPMax = null;
            }
            if ($newPMin !== null && ((float)$newPMin > 99999.99 || (float)$newPMin < 0)) {
                $newPMin = null;
            }
            if ($newPMax !== null && ((float)$newPMax > 99999.99 || (float)$newPMax < 0)) {
                $newPMax = null;
            }
            if ($newPMin !== null && $newPMax !== null && (float)$newPMin > (float)$newPMax) {
                [$newPMin, $newPMax] = [$newPMax, $newPMin];
            }

            // 4. Create new event
            $event = Event::create([
                'source_id' => $source->id,
                'source_slug' => $source->slug,
                'location_id' => $locationId,
                'title' => $dto->title,
                'slug' => Str::slug($dto->title) . '-' . Str::random(6),
                'description' => $dto->description,
                'short_description' => $dto->shortDescription ?: Str::limit(strip_tags($dto->description ?? ''), 160),
                'start_at' => $dto->startAt,
                'end_at' => $dto->endAt,
                'all_day' => false,
                'is_free' => $dto->isFree,
                'price_min' => $newPMin,
                'price_max' => $newPMax,
                'currency' => $dto->currency ?: 'EUR',
                'ticket_url' => $dto->ticketUrl,
                'image_url' => $dto->imageUrl,
                'source_url' => $dto->sourceUrl,
                'source_external_id' => $dto->sourceExternalId,
                'fingerprint' => $fingerprint,
                'entertainment_type' => $dto->entertainmentType ?: $this->guessEntertainmentType($dto->title, $dto->categoryNames),
                'status' => 'published',
                'published_at' => now(),
                'raw_data' => $dto->rawData,
            ]);

            // Save translation in event_translations table
            EventTranslation::create([
                'event_id' => $event->id,
                'locale' => $locale,
                'title' => $dto->title,
                'slug' => Str::slug($dto->title) . '-' . substr(md5($event->id . $locale), 0, 6),
                'description' => $dto->description,
                'short_description' => $dto->shortDescription ?: (mb_strlen($dto->description ?? '') <= 220 ? $dto->description : Str::limit(strip_tags($dto->description ?? ''), 160)),
            ]);

            if (!empty($categoryIds)) {
                $event->categories()->sync($categoryIds);
            }

            $this->inheritSiblingTranslations($event);

            return 'created';
        });
    }

    private function guessRegionByCity(string $city): string
    {
        $map = [
            'Rīga' => 'Rīga un Pierīga',
            'Jūrmala' => 'Rīga un Pierīga',
            'Sigulda' => 'Vidzeme',
            'Cēsis' => 'Vidzeme',
            'Valmiera' => 'Vidzeme',
            'Madona' => 'Vidzeme',
            'Ogre' => 'Vidzeme',
            'Liepāja' => 'Kurzeme',
            'Ventspils' => 'Kurzeme',
            'Kuldīga' => 'Kurzeme',
            'Talsi' => 'Kurzeme',
            'Saldus' => 'Kurzeme',
            'Tukums' => 'Kurzeme',
            'Jelgava' => 'Zemgale',
            'Bauska' => 'Zemgale',
            'Dobele' => 'Zemgale',
            'Aizkraukle' => 'Zemgale',
            'Koknese' => 'Zemgale',
            'Pļaviņas' => 'Zemgale',
            'Jaunjelgava' => 'Zemgale',
            'Skrīveri' => 'Zemgale',
            'Nereta' => 'Zemgale',
            'Daugavpils' => 'Latgale',
            'Rēzekne' => 'Latgale',
        ];

        return $map[$city] ?? 'Latvija';
    }

    private function guessCategoryIcon(string $name): string
    {
        $lower = mb_strtolower($name);
        if (str_contains($lower, 'koncert') || str_contains($lower, 'mūzik') || str_contains($lower, 'dziesm') || str_contains($lower, 'music')) return 'music';
        if (str_contains($lower, 'sport') || str_contains($lower, 'skriešan') || str_contains($lower, 'vel')) return 'activity';
        if (str_contains($lower, 'bērn') || str_contains($lower, 'ģimen') || str_contains($lower, 'kid') || str_contains($lower, 'famil')) return 'smile';
        if (str_contains($lower, 'dab') || str_contains($lower, 'pārgājien') || str_contains($lower, 'hike') || str_contains($lower, 'nature')) return 'trees';
        if (str_contains($lower, 'teātr') || str_contains($lower, 'kino') || str_contains($lower, 'theatre') || str_contains($lower, 'cinema')) return 'film';
        if (str_contains($lower, 'māksl') || str_contains($lower, 'izstād') || str_contains($lower, 'art') || str_contains($lower, 'exhibit')) return 'palette';
        if (str_contains($lower, 'ēdien') || str_contains($lower, 'tirdziņ') || str_contains($lower, 'food') || str_contains($lower, 'gastronom')) return 'utensils';
        if (str_contains($lower, 'festivāl') || str_contains($lower, 'svētk') || str_contains($lower, 'fest') || str_contains($lower, 'lāčplēš') || str_contains($lower, 'lāpu')) return 'sparkles';
        return 'calendar';
    }

    private function guessCategoryColor(string $name): string
    {
        $lower = mb_strtolower($name);
        if (str_contains($lower, 'koncert') || str_contains($lower, 'mūzik') || str_contains($lower, 'music')) return 'purple';
        if (str_contains($lower, 'sport') || str_contains($lower, 'aktīv')) return 'blue';
        if (str_contains($lower, 'bērn') || str_contains($lower, 'ģimen') || str_contains($lower, 'kid')) return 'amber';
        if (str_contains($lower, 'dab') || str_contains($lower, 'pārgājien') || str_contains($lower, 'hike')) return 'emerald';
        if (str_contains($lower, 'teātr') || str_contains($lower, 'māksl') || str_contains($lower, 'theatre')) return 'rose';
        if (str_contains($lower, 'ēdien') || str_contains($lower, 'garš') || str_contains($lower, 'food')) return 'orange';
        if (str_contains($lower, 'festivāl') || str_contains($lower, 'svētk') || str_contains($lower, 'lāčplēš') || str_contains($lower, 'lāpu')) return 'pink';
        return 'teal';
    }

    private function guessEntertainmentType(string $title, array $categories): string
    {
        $text = mb_strtolower($title . ' ' . implode(' ', $categories));

        if (str_contains($text, 'bērn') || str_contains($text, 'ģimen') || str_contains($text, 'kids') || str_contains($text, 'children')) return 'family';
        if (str_contains($text, 'koncert') || str_contains($text, 'mūzik') || str_contains($text, 'concert') || str_contains($text, 'music')) return 'concert';

        $holidayRegex = '/(?:\b|(?<=[^a-zāčēģīķļņšūž]))((?:valsts\s+svētk|valsts\s+svetk)[a-zāčēģīķļņšūž]*|(?:lāčplēša|lacplesa)\s+dien[a-zāčēģīķļņšūž]*|(?:lāpu\s+gājien|lapu\s+gajien)[a-zāčēģīķļņšūž]*|proklamēšan[a-zāčēģīķļņšūž]*|proklamesan[a-zāčēģīķļņšūž]*|neatkarības\s+atjaunošan[a-zāčēģīķļņšūž]*|baltā\s+galdaut[a-zāčēģīķļņšūž]*|brīvības\s+cīnītāj[a-zāčēģīķļņšūž]*|barikāžu\s+atcer[a-zāčēģīķļņšūž]*|barikāžu\s+aizstāvj[a-zāčēģīķļņšūž]*|18\.\s*novembr[a-zāčēģīķļņšūž]*)(?:\b|(?=[^a-zāčēģīķļņšūž]))/ui';

        if (
            preg_match($holidayRegex, $text) ||
            str_contains($text, 'svētk') || str_contains($text, 'svetk') || str_contains($text, 'svinīb') ||
            str_contains($text, 'piemiņ') || str_contains($text, 'atcer') || str_contains($text, 'salūts')
        ) {
            return 'celebration';
        }

        if (str_contains($text, 'pārgājien') || str_contains($text, 'sport') || str_contains($text, 'skrējiens') || str_contains($text, 'marathon')) return 'active';
        if (str_contains($text, 'festivāl') || str_contains($text, 'ballīt') || str_contains($text, 'party') || str_contains($text, 'festival')) return 'party';
        if (str_contains($text, 'meistarklas') || str_contains($text, 'seminār') || str_contains($text, 'workshop') || str_contains($text, 'lecture')) return 'workshop';
        if (str_contains($text, 'izstād') || str_contains($text, 'muzej') || str_contains($text, 'exhibition') || str_contains($text, 'museum')) return 'exhibition';

        return 'chill';
    }

    private function getLatvianWordStems(string $text): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text, 'UTF-8'));
        $words = array_values(array_filter(explode(' ', $clean), fn ($w) => mb_strlen($w, 'UTF-8') > 2 && !in_array($w, ['un', 'par', 'ar', 'pie', 'uz', 'no', 'vai', 'kas', 'tas', 'būs', 'kā'])));

        $stems = [];
        $suffixes = [
            'ajiem', 'ajām', 'ajam', 'ajai', 'ajās', 'ajos',
            'iem', 'ām', 'am', 'os', 'ēs', 'as', 'es', 'is', 'us',
            'ās', 'ēs', 'īs', 'os', 'ei', 'im', 'um', 'om', 'ai',
            'ju', 'ja', 'ļa', 'ņa', 'ra', 'sa', 'ta', 'da', 'ba', 'ka', 'ga', 'ma', 'va', 'za', 'ža', 'ša', 'ča',
            'dā', 'tā', 'mā', 'kā', 'sā', 'vā', 'rā', 'lā', 'bā', 'zā', 'žā', 'šā', 'čā',
            'a', 'e', 'i', 'u', 'o', 's', 'š', 'ā', 'ē', 'ī', 'ū',
        ];

        foreach ($words as $w) {
            $stem = $w;
            foreach ($suffixes as $suf) {
                if (mb_strlen($w, 'UTF-8') - mb_strlen($suf, 'UTF-8') >= 3 && str_ends_with($w, $suf)) {
                    $candidate = mb_substr($w, 0, mb_strlen($w, 'UTF-8') - mb_strlen($suf, 'UTF-8'), 'UTF-8');
                    if (mb_strlen($candidate, 'UTF-8') >= 3) {
                        $stem = $candidate;
                        break;
                    }
                }
            }
            $stems[] = $stem;
        }

        return array_values(array_unique($stems));
    }

    public function resolveOrCreateLocation(ScrapedEventDTO $dto, string $locale = 'lv'): ?int
    {
        if (!$dto->venueName && !$dto->city && !$dto->latitude) {
            return null;
        }

        $rawVenue = trim($dto->venueName ?? '');
        $cityName = trim($dto->city ?: 'Rīga');
        $regionName = $dto->region ?: $this->guessRegionByCity($cityName);

        // Clean venue name: strip quotes, decode entities
        $locationName = $rawVenue ?: ($cityName . ' centrs');
        $cleanQueryName = $this->cleanLocationName($locationName);

        $rawOrg = is_string($dto->rawData['organizer'] ?? null) ? mb_strtolower($dto->rawData['organizer'], 'UTF-8') : '';
        $isNationalTheatreOrg = str_contains($rawOrg, 'nacionālais teātris') || str_contains($rawOrg, 'nacionalais teatris');
        $isJrtOrg = str_contains($rawOrg, 'jaunais rīgas') || str_contains($rawOrg, 'jaunais rigas');
        $isDailesOrg = str_contains($rawOrg, 'dailes teātris') || str_contains($rawOrg, 'dailes teatris');

        // If venue is a bare generic hall name and organizer is a recognized major theatre, expand query name
        if (preg_match('/^(?:lielā|mazā|jaunā|aktieru|kamer|skatītāju)\s*zāle$/ui', trim($cleanQueryName))) {
            if ($isNationalTheatreOrg) {
                $cleanQueryName = 'Latvijas Nacionālais teātris, ' . $cleanQueryName;
                $locationName = $cleanQueryName;
            } elseif ($isDailesOrg) {
                $cleanQueryName = 'Dailes teātris, ' . $cleanQueryName;
                $locationName = $cleanQueryName;
            } elseif ($isJrtOrg) {
                $cleanQueryName = 'Jaunais Rīgas teātris, ' . $cleanQueryName;
                $locationName = $cleanQueryName;
            }
        }

        // 1. Exact / direct translation match in same city
        $location = Location::where(function ($q) use ($locationName, $cleanQueryName) {
            $q->where('name', $locationName)
              ->orWhere('name', $cleanQueryName)
              ->orWhereHas('translations', function ($tq) use ($locationName, $cleanQueryName) {
                  $tq->where('name', $locationName)
                     ->orWhere('name', $cleanQueryName);
              });
        })->where(function ($q) use ($cityName) {
            $q->where('city', $cityName)
              ->orWhereHas('translations', function ($tq) use ($cityName) {
                  $tq->where('city', $cityName);
              });
        })->first();

        // 2. Fuzzy / base venue / address match in the same city
        if (!$location) {
            $cityLocations = Location::where(function ($q) use ($cityName) {
                $q->where('city', $cityName)
                  ->orWhereHas('translations', function ($tq) use ($cityName) {
                      $tq->where('city', $cityName);
                  });
            })->get();

            $baseQueryName = $this->getBaseVenueName($cleanQueryName);
            $queryStreet = $this->extractStreetAndNumber($dto->address);

            foreach ($cityLocations as $candidate) {
                $candName = $candidate->name;
                $cleanCandName = $this->cleanLocationName($candName);
                $candLower = mb_strtolower($cleanCandName, 'UTF-8');

                // Safeguard against theater organizer cross-contamination
                if ($isNationalTheatreOrg && (str_contains($candLower, 'jaunais rīgas') || str_contains($candLower, 'jaunais rigas') || str_contains($candLower, 'dailes'))) {
                    continue;
                }
                if ($isJrtOrg && (str_contains($candLower, 'nacionāl') || str_contains($candLower, 'nacional') || str_contains($candLower, 'dailes'))) {
                    continue;
                }
                if ($isDailesOrg && (str_contains($candLower, 'nacionāl') || str_contains($candLower, 'nacional') || str_contains($candLower, 'jaunais rīgas') || str_contains($candLower, 'jaunais rigas'))) {
                    continue;
                }

                $baseCandName = $this->getBaseVenueName($cleanCandName);
                $candStreet = $this->extractStreetAndNumber($candidate->address);

                // A. Base venue match (ignoring hall/zāle suffixes or address in parentheses)
                if (mb_strlen($baseQueryName, 'UTF-8') >= 4 && mb_strtolower($baseQueryName, 'UTF-8') === mb_strtolower($baseCandName, 'UTF-8')) {
                    $location = $candidate;
                    break;
                }

                // B. Substring match if one venue name is fully contained in another (e.g. "Tradīciju māja" in "Daugavpils Vienības nama Tradīciju māja")
                $normQuery = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($cleanQueryName, 'UTF-8'));
                $normCand = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($cleanCandName, 'UTF-8'));
                $genericHalls = ['liela zale', 'maza zale', 'kamerzale', 'jauna zale', 'aktieru zale', 'skatitaju zale', 'balta zale', 'koncertzale'];
                $isGenericHall = in_array(trim($normQuery), $genericHalls, true);

                if (!$isGenericHall && mb_strlen($normQuery, 'UTF-8') >= 8 && mb_strlen($normCand, 'UTF-8') >= 8) {
                    if (str_contains($normCand, $normQuery) || str_contains($normQuery, $normCand)) {
                        $location = $candidate;
                        break;
                    }
                }

                // C. Street address match in the same city
                if ($queryStreet && $candStreet && mb_strtolower($queryStreet, 'UTF-8') === mb_strtolower($candStreet, 'UTF-8')) {
                    $location = $candidate;
                    break;
                }

                // D. Stemmed token overlap
                $queryStems = $this->getLatvianWordStems($cleanQueryName);
                $candStems = $this->getLatvianWordStems($cleanCandName);
                if (count($queryStems) >= 2 && count($candStems) >= 2) {
                    $common = array_intersect($queryStems, $candStems);
                    $overlap = (count($common) / max(count($queryStems), count($candStems))) * 100;
                    if ($overlap >= 75) {
                        $location = $candidate;
                        break;
                    }
                }
            }
        }

        if (!$location) {
            $address = $dto->address;
            $latitude = $dto->latitude;
            $longitude = $dto->longitude;

            if ($isNationalTheatreOrg && (!$address || str_contains($cleanQueryName, 'Nacionālais teātris') || str_contains($cleanQueryName, 'Nacionālā teātr'))) {
                $address = $address ?: 'Kronvalda bulvāris 2';
                $latitude = $latitude ?: 56.953631;
                $longitude = $longitude ?: 24.104910;
            }

            $location = Location::create([
                'name' => $cleanQueryName,
                'city' => $cityName,
                'region' => $regionName,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'longitude' => $dto->longitude,
                'place_type' => $dto->placeType ?: 'venue',
            ]);
        } else {
            // Enrich existing location if new data is more descriptive / complete
            $locUpdate = [];
            if (mb_strlen($cleanQueryName, 'UTF-8') > mb_strlen($location->name, 'UTF-8') && !str_contains($cleanQueryName, '(')) {
                $locUpdate['name'] = $cleanQueryName;
            }
            if (empty($location->address) && !empty($dto->address)) {
                $locUpdate['address'] = $dto->address;
            }
            if (empty($location->latitude) && !empty($dto->latitude)) {
                $locUpdate['latitude'] = $dto->latitude;
                $locUpdate['longitude'] = $dto->longitude;
            }
            if (!empty($locUpdate)) {
                $location->update($locUpdate);
            }
        }

        LocationTranslation::updateOrCreate(
            [
                'location_id' => $location->id,
                'locale' => $locale,
            ],
            [
                'name' => $location->name,
                'city' => $cityName,
                'region' => $regionName,
                'address' => $dto->address ?: $location->address,
            ]
        );

        return $location->id;
    }

    private function cleanLocationName(string $name): string
    {
        $clean = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = str_replace(['“', '”', '«', '»', '"', '’', '`'], '', $clean);
        return trim(preg_replace('/\s+/', ' ', $clean));
    }

    private function getBaseVenueName(string $name): string
    {
        $name = preg_replace('/\s*\([^)]*\)/', '', $name); // remove (address...)
        // strip hall suffixes
        $name = preg_replace('/,?\s*(?:Lielā zāle|Mazā zāle|Jaunā zāle|Kamerzāle|Kora zāle|Koncertzāle|Eksperimentālā skatuve|MAZĀ ZĀLE|LIELĀ ZĀLE|1\.\s*stāvs|2\.\s*stāvs)$/iu', '', $name);
        return trim($name);
    }

    private function extractStreetAndNumber(?string $address): ?string
    {
        if (empty($address)) return null;
        // match e.g. "Rīgas iela 22a", "Spīdolas iela 2", "Brīvības bulvāris 36", "Stadiona iela 1"
        if (preg_match('/([\p{L}\s]+(?:iela|bulvāris|gatve|prospekts|laukums|krastmala|dambis)\s+\d+[a-z]?)/iu', $address, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function normalizeCategoryName(string $name): string
    {
        $slug = ConsolidateCategoriesCommand::mapToCanonicalSlug($name);
        return ConsolidateCategoriesCommand::CANONICAL_CATEGORIES[$slug]['name'] ?? 'Cits';
    }

    public function resolveCanonicalCategory(string $rawNameOrKeyword): Category
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


    public function getMeaningfulTitleTokens(string $title): array
    {
        $clean = preg_replace('/[^\p{L}\s]+/u', ' ', mb_strtolower($title, 'UTF-8'));
        $stopwords = [
            'un', 'par', 'ar', 'pie', 'uz', 'no', 'vai', 'lai', 'kas', 'kur', 'kad', 'kā', 'ir', 'būt', 'arī', 'tiek', 'jeb',
            'izstāde', 'izstādes', 'izstādei', 'izstādi', 'izstādē', 'pasākums', 'pasākumi', 'pasākumu', 'nodarbība', 'nodarbības',
            'septembrī', 'oktobrī', 'novembrī', 'decembrī', 'janvārī', 'februārī', 'martā', 'aprīlī', 'maijā', 'jūnijā', 'jūlijā', 'augustā',
            'novadā', 'novads', 'pagastā', 'pilsētā', 'centrā', 'kultūras', 'nams',
            'the', 'and', 'or', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'an', 'a',
            'exhibition', 'event', 'events', 'festival',
            'и', 'в', 'во', 'на', 'с', 'со', 'по', 'к', 'ко', 'у', 'о', 'об', 'для', 'от', 'до', 'из', 'или', 'как', 'это', 'что',
            'выставка', 'выставки', 'мероприятие', 'фестиваль'
        ];

        $words = explode(' ', $clean);
        $meaningful = [];
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w, 'UTF-8') >= 3 && !in_array($w, $stopwords, true)) {
                $meaningful[] = $w;
            }
        }

        return array_values(array_unique($meaningful));
    }

    public function calculateCandidateQualityScore(Event $cand): float
    {
        $score = 0.0;
        $lvTrans = $cand->translations->firstWhere('locale', 'lv');
        $enTrans = $cand->translations->firstWhere('locale', 'en');
        $ruTrans = $cand->translations->firstWhere('locale', 'ru');

        if ($lvTrans && !empty(trim($lvTrans->description ?? ''))) {
            $score += 10.0;
            $score += min(mb_strlen(trim($lvTrans->description)) / 500, 2.0);
        }
        if ($enTrans && !empty(trim($enTrans->description ?? ''))) {
            $score += 2.0;
            $score += min(mb_strlen(trim($enTrans->description)) / 500, 1.0);
        }
        if ($ruTrans && !empty(trim($ruTrans->description ?? ''))) {
            $score += 2.0;
            $score += min(mb_strlen(trim($ruTrans->description)) / 500, 1.0);
        }
        if (!empty(trim($cand->description ?? ''))) {
            $score += 2.0;
        }
        if ($cand->source_slug === 'afiro-api' || ($cand->source && $cand->source->slug === 'afiro-api')) {
            $score += 1.0;
        }

        return $score;
    }

    public function findSiblingWithTranslations(Event $event): ?Event
    {
        $tokens = $this->getMeaningfulTitleTokens($event->title);
        $eventTicketUrl = $event->ticket_url;
        $eventSourceUrl = $event->source_url;
        $eventExternalId = $event->source_external_id;

        // 1. Direct match by shared ticketing platform URL or external identifier
        if ($eventTicketUrl || $eventSourceUrl || $eventExternalId) {
            $ticketCandidates = Event::with('translations')
                ->whereHas('translations', function ($q) {
                    $q->whereNotNull('description')->where('description', '!=', '');
                })
                ->where('id', '!=', $event->id)
                ->where(function ($q) use ($eventTicketUrl, $eventSourceUrl, $eventExternalId) {
                    if ($eventTicketUrl) {
                        $q->where('ticket_url', $eventTicketUrl);
                    }
                    if ($eventSourceUrl) {
                        $q->orWhere('source_url', $eventSourceUrl);
                    }
                    if ($eventExternalId) {
                        $q->orWhere('source_external_id', $eventExternalId);
                    }
                })
                ->get()
                ->sortByDesc(fn ($c) => $this->calculateCandidateQualityScore($c));

            $topTicket = $ticketCandidates->first();
            if ($topTicket && $this->calculateCandidateQualityScore($topTicket) >= 10.0) {
                return $topTicket;
            }
        }

        // 2. Search for candidates with translations and non-empty description
        // First try same location, then try cross-location for multi-venue/cinema/theatre events
        $locationCandidate = null;
        if ($event->location_id) {
            $locationCandidates = Event::with('translations')
                ->whereHas('translations', function ($q) {
                    $q->whereNotNull('description')->where('description', '!=', '');
                })
                ->where('id', '!=', $event->id)
                ->where('location_id', $event->location_id)
                ->get();

            $locationCandidate = $this->evaluateSiblingCandidates($event, $locationCandidates, $tokens, false);
            if ($locationCandidate && $this->calculateCandidateQualityScore($locationCandidate) >= 10.0) {
                return $locationCandidate;
            }
        }

        // If no sibling found at same location or location candidate lacks LV description, search across other locations
        if (!empty($tokens)) {
            $crossLocationCandidates = Event::with('translations')
                ->whereHas('translations', function ($q) {
                    $q->whereNotNull('description')->where('description', '!=', '');
                })
                ->where('id', '!=', $event->id)
                ->get();

            $crossCandidate = $this->evaluateSiblingCandidates($event, $crossLocationCandidates, $tokens, true);
            if ($crossCandidate) {
                if (!$locationCandidate || $this->calculateCandidateQualityScore($crossCandidate) > $this->calculateCandidateQualityScore($locationCandidate)) {
                    return $crossCandidate;
                }
            }
        }

        return $locationCandidate ?? ($topTicket ?? null);
    }

    private function evaluateSiblingCandidates(Event $event, $candidates, array $tokens, bool $crossLocation = false): ?Event
    {
        $bestCandidate = null;
        $bestTotalScore = 0;
        $minTitleScore = $crossLocation ? 0.75 : 0.40;

        // Gather all title variations from the target event (e.g. from existing translations)
        $targetTitles = [$event->title];
        if ($event->relationLoaded('translations')) {
            foreach ($event->translations as $t) {
                if (!empty($t->title)) $targetTitles[] = $t->title;
            }
        } else {
            foreach ($event->translations()->pluck('title') as $tTitle) {
                if (!empty($tTitle)) $targetTitles[] = $tTitle;
            }
        }
        $targetTitles = array_unique(array_filter($targetTitles));

        foreach ($candidates as $cand) {
            $candTitles = [$cand->title];
            foreach ($cand->translations as $ct) {
                if (!empty($ct->title)) {
                    $candTitles[] = $ct->title;
                }
            }
            $candTitles = array_unique(array_filter($candTitles));

            $highestCandTitleScore = 0.0;

            foreach ($targetTitles as $tTitle) {
                $tTokens = $this->getMeaningfulTitleTokens($tTitle);
                $tTitleLower = mb_strtolower(trim($tTitle), 'UTF-8');

                foreach ($candTitles as $cTitle) {
                    $cTitleLower = mb_strtolower(trim($cTitle), 'UTF-8');

                    // Exact match
                    if ($tTitleLower === $cTitleLower && mb_strlen($tTitleLower) >= 3) {
                        $highestCandTitleScore = max($highestCandTitleScore, 1.0);
                        continue;
                    }

                    $cTokens = $this->getMeaningfulTitleTokens($cTitle);
                    if (empty($tTokens) || empty($cTokens)) {
                        continue;
                    }

                    $common = array_intersect($tTokens, $cTokens);
                    $commonCount = count($common);

                    if ($commonCount >= 2) {
                        $overlapScore = $commonCount / max(count($tTokens), count($cTokens));
                        if ($overlapScore >= $minTitleScore) {
                            $highestCandTitleScore = max($highestCandTitleScore, $overlapScore);
                        }
                    } elseif ($commonCount >= 1 && count($tTokens) <= 3 && count($cTokens) <= 3) {
                        similar_text($tTitleLower, $cTitleLower, $simPercent);
                        $simScore = $simPercent / 100;
                        if ($simScore >= $minTitleScore && $simScore >= 0.70) {
                            $highestCandTitleScore = max($highestCandTitleScore, $simScore);
                        }
                    }
                }
            }

            if ($highestCandTitleScore >= $minTitleScore) {
                $qualityScore = $this->calculateCandidateQualityScore($cand);
                $totalScore = ($highestCandTitleScore * 20.0) + $qualityScore;

                if ($totalScore > $bestTotalScore) {
                    $bestTotalScore = $totalScore;
                    $bestCandidate = $cand;
                }
            }
        }

        return $bestCandidate;
    }

    public function inheritSiblingTranslations(Event $event): void
    {
        $sibling = $this->findSiblingWithTranslations($event);

        if (!$sibling) {
            return;
        }

        $targetLocales = ['lv', 'en', 'ru'];

        foreach ($targetLocales as $loc) {
            $existingTrans = $event->translations()->where('locale', $loc)->first();
            $sibTrans = $sibling->translations->firstWhere('locale', $loc);

            if ($sibTrans && !empty($sibTrans->description)) {
                if (!$existingTrans) {
                    EventTranslation::create([
                        'event_id' => $event->id,
                        'locale' => $loc,
                        'title' => $sibTrans->title,
                        'slug' => Str::slug($sibTrans->title) . '-' . substr(md5($event->id . $loc), 0, 6),
                        'description' => $sibTrans->description,
                        'short_description' => $sibTrans->short_description,
                    ]);
                } elseif (empty(trim($existingTrans->description ?? ''))) {
                    $existingTrans->update([
                        'description' => $sibTrans->description,
                        'short_description' => $sibTrans->short_description ?: (mb_strlen($sibTrans->description) <= 220 ? $sibTrans->description : Str::limit(strip_tags($sibTrans->description), 160)),
                    ]);
                }
            }
        }

        // Check if event itself has empty description or non-Latvian text in 'lv'
        $curLv = $event->translations()->where('locale', 'lv')->first();
        $sibLv = $sibling->translations->firstWhere('locale', 'lv');
        if ($sibLv && !empty($sibLv->description)) {
            $shouldUpdateMainLv = false;

            if (empty(trim($event->description ?? ''))) {
                $shouldUpdateMainLv = true;
            } elseif ($curLv) {
                $curLvLang = $this->detectTextLanguage($curLv->description ?: $curLv->title);
                $sibLvLang = $this->detectTextLanguage($sibLv->description ?: $sibLv->title);
                if ($curLvLang !== 'lv' && $sibLvLang === 'lv') {
                    $shouldUpdateMainLv = true;
                }
            }

            if ($shouldUpdateMainLv) {
                if ($curLv) {
                    $curLv->update([
                        'title' => $sibLv->title,
                        'description' => $sibLv->description,
                        'short_description' => $sibLv->short_description,
                    ]);
                }
                $event->update([
                    'title' => $sibLv->title,
                    'description' => $sibLv->description,
                    'short_description' => $sibLv->short_description,
                ]);
            }
        }
    }

    public function detectTextLanguage(?string $text): string
    {
        if (empty($text)) {
            return 'lv';
        }

        $ruHits = preg_match_all('/[\p{Cyrillic}]/u', $text);
        if ($ruHits > 5) {
            return 'ru';
        }

        $lower = mb_strtolower($text, 'UTF-8');
        $enHits = preg_match_all('/\b(the|and|in|is|are|of|to|with|for|during|from|we|you|please|will|not|have|can|this|that|on|at|by|tours?|exhibition|history|tickets?|visitors?|open|daily|adults?|students?|building|palace|museum)\b/u', $lower);
        $lvHits = preg_match_all('/\b(un|ir|par|ar|pie|no|vai|lai|mēs|jūs|kas|tiek|katru|dienu|lūdzu|cena|ieeja|biļetes|pēc|līdz|vieta|laiks|gads|gadā|stūra|māja|muzejs|skatāma|norise|iekļauts|apmeklēt)\b/u', $lower);

        if ($enHits > 3 && $enHits > $lvHits * 2) {
            return 'en';
        }

        return 'lv';
    }

    public static function isGenericPlaceholderImage(?string $url): bool
    {
        if (empty($url)) {
            return true;
        }
        $lower = mb_strtolower($url, 'UTF-8');
        return str_contains($lower, '/leagues/')
            || str_contains($lower, 'aplis-default-og-img.jpg')
            || str_contains($lower, 'default-event')
            || str_contains($lower, 'placeholder')
            || str_contains($lower, 'no-image');
    }

    public static function extractMatchupTeams(string $title): ?array
    {
        $clean = trim($title);
        if (preg_match('/^(.*?)\s+(?:vs\.?|pret|[-–—])\s+(.*?)$/iu', $clean, $matches)) {
            $t1 = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($matches[1], 'UTF-8')));
            $t2 = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($matches[2], 'UTF-8')));

            $stopWords = ['bk', 'fk', 'sk', 'hk', 'fc', 'sc', 'kk', 'vs', 'pret', 'spēle', 'spele', 'komanda', 'rīga', 'latvija'];
            $w1 = array_values(array_filter(explode(' ', $t1), fn($w) => mb_strlen($w, 'UTF-8') > 1 && !in_array($w, $stopWords, true)));
            $w2 = array_values(array_filter(explode(' ', $t2), fn($w) => mb_strlen($w, 'UTF-8') > 1 && !in_array($w, $stopWords, true)));

            if (!empty($w1) && !empty($w2)) {
                return [$w1, $w2];
            }
        }
        return null;
    }

    public static function areMatchupTeamsEquivalent(array $teamsA, array $teamsB): bool
    {
        $homeMatch = !empty(array_intersect($teamsA[0], $teamsB[0]));
        $awayMatch = !empty(array_intersect($teamsA[1], $teamsB[1]));
        if ($homeMatch && $awayMatch) {
            return true;
        }

        $revHomeMatch = !empty(array_intersect($teamsA[0], $teamsB[1]));
        $revAwayMatch = !empty(array_intersect($teamsA[1], $teamsB[0]));
        if ($revHomeMatch && $revAwayMatch) {
            return true;
        }

        return false;
    }

    public static function isLeagueOrTournamentTitle(string $title): bool
    {
        $clean = mb_strtolower(trim($title), 'UTF-8');
        if (preg_match('/\b(?:vs|pret)\b/u', $clean)) {
            return false;
        }
        if (preg_match('/\p{L}+\s*[-–—]\s*\p{L}+/u', $clean) && !str_contains($clean, 'līga') && !str_contains($clean, 'liga')) {
            return false;
        }

        return str_contains($clean, 'līga') || str_contains($clean, 'liga')
            || str_contains($clean, 'čempionāt') || str_contains($clean, 'cempionat')
            || str_contains($clean, 'virslīg') || str_contains($clean, 'virslig')
            || str_contains($clean, 'kauss') || str_contains($clean, 'turnīr')
            || str_contains($clean, 'meistarsacīkst') || str_contains($clean, 'meistarsacikst');
    }

    public static function mergeDescriptions(?string $existingDesc, ?string $newDesc): ?string
    {
        $d1 = trim((string) $existingDesc);
        $d2 = trim((string) $newDesc);

        if (empty($d1)) return $d2 ?: null;
        if (empty($d2)) return $d1 ?: null;
        if ($d1 === $d2) return $d1;

        $baseDesc = (mb_strlen($d1, 'UTF-8') >= mb_strlen($d2, 'UTF-8')) ? $d1 : $d2;
        $addonDesc = ($baseDesc === $d1) ? $d2 : $d1;

        $lines = explode("\n", $addonDesc);
        $uniqueAddons = [];
        $lowerBase = mb_strtolower($baseDesc, 'UTF-8');

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) continue;
            $lowerLine = mb_strtolower($trimmed, 'UTF-8');

            if (preg_match('/(?:tiešraid|tiesraid|broadcast|stream|kanāl|kanāls|tv\d|sportacentrs|youtube)/iu', $trimmed)) {
                if (!str_contains($lowerBase, $lowerLine)) {
                    $uniqueAddons[] = $trimmed;
                }
            }
        }

        if (!empty($uniqueAddons)) {
            return $baseDesc . "\n\n" . implode("\n", array_unique($uniqueAddons));
        }

        return $baseDesc;
    }

    public static function isTicketingSource(?string $sourceSlug, ?string $ticketUrl = null): bool
    {
        $ticketingSlugs = [
            'passportix', 'bilesuparadize', 'bilesu-paradize', 'bilesuserviss',
            'bilesu-serviss', 'bezrindas', 'ticketbest', 'ticketshop', 'aula', 'fienta', 'ekase',
        ];

        if ($sourceSlug && in_array(mb_strtolower($sourceSlug, 'UTF-8'), $ticketingSlugs, true)) {
            return true;
        }

        if (!empty($ticketUrl)) {
            $lower = mb_strtolower($ticketUrl, 'UTF-8');
            return (bool) preg_match('/(?:passportix\.eu|bilesuparadize\.lv|bilesuserviss\.lv|bezrindas\.lv|ticketbest\.(?:lv|eu)|ticketshop\.lv|aula\.lv|fienta\.com|ekase\.lv)/u', $lower);
        }

        return false;
    }
}

