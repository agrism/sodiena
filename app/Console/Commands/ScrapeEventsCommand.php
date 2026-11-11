<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Scrapers\EventIngestionService;
use Illuminate\Console\Command;

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
    public function handle(EventIngestionService $ingestionService): int
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

        $this->info("Uzsākam pasākumu parsēšanu {$sources->count()} avotiem...");

        foreach ($sources as $source) {
            $this->output->write("🔄 Parsējam avotu: <comment>{$source->name}</comment>... ");
            
            $log = $ingestionService->ingest($source);

            if ($log->status === 'success' || $log->status === 'partial') {
                $statusText = $log->status === 'partial' ? '<comment>DAĻĒJI PABEIGTS</comment>' : '<info>PABEIGTS</info>';
                $this->output->writeln("{$statusText} (Atrasti: {$log->items_found}, Izveidoti: {$log->items_created}, Atjaunoti: {$log->items_updated}, Ilgums: {$log->duration_seconds}s)");
            } else {
                $errMsg = is_array($log->errors) ? ($log->errors[0] ?? 'Nezināma kļūda') : 'Nezināma kļūda';
                $this->output->writeln("<error>KĻŪDA</error> ({$errMsg})");
            }
        }

        $this->info('✅ Parsēšana veiksmīgi pabeigta!');
        return self::SUCCESS;
    }
}
