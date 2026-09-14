<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\EventImageStorageService;
use Illuminate\Console\Command;

class SyncEventImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:sync-images 
                            {--id= : Specific event ID to process}
                            {--limit= : Maximum number of events to process}
                            {--force : Force re-upload even if internal_image_url already exists}
                            {--chunk=50 : Number of records per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download images from event image_url and upload them to Hetzner S3 (internal_image_url)';

    /**
     * Execute the console command.
     */
    public function handle(EventImageStorageService $imageService): int
    {
        $specificId = $this->option('id');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $force = (bool) $this->option('force');
        $chunkSize = (int) $this->option('chunk');

        $query = Event::query()
            ->withoutTrashed()
            ->whereNotIn('status', ['deleted', 'cancelled'])
            ->whereNotNull('image_url')
            ->where('image_url', '!=', '')
            ->where('image_url', 'not like', '%aplis-default-og-img.jpg%');

        if ($specificId) {
            $query->where('id', $specificId);
        } elseif (!$force) {
            $query->where(function ($q) {
                $q->whereNull('internal_image_url')->orWhere('internal_image_url', '');
            });
        }

        $total = $limit ? min($query->count(), $limit) : $query->count();

        if ($total === 0) {
            $this->info('No events found requiring image upload to S3.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} event(s) to process for S3 image upload.");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $success = 0;
        $failed = 0;

        $query->orderBy('id', 'desc');

        if ($limit) {
            $events = $query->take($limit)->get();
            foreach ($events as $event) {
                $url = $imageService->mirrorEventImage($event, $force);
                if ($url) {
                    $success++;
                } else {
                    $failed++;
                }
                $processed++;
                $bar->advance();
            }
        } else {
            $query->chunkById($chunkSize, function ($events) use (&$processed, &$success, &$failed, $imageService, $force, $bar) {
                foreach ($events as $event) {
                    $url = $imageService->mirrorEventImage($event, $force);
                    if ($url) {
                        $success++;
                    } else {
                        $failed++;
                    }
                    $processed++;
                    $bar->advance();
                }
            });
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Image sync completed!");
        $this->table(
            ['Total Processed', 'Successfully Uploaded', 'Failed / Skipped'],
            [[$processed, $success, $failed]]
        );

        return self::SUCCESS;
    }
}
