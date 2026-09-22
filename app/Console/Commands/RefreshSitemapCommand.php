<?php

namespace App\Console\Commands;

use App\Services\SitemapService;
use Illuminate\Console\Command;

class RefreshSitemapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sitemap:refresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Regenerate and pre-warm XML sitemap cache for search engine crawlers';

    /**
     * Execute the console command.
     */
    public function handle(SitemapService $sitemapService): int
    {
        $this->info('Regenerating sitemap XML cache...');

        $xml = $sitemapService->refresh();

        $urlCount = substr_count($xml, '<url>');

        $this->info("✅ Sitemap cache refreshed successfully ({$urlCount} URLs indexed).");

        return self::SUCCESS;
    }
}
