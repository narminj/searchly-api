<?php

namespace App\Listeners;

use App\Events\SearchPerformed;
use App\Services\ElasticsearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Writes one search_logs document per search, off the request path.
 * Analytics must never slow down or break search itself — hence queued,
 * single-attempt, and silently skipped while the logs index is absent.
 */
class LogSearchPerformed implements ShouldQueue
{
    use InteractsWithQueue;

    /** Analytics loss is acceptable; retry storms are not */
    public int $tries = 1;

    public function __construct(private readonly ElasticsearchService $es) {}

    public function handle(SearchPerformed $event): void
    {
        $index = config('elasticsearch.indices.search_logs.name');

        // Never let the bulk auto-create an unmapped index; bootstrap it with
        // `php artisan elasticsearch:create-index search_logs`
        if (! $this->es->existsIndex($index)) {
            return;
        }

        $this->es->indexDocument($index, null, [
            'query'           => mb_substr(trim($event->query), 0, 256),
            'filters'         => (object) $event->filters,
            'result_count'    => $event->resultCount,
            'zero_results'    => $event->resultCount === 0,
            'took_ms'         => $event->tookMs,
            'page'            => $event->page,
            'suggested_query' => $event->suggestedQuery,
            'session'         => $event->session,
            'created_at'      => now()->format('Y-m-d H:i:s'),
        ]);
    }
}
