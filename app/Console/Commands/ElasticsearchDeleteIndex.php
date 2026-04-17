<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class ElasticsearchDeleteIndex extends Command
{
    protected $signature = 'elasticsearch:delete-index
                            {index : The actual index name (not config key)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Delete an Elasticsearch index (irreversible)';

    public function handle(ElasticsearchService $es): int
    {
        $indexName = $this->argument('index');

        if (! $es->existsIndex($indexName)) {
            $this->warn("Index '{$indexName}' does not exist.");

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->confirm("Are you sure you want to DELETE index '{$indexName}'? This cannot be undone.")) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $this->info("Deleting index '{$indexName}'...");
        $es->deleteIndex($indexName);
        $this->info("Index '{$indexName}' deleted.");

        return self::SUCCESS;
    }
}
