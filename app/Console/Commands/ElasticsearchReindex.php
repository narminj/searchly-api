<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ElasticsearchService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class ElasticsearchReindex extends Command
{
    protected $signature = 'elasticsearch:reindex
                            {index? : Index config key (default: products)}
                            {--chunk=100 : Number of documents per bulk request}
                            {--fresh : Delete and recreate the index before reindexing}';

    protected $description = 'Reindex all products into Elasticsearch using bulk API';

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

        // Optionally recreate the index with fresh settings + mappings
        if ($this->option('fresh')) {
            $this->info('Recreating index...');
            $es->deleteIndex($indexName);
            $es->createIndex($indexName, $indexCfg['settings'] ?? [], $indexCfg['mappings'] ?? []);
            $this->info("Index '{$indexName}' recreated.");
        } elseif (! $es->existsIndex($indexName)) {
            $this->info("Index '{$indexName}' does not exist. Creating...");
            $es->createIndex($indexName, $indexCfg['settings'] ?? [], $indexCfg['mappings'] ?? []);
        }

        $total = Product::count();

        if ($total === 0) {
            $this->warn('No products found in the database.');

            return self::SUCCESS;
        }

        $this->info("Reindexing {$total} products in chunks of {$chunkSize}...");

        $bar     = $this->output->createProgressBar($total);
        $indexed = 0;
        $errors  = 0;

        // cursor() uses lazy loading — avoids loading all records into memory at once
        // Observers are suppressed because we call ES directly here
        Model::withoutObservers(function () use ($es, $indexName, $chunkSize, $bar, &$indexed, &$errors) {
            Product::query()
                ->cursor()
                ->chunk($chunkSize)
                ->each(function ($chunk) use ($es, $indexName, $bar, &$indexed, &$errors) {
                    $documents = $chunk->map(fn (Product $p) => $p->toSearchArray())->all();

                    try {
                        $result     = $es->bulkIndex($indexName, $documents);
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
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total products', $total],
                ['Indexed successfully', $indexed - $errors],
                ['Errors', $errors],
            ]
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
