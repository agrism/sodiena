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
    protected $signature = 'events:scrape {source? : Slug or ID of the source to scrape} {--all : Scrape all active sources}';

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

        $query = Source::query();

        if ($sourceIdentifier) {
            $query->where('id', $sourceIdentifier)->orWhere('slug', $sourceIdentifier);
        } else {
            $query->where('is_active', true);
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->warn('Nav atrasts neviens aktīvs pasākumu avots.');
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
