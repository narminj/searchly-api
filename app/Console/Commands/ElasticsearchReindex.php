<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class ElasticsearchReindex extends Command
{
    protected $signature = 'elasticsearch:reindex
                            {index? : Index config key (default: products)}
                            {--chunk=100 : Number of documents per bulk request}';

    protected $description = 'Bulk-upsert all active products into the live index/alias in place. '
        . 'Does NOT remove stale documents — for a full, consistent rebuild use elasticsearch:migrate.';

    public function handle(ElasticsearchService $es): int
    {
        $key       = $this->argument('index') ?? 'products';
        $indexCfg  = config("elasticsearch.indices.{$key}");
        $chunkSize = (int) $this->option('chunk');

        if (! $indexCfg) {
            $this->error("No index configuration found for key: '{$key}'");

            return self::FAILURE;
        }

        $indexName = $indexCfg['name'];

        if (! $es->existsIndex($indexName)) {
            $this->error("Index/alias '{$indexName}' does not exist. Run 'php artisan elasticsearch:migrate {$key}' first.");

            return self::FAILURE;
        }

        // The index only ever holds active products (see ProductObserver)
        $query = Product::query()->where('is_active', true);
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No active products found in the database.');

            return self::SUCCESS;
        }

        $this->info("Reindexing {$total} active products in chunks of {$chunkSize}...");

        $bar     = $this->output->createProgressBar($total);
        $indexed = 0;
        $errors  = 0;

        // cursor() uses lazy loading — avoids loading all records into memory at once
        $query
            ->cursor()
            ->chunk($chunkSize)
            ->each(function ($chunk) use ($es, $indexName, $bar, &$indexed, &$errors) {
                $documents = $chunk->map(fn (Product $p) => $p->toSearchArray())->all();

                try {
                    $result      = $es->bulkIndex($indexName, $documents);
                    $chunkErrors = collect($result['items'] ?? [])
                        ->filter(fn ($item) => isset($item['index']['error']))
                        ->count();

                    if ($chunkErrors > 0) {
                        $errors += $chunkErrors;
                        $this->newLine();
                        $this->warn("{$chunkErrors} errors in this chunk.");
                    }
                } catch (\Throwable $e) {
                    $errors += count($documents);
                    $this->newLine();
                    $this->error('Chunk failed: ' . $e->getMessage());
                }

                $indexed += count($documents);
                $bar->advance(count($documents));
            });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Active products', $total],
                ['Indexed successfully', $indexed - $errors],
                ['Errors', $errors],
            ]
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
