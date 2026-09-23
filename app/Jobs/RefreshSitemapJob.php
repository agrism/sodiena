<?php

namespace App\Jobs;

use App\Services\SitemapService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshSitemapJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * The number of seconds after which the job's unique lock will be released.
     * Prevents queue flood during rapid event publications.
     */
    public int $uniqueFor = 30;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance with debouncing delay and transaction commit safety.
     */
    public function __construct(int $delaySeconds = 5)
    {
        $this->delay = $delaySeconds;
        $this->afterCommit = true;
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'sitemap_refresh';
    }

    /**
     * Execute the job.
     */
    public function handle(SitemapService $sitemapService): void
    {
        $xml = $sitemapService->refresh();
        $urlCount = substr_count($xml, '<url>');

        Log::info("Sitemap successfully refreshed asynchronously via Queue Job ({$urlCount} URLs indexed).");
    }
}
