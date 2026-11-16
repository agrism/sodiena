<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class TalsiScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.talsunovads.lv';
    public const CALENDAR_PATH = '/lv/notikumu-kalendars';

    /**
     * Map of known Talsu novads venues with normalized metadata
     */
    protected array $venueAddressMap = [
        // Talsi
        'talsu tautas nams' => [
            'name' => 'Talsu Tautas nams',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 19/21, Talsi, Talsu novads, LV-3201',
            'place_type' => 'cultural_center',
        ],
        'talsu tn' => [
            'name' => 'Talsu Tautas nams',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 19/21, Talsi, Talsu novads, LV-3201',
            'place_type' => 'cultural_center',
        ],
        'kino auseklis' => [
            'name' => 'Kino "Auseklis"',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Kr. Valdemāra iela 17A, Talsi, Talsu novads, LV-3201',
            'place_type' => 'cultural_center',
        ],
        'kinoteātris auseklis' => [
            'name' => 'Kino "Auseklis"',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Kr. Valdemāra iela 17A, Talsi, Talsu novads, LV-3201',
            'place_type' => 'cultural_center',
        ],
        'talsu novada muzejs' => [
            'name' => 'Talsu novada muzejs',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'K. Mīlenbaha iela 19, Talsi, Talsu novads, LV-3201',
            'place_type' => 'museum',
        ],
        'talsu muzejs' => [
            'name' => 'Talsu novada muzejs',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'K. Mīlenbaha iela 19, Talsi, Talsu novads, LV-3201',
            'place_type' => 'museum',
        ],
        'talsu galvenā bibliotēka' => [
            'name' => 'Talsu Galvenā bibliotēka',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 17a, Talsi, Talsu novads, LV-3201',
            'place_type' => 'library',
        ],
        'talsu bērnu bibliotēka' => [
            'name' => 'Talsu Bērnu bibliotēka',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 27, Talsi, Talsu novads, LV-3201',
            'place_type' => 'library',
        ],
        'talsu sauleskalna estrāde' => [
            'name' => 'Talsu Sauleskalna estrāde',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Sauleskalna iela, Talsi, Talsu novads, LV-3201',
            'place_type' => 'park',
        ],
        'sauleskalna estrāde' => [
            'name' => 'Talsu Sauleskalna estrāde',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Sauleskalna iela, Talsi, Talsu novads, LV-3201',
            'place_type' => 'park',
        ],
        'sauleskalna estrādē' => [
            'name' => 'Talsu Sauleskalna estrāde',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Sauleskalna iela, Talsi, Talsu novads, LV-3201',
            'place_type' => 'park',
        ],
        'sauleskalnā' => [
            'name' => 'Talsu Sauleskalna estrāde',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Sauleskalna iela, Talsi, Talsu novads, LV-3201',
            'place_type' => 'park',
        ],
        'talsu sporta nams' => [
            'name' => 'Talsu Sporta nams',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'K. Mīlenbaha iela 32a, Talsi, Talsu novads, LV-3201',
            'place_type' => 'sports_facility',
        ],
        'talsu sporta halle' => [
            'name' => 'Talsu Sporta halle',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'K. Mīlenbaha iela 32a, Talsi, Talsu novads, LV-3201',
            'place_type' => 'sports_facility',
        ],
        'talsu ledus halle' => [
            'name' => 'Talsu ledus halle',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Zvaigžņu iela 11, Talsi, Talsu novads, LV-3201',
            'place_type' => 'sports_facility',
        ],
        'talsu mūzikas skola' => [
            'name' => 'Talsu Mūzikas skola',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'K. Valdemāra iela 34, Talsi, Talsu novads, LV-3201',
            'place_type' => 'cultural_center',
        ],
        'talsu mākslas skola' => [
            'name' => 'Talsu Mākslas skola',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'K. Valdemāra iela 26, Talsi, Talsu novads, LV-3201',
            'place_type' => 'cultural_center',
        ],
        'talsu valsts ģimnāzija' => [
            'name' => 'Talsu Valsts ģimnāzija',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 29, Talsi, Talsu novads, LV-3201',
            'place_type' => 'education',
        ],
        'talsu baznīca' => [
            'name' => 'Talsu evaņģēliski luteriskā baznīca',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Baznīcas laukums 1, Talsi, Talsu novads, LV-3201',
            'place_type' => 'religious',
        ],
        'talsu novada pašvaldība' => [
            'name' => 'Talsu novada pašvaldība',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Kareivju iela 7, Talsi, Talsu novads, LV-3201',
            'place_type' => 'venue',
        ],
        'talsu novada dome' => [
            'name' => 'Talsu novada pašvaldība',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Kareivju iela 7, Talsi, Talsu novads, LV-3201',
            'place_type' => 'venue',
        ],

        // Dundaga
        'dundagas pils' => [
            'name' => 'Dundagas pils',
            'city' => 'Dundaga',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 14, Dundaga, Dundagas pagasts, Talsu novads, LV-3270',
            'place_type' => 'historical',
        ],
        'dundagas kultūras pils' => [
            'name' => 'Dundagas pils',
            'city' => 'Dundaga',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 14, Dundaga, Dundagas pagasts, Talsu novads, LV-3270',
            'place_type' => 'cultural_center',
        ],
        'dundagas kultūras nams' => [
            'name' => 'Dundagas Kultūras nams',
            'city' => 'Dundaga',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 14, Dundaga, Dundagas pagasts, Talsu novads, LV-3270',
            'place_type' => 'cultural_center',
        ],
        'dundagas bibliotēka' => [
            'name' => 'Dundagas bibliotēka',
            'city' => 'Dundaga',
            'region' => 'Kurzeme',
            'address' => 'Pils iela 14, Dundaga, Dundagas pagasts, Talsu novads, LV-3270',
            'place_type' => 'library',
        ],

        // Roja
        'rojas kultūras centrs' => [
            'name' => 'Rojas Kultūras centrs',
            'city' => 'Roja',
            'region' => 'Kurzeme',
            'address' => 'Selgas iela 14e, Roja, Rojas pagasts, Talsu novads, LV-3264',
            'place_type' => 'cultural_center',
        ],
        'rojas kc' => [
            'name' => 'Rojas Kultūras centrs',
            'city' => 'Roja',
            'region' => 'Kurzeme',
            'address' => 'Selgas iela 14e, Roja, Rojas pagasts, Talsu novads, LV-3264',
            'place_type' => 'cultural_center',
        ],
        'rojas brīvdabas estrāde' => [
            'name' => 'Rojas brīvdabas estrāde',
            'city' => 'Roja',
            'region' => 'Kurzeme',
            'address' => 'Ostas iela 1, Roja, Rojas pagasts, Talsu novads, LV-3264',
            'place_type' => 'park',
        ],
        'rojas estrāde' => [
            'name' => 'Rojas brīvdabas estrāde',
            'city' => 'Roja',
            'region' => 'Kurzeme',
            'address' => 'Ostas iela 1, Roja, Rojas pagasts, Talsu novads, LV-3264',
            'place_type' => 'park',
        ],
        'rojas jūras zvejniecības muzejs' => [
            'name' => 'Rojas Jūras zvejniecības muzejs',
            'city' => 'Roja',
            'region' => 'Kurzeme',
            'address' => 'Selgas iela 33, Roja, Rojas pagasts, Talsu novads, LV-3264',
            'place_type' => 'museum',
        ],
        'rojas muzejs' => [
            'name' => 'Rojas Jūras zvejniecības muzejs',
            'city' => 'Roja',
            'region' => 'Kurzeme',
            'address' => 'Selgas iela 33, Roja, Rojas pagasts, Talsu novads, LV-3264',
            'place_type' => 'museum',
        ],
        'rojas bibliotēka' => [
            'name' => 'Rojas bibliotēka',
            'city' => 'Roja',
            'region' => 'Kurzeme',
            'address' => 'Selgas iela 14e, Roja, Rojas pagasts, Talsu novads, LV-3264',
            'place_type' => 'library',
        ],

        // Mērsrags
        'mērsraga tautas nams' => [
            'name' => 'Mērsraga Tautas nams',
            'city' => 'Mērsrags',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 35, Mērsrags, Mērsraga pagasts, Talsu novads, LV-3284',
            'place_type' => 'cultural_center',
        ],
        'mērsraga tn' => [
            'name' => 'Mērsraga Tautas nams',
            'city' => 'Mērsrags',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 35, Mērsrags, Mērsraga pagasts, Talsu novads, LV-3284',
            'place_type' => 'cultural_center',
        ],
        'mērsraga bibliotēka' => [
            'name' => 'Mērsraga bibliotēka',
            'city' => 'Mērsrags',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 35, Mērsrags, Mērsraga pagasts, Talsu novads, LV-3284',
            'place_type' => 'library',
        ],
        'mērsraga bāka' => [
            'name' => 'Mērsraga bāka',
            'city' => 'Mērsrags',
            'region' => 'Kurzeme',
            'address' => 'Mērsrags, Mērsraga pagasts, Talsu novads, LV-3284',
            'place_type' => 'historical',
        ],

        // Sabile
        'sabiles kultūras nams' => [
            'name' => 'Sabiles Kultūras nams',
            'city' => 'Sabile',
            'region' => 'Kurzeme',
            'address' => 'Ventspils iela 17, Sabile, Talsu novads, LV-3294',
            'place_type' => 'cultural_center',
        ],
        'sabiles kn' => [
            'name' => 'Sabiles Kultūras nams',
            'city' => 'Sabile',
            'region' => 'Kurzeme',
            'address' => 'Ventspils iela 17, Sabile, Talsu novads, LV-3294',
            'place_type' => 'cultural_center',
        ],
        'sabiles vīna kalns' => [
            'name' => 'Sabiles Vīna kalns',
            'city' => 'Sabile',
            'region' => 'Kurzeme',
            'address' => 'Kalna iela, Sabile, Talsu novads, LV-3294',
            'place_type' => 'park',
        ],
        'vīna kalns' => [
            'name' => 'Sabiles Vīna kalns',
            'city' => 'Sabile',
            'region' => 'Kurzeme',
            'address' => 'Kalna iela, Sabile, Talsu novads, LV-3294',
            'place_type' => 'park',
        ],
        'sabiles sinagoga' => [
            'name' => 'Sabiles Sinagoga - Mūsdienu mākslas centrs',
            'city' => 'Sabile',
            'region' => 'Kurzeme',
            'address' => 'Strautu iela 4, Sabile, Talsu novads, LV-3294',
            'place_type' => 'cultural_center',
        ],
        'sabiles bibliotēka' => [
            'name' => 'Sabiles pilsētas bibliotēka',
            'city' => 'Sabile',
            'region' => 'Kurzeme',
            'address' => 'Pilskalna iela 6, Sabile, Talsu novads, LV-3294',
            'place_type' => 'library',
        ],

        // Valdemārpils
        'valdemārpils tautas nams' => [
            'name' => 'Valdemārpils Tautas nams',
            'city' => 'Valdemārpils',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 27, Valdemārpils, Talsu novads, LV-3260',
            'place_type' => 'cultural_center',
        ],
        'valdemārpils tn' => [
            'name' => 'Valdemārpils Tautas nams',
            'city' => 'Valdemārpils',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 27, Valdemārpils, Talsu novads, LV-3260',
            'place_type' => 'cultural_center',
        ],
        'valdemārpils bibliotēka' => [
            'name' => 'Valdemārpils pilsētas bibliotēka',
            'city' => 'Valdemārpils',
            'region' => 'Kurzeme',
            'address' => 'Raiņa iela 14, Valdemārpils, Talsu novads, LV-3260',
            'place_type' => 'library',
        ],

        // Stende
        'stendes tautas nams' => [
            'name' => 'Stendes Tautas nams',
            'city' => 'Stende',
            'region' => 'Kurzeme',
            'address' => 'Dumbrāju iela 15, Stende, Talsu novads, LV-3257',
            'place_type' => 'cultural_center',
        ],
        'stendes tn' => [
            'name' => 'Stendes Tautas nams',
            'city' => 'Stende',
            'region' => 'Kurzeme',
            'address' => 'Dumbrāju iela 15, Stende, Talsu novads, LV-3257',
            'place_type' => 'cultural_center',
        ],
        'stendes bibliotēka' => [
            'name' => 'Stendes pilsētas bibliotēka',
            'city' => 'Stende',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 11, Stende, Talsu novads, LV-3257',
            'place_type' => 'library',
        ],

        // Lauciene & Nurmuiža
        'nurmuižas pils' => [
            'name' => 'Nurmuižas pils komplekss',
            'city' => 'Lauciene',
            'region' => 'Kurzeme',
            'address' => 'Nurmuiža, Laucienes pagasts, Talsu novads, LV-3285',
            'place_type' => 'historical',
        ],
        'nurmuiža' => [
            'name' => 'Nurmuižas pils komplekss',
            'city' => 'Lauciene',
            'region' => 'Kurzeme',
            'address' => 'Nurmuiža, Laucienes pagasts, Talsu novads, LV-3285',
            'place_type' => 'historical',
        ],
        'laucienes kultūras nams' => [
            'name' => 'Laucienes Kultūras nams',
            'city' => 'Lauciene',
            'region' => 'Kurzeme',
            'address' => 'Lauciene, Laucienes pagasts, Talsu novads, LV-3285',
            'place_type' => 'cultural_center',
        ],
        'laucienes kn' => [
            'name' => 'Laucienes Kultūras nams',
            'city' => 'Lauciene',
            'region' => 'Kurzeme',
            'address' => 'Lauciene, Laucienes pagasts, Talsu novads, LV-3285',
            'place_type' => 'cultural_center',
        ],

        // Pastende & Ģibuļi
        'pastendes kultūras nams' => [
            'name' => 'Pastendes Kultūras nams',
            'city' => 'Pastende',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 2, Pastende, Ģibuļu pagasts, Talsu novads, LV-3251',
            'place_type' => 'cultural_center',
        ],
        'pastendes muiža' => [
            'name' => 'Pastendes muiža',
            'city' => 'Pastende',
            'region' => 'Kurzeme',
            'address' => 'Pastende, Ģibuļu pagasts, Talsu novads, LV-3251',
            'place_type' => 'historical',
        ],
        'spāres tautas nams' => [
            'name' => 'Spāres Tautas nams',
            'city' => 'Spāre',
            'region' => 'Kurzeme',
            'address' => 'Spāre, Ģibuļu pagasts, Talsu novads, LV-3298',
            'place_type' => 'cultural_center',
        ],
        'spāres muiža' => [
            'name' => 'Spāres muiža',
            'city' => 'Spāre',
            'region' => 'Kurzeme',
            'address' => 'Spāre, Ģibuļu pagasts, Talsu novads, LV-3298',
            'place_type' => 'historical',
        ],

        // Vandzene
        'vandzenes tautas nams' => [
            'name' => 'Vandzenes Tautas nams',
            'city' => 'Vandzene',
            'region' => 'Kurzeme',
            'address' => 'Vandzene, Vandzenes pagasts, Talsu novads, LV-3281',
            'place_type' => 'cultural_center',
        ],
        'vandzenes skola' => [
            'name' => 'Vandzenes pamatskola',
            'city' => 'Vandzene',
            'region' => 'Kurzeme',
            'address' => 'Vandzene, Vandzenes pagasts, Talsu novads, LV-3281',
            'place_type' => 'education',
        ],

        // Tiņģere
        'tiņģeres pils' => [
            'name' => 'Tiņģeres pils',
            'city' => 'Tiņģere',
            'region' => 'Kurzeme',
            'address' => 'Tiņģere, Īves pagasts, Talsu novads, LV-3261',
            'place_type' => 'historical',
        ],
        'tiņģeres muiža' => [
            'name' => 'Tiņģeres pils',
            'city' => 'Tiņģere',
            'region' => 'Kurzeme',
            'address' => 'Tiņģere, Īves pagasts, Talsu novads, LV-3261',
            'place_type' => 'historical',
        ],

        // Kolka & Lībiešu krasts
        'kolkas lībiešu saieta nams' => [
            'name' => 'Kolkas Lībiešu saieta nams',
            'city' => 'Kolka',
            'region' => 'Kurzeme',
            'address' => '"Kolkasrags", Kolka, Kolkas pagasts, Talsu novads, LV-3275',
            'place_type' => 'cultural_center',
        ],
        'kolkas saieta nams' => [
            'name' => 'Kolkas Lībiešu saieta nams',
            'city' => 'Kolka',
            'region' => 'Kurzeme',
            'address' => '"Kolkasrags", Kolka, Kolkas pagasts, Talsu novads, LV-3275',
            'place_type' => 'cultural_center',
        ],
        'kolkasrags' => [
            'name' => 'Kolkasrags',
            'city' => 'Kolka',
            'region' => 'Kurzeme',
            'address' => 'Kolkasrags, Kolka, Kolkas pagasts, Talsu novads, LV-3275',
            'place_type' => 'park',
        ],
    ];

    public function getSlug(): string
    {
        return 'talsi';
    }

    public function getName(): string
    {
        return 'Talsu novada pašvaldība';
    }

    /**
     * Scrape events from talsunovads.lv/lv/notikumu-kalendars
     * Strict rule: NO crawling into detail pages - everything extracted from calendar cards directly!
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);
        $maxPages = 10;
        $today = Carbon::today('Europe/Riga');
        $seenExternalIds = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $pageUrl = $page === 0 ? $baseUrl : $baseUrl . '?page=' . $page;
            $crawler = $this->fetchCrawler($pageUrl);

            if (!$crawler) {
                break;
            }

            $items = $crawler->filter('div.event');
            if ($items->count() === 0) {
                break;
            }

            $allPageEventsPast = true;

            $items->each(function (Crawler $node) use (&$events, &$seenExternalIds, &$allPageEventsPast, $baseUrl, $today) {
                $titleNode = $node->filter('h2.event-title a, .event-title a, h2.event-title');
                $title = $this->cleanText($titleNode->count() ? $titleNode->first()->text() : '');
                if (empty($title)) {
                    return;
                }

                // Extract Categories
                $categories = [];
                $node->filter('.event-categories a, .event-categories')
                    ->each(function (Crawler $c) use (&$categories) {
                        $catText = trim($c->text());
                        if (!empty($catText)) {
                            $categories[] = $catText;
                        }
                    });
                $categories = array_values(array_unique($categories));

                // Exclude committee meetings, council sessions, citizen receptions, etc.
                if ($this->isExcludedEvent($categories, $title)) {
                    return;
                }

                // Link to event
                $linkNode = $node->filter('h2.event-title a, a[href*="/notikums/"]');
                $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'talsi-' . $slugPart;

                if (isset($seenExternalIds[$externalId])) {
                    return;
                }

                // Dates & times
                $dateStr = $node->filter('span.date-date')->count() ? $this->cleanText($node->filter('span.date-date')->text()) : '';
                $timeStr = $node->filter('span.date-time')->count() ? $this->cleanText($node->filter('span.date-time')->text()) : '';
                [$startAt, $endAt, $allDay] = $this->parseLatvianDates($dateStr, $timeStr);

                if (!$startAt) {
                    return;
                }

                // Strict AGENTS.md rule: Do not process past events
                if ($endAt && $endAt->lt($today)) {
                    return;
                }
                if (!$endAt && $startAt->lt($today)) {
                    return;
                }

                $allPageEventsPast = false;

                // Description directly from card (strictly NO crawling into detail pages)
                $description = $node->filter('div.event-content')->count() ? $this->cleanText($node->filter('div.event-content')->text()) : $title;
                if (empty($description)) {
                    $description = $title;
                }

                // Location
                $locationRaw = $node->filter('span.describing-field-value')->count() ? $this->cleanText($node->filter('span.describing-field-value')->text()) : '';
                $venueInfo = $this->resolveLocation($locationRaw, $title, $description);

                // Categories & Entertainment Type (adhering strictly to AGENTS.md)
                $canonicalCategories = $this->resolveCategories($categories, $title, $description);
                $entertainmentType = $this->resolveEntertainmentType($canonicalCategories, $title, $description);

                // Price & Free check
                $priceData = $this->parsePrice($description);

                // Image
                $thumbImg = $node->filter('div.event-image img')->count() ? $node->filter('div.event-image img')->attr('src') : null;
                if ($thumbImg && !str_starts_with($thumbImg, 'http')) {
                    $thumbImg = self::BASE_URL . (str_starts_with($thumbImg, '/') ? '' : '/') . $thumbImg;
                }

                $seenExternalIds[$externalId] = true;

                $dto = new ScrapedEventDTO(
                    title: $title,
                    startAt: $startAt,
                    endAt: $endAt,
                    description: $description,
                    shortDescription: Str::limit(strip_tags($description), 160),
                    venueName: $venueInfo['name'],
                    city: $venueInfo['city'],
                    region: 'Kurzeme',
                    address: $venueInfo['address'],
                    placeType: $venueInfo['place_type'],
                    categoryNames: $canonicalCategories,
                    entertainmentType: $entertainmentType,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: $cleanUrl ?: $baseUrl,
                    imageUrl: $thumbImg,
                    sourceUrl: $cleanUrl ?: $baseUrl,
                    sourceExternalId: $externalId,
                    locale: 'lv',
                    rawData: [
                        'date_raw' => $dateStr,
                        'time_raw' => $timeStr,
                        'location_raw' => $locationRaw,
                        'categories_raw' => $categories,
                    ]
                );

                $events->push($dto);
            });

            if ($allPageEventsPast && $items->count() > 0) {
                // If all events on this page are in the past, stop paginating
                break;
            }
        }

        return $events;
    }

    /**
     * Resolve Location metadata for Talsu novads
     */
    public function resolveLocation(string $locationRaw, string $title = '', string $description = ''): array
    {
        $raw = trim($locationRaw);
        $combined = mb_strtolower($raw . ' ' . $title . ' ' . $description, 'UTF-8');

        // 1. Direct or stem match in known venues map
        foreach ($this->venueAddressMap as $venueKey => $info) {
            if (str_contains($combined, $venueKey)) {
                return $info;
            }
            // Check stem (e.g. "talsu tautas nam" matches "talsu tautas namā")
            $stem = preg_replace('/(s|ā|u|am|i|a|e|ē)$/u', '', $venueKey);
            if (mb_strlen($stem) >= 6 && str_contains($combined, $stem)) {
                return $info;
            }
        }

        // 2. Identify town / parish from location or combined context
        // Clean generic "Talsu novads" to avoid false positives for specific parishes
        $rawCleaned = trim(preg_replace('/\btalsu\s+novad[a-zāčēģīķļņšūž]*/ui', '', $raw));
        $combinedCleaned = trim(preg_replace('/\btalsu\s+novad[a-zāčēģīķļņšūž]*/ui', '', $combined));

        $townStems = [
            'dundag' => 'Dundaga',
            'roj' => 'Roja',
            'mērsrag' => 'Mērsrags',
            'mersrag' => 'Mērsrags',
            'sabil' => 'Sabile',
            'valdemārpil' => 'Valdemārpils',
            'valdemarpil' => 'Valdemārpils',
            'stend' => 'Stende',
            'kolk' => 'Kolka',
            'nurmuiž' => 'Lauciene',
            'nurmuiz' => 'Lauciene',
            'laucien' => 'Lauciene',
            'pastend' => 'Pastende',
            'spār' => 'Spāre',
            'spar' => 'Spāre',
            'vandzen' => 'Vandzene',
            'tiņģer' => 'Tiņģere',
            'tinger' => 'Tiņģere',
            'laidz' => 'Laidze',
            'lībag' => 'Lībagi',
            'libag' => 'Lībagi',
            'mundig' => 'Mundigciems',
            'dižstend' => 'Dižstende',
            'dizstend' => 'Dižstende',
            'strazd' => 'Strazde',
            'valdgal' => 'Valdgale',
            'pūņ' => 'Pūņas',
            'pun' => 'Pūņas',
            'virb' => 'Jaunpagasts',
            'jaunpagast' => 'Jaunpagasts',
            'dursup' => 'Dursupe',
            'balgal' => 'Dursupe',
            'abav' => 'Sabile',
            'ārlav' => 'Valdemārpils',
            'arlav' => 'Valdemārpils',
            'kalten' => 'Kaltene',
            'mazirb' => 'Mazirbe',
            'tals' => 'Talsi',
        ];

        $matchedTown = null;
        // Check cleaned raw location string first
        foreach ($townStems as $stem => $canonicalTown) {
            if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $rawCleaned)) {
                $matchedTown = $canonicalTown;
                break;
            }
        }

        // If not found in raw, check combined context
        if (!$matchedTown) {
            foreach ($townStems as $stem => $canonicalTown) {
                if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $combinedCleaned)) {
                    $matchedTown = $canonicalTown;
                    break;
                }
            }
        }

        $city = $matchedTown ?: 'Talsi';

        // 3. Extract venue name and address
        $venueName = $city;
        $address = "{$city}, Talsu novads";

        if (!empty($raw)) {
            $parts = array_map('trim', explode(',', $raw));

            // Clean trailing country/municipality redundant tokens
            $cleanParts = array_filter($parts, function ($part) {
                $p = mb_strtolower($part, 'UTF-8');
                return $p !== 'latvija' && $p !== 'latvia' && $p !== 'talsu novads' && $p !== 'talsi' && $p !== 'talsu';
            });

            if (!empty($cleanParts)) {
                $venueName = reset($cleanParts);
                $address = implode(', ', $cleanParts) . ', Talsu novads';
            }
        }

        // 4. Infer place type
        $placeType = 'venue';
        $lowerVenue = mb_strtolower($venueName, 'UTF-8');
        if (str_contains($lowerVenue, 'tautas nams') || str_contains($lowerVenue, 'kultūras nams') || str_contains($lowerVenue, 'kultūras centrs') || str_contains($lowerVenue, 'saieta nams')) {
            $placeType = 'cultural_center';
        } elseif (str_contains($lowerVenue, 'muzejs')) {
            $placeType = 'museum';
        } elseif (str_contains($lowerVenue, 'pils') || str_contains($lowerVenue, 'muiža') || str_contains($lowerVenue, 'bāka') || str_contains($lowerVenue, 'drupas')) {
            $placeType = 'historical';
        } elseif (str_contains($lowerVenue, 'bibliotēka')) {
            $placeType = 'library';
        } elseif (str_contains($lowerVenue, 'sporta') || str_contains($lowerVenue, 'halle') || str_contains($lowerVenue, 'stadions')) {
            $placeType = 'sports_facility';
        } elseif (str_contains($lowerVenue, 'parks') || str_contains($lowerVenue, 'estrāde') || str_contains($lowerVenue, 'kalns') || str_contains($lowerVenue, 'rags')) {
            $placeType = 'park';
        } elseif (str_contains($lowerVenue, 'skola') || str_contains($lowerVenue, 'ģimnāzija')) {
            $placeType = 'education';
        } elseif (str_contains($lowerVenue, 'baznīca') || str_contains($lowerVenue, 'sinagoga')) {
            $placeType = 'religious';
        }

        return [
            'name' => $venueName,
            'city' => $city,
            'region' => 'Kurzeme',
            'address' => $address,
            'place_type' => $placeType,
        ];
    }

    /**
     * Resolve categories following strict AGENTS.md rules
     */
    public function resolveCategories(array $rawCategories, string $title, string $description = ''): array
    {
        $text = mb_strtolower(implode(' ', $rawCategories) . ' ' . $title . ' ' . $description, 'UTF-8');

        // STRICT RULE 1: Cinema / Kino (Priority)
        // Kino venues or keywords: ALWAYS KINO, NEVER TEATRIS
        $cinemaKeywords = [
            'filma', 'filmas', 'filmu', 'filmā',
            'kinoseanss', 'filmas seanss', 'spēlfilma',
            'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kino seanss', 'kinoteātr', 'kino:', 'kino |', 'kino auseklis',
        ];
        $isCinema = in_array('Kino', $rawCategories, true);
        if (!$isCinema) {
            foreach ($cinemaKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isCinema = true;
                    break;
                }
            }
        }

        if ($isCinema) {
            $categories = ['Kino'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'animācija') || str_contains($text, 'multfilma')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // STRICT RULE 2: Theaters
        $theatreKeywords = [
            'teātra izrāde', 'izrāde', 'izrādē', 'izrādi', 'izrādes', 'iestudējums',
            'luga', 'lugā', 'komēdija', 'traģēdija', 'stāvizrāde',
            'stand-up', 'standup', 'leļļu izrāde', 'balets', 'opera',
        ];
        $isTheatre = in_array('Teātris', $rawCategories, true) || in_array('Izrāde', $rawCategories, true);
        if (!$isTheatre) {
            foreach ($theatreKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isTheatre = true;
                    break;
                }
            }
        }

        if ($isTheatre) {
            $categories = ['Teātris'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm') || str_contains($text, 'leļļu')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // Music & Concerts
        $musicKeywords = [
            'koncerts', 'koncertā', 'koncertprogramma', 'dziedātājs',
            'koris', 'koru', 'orķestris', 'orķestra', 'kvartets',
            'akustiskā tūre', 'dziesmas', 'muzikāls', 'džezs', 'roks', 'balle',
        ];
        $isMusic = in_array('Koncerts', $rawCategories, true) || in_array('Mūzika', $rawCategories, true);
        if (!$isMusic) {
            foreach ($musicKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isMusic = true;
                    break;
                }
            }
        }

        if ($isMusic) {
            $categories = ['Mūzika'];
            if (str_contains($text, 'bērniem') || str_contains($text, 'ģimenēm')) {
                $categories[] = 'Bērniem';
            }
            return array_unique($categories);
        }

        // Exhibitions & Art
        $exhibitionKeywords = [
            'izstāde', 'izstādē', 'izstādi', 'ekspozīcija',
            'personālizstāde', 'gleznas', 'glezniecība', 'gleznu',
            'tēlniecība', 'fotogrāfiju izstāde',
        ];
        $isExhibition = in_array('Izstāde', $rawCategories, true);
        if (!$isExhibition) {
            foreach ($exhibitionKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    $isExhibition = true;
                    break;
                }
            }
        }

        if ($isExhibition) {
            return ['Izstādes'];
        }

        // Sports
        $sportsKeywords = [
            'sports', 'sporta', 'skrējien', 'maraton', 'basketbol',
            'futbol', 'hokej', 'slidošan', 'turnīr', 'čempionāt',
            'sacensīb', 'nūjošan', 'peldēšan', 'volejbol', 'orientēšanās',
        ];
        foreach ($sportsKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return ['Sports'];
            }
        }

        // Children
        if (str_contains($text, 'bērniem') || str_contains($text, 'leļļu') || in_array('Bērniem', $rawCategories, true)) {
            return ['Bērniem'];
        }

        // Fallback: Cits (Strict AGENTS.md rule)
        return ['Cits'];
    }

    /**
     * Resolve entertainment type
     */
    public function resolveEntertainmentType(array $categories, string $title, string $description = ''): string
    {
        $text = mb_strtolower($title . ' ' . $description, 'UTF-8');

        if (in_array('Kino', $categories, true)) {
            return 'movie';
        }
        if (in_array('Mūzika', $categories, true)) {
            return 'concert';
        }
        if (in_array('Teātris', $categories, true)) {
            return 'theatre';
        }
        if (in_array('Izstādes', $categories, true)) {
            return 'exhibition';
        }
        if (in_array('Sports', $categories, true)) {
            return 'sports';
        }
        if (str_contains($text, 'festivāls') || str_contains($text, 'svētki') || str_contains($text, 'tirdziņš') || str_contains($text, 'gadatirgus')) {
            return 'festival';
        }

        return 'event';
    }

    /**
     * Parse Latvian dates from "span.date-date" and "span.date-time"
     */
    public function parseLatvianDates(string $dateStr, string $timeStr = ''): array
    {
        $months = [
            'janvāris' => 1, 'janvārī' => 1, 'janvāra' => 1, 'janv' => 1,
            'februāris' => 2, 'februārī' => 2, 'februāra' => 2, 'febr' => 2,
            'marts' => 3, 'martā' => 3, 'marta' => 3, 'mar' => 3,
            'aprīlis' => 4, 'aprīlī' => 4, 'aprīļa' => 4, 'apr' => 4,
            'maijs' => 5, 'maijā' => 5, 'maija' => 5, 'mai' => 5,
            'jūnijs' => 6, 'jūnijā' => 6, 'jūnija' => 6, 'jūn' => 6,
            'jūlijs' => 7, 'jūlijā' => 7, 'jūlija' => 7, 'jūl' => 7,
            'augusts' => 8, 'augustā' => 8, 'augusta' => 8, 'aug' => 8,
            'septembris' => 9, 'septembrī' => 9, 'septembra' => 9, 'sept' => 9,
            'oktobris' => 10, 'oktobrī' => 10, 'oktobra' => 10, 'okt' => 10,
            'novembris' => 11, 'novembrī' => 11, 'novembra' => 11, 'nov' => 11,
            'decembris' => 12, 'decembrī' => 12, 'decembra' => 12, 'dec' => 12,
        ];

        $clean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $dateStr));
        $allDay = false;

        // Parse time string e.g. "18.00", "18:00", "18.00 - 20.00", "Visu dienu"
        $startTime = '10:00:00';
        $endTime = null;

        $timeClean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $timeStr));
        if (mb_stripos($timeClean, 'visu dienu') !== false) {
            $allDay = true;
            $startTime = '00:00:00';
            $endTime = '23:59:59';
        } elseif (preg_match('/(\d{1,2})[.:](\d{2})\s*-\s*(\d{1,2})[.:](\d{2})/u', $timeClean, $tm)) {
            $startTime = sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]);
            $endTime = sprintf('%02d:%02d:00', (int)$tm[3], (int)$tm[4]);
        } elseif (preg_match('/(\d{1,2})[.:](\d{2})/u', $timeClean, $tm)) {
            $startTime = sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]);
        }

        // Check for date range e.g. "16. - 20. novembris, 2026" or "16. novembris - 5. decembris, 2026"
        if (str_contains($clean, '-')) {
            $parts = explode('-', $clean, 2);
            $p1 = trim($parts[0]);
            $p2 = trim($parts[1]);

            // Case: "16. - 20. novembris, 2026"
            if (preg_match('/^(\d{1,2})\.?$/u', $p1, $m1) && preg_match('/^(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p2, $m2)) {
                $day1 = (int) $m1[1];
                $day2 = (int) $m2[1];
                $monthName = mb_strtolower($m2[2], 'UTF-8');
                $month = $months[$monthName] ?? (int) date('n');
                $year = !empty($m2[3]) ? (int) $m2[3] : (int) date('Y');

                $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day1, $startTime), 'Europe/Riga');
                $endAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day2, $endTime ?: '22:00:00'), 'Europe/Riga');

                return [$startAt, $endAt, $allDay];
            }

            // Case: "16. novembris - 5. decembris, 2026"
            if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p1, $m1) &&
                preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p2, $m2)) {
                $day1 = (int) $m1[1];
                $mName1 = mb_strtolower($m1[2], 'UTF-8');
                $month1 = $months[$mName1] ?? (int) date('n');

                $day2 = (int) $m2[1];
                $mName2 = mb_strtolower($m2[2], 'UTF-8');
                $month2 = $months[$mName2] ?? (int) date('n');

                $year2 = !empty($m2[3]) ? (int) $m2[3] : (int) date('Y');
                $year1 = !empty($m1[3]) ? (int) $m1[3] : $year2;

                $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year1, $month1, $day1, $startTime), 'Europe/Riga');
                $endAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year2, $month2, $day2, $endTime ?: '22:00:00'), 'Europe/Riga');

                return [$startAt, $endAt, $allDay];
            }
        }

        // Single date e.g. "18. novembris, 2026" or "18. novembris"
        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $clean, $m)) {
            $day = (int) $m[1];
            $monthName = mb_strtolower($m[2], 'UTF-8');
            $month = $months[$monthName] ?? (int) date('n');
            $year = !empty($m[3]) ? (int) $m[3] : (int) date('Y');

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $startTime), 'Europe/Riga');
            $endAt = $endTime ? Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $endTime), 'Europe/Riga') : null;

            return [$startAt, $endAt, $allDay];
        }

        // Standard numeric date: "18.11.2026"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $clean, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            $startAt = Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $startTime), 'Europe/Riga');
            $endAt = $endTime ? Carbon::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $endTime), 'Europe/Riga') : null;

            return [$startAt, $endAt, $allDay];
        }

        return [null, null, false];
    }

    /**
     * Parse price and free status
     */
    public function parsePrice(string $content): array
    {
        $isFree = true;
        $priceMin = null;
        $priceMax = null;

        if (
            preg_match('/(?:ieeja|biļet[a-z]*|maksa)\s*[-–—:]?\s*(?:bez\s+maksas|brīva)/iu', $content) ||
            preg_match('/\b(?:bezmaksas|brīva\s+ieeja|ieeja\s+brīva)\b/iu', $content)
        ) {
            $isFree = true;
            $priceMin = 0.0;
            $priceMax = 0.0;
        } elseif (preg_match('/(?:ieeja|biļet[a-z]*|cena|maksa)\s*[-–—:]?\s*(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur)/iu', $content, $pm)) {
            $val = (float) str_replace(',', '.', $pm[1]);
            $isFree = false;
            $priceMin = $val;
            $priceMax = $val;
        } elseif (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:€|eur)/iu', $content, $pm)) {
            $val = (float) str_replace(',', '.', $pm[1]);
            if ($val > 0 && $val <= 500) {
                $isFree = false;
                $priceMin = $val;
                $priceMax = $val;
            }
        }

        return [
            'is_free' => $isFree,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }

    /**
     * Normalize relative or absolute event URLs
     */
    public function normalizeUrl(?string $relUrl): ?string
    {
        if (empty($relUrl)) {
            return null;
        }

        if (str_starts_with($relUrl, 'http://') || str_starts_with($relUrl, 'https://')) {
            $parsed = parse_url($relUrl);
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.talsunovads.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return self::BASE_URL . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    /**
     * Check if item is an administrative / municipal meeting that shouldn't be in cultural events calendar
     */
    public function isExcludedEvent(array $categories, string $title): bool
    {
        $categoriesLower = array_map(fn ($c) => mb_strtolower($c, 'UTF-8'), $categories);
        foreach ($categoriesLower as $cat) {
            if (
                str_contains($cat, 'komitejas sēde') ||
                str_contains($cat, 'domes sēde') ||
                str_contains($cat, 'ārkārtas sēde') ||
                str_contains($cat, 'iedzīvotāju pieņemšana')
            ) {
                return true;
            }
        }

        $titleLower = mb_strtolower($title, 'UTF-8');
        if (
            str_contains($titleLower, 'komitejas sēde') ||
            str_contains($titleLower, 'domes sēde') ||
            str_contains($titleLower, 'ārkārtas sēde') ||
            str_contains($titleLower, 'pieņem iedzīvotājus') ||
            str_contains($titleLower, 'deputātu tikšanās') ||
            str_contains($titleLower, 'deputāta pieņemšana')
        ) {
            return true;
        }

        return false;
    }
}
