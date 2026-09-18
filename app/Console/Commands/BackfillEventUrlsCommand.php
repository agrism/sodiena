<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

class BackfillEventUrlsCommand extends Command
{
    protected $signature = 'events:backfill-urls';
    protected $description = 'Sanitizes all ticket_url and source_url values in the database, replacing afiro aggregator URLs with real ticketing and official URLs extracted from event data';

    public function handle(): int
    {
        $this->info('Starting URL backfill for events...');

        $ticketPlatforms = [
            'bilesuparadize.lv', 'bilesuserviss.lv', 'bezrindas.lv', 'ticketshop.lv',
            'aula.lv', 'fienta.com', 'apollokino.lv', 'forumcinemas.lv', 'splendidpalace.lv',
            'cinamonkino.com', 'opera.lv', 'passportix.eu', 'ticketbest.eu', 'ticketly.eu',
            'forms.gle', 'docs.google.com/forms', 'tally.so', 'distantrace.com',
            'play.fiba3x3.com', 'cuescore.com'
        ];

        $updatedCount = 0;
        $total = Event::count();

        Event::chunk(100, function ($events) use (&$updatedCount) {
            foreach ($events as $event) {
                $ticketLinks = $event->ticket_links;
                $newTicketUrl = !empty($ticketLinks) ? $ticketLinks[0]['url'] : null;
                $newSourceUrl = $event->extractRealOfficialUrl() ?: ($newTicketUrl ?: null);

                // Update raw DB columns directly
                \DB::table('events')->where('id', $event->id)->update([
                    'ticket_url' => $newTicketUrl,
                    'source_url' => $newSourceUrl,
                ]);

                $updatedCount++;
            }
        });

        $this->info("Completed! Processed {$total} events. Updated {$updatedCount} records.");
        return self::SUCCESS;
    }
}
