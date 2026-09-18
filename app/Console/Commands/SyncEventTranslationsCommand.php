<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventTranslation;
use App\Services\Scrapers\EventIngestionService;
use Illuminate\Console\Command;
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
    protected $description = 'Inherit and sync missing multilingual translations (LV, EN, RU) across sibling events and tour instances';

    /**
     * Execute the console command.
     */
    public function handle(EventIngestionService $ingestionService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info('Starting event translations synchronization (current & upcoming events only)...');

        $events = Event::where(function ($q) {
            $q->where('end_at', '>=', now()->startOfDay())
              ->orWhere(function ($sq) {
                  $sq->whereNull('end_at')
                     ->where('start_at', '>=', now()->startOfDay());
              });
        })->with('translations', 'location')->get();

        $updatedCount = 0;

        foreach ($events as $event) {
            $existingTranslations = $event->translations->keyBy('locale');
            $targetLocales = ['lv', 'en', 'ru'];
            $needsSync = false;

            // Check if any target locale translation is missing or has empty description
            foreach ($targetLocales as $loc) {
                $t = $existingTranslations->get($loc);
                if (!$t || empty(trim($t->description ?? ''))) {
                    $needsSync = true;
                    break;
                }
            }

            // Check if main event description is empty
            if (empty(trim($event->description ?? ''))) {
                $needsSync = true;
            }

            // 1. Check if 'lv' translation actually contains Russian or English text
            $lvTrans = $existingTranslations->get('lv');
            $lvLanguage = 'lv';
            if ($lvTrans && !empty($lvTrans->title . ' ' . $lvTrans->description)) {
                $detectedLang = $ingestionService->detectTextLanguage($lvTrans->title . ' ' . $lvTrans->description);
                if ($detectedLang !== 'lv') {
                    $lvLanguage = $detectedLang;
                    $needsSync = true;
                    if (!$dryRun) {
                        // Ensure the detected language translation exists with this content
                        if (!$existingTranslations->has($detectedLang)) {
                            EventTranslation::create([
                                'event_id' => $event->id,
                                'locale' => $detectedLang,
                                'title' => $lvTrans->title,
                                'slug' => Str::slug($lvTrans->title) . '-' . substr(md5($event->id . $detectedLang), 0, 6),
                                'description' => $lvTrans->description,
                                'short_description' => $lvTrans->short_description,
                            ]);
                        }
                    }
                }
            }

            // If everything is complete and valid, skip
            if (!$needsSync && $lvLanguage === 'lv') {
                continue;
            }

            // 2. If event is from Afiro API, fetch missing translations directly from Afiro API
            if ($event->source_slug === 'afiro-api' && $event->source_external_id) {
                $afiroUpdated = false;
                foreach ($targetLocales as $loc) {
                    $curTrans = $event->translations()->where('locale', $loc)->first();
                    if (!$curTrans || empty(trim($curTrans->description ?? ''))) {
                        try {
                            $res = \Illuminate\Support\Facades\Http::withHeaders([
                                'Accept' => 'application/json',
                                'x-lang' => $loc,
                                'Origin' => 'https://afiro.lv',
                                'Referer' => 'https://afiro.lv/',
                            ])->timeout(10)->get("https://api.afiro.lv/events/{$event->source_external_id}");

                            if ($res->successful() && !empty($res->json('title'))) {
                                $afTitle = trim($res->json('title'));
                                $afDesc = trim($res->json('description') ?? '');
                                if (!empty($afDesc) || !empty($afTitle)) {
                                    $this->line("  -> Event #{$event->id} ({$event->title}) fetched [{$loc}] directly from Afiro API");
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

                                        if ($loc === 'lv' || empty(trim($event->description ?? ''))) {
                                            $event->update([
                                                'title' => $afTitle,
                                                'description' => $afDesc,
                                                'short_description' => mb_strlen($afDesc) <= 220 ? $afDesc : Str::limit(strip_tags($afDesc), 160),
                                            ]);
                                        }
                                    }
                                    $afiroUpdated = true;
                                }
                            }
                        } catch (\Throwable $e) {
                            // ignore and fallback to sibling search
                        }
                    }
                }

                if ($afiroUpdated) {
                    $updatedCount++;
                    $event->load('translations');
                    $existingTranslations = $event->translations->keyBy('locale');
                }
            }

            // 3. Search for sibling with full translations
            $sibling = $ingestionService->findSiblingWithTranslations($event);

            if ($sibling) {
                $copiedAny = false;
                foreach ($targetLocales as $loc) {
                    $curTrans = $event->translations()->where('locale', $loc)->first();
                    $sibTrans = $sibling->translations->firstWhere('locale', $loc);

                    if ($sibTrans && !empty(trim($sibTrans->description ?? ''))) {
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
                            $copiedAny = true;
                        } elseif (empty(trim($curTrans->description ?? ''))) {
                            $this->line("  -> Event #{$event->id} ({$event->title}) populates missing [{$loc}] description from Event #{$sibling->id}");
                            if (!$dryRun) {
                                $curTrans->update([
                                    'description' => $sibTrans->description,
                                    'short_description' => $sibTrans->short_description ?: (mb_strlen($sibTrans->description) <= 220 ? $sibTrans->description : Str::limit(strip_tags($sibTrans->description), 160)),
                                ]);
                            }
                            $copiedAny = true;
                        }
                    }
                }

                // If 'lv' was originally Russian/English, or if main event has empty description, populate from sibling LV
                $lvSibTrans = $sibling->translations->firstWhere('locale', 'lv');
                $curLv = $event->translations()->where('locale', 'lv')->first();
                if ($lvSibTrans && !empty(trim($lvSibTrans->description ?? ''))) {
                    $curLang = $curLv ? $ingestionService->detectTextLanguage($curLv->description ?: $curLv->title) : 'lv';
                    $sibLang = $ingestionService->detectTextLanguage($lvSibTrans->description ?: $lvSibTrans->title);
                    $mainDescEmpty = empty(trim($event->description ?? ''));

                    if (($curLang !== 'lv' && $sibLang === 'lv') || $mainDescEmpty) {
                        $this->line("  -> Event #{$event->id} ({$event->title}) updating [lv] / main description from Event #{$sibling->id}");
                        if (!$dryRun) {
                            if ($curLv) {
                                $curLv->update([
                                    'title' => $lvSibTrans->title,
                                    'description' => $lvSibTrans->description,
                                    'short_description' => $lvSibTrans->short_description,
                                ]);
                            }
                            $event->update([
                                'title' => $lvSibTrans->title,
                                'description' => $lvSibTrans->description,
                                'short_description' => $lvSibTrans->short_description,
                            ]);
                        }
                        $copiedAny = true;
                    }
                }

                if ($copiedAny) {
                    $updatedCount++;
                }
            }
        }

        $this->info("Translation synchronization completed. Updated {$updatedCount} events.");
        return self::SUCCESS;
    }
}
