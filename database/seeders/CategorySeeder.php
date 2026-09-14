<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Mūzika & Koncerti',
                'icon' => 'music',
                'color' => 'purple',
                'description' => 'Festivāli, dzīvā mūzika, orķestri, pop, roks un elektroniskā mūzika',
                'order' => 1,
            ],
            [
                'name' => 'Kultūra & Māksla',
                'icon' => 'palette',
                'color' => 'indigo',
                'description' => 'Izstādes, muzeji, performances, vēsture un mākslas meistarklases',
                'order' => 2,
            ],
            [
                'name' => 'Daba & Pārgājieni',
                'icon' => 'trees',
                'color' => 'emerald',
                'description' => 'Mežu takas, purva laipas, laivu braucieni, kempingi un nacionālie parki',
                'order' => 3,
            ],
            [
                'name' => 'Sports & Aktīvā atpūta',
                'icon' => 'activity',
                'color' => 'blue',
                'description' => 'Skriešanas sacensības, velobraucieni, maratoni, ūdens sports un fitnesa notikumi',
                'order' => 4,
            ],
            [
                'name' => 'Ģimenēm & Bērniem',
                'icon' => 'smile',
                'color' => 'amber',
                'description' => 'Radošās darbnīcas, atrakcijas, bērnu teātris, zinātnes šovi un rotaļas',
                'order' => 5,
            ],
            [
                'name' => 'Filmas & Kino',
                'icon' => 'film',
                'color' => 'teal',
                'description' => 'Kino seansi, jaunākās filmas, kinofestivāli, pirmizrādes un brīvdabas kino',
                'order' => 6,
            ],
            [
                'name' => 'Teātris & Kino',
                'icon' => 'drama',
                'color' => 'rose',
                'description' => 'Drāmas, komēdijas, brīvdabas izrādes un teātra festivāli',
                'order' => 7,
            ],
            [
                'name' => 'Gastronomija & Tirgi',
                'icon' => 'utensils',
                'color' => 'orange',
                'description' => 'Zemnieku tirdziņi, ielu ēdienu festivāli, degustācijas un meistarklases',
                'order' => 7,
            ],
            [
                'name' => 'Festivāli & Svētki',
                'icon' => 'sparkles',
                'color' => 'pink',
                'description' => 'Pilsētas svētki, gadskārtu svinības, brīvdabas festivāli un tradīcijas',
                'order' => 8,
            ],
            [
                'name' => 'Naktsdzīve & Ballītes',
                'icon' => 'moon',
                'color' => 'violet',
                'description' => 'Klubi, bāri, dīdžeju naktis un tematiskās ballītes',
                'order' => 9,
            ],
            [
                'name' => 'Izglītība & Semināri',
                'icon' => 'book-open',
                'color' => 'teal',
                'description' => 'Konferences, semināri, meistarklases, lekcijas un tīklošanās pasākumi',
                'order' => 10,
            ],
        ];

        foreach ($categories as $cat) {
            Category::updateOrCreate(
                ['slug' => Str::slug($cat['name'])],
                $cat
            );
        }
    }
}
