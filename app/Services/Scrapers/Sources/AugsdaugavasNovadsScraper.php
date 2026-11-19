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

class AugsdaugavasNovadsScraper extends BaseScraper
{
    public const BASE_URL = 'https://www.augsdaugavasnovads.lv';
    public const CALENDAR_PATH = '/lv/notikumu-kalendars';

    /**
     * Map of known Augšdaugavas novads venues with normalized metadata
     */
    protected array $venueAddressMap = [
        // Ilūkste
        'ilūkstes kultūras un mākslas centr' => [
            'name' => 'Ilūkstes Kultūras un mākslas centrs',
            'city' => 'Ilūkste',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 12, Ilūkste, Augšdaugavas novads, LV-5447',
            'place_type' => 'cultural_center',
        ],
        'ilūkstes kmc' => [
            'name' => 'Ilūkstes Kultūras un mākslas centrs',
            'city' => 'Ilūkste',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 12, Ilūkste, Augšdaugavas novads, LV-5447',
            'place_type' => 'cultural_center',
        ],
        'ilūkstes kultūras nam' => [
            'name' => 'Ilūkstes Kultūras un mākslas centrs',
            'city' => 'Ilūkste',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 12, Ilūkste, Augšdaugavas novads, LV-5447',
            'place_type' => 'cultural_center',
        ],
        'ilūkstes bērnu un jauniešu centr' => [
            'name' => 'Ilūkstes Bērnu un jauniešu centrs',
            'city' => 'Ilūkste',
            'region' => 'Latgale',
            'address' => 'Sporta iela 2, Ilūkste, Augšdaugavas novads, LV-5447',
            'place_type' => 'educational',
        ],
        'ilūkstes bjc' => [
            'name' => 'Ilūkstes Bērnu un jauniešu centrs',
            'city' => 'Ilūkste',
            'region' => 'Latgale',
            'address' => 'Sporta iela 2, Ilūkste, Augšdaugavas novads, LV-5447',
            'place_type' => 'educational',
        ],
        'ilūkstes estrād' => [
            'name' => 'Ilūkstes brīvdabas estrāde',
            'city' => 'Ilūkste',
            'region' => 'Latgale',
            'address' => 'Kastaņu iela, Ilūkste, Augšdaugavas novads, LV-5447',
            'place_type' => 'park',
        ],
        'ilūkstes bibliotēk' => [
            'name' => 'Ilūkstes pilsētas centrālā bibliotēka',
            'city' => 'Ilūkste',
            'region' => 'Latgale',
            'address' => 'Brīvības iela 12, Ilūkste, Augšdaugavas novads, LV-5447',
            'place_type' => 'library',
        ],

        // Subate
        'subates kultūras nam' => [
            'name' => 'Subates Kultūras nams',
            'city' => 'Subate',
            'region' => 'Latgale',
            'address' => 'Jelgavas iela 7, Subate, Augšdaugavas novads, LV-5471',
            'place_type' => 'cultural_center',
        ],
        'subates bibliotēk' => [
            'name' => 'Subates bibliotēka',
            'city' => 'Subate',
            'region' => 'Latgale',
            'address' => 'Tirgus laukums 1, Subate, Augšdaugavas novads, LV-5471',
            'place_type' => 'library',
        ],

        // Naujene / Krauja / Slutišķi
        'naujenes kultūras centr' => [
            'name' => 'Naujenes Kultūras centrs',
            'city' => 'Krauja',
            'region' => 'Latgale',
            'address' => 'Daugavas iela 31A, Krauja, Naujenes pagasts, Augšdaugavas novads, LV-5458',
            'place_type' => 'cultural_center',
        ],
        'naujenes kc' => [
            'name' => 'Naujenes Kultūras centrs',
            'city' => 'Krauja',
            'region' => 'Latgale',
            'address' => 'Daugavas iela 31A, Krauja, Naujenes pagasts, Augšdaugavas novads, LV-5458',
            'place_type' => 'cultural_center',
        ],
        'naujenes novadpētniecības muzej' => [
            'name' => 'Naujenes novadpētniecības muzejs',
            'city' => 'Naujene',
            'region' => 'Latgale',
            'address' => 'Skolas iela 1b, Naujene, Naujenes pagasts, Augšdaugavas novads, LV-5462',
            'place_type' => 'museum',
        ],
        'naujenes muzej' => [
            'name' => 'Naujenes novadpētniecības muzejs',
            'city' => 'Naujene',
            'region' => 'Latgale',
            'address' => 'Skolas iela 1b, Naujene, Naujenes pagasts, Augšdaugavas novads, LV-5462',
            'place_type' => 'museum',
        ],
        'slutišķ' => [
            'name' => 'Slutišķu vecticībnieku sēta un Naujenes muzeja brīvdabas nodaļa',
            'city' => 'Slutišķi',
            'region' => 'Latgale',
            'address' => 'Slutišķi, Naujenes pagasts, Augšdaugavas novads, LV-5462',
            'place_type' => 'museum',
        ],
        'naujenes tautas bibliotēk' => [
            'name' => 'Naujenes tautas bibliotēka',
            'city' => 'Krauja',
            'region' => 'Latgale',
            'address' => 'Daugavas iela 34, Krauja, Naujenes pagasts, Augšdaugavas novads, LV-5458',
            'place_type' => 'library',
        ],

        // Višķi / Špoģi
        'višķu sabiedrisk' => [
            'name' => 'Višķu sabiedriskais centrs',
            'city' => 'Špoģi',
            'region' => 'Latgale',
            'address' => 'Skolas iela 17, Špoģi, Višķu pagasts, Augšdaugavas novads, LV-5481',
            'place_type' => 'cultural_center',
        ],
        'višķu estrād' => [
            'name' => 'Višķu brīvdabas estrāde',
            'city' => 'Višķu tehnikums',
            'region' => 'Latgale',
            'address' => 'Višķu tehnikums, Višķu pagasts, Augšdaugavas novads, LV-5481',
            'place_type' => 'park',
        ],
        'višķu stadion' => [
            'name' => 'Višķu stadions',
            'city' => 'Višķu tehnikums',
            'region' => 'Latgale',
            'address' => 'Višķu tehnikums, Višķu pagasts, Augšdaugavas novads, LV-5481',
            'place_type' => 'sports_centre',
        ],
        'špoģu mūzikas un mākslas skol' => [
            'name' => 'Špoģu Mūzikas un mākslas skola',
            'city' => 'Špoģi',
            'region' => 'Latgale',
            'address' => 'Skolas iela 17, Špoģi, Višķu pagasts, Augšdaugavas novads, LV-5481',
            'place_type' => 'educational',
        ],

        // Bebrene
        'bebrenes kultūras nam' => [
            'name' => 'Bebrenes Kultūras nams',
            'city' => 'Bebrene',
            'region' => 'Latgale',
            'address' => 'Bebrene, Bebrenes pagasts, Augšdaugavas novads, LV-5439',
            'place_type' => 'cultural_center',
        ],
        'bebrenes muiž' => [
            'name' => 'Bebrenes muižas komplekss',
            'city' => 'Bebrene',
            'region' => 'Latgale',
            'address' => 'Bebrene, Bebrenes pagasts, Augšdaugavas novads, LV-5439',
            'place_type' => 'historical',
        ],
        'bebrenes pil' => [
            'name' => 'Bebrenes muižas komplekss',
            'city' => 'Bebrene',
            'region' => 'Latgale',
            'address' => 'Bebrene, Bebrenes pagasts, Augšdaugavas novads, LV-5439',
            'place_type' => 'historical',
        ],

        // Berķenele / Kalkūni
        'raiņa māj' => [
            'name' => 'Kultūras centrs “Raiņa māja Berķenelē”',
            'city' => 'Birkineļi',
            'region' => 'Latgale',
            'address' => 'Birkineļi, Kalkūnes pagasts, Augšdaugavas novads, LV-5449',
            'place_type' => 'museum',
        ],
        'berķenel' => [
            'name' => 'Kultūras centrs “Raiņa māja Berķenelē”',
            'city' => 'Birkineļi',
            'region' => 'Latgale',
            'address' => 'Birkineļi, Kalkūnes pagasts, Augšdaugavas novads, LV-5449',
            'place_type' => 'museum',
        ],
        'kalkūnes kultūras nam' => [
            'name' => 'Kalkūnes Kultūras nams',
            'city' => 'Kalkūni',
            'region' => 'Latgale',
            'address' => 'Kalkūni, Kalkūnes pagasts, Augšdaugavas novads, LV-5449',
            'place_type' => 'cultural_center',
        ],

        // Vabole / Skrindu muzejs
        'skrindu dzimtas muzej' => [
            'name' => 'Skrindu dzimtas muzejs',
            'city' => 'Vabole',
            'region' => 'Latgale',
            'address' => 'Vabole, Vaboles pagasts, Augšdaugavas novads, LV-5477',
            'place_type' => 'museum',
        ],
        'skrindas muzej' => [
            'name' => 'Skrindu dzimtas muzejs',
            'city' => 'Vabole',
            'region' => 'Latgale',
            'address' => 'Vabole, Vaboles pagasts, Augšdaugavas novads, LV-5477',
            'place_type' => 'museum',
        ],
        'vaboles kultūras nam' => [
            'name' => 'Vaboles Kultūras nams',
            'city' => 'Vabole',
            'region' => 'Latgale',
            'address' => 'Vabole, Vaboles pagasts, Augšdaugavas novads, LV-5477',
            'place_type' => 'cultural_center',
        ],
        'vaboles park' => [
            'name' => 'Vaboles muižas parks',
            'city' => 'Vabole',
            'region' => 'Latgale',
            'address' => 'Vabole, Vaboles pagasts, Augšdaugavas novads, LV-5477',
            'place_type' => 'park',
        ],
        'vaboles muiž' => [
            'name' => 'Vaboles muižas parks',
            'city' => 'Vabole',
            'region' => 'Latgale',
            'address' => 'Vabole, Vaboles pagasts, Augšdaugavas novads, LV-5477',
            'place_type' => 'park',
        ],

        // Līksna
        'līksnas kultūras nam' => [
            'name' => 'Līksnas Kultūras nams',
            'city' => 'Līksna',
            'region' => 'Latgale',
            'address' => 'Daugavas iela 8, Līksna, Līksnas pagasts, Augšdaugavas novads, LV-5456',
            'place_type' => 'cultural_center',
        ],
        'līksnas muiž' => [
            'name' => 'Līksnas muižas parks',
            'city' => 'Līksna',
            'region' => 'Latgale',
            'address' => 'Līksna, Līksnas pagasts, Augšdaugavas novads, LV-5456',
            'place_type' => 'park',
        ],
        'līksnas park' => [
            'name' => 'Līksnas muižas parks',
            'city' => 'Līksna',
            'region' => 'Latgale',
            'address' => 'Līksna, Līksnas pagasts, Augšdaugavas novads, LV-5456',
            'place_type' => 'park',
        ],

        // Svente
        'sventes tautas nam' => [
            'name' => 'Sventes Tautas nams',
            'city' => 'Svente',
            'region' => 'Latgale',
            'address' => 'Alejas iela 7, Svente, Sventes pagasts, Augšdaugavas novads, LV-5473',
            'place_type' => 'cultural_center',
        ],
        'sventes kultūras nam' => [
            'name' => 'Sventes Tautas nams',
            'city' => 'Svente',
            'region' => 'Latgale',
            'address' => 'Alejas iela 7, Svente, Sventes pagasts, Augšdaugavas novads, LV-5473',
            'place_type' => 'cultural_center',
        ],
        'sventes muiž' => [
            'name' => 'Sventes muiža un militārās tehnikas muzejs',
            'city' => 'Svente',
            'region' => 'Latgale',
            'address' => 'Alejas iela 7, Svente, Sventes pagasts, Augšdaugavas novads, LV-5473',
            'place_type' => 'museum',
        ],

        // Medumi
        'medumu tautas nam' => [
            'name' => 'Medumu Tautas nams',
            'city' => 'Medumi',
            'region' => 'Latgale',
            'address' => 'Alejas iela 2, Medumi, Medumu pagasts, Augšdaugavas novads, LV-5460',
            'place_type' => 'cultural_center',
        ],
        'medumu kultūras nam' => [
            'name' => 'Medumu Tautas nams',
            'city' => 'Medumi',
            'region' => 'Latgale',
            'address' => 'Alejas iela 2, Medumi, Medumu pagasts, Augšdaugavas novads, LV-5460',
            'place_type' => 'cultural_center',
        ],
        'medumu park' => [
            'name' => 'Medumu muižas parks',
            'city' => 'Medumi',
            'region' => 'Latgale',
            'address' => 'Medumi, Medumu pagasts, Augšdaugavas novads, LV-5460',
            'place_type' => 'park',
        ],

        // Kalupe
        'kalupes kultūras nam' => [
            'name' => 'Kalupes Kultūras nams',
            'city' => 'Kalupe',
            'region' => 'Latgale',
            'address' => 'Ezeru iela 4, Kalupe, Kalupes pagasts, Augšdaugavas novads, LV-5455',
            'place_type' => 'cultural_center',
        ],

        // Dubna
        'dubnas kultūras nam' => [
            'name' => 'Dubnas Kultūras nams',
            'city' => 'Dubna',
            'region' => 'Latgale',
            'address' => 'Nākotnes iela 1, Dubna, Dubnas pagasts, Augšdaugavas novads, LV-5448',
            'place_type' => 'cultural_center',
        ],

        // Ambeļi
        'ambeļu kultūras nam' => [
            'name' => 'Ambeļu Kultūras nams',
            'city' => 'Ambeļi',
            'region' => 'Latgale',
            'address' => 'Ambeļi, Ambeļu pagasts, Augšdaugavas novads, LV-5441',
            'place_type' => 'cultural_center',
        ],

        // Dviete
        'dvietes kultūras nam' => [
            'name' => 'Dvietes Kultūras nams',
            'city' => 'Dviete',
            'region' => 'Latgale',
            'address' => 'Dviete, Dvietes pagasts, Augšdaugavas novads, LV-5442',
            'place_type' => 'cultural_center',
        ],
        'dvietes palien' => [
            'name' => 'Dvietes senlejas dabas parka informācijas centrs “Gulbji”',
            'city' => 'Dviete',
            'region' => 'Latgale',
            'address' => 'Gulbji, Dvietes pagasts, Augšdaugavas novads, LV-5442',
            'place_type' => 'park',
        ],

        // Eglaine
        'eglaines kultūras nam' => [
            'name' => 'Eglaines Kultūras nams',
            'city' => 'Eglaine',
            'region' => 'Latgale',
            'address' => 'Stendera iela 7, Eglaine, Eglaines pagasts, Augšdaugavas novads, LV-5444',
            'place_type' => 'cultural_center',
        ],
        'stendera muzej' => [
            'name' => 'G. F. Stendera muzejs',
            'city' => 'Eglaine',
            'region' => 'Latgale',
            'address' => 'Eglaine, Eglaines pagasts, Augšdaugavas novads, LV-5444',
            'place_type' => 'museum',
        ],

        // Silene / Skrudaliena
        'silenes kultūras nam' => [
            'name' => 'Silenes Kultūras nams',
            'city' => 'Silene',
            'region' => 'Latgale',
            'address' => 'Daugavas iela 38, Silene, Skrudalienas pagasts, Augšdaugavas novads, LV-5467',
            'place_type' => 'cultural_center',
        ],
        'skrudalienas kultūras nam' => [
            'name' => 'Silenes Kultūras nams',
            'city' => 'Silene',
            'region' => 'Latgale',
            'address' => 'Daugavas iela 38, Silene, Skrudalienas pagasts, Augšdaugavas novads, LV-5467',
            'place_type' => 'cultural_center',
        ],

        // Demene
        'demenes kultūras nam' => [
            'name' => 'Demenes Kultūras nams',
            'city' => 'Demene',
            'region' => 'Latgale',
            'address' => 'Briģenes iela 2, Demene, Demenes pagasts, Augšdaugavas novads, LV-5443',
            'place_type' => 'cultural_center',
        ],

        // Vecsaliena / Červonka
        'červonkas muiž' => [
            'name' => 'Červonkas (Vecsalienas) muižas pils un parks',
            'city' => 'Červonka',
            'region' => 'Latgale',
            'address' => 'Červonka, Vecsalienas pagasts, Augšdaugavas novads, LV-5468',
            'place_type' => 'historical',
        ],
        'červonkas pil' => [
            'name' => 'Červonkas (Vecsalienas) muižas pils un parks',
            'city' => 'Červonka',
            'region' => 'Latgale',
            'address' => 'Červonka, Vecsalienas pagasts, Augšdaugavas novads, LV-5468',
            'place_type' => 'historical',
        ],
        'vecsalienas muiž' => [
            'name' => 'Červonkas (Vecsalienas) muižas pils un parks',
            'city' => 'Červonka',
            'region' => 'Latgale',
            'address' => 'Červonka, Vecsalienas pagasts, Augšdaugavas novads, LV-5468',
            'place_type' => 'historical',
        ],
        'vecsalienas pil' => [
            'name' => 'Červonkas (Vecsalienas) muižas pils un parks',
            'city' => 'Červonka',
            'region' => 'Latgale',
            'address' => 'Červonka, Vecsalienas pagasts, Augšdaugavas novads, LV-5468',
            'place_type' => 'historical',
        ],
        'vecsalienas kultūras nam' => [
            'name' => 'Vecsalienas Kultūras nams',
            'city' => 'Červonka',
            'region' => 'Latgale',
            'address' => 'Červonka, Vecsalienas pagasts, Augšdaugavas novads, LV-5468',
            'place_type' => 'cultural_center',
        ],

        // Saliena
        'salienas kultūras nam' => [
            'name' => 'Salienas Kultūras nams',
            'city' => 'Saliena',
            'region' => 'Latgale',
            'address' => 'Centrāla iela 24, Saliena, Salienas pagasts, Augšdaugavas novads, LV-5469',
            'place_type' => 'cultural_center',
        ],

        // Nīcgale
        'nīcgales kultūras nam' => [
            'name' => 'Nīcgales Tautas nams',
            'city' => 'Nīcgale',
            'region' => 'Latgale',
            'address' => 'Skolas iela 17, Nīcgale, Nīcgales pagasts, Augšdaugavas novads, LV-5463',
            'place_type' => 'cultural_center',
        ],
        'nīcgales tautas nam' => [
            'name' => 'Nīcgales Tautas nams',
            'city' => 'Nīcgale',
            'region' => 'Latgale',
            'address' => 'Skolas iela 17, Nīcgale, Nīcgales pagasts, Augšdaugavas novads, LV-5463',
            'place_type' => 'cultural_center',
        ],

        // Šēdere
        'šēderes kultūras nam' => [
            'name' => 'Šēderes Kultūras nams',
            'city' => 'Šēdere',
            'region' => 'Latgale',
            'address' => 'Šēdere, Šēderes pagasts, Augšdaugavas novads, LV-5474',
            'place_type' => 'cultural_center',
        ],

        // Biķernieki
        'biķernieku kultūras nam' => [
            'name' => 'Biķernieku Kultūras nams',
            'city' => 'Biķernieki',
            'region' => 'Latgale',
            'address' => 'Saules iela 4, Biķernieki, Biķernieku pagasts, Augšdaugavas novads, LV-5440',
            'place_type' => 'cultural_center',
        ],

        // Maļinova
        'maļinovas kultūras nam' => [
            'name' => 'Maļinovas Kultūras nams',
            'city' => 'Maļinova',
            'region' => 'Latgale',
            'address' => 'Skolas iela 1, Maļinova, Maļinovas pagasts, Augšdaugavas novads, LV-5459',
            'place_type' => 'cultural_center',
        ],

        // Tabore
        'tabores' => [
            'name' => 'Tabores sabiedriskais centrs',
            'city' => 'Tabore',
            'region' => 'Latgale',
            'address' => 'Tabore, Tabores pagasts, Augšdaugavas novads, LV-5476',
            'place_type' => 'cultural_center',
        ],

        // Pilskalne
        'pilskalnes siguldiņ' => [
            'name' => 'Dabas liegums “Pilskalnes Siguldiņa”',
            'city' => 'Pilskalne',
            'region' => 'Latgale',
            'address' => 'Pilskalne, Pilskalnes pagasts, Augšdaugavas novads, LV-5447',
            'place_type' => 'park',
        ],

        // KC Vārpa
        'kultūras centrs "vārpa"' => [
            'name' => 'Kultūras centrs “Vārpa”',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Dobeles iela 30, Daugavpils, LV-5404',
            'place_type' => 'cultural_center',
        ],
        'kc vārpa' => [
            'name' => 'Kultūras centrs “Vārpa”',
            'city' => 'Daugavpils',
            'region' => 'Latgale',
            'address' => 'Dobeles iela 30, Daugavpils, LV-5404',
            'place_type' => 'cultural_center',
        ],
    ];

    public function getSlug(): string
    {
        return 'augsdaugavas-novads';
    }

    public function getName(): string
    {
        return 'Augšdaugavas novada pašvaldība';
    }

    /**
     * Scrape events from augsdaugavasnovads.lv/lv/notikumu-kalendars
     * Strict rule: NO crawling into detail pages - everything extracted from calendar cards directly
     * to avoid triggering LVRTC rate-limiting/firewall drops.
     */
    public function scrape(Source $source): Collection
    {
        $events = collect();
        $baseUrl = $source->url ?: (self::BASE_URL . self::CALENDAR_PATH);
        $maxPages = 10;
        $today = Carbon::today('Europe/Riga');
        $seenExternalIds = [];

        Log::info('Starting Augšdaugavas novads scraping...', [
            'scraper' => static::class,
            'source' => $source->name,
            'url' => $baseUrl,
        ]);

        for ($page = 0; $page < $maxPages; $page++) {
            if ($page > 0) {
                // 1000ms polite delay with jitter on LVRTC/VRAA infrastructure
                $this->politeDelay(1000, 300);
            }

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

                // Exclude committee meetings, council sessions, etc.
                if ($this->isExcludedEvent($categories, $title)) {
                    return;
                }

                // Link to event
                $linkNode = $node->filter('h2.event-title a, a[href*="/notikums/"]');
                $relLink = $linkNode->count() ? $linkNode->first()->attr('href') : '';
                $cleanUrl = $this->normalizeUrl($relLink);

                $slugPart = $cleanUrl ? basename(parse_url($cleanUrl, PHP_URL_PATH)) : Str::slug($title);
                $externalId = 'augsdaugavas_' . $slugPart;

                // Dates & times
                $dateStr = $node->filter('span.date-date')->count() ? $this->cleanText($node->filter('span.date-date')->text()) : '';
                $timeStr = $node->filter('span.date-time')->count() ? $this->cleanText($node->filter('span.date-time')->text()) : '';
                [$startAt, $endAt, $allDay] = $this->parseLatvianDates($dateStr, $timeStr);

                if (!$startAt) {
                    return;
                }

                // Append start datetime suffix if externalId might collide across recurring dates
                $externalId .= '_' . $startAt->format('YmdHi');

                if (isset($seenExternalIds[$externalId])) {
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

                // Description: snippet from card
                $description = $node->filter('div.event-content')->count() ? $this->cleanText($node->filter('div.event-content')->text()) : '';
                if (empty($description)) {
                    $description = $title . ' Augšdaugavas novadā.';
                }

                // Location resolution
                $locationRaw = $node->filter('span.describing-field-value')->count() ? $this->cleanText($node->filter('span.describing-field-value')->text()) : '';
                $venueInfo = $this->resolveLocation($locationRaw, $title, $description);

                // Categories (strictly adhering to AGENTS.md)
                $canonicalCategories = $this->resolveCategories($categories, $title, $description, $venueInfo['name']);

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
                    region: $venueInfo['region'],
                    address: $venueInfo['address'],
                    latitude: $venueInfo['lat'] ?? null,
                    longitude: $venueInfo['lon'] ?? null,
                    placeType: $venueInfo['place_type'],
                    categoryNames: $canonicalCategories,
                    isFree: $priceData['is_free'],
                    priceMin: $priceData['price_min'],
                    priceMax: $priceData['price_max'],
                    currency: 'EUR',
                    ticketUrl: $cleanUrl ?: $baseUrl,
                    imageUrl: $thumbImg,
                    sourceUrl: $cleanUrl ?: $baseUrl,
                    sourceExternalId: $externalId,
                    rawData: [
                        'raw_categories' => $categories,
                        'all_day' => $allDay,
                        'original_location' => $locationRaw,
                        'original_date_str' => $dateStr,
                        'original_time_str' => $timeStr,
                    ],
                );

                $events->push($dto);
            });

            // If all events on the current page are in the past, stop pagination
            if ($allPageEventsPast && $items->count() > 0) {
                break;
            }
        }

        Log::info('Augšdaugavas novads scraping completed: ' . $events->count() . ' events collected', [
            'scraper' => static::class,
        ]);

        return $events;
    }

    /**
     * Resolve location and address for Augšdaugavas novads
     */
    public function resolveLocation(string $locationRaw, string $title = '', string $description = ''): array
    {
        $normalizedLoc = mb_strtolower(trim($locationRaw), 'UTF-8');
        $haystack = mb_strtolower($locationRaw . ' ' . $title . ' ' . $description, 'UTF-8');

        // Check venue dictionary
        foreach ($this->venueAddressMap as $keyword => $venue) {
            if (str_contains($normalizedLoc, $keyword) || str_contains($haystack, $keyword)) {
                return $venue;
            }
        }

        // Town and pagasti matching
        $pagasti = [
            'ilūkste' => ['city' => 'Ilūkste', 'place_type' => 'city'],
            'subate' => ['city' => 'Subate', 'place_type' => 'city'],
            'naujene' => ['city' => 'Naujene', 'place_type' => 'town'],
            'krauja' => ['city' => 'Krauja', 'place_type' => 'town'],
            'slutišķi' => ['city' => 'Slutišķi', 'place_type' => 'town'],
            'višķi' => ['city' => 'Višķi', 'place_type' => 'town'],
            'špoģi' => ['city' => 'Špoģi', 'place_type' => 'town'],
            'bebrene' => ['city' => 'Bebrene', 'place_type' => 'town'],
            'birkineļi' => ['city' => 'Birkineļi', 'place_type' => 'town'],
            'berķenele' => ['city' => 'Birkineļi', 'place_type' => 'town'],
            'vabole' => ['city' => 'Vabole', 'place_type' => 'town'],
            'līksna' => ['city' => 'Līksna', 'place_type' => 'town'],
            'svente' => ['city' => 'Svente', 'place_type' => 'town'],
            'medumi' => ['city' => 'Medumi', 'place_type' => 'town'],
            'kalupe' => ['city' => 'Kalupe', 'place_type' => 'town'],
            'dubna' => ['city' => 'Dubna', 'place_type' => 'town'],
            'ambeļi' => ['city' => 'Ambeļi', 'place_type' => 'town'],
            'dviete' => ['city' => 'Dviete', 'place_type' => 'town'],
            'eglaine' => ['city' => 'Eglaine', 'place_type' => 'town'],
            'silene' => ['city' => 'Silene', 'place_type' => 'town'],
            'skrudaliena' => ['city' => 'Silene', 'place_type' => 'town'],
            'demene' => ['city' => 'Demene', 'place_type' => 'town'],
            'červonka' => ['city' => 'Červonka', 'place_type' => 'town'],
            'vecsaliena' => ['city' => 'Červonka', 'place_type' => 'town'],
            'saliena' => ['city' => 'Saliena', 'place_type' => 'town'],
            'nīcgale' => ['city' => 'Nīcgale', 'place_type' => 'town'],
            'kalkūne' => ['city' => 'Kalkūni', 'place_type' => 'town'],
            'kalkūni' => ['city' => 'Kalkūni', 'place_type' => 'town'],
            'šēdere' => ['city' => 'Šēdere', 'place_type' => 'town'],
            'biķernieki' => ['city' => 'Biķernieki', 'place_type' => 'town'],
            'maļinova' => ['city' => 'Maļinova', 'place_type' => 'town'],
            'tabore' => ['city' => 'Tabore', 'place_type' => 'town'],
            'laucesa' => ['city' => 'Laucesa', 'place_type' => 'town'],
            'pilskalne' => ['city' => 'Pilskalne', 'place_type' => 'town'],
            'prode' => ['city' => 'Subate', 'place_type' => 'town'],
        ];

        foreach ($pagasti as $pagasts => $meta) {
            if (str_contains($normalizedLoc, $pagasts) || str_contains($haystack, $pagasts)) {
                $venueTitle = !empty($locationRaw) ? $locationRaw : $meta['city'];
                return [
                    'name' => $venueTitle,
                    'city' => $meta['city'],
                    'region' => 'Latgale',
                    'address' => $venueTitle . ', Augšdaugavas novads',
                    'place_type' => $meta['place_type'],
                ];
            }
        }

        if (!empty($locationRaw)) {
            return [
                'name' => $locationRaw,
                'city' => 'Augšdaugavas novads',
                'region' => 'Latgale',
                'address' => $locationRaw . ', Augšdaugavas novads',
                'place_type' => 'cultural_centre',
            ];
        }

        return [
            'name' => 'Augšdaugavas novads',
            'city' => 'Augšdaugavas novads',
            'region' => 'Latgale',
            'address' => 'Rīgas iela 2, Daugavpils, LV-5401',
            'place_type' => 'cultural_centre',
        ];
    }

    /**
     * Resolve categories strictly following AGENTS.md rules:
     * - Kino is ALWAYS Kino and NEVER Teātris
     * - "kinoteātris" must NEVER be classified as Teātris
     * - Fallback is "Cits"
     */
    public function resolveCategories(array $rawCategories, string $title, string $description, string $venueName = ''): array
    {
        $text = mb_strtolower($title . ' ' . $description . ' ' . $venueName . ' ' . implode(' ', $rawCategories), 'UTF-8');

        // 1. Strict Cinema / Kino rule per AGENTS.md
        $cinemaKeywords = [
            'filma', 'filmas', 'filmu', 'filmā', 'kinoseanss', 'filmas seanss',
            'spēlfilma', 'dokumentālā filma', 'animācijas filma', 'kinofestivāls',
            'kino vakars', 'brīvdabas kino', 'kino seanss', 'kino',
        ];

        $isCinema = false;
        foreach ($cinemaKeywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text)) {
                $isCinema = true;
                break;
            }
        }

        if ($isCinema) {
            return ['kino'];
        }

        $categories = [];

        // 2. Teātris (Strict: only actual theater productions)
        $theatreKeywords = ['izrāde', 'teātra izrāde', 'luga', 'iestudējums', 'monodrāma', 'komēdija', 'traģēdija', 'opera', 'balets'];
        foreach ($theatreKeywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text)) {
                $categories[] = 'teatris';
                break;
            }
        }

        // 3. Mūzika / Koncerti
        $musicKeywords = ['koncerts', 'muzicē', 'dzied', 'orķestris', 'koris', 'ansamblis', 'mūzika', 'dziesma', 'solists', 'muzikāls', 'ģitāra', 'kamermūzika', 'rokkoncerts'];
        foreach ($musicKeywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text)) {
                $categories[] = 'muzika';
                break;
            }
        }

        // 4. Izstādes
        $exhibitionKeywords = ['izstāde', 'gleznas', 'fotogrāfijas', 'ekspozīcija', 'mākslas darbi', 'tēlotājmāksla', 'keramika', 'rokdarbi'];
        foreach ($exhibitionKeywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text)) {
                $categories[] = 'izstades';
                break;
            }
        }

        // 5. Sports
        $sportsKeywords = ['sports', 'sacensības', 'čempionāts', 'turnīrs', 'mačs', 'skrējiens', 'maratons', 'futbols', 'basketbols', 'volejbols', 'orientēšanās', 'nūjošana', 'velobrauciens', 'šahs'];
        foreach ($sportsKeywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text)) {
                $categories[] = 'sports';
                break;
            }
        }

        // 6. Bērniem
        $kidsKeywords = ['bērniem', 'ģimenēm', 'radošā darbnīca', 'leļļu', 'bērnu rīts', 'rotaļas', 'animācija', 'jauniešiem'];
        foreach ($kidsKeywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text)) {
                $categories[] = 'berniem';
                break;
            }
        }

        // 7. Svētki
        $holidayKeywords = ['svētki', 'līgo', 'jāņi', 'jaunais gads', 'valsts svētki', 'latvijas dzimšanas diena', '18. novembr', 'advente', 'ziemassvētki', 'lieldienas', 'gadatirgus', 'tirdziņš', 'pagasta svētki'];
        foreach ($holidayKeywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text)) {
                $categories[] = 'svetki';
                break;
            }
        }

        // 8. Semināri / Izglītojoši
        $seminarKeywords = ['seminārs', 'lekcija', 'diskusija', 'meistarklase', 'apmācība', 'konference'];
        foreach ($seminarKeywords as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/u', $text)) {
                $categories[] = 'seminari';
                break;
            }
        }

        // Fallback rule per AGENTS.md
        if (empty($categories)) {
            return ['citi'];
        }

        return array_values(array_unique($categories));
    }

    /**
     * Exclude administrative meetings, council sessions, committee meetings
     */
    public function isExcludedEvent(array $categories, string $title): bool
    {
        $titleLower = mb_strtolower($title, 'UTF-8');
        $catStr = mb_strtolower(implode(' ', $categories), 'UTF-8');

        $administrativePatterns = [
            '/\bdomes\s+[^\n]*sēde\b/u',
            '/\bkomitejas\s+sēde\b/u',
            '/\bārkārtas\s+sēde\b/u',
            '/\bsvinīgā\s+sēde\b/u',
            '/\bsēde\b/u',
            '/\bdeputātu\s+(?:pieņemšana|tikšanās)\b/u',
            '/\biedzīvotāju\s+pieņemšana\b/u',
            '/\bsabiedriskā\s+apspriešana\b/u',
            '/\bkonsultatīvā[s]?\s+padome[s]?\b/u',
        ];

        foreach ($administrativePatterns as $pattern) {
            if (preg_match($pattern, $titleLower) || preg_match($pattern, $catStr)) {
                return true;
            }
        }

        return false;
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
            preg_match('/\b(?:bezmaksas|brīva\s+ieeja|ieeja\s+brīva|bez\s+maksas)\b/iu', $content)
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
     * Parse Latvian date and time strings from Drupal view-events format
     *
     * @return array{0: ?Carbon, 1: ?Carbon, 2: bool} [startAt, endAt, allDay]
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

        // Clean redundant "Datums" prefix if present
        $clean = preg_replace('/^Datums\s*/iu', '', trim($dateStr));
        $clean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $clean));

        // Time clean
        $timeClean = preg_replace('/^Laiks\s*/iu', '', trim($timeStr));
        $timeClean = trim(str_replace(['&#8211;', '&ndash;', '–', '—'], '-', $timeClean));

        $allDay = false;
        $startTime = '10:00:00';
        $endTime = null;

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

        // Multi-day date range: "15. - 18. novembris, 2026" or "3. novembris, 2026 - 6. decembris, 2026"
        if (str_contains($clean, '-')) {
            $parts = explode('-', $clean, 2);
            $p1 = trim($parts[0]);
            $p2 = trim($parts[1]);

            // Case: "15. - 18. novembris, 2026"
            if (preg_match('/^(\d{1,2})\.?$/u', $p1, $m1) && preg_match('/^(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p2, $m2)) {
                $day1 = (int) $m1[1];
                $day2 = (int) $m2[1];
                $monthName = mb_strtolower($m2[2], 'UTF-8');
                $year = !empty($m2[3]) ? (int) $m2[3] : Carbon::today('Europe/Riga')->year;

                if (isset($months[$monthName])) {
                    $monthNum = $months[$monthName];
                    $startAt = Carbon::create($year, $monthNum, $day1, 0, 0, 0, 'Europe/Riga');
                    $endAt = Carbon::create($year, $monthNum, $day2, 23, 59, 59, 'Europe/Riga');

                    if ($startTime !== '00:00:00') {
                        [$h, $m] = explode(':', $startTime);
                        $startAt->setTime((int)$h, (int)$m, 0);
                    }
                    if ($endTime && $endTime !== '23:59:59') {
                        [$h, $m] = explode(':', $endTime);
                        $endAt->setTime((int)$h, (int)$m, 0);
                    }

                    return [$startAt, $endAt, $allDay];
                }
            }

            // Case: "3. novembris, 2026 - 6. decembris, 2026"
            if (
                preg_match('/^(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p1, $m1) &&
                preg_match('/^(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $p2, $m2)
            ) {
                $day1 = (int) $m1[1];
                $day2 = (int) $m2[1];
                $month1 = mb_strtolower($m1[2], 'UTF-8');
                $month2 = mb_strtolower($m2[2], 'UTF-8');
                $year2 = !empty($m2[3]) ? (int) $m2[3] : Carbon::today('Europe/Riga')->year;
                $year1 = !empty($m1[3]) ? (int) $m1[3] : $year2;

                if (isset($months[$month1], $months[$month2])) {
                    $startAt = Carbon::create($year1, $months[$month1], $day1, 0, 0, 0, 'Europe/Riga');
                    $endAt = Carbon::create($year2, $months[$month2], $day2, 23, 59, 59, 'Europe/Riga');

                    if ($startTime !== '00:00:00') {
                        [$h, $m] = explode(':', $startTime);
                        $startAt->setTime((int)$h, (int)$m, 0);
                    }
                    if ($endTime && $endTime !== '23:59:59') {
                        $parts = explode(':', $endTime);
                        $endAt->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0), (int)($parts[2] ?? 0));
                    }

                    return [$startAt, $endAt, $allDay];
                }
            }

            // Case: "15.11.2026 - 18.11.2026"
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $p1, $m1) && preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $p2, $m2)) {
                $startAt = Carbon::create((int)$m1[3], (int)$m1[2], (int)$m1[1], 0, 0, 0, 'Europe/Riga');
                $endAt = Carbon::create((int)$m2[3], (int)$m2[2], (int)$m2[1], 23, 59, 59, 'Europe/Riga');

                if ($startTime !== '00:00:00') {
                    [$h, $m] = explode(':', $startTime);
                    $startAt->setTime((int)$h, (int)$m, 0);
                }
                if ($endTime && $endTime !== '23:59:59') {
                    $parts = explode(':', $endTime);
                    $endAt->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0), (int)($parts[2] ?? 0));
                }

                return [$startAt, $endAt, $allDay];
            }
        }

        // Single date with month name: "18. novembris, 2026" or "18. novembris"
        if (preg_match('/(\d{1,2})\.\s*([a-zāčēģīķļņšūž]+)(?:,?\s*(\d{4}))?/ui', $clean, $m)) {
            $day = (int) $m[1];
            $monthName = mb_strtolower($m[2], 'UTF-8');
            $year = !empty($m[3]) ? (int) $m[3] : Carbon::today('Europe/Riga')->year;

            if (isset($months[$monthName])) {
                $monthNum = $months[$monthName];
                $startAt = Carbon::create($year, $monthNum, $day, 0, 0, 0, 'Europe/Riga');
                $endAt = null;

                if ($startTime) {
                    [$h, $m] = explode(':', $startTime);
                    $startAt->setTime((int)$h, (int)$m, 0);
                }

                if ($endTime) {
                    $endAt = Carbon::create($year, $monthNum, $day, 0, 0, 0, 'Europe/Riga');
                    $parts = explode(':', $endTime);
                    $endAt->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0), (int)($parts[2] ?? 0));
                }

                return [$startAt, $endAt, $allDay];
            }
        }

        // Standard numeric date: "18.11.2026"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $clean, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            $startAt = Carbon::create($year, $month, $day, 0, 0, 0, 'Europe/Riga');
            $endAt = null;

            if ($startTime) {
                [$h, $m] = explode(':', $startTime);
                $startAt->setTime((int)$h, (int)$m, 0);
            }

            if ($endTime) {
                $endAt = Carbon::create($year, $month, $day, 0, 0, 0, 'Europe/Riga');
                $parts = explode(':', $endTime);
                $endAt->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0), (int)($parts[2] ?? 0));
            }

            return [$startAt, $endAt, $allDay];
        }

        return [null, null, false];
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
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.augsdaugavasnovads.lv') . ($parsed['path'] ?? '');
        }

        $path = strtok($relUrl, '?');
        return self::BASE_URL . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    public function cleanText(?string $text): string
    {
        if (!$text) {
            return '';
        }

        $cleaned = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleaned = strip_tags($cleaned);
        $cleaned = preg_replace('/[ \t\x{00A0}]+/u', ' ', $cleaned);
        return trim($cleaned);
    }
}
