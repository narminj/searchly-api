<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class ElasticsearchMigrate extends Command
{
    protected $signature = 'elasticsearch:migrate
                            {index? : Index config key (default: products)}
                            {--chunk=500 : Number of documents per bulk request}
                            {--prune : After swapping, delete obsolete versions (the previous one is kept for rollback)}';

    protected $description = 'Build a new versioned index from config, load it from the database, then atomically swap the alias (zero downtime)';

    public function handle(ElasticsearchService $es): int
    {
        $key = $this->argument('index') ?? 'products';
        $cfg = config("elasticsearch.indices.{$key}");

        if (! $cfg) {
            $this->error("No index configuration found for key: '{$key}'");

            return self::FAILURE;
        }

        $alias = $cfg['name'];
        $chunk = max(1, (int) $this->option('chunk'));

        // ── Current state ─────────────────────────────────────────────────────
        $aliasIndices = $es->getAliasIndices($alias);
        // Legacy state: a physical index occupies the alias name (pre-alias setup)
        $legacyIndex = empty($aliasIndices) && $es->existsIndex($alias);

        $versions = $es->listIndices("{$alias}_v*");
        $nextN    = collect($versions)
            ->map(fn (string $name) => (int) substr($name, strlen("{$alias}_v")))
            ->max();
        $next = "{$alias}_v" . ($nextN ? $nextN + 1 : 1);

        $this->info("Alias '{$alias}' → building new index '{$next}'");
        if ($legacyIndex) {
            $this->warn("Physical index '{$alias}' occupies the alias name (legacy state); it will be removed atomically during the swap.");
        }

        // ── Create the new index (tuned for bulk loading) ─────────────────────
        $settings        = $cfg['settings'] ?? [];
        $targetReplicas  = $settings['number_of_replicas'] ?? 0;
        $settings['number_of_replicas'] = 0;
        $settings['refresh_interval']   = '-1';

        $es->createIndex($next, $settings, $cfg['mappings'] ?? []);

        // ── Bulk-load ACTIVE products from the DB (the index never holds inactive ones)
        // Note: writes that land via the queue while this runs go to the OLD
        // index and won't be in the new one — run elasticsearch:reindex after
        // the swap if the catalog changes heavily during migration.
        $dbCount = Product::where('is_active', true)->count();
        $this->info("Indexing {$dbCount} active products in chunks of {$chunk}...");

        $bar    = $this->output->createProgressBar($dbCount);
        $errors = 0;

        Product::query()
            ->where('is_active', true)
            ->cursor()
            ->chunk($chunk)
            ->each(function ($products) use ($es, $next, $bar, &$errors) {
                $documents = $products->map(fn (Product $p) => $p->toSearchArray())->all();

                // No per-chunk try/catch here (unlike elasticsearch:reindex): a
                // transport-level bulk failure must abort the whole migration.
                // The alias is never swapped, so the live index stays untouched
                // and the half-built '{$next}' index is left in place for inspection.
                $result  = $es->bulkIndex($next, $documents);
                $errors += collect($result['items'] ?? [])
                    ->filter(fn ($item) => isset($item['index']['error']))
                    ->count();

                $bar->advance(count($documents));
            });

        $bar->finish();
        $this->newLine();

        if ($errors > 0) {
            $this->error("{$errors} documents failed to index. Swap aborted; '{$next}' left in place for inspection.");

            return self::FAILURE;
        }

        // ── Restore settings, refresh, validate counts ────────────────────────
        $es->putSettings($next, [
            'number_of_replicas' => $targetReplicas,
            'refresh_interval'   => null, // back to the default (1s)
        ]);
        $es->refreshIndex($next);

        $esCount = $es->count($next);
        if ($esCount !== $dbCount) {
            $this->error("Count mismatch: DB={$dbCount}, ES={$esCount}. Swap aborted; '{$next}' left in place.");

            return self::FAILURE;
        }

        // ── Atomic alias swap ─────────────────────────────────────────────────
        $actions = [];
        if ($legacyIndex) {
            $actions[] = ['remove_index' => ['index' => $alias]];
        }
        foreach ($aliasIndices as $old) {
            $actions[] = ['remove' => ['index' => $old, 'alias' => $alias]];
        }
        $actions[] = ['add' => ['index' => $next, 'alias' => $alias, 'is_write_index' => true]];

        $es->updateAliases($actions);
        $this->info("Alias '{$alias}' now points to '{$next}' ({$esCount} docs).");

        // ── Old versions: keep for rollback, or prune all but the previous one ─
        $obsolete = $versions;
        sort($obsolete, SORT_NATURAL);

        if ($this->option('prune') && $obsolete) {
            $kept = array_pop($obsolete); // most recent old version stays
            foreach ($obsolete as $oldIndex) {
                $es->deleteIndex($oldIndex);
                $this->line("Pruned '{$oldIndex}'.");
            }
            $this->line("Kept '{$kept}' for rollback.");
        } elseif ($obsolete) {
            $this->line('Old versions kept for rollback: ' . implode(', ', $obsolete));
        }

        return self::SUCCESS;
    }
}
