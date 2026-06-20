<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class ElasticsearchSearchStats extends Command
{
    protected $signature = 'elasticsearch:search-stats
                            {--days=7 : Look-back window in days}
                            {--size=15 : Rows per table}';

    protected $description = 'Search analytics report from the search_logs index: '
        . 'top queries, zero-result queries (synonym candidates), latency.';

    public function handle(ElasticsearchService $es): int
    {
        $index = config('elasticsearch.indices.search_logs.name');
        $days  = max(1, (int) $this->option('days'));
        $size  = max(1, (int) $this->option('size'));

        if (! $es->existsIndex($index)) {
            $this->warn("Index '{$index}' does not exist yet. Bootstrap it with: php artisan elasticsearch:create-index search_logs");

            return self::FAILURE;
        }

        $response = $es->search($index, [
            'size'  => 0,
            'query' => [
                'bool' => [
                    'filter' => [
                        ['range' => ['created_at' => ['gte' => now()->subDays($days)->format('Y-m-d H:i:s')]]],
                    ],
                ],
            ],
            'aggs' => [
                'top_queries' => [
                    // Empty query = browsing, not searching — exclude it
                    'filter' => ['bool' => ['must_not' => [['term' => ['query' => '']]]]],
                    'aggs'   => ['terms' => ['terms' => ['field' => 'query', 'size' => $size]]],
                ],
                'zero_result_queries' => [
                    'filter' => ['bool' => [
                        'filter'   => [['term' => ['zero_results' => true]]],
                        'must_not' => [['term' => ['query' => '']]],
                    ]],
                    'aggs' => ['terms' => ['terms' => ['field' => 'query', 'size' => $size]]],
                ],
                'avg_took'        => ['avg' => ['field' => 'took_ms']],
                'p95_took'        => ['percentiles' => ['field' => 'took_ms', 'percents' => [95]]],
                'unique_sessions' => ['cardinality' => ['field' => 'session']],
            ],
        ]);

        $aggs  = $response['aggregations'] ?? [];
        $total = $response['hits']['total']['value'] ?? 0;

        $this->info("Last {$days} day(s): {$total} searches, "
            . (int) ($aggs['unique_sessions']['value'] ?? 0) . ' unique sessions, '
            . 'avg ' . round($aggs['avg_took']['value'] ?? 0) . 'ms, '
            . 'p95 ' . round($aggs['p95_took']['values']['95.0'] ?? 0) . 'ms');

        $this->newLine();
        $this->line('Top queries:');
        $this->table(['Query', 'Count'], array_map(
            fn ($b) => [$b['key'], $b['doc_count']],
            $aggs['top_queries']['terms']['buckets'] ?? []
        ));

        $this->line('Zero-result queries (synonym dictionary candidates):');
        $this->table(['Query', 'Count'], array_map(
            fn ($b) => [$b['key'], $b['doc_count']],
            $aggs['zero_result_queries']['terms']['buckets'] ?? []
        ));

        return self::SUCCESS;
    }
}
