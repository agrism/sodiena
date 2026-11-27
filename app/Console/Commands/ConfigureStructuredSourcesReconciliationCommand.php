<?php

namespace App\Console\Commands;

use App\Models\Source;
use Illuminate\Console\Command;

class ConfigureStructuredSourcesReconciliationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sources:configure-reconciliation
        {--disable : Atspējot reconciliation visiem avotiem}
        {--source= : Ieslēgt/atspējot tikai konkrētam avota slug}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure automatic session reconciliation (circuit breaker & grace runs) for structured feed sources';

    /**
     * Whitelisted structured sources where full catalog feeds are guaranteed.
     */
    public const STRUCTURED_SOURCE_SLUGS = [
        'apollo-kino',
        'forum-cinemas',
        'cinamon',
        'splendid-palace',
        'ksuns',
        'lhf',
        'lbs',
        'lff',
        'volejbols-lv',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDisable = (bool) $this->option('disable');
        $specificSource = $this->option('source');

        $slugs = $specificSource ? [$specificSource] : self::STRUCTURED_SOURCE_SLUGS;

        $this->info("=== Strukturēto avotu Reconciliation konfigurācija ===");

        $rows = [];

        foreach ($slugs as $slug) {
            $source = Source::where('slug', $slug)->first();
            if (!$source) {
                $rows[] = [$slug, 'Nav atrasts DB', '-', '-', '-'];
                continue;
            }

            if ($isDisable) {
                $source->update([
                    'supports_reconciliation' => false,
                ]);
                $rows[] = [$source->name, 'Atspējots', '-', '-', 'Izslēgts'];
            } else {
                $source->update([
                    'supports_reconciliation' => true,
                    'reconciliation_min_ratio' => 0.60,
                    'reconciliation_grace_runs' => 1,
                ]);
                $rows[] = [$source->name, 'Iespējots', '0.60 (60%)', '1 cikls', 'Aktīvs'];
            }
        }

        $this->table(
            ['Avots', 'Reconciliation', 'Min Ratio', 'Grace Runs', 'Statuss'],
            $rows
        );

        if (!$isDisable) {
            $this->newLine();
            $this->info("✅ Reconciliation veiksmīgi konfigurēts strukturētajiem avotiem.");
            $this->line("Piezīme: Agregatori (afiro-api, kulturasdati) un daļējie skrāperi paliek ar supports_reconciliation = 0.");
        } else {
            $this->warn("⚠️  Reconciliation atspējots.");
        }

        return self::SUCCESS;
    }
}
