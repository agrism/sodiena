<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use App\Services\SystemProcessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScrapeEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:scrape {source? : Slug or ID of the source to scrape} {--all : Scrape all active sources} {--type= : Filter sources by category (ticketing, venue, sports, municipality, thematic, aggregator)} {--dry-run : Preview scraped events without saving to database} {--limit= : Limit number of rows in dry-run preview}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape and ingest future events from configured public sources';

    /**
     * Execute the console command.
     */
    public function handle(EventIngestionService $ingestionService, SystemProcessService $processService): int
    {
        $sourceIdentifier = $this->argument('source');
        $all = $this->option('all');
        $type = $this->option('type');
        $isDryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : 20;

        $query = Source::query();

        if ($sourceIdentifier) {
            $query->where(function ($q) use ($sourceIdentifier) {
                $q->where('id', $sourceIdentifier)->orWhere('slug', $sourceIdentifier);
            });
        } else {
            $query->where('is_active', true);
        }

        if ($type) {
            $query->where('type', $type);
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->warn('Nav atrasts neviens aktīvs pasākumu avots.');
            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn("🔍 DRY-RUN REŽĪMS: Dati netiks saglabāti datubāzē.");
            foreach ($sources as $source) {
                $this->info("\n--- Avots: {$source->name} ({$source->slug}) ---");
                try {
                    $scraper = app()->make($source->scraper_class);
                    $startTime = microtime(true);
                    $events = $scraper->scrape($source);
                    $duration = round(microtime(true) - $startTime, 2);

                    $this->info("Kopā atrasti: {$events->count()} pasākumi ({$duration}s).");

                    $preview = $events->take($limit);
                    $rows = [];
                    foreach ($preview as $i => $dto) {
                        $rows[] = [
                            $i + 1,
                            $dto->startAt ? $dto->startAt->format('Y-m-d H:i') : '-',
                            \Illuminate\Support\Str::limit($dto->title, 38),
                            \Illuminate\Support\Str::limit(($dto->venueName ?: '-') . ' (' . ($dto->city ?: '-') . ')', 35),
                            implode(', ', $dto->categoryNames) ?: '-',
                            !empty($dto->imageUrl) ? 'Jā' : 'Nav',
                            $dto->sourceExternalId ?: '-',
                        ];
                    }

                    $this->table(
                        ['#', 'Datums & Laiks', 'Nosaukums', 'Vieta (Pilsēta)', 'Kategorija', 'Bilde?', 'Ext ID'],
                        $rows
                    );

                    if ($events->count() > $limit) {
                        $this->line("... un vēl " . ($events->count() - $limit) . " pasākumi (izmanto --limit=N, lai redzētu vairāk).");
                    }
                } catch (\Throwable $e) {
                    $this->error("Kļūda avotam {$source->name}: " . $e->getMessage());
                }
            }
            $this->info("\n✅ Dry-run pārbaude pabeigta!");
            return self::SUCCESS;
        }

        // When running full scrape, acquire lock to prevent overlapping runs
        if ($all && !$isDryRun) {
            $lockRes = $processService->runWithLock(SystemProcessService::PROCESS_SCRAPE_ALL, function () use ($sources, $ingestionService, $all, $isDryRun) {
                return $this->runScrapeLoop($sources, $ingestionService, $all, $isDryRun);
            });

            if (!$lockRes['executed']) {
                $this->warn($lockRes['message']);
                return self::SUCCESS;
            }

            return $lockRes['result'] ?? self::SUCCESS;
        }

        return $this->runScrapeLoop($sources, $ingestionService, (bool) $all, $isDryRun);
    }

    private function runScrapeLoop($sources, EventIngestionService $ingestionService, bool $all, bool $isDryRun): int
    {
        // Prevent memory exhaustion on large multi-source scrape runs
        DB::disableQueryLog();

        $this->info("Uzsākam pasākumu parsēšanu {$sources->count()} avotiem...");

        $successCount = 0;
        $failedSources = [];

        foreach ($sources as $source) {
            $this->output->write("🔄 Parsējam avotu: <comment>{$source->name}</comment>... ");

            try {
                // Garbage collect between scraper runs to prevent memory bloat
                gc_collect_cycles();

                $log = $ingestionService->ingest($source);

                if ($log->status === 'success' || $log->status === 'partial') {
                    $statusText = $log->status === 'partial' ? '<comment>DAĻĒJI PABEIGTS</comment>' : '<info>PABEIGTS</info>';
                    $this->output->writeln("{$statusText} (Atrasti: {$log->items_found}, Izveidoti: {$log->items_created}, Atjaunoti: {$log->items_updated}, Ilgums: {$log->duration_seconds}s)");
                    $successCount++;
                } else {
                    $errMsg = is_array($log->errors) ? ($log->errors[0] ?? 'Nezināma kļūda') : 'Nezināma kļūda';
                    $this->output->writeln("<error>KĻŪDA</error> ({$errMsg})");
                    $failedSources[] = "{$source->name} ({$errMsg})";
                }
            } catch (\Throwable $e) {
                // Safety net: an uncaught exception on one scraper MUST NEVER terminate the loop
                Log::error("ScrapeEventsCommand: critical crash on source {$source->slug}: " . $e->getMessage(), [
                    'source_id' => $source->id,
                    'exception' => $e,
                ]);

                $this->output->writeln("<error>FATĀLA KĻŪDA</error> ({$e->getMessage()}) -> Pārejam pie nākamā avota!");
                $failedSources[] = "{$source->name} (CRASH: {$e->getMessage()})";

                // In case MySQL connection dropped, attempt to reconnect for subsequent sources
                try {
                    DB::reconnect();
                    $source->update([
                        'last_scraped_at' => now(),
                        'last_status' => 'failed',
                    ]);
                } catch (\Throwable $reconnectEx) {
                    // Ignore reconnect failures here to guarantee we move forward
                }
            }
        }

        $this->newLine();
        if (empty($failedSources)) {
            $this->info("✅ Parsēšana veiksmīgi pabeigta visiem {$sources->count()} avotiem!");
        } else {
            $this->warn("⚠️  Parsēšana pabeigta ar brīdinājumiem: {$successCount}/{$sources->count()} avoti veiksmīgi, " . count($failedSources) . " ar kļūdām:");
            foreach ($failedSources as $f) {
                $this->line("   • <comment>{$f}</comment>");
            }
        }

        // Automatically run sequential post-processing pipeline (images -> translations -> sitemap)
        if ($all && !$isDryRun) {
            $this->newLine();
            $this->info("🔗 Uzsākam pēcapstrādes procesu ķēdi (secīgi, bez pārklāšanās)...");

            // 1. Attēlu sinhronizācija uz Hetzner S3
            $this->info("📸 [1/3] Sinhronizējam pasākumu attēlus uz Hetzner S3...");
            try {
                $this->call('events:sync-images', ['--chunk' => 50]);
            } catch (\Throwable $e) {
                Log::warning("Post-scrape sync-images failed: " . $e->getMessage());
            }

            // 2. Tulkojumu sinhronizācija (LV/EN/RU)
            $this->info("🌐 [2/3] Sinhronizējam trūkstošos tulkojumus (LV, EN, RU)...");
            try {
                $this->call('events:sync-translations');
            } catch (\Throwable $e) {
                Log::warning("Post-scrape sync-translations failed: " . $e->getMessage());
            }

            // 3. Sitemap atjaunošana
            $this->info("🗺️  [3/3] Atjaunojam sitemap.xml vietnes karti...");
            try {
                $this->call('sitemap:refresh');
            } catch (\Throwable $e) {
                Log::warning("Post-scrape sitemap refresh failed: " . $e->getMessage());
            }

            $this->info("✨ Pilnais cikls (parsēšana + attēli + tulkojumi + sitemap) pabeigts veiksmīgi!");
        } elseif (!$isDryRun) {
            $this->newLine();
            $this->info("🗺️  Atjaunojam sitemap.xml...");
            try {
                $this->call('sitemap:refresh');
            } catch (\Throwable $e) {
                Log::warning("Sitemap refresh failed after scrape: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
