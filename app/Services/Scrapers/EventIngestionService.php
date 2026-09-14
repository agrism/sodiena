<?php

namespace App\Services\Scrapers;

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
            $fingerprint = $dto->getFingerprint();
            $locale = $dto->locale ?: 'lv';

            // 1. Resolve or create Location and Location Translations
            $locationId = $this->resolveOrCreateLocation($dto, $locale);

            // 2. Resolve or create Categories and Category Translations
            $categoryIds = [];
            foreach ($dto->categoryNames as $catName) {
                if (empty($catName)) continue;
                $catNameTrimmed = trim($catName);
                $catSlug = Str::slug($catNameTrimmed);

                $category = Category::firstOrCreate(
                    ['slug' => $catSlug],
                    [
                        'name' => $catNameTrimmed,
                        'icon' => $this->guessCategoryIcon($catNameTrimmed),
                        'color' => $this->guessCategoryColor($catNameTrimmed),
                    ]
                );

                CategoryTranslation::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'locale' => $locale,
                    ],
                    [
                        'name' => $catNameTrimmed,
                    ]
                );

                $categoryIds[] = $category->id;
            }

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
                $sameDayEvents = $candidatesQuery->get();
                $cleanDto = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($dto->title, 'UTF-8'));
                $dtoStems = $this->getLatvianWordStems($dto->title);
                $dtoWords = array_values(array_filter(explode(' ', $cleanDto), fn ($w) => mb_strlen($w, 'UTF-8') > 2 && !in_array($w, ['un', 'par', 'ar', 'pie', 'uz', 'no', 'vai'])));

                foreach ($sameDayEvents as $candidate) {
                    $cleanCand = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($candidate->title, 'UTF-8'));

                    // 1. Direct string similarity
                    similar_text($cleanCand, $cleanDto, $percent);
                    if ($percent >= 75) {
                        $existingEvent = $candidate;
                        break;
                    }

                    // 2. Substring check
                    if ((mb_strlen($cleanDto, 'UTF-8') > 12 && str_contains($cleanCand, $cleanDto)) || (mb_strlen($cleanCand, 'UTF-8') > 12 && str_contains($cleanDto, $cleanCand))) {
                        $existingEvent = $candidate;
                        break;
                    }

                    // 3. Exact word token overlap on same day
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

                    // 4. Stemmed word overlap (Latvian declension-aware)
                    $candStems = $this->getLatvianWordStems($candidate->title);
                    $commonStems = array_intersect($dtoStems, $candStems);
                    if (count($commonStems) >= 2) {
                        $stemOverlap = (count($commonStems) / min(count($dtoStems), count($candStems))) * 100;
                        if ($stemOverlap >= 40) {
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

                // Image handling: if new scraper provides an image, use it.
                // If existing event has a known generic placeholder image (like aplis-default-og-img.jpg) and dto doesn't have an image, clear it to null.
                $finalImageUrl = $dto->imageUrl;
                if (!$finalImageUrl && $existingEvent->image_url && str_contains($existingEvent->image_url, 'aplis-default-og-img.jpg')) {
                    $finalImageUrl = null;
                } elseif (!$finalImageUrl) {
                    $finalImageUrl = $existingEvent->image_url;
                }

                // Update existing event details
                $updateData = [
                    'source_id' => $existingEvent->source_id ?: $source->id,
                    'source_slug' => $existingEvent->source_slug ?: $source->slug,
                    'source_url' => $dto->sourceUrl ?: $existingEvent->source_url,
                    'location_id' => $locationId ?: $existingEvent->location_id,
                    'start_at' => $dto->startAt ?: $existingEvent->start_at,
                    'end_at' => $dto->endAt ?: $existingEvent->end_at,
                    'image_url' => $finalImageUrl,
                    'ticket_url' => $dto->ticketUrl ?: $existingEvent->ticket_url,
                    'is_free' => $dto->isFree,
                    'price_min' => $dto->priceMin !== null ? $dto->priceMin : $existingEvent->price_min,
                    'price_max' => $dto->priceMax !== null ? $dto->priceMax : $existingEvent->price_max,
                    'entertainment_type' => $dto->entertainmentType ?: $existingEvent->entertainment_type,
                    'raw_data' => array_merge($existingEvent->raw_data ?? [], $dto->rawData),
                ];

                if ($locale === 'lv' || empty($existingEvent->title)) {
                    $updateData['title'] = $dto->title ?: $existingEvent->title;
                    $updateData['description'] = $dto->description ?: $existingEvent->description;
                    $updateData['short_description'] = $dto->shortDescription ?: $existingEvent->short_description;
                }

                $existingEvent->update($updateData);

                // Save or update translation in event_translations table
                EventTranslation::updateOrCreate(
                    [
                        'event_id' => $existingEvent->id,
                        'locale' => $locale,
                    ],
                    [
                        'title' => $dto->title,
                        'slug' => Str::slug($dto->title) . '-' . substr(md5($existingEvent->id . $locale), 0, 6),
                        'description' => $dto->description,
                        'short_description' => $dto->shortDescription ?: (mb_strlen($dto->description ?? '') <= 220 ? $dto->description : Str::limit(strip_tags($dto->description ?? ''), 160)),
                    ]
                );

                if (!empty($categoryIds)) {
                    if ($existingEvent->source_id === $source->id) {
                        $existingEvent->categories()->sync($categoryIds);
                    } else {
                        $existingEvent->categories()->syncWithoutDetaching($categoryIds);
                    }
                }

                return 'updated';
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
                'price_min' => $dto->priceMin,
                'price_max' => $dto->priceMax,
                'currency' => $dto->currency ?: 'EUR',
                'ticket_url' => $dto->ticketUrl,
                'image_url' => $dto->imageUrl,
                'source_url' => $dto->sourceUrl,
                'source_external_id' => $dto->sourceExternalId,
                'fingerprint' => $fingerprint,
                'entertainment_type' => $dto->entertainmentType ?: $this->guessEntertainmentType($dto->title, $dto->categoryNames),
                'status' => 'draft',
                'published_at' => null,
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
        if (str_contains($lower, 'festivāl') || str_contains($lower, 'svētk') || str_contains($lower, 'fest')) return 'sparkles';
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
        return 'teal';
    }

    private function guessEntertainmentType(string $title, array $categories): string
    {
        $text = mb_strtolower($title . ' ' . implode(' ', $categories));

        if (str_contains($text, 'bērn') || str_contains($text, 'ģimen') || str_contains($text, 'kids') || str_contains($text, 'children')) return 'family';
        if (str_contains($text, 'koncert') || str_contains($text, 'mūzik') || str_contains($text, 'concert') || str_contains($text, 'music')) return 'concert';
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
                if (mb_strlen($normQuery, 'UTF-8') >= 8 && mb_strlen($normCand, 'UTF-8') >= 8) {
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
                    $overlap = (count($common) / min(count($queryStems), count($candStems))) * 100;
                    if ($overlap >= 75) {
                        $location = $candidate;
                        break;
                    }
                }
            }
        }

        if (!$location) {
            $location = Location::create([
                'name' => $cleanQueryName,
                'city' => $cityName,
                'region' => $regionName,
                'address' => $dto->address,
                'latitude' => $dto->latitude,
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
}

