<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventTranslation;
use App\Services\Scrapers\EventIngestionService;
use App\Services\TranslationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncEventTranslationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:sync-translations {--dry-run : Only display what would be synced without saving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inherit, translate, and verify multilingual translations (LV, EN, RU) for all active events';

    /**
     * Execute the console command.
     */
    public function handle(EventIngestionService $ingestionService, TranslationService $translationService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info('Starting event translations verification and synchronization (active & upcoming events only)...');

        $events = Event::where(function ($q) {
            $q->where('end_at', '>=', now()->startOfDay())
              ->orWhere(function ($sq) {
                  $sq->whereNull('end_at')
                     ->where('start_at', '>=', now()->startOfDay());
              });
        })->with('translations', 'location')->get();

        $this->info("Found {$events->count()} active/upcoming events to verify.");

        $updatedCount = 0;
        $fixedLangCount = 0;
        $afiroFetchedCount = 0;
        $siblingInheritedCount = 0;
        $autoTranslatedCount = 0;
        $syntheticDescCount = 0;

        foreach ($events as $event) {
            $existingTranslations = $event->translations->keyBy('locale');
            $targetLocales = ['lv', 'en', 'ru'];
            $eventModified = false;

            // -------------------------------------------------------------
            // STEP 1: Detect non-Latvian text in 'lv' and preserve into 'ru' or 'en'
            // -------------------------------------------------------------
            $lvTrans = $existingTranslations->get('lv');
            $originalLvWasRuOrEn = null;

            if ($lvTrans && !empty($lvTrans->title . ' ' . $lvTrans->description)) {
                $lvText = $lvTrans->title . ' ' . $lvTrans->description;
                $cyrillicInLv = preg_match_all('/[\p{Cyrillic}]/u', $lvText);

                if ($cyrillicInLv > 20 && !$this->isLatvianText($lvTrans->description)) {
                    $originalLvWasRuOrEn = 'ru';
                    if (!$dryRun) {
                        $ruTrans = $existingTranslations->get('ru');
                        if (!$ruTrans || empty(trim($ruTrans->description ?? '')) || !$this->isRussianText($ruTrans->description)) {
                            EventTranslation::updateOrCreate(
                                ['event_id' => $event->id, 'locale' => 'ru'],
                                [
                                    'title' => $lvTrans->title,
                                    'slug' => Str::slug($lvTrans->title) . '-' . substr(md5($event->id . 'ru'), 0, 6),
                                    'description' => $lvTrans->description,
                                    'short_description' => $lvTrans->short_description ?: Str::limit(strip_tags($lvTrans->description), 160),
                                ]
                            );
                        }
                    }
                    $fixedLangCount++;
                    $eventModified = true;
                    $event->load('translations');
                    $existingTranslations = $event->translations->keyBy('locale');
                } elseif ($this->isEnglishText($lvText) && !$this->isLatvianText($lvTrans->description)) {
                    $originalLvWasRuOrEn = 'en';
                    if (!$dryRun) {
                        $enTrans = $existingTranslations->get('en');
                        if (!$enTrans || empty(trim($enTrans->description ?? '')) || $this->isLatvianText($enTrans->description)) {
                            EventTranslation::updateOrCreate(
                                ['event_id' => $event->id, 'locale' => 'en'],
                                [
                                    'title' => $lvTrans->title,
                                    'slug' => Str::slug($lvTrans->title) . '-' . substr(md5($event->id . 'en'), 0, 6),
                                    'description' => $lvTrans->description,
                                    'short_description' => $lvTrans->short_description ?: Str::limit(strip_tags($lvTrans->description), 160),
                                ]
                            );
                        }
                    }
                    $fixedLangCount++;
                    $eventModified = true;
                    $event->load('translations');
                    $existingTranslations = $event->translations->keyBy('locale');
                }
            }

            // Clean bilingual slash in LV title if present (e.g. "Title LV / Title RU")
            if ($lvTrans && str_contains($lvTrans->title, ' / ')) {
                $parts = explode(' / ', $lvTrans->title, 2);
                if (count($parts) === 2 && preg_match('/[\p{Cyrillic}]/u', $parts[1])) {
                    if (!$dryRun) {
                        $cleanLvTitle = trim($parts[0]);
                        $cleanRuTitle = trim($parts[1]);
                        $lvTrans->update(['title' => $cleanLvTitle]);
                        $event->update(['title' => $cleanLvTitle]);

                        $ruTrans = $existingTranslations->get('ru');
                        if ($ruTrans && (empty($ruTrans->title) || !preg_match('/[\p{Cyrillic}]/u', $ruTrans->title))) {
                            $ruTrans->update(['title' => $cleanRuTitle]);
                        }
                    }
                    $eventModified = true;
                }
            }

            // -------------------------------------------------------------
            // STEP 2: Afiro API Direct Fetch for missing translations
            // -------------------------------------------------------------
            if ($event->source_slug === 'afiro-api' && $event->source_external_id) {
                $afiroUpdated = false;
                foreach ($targetLocales as $loc) {
                    $curTrans = $existingTranslations->get($loc);
                    $needsAfiro = !$curTrans
                        || empty(trim($curTrans->description ?? ''))
                        || ($loc === 'lv' && $originalLvWasRuOrEn)
                        || ($loc === 'en' && $this->isLatvianText($curTrans->description))
                        || ($loc === 'ru' && !$this->isRussianText($curTrans->description));

                    if ($needsAfiro) {
                        try {
                            $res = Http::withHeaders([
                                'Accept' => 'application/json',
                                'x-lang' => $loc,
                                'Origin' => 'https://afiro.lv',
                                'Referer' => 'https://afiro.lv/',
                            ])->timeout(10)->get("https://api.afiro.lv/events/{$event->source_external_id}");

                            if ($res->successful() && !empty($res->json('title'))) {
                                $afTitle = trim($res->json('title'));
                                $afDesc = trim($res->json('description') ?? '');

                                // Ensure fetched text matches expected language
                                $validForLoc = true;
                                if ($loc === 'en' && $this->isLatvianText($afDesc)) $validForLoc = false;
                                if ($loc === 'ru' && !empty($afDesc) && !$this->isRussianText($afDesc)) $validForLoc = false;

                                if ($validForLoc && (!empty($afDesc) || !empty($afTitle))) {
                                    $this->line("  -> Event #{$event->id} ({$event->title}) fetched [{$loc}] from Afiro API");
                                    if (!$dryRun) {
                                        EventTranslation::updateOrCreate(
                                            [
                                                'event_id' => $event->id,
                                                'locale' => $loc,
                                            ],
                                            [
                                                'title' => $afTitle,
                                                'slug' => Str::slug($afTitle) . '-' . substr(md5($event->id . $loc), 0, 6),
                                                'description' => $afDesc,
                                                'short_description' => mb_strlen($afDesc) <= 220 ? $afDesc : Str::limit(strip_tags($afDesc), 160),
                                            ]
                                        );

                                        if ($loc === 'lv' || empty(trim($event->description ?? '')) || $originalLvWasRuOrEn) {
                                            $event->update([
                                                'title' => $afTitle,
                                                'description' => $afDesc,
                                                'short_description' => mb_strlen($afDesc) <= 220 ? $afDesc : Str::limit(strip_tags($afDesc), 160),
                                            ]);
                                        }
                                    }
                                    $afiroUpdated = true;
                                    if ($loc === 'lv') {
                                        $originalLvWasRuOrEn = null;
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            // ignore and continue
                        }
                    }
                }

                if ($afiroUpdated) {
                    $afiroFetchedCount++;
                    $eventModified = true;
                    $event->load('translations');
                    $existingTranslations = $event->translations->keyBy('locale');
                }
            }

            // -------------------------------------------------------------
            // STEP 3: Inherit from sibling events across locations/dates
            // -------------------------------------------------------------
            $needsSiblingSync = false;
            foreach ($targetLocales as $loc) {
                $t = $existingTranslations->get($loc);
                if (!$t
                    || empty(trim($t->description ?? ''))
                    || ($loc === 'lv' && $originalLvWasRuOrEn)
                    || ($loc === 'en' && $this->isLatvianText($t->description))
                    || ($loc === 'ru' && !$this->isRussianText($t->description))
                ) {
                    $needsSiblingSync = true;
                    break;
                }
            }

            if ($needsSiblingSync) {
                $sibling = $ingestionService->findSiblingWithTranslations($event);
                if ($sibling) {
                    $copiedSibling = false;
                    foreach ($targetLocales as $loc) {
                        $curTrans = $existingTranslations->get($loc);
                        $sibTrans = $sibling->translations->firstWhere('locale', $loc);

                        if ($sibTrans && !empty(trim($sibTrans->description ?? ''))) {
                            $sibValid = true;
                            if ($loc === 'en' && $this->isLatvianText($sibTrans->description)) $sibValid = false;
                            if ($loc === 'ru' && !$this->isRussianText($sibTrans->description)) $sibValid = false;

                            if ($sibValid) {
                                if (!$curTrans) {
                                    $this->line("  -> Event #{$event->id} ({$event->title}) inherits [{$loc}] from Event #{$sibling->id}");
                                    if (!$dryRun) {
                                        EventTranslation::create([
                                            'event_id' => $event->id,
                                            'locale' => $loc,
                                            'title' => $sibTrans->title,
                                            'slug' => Str::slug($sibTrans->title) . '-' . substr(md5($event->id . $loc), 0, 6),
                                            'description' => $sibTrans->description,
                                            'short_description' => $sibTrans->short_description,
                                        ]);
                                    }
                                    $copiedSibling = true;
                                } elseif (
                                    empty(trim($curTrans->description ?? ''))
                                    || ($loc === 'lv' && $originalLvWasRuOrEn)
                                    || ($loc === 'en' && $this->isLatvianText($curTrans->description))
                                    || ($loc === 'ru' && !$this->isRussianText($curTrans->description))
                                ) {
                                    $this->line("  -> Event #{$event->id} ({$event->title}) inherits [{$loc}] description from Event #{$sibling->id}");
                                    if (!$dryRun) {
                                        $curTrans->update([
                                            'title' => $sibTrans->title,
                                            'description' => $sibTrans->description,
                                            'short_description' => $sibTrans->short_description ?: (mb_strlen($sibTrans->description) <= 220 ? $sibTrans->description : Str::limit(strip_tags($sibTrans->description), 160)),
                                        ]);
                                        if ($loc === 'lv') {
                                            $event->update([
                                                'title' => $sibTrans->title,
                                                'description' => $sibTrans->description,
                                                'short_description' => $sibTrans->short_description,
                                            ]);
                                        }
                                    }
                                    $copiedSibling = true;
                                    if ($loc === 'lv') {
                                        $originalLvWasRuOrEn = null;
                                    }
                                }
                            }
                        }
                    }

                    if ($copiedSibling) {
                        $siblingInheritedCount++;
                        $eventModified = true;
                        $event->load('translations');
                        $existingTranslations = $event->translations->keyBy('locale');
                    }
                }
            }

            // -------------------------------------------------------------
            // STEP 4: Machine translate LV if still Russian/English and no sibling found
            // -------------------------------------------------------------
            if ($originalLvWasRuOrEn) {
                $lvTrans = $existingTranslations->get('lv');
                if ($lvTrans) {
                    $this->line("  -> Event #{$event->id} ({$event->title}): Machine translating {$originalLvWasRuOrEn} -> lv for LV locale...");
                    if (!$dryRun) {
                        $translatedLvTitle = $translationService->translate($lvTrans->title, $originalLvWasRuOrEn, 'lv') ?: $lvTrans->title;
                        $translatedLvDesc = $translationService->translate($lvTrans->description, $originalLvWasRuOrEn, 'lv') ?: $lvTrans->description;
                        $translatedLvShort = $translationService->translate($lvTrans->short_description, $originalLvWasRuOrEn, 'lv') ?: Str::limit(strip_tags($translatedLvDesc), 160);

                        $lvTrans->update([
                            'title' => $translatedLvTitle,
                            'description' => $translatedLvDesc,
                            'short_description' => $translatedLvShort,
                        ]);

                        $event->update([
                            'title' => $translatedLvTitle,
                            'description' => $translatedLvDesc,
                            'short_description' => $translatedLvShort,
                        ]);
                    }
                    $event->load('translations');
                    $existingTranslations = $event->translations->keyBy('locale');
                }
            }

            // -------------------------------------------------------------
            // STEP 5: Synthetic Fallback if Description is completely missing
            // -------------------------------------------------------------
            $currentMainDesc = trim($event->description ?? ($existingTranslations->get('lv')?->description ?? ''));
            if (empty($currentMainDesc)) {
                $venueName = $event->location?->name ?: ($event->location?->city ?: 'Latvijā');
                $cityName = $event->location?->city ?: 'Latvijā';
                $formattedDate = $event->start_at ? $event->start_at->format('d.m.Y H:i') : '';

                $syntheticLv = "Apmeklējiet pasākumu \"{$event->title}\" {$venueName}" . ($cityName !== $venueName ? " ({$cityName})" : "") . ($formattedDate ? ". Norises laiks: {$formattedDate}." : ".") . " Plašāka informācija un biļešu iegāde pieejama norādītajā pasākuma saitē.";
                $syntheticShort = "Pasākums \"{$event->title}\" {$venueName}" . ($formattedDate ? ", {$formattedDate}" : "");

                $this->line("  -> Event #{$event->id} ({$event->title}): Generated descriptive fallback");

                if (!$dryRun) {
                    $event->update([
                        'description' => $syntheticLv,
                        'short_description' => $syntheticShort,
                    ]);

                    EventTranslation::updateOrCreate(
                        ['event_id' => $event->id, 'locale' => 'lv'],
                        [
                            'title' => $event->title,
                            'slug' => Str::slug($event->title) . '-' . substr(md5($event->id . 'lv'), 0, 6),
                            'description' => $syntheticLv,
                            'short_description' => $syntheticShort,
                        ]
                    );
                }

                $syntheticDescCount++;
                $eventModified = true;
                $event->load('translations');
                $existingTranslations = $event->translations->keyBy('locale');
            }

            // -------------------------------------------------------------
            // STEP 6: Auto-translate missing or invalid EN and RU translations
            // -------------------------------------------------------------
            $lvRecord = $existingTranslations->get('lv');
            $baseTitle = $lvRecord?->title ?: $event->title;
            $baseDesc = $lvRecord?->description ?: $event->description;
            $baseShort = $lvRecord?->short_description ?: $event->short_description;

            if (!empty($baseDesc)) {
                // Check EN
                $enRecord = $existingTranslations->get('en');
                $needsEn = !$enRecord
                    || empty(trim($enRecord->description ?? ''))
                    || $this->isLatvianText($enRecord->description)
                    || preg_match_all('/[\p{Cyrillic}]/u', $enRecord->description ?? '') > 5;

                if ($needsEn) {
                    $this->line("  -> Event #{$event->id} ({$event->title}): Translating LV -> en...");
                    if (!$dryRun) {
                        $transTitle = $translationService->translate($baseTitle, 'lv', 'en') ?: $baseTitle;
                        $transDesc = $translationService->translate($baseDesc, 'lv', 'en') ?: $baseDesc;
                        $transShort = $baseShort ? ($translationService->translate($baseShort, 'lv', 'en') ?: Str::limit(strip_tags($transDesc), 160)) : Str::limit(strip_tags($transDesc), 160);

                        EventTranslation::updateOrCreate(
                            ['event_id' => $event->id, 'locale' => 'en'],
                            [
                                'title' => $transTitle,
                                'slug' => Str::slug($transTitle) . '-' . substr(md5($event->id . 'en'), 0, 6),
                                'description' => $transDesc,
                                'short_description' => $transShort,
                            ]
                        );
                    }
                    $autoTranslatedCount++;
                    $eventModified = true;
                }

                // Check RU
                $ruRecord = $existingTranslations->get('ru');
                $needsRu = !$ruRecord
                    || empty(trim($ruRecord->description ?? ''))
                    || (!$this->isRussianText($ruRecord->description) && ($this->isLatvianText($ruRecord->description) || $this->isEnglishText($ruRecord->description)));

                if ($needsRu) {
                    $this->line("  -> Event #{$event->id} ({$event->title}): Translating LV -> ru...");
                    if (!$dryRun) {
                        $transTitle = $translationService->translate($baseTitle, 'lv', 'ru') ?: $baseTitle;
                        $transDesc = $translationService->translate($baseDesc, 'lv', 'ru') ?: $baseDesc;
                        $transShort = $baseShort ? ($translationService->translate($baseShort, 'lv', 'ru') ?: Str::limit(strip_tags($transDesc), 160)) : Str::limit(strip_tags($transDesc), 160);

                        EventTranslation::updateOrCreate(
                            ['event_id' => $event->id, 'locale' => 'ru'],
                            [
                                'title' => $transTitle,
                                'slug' => Str::slug($transTitle) . '-' . substr(md5($event->id . 'ru'), 0, 6),
                                'description' => $transDesc,
                                'short_description' => $transShort,
                            ]
                        );
                    }
                    $autoTranslatedCount++;
                    $eventModified = true;
                }
            }

            // Ensure main event table has proper LV title and description
            if (!$dryRun) {
                $finalLv = $event->translations()->where('locale', 'lv')->first();
                if ($finalLv && (!empty($finalLv->description) && (empty($event->description) || $event->title !== $finalLv->title))) {
                    $event->update([
                        'title' => $finalLv->title,
                        'description' => $finalLv->description,
                        'short_description' => $finalLv->short_description,
                    ]);
                }
            }

            if ($eventModified) {
                $updatedCount++;
            }
        }

        $this->info("----------------------------------------------------------------");
        $this->info("Synchronization and verification summary:");
        $this->info("  - Total active events processed: {$events->count()}");
        $this->info("  - Language mismatches repaired: {$fixedLangCount}");
        $this->info("  - Direct Afiro API translations fetched: {$afiroFetchedCount}");
        $this->info("  - Sibling translations inherited: {$siblingInheritedCount}");
        $this->info("  - Auto-translated into missing languages: {$autoTranslatedCount}");
        $this->info("  - Synthetic fallback descriptions created: {$syntheticDescCount}");
        $this->info("  - Total events updated/verified: {$updatedCount}");
        $this->info("----------------------------------------------------------------");

        return self::SUCCESS;
    }

    protected function isLatvianText(?string $text): bool
    {
        if (empty($text)) {
            return false;
        }
        $clean = mb_strtolower(strip_tags($text));
        if (str_contains($clean, 'apmeklējiet pasākumu') || str_contains($clean, 'norises laiks:')) {
            return true;
        }

        $lvStopWords = [' un ', ' ir ', ' ar ', ' par ', ' pie ', ' no ', ' uz ', ' kā ', ' kas ', ' vai ', ' lai ', ' tiek ', ' notiks ', ' biļetes ', ' izrāde ', ' koncerts ', ' vieta ', ' pasākums ', ' spēle ', ' ieeja ', ' stāsts ', ' zālē ', ' biļešu ', ' skatuves ', ' festivāls ', ' programma ', ' dalība '];
        $enStopWords = [' the ', ' and ', ' is ', ' in ', ' of ', ' to ', ' with ', ' for ', ' at ', ' will ', ' on ', ' tickets ', ' event ', ' performance ', ' venue ', ' admission '];

        $padded = " {$clean} ";
        $lvCount = 0;
        foreach ($lvStopWords as $w) {
            if (str_contains($padded, $w)) {
                $lvCount++;
            }
        }
        $enCount = 0;
        foreach ($enStopWords as $w) {
            if (str_contains($padded, $w)) {
                $enCount++;
            }
        }

        $latvianChars = preg_match_all('/[āēīūģķļņšžč]/u', $clean);

        if ($lvCount >= 2 && $lvCount > $enCount) {
            return true;
        }
        if ($lvCount >= 1 && $enCount === 0 && $latvianChars >= 2) {
            return true;
        }
        if ($latvianChars >= 5 && $enCount === 0) {
            return true;
        }

        return false;
    }

    protected function isRussianText(?string $text): bool
    {
        if (empty($text)) {
            return false;
        }
        $cyrillicCount = preg_match_all('/[\p{Cyrillic}]/u', $text);
        return $cyrillicCount >= 10;
    }

    protected function isEnglishText(?string $text): bool
    {
        if (empty($text)) {
            return false;
        }
        $clean = mb_strtolower(strip_tags($text));
        $enStopWords = [' the ', ' and ', ' is ', ' in ', ' of ', ' to ', ' with ', ' for ', ' at ', ' will ', ' on ', ' tickets ', ' event ', ' performance ', ' venue ', ' admission '];
        $lvStopWords = [' un ', ' ir ', ' ar ', ' par ', ' pie ', ' no ', ' uz ', ' kā ', ' kas ', ' vai ', ' lai ', ' tiek ', ' notiks ', ' biļetes '];

        $padded = " {$clean} ";
        $enCount = 0;
        foreach ($enStopWords as $w) {
            if (str_contains($padded, $w)) {
                $enCount++;
            }
        }
        $lvCount = 0;
        foreach ($lvStopWords as $w) {
            if (str_contains($padded, $w)) {
                $lvCount++;
            }
        }

        $cyrillicCount = preg_match_all('/[\p{Cyrillic}]/u', $text);

        return $enCount >= 2 && $enCount > $lvCount && $cyrillicCount < 5;
    }
}
