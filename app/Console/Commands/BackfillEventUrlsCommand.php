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

        Event::chunk(100, function ($events) use ($ticketPlatforms, &$updatedCount) {
            foreach ($events as $event) {
                $text = ($event->getRawOriginal('description') ?? '') . ' ' . json_encode($event->raw_data ?? []);
                preg_match_all('/https?:\/\/[^\s\)\"\'<>]+/i', $text, $matches);

                $foundTicket = null;
                $foundOfficial = null;

                foreach ($matches[0] as $rawUrl) {
                    $cleanUrl = preg_replace('/(\?|\&)utm_[a-zA-Z0-9_]+=[^&]*/', '', $rawUrl);
                    $cleanUrl = rtrim($cleanUrl, '?&.,;:\'\"');

                    if (str_contains($cleanUrl, 'afiro.lv') || str_contains($cleanUrl, 'imagekit.io')) {
                        continue;
                    }

                    foreach ($ticketPlatforms as $platform) {
                        if (str_contains($cleanUrl, $platform)) {
                            $foundTicket = $cleanUrl;
                            break;
                        }
                    }

                    if (!$foundOfficial && !str_contains($cleanUrl, 'youtube.com') && !str_contains($cleanUrl, 'youtu.be') && !str_contains($cleanUrl, 'tiktok.com')) {
                        $foundOfficial = $cleanUrl;
                    }
                }

                $newTicketUrl = $foundTicket ?: null;
                $newSourceUrl = $foundOfficial ?: ($foundTicket ?: null);

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
