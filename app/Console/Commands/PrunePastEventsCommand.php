<?php

namespace App\Console\Commands;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PrunePastEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:prune-past {--force : Force execution without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Soft delete events that ended before today (midnight cleanup)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $todayStart = Carbon::today(); // start of today at 00:00:00

        $this->info("Scanning for events that ended before today ({$todayStart->toDateString()})...");

        // Find all events that ended before today and are not yet soft-deleted
        $pastEventsQuery = Event::where(function ($query) use ($todayStart) {
            $query->whereNotNull('end_at')
                  ->where('end_at', '<', $todayStart)
                  ->orWhere(function ($sub) use ($todayStart) {
                      $sub->whereNull('end_at')
                          ->where('start_at', '<', $todayStart);
                  });
        });

        $count = $pastEventsQuery->count();

        if ($count === 0) {
            $this->info("No past events found to prune.");
            return Command::SUCCESS;
        }

        $this->info("Found {$count} past event(s) to soft-delete.");

        $deletedCount = $pastEventsQuery->delete();

        $this->info("Successfully soft-deleted {$deletedCount} past event(s).");

        return Command::SUCCESS;
    }
}
