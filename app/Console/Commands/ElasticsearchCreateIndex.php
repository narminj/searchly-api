<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class ElasticsearchCreateIndex extends Command
{
    protected $signature = 'elasticsearch:create-index
                            {index? : Index key from config (default: products)}';

    protected $description = 'Bootstrap an empty versioned index ({name}_v1) behind its alias. '
        . 'For rebuilds of an existing index use elasticsearch:migrate.';

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
            $this->warn("'{$indexName}' already exists (as an index or alias). Use 'elasticsearch:migrate {$key}' for versioned rebuilds.");

            return self::FAILURE;
        }

        $physical = "{$indexName}_v1";
        $this->info("Creating index '{$physical}' with alias '{$indexName}'...");

        $es->createIndex(
            $physical,
            $indexCfg['settings'] ?? [],
            $indexCfg['mappings'] ?? [],
            [$indexName => (object) []]
        );

        $this->info("Index '{$physical}' created successfully (alias: '{$indexName}').");

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
