<?php

namespace App\Services\Scrapers;

use App\Services\Scrapers\Contracts\EventScraperInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

abstract class BaseScraper implements EventScraperInterface
{
    protected Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 20,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 IzdzivotBot/1.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'lv,en-US;q=0.7,en;q=0.3',
            ],
            'verify' => false, // Prevents local SSL cert failures on some legacy latvian portals
        ]);
    }

    /**
     * Fetch HTML and return a Symfony DomCrawler instance
     */
    protected function fetchCrawler(string $url): ?Crawler
    {
        try {
            $response = $this->httpClient->get($url);
            $html = (string) $response->getBody();
            return new Crawler($html, $url);
        } catch (\Throwable $e) {
            Log::error("Scraper fetch error for URL {$url}: " . $e->getMessage(), [
                'scraper' => static::class,
            ]);
            return null;
        }
    }

    /**
     * Fetch JSON and return decoded array
     */
    protected function fetchJson(string $url): ?array
    {
        try {
            $response = $this->httpClient->get($url);
            $json = (string) $response->getBody();
            return json_decode($json, true);
        } catch (\Throwable $e) {
            Log::error("Scraper JSON fetch error for URL {$url}: " . $e->getMessage(), [
                'scraper' => static::class,
            ]);
            return null;
        }
    }

    /**
     * Helper to clean Latvian text from excessive whitespace
     */
    protected function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        // Replace non-newline whitespace with single space
        $text = preg_replace('/[^\S\r\n]+/u', ' ', $text);
        // Collapse 3 or more newlines into 2
        $text = preg_replace('/(\r?\n\s*){3,}/u', "\n\n", $text);
        // Trim each line
        $lines = array_map('trim', explode("\n", $text));
        return trim(implode("\n", $lines));
    }
}
