<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventTranslation;
use App\Models\Location;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UnpublishedEventsSeeder extends Seeder
{
    public function run(): void
    {
        // Helper to find or create location
        $getLocation = function($name, $city, $address, $lat, $lng, $type) {
            $loc = Location::where('name', $name)->orWhere('slug', Str::slug($name . '-' . $city))->first();
            if (!$loc) {
                $loc = Location::create([
                    'name' => $name,
                    'city' => $city,
                    'region' => 'Rīga',
                    'address' => $address,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'place_type' => $type,
                ]);
            }
            return $loc;
        };

        $riga = $getLocation('Dailes teātris', 'Rīga', 'Brīvības iela 75', 56.958, 24.127, 'theatre');
        $citadele = $getLocation('Kino Citadele (Forum Cinemas)', 'Rīga', '13. janvāra iela 8', 56.945, 24.113, 'cinema');
        $gilde = $getLocation('Lielā ģilde', 'Rīga', 'Amatu iela 6', 56.949, 24.108, 'concert_hall');
        $atta = $getLocation('ATTA Centre', 'Rīga', 'Krasta iela 73', 56.929, 24.150, 'convention_center');
        $vef = $getLocation('VEF Kultūras pils', 'Rīga', 'Ropažu iela 2', 56.969, 24.168, 'culture_palace');

        $sourceBp = Source::firstWhere('slug', 'bilesu-paradize') ?: Source::first();
        $sourceFc = Source::firstWhere('slug', 'bezrindas') ?: Source::first();
        $sourceBs = Source::firstWhere('slug', 'bilesu-serviss') ?: Source::first();

        $catTeatris = Category::where('slug', 'teatris')->first();
        $catKino = Category::where('slug', 'kino')->first();
        $catKoncerti = Category::where('slug', 'koncerti')->first();
        $catBerniem = Category::where('slug', 'berniem')->first();
        $catCiti = Category::where('slug', 'citi')->first();

        $items = [
            [
                'title' => 'Laimīgas laulības noslēpums',
                'slug' => 'laimigas-laulibas-noslepums-' . Str::random(6),
                'source_id' => $sourceBp->id,
                'source_slug' => $sourceBp->slug,
                'location_id' => $riga->id,
                'start_at' => Carbon::now()->addDays(3)->setTime(19, 0),
                'end_at' => Carbon::now()->addDays(3)->setTime(21, 30),
                'price_min' => 15.00,
                'price_max' => 35.00,
                'is_free' => false,
                'currency' => 'EUR',
                'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/146030',
                'source_url' => 'https://www.bilesuparadize.lv/lv/event/146030',
                'status' => 'draft',
                'published_at' => null,
                'category_id' => $catTeatris?->id,
                'translations' => [
                    'lv' => [
                        'title' => 'Laimīgas laulības noslēpums',
                        'description' => 'Aizraujoša franču bulvāru komēdija par laulības noslēpumiem un cilvēku vājībām divos cēlienos ar lieliskiem aktieriem.',
                    ],
                    'en' => [
                        'title' => 'The Secret of a Happy Marriage',
                        'description' => 'An exciting French boulevard comedy about marriage secrets and human weaknesses in two acts with stellar cast.',
                    ],
                    'ru' => [
                        'title' => 'Секрет счастливого брака',
                        'description' => 'Увлекательная французская бульварная комедия о тайнах брака и человеческих слабостях в двух действиях.',
                    ],
                ],
            ],
            [
                'title' => 'Kino: Koijots pret ACME',
                'slug' => 'kino-koijots-pret-acme-' . Str::random(6),
                'source_id' => $sourceFc->id,
                'source_slug' => $sourceFc->slug,
                'location_id' => $citadele->id,
                'start_at' => Carbon::now()->addDays(2)->setTime(18, 15),
                'end_at' => Carbon::now()->addDays(2)->setTime(20, 00),
                'price_min' => 7.50,
                'price_max' => 12.00,
                'is_free' => false,
                'currency' => 'EUR',
                'ticket_url' => 'https://www.forumcinemas.lv/',
                'source_url' => 'https://www.forumcinemas.lv/',
                'status' => 'draft',
                'published_at' => null,
                'category_id' => $catKino?->id,
                'translations' => [
                    'lv' => [
                        'title' => 'Kino: Koijots pret ACME',
                        'description' => 'Leģendārais animācijas varonis Koijots nolemj tiesāties ar korporāciju ACME par brāķētām lamatām un raķetēm.',
                    ],
                    'en' => [
                        'title' => 'Movie: Coyote vs. ACME',
                        'description' => 'The legendary cartoon character Wile E. Coyote sues ACME corporation over defective products and gadgets.',
                    ],
                    'ru' => [
                        'title' => 'Кино: Койот против ACME',
                        'description' => 'Легендарный персонаж мультфильмов Койот подает в суд на корпорацию ACME из-за бракованных ловушек.',
                    ],
                ],
            ],
            [
                'title' => 'Čalotāji pavasara noskaņās',
                'slug' => 'calotaji-pavasara-noskanas-' . Str::random(6),
                'source_id' => $sourceBp->id,
                'source_slug' => $sourceBp->slug,
                'location_id' => $gilde->id,
                'start_at' => Carbon::now()->addDays(5)->setTime(19, 0),
                'end_at' => Carbon::now()->addDays(5)->setTime(21, 0),
                'price_min' => 12.00,
                'price_max' => 28.00,
                'is_free' => false,
                'currency' => 'EUR',
                'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/170459',
                'source_url' => 'https://www.bilesuparadize.lv/lv/event/170459',
                'status' => 'draft',
                'published_at' => null,
                'category_id' => $catKoncerti?->id,
                'translations' => [
                    'lv' => [
                        'title' => 'Čalotāji pavasara noskaņās',
                        'description' => 'Tradicionālais pavasara vokālās un kora mūzikas koncerts ar izciliem solistiem un orķestra pavadījumu.',
                    ],
                    'en' => [
                        'title' => 'Warblers in Spring Mood',
                        'description' => 'Traditional spring vocal and choral concert featuring renowned soloists accompanied by orchestra.',
                    ],
                    'ru' => [
                        'title' => 'Щебетатели в весеннем настроении',
                        'description' => 'Традиционный весенний вокально-хоровой концерт с участием выдающихся солистов и оркестра.',
                    ],
                ],
            ],
            [
                'title' => 'Drogas Klientu Dienas & Skaistuma Meistarklase',
                'slug' => 'drogas-klientu-dienas-' . Str::random(6),
                'source_id' => $sourceBs->id,
                'source_slug' => $sourceBs->slug,
                'location_id' => $atta->id,
                'start_at' => Carbon::now()->addDays(6)->setTime(11, 0),
                'end_at' => Carbon::now()->addDays(6)->setTime(18, 0),
                'price_min' => 0.00,
                'price_max' => 0.00,
                'is_free' => true,
                'currency' => 'EUR',
                'ticket_url' => 'https://www.bilesuserviss.lv/lat/biletes/visi/',
                'source_url' => 'https://www.bilesuserviss.lv/lat/biletes/visi/',
                'status' => 'draft',
                'published_at' => null,
                'category_id' => $catCiti?->id,
                'translations' => [
                    'lv' => [
                        'title' => 'Drogas Klientu Dienas & Skaistuma Meistarklase',
                        'description' => 'Ekskluzīvas skaistumkopšanas konsultācijas, bezmaksas dāvanas un ekspertu lekcijas visas dienas garumā.',
                    ],
                    'en' => [
                        'title' => 'Drogas Customer Days & Beauty Masterclass',
                        'description' => 'Exclusive beauty consultations, complimentary gifts, and expert lectures throughout the day.',
                    ],
                    'ru' => [
                        'title' => 'Дни клиентов Drogas и мастер-класс по красоте',
                        'description' => 'Эксклюзивные консультации по красоте, подарки и лекции экспертов на протяжении всего дня.',
                    ],
                ],
            ],
            [
                'title' => 'Čučispilventiņš Čučumuižas pasaka',
                'slug' => 'cucispilventin-cucumuizas-pasaka-' . Str::random(6),
                'source_id' => $sourceBp->id,
                'source_slug' => $sourceBp->slug,
                'location_id' => $vef->id,
                'start_at' => Carbon::now()->addDays(7)->setTime(12, 0),
                'end_at' => Carbon::now()->addDays(7)->setTime(13, 20),
                'price_min' => 8.00,
                'price_max' => 16.00,
                'is_free' => false,
                'currency' => 'EUR',
                'ticket_url' => 'https://www.bilesuparadize.lv/lv/event/150120',
                'source_url' => 'https://www.bilesuparadize.lv/lv/event/150120',
                'status' => 'draft',
                'published_at' => null,
                'category_id' => $catBerniem?->id,
                'translations' => [
                    'lv' => [
                        'title' => 'Čučispilventiņš Čučumuižas pasaka',
                        'description' => 'Mīļa un muzikāla pasaku izrāde pašiem mazākajiem skatītājiem kopā ar Čučumuižas rūķiem un draugiem.',
                    ],
                    'en' => [
                        'title' => 'Sleepy Pillow: A Fairy Tale from Chuchumuizha',
                        'description' => 'A heartwarming musical fairy tale performance for the youngest audience with the forest gnomes.',
                    ],
                    'ru' => [
                        'title' => 'Подушечка: Сказка Чучумуйжи',
                        'description' => 'Добрая и музыкальная сказка для самых маленьких зрителей вместе с гномами Чучумуйжи и их друзьями.',
                    ],
                ],
            ],
        ];

        foreach ($items as $item) {
            $catId = $item['category_id'] ?? null;
            $translations = $item['translations'] ?? [];
            unset($item['category_id'], $item['translations']);

            $item['description'] = $translations['lv']['description'] ?? '';

            $event = Event::create($item);

            if ($catId) {
                $event->categories()->sync([$catId]);
            }

            foreach ($translations as $locale => $tData) {
                EventTranslation::create([
                    'event_id' => $event->id,
                    'locale' => $locale,
                    'title' => $tData['title'] ?? $event->title,
                    'description' => $tData['description'] ?? '',
                    'short_description' => Str::limit(strip_tags($tData['description'] ?? ''), 120),
                ]);
            }
        }
    }
}
