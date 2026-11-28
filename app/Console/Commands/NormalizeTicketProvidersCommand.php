<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\OccurrenceSource;
use App\Services\Tickets\TicketProviderResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NormalizeTicketProvidersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:normalize-providers
        {--dry-run : Parādīt izmaiņas bez to saglabāšanas datubāzē}
        {--source= : Filtrēt pēc avota slug (piem., afiro-api)}
        {--all-sources : Pārbaudīt visus avotus, ne tikai agregatorus}
        {--include-past : Pārbaudīt arī pagātnes pasākumus (pēc noklusējuma tikai tekošos un gaidāmos)}
        {--limit= : Maksimālais apstrādājamo ierakstu skaits}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize ticket provider names on occurrence sources and events, replacing aggregator names with legitimate merchants or null.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $sourceSlug = $this->option('source');
        $allSources = (bool) $this->option('all-sources');
        $includePast = (bool) $this->option('include-past');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('======================================================================');
        $this->info('       BIĻEŠU TIRGOTĀJU (PROVIDER_NAME) NORMALIZĀCIJAS KOMANDA       ');
        $this->info('======================================================================');
        $this->comment('Režīms: ' . ($isDryRun ? 'DRY-RUN (tikai parādīt, bez saglabāšanas)' : 'LIVE (izmaiņas tiks saglabātas)'));
        if ($sourceSlug) {
            $this->comment("Filtrs pēc avota: {$sourceSlug}");
        }
        $this->comment('Pasākumu tvērums: ' . ($includePast ? 'Visi (ieskaitot pagātnes)' : 'Stingri tikai tekošie un gaidāmie pasākumi'));
        $this->newLine();

        $today = now()->startOfDay();

        // 1. Apstrādāt occurrence_sources (ticket_offers V2)
        $this->info('1. Pārbauda occurrence_sources tabulu...');
        $occSourcesQuery = OccurrenceSource::with(['source', 'occurrence.event'])
            ->whereNotNull('ticket_url')
            ->where('ticket_url', '!=', '');

        if ($sourceSlug) {
            $occSourcesQuery->whereHas('source', fn ($q) => $q->where('slug', $sourceSlug));
        }

        if (!$includePast) {
            $occSourcesQuery->whereHas('occurrence', function ($oq) use ($today) {
                $oq->where(function ($q) use ($today) {
                    $q->where('end_at', '>=', $today)
                      ->orWhere(function ($sq) use ($today) {
                          $sq->whereNull('end_at')->where('start_at', '>=', $today);
                      });
                })->orWhereHas('event', function ($eq) use ($today) {
                    $eq->where(function ($q) use ($today) {
                        $q->where('end_at', '>=', $today)
                          ->orWhere(function ($sq) use ($today) {
                              $sq->whereNull('end_at')->where('start_at', '>=', $today);
                          });
                    });
                });
            });
        }

        if ($limit) {
            $occSourcesQuery->limit($limit);
        }

        $occSources = $occSourcesQuery->get();

        $checkedOccSources = 0;
        $changedOccSources = 0;
        $toMerchantOccSources = 0;
        $toNullOccSources = 0;
        $sampleChanges = [];
        $touchedEventIds = [];

        foreach ($occSources as $os) {
            $checkedOccSources++;
            $currentProvider = $os->provider_name;
            $newProvider = TicketProviderResolver::resolve(
                $os->ticket_url,
                $os->source?->slug,
                $currentProvider ?: $os->source?->name
            );

            if ($currentProvider !== $newProvider) {
                $changedOccSources++;
                if ($newProvider !== null) {
                    $toMerchantOccSources++;
                } else {
                    $toNullOccSources++;
                }

                if (count($sampleChanges) < 20) {
                    $sampleChanges[] = [
                        'id' => $os->id,
                        'source' => $os->source?->slug ?? 'nav',
                        'url' => mb_strimwidth($os->ticket_url, 0, 45, '...'),
                        'old' => $currentProvider ?? '(null)',
                        'new' => $newProvider ?? '(null)',
                    ];
                }

                $event = $os->occurrence?->event;
                if ($event) {
                    $touchedEventIds[$event->id] = $event;
                }

                if (!$isDryRun) {
                    $os->provider_name = $newProvider;
                    $os->save();
                }
            }
        }

        // 2. Ja eksistē atsevišķa ticket_offers tabula, apstrādāt arī to
        $checkedTicketOffers = 0;
        $changedTicketOffers = 0;
        if (Schema::hasTable('ticket_offers')) {
            $this->info('2. Pārbauda atsevišķo ticket_offers tabulu...');
            $offersQuery = DB::table('ticket_offers')
                ->whereNotNull('url')
                ->where('url', '!=', '');

            if ($sourceSlug && Schema::hasColumn('ticket_offers', 'source_slug')) {
                $offersQuery->where('source_slug', $sourceSlug);
            }

            if ($limit) {
                $offersQuery->limit($limit);
            }

            $offers = $offersQuery->get();
            foreach ($offers as $offer) {
                $checkedTicketOffers++;
                $slug = $offer->source_slug ?? null;
                $current = $offer->provider_name ?? null;
                $new = TicketProviderResolver::resolve($offer->url, $slug, $current);

                if ($current !== $new) {
                    $changedTicketOffers++;
                    if (!$isDryRun) {
                        DB::table('ticket_offers')->where('id', $offer->id)->update([
                            'provider_name' => $new,
                            'updated_at' => now(),
                        ]);
                    }

                    if (!empty($offer->event_id)) {
                        $touchedEventIds[$offer->event_id] = true;
                    }
                }
            }
        }

        // 3. Pārbaudīt un iztīrīt esošo pasākumu raw_data['ticket_links']
        $this->info('3. Pārbauda events.raw_data[\'ticket_links\']...');
        $eventsQuery = Event::query();
        if ($sourceSlug) {
            $eventsQuery->where('source_slug', $sourceSlug);
        }

        if (!$includePast) {
            $eventsQuery->where(function ($q) use ($today) {
                $q->where('end_at', '>=', $today)
                  ->orWhere(function ($sq) use ($today) {
                      $sq->whereNull('end_at')->where('start_at', '>=', $today);
                  });
            });
        }

        $eventsWithRawLinks = $eventsQuery->whereNotNull('raw_data->ticket_links')->get();
        $cleanedRawEvents = 0;

        foreach ($eventsWithRawLinks as $event) {
            $rawData = is_array($event->raw_data) ? $event->raw_data : [];
            $ticketLinks = $rawData['ticket_links'] ?? [];
            if (!is_array($ticketLinks) || empty($ticketLinks)) {
                continue;
            }

            $modified = false;
            $newLinks = [];

            foreach ($ticketLinks as $link) {
                $url = $link['url'] ?? '';
                $currProvider = $link['provider_name'] ?? null;
                $currTitle = $link['title'] ?? null;

                $resolved = TicketProviderResolver::resolve($url, $event->source_slug, $currProvider);

                if ($currProvider !== $resolved || (is_string($currTitle) && TicketProviderResolver::isAggregatorName($currTitle))) {
                    $link['provider_name'] = $resolved;
                    $link['title'] = $resolved ?: 'Biļetes';
                    $modified = true;
                }

                $newLinks[] = $link;
            }

            if ($modified) {
                $cleanedRawEvents++;
                $touchedEventIds[$event->id] = $event;

                if (!$isDryRun) {
                    $rawData['ticket_links'] = $newLinks;
                    $event->raw_data = $rawData;
                    $event->save();
                }
            }
        }

        // 4. Sinhronizācija: pieskarties (touch) izmainītajiem pasākumiem, lai piedzivo.lv paņemtu jaunākos datus
        $touchedCount = count($touchedEventIds);
        if (!$isDryRun && $touchedCount > 0) {
            $this->info("4. Atjauno {$touchedCount} pasākumu updated_at sinhronizācijai...");
            $now = now();
            foreach ($touchedEventIds as $ev) {
                if ($ev instanceof Event) {
                    $ev->timestamps = false;
                    $ev->updated_at = $now;
                    $ev->save();
                } elseif (is_numeric($ev)) {
                    Event::where('id', $ev)->update(['updated_at' => $now]);
                }
            }
        }

        // Parādīt izmaiņu paraugu tabulu
        if (!empty($sampleChanges)) {
            $this->newLine();
            $this->info('--- Paraugs: pirmās izmaiņas occurrence_sources ---');
            $this->table(
                ['ID', 'Avots', 'Biļetes URL', 'Vecais provider_name', 'Jaunais provider_name'],
                $sampleChanges
            );
        }

        // Kopsavilkuma statistika
        $this->newLine();
        $this->info('======================================================================');
        $this->info('                           KOPSAVILKUMS                              ');
        $this->info('======================================================================');
        $this->table(
            ['Metrika', 'Vērtība'],
            [
                ['Occurrence sources pārbaudīti', $checkedOccSources],
                ['Occurrence sources izmainīti', $changedOccSources],
                ['  -> Nomainīti uz reālu tirgotāju', $toMerchantOccSources],
                ['  -> Nomainīti uz null (izņemts agregators)', $toNullOccSources],
                ['ticket_offers pārbaudīti (ja tabula)', $checkedTicketOffers],
                ['ticket_offers izmainīti', $changedTicketOffers],
                ['Events raw_data[\'ticket_links\'] iztīrīti', $cleanedRawEvents],
                ['Piesaistītie pasākumi ar atjaunotu updated_at', $touchedCount],
                ['Izpildes statuss', $isDryRun ? 'DRY-RUN (reālas izmaiņas netika saglabātas)' : 'VEIKSMĪGI PIELIETOTS DB'],
            ]
        );

        return Command::SUCCESS;
    }
}
