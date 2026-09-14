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

            // 1. Check if 'lv' translation actually contains English text
            $lvTrans = $existingTranslations->get('lv');
            $lvIsEnglish = false;
            if ($lvTrans && !empty($lvTrans->description)) {
                $lang = $ingestionService->detectTextLanguage($lvTrans->title . ' ' . $lvTrans->description);
                if ($lang === 'en') {
                    $lvIsEnglish = true;
                    if (!$dryRun) {
                        // Ensure 'en' translation exists with this text
                        if (!$existingTranslations->has('en')) {
                            EventTranslation::create([
                                'event_id' => $event->id,
                                'locale' => 'en',
                                'title' => $lvTrans->title,
                                'slug' => Str::slug($lvTrans->title) . '-' . substr(md5($event->id . 'en'), 0, 6),
                                'description' => $lvTrans->description,
                                'short_description' => $lvTrans->short_description,
                            ]);
                        }
                    }
                }
            }

            // If no missing locales and 'lv' is not in English, nothing to inherit
            if (empty($missingLocales) && !$lvIsEnglish) {
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

                // If 'lv' was originally English, overwrite 'lv' with the sibling's real Latvian translation
                $lvSibTrans = $sibling->translations->firstWhere('locale', 'lv');
                $curLv = $event->translations()->where('locale', 'lv')->first();
                if ($curLv && $lvSibTrans && !empty($lvSibTrans->description)) {
                    $curLang = $ingestionService->detectTextLanguage($curLv->description);
                    $sibLang = $ingestionService->detectTextLanguage($lvSibTrans->description);
                    if ($curLang === 'en' && $sibLang === 'lv') {
                        $this->line("  -> Event #{$event->id} ({$event->title}) overwriting [lv] with real LV text from Event #{$sibling->id}");
                        if (!$dryRun) {
                            $curLv->update([
                                'title' => $lvSibTrans->title,
                                'description' => $lvSibTrans->description,
                                'short_description' => $lvSibTrans->short_description,
                            ]);
                            $event->update([
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
