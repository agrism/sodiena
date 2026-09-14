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
            $existingLocales = $existingTranslations->keys()->toArray();

            // 1. Check if 'lv' translation actually contains English text
            $lvTrans = $existingTranslations->get('lv');
            if ($lvTrans && !empty($lvTrans->description)) {
                $lang = $this->detectLanguage($lvTrans->title . ' ' . $lvTrans->description);
                if ($lang === 'en') {
                    // This is an English text mistakenly placed in 'lv'
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

            // 2. Find missing locales among ['lv', 'en', 'ru']
            $targetLocales = ['lv', 'en', 'ru'];
            $missingLocales = array_diff($targetLocales, $event->translations()->pluck('locale')->toArray());

            if (empty($missingLocales)) {
                continue;
            }

            // 3. Search for sibling with full translations
            $sibling = $this->findSiblingWithTranslations($event, $ingestionService);

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
                if ($curLv && $lvSibTrans && $this->detectLanguage($curLv->description) === 'en') {
                    if (!$dryRun) {
                        $curLv->update([
                            'title' => $lvSibTrans->title,
                            'description' => $lvSibTrans->description,
                            'short_description' => $lvSibTrans->short_description,
                        ]);
                    }
                    $copiedAny = true;
                }

                if ($copiedAny) {
                    $updatedCount++;
                }
            }
        }

        $this->info("Translation synchronization completed. Updated {$updatedCount} events.");
        return self::SUCCESS;
    }

    protected function findSiblingWithTranslations(Event $event, EventIngestionService $ingestionService): ?Event
    {
        $cleanTitle = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($event->title, 'UTF-8')));
        $words = array_values(array_filter(explode(' ', $cleanTitle), fn ($w) => mb_strlen($w, 'UTF-8') > 2));
        
        $siblings = Event::with('translations')
            ->has('translations', '>=', 2)
            ->where('id', '!=', $event->id)
            ->where(function ($q) use ($event) {
                if ($event->location_id) {
                    $q->where('location_id', $event->location_id);
                }
            })
            ->latest('id')
            ->limit(30)
            ->get();

        foreach ($siblings as $candidate) {
            $candTitle = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($candidate->title, 'UTF-8')));
            $candWords = array_values(array_filter(explode(' ', $candTitle), fn ($w) => mb_strlen($w, 'UTF-8') > 2));

            $common = array_intersect($words, $candWords);
            if (count($common) >= 2) {
                return $candidate;
            }
        }

        return null;
    }

    protected function detectLanguage(?string $text): string
    {
        if (empty($text)) {
            return 'lv';
        }

        $lower = mb_strtolower($text, 'UTF-8');
        $enHits = preg_match_all('/\b(the|and|in|during|guided|tour|exhibition|history|tickets|with|for|are|not|allowed|open|daily|adults|students|building|palace|museum)\b/u', $lower);
        $lvChars = preg_match_all('/[āčēģīķļņšūž]/u', $lower);

        if ($enHits > 5 && $lvChars < 4) {
            return 'en';
        }

        return 'lv';
    }
}
