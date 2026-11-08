<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $pivotTable = Schema::hasTable('category_event') ? 'category_event' : 'event_category';
        $pivotRows = Schema::hasTable($pivotTable) ? DB::table($pivotTable)->get() : collect();

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

            // Cross-category sanity guards:
            $sportsId = $canonicalMap['sports']->id ?? null;
            $izstadesId = $canonicalMap['izstades']->id ?? null;
            $teatrisId = $canonicalMap['teatris']->id ?? null;
            $citiId = $canonicalMap['citi']->id ?? null;

            // 1. If smartSlug is sports, remove false izstades/teatris from default fallbacks
            if ($smartSlug === 'sports' && $sportsId) {
                if ($izstadesId && isset($eventCanonicalLinks[$event->id][$izstadesId])) {
                    unset($eventCanonicalLinks[$event->id][$izstadesId]);
                }
                if ($teatrisId && isset($eventCanonicalLinks[$event->id][$teatrisId])) {
                    unset($eventCanonicalLinks[$event->id][$teatrisId]);
                }
            }

            // 2. If smartSlug is teatris, muzika, izstades, seminari, kino, svetki, remove false sports
            if (in_array($smartSlug, ['teatris', 'muzika', 'izstades', 'seminari', 'kino', 'svetki'], true) && $sportsId) {
                if (isset($eventCanonicalLinks[$event->id][$sportsId])) {
                    unset($eventCanonicalLinks[$event->id][$sportsId]);
                }
            }

            // 3. If smartSlug is muzika, teatris, svetki, remove false izstades unless title/description really has an exhibition
            if (in_array($smartSlug, ['muzika', 'teatris', 'svetki'], true) && $izstadesId) {
                $hasRealExhibition = str_contains($titleLower, 'izstād') || str_contains($titleLower, 'ekspozīcij') || str_contains($titleLower, 'glezn') || str_contains($titleLower, 'fotoizstād');
                if (!$hasRealExhibition && isset($eventCanonicalLinks[$event->id][$izstadesId])) {
                    unset($eventCanonicalLinks[$event->id][$izstadesId]);
                }
            }

            // If the event has category 'svetki' and entertainment_type is chill, exhibition, or empty, upgrade to celebration or concert
            $hasSvetki = isset($eventCanonicalLinks[$event->id][$canonicalMap['svetki']->id ?? 0]);
            if ($hasSvetki && (empty($event->entertainment_type) || in_array($event->entertainment_type, ['chill', 'exhibition'], true))) {
                $newType = preg_match('/\b(?:koncerts?|koncertā|orķestr[a-zāčēģīķļņšūž]*|koris|kora)\b/ui', $event->title) ? 'concert' : 'celebration';
                $event->update(['entertainment_type' => $newType]);
            }

            // If the event has ANY specific category, remove 'citi'
            $citiId = $canonicalMap['citi']->id ?? null;
            if ($citiId && isset($eventCanonicalLinks[$event->id][$citiId]) && count($eventCanonicalLinks[$event->id]) > 1) {
                unset($eventCanonicalLinks[$event->id][$citiId]);
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

        // 4. Svētki & Valsts svētki (checked before izstades to prevent 'kultur' matching cultural celebrations)
        if (
            str_contains($clean, 'svetk') || str_contains($clean, 'svētk') ||
            str_contains($clean, 'festival') || str_contains($clean, 'festivāl') ||
            str_contains($clean, 'tirdz') || str_contains($clean, 'tirdziņ') ||
            str_contains($clean, 'tirg') || str_contains($clean, 'gadatirg') ||
            str_contains($clean, 'ball') || str_contains($clean, 'party') ||
            str_contains($clean, 'naktsdziv') || str_contains($clean, 'naktsdzīv') ||
            str_contains($clean, 'gastronom') || str_contains($clean, 'svinīb') ||
            str_contains($clean, 'svinib') || str_contains($clean, 'salūts') ||
            str_contains($clean, 'lāčplēš') || str_contains($clean, 'lacples') ||
            str_contains($clean, 'lāpu gājiens') || str_contains($clean, 'lapu gajiens') ||
            str_contains($clean, 'lāpu gājien') || str_contains($clean, 'lapu gajien') ||
            str_contains($clean, 'valsts svētk') || str_contains($clean, 'valsts svetk') ||
            str_contains($clean, 'proklamēšan') || str_contains($clean, 'proklamesan') ||
            str_contains($clean, 'neatkarīb') || str_contains($clean, 'neatkarib') ||
            str_contains($clean, 'piemiņ') || str_contains($clean, 'atcer') ||
            str_contains($clean, 'brīvības cīnītāj') || str_contains($clean, '18. novembr')
        ) {
            return 'svetki';
        }

        // 5. Izstādes
        if (str_contains($clean, 'izstad') || str_contains($clean, 'izstād') || str_contains($clean, 'maksl') || str_contains($clean, 'māksl') || str_contains($clean, 'art') || str_contains($clean, 'exhibit') || str_contains($clean, 'muzej') || str_contains($clean, 'galerij') || str_contains($clean, 'kultur') || str_contains($clean, 'kultūr') || str_contains($clean, 'tradic') || str_contains($clean, 'tradīc') || str_contains($clean, 'mantojum') || str_contains($clean, 'ekspozic') || str_contains($clean, 'ekspozīc') || str_contains($clean, 'glezn') || str_contains($clean, 'fotoizstād')) {
            return 'izstades';
        }

        // 6. Bērniem
        if (str_contains($clean, 'bern') || str_contains($clean, 'bērn') || str_contains($clean, 'gimen') || str_contains($clean, 'ģimen') || str_contains($clean, 'kid') || str_contains($clean, 'child') || str_contains($clean, 'family') || str_contains($clean, 'det') || str_contains($clean, 'дет') || str_contains($clean, 'mazuļ') || str_contains($clean, 'skolēn')) {
            return 'berniem';
        }

        // 7. Sports
        $cleanForSports = preg_replace('/\b[a-zāčēģīķļņšūž]*(?:transport|eksport|export|pasport|passport)[a-zāčēģīķļņšūž]*\b/ui', '', $clean);
        if (
            str_contains($cleanForSports, 'sport') || str_contains($cleanForSports, 'skrie') ||
            str_contains($cleanForSports, 'skrieš') || str_contains($cleanForSports, 'vel') ||
            str_contains($cleanForSports, 'maraton') ||
            str_contains($cleanForSports, 'pargaj') || str_contains($cleanForSports, 'pārgāj') ||
            str_contains($cleanForSports, 'fitnes') || str_contains($cleanForSports, 'hike') ||
            str_contains($cleanForSports, 'orientē') || str_contains($cleanForSports, 'orient') ||
            str_contains($cleanForSports, 'turnir') || str_contains($cleanForSports, 'turnīr') ||
            str_contains($cleanForSports, 'sacens') || str_contains($cleanForSports, 'futbol') ||
            str_contains($cleanForSports, 'basketbol') || str_contains($cleanForSports, 'hokej') ||
            str_contains($cleanForSports, 'florbol') || str_contains($cleanForSports, 'volejbol') ||
            str_contains($cleanForSports, 'handbol') || str_contains($cleanForSports, 'handball') ||
            str_contains($cleanForSports, 'padel') || str_contains($cleanForSports, 'padels') ||
            str_contains($cleanForSports, 'virslīg') || str_contains($cleanForSports, 'virslig') ||
            str_contains($cleanForSports, 'basketball') || str_contains($cleanForSports, 'football') ||
            str_contains($cleanForSports, 'hockey') || str_contains($cleanForSports, 'floorball') ||
            str_contains($cleanForSports, 'championship') || str_contains($cleanForSports, 'tournament') ||
            str_contains($cleanForSports, 'tenis') || str_contains($cleanForSports, 'fiba') ||
            str_contains($cleanForSports, 'eurobasket') || str_contains($cleanForSports, 'pelde')
        ) {
            return 'sports';
        }

        // 8. Semināri
        if (
            str_contains($clean, 'seminar') || str_contains($clean, 'seminār') || str_contains($clean, 'meistarklas') ||
            str_contains($clean, 'lekcij') || str_contains($clean, 'kurs') || str_contains($clean, 'izglit') ||
            str_contains($clean, 'izglīt') || str_contains($clean, 'biznes') || str_contains($clean, 'workshop') ||
            str_contains($clean, 'conference') || str_contains($clean, 'konferenc') || str_contains($clean, 'apmac') ||
            str_contains($clean, 'apmāc') || str_contains($clean, 'nodarbīb') || str_contains($clean, 'nodarbib') ||
            str_contains($clean, 'vebinar') || str_contains($clean, 'vebinār') || str_contains($clean, 'diskusij') ||
            str_contains($clean, 'sarun') || str_contains($clean, 'atvērto durvju') || str_contains($clean, 'open day') ||
            str_contains($clean, 'studij') || str_contains($clean, 'education')
        ) {
            return 'seminari';
        }

        // 9. Svētki fallback
        if (str_contains($clean, 'svetk') || str_contains($clean, 'svētk') || str_contains($clean, 'vakars')) {
            return 'svetki';
        }

        // 10. Cits
        return 'citi';
    }

    public static function inferFromContentAndVenue(string $title, ?string $description = null, ?string $venue = null, array $rawCategories = []): string
    {
        $text = mb_strtolower($title . ' ' . ($description ?? '') . ' ' . ($venue ?? ''), 'UTF-8');
        $rawCatText = mb_strtolower(implode(' ', $rawCategories), 'UTF-8');

        // Clean disclaimer clauses about admission for infants/children to avoid false positives for sports/concerts
        $cleanForKids = preg_replace('/\b(?:bērniem|bērnam)\s+līdz\s+\d+\s*(?:gadu|gadiem|mēn)?\s*(?:vecumam)?\s*(?:\([^\)]*\))?\s*(?:ieeja|bez\s*maksas|brīva)?/ui', '', $text);
        $cleanForKids = preg_replace('/\b(?:ieeja|bez\s*maksas|brīva\s*ieeja)\s*(?:bērniem|bērnam)\s+līdz\s+\d+/ui', '', $cleanForKids);

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

        // 2. National holidays, Lāčplēša diena, lāpu gājieni, 18. novembris, commemorative dates
        $holidayRegex = '/(?:\b|(?<=[^a-zāčēģīķļņšūž]))((?:valsts\s+svētk|valsts\s+svetk)[a-zāčēģīķļņšūž]*|(?:lāčplēša|lacplesa)\s+dien[a-zāčēģīķļņšūž]*|(?:lāpu\s+gājien|lapu\s+gajien)[a-zāčēģīķļņšūž]*|proklamēšan[a-zāčēģīķļņšūž]*|proklamesan[a-zāčēģīķļņšūž]*|neatkarības\s+atjaunošan[a-zāčēģīķļņšūž]*|baltā\s+galdaut[a-zāčēģīķļņšūž]*|brīvības\s+cīnītāj[a-zāčēģīķļņšūž]*|barikāžu\s+atcer[a-zāčēģīķļņšūž]*|barikāžu\s+aizstāvj[a-zāčēģīķļņšūž]*|18\.\s*novembr[a-zāčēģīķļņšūž]*)(?:\b|(?=[^a-zāčēģīķļņšūž]))/ui';
        $isSportsHoliday = preg_match('/\b(?:skrējiens?|maratons?|kross?|turnīrs?|čempionāts?|kauss|mačs|futbol|basketbol|hokej|florbol|orientēšan[a-zāčēģīķļņšūž]*)\b/ui', $title);
        if (preg_match($holidayRegex, $title) && !$isSportsHoliday) {
            return 'svetki';
        }

        // 3. Teātris (theatres, plays, performances, dramaturgy - strictly excluding cinema venues and film text)
        if (
            !$isCinemaVenue && !$hasFilmText &&
            (
                str_contains($text, 'teātr') || str_contains($text, 'teatr') || str_contains($text, 'teatro') ||
                str_contains($text, 'izrāde') || str_contains($text, 'izrādē') || str_contains($text, 'pirmizrāde') ||
                str_contains($text, 'luga') || str_contains($text, 'lugā') || str_contains($text, 'iestudējum') ||
                str_contains($text, 'dramaturg') || str_contains($text, 'aktier') || str_contains($text, 'režisor') ||
                str_contains($text, 'operet') || str_contains($text, 'balet') || str_contains($text, 'cirks') ||
                str_contains($text, 'stand-up') || str_contains($text, 'standup') || str_contains($text, 'komēdij') ||
                str_contains($text, 'piedzīvojums tumsā') || str_contains($text, 'randiņš tumsā')
            ) &&
            !preg_match('/\b(?:pret|vs|virslīga|fiba|čempionu līga)\b/ui', $title)
        ) {
            return 'teatris';
        }

        // 3. Mūzika (concerts, music groups, singers, choirs, orchestras)
        if (
            (
                str_contains($text, 'koncerts') || str_contains($text, 'koncertā') || str_contains($text, 'mūzika') ||
                str_contains($text, 'mūzikas') || str_contains($text, 'orķestr') || str_contains($text, 'koris') ||
                str_contains($text, 'dziesm') || str_contains($text, 'solist') || str_contains($text, 'džezs') ||
                str_contains($text, 'rokkoncert') || str_contains($text, 'dziedāt') || str_contains($text, 'rokenrol') ||
                str_contains($text, 'grupa ') || str_contains($text, 'grupu ') || str_contains($text, 'grupas ') ||
                str_contains($text, 'kamermūzik')
            ) &&
            !preg_match('/\b(?:pret|vs|virslīga|fiba|čempionāts|kauss|mačs)\b/ui', $title)
        ) {
            return 'muzika';
        }

        // 4. Izstādes, Muzeji un Ekskursijas (guided tours, exhibitions, museums)
        if (
            str_contains($text, 'izstāde') || str_contains($text, 'izstādē') || str_contains($text, 'ekspozīcij') ||
            str_contains($text, 'muzejs') || str_contains($text, 'muzejā') || str_contains($text, 'galerij') ||
            str_contains($text, 'glezn') || str_contains($text, 'mākslas darbi') || str_contains($text, 'ekskursij') ||
            str_contains($text, 'gida pavadīb') || str_contains($text, 'kūrorta parks')
        ) {
            return 'izstades';
        }

        // 5. Semināri (izglītība, atvērto durvju dienas, lekcijas, kursi, meistarklases, konferences)
        if (
            str_contains($text, 'meistarklas') || str_contains($text, 'seminār') || str_contains($text, 'lekcij') ||
            str_contains($text, 'apmācīb') || str_contains($text, 'konferenc') || str_contains($text, 'vebinār') ||
            str_contains($text, 'nodarbīb') || str_contains($text, 'diskusij') ||
            str_contains($text, 'atvērto durvju') || str_contains($text, 'atvērtās durvis') ||
            str_contains($text, 'open day') || str_contains($text, 'studiju iespēj') ||
            str_contains($text, 'bakalaura') || str_contains($text, 'maģistra') || str_contains($text, 'doktorantūr') ||
            str_contains($text, 'izglītīb') || str_contains($text, 'kursi') || str_contains($text, 'prezentācij') ||
            str_contains($text, 'kulinārijas skola')
        ) {
            return 'seminari';
        }

        // 6. Bērniem (checked with sanitized text to avoid free ticket disclaimer false positives)
        if (str_contains($cleanForKids, 'bērniem') || str_contains($cleanForKids, 'leļļu') || str_contains($cleanForKids, 'pasaka') || str_contains($cleanForKids, 'ģimenēm') || str_contains($cleanForKids, 'mazuļiem') || str_contains($cleanForKids, 'skolēniem') || str_contains($cleanForKids, 'bērnu')) {
            return 'berniem';
        }

        // 7. Sports (basketbols, futbols, hokejs, florbols, turnīri, čempionāti, sacensības, spēles)
        $textForSports = preg_replace('/\b[a-zāčēģīķļņšūž]*(?:transport|eksport|export|pasport|passport)[a-zāčēģīķļņšūž]*\b/ui', '', $text);
        $rawCatTextForSports = preg_replace('/\b[a-zāčēģīķļņšūž]*(?:transport|eksport|export|pasport|passport)[a-zāčēģīķļņšūž]*\b/ui', '', $rawCatText);
        $titleForSports = preg_replace('/\b[a-zāčēģīķļņšūž]*(?:transport|eksport|export|pasport|passport)[a-zāčēģīķļņšūž]*\b/ui', '', $title);

        $hasSportsInTitle = preg_match('/\b(?:sports?|sporta|turnīr[a-zāčēģīķļņšūž]*|čempionāt[a-zāčēģīķļņšūž]*|kauss?|kausā|mačs?|mačā|mači|futbol[a-zāčēģīķļņšūž]*|basketbol[a-zāčēģīķļņšūž]*|hokej[a-zāčēģīķļņšūž]*|florbol[a-zāčēģīķļņšūž]*|volejbol[a-zāčēģīķļņšūž]*|handbol[a-zāčēģīķļņšūž]*|padel[a-zāčēģīķļņšūž]*|padels?|skrējiens?|maratons?|fiba|virslīg[a-zāčēģīķļņšūž]*|basketball|handball|tournament[s]?|championship[s]?|league)\b/ui', $titleForSports)
            || (preg_match('/\b(?:sporta spēl[a-zāčēģīķļņšūž]*|mājas spēl[a-zāčēģīķļņšūž]*|finālspēl[a-zāčēģīķļņšūž]*|spēļu kalendārs)\b/ui', $titleForSports) && !preg_match('/\b(?:luga|izrāde|iestudējums|komēdija|teātris|bērniem)\b/ui', $titleForSports));

        if (
            $hasSportsInTitle ||
            preg_match('/\b(?:sports?|sporta|sportā|sportam|sportisk[a-zāčēģīķļņšūž]*)\b/ui', $rawCatTextForSports) ||
            preg_match('/\b(?:sports?|sporta|sportā|sportam|sporti|sportisk[a-zāčēģīķļņšūž]*|sportot)\b/ui', $textForSports) ||
            str_contains($textForSports, 'sacensīb') ||
            str_contains($textForSports, 'maratons') || str_contains($textForSports, 'skrējiens') || str_contains($textForSports, 'velobrauciens') ||
            str_contains($textForSports, 'čempionāt') || str_contains($textForSports, 'turnīr') ||
            str_contains($textForSports, 'pārgājiens') || str_contains($textForSports, 'basketbol') || str_contains($textForSports, 'futbol') ||
            str_contains($textForSports, 'hokej') || str_contains($textForSports, 'florbol') || str_contains($textForSports, 'volejbol') ||
            str_contains($textForSports, 'handbol') || str_contains($textForSports, 'handball') ||
            str_contains($textForSports, 'padel') || str_contains($textForSports, 'padels') ||
            str_contains($textForSports, 'tenis') || str_contains($textForSports, 'bokss') || str_contains($textForSports, 'fiba') ||
            str_contains($textForSports, 'eurobasket') || str_contains($textForSports, 'kvalifikācij') || str_contains($textForSports, 'valstsvienīb') ||
            str_contains($textForSports, 'virslīg') || str_contains($textForSports, 'čempionu līg') ||
            str_contains($textForSports, 'basketball') || str_contains($textForSports, 'championship') || str_contains($textForSports, 'tournament') ||
            preg_match('/\b(?:mačs|mači|mačā|sporta spēl|mājas spēl|finālspēl|pusfinālspēl|līgas spēl)\b/ui', $textForSports) ||
            preg_match('/\b(?:pret|vs)\b/ui', $titleForSports)
        ) {
            return 'sports';
        }

        // 8. Svētki
        if (
            str_contains($text, 'svētk') || str_contains($text, 'svetk') ||
            str_contains($text, 'festivāl') || str_contains($text, 'festival') ||
            str_contains($text, 'gadatirg') || str_contains($text, 'tirdziņ') ||
            str_contains($text, 'zaļumballe') || str_contains($text, 'ballīte') ||
            str_contains($text, 'balle') || str_contains($text, 'naktsdzīv') ||
            str_contains($text, 'svinīb') || str_contains($text, 'salūts') ||
            preg_match($holidayRegex, $text)
        ) {
            return 'svetki';
        }

        // 9. Izstādes fallback
        if (str_contains($text, 'izstāde') || str_contains($text, 'izstādē') || str_contains($text, 'ekspozīcij') || str_contains($text, 'muzejs') || str_contains($text, 'muzejā') || str_contains($text, 'galerij') || str_contains($text, 'glezn') || str_contains($text, 'mākslas darbi')) {
            return 'izstades';
        }

        return self::mapToCanonicalSlug($rawCatText ?: $text);
    }
}
