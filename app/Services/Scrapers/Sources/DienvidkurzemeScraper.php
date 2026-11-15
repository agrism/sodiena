<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DienvidkurzemeScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.dienvidkurzeme.travel';
    public const CALENDAR_PATH = '/pasakumi-dienvidkurzeme/';
    public const DEFAULT_CALENDAR_ID = '41a31238ed947d80727babf9503e8fef4d59d22f863c8949542047ec6b87f836@group.calendar.google.com';

    /**
     * Map of known Dienvidkurzeme venues with normalized metadata
     */
    protected array $venueAddressMap = [
        'aizputes kultūras nams' => [
            'name' => 'Aizputes kultūras nams',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Atmodas iela 16, Aizpute, LV-3456',
            'place_type' => 'cultural_center',
            'lat' => 56.7208,
            'lng' => 21.6035,
        ],
        'aizputes novadpētniecības muzejs' => [
            'name' => 'Aizputes novadpētniecības muzejs',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 1, Aizpute, LV-3456',
            'place_type' => 'museum',
            'lat' => 56.7198,
            'lng' => 21.6022,
        ],
        'aizputes tūrisma informācijas centrs' => [
            'name' => 'Aizputes Tūrisma informācijas centrs',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Atmodas iela 16, Aizpute, LV-3456',
            'place_type' => 'visitor_center',
            'lat' => 56.7208,
            'lng' => 21.6035,
        ],
        'aizputes pilsētas bibliotēka' => [
            'name' => 'Aizputes pilsētas bibliotēka',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Atmodas iela 24, Aizpute, LV-3456',
            'place_type' => 'library',
            'lat' => 56.7215,
            'lng' => 21.6042,
        ],
        'aizputes skolēnu jaunrades centrs' => [
            'name' => 'Aizputes Skolēnu jaunrades centrs',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 1, Aizpute, LV-3456',
            'place_type' => 'cultural_center',
            'lat' => 56.7198,
            'lng' => 21.6022,
        ],
        'mārtiņa freimaņa piemiņas istaba' => [
            'name' => 'Mārtiņa Freimaņa piemiņas istaba',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 1, Aizpute, LV-3456',
            'place_type' => 'museum',
            'lat' => 56.7198,
            'lng' => 21.6022,
        ],
        'aizputes sporta centrs' => [
            'name' => 'Aizputes sporta centrs "Lejaskurzeme"',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Ziedu iela 7, Aizpute, LV-3456',
            'place_type' => 'sports_facility',
            'lat' => 56.7170,
            'lng' => 21.6010,
        ],
        'aizputes mākslas parks' => [
            'name' => 'Aizputes mākslas parks',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Mazā iela 3, Aizpute, LV-3456',
            'place_type' => 'park',
            'lat' => 56.7210,
            'lng' => 21.6020,
        ],
        'amalienburgas muiža' => [
            'name' => 'Amalienburgas muiža',
            'city' => 'Aizpute',
            'region' => 'Kurzeme',
            'address' => 'Atmodas iela 5, Aizpute, LV-3456',
            'place_type' => 'historical',
            'lat' => 56.7180,
            'lng' => 21.6000,
        ],
        'otto hotel & sun' => [
            'name' => 'OTTO Hotel & Sun',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Dzintaru iela 7, Pāvilosta, LV-3466',
            'place_type' => 'hotel',
            'lat' => 56.8876,
            'lng' => 21.1741,
        ],
        'pāvilostas pilsētas kultūras nams' => [
            'name' => 'Pāvilostas pilsētas kultūras nams',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Dzintaru iela 47, Pāvilosta, LV-3466',
            'place_type' => 'cultural_center',
            'lat' => 56.8895,
            'lng' => 21.1830,
        ],
        'pāvilostas kultūras nams' => [
            'name' => 'Pāvilostas pilsētas kultūras nams',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Dzintaru iela 47, Pāvilosta, LV-3466',
            'place_type' => 'cultural_center',
            'lat' => 56.8895,
            'lng' => 21.1830,
        ],
        'pāvilostas novadpētniecības muzejs' => [
            'name' => 'Pāvilostas novadpētniecības muzejs',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Dzintaru iela 1, Pāvilosta, LV-3466',
            'place_type' => 'museum',
            'lat' => 56.8869,
            'lng' => 21.1712,
        ],
        'pāvilostas pilsētas bibliotēka' => [
            'name' => 'Pāvilostas pilsētas bibliotēka',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Dzintaru iela 73, Pāvilosta, LV-3466',
            'place_type' => 'library',
            'lat' => 56.8910,
            'lng' => 21.1870,
        ],
        'pāvilostas pamatskola' => [
            'name' => 'Pāvilostas pamatskola',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Dzintaru iela 93, Pāvilosta, LV-3466',
            'place_type' => 'education',
            'lat' => 56.8920,
            'lng' => 21.1900,
        ],
        'pair' => [
            'name' => 'PAiR rezidence',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Ernesta Šneidera laukums 11, Pāvilosta, LV-3466',
            'place_type' => 'cultural_center',
            'lat' => 56.8885,
            'lng' => 21.1770,
        ],
        'upesmuižas parka estrāde' => [
            'name' => 'Upesmuižas parka estrāde',
            'city' => 'Pāvilosta',
            'region' => 'Kurzeme',
            'address' => 'Upesmuižas parks, Pāvilosta, LV-3466',
            'place_type' => 'park',
            'lat' => 56.8880,
            'lng' => 21.1800,
        ],
        'priekules kultūras nams' => [
            'name' => 'Priekules kultūras nams',
            'city' => 'Priekule',
            'region' => 'Kurzeme',
            'address' => 'Peldu iela 1, Priekule, LV-3434',
            'place_type' => 'cultural_center',
            'lat' => 56.4468,
            'lng' => 21.5912,
        ],
        'brīnumlāde' => [
            'name' => 'Svētku telpa "Brīnumlāde"',
            'city' => 'Priekule',
            'region' => 'Kurzeme',
            'address' => 'Raiņa iela 3-5, Priekule, LV-3434',
            'place_type' => 'cultural_center',
            'lat' => 56.4455,
            'lng' => 21.5900,
        ],
        'durbes kultūras nams' => [
            'name' => 'Durbes kultūras nams',
            'city' => 'Durbe',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 5, Durbe, LV-3440',
            'place_type' => 'cultural_center',
            'lat' => 56.5869,
            'lng' => 21.3694,
        ],
        'vaiņodes kultūras nams' => [
            'name' => 'Vaiņodes kultūras nams',
            'city' => 'Vaiņode',
            'region' => 'Kurzeme',
            'address' => 'Tirgoņu iela 23, Vaiņode, LV-3435',
            'place_type' => 'cultural_center',
            'lat' => 56.4172,
            'lng' => 21.8530,
        ],
        'vaiņodes lidlauks' => [
            'name' => 'Vaiņodes lidlauks',
            'city' => 'Vaiņode',
            'region' => 'Kurzeme',
            'address' => 'Raiņa iela 21, Vaiņode, LV-3435',
            'place_type' => 'historical',
            'lat' => 56.4050,
            'lng' => 21.8700,
        ],
        'volzbaha kalns' => [
            'name' => 'Volzbaha kalns',
            'city' => 'Vaiņode',
            'region' => 'Kurzeme',
            'address' => 'Baznīcas iela, Vaiņode, LV-3435',
            'place_type' => 'park',
            'lat' => 56.4250,
            'lng' => 21.8600,
        ],
        'kazdangas kultūras nams' => [
            'name' => 'Kazdangas kultūras nams',
            'city' => 'Kazdanga',
            'region' => 'Kurzeme',
            'address' => 'Pils gatve 4, Kazdanga, LV-3457',
            'place_type' => 'cultural_center',
            'lat' => 56.7322,
            'lng' => 21.6575,
        ],
        'kazdangas pils' => [
            'name' => 'Kazdangas pils',
            'city' => 'Kazdanga',
            'region' => 'Kurzeme',
            'address' => 'Jaunatnes gatve 1, Kazdanga, LV-3457',
            'place_type' => 'historical',
            'lat' => 56.7330,
            'lng' => 21.6600,
        ],
        'kazbārs' => [
            'name' => '"Kazbārs"',
            'city' => 'Kazdanga',
            'region' => 'Kurzeme',
            'address' => 'Liepu gatve, Kazdanga, LV-3457',
            'place_type' => 'venue',
            'lat' => 56.7325,
            'lng' => 21.6580,
        ],
        'cīravas kultūras nams' => [
            'name' => 'Cīravas kultūras nams',
            'city' => 'Cīrava',
            'region' => 'Kurzeme',
            'address' => 'Gaismas iela 1, Cīrava, LV-3453',
            'place_type' => 'cultural_center',
            'lat' => 56.7358,
            'lng' => 21.3811,
        ],
        'vērgales kultūras nams' => [
            'name' => 'Vērgales kultūras nams',
            'city' => 'Vērgale',
            'region' => 'Kurzeme',
            'address' => 'Vērgale, LV-3463',
            'place_type' => 'cultural_center',
            'lat' => 56.7119,
            'lng' => 21.1278,
        ],
        'gaviezes kultūras nams' => [
            'name' => 'Gaviezes kultūras nams',
            'city' => 'Gavieze',
            'region' => 'Kurzeme',
            'address' => 'Gavieze, LV-3431',
            'place_type' => 'cultural_center',
            'lat' => 56.4764,
            'lng' => 21.2889,
        ],
        'dunalkas kultūras nams' => [
            'name' => 'Dunalkas kultūras nams',
            'city' => 'Dunalka',
            'region' => 'Kurzeme',
            'address' => 'Danči, Dunalka, LV-3452',
            'place_type' => 'cultural_center',
            'lat' => 56.7028,
            'lng' => 21.3783,
        ],
        'otaņķu tautas nams' => [
            'name' => 'Otaņķu tautas nams',
            'city' => 'Otaņķi',
            'region' => 'Kurzeme',
            'address' => 'Rude, Otaņķu pagasts, LV-3475',
            'place_type' => 'cultural_center',
            'lat' => 56.3764,
            'lng' => 21.0886,
        ],
        'nīcas kultūras nams' => [
            'name' => 'Nīcas kultūras nams',
            'city' => 'Nīca',
            'region' => 'Kurzeme',
            'address' => 'Bārtas iela 6, Nīca, LV-3473',
            'place_type' => 'cultural_center',
            'lat' => 56.3475,
            'lng' => 21.0642,
        ],
        'bārtas kultūras nams' => [
            'name' => 'Bārtas kultūras nams',
            'city' => 'Bārta',
            'region' => 'Kurzeme',
            'address' => 'Bārta, LV-3482',
            'place_type' => 'cultural_center',
            'lat' => 56.3681,
            'lng' => 21.3411,
        ],
        'gramzdas tautas nams' => [
            'name' => 'Gramzdas tautas nams',
            'city' => 'Gramzda',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 5, Gramzda, LV-3486',
            'place_type' => 'cultural_center',
            'lat' => 56.3672,
            'lng' => 21.6033,
        ],
        'rucavas kultūras nams' => [
            'name' => 'Rucavas kultūras nams',
            'city' => 'Rucava',
            'region' => 'Kurzeme',
            'address' => 'Rucava, LV-3477',
            'place_type' => 'cultural_center',
            'lat' => 56.1603,
            'lng' => 21.1600,
        ],
        'rucavas tic' => [
            'name' => 'Rucavas tūrisma informācijas centrs',
            'city' => 'Rucava',
            'region' => 'Kurzeme',
            'address' => 'Rucava, LV-3477',
            'place_type' => 'visitor_center',
            'lat' => 56.1603,
            'lng' => 21.1600,
        ],
        'zvanītāji' => [
            'name' => 'Etnogrāfiskā māja "Zvanītāji"',
            'city' => 'Rucava',
            'region' => 'Kurzeme',
            'address' => 'Zvanītāji, Rucavas pagasts, LV-3477',
            'place_type' => 'museum',
            'lat' => 56.1620,
            'lng' => 21.1650,
        ],
        'bunkas kultūras nams' => [
            'name' => 'Bunkas kultūras nams',
            'city' => 'Bunka',
            'region' => 'Kurzeme',
            'address' => 'Bunka, LV-3446',
            'place_type' => 'cultural_center',
            'lat' => 56.4950,
            'lng' => 21.4989,
        ],
        'vecpils tautas nams' => [
            'name' => 'Vecpils tautas nams',
            'city' => 'Vecpils',
            'region' => 'Kurzeme',
            'address' => 'Vecpils, LV-3441',
            'place_type' => 'cultural_center',
            'lat' => 56.6272,
            'lng' => 21.5361,
        ],
        'vecpils senlietu krātuve' => [
            'name' => 'Vecpils senlietu krātuve',
            'city' => 'Vecpils',
            'region' => 'Kurzeme',
            'address' => 'Vecpils, LV-3441',
            'place_type' => 'museum',
            'lat' => 56.6272,
            'lng' => 21.5361,
        ],
        'medzes kultūras nams' => [
            'name' => 'Medzes kultūras nams',
            'city' => 'Medze',
            'region' => 'Kurzeme',
            'address' => 'Kapsēde, LV-3461',
            'place_type' => 'cultural_center',
            'lat' => 56.5911,
            'lng' => 21.1092,
        ],
        'lieģu kultūras nams' => [
            'name' => 'Lieģu kultūras nams',
            'city' => 'Lieģi',
            'region' => 'Kurzeme',
            'address' => 'Lieģi, Tadaiķu pagasts, LV-3430',
            'place_type' => 'cultural_center',
            'lat' => 56.5786,
            'lng' => 21.3325,
        ],
        'kalētu tautas nams' => [
            'name' => 'Kalētu tautas nams',
            'city' => 'Kalēti',
            'region' => 'Kurzeme',
            'address' => 'Kalēti, LV-3484',
            'place_type' => 'cultural_center',
            'lat' => 56.3606,
            'lng' => 21.5033,
        ],
        'kalvenes kultūras nams' => [
            'name' => 'Kalvenes kultūras nams',
            'city' => 'Kalvene',
            'region' => 'Kurzeme',
            'address' => 'Skolas iela 4, Kalvene, LV-3443',
            'place_type' => 'cultural_center',
            'lat' => 56.6025,
            'lng' => 21.7333,
        ],
        'zentas mauriņas piemiņas istabas' => [
            'name' => 'Zentas Mauriņas piemiņas istabas',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 84, Grobiņa, LV-3430',
            'place_type' => 'museum',
            'lat' => 56.5345,
            'lng' => 21.1675,
        ],
        'grobiņas pilsdrupas' => [
            'name' => 'Grobiņas pilsdrupas',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'Grobiņa, LV-3430',
            'place_type' => 'historical',
            'lat' => 56.5361,
            'lng' => 21.1642,
        ],
        'dienvidkurzemes novada bibliotēka' => [
            'name' => 'Dienvidkurzemes novada bibliotēka',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'Lielā iela 54, Grobiņa, LV-3430',
            'place_type' => 'library',
            'lat' => 56.5340,
            'lng' => 21.1660,
        ],
        'tabitas sirds' => [
            'name' => '"Tabitas sirds" izdales centrs',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'Liepu iela 1A, Robežnieki, Grobiņas pagasts, LV-3430',
            'place_type' => 'venue',
            'lat' => 56.5400,
            'lng' => 21.1700,
        ],
        'virgas tradīciju nams' => [
            'name' => 'Virgas tradīciju nams',
            'city' => 'Virga',
            'region' => 'Kurzeme',
            'address' => 'Virga, LV-3433',
            'place_type' => 'cultural_center',
            'lat' => 56.4258,
            'lng' => 21.4089,
        ],
        'apriķu muiža' => [
            'name' => 'Apriķu muiža-muzejs',
            'city' => 'Apriķi',
            'region' => 'Kurzeme',
            'address' => 'Apriķi, Lažas pagasts, LV-3455',
            'place_type' => 'museum',
            'lat' => 56.8167,
            'lng' => 21.5000,
        ],
        'ziemupes tautas nams' => [
            'name' => 'Ziemupes tautas nams',
            'city' => 'Ziemupe',
            'region' => 'Kurzeme',
            'address' => 'Ziemupe, Vērgales pagasts, LV-3463',
            'place_type' => 'cultural_center',
            'lat' => 56.7500,
            'lng' => 21.0667,
        ],
    ];

    /**
     * Map of Dienvidkurzeme towns and settlements coordinates
     */
    protected array $townCoordinates = [
        'grobiņa' => ['lat' => 56.5350, 'lng' => 21.1680],
        'aizpute' => ['lat' => 56.7214, 'lng' => 21.6014],
        'priekule' => ['lat' => 56.4461, 'lng' => 21.5897],
        'pāvilosta' => ['lat' => 56.8883, 'lng' => 21.1764],
        'durbe' => ['lat' => 56.5878, 'lng' => 21.3686],
        'vaiņode' => ['lat' => 56.4183, 'lng' => 21.8542],
        'nīca' => ['lat' => 56.3475, 'lng' => 21.0642],
        'rucava' => ['lat' => 56.1603, 'lng' => 21.1600],
        'kazdanga' => ['lat' => 56.7314, 'lng' => 21.6583],
        'cīrava' => ['lat' => 56.7358, 'lng' => 21.3811],
        'vērgale' => ['lat' => 56.7119, 'lng' => 21.1278],
        'dunalka' => ['lat' => 56.7028, 'lng' => 21.3783],
        'gavieze' => ['lat' => 56.4764, 'lng' => 21.2889],
        'vecpils' => ['lat' => 56.6272, 'lng' => 21.5361],
        'bunka' => ['lat' => 56.4950, 'lng' => 21.4989],
        'bārta' => ['lat' => 56.3681, 'lng' => 21.3411],
        'gramzda' => ['lat' => 56.3672, 'lng' => 21.6033],
        'kalēti' => ['lat' => 56.3606, 'lng' => 21.5033],
        'kalvene' => ['lat' => 56.6025, 'lng' => 21.7333],
        'medze' => ['lat' => 56.5911, 'lng' => 21.1092],
        'kapsēde' => ['lat' => 56.5911, 'lng' => 21.1092],
        'otaņķi' => ['lat' => 56.3764, 'lng' => 21.0886],
        'rude' => ['lat' => 56.3764, 'lng' => 21.0886],
        'saraiķi' => ['lat' => 56.6583, 'lng' => 21.0250],
        'bernāti' => ['lat' => 56.3769, 'lng' => 20.9856],
        'virga' => ['lat' => 56.4258, 'lng' => 21.4089],
        'krote' => ['lat' => 56.5333, 'lng' => 21.5333],
        'apriķi' => ['lat' => 56.8167, 'lng' => 21.5000],
        'ziemupe' => ['lat' => 56.7500, 'lng' => 21.0667],
        'embūte' => ['lat' => 56.5056, 'lng' => 21.8217],
        'vībiņi' => ['lat' => 56.5056, 'lng' => 21.8217],
    ];

    public function getSlug(): string
    {
        return 'dienvidkurzeme';
    }

    public function getName(): string
    {
        return 'Dienvidkurzeme Travel';
    }

    public function scrape(Source $source): Collection
    {
        $events = collect();
        $targetUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);

        try {
            $icsUrl = $this->discoverIcsUrl($targetUrl);
            $response = $this->httpClient->get($icsUrl);
            $icsContent = (string) $response->getBody();

            if (empty($icsContent)) {
                Log::warning("Empty ICS content fetched for Dienvidkurzeme: {$icsUrl}");
                return $events;
            }

            return $this->parseIcs($icsContent, $targetUrl);
        } catch (\Throwable $e) {
            Log::error("Dienvidkurzeme scraper error: " . $e->getMessage(), [
                'exception' => $e,
                'target_url' => $targetUrl,
            ]);
            return $events;
        }
    }

    /**
     * Discover or construct public Google Calendar ICS URL
     */
    public function discoverIcsUrl(string $targetUrl): string
    {
        // If target URL directly points to an ICS feed
        if (str_contains($targetUrl, '.ics') || str_contains($targetUrl, 'calendar.google.com/calendar/ical/')) {
            return $targetUrl;
        }

        $calendarId = null;

        try {
            $response = $this->httpClient->get($targetUrl);
            $html = (string) $response->getBody();

            // Extract Google Calendar iframe src parameter
            if (preg_match('/calendar\.google\.com\/calendar\/embed\?[^"\']*src=([^&"\']+)/i', $html, $matches)) {
                $rawSrc = urldecode($matches[1]);
                if (str_contains($rawSrc, '@')) {
                    $calendarId = $rawSrc;
                } else {
                    $decoded = base64_decode($rawSrc, true);
                    if ($decoded && str_contains($decoded, '@')) {
                        $calendarId = $decoded;
                    } else {
                        $calendarId = $rawSrc;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch HTML for calendar embed discovery: " . $e->getMessage());
        }

        if (!$calendarId) {
            $calendarId = self::DEFAULT_CALENDAR_ID;
        }

        return 'https://calendar.google.com/calendar/ical/' . urlencode($calendarId) . '/public/basic.ics';
    }

    /**
     * Parse RFC 5545 iCalendar content into a collection of ScrapedEventDTO
     *
     * @return Collection<int, ScrapedEventDTO>
     */
    public function parseIcs(string $icsContent, string $fallbackSourceUrl): Collection
    {
        $events = collect();
        $today = Carbon::today('Europe/Riga');
        $seenExternalIds = [];

        // Unfold wrapped lines according to RFC 5545 (CRLF followed by space/tab)
        $unfolded = preg_replace('/\r?\n[ \t]/', '', $icsContent);
        $veventBlocks = explode('BEGIN:VEVENT', $unfolded);

        // Skip the header before the first BEGIN:VEVENT
        foreach (array_slice($veventBlocks, 1) as $block) {
            $lines = preg_split('/\r?\n/', $block);
            $props = [];

            foreach ($lines as $line) {
                if (preg_match('/^([A-Z0-9_-]+)(?:;([^:]+))?:(.*)$/', $line, $matches)) {
                    $propName = $matches[1];
                    $params = $matches[2];
                    $val = $matches[3];

                    if (!isset($props[$propName])) {
                        $props[$propName] = [];
                    }
                    $props[$propName][] = [
                        'params' => $params,
                        'value' => $val,
                    ];
                }
            }

            // Check STATUS - skip cancelled events
            $status = isset($props['STATUS']) ? trim($props['STATUS'][0]['value']) : 'CONFIRMED';
            if (strcasecmp($status, 'CANCELLED') === 0) {
                continue;
            }

            // 1. UID as unique identifier
            $uid = isset($props['UID']) ? trim($props['UID'][0]['value']) : null;
            if (!$uid) {
                continue;
            }
            if (isset($seenExternalIds[$uid])) {
                continue;
            }
            $seenExternalIds[$uid] = true;

            // 2. SUMMARY as Title
            $rawSummary = isset($props['SUMMARY']) ? $props['SUMMARY'][0]['value'] : '';
            $title = trim($this->unescapeIcs($rawSummary));
            if (empty($title)) {
                continue;
            }

            // 3. Start & End Dates
            $dtstartRaw = $props['DTSTART'][0]['value'] ?? null;
            $dtstartParams = $props['DTSTART'][0]['params'] ?? null;
            if (!$dtstartRaw) {
                continue;
            }

            $startAt = $this->parseIcsDate($dtstartRaw, $dtstartParams);
            if (!$startAt) {
                continue;
            }

            $dtendRaw = $props['DTEND'][0]['value'] ?? null;
            $dtendParams = $props['DTEND'][0]['params'] ?? null;
            $endAt = $dtendRaw ? $this->parseIcsEndDate($dtendRaw, $dtendParams, $startAt) : null;

            // STRICT RULE: No Past Events Modification
            $effectiveEnd = $endAt ?: $startAt;
            if ($effectiveEnd->lt($today)) {
                continue;
            }

            // 4. Raw Description & Location
            $rawDescription = isset($props['DESCRIPTION']) ? $props['DESCRIPTION'][0]['value'] : null;
            $unescapedDesc = $rawDescription !== null ? $this->unescapeIcs($rawDescription) : null;

            $rawLocation = isset($props['LOCATION']) ? $props['LOCATION'][0]['value'] : null;
            $unescapedLocation = $rawLocation !== null ? $this->unescapeIcs($rawLocation) : null;

            // Extract ATTACH attachments (e.g. Google Drive files or direct images)
            $attachUrls = [];
            if (!empty($props['ATTACH'])) {
                foreach ($props['ATTACH'] as $att) {
                    $attachUrls[] = trim($att['value']);
                }
            }

            // 5. Parse Links & Images
            $mediaAndLinks = $this->extractUrlsAndImage($unescapedDesc, $attachUrls, $fallbackSourceUrl);
            $eventSourceUrl = $mediaAndLinks['source_url'] ?: $fallbackSourceUrl;
            $ticketUrl = $mediaAndLinks['ticket_url'];
            $imageUrl = $mediaAndLinks['image_url'];

            // 6. Clean Text Description
            $cleanDescription = $this->cleanText($unescapedDesc);
            $shortDescription = !empty($cleanDescription)
                ? Str::limit(preg_replace('/\s+/', ' ', $cleanDescription), 250, '...')
                : null;

            // 7. Resolve Location
            $locationInfo = $this->resolveLocation($unescapedLocation, $title, $cleanDescription);

            // 8. Prices & Free Admission
            $priceInfo = $this->extractPricesAndFree($unescapedDesc ?? $title);

            // 9. Categories
            $categories = $this->inferCategory($title, $cleanDescription, $locationInfo['name']);

            $dto = new ScrapedEventDTO(
                title: $title,
                startAt: $startAt,
                endAt: $endAt,
                description: $cleanDescription,
                shortDescription: $shortDescription,
                venueName: $locationInfo['name'],
                city: $locationInfo['city'],
                region: 'Kurzeme',
                address: $locationInfo['address'],
                latitude: $locationInfo['lat'],
                longitude: $locationInfo['lng'],
                placeType: $locationInfo['place_type'],
                categoryNames: $categories,
                entertainmentType: null,
                isFree: $priceInfo['is_free'],
                priceMin: $priceInfo['price_min'],
                priceMax: $priceInfo['price_max'],
                currency: 'EUR',
                ticketUrl: $ticketUrl,
                imageUrl: $imageUrl,
                sourceUrl: $eventSourceUrl,
                sourceExternalId: $uid,
                locale: 'lv',
                rawData: [
                    'uid' => $uid,
                    'raw_location' => $unescapedLocation,
                    'dtstart' => $dtstartRaw,
                    'dtend' => $dtendRaw,
                ]
            );

            $events->push($dto);
        }

        return $events;
    }

    /**
     * Parse iCal start date into Carbon (Europe/Riga)
     */
    public function parseIcsDate(string $raw, ?string $params = null): ?Carbon
    {
        $raw = trim($raw);
        if (empty($raw)) {
            return null;
        }

        try {
            // All-day date format: YYYYMMDD
            if (($params && str_contains($params, 'VALUE=DATE')) || (strlen($raw) === 8 && ctype_digit($raw))) {
                return Carbon::createFromFormat('!Ymd', substr($raw, 0, 8), 'Europe/Riga');
            }

            // UTC format: YYYYMMDDTHHiSSZ
            if (str_ends_with($raw, 'Z')) {
                return Carbon::parse($raw)->setTimezone('Europe/Riga');
            }

            // Local format: YYYYMMDDTHHiSS
            $clean = preg_replace('/[^0-9T]/', '', $raw);
            if (strlen($clean) >= 15) {
                return Carbon::createFromFormat('Ymd\THis', substr($clean, 0, 15), 'Europe/Riga');
            }

            return Carbon::parse($raw, 'Europe/Riga');
        } catch (\Throwable $e) {
            Log::warning("Could not parse ICS start date '{$raw}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse iCal end date into Carbon (Europe/Riga)
     */
    public function parseIcsEndDate(string $raw, ?string $params = null, ?Carbon $start = null): ?Carbon
    {
        $raw = trim($raw);
        if (empty($raw)) {
            return null;
        }

        try {
            // All-day end date in iCalendar standard is non-inclusive (e.g. 20261116 means ends at end of 20261115)
            if (($params && str_contains($params, 'VALUE=DATE')) || (strlen($raw) === 8 && ctype_digit($raw))) {
                $end = Carbon::createFromFormat('!Ymd', substr($raw, 0, 8), 'Europe/Riga');
                if ($start && $end->gt($start)) {
                    return $end->subSecond();
                }
                return $end->endOfDay();
            }

            // UTC format
            if (str_ends_with($raw, 'Z')) {
                return Carbon::parse($raw)->setTimezone('Europe/Riga');
            }

            // Local format
            $clean = preg_replace('/[^0-9T]/', '', $raw);
            if (strlen($clean) >= 15) {
                return Carbon::createFromFormat('Ymd\THis', substr($clean, 0, 15), 'Europe/Riga');
            }

            return Carbon::parse($raw, 'Europe/Riga');
        } catch (\Throwable $e) {
            Log::warning("Could not parse ICS end date '{$raw}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Unescape standard iCalendar RFC 5545 escaped characters
     */
    public function unescapeIcs(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        return str_replace(
            ['\\\\', '\\,', '\\;', '\\n', '\\N'],
            ['\\', ',', ';', "\n", "\n"],
            $text
        );
    }

    /**
     * Extract links (tickets, info) and images (Drive thumbnails or direct image URLs)
     */
    public function extractUrlsAndImage(?string $description, array $attachUrls, string $fallbackSourceUrl): array
    {
        $ticketUrl = null;
        $sourceUrl = null;
        $imageUrl = null;

        // 1. Process ATTACH properties first for image
        foreach ($attachUrls as $att) {
            $parsedImg = $this->normalizeImageUrl($att);
            if ($parsedImg) {
                $imageUrl = $parsedImg;
                break;
            }
        }

        if ($description) {
            // Check for HTML img tags
            if (!$imageUrl && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $description, $m)) {
                $imageUrl = $this->normalizeImageUrl($m[1]);
            }

            // Check for HTML links
            if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $description, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $url = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $anchorText = mb_strtolower(trim(strip_tags($match[2])), 'UTF-8');

                    if (empty($url) || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
                        continue;
                    }

                    // Ticket link detection
                    if (
                        str_contains($url, 'bilesuparadize.lv') ||
                        str_contains($url, 'bilesuserviss.lv') ||
                        str_contains($url, 'bezrindas.lv') ||
                        str_contains($url, 'ticketbest.eu') ||
                        str_contains($url, 'ekase.lv') ||
                        str_contains($url, 'fienta.com') ||
                        str_contains($anchorText, 'biļet') ||
                        str_contains($anchorText, 'pirkt biļeti')
                    ) {
                        if (!$ticketUrl) {
                            $ticketUrl = $url;
                        }
                    }

                    // Source / detail page on dienvidkurzeme.travel
                    if (str_contains($url, 'dienvidkurzeme.travel')) {
                        if (!$sourceUrl) {
                            $sourceUrl = $url;
                        }
                    }

                    // Check if link is an image
                    if (!$imageUrl && preg_match('/\.(?:jpg|jpeg|png|webp|gif)(?:\?.*)?$/i', $url)) {
                        $imageUrl = $this->normalizeImageUrl($url);
                    }
                }
            }

            // Direct image URL in text
            if (!$imageUrl && preg_match('/https?:\/\/[^\s<>"\'()]+\.(?:jpg|jpeg|png|webp)(?:\?[^\s<>"\'()]*)?/i', $description, $imgMatch)) {
                $imageUrl = $this->normalizeImageUrl($imgMatch[0]);
            }

            // Bare URLs in description if no ticketUrl or sourceUrl found
            if (!$sourceUrl && preg_match('/https?:\/\/www\.dienvidkurzeme\.travel\/[^\s<>"\'()]+/i', $description, $srcMatch)) {
                $sourceUrl = $srcMatch[0];
            }
        }

        return [
            'ticket_url' => $ticketUrl,
            'source_url' => $sourceUrl,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * Normalize image URL, converting Google Drive links to CDN thumbnail URLs
     */
    public function normalizeImageUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);

        // Google Drive share link -> Google Drive direct CDN thumbnail
        if (preg_match('/drive\.google\.com\/.*[?&]id=([a-zA-Z0-9_-]+)/i', $url, $m)) {
            return "https://lh3.googleusercontent.com/d/{$m[1]}";
        }
        if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/i', $url, $m)) {
            return "https://lh3.googleusercontent.com/d/{$m[1]}";
        }

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return null;
    }

    /**
     * Resolve Location metadata for Dienvidkurzemes novads
     */
    public function resolveLocation(?string $rawLocation, string $title, ?string $description = null): array
    {
        $raw = trim($rawLocation ?? '');
        $combined = mb_strtolower($raw . ' ' . $title . ' ' . ($description ?? ''), 'UTF-8');

        // 1. Check known venue map
        foreach ($this->venueAddressMap as $venueKey => $info) {
            if (str_contains($combined, $venueKey)) {
                return $info;
            }
        }

        // 2. Identify town / settlement from location or context (specific villages first, then parishes and towns)
        $townStems = [
            'saraiķ' => 'saraiķi',
            'ziemup' => 'ziemupe',
            'kapsēd' => 'kapsēde',
            'krot' => 'krote',
            'apriķ' => 'apriķi',
            'rud' => 'rude',
            'bernāt' => 'bernāti',
            'vībiņ' => 'vībiņi',
            'pāvilost' => 'pāvilosta',
            'aizput' => 'aizpute',
            'priekul' => 'priekule',
            'durb' => 'durbe',
            'vaiņod' => 'vaiņode',
            'nīc' => 'nīca',
            'rucav' => 'rucava',
            'kazdang' => 'kazdanga',
            'cīrav' => 'cīrava',
            'vērgal' => 'vērgale',
            'dunalk' => 'dunalka',
            'gaviez' => 'gavieze',
            'vecpils' => 'vecpils',
            'bunk' => 'bunka',
            'bārt' => 'bārta',
            'gramzd' => 'gramzda',
            'kalēt' => 'kalēti',
            'kalven' => 'kalvene',
            'medz' => 'medze',
            'otaņķ' => 'otaņķi',
            'virg' => 'virga',
            'embūt' => 'embūte',
            'grobiņ' => 'grobiņa',
        ];

        $matchedTown = null;
        // Check raw location first
        foreach ($townStems as $stem => $canonicalTown) {
            if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $raw)) {
                $matchedTown = $canonicalTown;
                break;
            }
        }
        // If not found in raw, check combined
        if (!$matchedTown) {
            foreach ($townStems as $stem => $canonicalTown) {
                if (preg_match('/\b' . preg_quote($stem, '/') . '[a-zāčēģīķļņšūž]*/ui', $combined)) {
                    $matchedTown = $canonicalTown;
                    break;
                }
            }
        }

        $townName = $matchedTown ? Str::title($matchedTown) : 'Grobiņa';
        $coords = $matchedTown ? $this->townCoordinates[$matchedTown] : ['lat' => 56.5350, 'lng' => 21.1680];

        // 3. Extract venue name and address from comma-separated location string
        $venueName = $townName;
        $address = $raw;

        if (!empty($raw)) {
            $parts = array_map('trim', explode(',', $raw));

            // Clean trailing country/municipality redundant tokens
            $cleanParts = array_filter($parts, function ($part) {
                $p = mb_strtolower($part, 'UTF-8');
                return $p !== 'latvija' && $p !== 'latvia' && $p !== 'dienvidkurzemes novads' && $p !== 'south kurzeme municipality';
            });

            if (!empty($cleanParts)) {
                $venueName = reset($cleanParts);
                $address = implode(', ', $cleanParts) . ', Dienvidkurzemes novads';
            }
        }

        // 4. Infer place type
        $placeType = 'venue';
        $lowerVenue = mb_strtolower($venueName, 'UTF-8');
        if (str_contains($lowerVenue, 'kultūras nams') || str_contains($lowerVenue, 'tautas nams') || str_contains($lowerVenue, 'saieta nams')) {
            $placeType = 'cultural_center';
        } elseif (str_contains($lowerVenue, 'muzejs') || str_contains($lowerVenue, 'senlietu krātuve') || str_contains($lowerVenue, 'piemiņas istaba')) {
            $placeType = 'museum';
        } elseif (str_contains($lowerVenue, 'muiža') || str_contains($lowerVenue, 'pils') || str_contains($lowerVenue, 'drupas')) {
            $placeType = 'historical';
        } elseif (str_contains($lowerVenue, 'bibliotēka')) {
            $placeType = 'library';
        } elseif (str_contains($lowerVenue, 'sporta') || str_contains($lowerVenue, 'stadions')) {
            $placeType = 'sports_facility';
        } elseif (str_contains($lowerVenue, 'parks') || str_contains($lowerVenue, 'estrāde') || str_contains($lowerVenue, 'dārzs')) {
            $placeType = 'park';
        } elseif (str_contains($lowerVenue, 'skola') || str_contains($lowerVenue, 'ģimnāzija')) {
            $placeType = 'education';
        } elseif (str_contains($lowerVenue, 'baznīca')) {
            $placeType = 'religious';
        } elseif (str_contains($lowerVenue, 'hotel') || str_contains($lowerVenue, 'viesu nams')) {
            $placeType = 'hotel';
        }

        return [
            'name' => $venueName,
            'city' => $townName,
            'region' => 'Kurzeme',
            'address' => $address ?: "{$townName}, Dienvidkurzemes novads",
            'place_type' => $placeType,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
        ];
    }

    /**
     * Extract prices and free admission indicators
     */
    public function extractPricesAndFree(?string $text): array
    {
        if (empty($text)) {
            return [
                'is_free' => false,
                'price_min' => null,
                'price_max' => null,
            ];
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $isFree = false;

        if (preg_match('/(?:bez\s+maksas|bezmaksas|ieeja\s+brīva|briva\s+ieeja|ieeja\s+par\s+brīvu)/ui', $text)) {
            $isFree = true;
        }

        $prices = [];
        // Matches e.g. "Ieeja 10 eiro", "5 euro", "3 EUR", "10 €"
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:eiro|euro|€|eur)\b/ui', $text, $matches)) {
            foreach ($matches[1] as $priceStr) {
                $val = (float) str_replace(',', '.', $priceStr);
                if ($val > 0 && $val <= 500) {
                    $prices[] = $val;
                }
            }
        }

        $priceMin = !empty($prices) ? min($prices) : ($isFree ? 0.0 : null);
        $priceMax = !empty($prices) ? max($prices) : ($isFree ? 0.0 : null);

        return [
            'is_free' => $isFree && empty($prices),
            'price_min' => $priceMin,
            'price_max' => $priceMax,
        ];
    }

    /**
     * Strict categorization complying with AGENTS.md rules
     */
    public function inferCategory(string $title, ?string $description = null, ?string $venueName = null): array
    {
        $text = mb_strtolower($title . ' ' . ($description ?? '') . ' ' . ($venueName ?? ''), 'UTF-8');
        $titleLower = mb_strtolower($title, 'UTF-8');
        $venueLower = mb_strtolower($venueName ?? '', 'UTF-8');

        // 1. Strict Kino rules from AGENTS.md (Priority: Cinema venues or keywords -> ALWAYS Kino, NEVER Teātris)
        if (
            str_contains($venueLower, 'kino rio') ||
            str_contains($venueLower, 'k.suns') ||
            str_contains($venueLower, 'k suns') ||
            str_contains($venueLower, 'forum cinemas') ||
            str_contains($venueLower, 'apollo kino') ||
            str_contains($venueLower, 'cinamon') ||
            str_contains($venueLower, 'splendid palace') ||
            str_contains($venueLower, 'kino bize') ||
            str_contains($venueLower, 'kino lora') ||
            str_contains($venueLower, 'kino gaisma') ||
            preg_match('/\bkinoteātr[a-zāčēģīķļņšūž]*/ui', $venueLower) ||
            preg_match('/\bkinoteātr[a-zāčēģīķļņšūž]*/ui', $titleLower) ||
            preg_match('/\b(kinoseans[a-zāčēģīķļņšūž]*|film[a-zāčēģīķļņšūž]*|filmu vakars|spēlfilm[a-zāčēģīķļņšūž]*|dokumentāl[a-zāčēģīķļņšūž]* film[a-zāčēģīķļņšūž]*|animācij[a-zāčēģīķļņšūž]* film[a-zāčēģīķļņšūž]*|kinofestivāl[a-zāčēģīķļņšūž]*)\b/ui', $titleLower) ||
            preg_match('/\b(kinoseans[a-zāčēģīķļņšūž]*|filmas seans[a-zāčēģīķļņšūž]*|spēlfilm[a-zāčēģīķļņšūž]*|dokumentāl[a-zāčēģīķļņšūž]* film[a-zāčēģīķļņšūž]*|animācij[a-zāčēģīķļņšūž]* film[a-zāčēģīķļņšūž]*)\b/ui', $text)
        ) {
            return ['Kino'];
        }

        // 2. Strict Theatre rules from AGENTS.md (Only actual theater productions or theater venues, never kinoteātris)
        if (
            !preg_match('/\bkinoteātr/ui', $text) && (
                preg_match('/\b(izrād[a-zāčēģīķļņšūž]*|teātr[a-zāčēģīķļņšūž]*|amatierteātr[a-zāčēģīķļņšūž]*|lug[a-zāčēģīķļņšūž]*|iestudējum[a-zāčēģīķļņšūž]*|oper[a-zāčēģīķļņšūž]*|balet[a-zāčēģīķļņšūž]*)\b/ui', $titleLower) ||
                preg_match('/\b(teātra izrād[a-zāčēģīķļņšūž]*|amatierteātra izrād[a-zāčēģīķļņšūž]*|pirmizrād[a-zāčēģīķļņšūž]*|leļļu izrād[a-zāčēģīķļņšūž]*)\b/ui', $text)
            )
        ) {
            return ['Teātris'];
        }

        // 3. Music & Concerts
        if (preg_match('/\b(koncert[a-zāčēģīķļņšūž]*|dzejkoncert[a-zāčēģīķļņšūž]*|koru|koris|korim|orķestr[a-zāčēģīķļņšūž]*|akustisk[a-zāčēģīķļņšūž]*|dziesm[a-zāčēģīķļņšūž]*|ball[a-zāčēģīķļņšūž]*|zaļumball[a-zāčēģīķļņšūž]*|muzikāl[a-zāčēģīķļņšūž]*|mūzik[a-zāčēģīķļņšūž]*|muzicē|grupa|ansambl[a-zāčēģīķļņšūž]*|dziedātāj[a-zāčēģīķļņšūž]*|solist[a-zāčēģīķļņšūž]*)\b/ui', $text)) {
            return ['Mūzika'];
        }

        // 4. Sports & Active
        if (preg_match('/\b(sports|sporta|sportisk[a-zāčēģīķļņšūž]*|sacensīb[a-zāčēģīķļņšūž]*|čempionāt[a-zāčēģīķļņšūž]*|turnīr[a-zāčēģīķļņšūž]*|skrējien[a-zāčēģīķļņšūž]*|maraton[a-zāčēģīķļņšūž]*|pārgājien[a-zāčēģīķļņšūž]*|velobraucien[a-zāčēģīķļņšūž]*|orientēšan[a-zāčēģīķļņšūž]*|basketbol[a-zāčēģīķļņšūž]*|futbol[a-zāčēģīķļņšūž]*|volejbol[a-zāčēģīķļņšūž]*|florbol[a-zāčēģīķļņšūž]*|hokej[a-zāčēģīķļņšūž]*|nūjošan[a-zāčēģīķļņšūž]*|izpēdo dienvidkurzemi|makšķerēšan[a-zāčēģīķļņšūž]*|regāt[a-zāčēģīķļņšūž]*|šahs)\b/ui', $text)) {
            return ['Sports'];
        }

        // 5. Exhibitions & Art
        if (preg_match('/\b(fotoizstād[a-zāčēģīķļņšūž]*|mākslas izstād[a-zāčēģīķļņšūž]*|izstād[a-zāčēģīķļņšūž]*|ekspozīcij[a-zāčēģīķļņšūž]*|glezn[a-zāčēģīķļņšūž]*|fotogrāfij[a-zāčēģīķļņšūž]*|fotomāksl[a-zāčēģīķļņšūž]*|māksl[a-zāčēģīķļņšūž]*|keramik[a-zāčēģīķļņšūž]*|tēlniecīb[a-zāčēģīķļņšūž]*|simpozij[a-zāčēģīķļņšūž]*|rokdarb[a-zāčēģīķļņšūž]*)\b/ui', $text)) {
            return ['Izstādes'];
        }

        // 6. Kids & Family
        if (preg_match('/\b(bērniem|bērnu|ģimenēm|leļļu|radošā darbnīca bērniem|rotaļas|animācija)\b/ui', $text)) {
            return ['Bērniem'];
        }

        // 7. Education & Workshops
        if (preg_match('/\b(meistarklas[a-zāčēģīķļņšūž]*|seminār[a-zāčēģīķļņšūž]*|lekcij[a-zāčēģīķļņšūž]*|nodarbīb[a-zāčēģīķļņšūž]*|darbnīc[a-zāčēģīķļņšūž]*|diskusij[a-zāčēģīķļņšūž]*|sarunu vakars|stāstu vakars|viktorīn[a-zāčēģīķļņšūž]*|konferenc[a-zāčēģīķļņšūž]*)\b/ui', $text)) {
            return ['Izglītība'];
        }

        // 8. Holidays & Culture
        if (preg_match('/\b(svētk[a-zāčēģīķļņšūž]*|svinīb[a-zāčēģīķļņšūž]*|lāčplēš[a-zāčēģīķļņšūž]*|valsts svētk[a-zāčēģīķļņšūž]*|mārtiņdien[a-zāčēģīķļņšūž]*|ziemassvētk[a-zāčēģīķļņšūž]*|egles iedegšan[a-zāčēģīķļņšūž]*|jaunais gads|lieldien[a-zāčēģīķļņšūž]*|saulgriež[a-zāčēģīķļņšūž]*|jāņi|nēģu dien[a-zāčēģīķļņšūž]*|senās uguns nakts)\b/ui', $text)) {
            return ['Kultūra'];
        }

        // 9. Fallback
        return ['Cits'];
    }
}
