<?php

namespace App\Services\Scrapers\Sources;

use App\Models\Source;
use App\Services\Scrapers\BaseScraper;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class FloorballLvScraper extends BaseScraper
{
    public const DEFAULT_IMAGE = 'https://sodiena.hel1.your-objectstorage.com/leagues/floorball_elvi.webp';
    public const DEFAULT_IMAGE_ELVI = 'https://sodiena.hel1.your-objectstorage.com/leagues/floorball_elvi.webp';
    public const DEFAULT_IMAGE_LATEST = 'https://sodiena.hel1.your-objectstorage.com/leagues/floorball_latest.webp';

    public function getSlug(): string
    {
        return 'floorball-lv';
    }

    public function getName(): string
    {
        return 'Latvijas Florbola Savienība';
    }

    /**
     * Supported floorball leagues configuration
     */
    protected array $groups = [
        'vv' => [
            'name' => 'Elvi Florbola līga (Vīrieši)',
            'image' => self::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
        ],
        'sv' => [
            'name' => 'Elvi Florbola līga (Sievietes)',
            'image' => self::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/sv/kalendars',
        ],
        'LE' => [
            'name' => 'LAT-EST florbola līga',
            'image' => self::DEFAULT_IMAGE_LATEST,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/LE/kalendars',
        ],
        'v1' => [
            'name' => 'Latvijas 1. līga (Vīrieši)',
            'image' => self::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/v1/kalendars',
        ],
        's1' => [
            'name' => 'Latvijas 1. līga (Sievietes)',
            'image' => self::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/s1/kalendars',
        ],
        'v2' => [
            'name' => 'Latvijas 2. līga (Vīrieši)',
            'image' => self::DEFAULT_IMAGE_ELVI,
            'url' => 'https://www.floorball.lv/lv/2026/chempionats/v2/kalendars',
        ],
    ];

    /**
     * Floorball arena metadata mapping with verified OpenStreetMap coordinates and official addresses
     */
    protected array $venues = [
        'Sporta komplekss Cēsis' => [
            'venue' => 'Cēsu Sporta komplekss',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Piebalgas iela 18, Cēsis',
            'lat' => 57.30790,
            'lon' => 25.27820,
        ],
        'Gaujas iela 45, Cēsis' => [
            'venue' => 'Cēsu sporta zāle (Gaujas iela 45)',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Gaujas iela 45, Cēsis',
            'lat' => 57.30896,
            'lon' => 25.24579,
        ],
        'Cēsu 2. pamatskola' => [
            'venue' => 'Cēsu 2. pamatskolas sporta zāle',
            'city' => 'Cēsis',
            'region' => 'Vidzeme',
            'address' => 'Gaujas iela 45, Cēsis',
            'lat' => 57.30896,
            'lon' => 25.24579,
        ],
        'Talsu Valsts ģimnāzija' => [
            'venue' => 'Talsu Valsts ģimnāzijas sporta halle',
            'city' => 'Talsi',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 29, Talsi',
            'lat' => 57.24002,
            'lon' => 22.59116,
        ],
        'Kocēnu sporta nams' => [
            'venue' => 'Kocēnu sporta nams',
            'city' => 'Kocēni',
            'region' => 'Vidzeme',
            'address' => 'Alejas iela 4, Kocēni',
            'lat' => 57.52194,
            'lon' => 25.33477,
        ],
        'LOC manēža' => [
            'venue' => 'Liepājas Olimpiskā centra vieglatlētikas manēža',
            'city' => 'Liepāja',
            'region' => 'Kurzeme',
            'address' => 'Brīvības iela 39, Liepāja',
            'lat' => 56.51906,
            'lon' => 21.01784,
        ],
        'Lielvārdes sporta centrs' => [
            'venue' => 'Lielvārdes sporta centrs',
            'city' => 'Lielvārde',
            'region' => 'Vidzeme',
            'address' => 'Raiņa iela 22, Lielvārde',
            'lat' => 56.71433,
            'lon' => 24.82019,
        ],
        'Ķekavas vsk. sporta komplekss' => [
            'venue' => 'Ķekavas vidusskolas sporta komplekss',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gaismas iela 19, Ķekava',
            'lat' => 56.82403,
            'lon' => 24.23006,
        ],
        'Nākotnes iela 1A' => [
            'venue' => 'Ķekavas sporta komplekss',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Nākotnes iela 1A, Ķekava',
            'lat' => 56.82845,
            'lon' => 24.23812,
        ],
        'Brīvības iela 9, Ķekava' => [
            'venue' => 'Ķekavas sporta centrs',
            'city' => 'Ķekava',
            'region' => 'Rīga un Pierīga',
            'address' => 'Brīvības iela 9, Ķekava',
            'lat' => 56.82845,
            'lon' => 24.23812,
        ],
        'Valmieras olimpiskais centrs' => [
            'venue' => 'Valmieras Olimpiskais centrs',
            'city' => 'Valmiera',
            'region' => 'Vidzeme',
            'address' => 'Čempionu iela 2, Valmiera',
            'lat' => 57.52812,
            'lon' => 25.38789,
        ],
        'Ulbrokas sporta komplekss' => [
            'venue' => 'Ulbrokas sporta komplekss',
            'city' => 'Ulbroka',
            'region' => 'Rīga un Pierīga',
            'address' => 'Institūta iela 1B, Ulbroka',
            'lat' => 56.92862,
            'lon' => 24.32186,
        ],
        'Ādažu sporta centrs' => [
            'venue' => 'Ādažu sporta centrs',
            'city' => 'Ādaži',
            'region' => 'Rīga un Pierīga',
            'address' => 'Gaujas iela 30, Ādaži',
            'lat' => 57.07909,
            'lon' => 24.33935,
        ],
        'Inčukalna Sporta centrs' => [
            'venue' => 'Inčukalna Sporta centrs',
            'city' => 'Inčukalns',
            'region' => 'Vidzeme',
            'address' => 'Atmodas iela 2, Inčukalns',
            'lat' => 57.09841,
            'lon' => 24.68652,
        ],
        'Ogres Kalna Pamatskola' => [
            'venue' => 'Ogres Kalna pamatskolas sporta halle',
            'city' => 'Ogre',
            'region' => 'Vidzeme',
            'address' => 'Meža prospekts 14, Ogre',
            'lat' => 56.82054,
            'lon' => 24.62145,
        ],
        'Kuldīgas sporta halle' => [
            'venue' => 'Kuldīgas sporta halle',
            'city' => 'Kuldīga',
            'region' => 'Kurzeme',
            'address' => 'Piltenes iela 25, Kuldīga',
            'lat' => 56.97412,
            'lon' => 21.96821,
        ],
        'Grobiņas sporta centrs' => [
            'venue' => 'Grobiņas Sporta centrs',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'M. Namiķa iela 2b, Grobiņa',
            'lat' => 56.53610,
            'lon' => 21.16480,
        ],
        'Grobiņas SC' => [
            'venue' => 'Grobiņas Sporta centrs',
            'city' => 'Grobiņa',
            'region' => 'Kurzeme',
            'address' => 'M. Namiķa iela 2b, Grobiņa',
            'lat' => 56.53610,
            'lon' => 21.16480,
        ],
        'Baložu vidusskola' => [
            'venue' => 'Baložu vidusskolas sporta halle',
            'city' => 'Baloži',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 6, Baloži',
            'lat' => 56.87622,
            'lon' => 24.11945,
        ],
        'Zemgales vidusskola' => [
            'venue' => 'Zemgales vidusskolas sporta halle',
            'city' => 'Slampe',
            'region' => 'Zemgale',
            'address' => 'Zemgales vidusskola, Slampes pagasts',
            'lat' => 56.84912,
            'lon' => 23.30054,
        ],
        'Irlavas sporta nams' => [
            'venue' => 'Irlavas sporta nams "Svēte"',
            'city' => 'Irlava',
            'region' => 'Kurzeme',
            'address' => '"Svēte", Irlavas pagasts',
            'lat' => 56.86720,
            'lon' => 23.00412,
        ],
        'Ozolnieku vidusskola' => [
            'venue' => 'Ozolnieku vidusskolas sporta halle',
            'city' => 'Ozolnieki',
            'region' => 'Zemgale',
            'address' => 'Stadiona iela 5, Ozolnieki',
            'lat' => 56.69123,
            'lon' => 23.78456,
        ],
        'Stalbes sporta zāle' => [
            'venue' => 'Stalbes sporta zāle',
            'city' => 'Stalbe',
            'region' => 'Vidzeme',
            'address' => 'Skolas iela 1, Stalbe',
            'lat' => 57.37120,
            'lon' => 25.03210,
        ],
        'Rencēnu pamatskola' => [
            'venue' => 'Rencēnu pamatskolas sporta zāle',
            'city' => 'Rencēni',
            'region' => 'Vidzeme',
            'address' => 'Rīgas iela 6, Rencēni',
            'lat' => 57.73145,
            'lon' => 25.42154,
        ],
        'Limbažu Olimpiskais centrs' => [
            'venue' => 'Limbažu Olimpiskais centrs',
            'city' => 'Limbaži',
            'region' => 'Vidzeme',
            'address' => 'Sporta iela 3, Limbaži',
            'lat' => 57.51421,
            'lon' => 24.71890,
        ],
        'Līgatnes Sporta centrs' => [
            'venue' => 'Līgatnes Sporta centrs',
            'city' => 'Līgatne',
            'region' => 'Vidzeme',
            'address' => 'Spriņģu iela 4, Līgatne',
            'lat' => 57.23412,
            'lon' => 25.04120,
        ],
        'Rēzeknes Olimpiskais Centrs' => [
            'venue' => 'Rēzeknes Olimpiskais centrs',
            'city' => 'Rēzekne',
            'region' => 'Latgale',
            'address' => 'Stacijas iela 30b, Rēzekne',
            'lat' => 56.51654,
            'lon' => 27.34212,
        ],
        'Poļu ģimnāzija, Marijas 1D' => [
            'venue' => 'Daugavpils poļu ģimnāzijas sporta halle',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Marijas iela 1D, Daugavpils',
            'lat' => 55.87520,
            'lon' => 26.54120,
        ],
        'Sporta nams Mēmele' => [
            'venue' => 'Sporta nams "Mēmele"',
            'city' => 'Bauska',
            'region' => 'Zemgale',
            'address' => 'Rīgas iela 41, Bauska',
            'lat' => 56.41120,
            'lon' => 24.18450,
        ],
        'Salaspils sporta nams' => [
            'venue' => 'Salaspils sporta nams',
            'city' => 'Salaspils',
            'region' => 'Rīga un Pierīga',
            'address' => 'Smilšu iela 1, Salaspils',
            'lat' => 56.85871,
            'lon' => 24.35412,
        ],
        'Būtnāru sporta halle' => [
            'venue' => 'Būtnāru sporta halle',
            'city' => 'Saldus novads',
            'region' => 'Kurzeme',
            'address' => 'Būtnāri, Rubas pagasts',
            'lat' => 56.44412,
            'lon' => 22.58120,
        ],
        'Rimi Olimpiskais centrs' => [
            'venue' => 'Rimi Olimpiskais centrs',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Grostonas iela 6B, Rīga',
            'lat' => 56.96912,
            'lon' => 24.12945,
        ],
        'Bulduru tehnikums' => [
            'venue' => 'Bulduru tehnikuma sporta zāle',
            'city' => 'Jūrmala',
            'region' => 'Rīga un Pierīga',
            'address' => 'Viestura iela 6, Jūrmala',
            'lat' => 56.98541,
            'lon' => 23.85412,
        ],
        'Sporta halle Pārventa' => [
            'venue' => 'Sporta halle "Pārventa"',
            'city' => 'Ventspils',
            'region' => 'Kurzeme',
            'address' => 'Tārgales iela 5b, Ventspils',
            'lat' => 57.40812,
            'lon' => 21.58412,
        ],
        'Rīgas Teikas vidusskola' => [
            'venue' => 'Rīgas Teikas vidusskolas sporta halle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Aizkraukles iela 14, Rīga',
            'lat' => 56.97412,
            'lon' => 24.19512,
        ],
        'Valkas JC ģimnāzija' => [
            'venue' => 'Valkas Jāņa Cimzes ģimnāzijas sporta halle',
            'city' => 'Valka',
            'region' => 'Vidzeme',
            'address' => 'Raiņa iela 28A, Valka',
            'lat' => 57.77612,
            'lon' => 26.01842,
        ],
        'Valga' => [
            'venue' => 'Valkas / Valgas sporta halle',
            'city' => 'Valka',
            'region' => 'Vidzeme',
            'address' => 'Raiņa iela 28A, Valka',
            'lat' => 57.77612,
            'lon' => 26.01842,
        ],
        'Ziemeļvalstu ģimnāzija' => [
            'venue' => 'Ziemeļvalstu ģimnāzijas sporta zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Paula Lejiņa iela 12, Rīga',
            'lat' => 56.93812,
            'lon' => 24.01542,
        ],
        'Kokneses sporta centrs' => [
            'venue' => 'Kokneses sporta centrs',
            'city' => 'Koknese',
            'region' => 'Vidzeme',
            'address' => 'Parka iela 27, Koknese',
            'lat' => 56.64712,
            'lon' => 25.43120,
        ],
        'Madonas sporta centrs' => [
            'venue' => 'Madonas pilsētas sporta centrs',
            'city' => 'Madona',
            'region' => 'Vidzeme',
            'address' => 'Gaujas iela 13, Madona',
            'lat' => 56.85210,
            'lon' => 26.22150,
        ],
        'Tērvetes sporta centrs' => [
            'venue' => 'Tērvetes sporta halle',
            'city' => 'Zelmeņi',
            'region' => 'Zemgale',
            'address' => 'Zelmeņi, Tērvetes pagasts',
            'lat' => 56.46712,
            'lon' => 23.34120,
        ],
        'Kalsnavas pamatskola' => [
            'venue' => 'Kalsnavas pamatskolas sporta zāle',
            'city' => 'Jaunkalsnava',
            'region' => 'Vidzeme',
            'address' => 'Pārupes iela 6, Jaunkalsnava',
            'lat' => 56.70812,
            'lon' => 25.96120,
        ],
        'Aizkraukles sporta centrs' => [
            'venue' => 'Aizkraukles sporta centrs',
            'city' => 'Aizkraukle',
            'region' => 'Vidzeme',
            'address' => 'Lāčplēša iela 21, Aizkraukle',
            'lat' => 56.60412,
            'lon' => 25.25120,
        ],
        'Spuņciems' => [
            'venue' => 'Salas pagasta sporta halle',
            'city' => 'Spuņciems',
            'region' => 'Rīga un Pierīga',
            'address' => 'Skolas iela 1, Spuņciems, Salas pagasts, Mārupes novads',
            'lat' => 56.93818,
            'lon' => 23.70735,
        ],
        'Juglas vsk.' => [
            'venue' => 'Rīgas Juglas vidusskolas sporta halle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => 'Kvēles iela 64, Rīga',
            'lat' => 56.98512,
            'lon' => 24.24120,
        ],
        'Vārmes sporta halle' => [
            'venue' => 'Vārmes sporta halle',
            'city' => 'Vārme',
            'region' => 'Kurzeme',
            'address' => 'Vārme, Kuldīgas novads',
            'lat' => 56.86120,
            'lon' => 22.18120,
        ],
        'Märjamaa Valla Spordikeskus' => [
            'venue' => 'Märjamaa Valla Spordikeskus',
            'city' => 'Märjamaa',
            'region' => 'Igaunija',
            'address' => 'Pärnu mnt 67, Märjamaa, Igaunija',
            'lat' => 58.90386,
            'lon' => 24.42711,
        ],
        'Ääsmäe Spordihoone' => [
            'venue' => 'Ääsmäe Spordihoone',
            'city' => 'Ääsmäe',
            'region' => 'Igaunija',
            'address' => 'Kasesalu tee 16, Ääsmäe, Igaunija',
            'lat' => 59.24494,
            'lon' => 24.50672,
        ],
        'Õismäe Sportmängude hall' => [
            'venue' => 'Õismäe Sportmängude hall',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => 'Ehitajate tee 141, Tallinn, Igaunija',
            'lat' => 59.41304,
            'lon' => 24.66072,
        ],
        'Õismäe Sportmängude Hall' => [
            'venue' => 'Õismäe Sportmängude hall',
            'city' => 'Tallina',
            'region' => 'Igaunija',
            'address' => 'Ehitajate tee 141, Tallinn, Igaunija',
            'lat' => 59.41304,
            'lon' => 24.66072,
        ],
        'Jõgeva spordihoone' => [
            'venue' => 'Jõgeva spordihoone Virtus',
            'city' => 'Jõgeva',
            'region' => 'Igaunija',
            'address' => 'Aia 40, Jõgeva, Igaunija',
            'lat' => 58.74810,
            'lon' => 26.38874,
        ],
        'Jõgeva SH' => [
            'venue' => 'Jõgeva spordihoone Virtus',
            'city' => 'Jõgeva',
            'region' => 'Igaunija',
            'address' => 'Aia 40, Jõgeva, Igaunija',
            'lat' => 58.74810,
            'lon' => 26.38874,
        ],
        'Virtuse SH' => [
            'venue' => 'Jõgeva spordihoone Virtus',
            'city' => 'Jõgeva',
            'region' => 'Igaunija',
            'address' => 'Aia 40, Jõgeva, Igaunija',
            'lat' => 58.74810,
            'lon' => 26.38874,
        ],
        'Eesti Maaülikooli spordihoone' => [
            'venue' => 'Eesti Maaülikooli spordihoone',
            'city' => 'Tartu',
            'region' => 'Igaunija',
            'address' => 'Kreutzwaldi 3, Tartu, Igaunija',
            'lat' => 58.38812,
            'lon' => 26.69912,
        ],
    ];

    /**
     * Team fallback city mapping (if venue is unspecified)
     */
    protected array $teamCities = [
        'FBK SĀC' => ['city' => 'Ādaži', 'region' => 'Rīga un Pierīga'],
        'FBK Valmiera' => ['city' => 'Valmiera', 'region' => 'Vidzeme'],
        'FK Kurši' => ['city' => 'Liepāja', 'region' => 'Kurzeme'],
        'Ķekava/RB&B' => ['city' => 'Ķekava', 'region' => 'Rīga un Pierīga'],
        'FK Ķekava' => ['city' => 'Ķekava', 'region' => 'Rīga un Pierīga'],
        'Lekrings' => ['city' => 'Cēsis', 'region' => 'Vidzeme'],
        'Lekrings/CPSS' => ['city' => 'Cēsis', 'region' => 'Vidzeme'],
        'Lielvārde/Unihoc' => ['city' => 'Lielvārde', 'region' => 'Vidzeme'],
        'Rubene' => ['city' => 'Kocēni', 'region' => 'Vidzeme'],
        'FK Talsi/TNSS/Krauzers' => ['city' => 'Talsi', 'region' => 'Kurzeme'],
        'Masters Ulbroka/LU' => ['city' => 'Ulbroka', 'region' => 'Rīga un Pierīga'],
        'NND/RJTC' => ['city' => 'Rīga', 'region' => 'Rīga un Pierīga'],
        'Tartu Linnameeskond' => ['city' => 'Tartu', 'region' => 'Igaunija'],
        'Sparta team Automaailm' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'Sparta' => ['city' => 'Tallina', 'region' => 'Igaunija'],
        'Jogeva SK Tähe/Olivia' => ['city' => 'Jõgeva', 'region' => 'Igaunija'],
        'Jõgeva SK Tähe/Olivia' => ['city' => 'Jõgeva', 'region' => 'Igaunija'],
        'Eesti Maaülikooli SK' => ['city' => 'Tartu', 'region' => 'Igaunija'],
    ];

    public function scrape(Source $source): Collection
    {
        $jar = new CookieJar();
        $client = new Client([
            'cookies' => $jar,
            'version' => 1.1, // Mandatory: floorball.lv nginx HTTP/2 bug causes REFUSED_STREAM on POST
            'timeout' => 20,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'Referer' => 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars',
            ],
            'verify' => false,
        ]);

        // 1. Visit calendar page to establish PHP session (PHPSESSID) and discover season info
        $pageUrl = $source->url ?: 'https://www.floorball.lv/lv/2026/chempionats/vv/kalendars';
        $seasonStartYear = (int) date('Y');
        $seasonId = 35; // Default season ID for 2026/2027

        try {
            $response = $client->get($pageUrl);
            $html = (string) $response->getBody();

            if (preg_match('/\/(\d{4})\/chempionats/', $pageUrl, $ym)) {
                $seasonStartYear = (int) $ym[1];
            } elseif (preg_match('/Season\s+(\d{4})\/(\d{4})/', $html, $sm)) {
                $seasonStartYear = (int) $sm[1];
            }

            if (preg_match('/"filtrs_sezona",\s*"value":\s*"(\d+)"/', $html, $szm)) {
                $seasonId = (int) $szm[1];
            }
        } catch (\Throwable $e) {
            Log::error("FloorballLvScraper: error establishing session on {$pageUrl}: " . $e->getMessage());
        }

        // 2. Determine groups to scrape: by default scrape all 3 (vv, sv, LE)
        $groupsToScrape = $this->groups;
        if (!empty($source->url) && preg_match('/[?&]group=([a-zA-Z0-9_-]+)/', $source->url, $gm)) {
            $specifiedGroup = $gm[1];
            foreach ($this->groups as $grpKey => $meta) {
                if (strcasecmp($grpKey, $specifiedGroup) === 0) {
                    $groupsToScrape = [$grpKey => $meta];
                    break;
                }
            }
        }

        $allEvents = collect();

        foreach ($groupsToScrape as $grp => $meta) {
            $groupEvents = $this->scrapeGroup($grp, $meta, $client, $seasonStartYear, $seasonId);
            $allEvents = $allEvents->merge($groupEvents);
        }

        return $allEvents;
    }

    /**
     * Scrape a specific group (vv, sv, LE) via DataTables AJAX POST
     */
    public function scrapeGroup(string $grp, array $meta, Client $client, int $seasonStartYear, int $seasonId): Collection
    {
        $events = collect();
        $ajaxUrl = 'https://www.floorball.lv/ajax/ajax_chempionats_kalendars.php';

        try {
            $response = $client->post($ajaxUrl, [
                'headers' => [
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => $meta['url'],
                ],
                'form_params' => [
                    'sEcho' => 1,
                    'iColumns' => 7,
                    'iDisplayStart' => 0,
                    'iDisplayLength' => 300, // Fetch all matches for the league
                    'url' => 'https://www.floorball.lv/lv',
                    'menu' => 'chempionats',
                    'filtrs_grupa' => $grp,
                    'filtrs_sezona' => $seasonId,
                    'filtrs_spelu_veids' => 0,
                    'filtrs_menesis' => 'all', // Full season
                    'filtrs_komanda' => 0,
                    'filtrs_majas_viesi' => 0,
                ],
            ]);

            $json = json_decode((string) $response->getBody(), true);
            $rows = $json['aaData'] ?? [];

            foreach ($rows as $row) {
                $dto = $this->parseRow($row, $grp, $meta, $seasonStartYear);
                if ($dto) {
                    $events->push($dto);
                }
            }
        } catch (\Throwable $e) {
            Log::error("FloorballLvScraper error fetching group {$grp}: " . $e->getMessage());
        }

        return $events;
    }

    /**
     * Parse a single match row from aaData
     * Format: [0: "DD.MM", 1: "HH:MM", 2: homeHtml, 3: scoreHtml, 4: awayHtml, 5: venue, 6: referees]
     */
    public function parseRow(array $row, string $grp, array $meta, int $seasonStartYear): ?ScrapedEventDTO
    {
        $dateText = trim(strip_tags($row[0] ?? ''));
        $timeText = trim(strip_tags($row[1] ?? ''));

        if (!preg_match('/(\d{1,2})\.(\d{1,2})/', $dateText, $dm)) {
            return null;
        }

        $day = (int) $dm[1];
        $mon = (int) $dm[2];
        $year = ($mon >= 7) ? $seasonStartYear : ($seasonStartYear + 1);

        $time = preg_match('/^\d{1,2}:\d{2}$/', $timeText) ? $timeText : '00:00';

        try {
            $startAt = Carbon::createFromFormat('Y-m-d H:i', sprintf('%04d-%02d-%02d %s', $year, $mon, $day, $time), 'Europe/Riga');
        } catch (\Throwable $e) {
            return null;
        }

        // Rule: do not ingest past events
        if ($startAt->lt(now()->startOfDay())) {
            return null;
        }

        // Floorball match duration ≈ 105 minutes (3 periods + breaks)
        $endAt = (clone $startAt)->addMinutes(105);

        // Extract home team name and ID
        $homeRaw = $row[2] ?? '';
        if (preg_match('/title="([^"]+)"/', $homeRaw, $hm)) {
            $home = html_entity_decode(trim($hm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $home = html_entity_decode(trim(strip_tags($homeRaw)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Extract away team name and ID
        $awayRaw = $row[4] ?? '';
        if (preg_match('/title="([^"]+)"/', $awayRaw, $aw)) {
            $away = html_entity_decode(trim($aw[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $away = html_entity_decode(trim(strip_tags($awayRaw)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $home = trim(str_replace('&nbsp;', ' ', $home));
        $away = trim(str_replace('&nbsp;', ' ', $away));

        if (empty($home) || empty($away)) {
            return null;
        }

        $homeId = '';
        if (preg_match('/\/komanda\/(\d+)/', $homeRaw, $hmId)) {
            $homeId = $hmId[1];
        }

        $awayId = '';
        if (preg_match('/\/komanda\/(\d+)/', $awayRaw, $awId)) {
            $awayId = $awId[1];
        }

        $venueRaw = trim(strip_tags($row[5] ?? ''));
        $location = $this->resolveLocation($venueRaw, $home);

        $refereesRaw = trim(strip_tags($row[6] ?? ''));
        $referees = !empty($refereesRaw) ? html_entity_decode($refereesRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

        $gameUrl = $meta['url'];
        if (preg_match('/href="([^"]+)"/', $row[3] ?? '', $protoMatch)) {
            $proto = $protoMatch[1];
            $gameUrl = str_starts_with($proto, 'http') ? $proto : ('https://www.floorball.lv' . $proto);
        }

        // Deterministic external ID
        $idParts = [$grp, $startAt->format('YmdHi')];
        if ($homeId && $awayId) {
            $idParts[] = $homeId;
            $idParts[] = $awayId;
        } else {
            $idParts[] = substr(md5("{$home}_{$away}"), 0, 8);
        }
        $sourceExternalId = implode('_', $idParts);

        $leagueName = $meta['name'];
        $title = "{$home} vs {$away}";
        $shortDescription = "{$leagueName}: {$home} pret {$away} ({$location['venue']}, {$location['city']}).";

        $monthNames = [
            1 => 'janvārī', 2 => 'februārī', 3 => 'martā', 4 => 'aprīlī', 5 => 'maijā', 6 => 'jūnijā',
            7 => 'jūlijā', 8 => 'augustā', 9 => 'septembrī', 10 => 'oktobrī', 11 => 'novembrī', 12 => 'decembrī'
        ];
        $dateFormatted = "{$startAt->day}. " . ($monthNames[$startAt->month] ?? '') . " plkst. {$time}";

        $descLines = [
            "🏑 {$leagueName} čempionāta spēle: {$home} pret {$away}.",
            '',
            "Latvijas Florbola Savienības (LFS) rīkotā oficiālā čempionāta spēle, kurā laukumā tiekas {$home} pret {$away}.",
            '',
            "📍 Norises vieta: {$location['venue']}",
        ];
        if (!empty($location['address'])) {
            $descLines[] = "🏢 Adrese: {$location['address']}";
        } else {
            $descLines[] = "🏢 Pilsēta: {$location['city']}";
        }
        $descLines[] = "📅 Sākums: {$dateFormatted}";
        if (!empty($referees)) {
            $descLines[] = "👥 Spēles tiesneši: {$referees}";
        }
        $descLines[] = '';
        $descLines[] = '🎟️ Ieeja un biļetes:';
        $descLines[] = "Ieejas noteikumi un biļešu iegāde saskaņā ar mājinieku komandas ({$home}) un sporta bāzes noteikumiem. Līdzjutēji aicināti atbalstīt komandu klātienē!";
        $descLines[] = '';
        $descLines[] = '🔗 Oficiālā informācija, rezultāti un turnīra tabula: floorball.lv';

        $description = implode("\n", $descLines);

        return new ScrapedEventDTO(
            title: $title,
            startAt: $startAt,
            endAt: $endAt,
            description: $description,
            shortDescription: $shortDescription,
            venueName: $location['venue'],
            city: $location['city'],
            region: $location['region'],
            address: $location['address'],
            latitude: $location['lat'],
            longitude: $location['lon'],
            placeType: 'sports_facility',
            categoryNames: ['Sports'],
            entertainmentType: 'sports',
            isFree: false,
            priceMin: null,
            priceMax: null,
            currency: 'EUR',
            ticketUrl: null,
            imageUrl: $meta['image'],
            sourceUrl: $gameUrl,
            sourceExternalId: $sourceExternalId,
            locale: 'lv',
            rawData: [
                'group' => $grp,
                'league' => $leagueName,
                'home_team' => $home,
                'away_team' => $away,
                'home_id' => $homeId,
                'away_id' => $awayId,
                'venue_raw' => $venueRaw,
                'referees' => $referees,
                'date_raw' => $dateText,
                'time_raw' => $timeText,
            ]
        );
    }

    public function resolveLocation(string $venueRaw, string $homeTeam): array
    {
        $trimmed = trim(rtrim(trim($venueRaw), ','));

        // 1. Direct or fuzzy substring match in known floorball venues
        foreach ($this->venues as $name => $data) {
            if (!empty($trimmed) && (
                stripos($trimmed, $name) !== false ||
                stripos($name, $trimmed) !== false
            )) {
                return $data;
            }
        }

        // 2. Fallback to team home city if venue is unknown
        if (isset($this->teamCities[$homeTeam])) {
            $teamInfo = $this->teamCities[$homeTeam];
            return [
                'venue' => !empty($trimmed) ? $trimmed : "{$homeTeam} mājas zāle",
                'city' => $teamInfo['city'],
                'region' => $teamInfo['region'],
                'address' => null,
                'lat' => null,
                'lon' => null,
            ];
        }

        return [
            'venue' => !empty($trimmed) ? $trimmed : 'Florbola zāle',
            'city' => 'Rīga',
            'region' => 'Rīga un Pierīga',
            'address' => null,
            'lat' => null,
            'lon' => null,
        ];
    }
}
