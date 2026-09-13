<?php

namespace App\Services\Scrapers\Contracts;

use App\Models\Source;
use App\Services\Scrapers\DTO\ScrapedEventDTO;
use Illuminate\Support\Collection;

interface EventScraperInterface
{
    /**
     * Get unique scraper slug identifier
     */
    public function getSlug(): string;

    /**
     * Get human readable source name
     */
    public function getName(): string;

    /**
     * Perform the scraping operation and return a collection of ScrapedEventDTO objects
     * 
     * @return Collection<int, ScrapedEventDTO>
     */
    public function scrape(Source $source): Collection;
}
