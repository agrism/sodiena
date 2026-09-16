<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsolidateCategoriesCommand extends Command
{
    protected $signature = 'categories:consolidate';
    protected $description = 'Consolidate all event categories into 9 canonical short-named categories and remap existing events';

    public const CANONICAL_CATEGORIES = [
        'kino' => [
            'slug' => 'kino',
            'name' => 'Kino',
            'icon' => 'film',
            'color' => 'teal',
            'description' => 'Filmas, kino seansi, kinofestivāli, pirmizrādes un brīvdabas kino',
            'order' => 1,
            'translations' => [
                'lv' => 'Kino',
                'en' => 'Cinema',
                'ru' => 'Кино',
            ],
        ],
        'teatris' => [
            'slug' => 'teatris',
            'name' => 'Teātris',
            'icon' => 'drama',
            'color' => 'rose',
            'description' => 'Teātra izrādes, opera, balets, stand-up komēdija, cirks un skatuves māksla',
            'order' => 2,
            'translations' => [
                'lv' => 'Teātris',
                'en' => 'Theatre',
                'ru' => 'Театр',
            ],
        ],
        'muzika' => [
            'slug' => 'muzika',
            'name' => 'Mūzika',
            'icon' => 'music',
            'color' => 'purple',
            'description' => 'Koncerti, dzīvā mūzika, orķestri, pop, roks, akadēmiskā mūzika un dīdžeji',
            'order' => 3,
            'translations' => [
                'lv' => 'Mūzika',
                'en' => 'Music',
                'ru' => 'Музыка',
            ],
        ],
        'izstades' => [
            'slug' => 'izstades',
            'name' => 'Izstādes',
            'icon' => 'palette',
            'color' => 'indigo',
            'description' => 'Mākslas izstādes, muzeju ekspozīcijas, galerijas, vēsture un kultūras mantojums',
            'order' => 4,
            'translations' => [
                'lv' => 'Izstādes',
                'en' => 'Exhibitions',
                'ru' => 'Выставки',
            ],
        ],
        'berniem' => [
            'slug' => 'berniem',
            'name' => 'Bērniem',
            'icon' => 'smile',
            'color' => 'amber',
            'description' => 'Radošās darbnīcas, bērnu pasākumi, izrādes un rotaļas visai ģimenei',
            'order' => 5,
            'translations' => [
                'lv' => 'Bērniem',
                'en' => 'Family & Kids',
                'ru' => 'Детям',
            ],
        ],
        'sports' => [
            'slug' => 'sports',
            'name' => 'Sports',
            'icon' => 'activity',
            'color' => 'blue',
            'description' => 'Sporta sacensības, pārgājieni dabā, velobraucieni, maratoni un aktīvā atpūta',
            'order' => 6,
            'translations' => [
                'lv' => 'Sports',
                'en' => 'Sports',
                'ru' => 'Спорт',
            ],
        ],
        'seminari' => [
            'slug' => 'seminari',
            'name' => 'Semināri',
            'icon' => 'book-open',
            'color' => 'emerald',
            'description' => 'Semināri, meistarklases, lekcijas, kursi, konferences un apmācības',
            'order' => 7,
            'translations' => [
                'lv' => 'Semināri',
                'en' => 'Workshops',
                'ru' => 'Семинары',
            ],
        ],
        'svetki' => [
            'slug' => 'svetki',
            'name' => 'Svētki',
            'icon' => 'sparkles',
            'color' => 'pink',
            'description' => 'Pilsētas svētki, gadatirgi, tradīcijas, festivāli un tematiski pasākumi',
            'order' => 8,
            'translations' => [
                'lv' => 'Svētki',
                'en' => 'Celebrations',
                'ru' => 'Праздники',
            ],
        ],
        'citi' => [
            'slug' => 'citi',
            'name' => 'Cits',
            'icon' => 'tag',
            'color' => 'slate',
            'description' => 'Citi un dažādi pasākumi',
            'order' => 9,
            'translations' => [
                'lv' => 'Cits',
                'en' => 'Other',
                'ru' => 'Другое',
            ],
        ],
    ];

    public function handle(): int
    {
        $this->info('Starting Category Consolidation...');

        // 1. Seed or Update the 9 canonical categories and translations
        $canonicalMap = []; // slug => Category instance
        foreach (self::CANONICAL_CATEGORIES as $slug => $data) {
            $cat = Category::updateOrCreate(
                ['slug' => $slug],
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
                        'category_id' => $cat->id,
                        'locale' => $loc,
                    ],
                    [
                        'name' => $transName,
                    ]
                );
            }

            $canonicalMap[$slug] = $cat;
        }

        $this->info('✓ 9 Canonical categories created/updated.');

        // 2. Map existing category IDs to canonical categories
        $allCategories = Category::all();
        $idToCanonicalId = [];
        $obsoleteCategoryIds = [];

        foreach ($allCategories as $cat) {
            if (isset(self::CANONICAL_CATEGORIES[$cat->slug])) {
                $idToCanonicalId[$cat->id] = $canonicalMap[$cat->slug]->id;
                continue;
            }

            $targetSlug = self::mapToCanonicalSlug($cat->slug . ' ' . $cat->name);
            $targetCat = $canonicalMap[$targetSlug] ?? $canonicalMap['citi'];
            $idToCanonicalId[$cat->id] = $targetCat->id;
            $obsoleteCategoryIds[] = $cat->id;

            $this->line("Mapping '{$cat->name}' ({$cat->slug}) -> '{$targetCat->name}' ({$targetCat->slug})");
        }

        // 3. Remap event_category pivot records
        $pivotRows = DB::table('category_event')->get();
        if ($pivotRows->isEmpty()) {
            // Check alternative table name if applicable
            $pivotRows = DB::table('event_category')->get();
            $pivotTable = 'event_category';
        } else {
            $pivotTable = 'category_event';
        }

        $remappedCount = 0;
        $eventCanonicalLinks = []; // event_id => array of canonical_category_ids

        foreach ($pivotRows as $row) {
            $catId = $row->category_id;
            $eventId = $row->event_id;
            $targetCatId = $idToCanonicalId[$catId] ?? $canonicalMap['citi']->id;

            $eventCanonicalLinks[$eventId][$targetCatId] = true;
        }

        // Also check events with zero categories or direct entertainment_type
        $eventsWithoutCategories = Event::whereDoesntHave('categories')->get();
        foreach ($eventsWithoutCategories as $event) {
            $targetSlug = self::mapToCanonicalSlug($event->title . ' ' . ($event->entertainment_type ?? ''));
            $targetCatId = $canonicalMap[$targetSlug]->id ?? $canonicalMap['citi']->id;
            $eventCanonicalLinks[$event->id][$targetCatId] = true;
        }

        // Truncate and rebuild pivot table cleanly
        DB::table($pivotTable)->truncate();

        $insertData = [];
        foreach ($eventCanonicalLinks as $eventId => $catIds) {
            foreach (array_keys($catIds) as $catId) {
                $insertData[] = [
                    'event_id' => $eventId,
                    'category_id' => $catId,
                ];
                $remappedCount++;

                if (count($insertData) >= 1000) {
                    DB::table($pivotTable)->insert($insertData);
                    $insertData = [];
                }
            }
        }

        if (!empty($insertData)) {
            DB::table($pivotTable)->insert($insertData);
        }

        $this->info("✓ Remapped {$remappedCount} event-category associations.");

        // 4. Delete obsolete categories and their translations
        if (!empty($obsoleteCategoryIds)) {
            CategoryTranslation::whereIn('category_id', $obsoleteCategoryIds)->delete();
            Category::whereIn('id', $obsoleteCategoryIds)->delete();
            $this->info('✓ Deleted ' . count($obsoleteCategoryIds) . ' obsolete categories.');
        }

        // 5. Output Summary Table
        $summary = Category::withCount('events')->orderBy('order')->get()->map(function ($c) {
            return [
                'ID' => $c->id,
                'Order' => $c->order,
                'Name' => $c->name,
                'Slug' => $c->slug,
                'Icon' => $c->icon,
                'Events Count' => $c->events_count,
            ];
        });

        $this->table(['ID', 'Order', 'Name', 'Slug', 'Icon', 'Events Count'], $summary);
        $this->info('Category consolidation completed successfully!');

        return self::SUCCESS;
    }

    public static function mapToCanonicalSlug(string $oldSlugOrName): string
    {
        $clean = mb_strtolower(trim($oldSlugOrName), 'UTF-8');

        // 1. Kino
        if (str_contains($clean, 'kino') || str_contains($clean, 'film') || str_contains($clean, 'cinema') || str_contains($clean, 'movie') || str_contains($clean, 'seans')) {
            return 'kino';
        }

        // 2. Teātris
        if (str_contains($clean, 'teatr') || str_contains($clean, 'teātr') || str_contains($clean, 'izrad') || str_contains($clean, 'izrād') || str_contains($clean, 'theatre') || str_contains($clean, 'theater') || str_contains($clean, 'stand-up') || str_contains($clean, 'standup') || str_contains($clean, 'komēdij') || str_contains($clean, 'komedij') || str_contains($clean, 'opera') || str_contains($clean, 'balet') || str_contains($clean, 'cirks') || str_contains($clean, 'drama') || str_contains($clean, 'drāma')) {
            return 'teatris';
        }

        // 3. Mūzika
        if (str_contains($clean, 'muzik') || str_contains($clean, 'mūzik') || str_contains($clean, 'koncert') || str_contains($clean, 'music') || str_contains($clean, 'concert') || str_contains($clean, 'klasik') || str_contains($clean, 'dziesm') || str_contains($clean, 'koris') || str_contains($clean, 'orķestr') || str_contains($clean, 'orkestr') || str_contains($clean, 'rok') || str_contains($clean, 'džez') || str_contains($clean, 'jazz') || str_contains($clean, 'dziedāt') || str_contains($clean, 'музык') || str_contains($clean, 'концерт')) {
            return 'muzika';
        }

        // 4. Izstādes
        if (str_contains($clean, 'izstad') || str_contains($clean, 'izstād') || str_contains($clean, 'maksl') || str_contains($clean, 'māksl') || str_contains($clean, 'art') || str_contains($clean, 'exhibit') || str_contains($clean, 'muzej') || str_contains($clean, 'galerij') || str_contains($clean, 'kultur') || str_contains($clean, 'kultūr') || str_contains($clean, 'tradic') || str_contains($clean, 'tradīc') || str_contains($clean, 'mantojum') || str_contains($clean, 'ekspozic') || str_contains($clean, 'ekspozīc') || str_contains($clean, 'glezn') || str_contains($clean, 'fotoizstād')) {
            return 'izstades';
        }

        // 5. Bērniem
        if (str_contains($clean, 'bern') || str_contains($clean, 'bērn') || str_contains($clean, 'gimen') || str_contains($clean, 'ģimen') || str_contains($clean, 'kid') || str_contains($clean, 'child') || str_contains($clean, 'family') || str_contains($clean, 'det') || str_contains($clean, 'дет') || str_contains($clean, 'mazuļ') || str_contains($clean, 'skolēn')) {
            return 'berniem';
        }

        // 6. Sports
        if (str_contains($clean, 'sport') || str_contains($clean, 'skrie') || str_contains($clean, 'skrieš') || str_contains($clean, 'vel') || str_contains($clean, 'maraton') || str_contains($clean, 'dab') || str_contains($clean, 'pargaj') || str_contains($clean, 'pārgāj') || str_contains($clean, 'fitnes') || str_contains($clean, 'hike') || str_contains($clean, 'orientē') || str_contains($clean, 'orient') || str_contains($clean, 'turnir') || str_contains($clean, 'turnīr') || str_contains($clean, 'sacens') || str_contains($clean, 'futbol') || str_contains($clean, 'basketbol') || str_contains($clean, 'hokej') || str_contains($clean, 'pelde')) {
            return 'sports';
        }

        // 7. Semināri
        if (str_contains($clean, 'seminar') || str_contains($clean, 'seminār') || str_contains($clean, 'meistarklas') || str_contains($clean, 'lekcij') || str_contains($clean, 'kurs') || str_contains($clean, 'izglit') || str_contains($clean, 'izglīt') || str_contains($clean, 'biznes') || str_contains($clean, 'workshop') || str_contains($clean, 'conference') || str_contains($clean, 'konferenc') || str_contains($clean, 'apmac') || str_contains($clean, 'apmāc') || str_contains($clean, 'nodarbīb') || str_contains($clean, 'nodarbib') || str_contains($clean, 'vebinar') || str_contains($clean, 'vebinār') || str_contains($clean, 'diskusij') || str_contains($clean, 'sarun')) {
            return 'seminari';
        }

        // 8. Svētki
        if (str_contains($clean, 'svetk') || str_contains($clean, 'svētk') || str_contains($clean, 'festival') || str_contains($clean, 'festivāl') || str_contains($clean, 'tirdz') || str_contains($clean, 'tirdziņ') || str_contains($clean, 'tirg') || str_contains($clean, 'tirdziņš') || str_contains($clean, 'gadatirg') || str_contains($clean, 'ball') || str_contains($clean, 'party') || str_contains($clean, 'naktsdziv') || str_contains($clean, 'naktsdzīv') || str_contains($clean, 'gastronom') || str_contains($clean, 'svinīb') || str_contains($clean, 'svinib') || str_contains($clean, 'vakars') || str_contains($clean, 'salūts')) {
            return 'svetki';
        }

        // 9. Cits
        return 'citi';
    }
}
