<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class ElasticsearchCreateIndex extends Command
{
    protected $signature = 'elasticsearch:create-index
                            {index? : Index key from config (default: products)}
                            {--force : Recreate even if the index already exists}';

    protected $description = 'Create an Elasticsearch index with settings and mappings from config';

    public function handle(ElasticsearchService $es): int
    {
        $key      = $this->argument('index') ?? 'products';
        $indexCfg = config("elasticsearch.indices.{$key}");

        if (! $indexCfg) {
            $this->error("No index configuration found for key: '{$key}'");

            return self::FAILURE;
        }

        $indexName = $indexCfg['name'];

        if ($es->existsIndex($indexName)) {
            if (! $this->option('force')) {
                $this->warn("Index '{$indexName}' already exists. Use --force to recreate it.");

                return self::SUCCESS;
            }

            $this->info("Deleting existing index '{$indexName}'...");
            $es->deleteIndex($indexName);
        }

        $this->info("Creating index '{$indexName}'...");

        $es->createIndex(
            $indexName,
            $indexCfg['settings'] ?? [],
            $indexCfg['mappings'] ?? []
        );

        $this->info("Index '{$indexName}' created successfully.");

        $settings = $indexCfg['settings'] ?? [];
        $this->table(
            ['Setting', 'Value'],
            [
                ['Shards',   $settings['number_of_shards'] ?? 1],
                ['Replicas', $settings['number_of_replicas'] ?? 0],
                ['Analyzers', implode(', ', array_keys($settings['analysis']['analyzer'] ?? []))],
                ['Fields', count($indexCfg['mappings']['properties'] ?? [])],
            ]
        );

        return self::SUCCESS;
    }
}
