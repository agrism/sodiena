<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Location;
use App\Models\LocationTranslation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsolidateLocationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'locations:consolidate {--dry-run : Only show what would be consolidated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and consolidate duplicate location records across cities and venues';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info("Scanning locations for duplicates and consolidation..." . ($dryRun ? ' [DRY RUN]' : ''));

        $locations = Location::with(['translations'])->withCount('events')->get();
        $groupedByCity = $locations->groupBy(fn ($l) => mb_strtolower(trim($l->city ?? '')));

        $mergedCount = 0;

        foreach ($groupedByCity as $city => $cityLocations) {
            if ($cityLocations->count() < 2) {
                continue;
            }

            $locList = $cityLocations->values()->all();
            $deletedIds = [];

            for ($i = 0; $i < count($locList); $i++) {
                $primary = $locList[$i];
                if (in_array($primary->id, $deletedIds)) {
                    continue;
                }

                for ($j = $i + 1; $j < count($locList); $j++) {
                    $secondary = $locList[$j];
                    if (in_array($secondary->id, $deletedIds)) {
                        continue;
                    }

                    if ($this->shouldMerge($primary, $secondary)) {
                        // Pick the best canonical location (prefer one with clean name, not containing address in parens, or more events)
                        [$canonical, $duplicate] = $this->pickCanonical($primary, $secondary);

                        $this->line("  🔄 Merging [{$duplicate->id}] \"{$duplicate->name}\" ({$duplicate->events_count} events) into [{$canonical->id}] \"{$canonical->name}\" ({$canonical->events_count} events) in {$primary->city}");

                        if (!$dryRun) {
                            DB::transaction(function () use ($canonical, $duplicate) {
                                // Reassign events
                                Event::where('location_id', $duplicate->id)->update(['location_id' => $canonical->id]);

                                // Enrich canonical with missing address / coordinates
                                $update = [];
                                if (empty($canonical->address) && !empty($duplicate->address)) {
                                    $update['address'] = $duplicate->address;
                                }
                                if (empty($canonical->latitude) && !empty($duplicate->latitude)) {
                                    $update['latitude'] = $duplicate->latitude;
                                    $update['longitude'] = $duplicate->longitude;
                                }
                                if (!empty($update)) {
                                    $canonical->update($update);
                                }

                                // Delete duplicate
                                LocationTranslation::where('location_id', $duplicate->id)->delete();
                                $duplicate->delete();
                            });
                        }

                        $deletedIds[] = $duplicate->id;
                        $mergedCount++;

                        // If primary was duplicate, stop scanning for this primary
                        if ($primary->id === $duplicate->id) {
                            break;
                        }
                    }
                }
            }
        }

        $this->info("✅ Consolidation complete. Total locations merged: {$mergedCount}");
        return self::SUCCESS;
    }

    private function shouldMerge(Location $l1, Location $l2): bool
    {
        $n1 = $this->cleanLocationName($l1->name);
        $n2 = $this->cleanLocationName($l2->name);

        if (mb_strtolower($n1) === mb_strtolower($n2)) {
            return true;
        }

        $base1 = $this->getBaseVenueName($n1);
        $base2 = $this->getBaseVenueName($n2);

        // Base venue match without suffixes / parenthetical address
        if (mb_strlen($base1, 'UTF-8') >= 5 && mb_strtolower($base1, 'UTF-8') === mb_strtolower($base2, 'UTF-8')) {
            return true;
        }

        // Substring match
        $norm1 = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($n1, 'UTF-8'));
        $norm2 = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($n2, 'UTF-8'));

        if (mb_strlen($norm1, 'UTF-8') >= 8 && mb_strlen($norm2, 'UTF-8') >= 8) {
            if (str_contains($norm1, $norm2) || str_contains($norm2, $norm1)) {
                return true;
            }
        }

        // Address match in the same city
        $s1 = $this->extractStreetAndNumber($l1->address);
        $s2 = $this->extractStreetAndNumber($l2->address);
        if ($s1 && $s2 && mb_strtolower($s1, 'UTF-8') === mb_strtolower($s2, 'UTF-8')) {
            return true;
        }

        return false;
    }

    private function pickCanonical(Location $l1, Location $l2): array
    {
        $n1 = $l1->name;
        $n2 = $l2->name;

        // Prefer name without parenthetical address
        $hasParen1 = str_contains($n1, '(');
        $hasParen2 = str_contains($n2, '(');

        if (!$hasParen1 && $hasParen2) return [$l1, $l2];
        if ($hasParen1 && !$hasParen2) return [$l2, $l1];

        // Prefer longer / more descriptive name if one is short abbreviation
        if (mb_strlen($n1) >= mb_strlen($n2) + 6) return [$l1, $l2];
        if (mb_strlen($n2) >= mb_strlen($n1) + 6) return [$l2, $l1];

        // Prefer the one with more events
        if ($l1->events_count >= $l2->events_count) {
            return [$l1, $l2];
        }
        return [$l2, $l1];
    }

    private function cleanLocationName(string $name): string
    {
        $clean = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = str_replace(['“', '”', '«', '»', '"', '’', '`'], '', $clean);
        return trim(preg_replace('/\s+/', ' ', $clean));
    }

    private function getBaseVenueName(string $name): string
    {
        $name = preg_replace('/\s*\([^)]*\)/', '', $name);
        $name = preg_replace('/,?\s*(?:Lielā zāle|Mazā zāle|Jaunā zāle|Kamerzāle|Kora zāle|Koncertzāle|Eksperimentālā skatuve|MAZĀ ZĀLE|LIELĀ ZĀLE|1\.\s*stāvs|2\.\s*stāvs)$/iu', '', $name);
        return trim($name);
    }

    private function extractStreetAndNumber(?string $address): ?string
    {
        if (empty($address)) return null;
        if (preg_match('/([\p{L}\s]+(?:iela|bulvāris|gatve|prospekts|laukums|krastmala|dambis)\s+\d+[a-z]?)/iu', $address, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
