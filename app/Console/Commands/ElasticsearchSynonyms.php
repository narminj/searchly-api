<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class ElasticsearchSynonyms extends Command
{
    protected $signature = 'elasticsearch:synonyms
                            {key? : Synonyms config key (default: products)}
                            {--show : Show the set currently stored in Elasticsearch instead of pushing}';

    protected $description = 'Push the version-controlled synonym dictionary (config/elasticsearch.php) to Elasticsearch. '
        . 'Takes effect immediately — search analyzers reload automatically, no reindex needed.';

    public function handle(ElasticsearchService $es): int
    {
        $key = $this->argument('key') ?? 'products';
        $cfg = config("elasticsearch.synonyms.{$key}");

        if (! $cfg) {
            $this->error("No synonyms configuration found for key: '{$key}'");

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $current = $es->getSynonymsSet($cfg['set_id']);

            if (empty($current)) {
                $this->warn("Synonyms set '{$cfg['set_id']}' does not exist in Elasticsearch.");

                return self::SUCCESS;
            }

            $this->table(
                ['ID', 'Synonyms'],
                array_map(
                    fn ($rule) => [$rule['id'] ?? '-', $rule['synonyms']],
                    $current['synonyms_set'] ?? []
                )
            );

            return self::SUCCESS;
        }

        $result = $es->putSynonymsSet($cfg['set_id'], $cfg['rules']);

        $reloaded = $result['reload_analyzers_details']['_shards']['successful'] ?? 0;
        $this->info(sprintf(
            "Synonyms set '%s' %s with %d rules (analyzers reloaded on %d shard(s)).",
            $cfg['set_id'],
            $result['result'] ?? 'updated',
            count($cfg['rules']),
            $reloaded
        ));

        return self::SUCCESS;
    }
}
