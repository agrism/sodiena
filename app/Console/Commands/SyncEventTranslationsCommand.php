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
        $this->info('Starting event translations synchronization...');

        $events = Event::with('translations', 'location')->get();
        $updatedCount = 0;

        foreach ($events as $event) {
            $existingTranslations = $event->translations->keyBy('locale');
            $targetLocales = ['lv', 'en', 'ru'];
            $missingLocales = array_diff($targetLocales, $existingTranslations->keys()->toArray());

            // 1. Check if 'lv' translation actually contains Russian or English text
            $lvTrans = $existingTranslations->get('lv');
            $lvLanguage = 'lv';
            if ($lvTrans && !empty($lvTrans->title . ' ' . $lvTrans->description)) {
                $detectedLang = $ingestionService->detectTextLanguage($lvTrans->title . ' ' . $lvTrans->description);
                if ($detectedLang !== 'lv') {
                    $lvLanguage = $detectedLang;
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

            // If no missing locales and 'lv' is real Latvian, nothing to inherit
            if (empty($missingLocales) && $lvLanguage === 'lv') {
                continue;
            }

            // 2. Search for sibling with full translations
            $sibling = $ingestionService->findSiblingWithTranslations($event);

            if ($sibling) {
                $copiedAny = false;
                foreach ($targetLocales as $loc) {
                    $hasCurrent = $event->translations()->where('locale', $loc)->exists();
                    $sibTrans = $sibling->translations->firstWhere('locale', $loc);

                    if (!$hasCurrent && $sibTrans && !empty($sibTrans->description)) {
                        $this->line("  -> Event #{$event->id} ({$event->title}) inherits [{$loc}] from Event #{$sibling->id}");
                        if (!$dryRun) {
                            EventTranslation::updateOrCreate(
                                [
                                    'event_id' => $event->id,
                                    'locale' => $loc,
                                ],
                                [
                                    'title' => $sibTrans->title,
                                    'slug' => Str::slug($sibTrans->title) . '-' . substr(md5($event->id . $loc), 0, 6),
                                    'description' => $sibTrans->description,
                                    'short_description' => $sibTrans->short_description,
                                ]
                            );
                        }
                        $copiedAny = true;
                    }
                }

                // If 'lv' was originally Russian or English, overwrite 'lv' with the sibling's real Latvian translation
                $lvSibTrans = $sibling->translations->firstWhere('locale', 'lv');
                $curLv = $event->translations()->where('locale', 'lv')->first();
                if ($curLv && $lvSibTrans && !empty($lvSibTrans->description)) {
                    $curLang = $ingestionService->detectTextLanguage($curLv->description ?: $curLv->title);
                    $sibLang = $ingestionService->detectTextLanguage($lvSibTrans->description ?: $lvSibTrans->title);
                    if ($curLang !== 'lv' && $sibLang === 'lv') {
                        $this->line("  -> Event #{$event->id} ({$event->title}) overwriting [lv] with real LV text from Event #{$sibling->id}");
                        if (!$dryRun) {
                            $curLv->update([
                                'title' => $lvSibTrans->title,
                                'description' => $lvSibTrans->description,
                                'short_description' => $lvSibTrans->short_description,
                            ]);
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
