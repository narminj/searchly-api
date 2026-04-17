<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class ElasticsearchUpdateMapping extends Command
{
    protected $signature = 'elasticsearch:update-mapping
                            {index? : Index config key (default: products)}';

    protected $description = 'Update index mappings without reindexing (add new fields only)';

    public function handle(ElasticsearchService $es): int
    {
        $key      = $this->argument('index') ?? 'products';
        $indexCfg = config("elasticsearch.indices.{$key}");

        if (! $indexCfg) {
            $this->error("No index configuration found for key: '{$key}'");

            return self::FAILURE;
        }

        $indexName = $indexCfg['name'];
        $mappings  = $indexCfg['mappings'] ?? [];

        if (empty($mappings)) {
            $this->warn('No mappings defined in config.');

            return self::SUCCESS;
        }

        if (! $es->existsIndex($indexName)) {
            $this->error("Index '{$indexName}' does not exist. Run elasticsearch:create-index first.");

            return self::FAILURE;
        }

        $this->info("Updating mappings for '{$indexName}'...");
        $this->warn('Note: Changing existing field types requires a full reindex (elasticsearch:reindex --fresh).');

        $es->putMapping($indexName, $mappings);

        $this->info('Mappings updated successfully.');

        return self::SUCCESS;
    }
}
