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

        // Refine events that might have been broadly categorized into 'izstades' or 'citi', and strictly enforce cinema venues
        $allEvents = Event::with('location')->get();
        $teatrisId = $canonicalMap['teatris']->id ?? null;
        $kinoId = $canonicalMap['kino']->id ?? null;

        foreach ($allEvents as $event) {
            $smartSlug = self::inferFromContentAndVenue(
                $event->title,
                $event->description,
                $event->location?->name,
                []
            );

            $venueLower = mb_strtolower($event->location?->name ?? '', 'UTF-8');
            $titleLower = mb_strtolower($event->title ?? '', 'UTF-8');
            $isCinemaVenue = (
                $smartSlug === 'kino' ||
                str_contains($venueLower, 'k.suns') || str_contains($venueLower, 'k suns') || str_contains($venueLower, 'ksuns') ||
                str_contains($venueLower, 'forum cinema') || str_contains($venueLower, 'forumcinemas') ||
                str_contains($venueLower, 'kinoteātr') || str_contains($venueLower, 'kinoteatr') ||
                str_contains($venueLower, 'apollo kino') || str_contains($venueLower, 'cinamon') ||
                str_contains($venueLower, 'kino bize') ||
                str_contains($titleLower, 'k.suns') || str_contains($titleLower, 'forum cinema') ||
                str_contains($titleLower, 'kinoseans') || str_contains($titleLower, 'filmas seans')
            );

            if ($isCinemaVenue && $kinoId) {
                // Strip teatris and enforce kino
                if ($teatrisId && isset($eventCanonicalLinks[$event->id][$teatrisId])) {
                    unset($eventCanonicalLinks[$event->id][$teatrisId]);
                }
                $eventCanonicalLinks[$event->id][$kinoId] = true;
            } elseif ($smartSlug && isset($canonicalMap[$smartSlug])) {
                $smartCatId = $canonicalMap[$smartSlug]->id;
                // If it was only mapped to 'citi' or 'izstades', replace with the smarter category
                $currentCatIds = array_keys($eventCanonicalLinks[$event->id] ?? []);
                $izstadesId = $canonicalMap['izstades']->id ?? null;
                $citiId = $canonicalMap['citi']->id ?? null;

                if (empty($currentCatIds) || (count($currentCatIds) === 1 && (in_array($izstadesId, $currentCatIds, true) || in_array($citiId, $currentCatIds, true)))) {
                    $eventCanonicalLinks[$event->id] = [$smartCatId => true];
                } else {
                    $eventCanonicalLinks[$event->id][$smartCatId] = true;
                }
            }
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

    public static function inferFromContentAndVenue(string $title, ?string $description = null, ?string $venue = null, array $rawCategories = []): string
    {
        $text = mb_strtolower($title . ' ' . ($description ?? '') . ' ' . ($venue ?? ''), 'UTF-8');
        $rawCatText = mb_strtolower(implode(' ', $rawCategories), 'UTF-8');

        // 1. Cinema / Kino (K.Suns, Forum Cinema, Kino Rio, Apollo Kino, Cinamon, Splendid Palace, Kino Bize etc. are ALWAYS KINO, NEVER Theater)
        $isCinemaVenue = (
            str_contains($text, 'k.suns') || str_contains($text, 'k suns') || str_contains($text, 'ksuns') ||
            str_contains($text, 'k. suns') || str_contains($text, 'k.  suns') ||
            str_contains($text, 'forum cinema') || str_contains($text, 'forumcinemas') ||
            str_contains($text, 'kino rio') || str_contains($text, 'kinorio') ||
            str_contains($text, 'kinoteātr') || str_contains($text, 'kinoteatr') ||
            str_contains($text, 'apollo kino') || str_contains($text, 'cinamon') ||
            str_contains($text, 'kino bize') || str_contains($text, 'splendid palace') ||
            str_contains($text, 'kino citadele') || str_contains($text, 'kino gaisma') ||
            str_contains($text, 'kino lora') || str_contains($text, 'kino ria') ||
            str_contains($text, 'kino baze') || str_contains($text, 'kino zāle') ||
            str_contains($text, 'kinozāle')
        );

        $hasFilmText = (
            str_contains($text, 'filma') || str_contains($text, 'filmas') || str_contains($text, 'filmu') ||
            str_contains($text, 'filmā') || str_contains($text, 'kinoseans') || str_contains($text, 'filmas seans') ||
            str_contains($text, 'spēlfilma') || str_contains($text, 'dokumentālā filma') ||
            str_contains($text, 'animācijas filma') || str_contains($text, 'īsfilma') ||
            str_contains($text, 'kino festivāl') || str_contains($text, 'kinofestivāl') ||
            str_contains($text, 'filmas pirmizrāde') || str_contains($text, 'filmas seanss') ||
            str_contains($rawCatText, 'kino') || str_contains($rawCatText, 'film') || str_contains($rawCatText, 'cinema')
        );

        if ($isCinemaVenue || $hasFilmText) {
            return 'kino';
        }

        // 2. Teātris (theatres, plays, performances, dramaturgy - strictly excluding cinema venues and film text)
        if (
            !$isCinemaVenue && !$hasFilmText &&
            (
                str_contains($text, 'teātr') || str_contains($text, 'teatr') || str_contains($text, 'teatro') ||
                str_contains($text, 'izrāde') || str_contains($text, 'izrādē') || str_contains($text, 'pirmizrāde') ||
                str_contains($text, 'luga') || str_contains($text, 'lugā') || str_contains($text, 'iestudējum') ||
                str_contains($text, 'dramaturg') || str_contains($text, 'aktier') || str_contains($text, 'režisor') ||
                str_contains($text, 'operet') || str_contains($text, 'balet') || str_contains($text, 'cirks') ||
                str_contains($text, 'stand-up') || str_contains($text, 'standup') || str_contains($text, 'komēdij')
            )
        ) {
            return 'teatris';
        }

        // 3. Mūzika
        if (str_contains($text, 'koncerts') || str_contains($text, 'koncertā') || str_contains($text, 'mūzika') || str_contains($text, 'mūzikas') || str_contains($text, 'orķestr') || str_contains($text, 'koris') || str_contains($text, 'dziesm') || str_contains($text, 'solist') || str_contains($text, 'džezs') || str_contains($text, 'rokkoncert') || str_contains($text, 'dziedāt')) {
            return 'muzika';
        }

        // 4. Bērniem
        if (str_contains($text, 'bērniem') || str_contains($text, 'leļļu') || str_contains($text, 'pasaka') || str_contains($text, 'ģimenēm') || str_contains($text, 'mazuļiem') || str_contains($text, 'skolēniem')) {
            return 'berniem';
        }

        // 5. Sports
        if (str_contains($text, 'sports') || str_contains($text, 'sacensīb') || str_contains($text, 'maratons') || str_contains($text, 'skrējiens') || str_contains($text, 'velobrauciens') || str_contains($text, 'čempionāt') || str_contains($text, 'turnīrs') || str_contains($text, 'pārgājiens')) {
            return 'sports';
        }

        // 6. Semināri
        if (str_contains($text, 'meistarklas') || str_contains($text, 'seminār') || str_contains($text, 'lekcij') || str_contains($text, 'apmācīb') || str_contains($text, 'konferenc') || str_contains($text, 'vebinār') || str_contains($text, 'nodarbīb') || str_contains($text, 'diskusij')) {
            return 'seminari';
        }

        // 7. Svētki
        if (str_contains($text, 'svētki') || str_contains($text, 'svētkos') || str_contains($text, 'festivāl') || str_contains($text, 'gadatirg') || str_contains($text, 'tirdziņ') || str_contains($text, 'zaļumballe') || str_contains($text, 'ballīte') || str_contains($text, 'naktsdzīv')) {
            return 'svetki';
        }

        // 8. Izstādes
        if (str_contains($text, 'izstāde') || str_contains($text, 'izstādē') || str_contains($text, 'ekspozīcij') || str_contains($text, 'muzejs') || str_contains($text, 'muzejā') || str_contains($text, 'galerij') || str_contains($text, 'glezn') || str_contains($text, 'mākslas darbi')) {
            return 'izstades';
        }

        return self::mapToCanonicalSlug($rawCatText ?: $text);
    }
}
